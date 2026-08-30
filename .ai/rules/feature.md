---
paths:
  - 'tests/Feature/*.php'
---

# Feature

## Test Connect: selalu sertakan catch-all Http::fake
Mesin dev punya akses internet nyata: di test, URL yang TIDAK ter-match oleh Http::fake akan benar-benar dipanggil ke graph.facebook.com (balas error Graph sungguhan, menyentuh rate limit/kuota). Setiap test yang memicu listPages()/flow Connect WAJIB menyertakan catch-all `'graph.facebook.com/*' => Http::response([])` supaya hermetis.
