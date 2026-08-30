<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

trait ResolvesInstagramAccountTrait
{
    private ?string $listPagesError = null;

    /**
     * Pesan error terakhir saat menyusun daftar halaman (nullable bila sukses).
     */
    public function listPagesError(): ?string
    {
        return $this->listPagesError;
    }

    /**
     * Daftar Page yang bisa diakses token user, lengkap dengan status akun
     * Instagram Business/Creator yang ter-link.
     *
     * Sumber:
     * - `/me/accounts` (page yang jadi Admin/Editor langsung di akun personal);
     * - fallback Business Portfolio `/me/businesses` → `owned_pages` + `client_pages`
     *   (page yang hanya diakses lewat Business Manager/Portofolio bisnis).
     * Kegagalan di salah satu sumber tidak fatal — sumber lain tetap dipakai.
     *
     * @return array<int, array{id: string, name: string, has_ig: bool, ig_user_id: ?string, ig_username: ?string}>
     */
    public function listPages(string $userToken): array
    {
        $this->listPagesError = null;

        $base = rtrim((string) config('instagram.api_base'), '/').'/'.config('instagram.api_version');

        $candidates = [];
        $seen = [];

        $collect = function (array $items) use (&$candidates, &$seen): void {
            foreach ($items as $item) {
                $id = (string) ($item['id'] ?? '');

                if ($id === '' || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $candidates[$id] = [
                    'id' => $id,
                    'name' => (string) ($item['name'] ?? $id),
                    'ig_account_id' => isset($item['instagram_business_account']['id'])
                        ? (string) $item['instagram_business_account']['id']
                        : null,
                ];
            }
        };

        try {
            $collect($this->graph($base, '/me/accounts', [
                'fields' => 'id,name',
                'access_token' => $userToken,
            ])->json('data') ?? []);
        } catch (InstagramApiException $e) {
            $this->listPagesError = $e->getMessage();
            // Tidak fatal — coba sumber bisnis.
        }

        try {
            $businesses = $this->graph($base, '/me/businesses', [
                'fields' => 'id,name',
                'access_token' => $userToken,
            ])->json('data') ?? [];

            foreach ($businesses as $business) {
                $businessId = (string) ($business['id'] ?? '');

                if ($businessId === '') {
                    continue;
                }

                foreach (['owned_pages', 'client_pages'] as $edge) {
                    try {
                        $collect($this->graph($base, '/'.$businessId.'/'.$edge, [
                            'fields' => 'id,name,instagram_business_account',
                            'access_token' => $userToken,
                        ])->json('data') ?? []);
                    } catch (InstagramApiException $e) {
                        $this->listPagesError = $e->getMessage();
                        // Satu edge bisnis gagal → lanjut edge/sumber lain.
                    }
                }
            }
        } catch (InstagramApiException $e) {
            $this->listPagesError = $e->getMessage();
            // Tidak punya business_management / belum dihubungkan — abaikan.
        }

        $pages = [];

        foreach ($candidates as $candidate) {
            $igUserId = $candidate['ig_account_id'];

            if ($igUserId === null) {
                $igUserId = $this->instagramAccountForPage($base, $userToken, $candidate['id']);
            }

            $pages[] = [
                'id' => $candidate['id'],
                'name' => $candidate['name'],
                'has_ig' => $igUserId !== null,
                'ig_user_id' => $igUserId,
                'ig_username' => $igUserId !== null ? $this->instagramUsername($base, $userToken, $igUserId) : null,
            ];
        }

        return $pages;
    }

    /**
     * Cari akun Instagram Business/Creator yang ter-link ke satu Page tertentu.
     *
     * @return array{ig_user_id: string, username: string, page_id: string, page_name: string}|null
     */
    public function resolveInstagramAccountForPage(string $userToken, string $pageId): ?array
    {
        $base = rtrim((string) config('instagram.api_base'), '/').'/'.config('instagram.api_version');

        $pageData = $this->graph($base, '/'.$pageId, [
            'fields' => 'id,name,instagram_business_account',
            'access_token' => $userToken,
        ])->json();

        $igAccount = $pageData['instagram_business_account'] ?? null;

        if ($igAccount === null) {
            return null;
        }

        $igUserId = (string) $igAccount['id'];

        $info = $this->graph($base, '/'.$igUserId, [
            'fields' => 'id,username',
            'access_token' => $userToken,
        ])->json();

        return [
            'ig_user_id' => $igUserId,
            'username' => (string) ($info['username'] ?? ''),
            'page_id' => (string) ($pageData['id'] ?? $pageId),
            'page_name' => (string) ($pageData['name'] ?? $pageId),
        ];
    }

    private function instagramAccountForPage(string $base, string $userToken, string $pageId): ?string
    {
        $page = $this->graph($base, '/'.$pageId, [
            'fields' => 'instagram_business_account',
            'access_token' => $userToken,
        ])->json();

        $igAccount = $page['instagram_business_account'] ?? null;

        return $igAccount !== null ? (string) $igAccount['id'] : null;
    }

    private function instagramUsername(string $base, string $userToken, string $igUserId): ?string
    {
        $info = $this->graph($base, '/'.$igUserId, [
            'fields' => 'username',
            'access_token' => $userToken,
        ])->json();

        return isset($info['username']) ? (string) $info['username'] : null;
    }

    /**
     * Helper Graph API dengan deteksi error (track bagian mana yang gagal).
     */
    private function graph(string $base, string $path, array $query): Response
    {
        $response = Http::baseUrl($base)
            ->timeout(30)
            ->get($path, $query);

        $json = $response->json();

        if (isset($json['error'])) {
            $err = $json['error'];

            throw new InstagramApiException(
                sprintf(
                    '%s (saat membaca %s)',
                    (string) ($err['message'] ?? 'Kesalahan Graph API'),
                    $path,
                ),
                (int) ($err['code'] ?? 0),
                isset($err['error_subcode']) ? (int) $err['error_subcode'] : null,
            );
        }

        return $response;
    }
}
