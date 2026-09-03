#!/usr/bin/env bash
set -euo pipefail

# === Auto-Reply Komentar IG — production packager ===
# Membuat .zip bersih berisi hanya kode yang dibutuhkan di lingkungan produksi,
# plus dump .sql (opsional) dan panduan setup cPanel (DEPLOY-cPanel.md).
#
# Jalankan dari root proyek:  ./production.sh
#
# Interaktif, keputusan ditanyakan saat berjalan:
#   1) Vendor  -> "Ya, cPanel mendukung composer"  = zip TANPA vendor
#                 (install vendor di server lewat composer install --no-dev)
#                 "Tidak"                          = vendor no-dev fresh di-staging
#   2) Dump DB -> akun IG dev tetap dipakai        = schema + data (opsi 1)
#                 akun produksi nanti beda         = schema kosong (opsi 2)

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# --- Prasyarat ---
for bin in zip mysqldump rsync; do
    command -v "$bin" >/dev/null || { echo "ERROR: $bin tidak ditemukan." >&2; exit 1; }
done
command -v composer >/dev/null || { echo "ERROR: composer tidak ditemukan." >&2; exit 1; }

# --- Baca kredensial DB dari .env ---
[[ -f .env ]] || { echo "ERROR: .env tidak ada. Salin .env.example dan isi dulu." >&2; exit 1; }
Cfg() { grep -E "^${1}=" .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d ' '; }
DB_HOST="$(Cfg DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(Cfg DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(Cfg DB_DATABASE)"; DB_NAME="${DB_NAME:-auto_reply}"
DB_USER="$(Cfg DB_USERNAME)"
DB_PASS="$(Cfg DB_PASSWORD)"

# --- Prompt 1: vendor ---
echo
echo "==> Vendor PHP di dalam zip?"
echo "    [1] Tidak sertakan vendor (recommended bila cPanel Anda mendukung composer:"
echo "        upload zip kecil, lalu jalankan 'composer install --no-dev' via Terminal cPanel)"
echo "    [2] Sertakan vendor no-dev fresh (dibuild di staging; client tidak perlu composer)"
while true; do
    read -r -p "Pilih [1/2]: " VENDOR_CHOICE
    case "$VENDOR_CHOICE" in
        1) WITH_VENDOR=0; break;;
        2) WITH_VENDOR=1; break;;
        *) echo "   Masukkan 1 atau 2.";;
    esac
done

# --- Prompt 2: dump DB ---
echo
echo "==> Dump database?"
echo "    [1] Schema + data runtime sekarang (bila akun IG mode dev ini tetap dipakai produksi)"
echo "    [2] Schema kosong saja         (bila nanti pakai akun IG lain di produksi)"
while true; do
    read -r -p "Pilih [1/2]: " DUMP_CHOICE
    case "$DUMP_CHOICE" in
        1) DUMP_DATA=1; break;;
        2) DUMP_DATA=0; break;;
        *) echo "   Masukkan 1 atau 2.";;
    esac
done

STAMP="$(date +%Y%m%d.%H%M)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo
echo "==> Menyusun staging di $STAGE"

