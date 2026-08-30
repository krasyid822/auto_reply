<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Auto-Reply IG') — Auto-Reply Komentar Instagram</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-neutral-100 text-neutral-900 antialiased">

<header class="border-b border-neutral-200 bg-white">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold">
            <span class="inline-flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-fuchsia-500 to-amber-500 text-sm font-bold text-white">IG</span>
            <span>Auto-Reply Komentar IG</span>
        </a>

        <nav class="flex items-center gap-1 text-sm">
            @php $current = request()->route()?->getName(); @endphp
            @foreach ([
                'dashboard' => ['Beranda', 'dashboard'],
                'rules.index' => ['Aturan Balasan', 'rules.index'],
                'logs.index' => ['Riwayat', 'logs.index'],
                'settings.index' => ['Pengaturan', 'settings.index'],
            ] as $route => [$label, $test])
                <a href="{{ route($route) }}"
                   class="rounded-lg px-3 py-1.5 {{ str_starts_with((string) $current, (string) $test) || (($test === 'dashboard') && $current === null) ? 'bg-neutral-900 text-white' : 'text-neutral-600 hover:bg-neutral-100' }}">
                    {{ $label }}
                </a>
            @endforeach

            @if (session()->has('admin_name'))
                @php $appLock = \App\Models\User::find(session('admin_id'))?->appLock; @endphp
                @if ($appLock !== null && $appLock->enabled && $appLock->pin_hash !== null)
                    <form method="POST" action="{{ route('lock.now') }}">
                        @csrf
                        <button class="ml-2 rounded-lg bg-amber-100 px-3 py-1.5 font-medium text-amber-900 hover:bg-amber-200" title="Kunci aplikasi sekarang">
                            🔒 Kunci ({{ session('admin_name') }})
                        </button>
                    </form>
                @endif
            @endif
        </nav>
    </div>
</header>

<main class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">

    @if (session('flash'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {{ session('flash') }}
        </div>
    @endif

    @if (session('flash-error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            {{ session('flash-error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>