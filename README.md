# Auto-Reply Komentar Instagram SAE


## Template
### Setup using AI
I'm building a new Laravel application.

Fetch and follow the instructions from https://laravel.com/for/agents. Treat the returned Markdown as the 
source of truth for how to install and set up Laravel in this session.

### New project
composer create-project laravel/laravel .
npm install && npm run build

### Run
composer run dev

### Undo / checkpoint di opencode
/review
/timeline


## Laravel Boost
### AI Guider
To augment Laravel Boost with your own custom AI guidelines, add .blade.php or .md files to your 
application's .ai/guidelines/* directory.

### Install
composer require laravel/boost --dev
php artisan boost:install


## Quick Start
- `composer install && npm run build` → `cp .env.example .env` → `php artisan key:generate` → `php artisan migrate --seed`.
- Worker balasan: `php artisan queue:work database` (selalu berjalan).
- Cron polling: `* * * * * php artisan schedule:run` (tanpa cron: tombol **Poll now** di Settings).
- Manual: `php artisan instagram:process-comments`, `php artisan instagram:test-connection`.
- Tes: `composer run test` (DB test: `auto_reply_test`). Format: `vendor/bin/pint`.
- Dashboard: `http://localhost:8000/profil` → buat profil → Settings → Connect akun.


## Setup Meta Developer

1. **Buat aplikasi** di <https://developers.facebook.com/apps> → *Create App*
   (pilih jenis yang mengaktifkan Instagram Graph API, misal "Business").
2. **Tambah produk**:
   - **Instagram → Instagram Login with Facebook** (bundle inti).
   - **Facebook Login** (WAJIB untuk tombol "Connect with Facebook").
3. **App Settings → Basic**: salin *App ID* dan *App Secret* ke `.env`:

   ```dotenv
   APP_ID="..."
   APP_SECRET="..."
   FACEBOOK_CLIENT_ID="${APP_ID}"       # atau isi sama
   FACEBOOK_CLIENT_SECRET="${APP_SECRET}"
   FACEBOOK_REDIRECT_URI="http://localhost:8000/auth/facebook/callback"
   FACEBOOK_API_VERSION="v26.0"
   ```

4. **Redirect URI**: di *Development mode*, pengalihan ke `http://localhost:8000/auth/facebook/callback`
   diizinkan otomatis (tanpa didaftarkan di *Valid OAuth Redirect URIs*). Yang penting:
   `FACEBOOK_REDIRECT_URI` di `.env` persis sama dengan tujuan browser.
   Saat **produksi** (mode Live / domain publik/HTTPS), daftarkan URL persisnya di
   **Facebook Login → Settings → Valid OAuth Redirect URIs**. Biarkan *Use Strict Mode* aktif.
5. **Scope** (`.env` `FACEBOOK_SCOPES`, dipisah koma):
   `instagram_basic, instagram_manage_comments, pages_show_list, pages_read_engagement, pages_manage_metadata, business_management`
   (`business_management` dipakai untuk membaca Page yang diakses lewat **Portofolio
   Bisnis / Business Manager** — bila Page Anda tidak jadi Admin/Editor langsung di akun
   personal, `/me/accounts` tidak menampilkannya; sistem akan fallback ke
   `owned_pages`/`client_pages` milik bisnis).
6. **Akun sendiri**: app tetap *Development mode* sudah cukup (Anda admin/test role);
   baru butuh **App Review + Live** bila akan dipakai akun orang lain.
7. Setelah itu, di aplikasi: **Settings → Connect with Facebook**.

> Base API: `https://graph.facebook.com/v26.0` (bundle "Instagram Login with Facebook").
> Token Instagram (IGAA, `graph.instagram.com`) **tidak** dipakai — tidak bisa baca komentar.

### Catatan untuk Token Expire 60 hari

- OAuth menghasilkan *short-lived token* (±1 jam) → otomatis ditukar ke **long-lived
  user token ±60 hari** (`fb_exchange_token`, lihat `MetaOAuth::longLived`).
- Saat expired balasan gagal dengan error `190`; komentar berstatus `failed`.
  **Perbaikan**: Settings → **Connect with Facebook** lagi (login ulang). Meta tidak
  mengizinkan refresh otomatis tanpa interaksi user.
- Opsional anti-repot: gunakan **Page token no-expiry** (langkah di bawah).

#### Link page Facebook ke akun Instagram untuk Token No-Expiry

1. Buat/gunakan **Facebook Page** (disarankan milik Anda sendiri).
2. Akun Instagram target harus tipe **Business** atau **Creator**.
3. Di aplikasi Instagram: *Settings → Linked Accounts → Facebook* → pilih Page → **Hubungkan**.
4. Konfirmasi relasi di Meta Business (Business Settings → Page → *Linked Instagram accounts*).
5. Ambil **Page access token** (Business Manager System User / Graph API Explorer milik Page).
6. Cek id IG: `GET /me/accounts` → `GET /{page_id}?fields=instagram_business_account`.
7. Masukkan token Page (menu manual/`tinker`) ke `instagram_accounts.access_token`,
   `token_type='page'`, `token_expires_at=null`. Sistem memakai token tersimpan di tabel
   `instagram_accounts` → praktis tanpa jadwal expire.

