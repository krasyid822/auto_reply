# CURRENT.md — Progres & Log Keputusan

Dokumen ini mencatat progres pembangunan **Auto-Reply Komentar Instagram SAE** dan
alasan di balik keputusan teknis (biar sesi OpenCode berikutnya tidak menebak lagi).

- **Konversi URL → media id di input "Postingan tertentu" (2026-08-31)**: textarea
  `media_ids` kini menerima URL postingan `instagram.com/{p|reel|tv}/{shortcode}` —
  otomatis dikonversi ke media_id numerik (JS debounce `setupMediaIdResolver()`,
  endpoint JSON `POST /settings/accounts/{acct}/resolve-media`). **Resolusi**: cek DB
  `comments.media_url` dulu → fallback paginasi daftar media akun saat shortcode
  di permalink cocok (`InstagramClient::findMediaIdByShortcode`), hasil di-cache
  `media-shortcode:{shortcode}` 1 hari. Server `updateMedia` juga mengonversi via
  `resolveMediaToken()` jadi aman walau tanpa JS. Karena shortcode "p/reel/tv" memang
  tidak bisa dikonversi offline (base64 sudah dibuktikan salah), resolusi selalu lewat
  DB/Graph. **69 tes lulus.**

- **Kedua mode pemindaian bisa aktif bersamaan + lock input (2026-08-31)**: kolom
  `media_mode` (radio, 1 pilihan) diganti dua boolean `scan_recent` + `scan_specific`
  (migrasi ...000000, runtime applied; data lama `specific` → recent=false/specific=true).
  `AutoReplyService` gabung hasil `recentMedia()` (bila scan_recent) + media pilihan
  (bila scan_specific), lalu dedupe per `media['id']` (id sama tak dipanggil komentarnya
  dua kali). `SettingController::updateMedia` validasi minimal satu mode centang
  (error key `media_mode`) dan `media_ids` hanya wajib bila scan_specific.
  **Lock = self-lock** (keputusan user): textarea `media_ids` readonly bila
  "Postingan tertentu" nonaktif; input global "Jumlah postingan yang dicek"
  readonly bila tidak ada akun yang mencentang "Postingan terbaru". Dipakai atribut
  `readonly` (bukan `disabled`) agar nilai tetap ter-submit saat form global disimpan.
  JS di `app.js` `setupMediaScanLock()` sinkron state; `npm run build`. **65 tes
  lulus**, pint bersih. **Media id**: bukan shortcode URL (base64 gagal); ambil dari
  header kartu "Postingan {media_id}" di Riwayat Komentar, atau daftar media Graph.

- **Shortcut media di Riwayat Komentar (2026-08-30)**: header tiap kartu "Postingan
  {media_id}" kini tombol — **hover → modal preview postingan via oEmbed Read
  (tokenless, tanpa akses token → tak konsumsi kuota Graph); klik → buka halaman
  postingan utuh** di tab baru. Implementasi: migrasi ...211115 menambah kolom
  `media_url` (nullable) di `comments`; `InstagramClient::recentMedia` minta field
  `permalink` + method `permalink(mediaId)` (fallback resolve saat URL belum ada);
  `AutoReplyService` simpan `media_url` saat create (mode "postingan tertentu" reuse
  dari DB, tak panggil Graph ekstra); `LogController::mediaPreview` resolve URL
  (DB dulu → Graph fallback, update backfill), panggil `graph.facebook.com/{v}/instagram_oembed?url=..&omitscript=true`, cache per media 1 hari. {html} sengaja
  `omitscript=true` → render wajib `https://www.instagram.com/embed.js` +
  `instgrm.Embeds.process()` (dimuat lazily di `setupMediaPreview`, app.js). Rute
  `logs.media-preview` (GET, admin). **PENTING: konversi media_id→shortcode base64
  TERBUKTI SALAH** (permink asli hanya valid dari Graph `fields=permalink`) — jangan
  dipakai lagi. Satukan script `@vite` > `<script>` inline. **64 tes lulus**, pint bersih.