# --- Salin folder inti (semua, lalu bersihkan yang tak perlu) ---
EXCLUDE_FILE="$STAGE/.pack_exclude"
cat > "$EXCLUDE_FILE" <<'EOF'
.env
.env.backup
/.git
/.gitignore
.agents
.ai
.claude
.zed
.mcp.json
opencode.json
boost.json
AGENTS.md
CLAUDE.md
CURRENT.md
README.md
README_old.md
SYSTEM.md
UX.md
run.sh
production.sh
auto-reply-*.zip
node_modules
vendor
tests
phpunit.xml
.phpunit.cache
.phpunit.result.cache
.editorconfig
.gitattributes
.npmrc
package.json
package-lock.json
vite.config.js
resources/css
resources/js
storage/logs/*.log
EOF
rsync -a --exclude-from "$EXCLUDE_FILE" ./ "$STAGE/"
rm -f "$EXCLUDE_FILE"

# --- Bersihkan artefak runtime dev ---
rm -rf "$STAGE"/storage/framework/{cache,sessions,testing,views}/* 2>/dev/null || true
rm -rf "$STAGE"/storage/logs/laravel.log "$STAGE"/storage/logs/instagram-poll.log "$STAGE"/storage/logs/queue-worker.log 2>/dev/null || true
rm -rf "$STAGE"/bootstrap/cache/{packages.php,services.php,config.php,routes.php,events.scanned.php} 2>/dev/null || true
rm -f "$STAGE"/database/database.sqlite "$STAGE"/database/.gitignore
rm -f "$STAGE"/.phpunit.result.cache

# --- Opsional: vendor no-dev ---
if [[ "$WITH_VENDOR" -eq 1 ]]; then
    echo "==> Membuild vendor (composer install --no-dev --optimize-autoloader)..."
    ( cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
fi

# --- Dump DB ---
MYSQL_ARGS=(-h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER")
[[ -n "$DB_PASS" ]] && MYSQL_ARGS+=("-p$DB_PASS")
if [[ "$DUMP_DATA" -eq 1 ]]; then
    echo "==> Dump schema + data $DB_NAME -> database/auto_reply.sql"
    mysqldump "${MYSQL_ARGS[@]}" --single-transaction --routines --triggers "$DB_NAME" > "$STAGE/database/auto_reply.sql"
else
    echo "==> Dump schema kosong $DB_NAME -> database/auto_reply.sql"
    mysqldump "${MYSQL_ARGS[@]}" --no-data "$DB_NAME" > "$STAGE/database/auto_reply.sql"
fi
[[ -s "$STAGE/database/auto_reply.sql" ]] || { echo "ERROR: dump gagal / kosong." >&2; exit 1; }

# --- URL repo (dinamis, versi HTTPS) ---
REPO_URL="$(git config --get remote.origin.url 2>/dev/null || true)"
if [[ -n "$REPO_URL" ]]; then
    [[ "$REPO_URL" == git@* ]] && REPO_URL="https://github.com/${REPO_URL#*:}"
    REPO_URL="${REPO_URL%.git}"
fi

# --- Template panduan setup cPanel (dibuat di staging, masuk zip) ---
cat > "$STAGE/DEPLOY-cPanel.md" <<'DOC'
# Deploy Auto-Reply Komentar IG ke cPanel

Berlaku untuk PHP 8.3+ dan MySQL/MariaDB (extension `mysqli`/`pdo_mysql` aktif).

## Sumber kode & versi

- Repo Git (buka/unduh kode sumber): $(REPO_URL)
- Backup/update: tarik kode terbaru dari repo ini, lalu ulangi langkah 2-5.

## 1. Upload & extract

1. Buka **File Manager** cPanel → masuk folder dokumen (mis. `public_html/auto-reply`).
2. Upload `auto-reply-*.zip`, lalu **Extract**.
   Hasilnya kira-kira begini:
   ```
   public_html/auto-reply/
   ├── app/
   ├── config/
   ├── public/          ← folder ini yang "terlihat" oleh pengunjung
   ├── routes/
   ├── .env               ← rahasia: isi kredensial DB & Meta
   ├── database/
   └── ...
   ```

### Kenapa harus arahkan halaman ke folder `public`?

**Analogi sederhana:** bayangkan folder proyek adalah sebuah rumah. Folder `public/`
adalah pintu depan / ruang tamu — satu-satunya bagian yang boleh dilihat tamu (pengunjung
website). Ruangan lain (dapur, kamar, gudang) menyimpan hal-hal penting atau privat.

- `.env` berisi **password DB + App Secret Meta** → seperti lemari berisi kunci rumah.
- `database/auto_reply.sql` dan `config/`, `routes/`, `storage/logs` → berkas teknis
  yang tidak boleh ada di ruang tamu.

Kalau pengunjung bisa membuka **seluruh folder proyek** (bukan cuma `public/`), mereka bisa
mengetik `https://domainkamu/.env` di browser dan **membaca password Anda**. Kode PHP
sebenarnya tidak bahaya (ditahan server), tapi berkas non-PHP seperti `.env`, `.sql`, dan
log bisa dibaca raw.

### Cara di cPanel

Ada dua pilihan — pilih sesuai cara Anda membuat domain/subdomain:

**A. Domain/subdomain yang SUDAH ada**
1. **cPanel → Domains** → klik **Manage** pada domain/subdomain.
2. Cari bagian **Document Root** → ubah dari `public_html/auto-reply`
   menjadi `public_html/auto-reply/public`.
3. **Save**.

**B. Subdomain BARU (yang paling mudah dipahami pemula)**
1. **cPanel → Subdomains**.
2. Field **Subdomain**: ketik mis. `app`.
3. **Document Root**: langkah penting — hapus nilai otomatis, lalu ketik
   `public_html/auto-reply/public` (bukan `public_html/auto-reply`).
4. **Create**. Hasilnya: `https://app.domainkamu` langsung menyajikan folder `public/`.

> **Tanda berhasil:** ketika Anda membuka URL di browser, seharusnya muncul dashboard.
> Coba juga buka `URL/.env` — kalau muncul *Not Found / 404*, berarti sudah benar dan aman.

## 2. Setup dependensi

**Jika zip TIDAK menyertakan folder `vendor`** (`production.sh` pilihan 1):

- Buka **Terminal** cPanel di folder proyek, jalankan:

  ```sh
  composer install --no-dev --optimize-autoloader --no-interaction
  ```

**Jika zip SUDAH menyertakan `vendor`** (pilihan 2): cukup pastikan izin file tidak berubah
(`rwxr-xr-x` untuk folder, `rw-r--r--` untuk file).

## 3. Konfigurasi `.env`

1. Copy `.env.example` → `.env` (di File Manager: rename/duplicate).
2. Isi:
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `APP_URL` = URL publik persis (https)
   - `APP_KEY` = jalankan di Terminal: `php artisan key:generate`
   - `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` dari **MySQL Databases**
     (jangan pakai root; buat user DB khusus + grant ALL pada DB)
   - Kredensial Meta (ambil dari **developers.facebook.com** → App → **App settings → Basic**):
     - `FACEBOOK_CLIENT_ID` = **App ID**
     - `FACEBOOK_CLIENT_SECRET` = **App Secret** (klik *Show* untuk menampilkan)
     - `FACEBOOK_REDIRECT_URI` = **harus persis** URL callback milik install ini,
       yaitu `APP_URL` Anda + `/auth/facebook/callback`.
       Contoh: `APP_URL=https://domainkamu` → `FACEBOOK_REDIRECT_URI=https://domainkamu/auth/facebook/callback`
     - `FACEBOOK_API_VERSION` = versi API sama dengan yang dipakai di dev (mis. `v26.0`)
   - Dari Meta app juga pastikan produk **Facebook Login** sudah ditambahkan.
3. **Daftarkan URL callback di Meta** (wajib agar OAuth "Hubungkan dengan Facebook" jalan):
   1. Buka **developers.facebook.com** → App Anda → **Facebook Login → Settings**.
   2. Kolom **Valid OAuth Redirect URIs** → tambahkan persis `FACEBOOK_REDIRECT_URI` tadi
      (mis. `https://domainkamu/auth/facebook/callback`).
   3. **Save changes**. ("Use Strict Mode" boleh tetap aktif.)
   > Bila tidak didaftarkan, tombol *Hubungkan dengan Facebook* akan gagal dengan
   > error seperti *"URL blocked / redirect_uri is not authorized"*.

## 4. Database

**Jika zip berisi `database/auto_reply.sql` schema + data (pilihan 1):**

- Buat DB kosong di cPanel → **phpMyAdmin** → pilih DB → **Import** → `auto_reply.sql`.

**Jika hanya schema (pilihan 2):**

- Pastikan DB kosong, lalu di Terminal:

  ```sh
  php artisan migrate --seed
  ```

## 5. Jalankan & cron (produksi cPanel)

Shared hosting cPanel tidak mengizinkan proses jalan terus (tidak ada systemd), jadi
**satu baris cron per menit** cukup untuk menjalankan polling komentar + worker queue:

1. Cek path PHP di cPanel: buka **Terminal**, jalankan `which php` (biasanya
   `/usr/local/bin/php` atau `/usr/bin/php`; simpan hasilnya).
2. Buka **Cron Jobs** → **Standard**:
   - Menit: `*` · Jam: `*` · Hari: `*` · Bulan: `*` · Hari kerja: `*`
   - Command:
     ```sh
     /usr/local/bin/php /home/USERNAME/path/artisan schedule:run
     ```
     (ganti `/usr/local/bin/php` dengan hasil `which php`, dan sesuaikan path `artisan`.)
3. **Uji**: tanpa menunggu menit cron, buka dashboard → **Cek sekarang**, atau
   jalankan manual di Terminal: `php artisan instagram:process-comments`.
   Pastikan `storage/logs/instagram-poll.log` dan `queue-worker.log` terisi.

> Format `* * * * * /usr/bin/php ...` di atas hanyalah padanan baris crontab
> klasik; di cPanel isi kolomnya lewat UI (setiap menit = semua kolom `*`).

## Keamanan singkat

- Dokumen root harus diarahkan ke `/public` — jangan sampai `.env` atau `auto_reply.sql`
  terbaca dari browser.
- Jangan commit `.env` / token. Dump `auto_reply.sql` berisi token terenkripsi
  (perlu `APP_KEY` yang sama); hapus dari server bila tidak lagi dibutuhkan.
DOC

# --- Substitusi URL repo (heredoc quoted, jadi ganti placeholder) ---
if [[ -n "$REPO_URL" ]]; then
    sed -i "s|\$(REPO_URL)|$REPO_URL|" "$STAGE/DEPLOY-cPanel.md"
else
    sed -i 's|Repo Git (buka/unduh kode sumber): $(REPO_URL)|Repo Git: (repo tidak tersedia — folder ini bukan clone dari git)|' "$STAGE/DEPLOY-cPanel.md"
fi

# --- Zip ---
ZIP="$ROOT/auto-reply-$STAMP.zip"
ZIP_TMP_DIR="$(mktemp -d)"
ZIP_TMP="$ZIP_TMP_DIR/auto-reply-$STAMP.zip"
echo
echo "==> Membuat $ZIP ..."
( cd "$STAGE" && zip -r -q "$ZIP_TMP" . )
mv -f "$ZIP_TMP" "$ZIP"
rm -rf "$ZIP_TMP_DIR"
rm -rf "$STAGE"

echo
echo "Selesai:"; echo "  $ZIP"
echo
echo "Panduan setup: $ZIP -> DEPLOY-cPanel.md"
if [[ "$WITH_VENDOR" -eq 0 ]]; then
    echo "Catatan: zip ini tanpa vendor — jalankan composer install --no-dev setelah upload."
else
    echo "Catatan: zip ini menyertakan vendor no-dev."
fi
if [[ "$DUMP_DATA" -eq 1 ]]; then
    echo "Dump DB: schema + data runtime (cookie akun dev tetap terpakai)."
else
    echo "Dump DB: schema kosong (akun IG harus connect ulang di produksi)."
fi