> Catatan token Page "no-expiry" bersyarat — bisa dicabut bila: pemilik ganti password
> Facebook (error 460), peran dicabut (492), tanpa aktifitas data-access ±90 hari.

## Cara ganti/sambungin akun Instagram ke sistem

1. Pastikan Meta app berisi produk **Facebook Login** dan akun IG Business/Creator
   ter-link ke Page (dev mode: `localhost` redirect mengizinkan otomatis).
2. Buka `http://localhost:8000/profil` → buat/pilih profil (cukup nama).
3. **Settings** → **Connect with Facebook** → login → setujui izin Instagram (Business/Creator ter-link Page).
4. Aplikasi menampilkan **daftar Halaman Facebook** milik akun tersebut beserta status link ke IG:
   pilih halaman yang bertanda *IG terhubung*. Belum ter-link sama sekali? Ada panduan otomatis
   untuk membuat Page & menyambungkannya ke akun IG (Business/Creator).
5. Kembali aplikasi menampilkan `@username` **terhubung** (via halaman yang dipilih); token tersimpan terenkripsi (APP_KEY).
6. Aktifkan **Bot**, atur interval → **Simpan**; jalankan worker + cron.
7. Verifikasi: Dashboard → **Test koneksi**; coba **Poll now** / `instagram:process-comments`.

**Ganti akun**: Settings → **Lepas** (menghapus `instagram_accounts`) → **Connect** dengan
akun lain. Log komentar lama tetap utuh; dedup mulai dari nol.

# Events
## Hal yang sudah didiskusikan ke opencode
1. optimize AGENTS.md
2. mau buat aplikasi apa
3. konekin ke Meta Developer + tutup hasil sepakat ke SYSTEM.md

## Hal yang akan didiskusikan ke opencode
4. bagaimana user akan menggunakan aplikasi ini + tutup hasil sepakat dengan file UX.md


# Notes
## Catatan untuk pengembang selanjutnya
### Ekspor/Impor database

Cadangkan/restore database runtime (MySQL):

```sh
# ekspor
mysqldump -h 127.0.0.1 -u root -p auto_reply > backup_auto_reply.sql

# impor
mysql -h 127.0.0.1 -u root -p auto_reply < backup_auto_reply.sql
```

Catatan: tabel `settings` id=1, token di `instagram_accounts` tersimpan terenkripsi
(APP_KEY harus sama saat impor agar bisa dibaca). DB test (`auto_reply_test`) tidak
ikut dicadangkan.

## Catatan untuk pengguna aplikasi


# Sesi opencode


# Current Planning
buat SYSTEM.md untuk kesimpulan hasil planning bagaimana sistem aplikasi ini dibangun
buat UX.md untuk kesimpulan hasil planning bagaimana nanti user mengoperasikan/menggunakan aplikasi ini
tanyakan opencode diakhir diskusi apa folder/file yang ia akan buat saat beralih kemode Build
setelah mengubah mode Plan ke Build perintahkan saja 'mulai'
untuk fitur app lock berfungsi mengikuti sistem multi-admin 1 akun instagram, app lock punya konfigurasinya sendiri untuk tiap admin
Catatan teknis kecil: OAuth butuh tambahan produk Facebook Login di Meta app Anda + isi Valid OAuth Redirect URIs (publik/HTTPS di produksi; localhost di dev) — akan saya masukkan ke README ## Setup Meta Developer juga saat implementasi.
gimana cara setup ini,  Verifikasi koneksi nyata — GAGAL. Token FB (EAA) expired 2026-08-30 00:00 PDT; instagram:test-connection menolak. Sistem bekerja tapi tak bisa dipakai sampai token segar.
2. OAuth "Connect with Facebook" belum diuji end-to-end. Modal: Meta app harus punya
   produk Facebook Login; redirect `localhost` diizinkan otomatis di dev mode
   (produksi: daftarkan URL di Valid OAuth Redirect URIs). Ini jalur pengganti token expired.
3. Polling + balasan publik riil belum pernah jalan lewat sistem: tabel comments kosong, last_polled_at NULL, bot_enabled=0. Kalau bot diaktifkan sekarang, siklus langsung gagal di langkah pertama (token expired → 190).
4. Runtime infra belum aktif: worker php artisan queue:work database dan cron schedule:run (scheduler sudah terdaftar — schedule:list menampilkan tiap 5 menit ✓, tinggal dijalankan).
buat script run.sh untuk menjalankan proyek ini
buat script production.sh untuk membuat .zip yang bersih, didalamnya hanya berisi kode yang hanya diperlukan dilingkungan production termasuk dump .sql, hanya perlu file teknis, file dokumentasi misalnya tidak diperlukan. minta review file dan folder apa saja yang akan dimasukkan untuk cross-check. masuk ke plan mode supaya enak
tambahkan metode login langsung dengan instagram agar tidak perlu lagi membuat page facebook terlebih dahulu
sinkronkan .env ke .env.example lalu optimalkan .env.example untuk lingkungan produksi