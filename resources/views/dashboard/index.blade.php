@extends('layouts.app')

@section('title', 'Beranda')

@section('content')
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Beranda</h1>
        <div class="flex items-center gap-3">
            <span id="live-updated" class="text-xs text-neutral-400"></span>
            <span class="rounded-full bg-neutral-200 px-3 py-1 text-xs font-medium">
                {{ $setting->bot_enabled ? '🟢 Balasan otomatis AKTIF' : '⚪ Balasan otomatis nonaktif' }}
            </span>
        </div>
    </div>

    <div id="live-dashboard" data-url="{{ route('dashboard.live') }}" class="space-y-6">
        @include('dashboard._live', [
            'accounts' => $accounts,
            'setting' => $setting,
            'stats' => $stats,
            'recent' => $recent,
        ])
    </div>
@endsection