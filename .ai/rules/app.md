---
paths:
  - 'app/**'
---

# App

## Instagram multi-akun: jangan hardcode identitas akun
Sistem mendukung banyak akun IG. Jangan pernah menyimpan/menebak IG id, username, atau page name untuk akun nyata di kode, config, atau opencode settings. Identitas hanya datang dari hasil OAuth (create/update per ig_user_id). InstagramClient harus dikonstruksi dengan akun terikat; resolveToken()/resolveIgUserId() mengalir ke InstagramApiException saat kosong (tidak ada fallback first()/config). Dedup komentar per (ig_user_id, comment_id), bukan per comment_id global. Konfigurasi instagram.php hanya menyimpan app settings.
