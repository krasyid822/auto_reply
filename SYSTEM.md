# SYSTEM.md — Arsitektur & Cara Kerja Teknis

Kesimpulan proses planning → implementasi **Auto-Reply Komentar Instagram SAE**.
Dokumen ini menjelaskan komponen, alur data, dan alasan keputusan teknis.

## Gambaran Alur

```
┌────────────┐   cron / schedule:run (setiap menit)
│ Scheduler  │ ───────────────────────────────► instagram:process-comments
└────────────┘                                          │
                                                        ▼
                                        ┌─────────────────────────────┐
                                        │ AutoReplyService::process() │
                                        └─────────────────────────────┘
        ┌───────────────────────────────┼──────────────────────────────┐
        ▼ media                        ▼ komentar                    ▼ dedup & rule
 GET /media (pagination)      GET /{media}/comments        check Comment::where(comment_id)
        │                                                        │
        │   case-insensitive contains                           ▼
        │                                                    match → dispatch ReplyToCommentJob
        ▼                                                          │
 ┌────────────────────┐                                   worker  │
 │  instagram_accounts │  token (encrypted)         ┌───────▼────────┐
 └────────────────────┘                            │ queue:work      │
        ▲                                          │ (database)      │
        └──── OAuth "Connect with Facebook" ◄──────└─────────────────┘
                                                  POST /{comment}/replies
```

1. **Scheduler** setiap menit menjalankan `instagram:process-comments` (dengan
   `withoutOverlapping`). Di dalamnya *proses* di-throttle oleh
   `settings.poll_interval_minutes` (default 5) + pengecekan cooldown.
2. **Proses (polling, sinkron)**:
   - baca `settings.bot_enabled`, **semua** akun (`instagram_accounts`), cooldown;
   - **loop per akun**: ambil media terbaru (`GET /{ig_user_id}/media`), lalu komentar tiap media
     (`GET /{media_id}/comments`), paginasi `after`;
   - **skip** komentar kita sendiri (username akun atau `from.id == ig_user_id`);
   - **dedup per akun**: `(ig_user_id, comment_id)` yang sudah ada di tabel `comments` dilewati;
   - cocokkan teks komentar dengan rule aktif (urutan `sort_order`, `contains` tak peka huruf);
   - cocok → simpan `pending` + `reply_text` + `rule_id` + `ig_user_id`, kirim `ReplyToCommentJob`;
   - tidak cocok → simpan status `skipped`.
   - rate-limit/error token pada satu akun → cooldown & hentikan siklus (semua akun).
3. **Worker** `php artisan queue:work database` memproses job → `POST /{comment_id}/replies`.
   - sukses → status `replied`, `replied_at`, `error=null`;
   - rate-limit (429) → job di-*release* (backoff 120 dtk, tries 3);
   - token expired (190) → status `failed` + pesan reconnect;
   - error lain → status `failed` + ringkasan error (bisa **Retry** dari Logs).

## Komponen Kode

| Komponen | Lokasi | Peran |
|---|---|---|
| Config | `config/instagram.php` | base URL, version, client id/secret, redirect url, scopes (tanpa identitas akun) |
| HTTP client | `app/Services/Instagram/InstagramClient.php` | media, comments, reply, accountInfo; melempar `InstagramApiException` terklasifikasi (429/190) |
| OAuth | `app/Services/Instagram/MetaOAuth.php` + `ResolvesInstagramAccountTrait` | dialog, tukar code, long-lived token, resolve IG Business acct dari FB token |
| Proses | `app/Services/AutoReplyService.php` | satu siklus polling: media → komentar → dedup → rule → dispatch |
| Job | `app/Jobs/ReplyToCommentJob.php` | balas 1 komentar via API (tries 3, backoff 120) |
| Command | `app/Console/Commands/ProcessCommentsCommand.php`, `TestConnectionCommand.php` | CLI polling & cek koneksi |
| Scheduler | `routes/console.php` | `schedule->command('instagram:process-comments')->everyFiveMinutes()->withoutOverlapping()` |
| Web | `app/Http/Controllers/*` + `routes/web.php` | profil, lock, connect, dashboard, rules, logs, settings |
| Auth lapisan | `app/Http/Middleware/EnsureAdminSession.php` | sesi profil + App Lock (PIN/timeout) |

## Model & Skema (ringkas)

- **users** — profil admin (hanya `name` wajib; `email`/`password` nullable → **tanpa login**).
- **app_locks** — per admin: `enabled`, `pin_hash`, `timeout_minutes`, `last_login_at`. Relasi `user.appLock` (hasOne).
- **instagram_accounts** — **multi-akun** (1 baris per akun IG): `ig_user_id`, `username`,
  `access_token` (**cast encrypted**), `token_type`, `token_expires_at`, `connected_at`,
  `page_id`, `page_name`, plus flag `token_invalid_at`, `last_checked_at`, `last_check_ok`.
  Identitas tak pernah di-hardcode; dibuat via `updateOrCreate(['ig_user_id'])` pada OAuth.
