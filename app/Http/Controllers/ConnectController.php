<?php

namespace App\Http\Controllers;

use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\MetaOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ConnectController extends Controller
{
    public function start(Request $request, MetaOAuth $oauth): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('oauth_state', $state);

        return redirect()->away($oauth->authorizeUrl($state));
    }

    public function callback(Request $request, MetaOAuth $oauth): RedirectResponse
    {
        $state = $request->query('state');
        $expected = $request->session()->pull('oauth_state');

        if ($state === null || $expected === null || ! hash_equals($expected, (string) $state)) {
            return redirect()->route('settings.index')->with('flash-error', 'State OAuth tidak valid (kemungkinan sesi kadaluwarsa). Coba lagi.');
        }

        if ($request->query('error') !== null) {
            return redirect()->route('settings.index')->with('flash-error', 'OAuth ditolak: '.$request->query('error_description', $request->query('error')));
        }

        $code = $request->query('code');

        if (blank($code)) {
            return redirect()->route('settings.index')->with('flash-error', 'Tidak ada authorization code dari Facebook.');
        }

        try {
            $short = $oauth->exchangeCode($code);
            $long = $oauth->longLived($short);
            $pages = $oauth->listPages($long['token']);
        } catch (InstagramApiException $e) {
            return redirect()->route('settings.index')->with('flash-error', 'Gagal OAuth: '.$e->getMessage());
        }

        if ($pages === [] && $oauth->listPagesError() !== null) {
            return redirect()->route('settings.index')->with('flash-error', 'Gagal memuat daftar halaman Facebook: '.$oauth->listPagesError());
        }

        $request->session()->put([
            'oauth.pending_token' => $long['token'],
            'oauth.pending_expires_at' => $long['expires_at']?->toDateTimeString(),
            'oauth.pending_pages' => $pages,
        ]);

        return redirect()->route('connect.pages');
    }

    public function pages(Request $request): View|RedirectResponse
    {
        if ($request->session()->missing('oauth.pending_token')) {
            return redirect()->route('settings.index')->with('flash-error', 'Sesi pemilihan halaman berakhir. Klik Connect with Facebook lagi.');
        }

        return view('connect.pages', [
            'pages' => $request->session()->get('oauth.pending_pages', []),
        ]);
    }

    public function select(Request $request, MetaOAuth $oauth): RedirectResponse
    {
        $token = $request->session()->pull('oauth.pending_token');
        $pendingExpiresAt = $request->session()->pull('oauth.pending_expires_at');
        $pages = $request->session()->pull('oauth.pending_pages', []);

        if ($token === null) {
            return redirect()->route('settings.index')->with('flash-error', 'Sesi pemilihan halaman berakhir. Klik Connect with Facebook lagi.');
        }

        $pageId = (string) ($request->input('page_id') ?? '');
        $selected = collect($pages)->firstWhere('id', $pageId);

        if ($selected === null || ! ($selected['has_ig'] ?? false)) {
            $this->putPending($request, $token, $pendingExpiresAt, $pages);

            return redirect()->route('connect.pages')->with('flash-error', 'Pilih halaman Facebook yang sudah ter-link ke akun Instagram.');
        }

        $resolved = $this->resolvedFromPending($selected);

        if ($resolved === null) {
            $this->putPending($request, $token, $pendingExpiresAt, $pages);

            return redirect()->route('connect.pages')->with('flash-error', 'Instagram tidak ditemukan pada halaman tersebut. Pastikan akun IG Business/Creator sudah ter-link ke halaman ini (lihat README).');
        }

        try {
            $live = $oauth->resolveInstagramAccountForPage($token, $pageId);

            if ($live !== null) {
                $resolved = $live;
            }
        } catch (InstagramApiException) {
            // Halaman hanya diakses via Business Portfolio: verifikasi per-objek
            // bisa ditolak, namun data sesi sudah diverifikasi saat listing pages.
        }

        InstagramAccount::updateOrCreate(
            ['ig_user_id' => $resolved['ig_user_id']],
            [
                'page_id' => $resolved['page_id'],
                'page_name' => $resolved['page_name'],
                'username' => $resolved['username'],
                'access_token' => $token,
                'token_type' => 'user',
                'token_expires_at' => $pendingExpiresAt !== null ? now()->parse($pendingExpiresAt) : null,
                'token_invalid_at' => null,
                'last_checked_at' => now(),
                'last_check_ok' => true,
                'connected_at' => now(),
            ],
        );

        return redirect()->route('settings.index')
            ->with('flash', "Terkoneksi @{$resolved['username']} lewat halaman {$resolved['page_name']}. Periksa rule & aktifkan bot untuk mulai membalas.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['oauth.pending_token', 'oauth.pending_expires_at', 'oauth.pending_pages']);

        return redirect()->route('settings.index')->with('flash', 'Koneksi dibatalkan.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        InstagramAccount::query()
            ->where('ig_user_id', $request->input('ig_user_id'))
            ->delete();

        $request->session()->forget(['oauth_state', 'oauth.pending_token', 'oauth.pending_expires_at', 'oauth.pending_pages']);

        return redirect()->route('settings.index')->with('flash', 'Akun Instagram dilepas dari sistem.');
    }

    private function resolvedFromPending(array $selected): ?array
    {
        if (empty($selected['ig_user_id'])) {
            return null;
        }

        return [
            'ig_user_id' => (string) $selected['ig_user_id'],
            'username' => (string) ($selected['ig_username'] ?? ''),
            'page_id' => (string) $selected['id'],
            'page_name' => (string) ($selected['name'] ?? $selected['id']),
        ];
    }

    private function putPending(Request $request, string $token, ?string $expiresAt, array $pages): void
    {
        $request->session()->put('oauth.pending_token', $token);

        if ($expiresAt !== null) {
            $request->session()->put('oauth.pending_expires_at', $expiresAt);
        }

        $request->session()->put('oauth.pending_pages', $pages);
    }
}