- **Bisa g di riwayat komentar itu mengelompokkan berdasarkan media atau postingan**: ✅
  Selesai dalam 2 iterasi. V1: grup per postingan dalam bentuk tabel (header "Postingan
  {media_id}" + badge jumlah komentar, kolom "Media" dihapus). **User menilai susah
  dibedakan** → V2: **satu kartu per postingan** — tiap kartu punya header payung (judul
  fuchsia "Postingan {media_id}" + badge "X komentar", media_type jika ada) dan isi daftar
  komentar (username, teks, balasan, status badge, waktu, tombol "Coba lagi" untuk failed).
  Struktur berubah dari `<table>/<tbody>` ke `<div id="logs-list">` → `app.js`
  `setupLogsLive` ikut disesuaikan (#logs-list, bukan #logs-tbody; `npm run build`).
  `LogController::index` mengelompokkan halaman aktif via `groupBy('media_id')` (order grup
  mengikuti komentar terbaru). Filter (status/q), paginasi 25, dan AJAX partial
  `logs._rows` tetap bekerja. Tes `test_logs_groups_comments_by_media`. **60 tes lulus**,
  pint bersih.

- **Siap deploy cPanel: sekali-cron (2026-08-30)**: `routes/console.php` menambah
  `queue:work database --tries=3 --stop-when-empty` `->everyMinute()` (append ke
  `storage/logs/queue-worker.log`) di samping polling 5 menit. Dengan ini **satu baris
  cron `php artisan schedule:run` per menit** sudah menangani polling + drain queue
  sekaligus tanpa systemd (cPanel tak izinkan proses jalan terus). "Multi worker" di
  cPanel = tambah baris cron identik bila butuh paralel. Di mesin dev, unit systemd
  `auto-reply-worker` tetap berguna (respond instan), unit scheduler menjalankan
  `schedule:work` → jadwal di atas ikut ditarik. **59 tes lulus**, pint bersih.

- **Debug nyata + keep-alive systemd (2026-08-30)**: komentar user tidak dibalas —
  bukan token/bot, tapi (1) cron tidak terpasang (`crontab: command not found`) sehingga
  scheduler tak pernah jalan (`last_polled_at` NULL), dan (2) worker queue tidak berjalan
  sehingga balasan hasil match rule nyangkut di queue. Fix: tambal bug
  `SettingController::updateMedia` baris 115 — ditulis `.withInput()` (operator konkatenasi
  string) harusnya `->withInput()` → bikin "Call to undefined function". Verifikasi manual
  `instagram:process-comments` sukses (komentar "harga" → match → `replied` 20:30:03).
  Keep-alive memakai **systemd user service** (dev-only; produksi cPanel pakai Cron Jobs +
  worker manual): `~/.config/systemd/user/auto-reply-scheduler.service` (schedule:work) &
  `auto-reply-worker.service` (queue:work --tries=3 --timeout=120), enable + `loginctl
  enable-linger`. Terbukti polling otomatis: 20:35:00 `instagram:process-comments` DONE,
  `last_polled_at` 20:35:17, dedup normal (`duplikat 4`, tak ada balas ganda). Log polling
  di `storage/logs/instagram-poll.log`. **59 tes lulus**, pint bersih.

- **Rename label UI ramah awam (2026-08-30)**: label teknis diganti bahasa sehari-hari,
  rute/controller/DB tidak berubah. Peta: Dashboard→Beranda, Rules→Aturan Balasan,
  Log→Riwayat Komentar, Settings→Pengaturan, Bot→Balasan otomatis, Poling→Pengecekan,
  "Poll now"→"Cek sekarang", status pending/replied/skipped/failed→Menunggu/Dibalas/
  Dilewati/Gagal (badge + filter), "Connect with Facebook"→"Hubungkan dengan Facebook",
  Retry→Coba lagi, "Dashboard terkunci"→"Aplikasi terkunci". Asersi DashboardPagesTest
  ikut diperbarui. **51 tes lulus**, pint bersih.

- **Tombol "🔒 Kunci" sembunyi saat App Lock nonaktif (2026-08-30)**: header hanya
  menampilkan tombol kunci bila profil aktif punya App Lock yang benar-benar berfungsi
  (`enabled && pin_hash !== null`), selaras kondisi middleware `EnsureAdminSession`. Tes
  baru `test_lock_button_hidden_when_app_lock_inactive`. **52 tes lulus**, pint bersih.

- **Pilihan postingan + tampilan sisa kuota (2026-08-30)**: label "Max media per siklus"
  diganti "Jumlah postingan yang dicek" (view settings, samakan value key). Per akun
  ditambah mode pemindaian: `media_mode` (`recent`/`specific`) + `media_ids` (array) di
  `instagram_accounts` (migrasi ...152825, runtime applied). Mode "Postingan tertentu"
  memanggil `comments(mediaId)` langsung per ID pilihan → hemat 1 panggilan daftar media
  per siklus; form-nya di kartu akun di Pengaturan (route `settings.account-media`,
  validasi: nilai spesifik wajib ≥1 ID numerik, maks 50). Kuota API dihitung lokal per
  akun (`api_calls_window_start` + `api_calls_count`, jendela 1 jam bergulir, batas
  `INSTAGRAM_CALLS_PER_HOUR`=200): tiap request Graph di `InstagramClient` memanggil
  `markApiCall()`; Meta tidak menyediakan endpoint sisa kuota. Beranda menampilkan
  "Sisa kuota ±X/jam" per akun. **56 tes lulus**, pint bersih.

- **UI realtime via AJAX fetch() (2026-08-30)**: tanpa dependency JS baru — Blade tetap
  satu-satunya sumber markup, JS (app.js) fetch periodik 15 detik dan ganti fragmen HTML.
  Beranda: endpoint `GET /dashboard/live` (DashboardController::live) mengembalikan
  `dashboard/_live.blade.php` (kartu statistik/kuota/token + aktivitas terakhir),
  `#live-dashboard` di-refresh + indikator "Diperbarui HH:MM:SS". Riwayat Komentar:
  `GET /logs?partial=1` mengembalikan `logs/_rows.blade.php` (tbody saja, data-count &
  data-base-url); filter q/status dipertahankan saat refresh, header "N komentar"
  ikut diperbarui. Pengaturan: "▶ Cek sekarang" dikirim via fetch, `pollNow()` balas
  JSON (`ok`+`message`, `wantsJson`) dan hasil tampil inline di `#poll-status`. Semua
  poller berhenti saat tab tidak terlihat (document.hidden) dan menahan request ganda
  (busy flag). **59 tes lulus**, pint bersih; `npm run build` dijalankan.

## Status

- [x] Konfigurasi .env + config
- [x] Migrasi + model + seeder
- [x] Service + command + job + scheduler
- [x] Dashboard web (Blade + Tailwind)
- [x] Tests + lint
- [x] Migrate + seed + build asset (MySQL `auto_reply` nyata)
- [x] Dokumentasi: README (Setup Meta/no-expiry/ganti akun), SYSTEM.md, UX.md
- [x] AGENTS.md & CLAUDE.md diperbarui (setup, perintah wajib, konvensi)
- [ ] Verifikasi nyata (koneksi + polling) — **terhalang: belum ada token valid dari app
      "Auto-reply" (1597930428679794) yang mengelola Page+IG; token paste lama milik app lain
      (debug_token → 190) dan hanya melihat Page Lumen5**
- [ ] OAuth "Connect with Facebook" diuji end-to-end (login browser → pilih halaman → polling)

## Keputusan yang Sudah Dibulatkan (hasil diskusi)

1. **Konten balasan**: template statis berdasarkan keyword (`auto_reply_rules`),
   case-insensitive `contains`. Bukan AI.
2. **Jenis balasan**: *public reply* di thread komentar (`POST /{comment_id}/replies`).
   Bukan private DM.
3. **Deteksi komentar**: **polling scheduler** (bukan webhook) — cukup, tanpa App
   Review/HTTPS publik. Interval default 5 menit (rate limit ±200 calls/jam).
4. **Bundle Meta**: "Instagram Login with Facebook" → base `https://graph.facebook.com`
   + **token FB/Page**. Token Instagram (IGAA) TIDAK dipakai — tak bisa baca komentar.
5. **Database**: MySQL/MariaDB runtime (db `auto_reply`, root@127.0.0.1 — verifikasi
   OK). Test dulu SQLite `:memory:` → **diubah ke MySQL db `auto_reply_test`**
   (pdo_sqlite tidak terpasang di CLI, tanpa sudo; PHPUnit env override di `phpunit.xml`).
6. **Proses balasan**: queue database + worker (`ReplyToCommentJob`), sehingga polling
   tidak diblokir IO API.
7. **Akses dashboard**: TANPA email/password. Profil admin cukup nama (tabel `users`,
   email/password nullable). Pengaman = **App Lock per admin** (PIN + timeout idle).
8. **Koneksi akun IG**: OAuth "Connect with Facebook" (tombol) + token tersimpan
   terenkripsi (cast `encrypted`, APP_KEY). Opsional fallback manual (README).
9. **Data akun IG**: `17841406718308216` (@rakurn299, MEDIA_CREATOR) dipakai sebagai
   akun *uji*; ter-link ke Page **"Auto-reply"** (bukan Lumen5). Page `Lumen5`
   `426360120565286` BELUM ter-link ke IG (guard onboarding menangani).
10. **Multi-akun (2026-08-30)**: sistem TIDAK terikat satu akun. `instagram_accounts`
    menampung banyak akun; komentar diatribusi via `comments.ig_user_id` (unik gabungan
    `(ig_user_id, comment_id)`). Tidak ada hardcode IG id/username/page di kode/config —
    identitas datang dari OAuth per halaman. Setelan global (bot, cooldown, max_media)
    tetap lintas akun.

## Fakta API Meta yang Terverifikasi (2026)

- `GET graph.facebook.com/v26.0/{ig}_user_id/media` → daftar media (pagination `after`).
- `GET /{media_id}/comments?fields=id,text,username,timestamp&after=...` → komentar.
- `POST /{comment_id}/replies?message=...` → balasan publik.
- Nested field `comments{...}` WAJIB URL-encoded di permintaan mentah.
- Permissions granted: `instagram_basic, instagram_manage_comments,
  pages_read_engagement, pages_manage_metadata, pages_show_list`.
- Token user long-lived ±60 hari (refresh manual/login ulang); Page token = long-lived
  tanpa jadwal expire tapi bisa mati (460/-perubahan password/492/peran dicabut/
  ±90 hari data-access). Detail: README `#### Link page Facebook ke akun Instagram
  untuk Token No-Expiry`.

## Catatan Lingkungan

- Laravel 13, PHP 8.5, Composer 2.10. `.npmrc` `ignore-scripts=true` → asset selalu
  lewat `composer run setup` / build manual.
- BUKAN git repo (belum ada `.git`). Kredensial di `.env` aman (gitignore).
- Dev server: `composer run dev` (`php artisan dev`, port 8000).

## Progres per Hari

### 2026-08-30 — pembahasan + mulai implementasi
- Semua keputusan desain sistem & UX dibulatkan (lihat di atas).
- `.env` MySQL + kredensial Meta diverifikasi.
- Implementasi kode dimulai (CURRENT.md dibuat).

### 2026-08-30 — implementasi selesai + tes lulus
- `.env`/`.env.example`: `DB_CONNECTION=mysql`, `DB_HOST/PORT`, `FACEBOOK_CLIENT_*`,
  `INSTAGRAM_API_BASE/_USER_ID/_USERNAME`, `FACEBOOK_SCOPES`. `config/instagram.php` dibuat.
- Migrasi: `users` (email/password nullable) + `app_locks`, `instagram_accounts`,
  `settings`, `auto_reply_rules`, `comments`. Model + relasi (`appLock`, `rule`).
- `Setting::singleton()` — **bug fixed**: `id` harus di `$fillable` karena
  `firstOrCreate(['id'=>1])`; tanpa itu setiap panggilan membuat baris baru (id auto-increment).
- Services: `InstagramClient` (media/comments/replyTo/accountInfo + deteksi 429/190),
  `MetaOAuth` (authorize/exchangeCode/longLived + resolve akun IG),
  `AutoReplyService` (dedup, skip komentar sendiri via username+`from.id`, match rule, dispatch).
- `ReplyToCommentJob` (tries 3, backoff 120, rilis saat 429, gagal permanen saat token expired).
- Commands `instagram:process-comments` & `instagram:test-connection`; scheduler 5 menit
  (`withoutOverlapping`). Seeder: 1 user Admin + Setting + 4 contoh rule.
- Dashboard web: middleware `admin.session`, profil (nama) + switch, App Lock (PIN+timeout),
  Rules CRUD+toggle, Logs (filter status/cari/retry), Settings (connect/disconnect/test/poll-now),
  semua view Blade + Tailwind (session `flash`/`flash-error`).
- Assets di-build (`npm install && npm run build`). Migrasi + seed jalan di MySQL `auto_reply`
  (token akun IG ter-encode EAA disimpan; **ternyata sudah expired**).
- **Bug fixed lain**: `ProcessCommentsCommand` `blank(last_polled_at)` dibalik;
  `InstagramClient::accountInfo()` GET `/{ig_user_id}`; `resolveIgUserId()` fallback config;
  `resolveToken()` throw saat kosong; template view `@{{ }}` → `{{ }}`.
- **Tests**: 28 tes lulus (client HTTP fake, AutoReplyService dedup/skip/cooldown,
  job, command, halaman dashboard). PHPUnit dialihkan ke MySQL `auto_reply_test`
  (pdo_sqlite tidak ada di CLI, tanpa sudo). `vendor/bin/pint` dibersihkan.
- **Blocking untuk live**: token FB (EAA) expired 2026-08-30 00:00 PDT. Sistem butuh
  OAuth reconnect: setup Meta app (produk **Facebook Login**; redirect `localhost`
  diizinkan otomatis di dev mode, produksi perlu *Valid OAuth Redirect URIs*) → klik
  "Connect with Facebook".
- **Dokumentasi selesai**: README (setup Meta Developer, FACEBOOK_SCOPES, quick start,
  token 60-hari, link Page→token no-expiry, cara sambung/ganti akun), `SYSTEM.md`
  (arsitektur+skema+keputusan), `UX.md` (alur admin + batasan), AGENTS.md/CLAUDE.md
  (perintah wajib: `composer run test`, `vendor/bin/pint`).
- **Catatan kecil → FIX (2026-08-30)**: indikasi token expired sebelumnya hanya
  mengandalkan `token_expires_at` (proyeksi) → dashboard bisa menampilkan "valid"
  padahal Meta menolak. Ditambah kolom `token_invalid_at`, `last_checked_at`,
  `last_check_ok` di `instagram_accounts` (migrasi `...000006`): client menandai
  invalid saat error 190/460/492, dan valid saat `accountInfo()` sukses; OAuth
  reconnect membersihkan flag. `isExpired()` mempertimbangkan flag. 31 tes hijau.
- Tilik berikut: uji OAuth end-to-end (token segar) → verifikasi polling+balasan nyata.
- **FIX OAuth "Akun Instagram tidak ditemukan" (2026-08-30)**: root cause = token user
  hanya mengelola Page **Lumen5** (tidak ter-link IG); IG @rakurn299 ter-link ke page lain
  ("Auto-reply") yang tak tampil di `/me/accounts` token. Bukan bug kode.
  → Alur dinamis: callback OAuth menukar code→long-lived, memuat daftar halaman
  (`MetaOAuth::listPages`, per-page cek `instagram_business_account`), simpan
  `oauth.pending_*` di session, redirect ke **pilih halaman** (`/connect/pages`).
  User memilih halaman ber-label "IG terhubung"; `resolveInstagramAccountForPage()`
  verifikasi ulang → simpan/replace akun (single-account). Belum ada page ter-link →
  panduan otomatis buat Page & sambungkan IG di layar.
  - Kolom baru `page_id`,`page_name` di `instagram_accounts` (migrasi ...135233) utk
    menampilkan halaman terhubung di Settings.
  - Routes baru: `connect.pages` GET, `connect.select` POST, `connect.cancel` POST.
  - Tests: `ConnectControllerTest` dirombak & diperluas ke 13 tes (21 ke skema 44
    seluruh suite; semuanya lulus, pint bersih).
- **REFACTOR MULTI-AKUN (2026-08-30)**: hilangkan ketergantungan satu akun (@rakurn299 /
  Page "Auto-reply"). Permintaan user: tidak boleh hardcode, akun IG lain akan menyusul.
  - Migrasi ...142802: `comments.ig_user_id` (nullable BIGINT) + index; unik gabungan
    `(ig_user_id, comment_id)` menggantikan unik `comment_id` → dedup per akun.
  - `InstagramClient` dikonstruksi dgn akun terikat; `resolveToken()`/`resolveIgUserId()`
    TIDAK lagi fallback ke `InstagramAccount::first()`/config → lempar bila kosong.
    `markStoredToken*` hanya menyentuh akun terikat (bukan token injeksi).
  - `AutoReplyService::process()` loop SEMUA akun terhubung (per-akun `processAccount()`;
    rate limit/error token → cooldown + henti siklus); summary tambah `account_seen`;
    komentar disimpan dgn `ig_user_id`.
  - `ReplyToCommentJob::handle()` tanpa argumen client; resolve akun via
    `comment->ig_user_id`; gagal permanen bila akun sudah dicabut.
  - `ConnectController::select()` pakai `updateOrCreate` (TIDAK menghapus akun lain);
    `disconnect()` menghapus hanya akun `ig_user_id` bersangkutan.
  - `SettingController::index`/`DashboardController`/`TestConnectionCommand` menangani
    daftar akun; `settings.test` di-parameter `ig_user_id`; command `instagram:test-connection`
    opsi `--user=` (IG user id). `config/instagram.php` BERSIH dari `ig_user_id`/`ig_username`.
  - `opencode.json` `INSTAGRAM_ACCOUNT_ID` → `{env:INSTAGRAM_ACCOUNT_ID}`.
  - View Settings menampilkan daftar akun (Test koneksi + Lepas per-akun, tombol
    "+ Connect akun baru"); Dashboard menampilkan ringkasan status semua akun & kolom
    "Akun" di aktivitas terakhir.
  - **50 tes lulus** (tambah: multi-akun di AutoReplyService, job akun hilang,
    select mempertahankan akun lain, disconnect per-akun), pint bersih. Migrasi runtime
    `auto_reply` dijalankan.
- **FALLBACK BUSINESS PORTFOLIO (2026-08-30)**: user melaporkan Page "Auto-reply" tidak
  muncul karena diakses lewat Portofolio Bisnis (Business Manager), bukan admin personal.
  `/me/accounts` hanya menampilkan page yang jadi Admin/Editor langsung — akses "pinjaman"
  via bisnis tak tampil. Solusi kode:
  - `listPages()` kini menggabungkan `/me/accounts` + `/me/businesses` → `owned_pages` +
    `client_pages` (dedup per page-id; `instagram_business_account` dari edge dipakai
    langsung, tak perlu per-page fetch). Kegagalan salah satu sumber TIDAK fatal; status
    error terakhir disimpan (`listPagesError()`), dan hanya disorotkan bila SEMUA sumber
    gagal (flash error di Settings).
  - `ConnectController::select()`: verifikasi ulang per-objek yang ditolak untuk page
    bisnis → pakai data sesi (`resolvedFromPending`) yang sudah diverifikasi saat listing.
  - Scope `business_management` ditambahkan ke `FACEBOOK_SCOPES` (config default +
    `.env` + `.env.example`); di dev mode otomatis disetujui utk admin app, produksi
    butuh App Review.
  - Tests: +2 (listing page via business & select fallback) → **50 tes lulus**, pint bersih.
  - Catatan infra: mesin dev punya akses internet — di test, URL yang TIDAK difake akan
    benar-benar dipanggil ke graph.facebook.com (balas error Graph). Selalu sertakan
    catch-all `graph.facebook.com/*` di `Http::fake` bila listPages ikut dipanggil.
  - **Settings baru: "Balas komentar dari akun sendiri" (2026-08-30)**: kolom
  `settings.reply_to_own_comments` (boolean, default false) + migrasi ...151252 (runtime
  applied). Saat mati, komentar akun sendiri tetap dilewati; saat aktif, komentar sendiri
  diproses normal (dedup → match rule → antre balasan). UI toggle baru di Settings
  (label ramah awam). Validasi controller + test (settings update & own-comment replied).
  **51 tes lulus**, pint bersih.