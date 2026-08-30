<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use App\Services\AutoReplyService;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        return view('settings.index', [
            'setting' => Setting::singleton(),
            'accounts' => InstagramAccount::query()->orderBy('username')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bot_enabled' => ['sometimes', 'boolean'],
            'reply_to_own_comments' => ['sometimes', 'boolean'],
            'poll_interval_minutes' => ['required', 'integer', 'between:1,60'],
            'max_media_per_cycle' => ['required', 'integer', 'between:1,50'],
        ]);

        Setting::singleton()->update([
            'bot_enabled' => $request->boolean('bot_enabled'),
            'reply_to_own_comments' => $request->boolean('reply_to_own_comments'),
            'poll_interval_minutes' => $data['poll_interval_minutes'],
            'max_media_per_cycle' => $data['max_media_per_cycle'],
            'poll_cooldown_until' => null,
        ]);

        return back()->with('flash', 'Setelan bot tersimpan.');
    }

    /**
     * Jalankan satu siklus polling secara manual (sinkron); JSON bila diminta fetch.
     */
    public function pollNow(Request $request, AutoReplyService $service): RedirectResponse|JsonResponse
    {
        $summary = $service->process();

        $message = $summary['aborted']
            ? 'Polling: '.$summary['reason']
            : sprintf('Polling selesai: %d komentar baru, %d balas diantrekan.', $summary['new'], $summary['replied_dispatch']);

        if ($request->wantsJson()) {
            return response()->json(['ok' => ! $summary['aborted'], 'message' => $message]);
        }

        return back()->with('flash', $message);
    }

    public function test(Request $request): RedirectResponse
    {
        $account = InstagramAccount::query()
            ->where('ig_user_id', $request->input('ig_user_id'))
            ->first();

        if ($account === null || blank($account->access_token)) {
            return back()->with('flash-error', 'Belum ada akun Instagram terhubung.');
        }

        try {
            $info = (new InstagramClient($account))->accountInfo();
        } catch (InstagramApiException $e) {
            return back()->with('flash-error', 'Gagal: '.$e->getMessage());
        }

        return back()->with('flash', "Terhubung sebagai @{$info['username']} (IG user id {$info['id']}).");
    }

    /**
     * Atur pilihan pemindaian postingan untuk satu akun. Kedua mode bisa aktif
     * bersamaan ("postingan terbaru" sesuai max_media_per_cycle global dan
     * daftar "postingan tertentu").
     */
    public function updateMedia(Request $request, InstagramAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'scan_recent' => ['sometimes', 'boolean'],
            'scan_specific' => ['sometimes', 'boolean'],
        ]);

        $scanRecent = $request->boolean('scan_recent');
        $scanSpecific = $request->boolean('scan_specific');

        if (! $scanRecent && ! $scanSpecific) {
            return back()->withErrors(['media_mode' => 'Centang minimal salah satu mode pemindaian.'])->withInput();
        }

        $tokens = collect(preg_split('/[\r\n,]+/', (string) $request->string('media_ids')))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        if ($scanSpecific) {
            if ($tokens->isEmpty()) {
                return back()->withErrors(['media_ids' => 'Tulis minimal satu ID postingan.'])->withInput();
            }

            if ($tokens->count() > 50) {
                return back()->withErrors(['media_ids' => 'Maksimal 50 ID postingan.'])->withInput();
            }

            $resolved = $tokens->map(fn (string $token) => $this->resolveMediaToken($account, $token));
            $invalid = $resolved->filter(fn (array $item): bool => $item['error'] !== null);

            if ($invalid->isNotEmpty()) {
                $shown = implode(', ', array_slice($invalid->pluck('value')->all(), 0, 3));

                return back()->withErrors(['media_ids' => 'ID postingan tidak valid: '.$shown])->withInput();
            }

            $ids = $resolved->pluck('media_id')->values()->all();
        } else {
            $ids = null;
        }

        $account->update([
            'scan_recent' => $scanRecent,
            'scan_specific' => $scanSpecific,
            'media_ids' => $ids,
        ]);

        return back()->with('flash', 'Pengaturan postingan akun diperbarui.');
    }

    /**
     * Konversi list token (ID/URL) ke media_id numerik, dipakai omni input
     * "Postingan tertentu". Mengembalikan hasil per token; error bila gagal.
     */
    public function resolveMedia(Request $request, InstagramAccount $account): JsonResponse
    {
        $tokens = collect($request->input('tokens', []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        $results = $tokens->map(fn (string $value): array => $this->resolveMediaToken($account, $value));

        return response()->json([
            'ok' => true,
            'results' => $results->map(fn (array $item): array => [
                'value' => $item['value'],
                'media_id' => $item['media_id'],
                'error' => $item['error'],
            ])->values(),
        ]);
    }

    /**
     * Konversi URL/ID postingan menjadi media_id numerik.
     * Cek DB (comments.media_url) dulu, fallback Graph, lalu cache hasilnya.
     *
     * @return array{value: string, media_id: string, error: ?string}
     */
    protected function resolveMediaToken(InstagramAccount $account, string $value): array
    {
        if (preg_match('/^[0-9]+$/', $value) === 1) {
            return ['value' => $value, 'media_id' => $value, 'error' => null];
        }

        $shortcode = $this->shortcodeFromUrl($value);

        if ($shortcode === null) {
            return ['value' => $value, 'media_id' => '', 'error' => $value];
        }

        $mediaId = $this->mediaIdByShortcode($account, $shortcode);

        if ($mediaId === null) {
            return ['value' => $value, 'media_id' => '', 'error' => $value];
        }

        return ['value' => $value, 'media_id' => $mediaId, 'error' => null];
    }

    /**
     * Ambil shortcode dari URL postingan Instagram (p/reel/tv).
     */
    protected function shortcodeFromUrl(string $value): ?string
    {
        if (preg_match('#^https?://(?:www\.)?instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)#', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * Resolusi shortcode → media_id: DB (comments.media_url) dulu, fallback
     * Graph (medias akun), hasil di-cache sehari supaya tak berulang Graph.
     */
    protected function mediaIdByShortcode(InstagramAccount $account, string $shortcode): ?string
    {
        return Cache::remember('media-shortcode:'.$shortcode, now()->addDay(), function () use ($account, $shortcode) {
            $fromDb = Comment::query()
                ->whereNotNull('media_url')
                ->where('media_url', 'like', '%'.$shortcode.'%')
                ->value('media_id');

            if ($fromDb !== null) {
                return (string) $fromDb;
            }

            try {
                return (new InstagramClient($account))->findMediaIdByShortcode($shortcode);
            } catch (InstagramApiException $e) {
                return null;
            }
        });
    }
}
