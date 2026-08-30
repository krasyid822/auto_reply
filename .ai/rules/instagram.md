---
paths:
  - 'app/Services/Instagram/**'
---

# Instagram

## Discover Page via Business Portfolio fallback
listPages() menggabungkan /me/accounts + fallback Business Portfolio (/me/businesses → owned_pages + client_pages, perlu scope business_management). Kegagalan satu sumber tidak fatal; listPagesError() hanya disorotkan ke user bila SEMUA sumber gagal memproduksi page. Di select(), verifikasi ulang per-objek yang ditolak untuk page bisnis di-fallback ke data sesi (resolvedFromPending).

## Sisa kuota Graph dihitung lokal, bukan dari Meta
Meta tidak punya endpoint untuk membaca sisa rate limit (±200 panggilan/jam per user token). Penghitung ada di instagram_accounts (api_calls_window_start + api_calls_count, jendela 1 jam bergulir); InstagramClient memanggil markApiCall() pada tiap request. Jangan pernah meminta "kuota sisa" ke API, dan jangan menimpa penghitung (kecuali window lewat). Batas bisa disesuaikan via INSTAGRAM_CALLS_PER_HOUR.
