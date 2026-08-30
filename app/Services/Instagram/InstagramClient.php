<?php

namespace App\Services\Instagram;

use App\Models\InstagramAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class InstagramClient
{
    /**
     * Komunikasi dengan Meta Graph API (Instagram comment management).
     * Bundle: "Instagram Login with Facebook" -> graph.facebook.com.
     */
    /**
     * @param  InstagramAccount|null  $account  akun terikat (sumber token & IG user id)
     * @param  string|null  $token  token eksplisit (override, mis. dari MCP/manual)
     */
    public function __construct(
        protected ?InstagramAccount $account = null,
        protected ?string $token = null,
    ) {}

    public function resolveToken(): string
    {
        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }

        if ($this->account !== null && $this->account->access_token !== '') {
            return $this->account->access_token;
        }

        throw new InstagramApiException('Instagram belum terhubung (token kosong). Hubungkan akun lewat Settings.');
    }

    public function resolveAccount(): ?InstagramAccount
    {
        return $this->account;
    }

    public function resolveIgUserId(): int
    {
        if ($this->account !== null) {
            return (int) $this->account->ig_user_id;
        }

        throw new InstagramApiException('Instagram belum terhubung (IG user id tidak diketahui). Hubungkan akun lewat Settings.');
    }

    protected function base(): string
    {
        return rtrim((string) config('instagram.api_base'), '/').'/'.config('instagram.api_version');
    }

    /**
     * GET /{ig-user-id}?fields=id,username,followers_count
     *
     * @return array{id: string, username: string}
     */
    public function accountInfo(): array
    {
        $data = $this->get('/'.$this->resolveIgUserId(), [
            'fields' => 'id,username,followers_count',
        ]);

        $this->markStoredTokenOk();

        return [
            'id' => (string) $data['id'],
            'username' => (string) ($data['username'] ?? ''),
            'followers_count' => (int) ($data['followers_count'] ?? 0),
        ];
    }

    /**
     * Daftar media terbaru akun.
     *
     * @return Collection<int, array{id: string, media_type: string, timestamp?: string}>
     */
    public function recentMedia(int $limit = 10): Collection
    {
        $media = collect();
        $after = null;

        do {
            $response = $this->get('/'.$this->resolveIgUserId().'/media', [
                'fields' => 'id,media_type,timestamp,permalink',
                'limit' => min($limit, 25),
                'after' => $after,
            ]);

            $media = $media->merge($response['data'] ?? []);
            $after = data_get($response, 'paging.cursors.after');
        } while ($after !== null && $media->count() < $limit);

        return $media->take($limit)->values();
    }

    /**
     * Semua komentar sebuah media (pagination untuk setiap halaman).
     *
     * @return Collection<int, array{id: string, text: string, username: string, timestamp: string}>
     */
    public function comments(string $mediaId): Collection
    {
        $comments = collect();
        $after = null;

        do {
            $response = $this->get('/'.$mediaId.'/comments', [
                'fields' => 'id,text,username,timestamp,from',
                'limit' => 100,
                'after' => $after,
            ]);

            $comments = $comments->merge($response['data'] ?? []);
            $after = data_get($response, 'paging.cursors.after');
        } while ($after !== null);

        return $comments->values();
    }

    /**
     * Cari media_id akun dari shortcode yang muncul di permalink postingan
     * (fallback saat belum ada media_url di DB). Paginasi dibatasi agar hemat kuota.
     */
    public function findMediaIdByShortcode(string $shortcode, int $maxPages = 10): ?string
    {
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->get('/'.$this->resolveIgUserId().'/media', [
                'fields' => 'id,permalink',
                'limit' => 25,
                'after' => $after,
            ]);

            foreach (($response['data'] ?? []) as $item) {
                if (str_contains((string) ($item['permalink'] ?? ''), $shortcode)) {
                    return (string) ($item['id'] ?? '');
                }
            }

            $after = data_get($response, 'paging.cursors.after');

            if ($after === null) {
                break;
            }
        }

        return null;
    }

    /**
     * URL publik (permalink) sebuah media, untuk shortcut "buka postingan".
     * Null bila Graph tidak menyertakan permalink (mis. media tak tersedia).
     */
    public function permalink(string $mediaId): ?string
    {
        $data = $this->get('/'.$mediaId, ['fields' => 'permalink']);

        return isset($data['permalink']) ? (string) $data['permalink'] : null;
    }

    /**
     * Balas publik ke komentar tertentu.
     *
     * @return array{id: string}
     */
    public function replyTo(string $commentId, string $message): array
    {
        $data = $this->post('/'.$commentId.'/replies', [
            'message' => $message,
        ]);

        return ['id' => (string) ($data['id'] ?? '')];
    }

    protected function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl($this->base())
            ->timeout(30)
            ->get($path, array_merge($query, ['access_token' => $this->resolveToken()]));

        $this->recordApiCall();

        return $this->parse($response);
    }

    protected function post(string $path, array $params = []): array
    {
        $response = Http::baseUrl($this->base())
            ->timeout(30)
            ->asForm()
            ->post($path, array_merge($params, ['access_token' => $this->resolveToken()]));

        $this->recordApiCall();

        return $this->parse($response);
    }

    /**
     * Hitung satu pemakaian kuota Graph API pada akun terikat (per user token).
     */
    protected function recordApiCall(): void
    {
        if ($this->account !== null) {
            $this->account->markApiCall();
        }
    }

    protected function parse(Response $response): array
    {
        $json = $response->json();

        if (isset($json['error'])) {
            $err = $json['error'];
            $code = (int) ($err['code'] ?? 0);
            $subcode = isset($err['error_subcode']) ? (int) $err['error_subcode'] : null;
            $message = (string) ($err['message'] ?? 'Kesalahan Graph API');

            // 190 = token invalid/expired, 460 = password diubah (page token), 492 = peran dicabut.
            $revoked = in_array($code, [190, 460, 492], true);

            if ($revoked) {
                $this->markStoredTokenInvalid();
            }

            throw new InstagramApiException(
                $message,
                $code,
                $subcode,
                rateLimited: $code === 429 || $response->status() === 429,
                tokenExpired: $revoked,
            );
        }

        if ($response->status() >= 400) {
            throw new InstagramApiException('Graph API HTTP '.$response->status());
        }

        return is_array($json) ? $json : [];
    }

    /**
     * Tandai token tersimpan sebagai tidak valid (hanya untuk akun terikat,
     * bukan token yang di-injeksi lewat constructor).
     */
    protected function markStoredTokenInvalid(): void
    {
        if ($this->token !== null || $this->account === null) {
            return;
        }

        $this->account->update([
            'token_invalid_at' => now(),
            'last_checked_at' => now(),
            'last_check_ok' => false,
        ]);
    }

    protected function markStoredTokenOk(): void
    {
        if ($this->token !== null || $this->account === null) {
            return;
        }

        $this->account->update([
            'token_invalid_at' => null,
            'last_checked_at' => now(),
            'last_check_ok' => true,
        ]);
    }
}
