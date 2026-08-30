@extends('layouts.app')

@section('title', 'App Lock')

@section('content')
    <div class="mx-auto max-w-md rounded-2xl bg-white p-8 text-center shadow-sm">
        <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-100 text-3xl">🔒</div>
        <h1 class="mt-4 text-lg font-bold">Aplikasi terkunci</h1>
        <p class="mt-1 text-sm text-neutral-500">Masukkan PIN <strong>{{ $admin?->name }}</strong> untuk melanjutkan.</p>

        <form method="POST" action="{{ route('lock.unlock') }}" class="mt-6 space-y-3">
            @csrf
            <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off"
                   autofocus required
                   class="w-full rounded-xl border border-neutral-300 px-4 py-3 text-center text-2xl tracking-[0.5em] focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200">
            @error('pin')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <button class="w-full rounded-xl bg-neutral-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-neutral-700">Buka</button>
        </form>

        <p class="mt-4 text-xs text-neutral-400">
            Tidak ingat PIN? Atur ulang di Pengaturan → App Lock oleh admin lain, atau lewat
            pengelolaan database.
        </p>
    </div>
@endsection