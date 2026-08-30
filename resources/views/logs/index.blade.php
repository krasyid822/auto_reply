@extends('layouts.app')

@section('title', 'Riwayat Komentar')

@section('content')
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Riwayat Komentar</h1>
        <span id="logs-count" class="text-sm text-neutral-500">{{ $comments->total() }} komentar</span>
    </div>

    <form method="GET" action="{{ route('logs.index') }}" class="flex flex-wrap items-center gap-2 rounded-2xl bg-white p-4 shadow-sm">
        <input name="q" value="{{ $search }}" placeholder="Cari teks / username…"
               class="w-full max-w-xs rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-100">

        @php $statusLabels = ['pending' => 'Menunggu', 'replied' => 'Dibalas', 'skipped' => 'Dilewati', 'failed' => 'Gagal']; @endphp
        <select name="status" class="rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:outline-none">
            <option value="">Semua status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($currentStatus === $status)>{{ $statusLabels[$status] ?? $status }}</option>
            @endforeach
        </select>

        <button class="rounded-xl bg-neutral-900 px-4 py-2 text-sm font-semibold text-white hover:bg-neutral-700">Filter</button>

        @if ($currentStatus || $search)
            <a href="{{ route('logs.index') }}" class="px-2 py-2 text-sm text-neutral-500 hover:underline">Reset</a>
        @endif
    </form>

    @include('logs._rows', ['comments' => $comments, 'groups' => $groups])

    <div class="mt-5">{{ $comments->links() }}</div>

    {{-- Balloon preview postingan (fetch oEmbed via /logs/media-preview). --}}
    <div id="media-popover"
         data-preview-base="{{ route('logs.media-preview') }}"
         class="fixed z-50 hidden w-96 max-w-[calc(100vw-2rem)]">
        <div class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-neutral-100 px-3 py-2">
                <p class="text-xs font-semibold text-neutral-800">Preview postingan</p>
                <a id="media-preview-open" href="#" target="_blank" rel="noopener"
                   class="text-xs font-medium text-fuchsia-600 hover:underline">Buka di Instagram</a>
            </div>
            <div id="media-popover-body" class="max-h-80 overflow-y-auto p-3 text-sm text-neutral-500">
                Memuat preview…
            </div>
        </div>
    </div>
@endsection