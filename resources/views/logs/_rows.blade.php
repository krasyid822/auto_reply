{{-- Fragmen Riwayat Komentar: satu kartu per postingan (di-render ulang via fetch dari #logs-list). --}}
<div id="logs-list"
     data-count="{{ $comments->total() }}"
     data-base-url="{{ route('logs.index') }}"
     class="space-y-5">
    @forelse ($groups as $group)
        <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
            <button type="button"
                    class="flex w-full items-center justify-between gap-3 border-b border-neutral-100 bg-fuchsia-50/60 px-4 py-3 text-left transition hover:bg-fuchsia-50">
                <span>
                    <span class="js-media-preview inline-flex cursor-pointer items-center gap-2 rounded text-xs font-semibold uppercase tracking-wide text-fuchsia-700 underline decoration-dotted underline-offset-4 decoration-fuchsia-300 hover:text-fuchsia-900"
                          data-media-id="{{ $group['media_id'] }}"
                          data-media-url="{{ $group['items']->first()?->media_url }}">
                        Postingan {{ $group['media_id'] }}
                        <svg class="h-3.5 w-3.5 shrink-0 text-fuchsia-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </span>
                    @if ($group['items']->first()?->media_type)
                        <span class="text-xs text-neutral-400">{{ $group['items']->first()->media_type }}</span>
                    @endif
                </span>
                <span class="shrink-0">
                    <span class="rounded-full bg-fuchsia-100 px-2 py-0.5 text-xs font-medium text-fuchsia-600">
                        {{ $group['items']->count() }} komentar
                    </span>
                </span>
            </button>

            <div class="divide-y divide-neutral-100">
                @foreach ($group['items'] as $comment)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-neutral-800">{{ $comment->username }}</p>
                                <p class="mt-0.5 truncate text-sm text-neutral-600">{{ $comment->text }}</p>
                                @if ($comment->reply_text)
                                    <p class="mt-1 truncate text-xs text-neutral-400">→ {{ $comment->reply_text }}</p>
                                @endif
                                @if ($comment->error)
                                    <p class="mt-1 truncate text-xs text-red-500">{{ $comment->error }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                @php $statusLabels = ['pending' => 'Menunggu', 'replied' => 'Dibalas', 'skipped' => 'Dilewati', 'failed' => 'Gagal']; @endphp
                                @php $colors = ['pending' => 'bg-sky-100 text-sky-800', 'replied' => 'bg-emerald-100 text-emerald-800', 'skipped' => 'bg-neutral-200 text-neutral-600', 'failed' => 'bg-red-100 text-red-800']; @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $colors[$comment->status] ?? 'bg-neutral-200' }}">
                                    {{ $statusLabels[$comment->status] ?? $comment->status }}
                                </span>
                                <span class="text-xs text-neutral-400">{{ $comment->created_at->diffForHumans() }}</span>
                            </div>
                        </div>
                        @if ($comment->status === 'failed' && $comment->reply_text)
                            <form method="POST" action="{{ route('logs.retry', $comment) }}" class="mt-2">
                                @csrf
                                <button class="rounded-lg border border-amber-200 px-3 py-1 text-xs font-medium text-amber-800 hover:bg-amber-50">Coba lagi</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-2xl border border-dashed border-neutral-200 bg-white p-10 text-center text-sm text-neutral-500">
            Tidak ada komentar.
        </div>
    @endforelse
</div>