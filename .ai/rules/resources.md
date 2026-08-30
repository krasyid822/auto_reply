---
paths:
  - 'resources/**'
---

# Resources

## UI realtime: fetch() periodik + fragmen Blade
UI realtime memakai fetch() periodik (15 dtk) terhadap endpoint yang mengembalikan fragmen Blade (dashboard.live, logs?partial=1) dan JSON untuk aksi (pollNow wantsJson). Jangan tambah framework JS (Livewire/Inertia/Reverb/websocket) tanpa persetujuan. Blokir request ganda (busy flag) dan berhenti saat document.hidden; tangani res.redirected (lock middleware) agar fragmen tak menggantikan halaman.
