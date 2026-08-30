# UX.md — Cara Pengguna Mengoperasikan Aplikasi

Kesimpulan planning **alur pemakaian** Auto-Reply Komentar Instagram SAE oleh admin.

## Konsep Akses (Tanpa Login)

- Aplikasi **tidak memakai email/password**. Pengguna memilih/membuat **profil** cukup
  dengan nama; tersimpan di sesi. Ini sesuai kebutuhan "buka langsung, cepat".
- Karena tanpa login, keamanan dijaga dengan **App Lock per profil** (PIN 4–6 digit +
  timeout idle). Tiap admin punya konfigurasi App Lock sendiri (fitur multi-admin,
  1 akun Instagram bersama).

## Alur Pertama Kali

1. Buka `http://localhost:8000` (atau `http://localhost:8000/profil`).
2. Tulis **nama profil** → *Buat Profil*.
3. Arahkan ke **Settings**:
   - klik **Connect with Facebook** → login FB → setujui izin Instagram
     (syarat: akun IG Business/Creator ter-link ke Page, lihat README);
   - setelah kembali, muncul `@username` **terhubung**;
   - atur **interval polling** (default 5 menit) dan **max media/siklus**;
   - aktifkan toggle **Bot** → **Simpan**.
4. Buat/cek **Rules** (keyword + template balasan), misal:
   `harga` → "Untuk info harga silakan DM ya!". Rule bisa diaktifkan/nonaktif per-item.
5. (Opsional) set **App Lock**: aktifkan + PIN + timeout → hasilnya dashboard akan
   mengunci setelah tidak aktif beberapa menit dan minta PIN lagi.
6. Pastikan **worker berjalan** (`php artisan queue:work database`) dan **cron**
   scheduler aktif (lihat README Quick start). Biasakan: Dashboard → **Test koneksi**.

## Halaman & Aksi

| Halaman | Yang bisa dilakukan |
|---|---|
| **Dashboard** (`/`) | Status koneksi, persen bot aktif, interval poling, statistik balasan/gagal/pending, aktivitas komentar terakhir. |
| **Rules** (`/rules`) | Tambah/edit/urutkan keyword + template; toggle aktif/nonaktif; hapus. Pencocokan: mengandung keyword (tak peduli huruf besar/kecil). |
| **Log** (`/logs`) | Riwayat komentar: filter by status, cari teks/username; **Retry** untuk komentar `failed` (yang punya balasan). |
| **Settings** (`/settings`) | Connect/disconnect akun IG, Test koneksi, toggle bot, interval polling, max media, **Poll now** (siklus manual), konfigurasi **App Lock**. |

## Alur Ganti / Sambung Ulang Akun

- **Ganti akun**: Settings → *Lepas* → *Connect with Facebook* → pilih akun lain.
  Log komentar lama tetap utuh; dedup mulai dari nol.
- **Token kedaluwarsa (±60 hari)**: balasan berhenti + komentar baru berstatus `failed`
  ("Access token kedaluwarsa"). Perbaiki: Settings → *Connect with Facebook* lagi.
- **Akun di-Lepas**: fitur aman, tidak ada token tersisa di DB (semua dihapus).

## Push-Back: yang TIDAK dilakukan

- Tidak ada email/password & halaman login di luar profil.
- Tidak ada pengaturan balasan berbasis AI/GPT — hanya template statis keyword.
- Tidak ada balasan DM private (aturan Meta melarang bot DM untuk IG).
- Tidak ada webhook (butuh HTTPS publik + App Review); polling cukup.
- Dashboard tidak punya role/permission multi-level — semua profil = admin penuh atas
  satu akun IG yang sama.

## Catatan Penanganan Status Komentar

- `pending` → sedang menunggu worker.
- `replied` → sudah dibalas + `replied_at`.
- `skipped` → bukan komentar kita, tapi tidak cocok rule (ada di log, tidak di reply).
- `failed` → gagal dibalas (error/rate-limit/token); bisa **Retry** dari Logs bila ada `reply_text`.

## Tanya di Sesi Berikutnya

- Apakah perlu dashboard ringkasan mingguan / ekspor log CSV?
- Perlu multi-akun (beberapa `instagram_accounts` + pilih aktif) untuk ditangani?