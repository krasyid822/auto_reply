@extends('layouts.app')

@section('title', 'Pengaturan')

@section('content')
    <h1 class="text-2xl font-bold">Pengaturan</h1>

    {{-- Koneksi Instagram --}}
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="font-bold">Akun Instagram</h2>

        <div class="mt-4">
            @if ($accounts->isEmpty())
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-neutral-600">
                            Belum terhubung. Klik tombol untuk login & mengizinkan lewat Facebook
                            (sekali, token disimpan terenkripsi).
                        </p>
                        <p class="mt-1 text-xs text-neutral-400">
                            Setelah login, Anda memilih Halaman Facebook mana yang terhubung ke akun IG
                            (IG Business/Creator wajib ter-link ke halaman tersebut).
                        </p>
                    </div>
                    <a href="{{ route('connect.start') }}"
                       class="inline-flex items-center gap-2 rounded-xl bg-[#1877F2] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#0f68d6]">
                        Hubungkan dengan Facebook
                    </a>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($accounts as $acct)
                        <div class="rounded-xl border border-neutral-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium">{{ $acct->username }}
                                        <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">terhubung</span>
                                    </p>
                                    <p class="mt-1 text-sm text-neutral-500">
                                        Halaman {{ $acct->page_name ?? '—' }} · IG user id {{ $acct->ig_user_id }} · Token {{ $acct->token_type }} ·
                                        @if ($acct->isExpired())
                                            <span class="font-semibold text-red-600">kedaluwarsa/dicabut — hubungkan ulang</span>
                                        @elseif ($acct->token_expires_at === null)
                                            tanpa jadwal expire
                                        @else
                                            sisa {{ $acct->daysUntilExpiry() }} hari
                                        @endif
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <form method="POST" action="{{ route('settings.test') }}">
                                        @csrf
                                        <input type="hidden" name="ig_user_id" value="{{ $acct->ig_user_id }}">
                                        <button class="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-medium hover:bg-neutral-100">Test koneksi</button>
                                    </form>
                                    <form method="POST" action="{{ route('connect.disconnect') }}"
                                          onsubmit="return confirm('Lepas akun @{{ $acct->username }} dari sistem?')">
                                        @csrf
                                        <input type="hidden" name="ig_user_id" value="{{ $acct->ig_user_id }}">
                                        <button class="rounded-xl border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50">Lepas</button>
                                    </form>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('settings.account-media', $acct) }}"
                              data-resolve-url="{{ route('settings.resolve-media', $acct) }}"
                              class="mt-3 js-account-media-form rounded-xl bg-neutral-50 p-3">
                                @csrf
                                <p class="text-xs font-semibold text-neutral-500">Pindai postingan</p>
                                <div class="mt-2 space-y-1 text-sm text-neutral-700">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="scan_recent" value="1"
                                               @checked($acct->scan_recent)
                                               class="size-3.5 accent-fuchsia-600">
                                        <span>Postingan terbaru (sesuai "Jumlah postingan yang dicek" di bawah)</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="scan_specific" value="1"
                                               @checked($acct->scan_specific)
                                               class="size-3.5 accent-fuchsia-600">
                                        <span>Postingan tertentu (hanya kunjungi postingan pilihan — lebih hemat kuota)</span>
                                    </label>
                                </div>
                                <textarea name="media_ids" rows="2" placeholder="ID postingan atau URL Instagram, dipisah koma atau baris baru"
                                          @readonly(! $acct->scan_specific)
                                          class="mt-2 w-full rounded-lg border border-neutral-300 px-3 py-2 text-xs focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-100 readonly:cursor-not-allowed readonly:bg-neutral-100">{{ is_array($acct->media_ids) ? implode(', ', $acct->media_ids) : '' }}</textarea>
                                <p class="mt-1 text-xs text-neutral-400">URL Instagram otomatis dikonversi ke media id. Kunci aktif bila "Postingan terbaru" dipilih.</p>
                                <p class="media-resolve-status mt-1 text-xs font-medium" role="status" aria-live="polite"></p>
                                <button class="mt-2 rounded-lg bg-neutral-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-neutral-700">Simpan pilihan postingan</button>
                            </form>
                        </div>
                    @endforeach

                    <a href="{{ route('connect.start') }}"
                       class="inline-flex items-center gap-2 rounded-xl bg-[#1877F2] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#0f68d6]">
                        + Hubungkan akun baru
                    </a>
                </div>
            @endif
        </div>
    </section>

    {{-- Setelan bot --}}
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="font-bold">Balasan otomatis</h2>
        <form method="POST" action="{{ route('settings.update') }}" class="mt-4 space-y-4">
            @csrf

            <div class="flex items-center justify-between rounded-xl border border-neutral-200 px-4 py-3">
                <div>
                    <p class="font-medium">Aktifkan balasan otomatis</p>
                    <p class="text-xs text-neutral-500">Izinkan pemrosesan komentar otomatis.</p>
                </div>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" name="bot_enabled" value="1" @checked($setting->bot_enabled) class="peer sr-only">
                    <span class="h-6 w-11 rounded-full bg-neutral-300 after:absolute after:left-[2px] after:top-[2px] after:size-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-emerald-500 peer-checked:after:translate-x-5"></span>
                </label>
            </div>

            <div class="flex items-center justify-between rounded-xl border border-neutral-200 px-4 py-3">
                <div>
                    <p class="font-medium">Balas komentar dari akun sendiri</p>
                    <p class="text-xs text-neutral-500">Biasanya komentar dari akun Instagram Anda dilewati. Aktifkan bila ingin bot ikut meresponsnya (misalnya untuk balasan pada utas publik).</p>
                </div>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" name="reply_to_own_comments" value="1" @checked($setting->reply_to_own_comments) class="peer sr-only">
                    <span class="h-6 w-11 rounded-full bg-neutral-300 after:absolute after:left-[2px] after:top-[2px] after:size-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-emerald-500 peer-checked:after:translate-x-5"></span>
                </label>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-500">Cek komentar setiap (menit)</label>
                    <input type="number" name="poll_interval_minutes" min="1" max="60"
                           value="{{ old('poll_interval_minutes', $setting->poll_interval_minutes) }}"
                           class="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-100">
                    <p class="mt-1 text-xs text-neutral-400">Seberapa sering komentar diperiksa. Jangan terlalu rapat agar kuota (±200 panggilan/jam) tidak cepat habis.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-500">Jumlah postingan yang dicek</label>
                    <input type="number" name="max_media_per_cycle" min="1" max="50"
                           value="{{ old('max_media_per_cycle', $setting->max_media_per_cycle) }}"
                           @readonly(! $accounts->contains(fn (App\Models\InstagramAccount $acct) => $acct->scan_recent))
                           class="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-100 readonly:cursor-not-allowed readonly:bg-neutral-100">
                    <p class="mt-1 text-xs text-neutral-400">Postingan/reels terbaru per akun yang diperiksa tiap siklus. Terkunci bila tidak ada akun yang memakai "Postingan terbaru".</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button class="rounded-xl bg-neutral-900 px-5 py-2 text-sm font-semibold text-white hover:bg-neutral-700">Simpan</button>
                <form method="POST" action="{{ route('settings.poll-now') }}" id="poll-now-form">
                    @csrf
                    <button class="rounded-xl border border-neutral-300 px-5 py-2 text-sm font-medium hover:bg-neutral-100">▶ Cek sekarang</button>
                </form>
                <span id="poll-status" class="text-sm"></span>
            </div>
        </form>
    </section>

    {{-- App Lock --}}
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="font-bold">App Lock — profil: {{ session('admin_name') }}</h2>
        <form method="POST" action="{{ route('app-lock.store') }}" class="mt-4 space-y-4">
            @csrf
            @php $lock = \App\Models\User::find(session('admin_id'))?->appLock; @endphp

            <div class="flex items-center justify-between rounded-xl border border-neutral-200 px-4 py-3">
                <div>
                    <p class="font-medium">Aktifkan App Lock untuk profil ini</p>
                    <p class="text-xs text-neutral-500">Kunci dashboard setelah tidak aktif beberapa menit.</p>
                </div>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" name="enabled" value="1" @checked($lock?->enabled) class="peer sr-only">
                    <span class="h-6 w-11 rounded-full bg-neutral-300 after:absolute after:left-[2px] after:top-[2px] after:size-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-amber-500 peer-checked:after:translate-x-5"></span>
                </label>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-500">PIN baru (4–6 digit)</label>
                    <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6"
                           autocomplete="new-password"
                           class="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100">
                    <p class="mt-1 text-xs text-neutral-400">Kosongkan jika hanya ingin mengubah pengaturan lain.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-500">Timeout idle (menit)</label>
                    <input type="number" name="timeout_minutes" min="1" max="60"
                           value="{{ old('timeout_minutes', $lock?->timeout_minutes ?? 5) }}"
                           class="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100">
                </div>
            </div>

            <button class="rounded-xl bg-neutral-900 px-5 py-2 text-sm font-semibold text-white hover:bg-neutral-700">Simpan App Lock</button>
        </form>
    </section>

    {{-- Langkah manual / dokumentasi --}}
    <section class="rounded-2xl border border-dashed border-neutral-300 p-6 text-sm text-neutral-500">
        <p>
            🔑 <strong>Token kedaluwarsa?</strong> Klik <em>Hubungkan dengan Facebook</em> lagi (login ulang wajib per kebijakan Meta).
            Ingin token "no-expiry"? Lihat <code>README.md</code> → "Link page Facebook ke akun Instagram untuk Token No-Expiry".
        </p>
        <p class="mt-2">
            ⚙️ Proses otomatis: cron <code>* * * * * php artisan schedule:run</code> + worker
            <code>php artisan queue:work database</code>. Manual: <code>php artisan instagram:process-comments</code>.
        </p>
    </section>
@endsection