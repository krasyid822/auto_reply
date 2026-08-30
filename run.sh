#!/usr/bin/env bash
set -euo pipefail

# === Auto-Reply Komentar IG — dev runner ===
# Menjalankan: web server + queue worker + vite + logs (php artisan dev)
# sekaligus scheduler (schedule:work) untuk polling otomatis.
# Salin & isi .env (bila belum ada), install dependensi, migrate, lalu start.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

command -v php >/dev/null || { echo "ERROR: php tidak ditemukan." >&2; exit 1; }
command -v composer >/dev/null || { echo "ERROR: composer tidak ditemukan." >&2; exit 1; }
command -v npm >/dev/null || { echo "ERROR: npm tidak ditemukan." >&2; exit 1; }

# --- 1. .env ---
if [[ ! -f .env ]]; then
    echo "==> .env belum ada, dibuat dari .env.example"
    cp .env.example .env
    php artisan key:generate
    echo "   PERHATIAN: isi kredensial DB & Meta di .env, lalu jalankan ulang script."
    exit 1
fi

# --- 2. Dependensi ---
if [[ ! -d vendor ]]; then
    echo "==> vendor belum ada, composer install..."
    composer install --no-interaction
fi
if [[ ! -d node_modules ]]; then
    echo "==> node_modules belum ada, npm install..."
    npm install --ignore-scripts
fi
if [[ ! -d public/build ]]; then
    echo "==> aset belum di-build, npm run build..."
    npm run build
fi

# --- 3. Migrasi ---
php artisan migrate --force

# --- 4. Jalankan stack ---
cleanup() {
    echo
    echo "==> Menghentikan worker (PID $SCHED_PID)..."
    kill "$SCHED_PID" 2>/dev/null || true
    wait "$SCHED_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "==> Memulai scheduler (schedule:work) di background..."
php artisan schedule:work >/dev/null 2>&1 &
SCHED_PID=$!

echo "==> Menjalankan dev server (server + queue + vite + logs)."
echo "    Dashboard: http://localhost:8000/profil   (sesuaikan dengan APP_URL)"
echo "    Tekan Ctrl+C untuk berhenti."
php artisan dev