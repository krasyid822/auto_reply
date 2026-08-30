@extends('layouts.app')

@section('title', 'Pilih Profil')

@section('content')
    <div class="mx-auto max-w-lg space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-sm">
            <h1 class="text-xl font-bold">Pilih atau buat profil</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Aplikasi ini tanpa login. Profil hanya berisi nama, pengamanan memakai
                <strong>App Lock</strong> (PIN) yang diatur per profil.
            </p>

            <form method="POST" action="{{ route('profiles.store') }}" class="mt-5 flex gap-2">
                @csrf
                <input name="name" required maxlength="255" placeholder="Nama profil admin"
                       class="w-full rounded-xl border border-neutral-300 px-4 py-2 text-sm focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-200">
                <button class="rounded-xl bg-neutral-900 px-4 py-2 text-sm font-semibold text-white hover:bg-neutral-700">Buat</button>
            </form>
        </div>

        @if ($admins->isNotEmpty())
            <div class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">Profil yang tersedia</h2>
                <div class="mt-3 divide-y divide-neutral-100">
                    @foreach ($admins as $admin)
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="font-medium">{{ $admin->name }}</p>
                                <p class="text-xs text-neutral-500">
                                    {{ $admin->appLock?->enabled ? '🔒 App Lock aktif ('.$admin->appLock->timeout_minutes.' mnt)' : 'Tanpa App Lock' }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('profiles.switch') }}">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $admin->id }}">
                                <button class="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-medium hover:bg-neutral-100">Pilih</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection