{{-- Fragmen live Beranda (di-render ulang via fetch dari #live-dashboard). --}}
<section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Status koneksi</p>
        @if ($accounts->isEmpty())
            <p class="mt-2 text-lg font-bold text-neutral-400">Belum terhubung</p>
            <p class="mt-1 text-xs text-neutral-500">Hubungkan akun Instagram di Pengaturan.</p>
        @else
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($accounts as $acct)
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="font-bold">{{ $acct->username }}</span>
                        @if ($acct->isExpired())
                            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">token habis</span>
                        @elseif ($acct->token_expires_at === null)
                            <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-xs font-medium text-neutral-600">tanpa kedaluwarsa</span>
                        @else
                            <span class="text-xs text-neutral-500">{{ $acct->daysUntilExpiry() }} hari lagi</span>
                        @endif
                    </li>
                    <li class="text-xs text-neutral-400">
                        Sisa kuota: ±{{ $acct->remainingCalls() }}/{{ $acct->rateLimitPerHour() }} panggilan per jam
                        @if ($acct->scan_specific)
                            · memindai {{ count($acct->media_ids ?? []) }} postingan pilihan
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Pengecekan</p>
        <p class="mt-2 text-lg font-bold">Tiap {{ $setting->poll_interval_minutes }} menit</p>
        <p class="mt-1 text-xs text-neutral-500">
            @if ($setting->last_polled_at)
                Terakhir: {{ $setting->last_polled_at->diffForHumans() }}
            @else
                Belum pernah dicek
            @endif
        </p>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Balasan</p>
        <p class="mt-2 text-lg font-bold text-emerald-600">{{ $stats['replied'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-neutral-500">Komentar sudah dibalas</p>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Gagal / menunggu</p>
        <p class="mt-2 text-lg font-bold {{ ($stats['failed'] ?? 0) > 0 ? 'text-red-600' : 'text-neutral-900' }}">
            {{ $stats['failed'] ?? 0 }} / {{ $stats['pending'] ?? 0 }}
        </p>
        <p class="mt-1 text-xs text-neutral-500">Perlu perhatian</p>
    </div>
</section>

<section class="rounded-2xl bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between">
        <h2 class="font-bold">Aktivitas terakhir</h2>
        <a href="{{ route('logs.index') }}" class="text-sm font-medium text-fuchsia-600 hover:underline">Lihat semua →</a>
    </div>

    @if ($recent->isEmpty())
        <p class="mt-4 text-sm text-neutral-500">Belum ada komentar terpindai. Aktifkan balasan otomatis atau jalankan "Cek sekarang" di Pengaturan.</p>
    @else
        <div class="mt-4 overflow-x-auto">
            @php $statusLabels = ['pending' => 'Menunggu', 'replied' => 'Dibalas', 'skipped' => 'Dilewati', 'failed' => 'Gagal']; @endphp
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                        <th class="py-2 pr-4">Komentar</th>
                        <th class="py-2 pr-4">Dari</th>
                        <th class="py-2 pr-4">Akun</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Waktu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @foreach ($recent as $comment)
                        <tr>
                            <td class="max-w-xs truncate py-3 pr-4">{{ $comment->text }}</td>
                            <td class="py-3 pr-4">{{ $comment->username }}</td>
                            <td class="py-3 pr-4 text-neutral-500">{{ $comment->account?->username ?? '—' }}</td>
                            <td class="py-3 pr-4">
                                @php $colors = ['pending' => 'bg-sky-100 text-sky-800', 'replied' => 'bg-emerald-100 text-emerald-800', 'skipped' => 'bg-neutral-200 text-neutral-600', 'failed' => 'bg-red-100 text-red-800']; @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $colors[$comment->status] ?? 'bg-neutral-200' }}">
                                    {{ $statusLabels[$comment->status] ?? $comment->status }}
                                </span>
                            </td>
                            <td class="py-3">{{ $comment->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>