- **settings** — singleton id=1: `bot_enabled`, `poll_interval_minutes`, `max_media_per_cycle`, `poll_cooldown_until`, `last_polled_at`. (`Setting::singleton()` memakai `firstOrCreate(['id'=>1])`.)
- **auto_reply_rules** — `keyword`, `reply_text`, `is_active`, `sort_order`.
- **comments** — `ig_user_id` + `comment_id` (unik gabungan → dedup per akun), `media_id`,
  `text`, `username`, `from_user_id`, `status` (`pending/replied/skipped/failed`), `reply_text`,
  `rule_id`, `error`, `replied_at`. Relasi `account()` mengarah ke `instagram_accounts`.

## Keputusan Teknis & Alasannya

1. **Public reply, bukan DM** — aturan Instagram: aplikasi/Bot **tidak boleh** membalas DM,
   reply publik di komentar diizinkan. Template statis ditentukan admin (bukan AI) →
   prediktif & hemat biaya API.
2. **Polling, bukan webhook** — tanpa App Review/HTTPS publik/tantangan verifikasi;
   cukup cron. Rate limit ±200 calls/jam dimitigasi interval 5 menit + max media/siklus.
3. **Bundle "Instagram Login with Facebook"** (`graph.facebook.com`) — token FB dengan
   scope `instagram_manage_comments` bisa baca komentar. Token bundle Instagram (IGAA,
   `graph.instagram.com`) **tidak**; itu diverifikasi langsung ke Meta.
4. **Queue database + worker** — memisahkan polling (IO API) dari pekerjaan balas agar
   siklus polling cepat dan error rate-limit tidak menggagalkan siklus.
5. **Token 60 hari, refresh manual** — per kebijakan Meta; user harus reconnect.
   Opsional Page token no-expiry (lihat README). Semua token dienkripsi (APP_KEY).
6. **Dashboard tanpa login** — sesuai kebutuhan "akses cepat". Pengaman diganti
   **App Lock per admin** (PIN di-hash bcrypt + timeout idle lewat session timestamp).
7. **Test memakai MySQL** — `auto_reply_test` (phpunit.xml override) karena pada mesin
   dev `pdo_sqlite` tidak tersedia (tanpa sudo). Migration berjalan tiap sesi tes.

## Penanganan Error Graph API

- `429` / kode `429` → `InstagramApiException::isRateLimited()` → cooldown `settings.poll_cooldown_until`
  (interval×3, min 10 menit) + job `release(120)`.
- `190` → `isTokenExpired()` → job set `failed` "Access token kedaluwarsa — hubungkan ulang akun."
- selainnya → `failed` + pesan; tersedia tombol **Retry** di Logs.

## Operasional

- Cron: `* * * * * php artisan schedule:run` (menjalankan semua command terjadwal).
- Worker: `php artisan queue:work database` (sebaiknya selalu berjalan).
- Manual: `php artisan instagram:process-comments [--media=N]`, `instagram:test-connection`.
- Migrasi: `php artisan migrate --seed`. Env baru: `composer run setup`.

## Lingkungan yang Terverifikasi

- Meta: `APP_ID=1597930428679794`, IG uji `17841406718308216` (`rakurn299`, MEDIA_CREATOR)
  ter-link ke Page **"Auto-reply"**; Page `Lumen5` `426360120565286` **belum ter-link ke IG**.
  Base `graph.facebook.com`, version `v26.0`. Scope confirmed: `instagram_basic,
  instagram_manage_comments, pages_read_engagement, pages_manage_metadata, pages_show_list`;
  + `business_management` ditambahkan utk membaca Page lewat Business Portfolio
  (dev mode otomatis disetujui; produksi butuh App Review).
- OAuth flow: callback → tukar code → long-lived token → daftar halaman (`/me/accounts`
  = page yang jadi Admin/Editor langsung **plus fallback Business Portfolio**
  `/me/businesses` → `owned_pages` + `client_pages` utk page yang diakses via
  Business Manager) → session `oauth.pending_*` → `/connect/pages` → pilih halaman →
  verifikasi ulang (bila ditolak per-objek, pakai data sesi) → simpan akun. Belum ada
  halaman ter-link IG → panduan buat page + sambungkan. Kegagalan salah satu sumber
  listing tidak fatal; bila SEMUA sumber gagal → flash error di Settings.
- DB: MySQL root@127.0.0.1:3306 → db `auto_reply`; test → `auto_reply_test`.
- Bukan git repo; `.npmrc` `ignore-scripts=true` → build via `composer run setup`/`npm run build`.
- Token FB lama (EAA) **expired 2026-08-30** → menunggu OAuth reconnect (app "Auto-reply").