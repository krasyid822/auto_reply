@extends('layouts.app')

@section('title', 'Pilih Halaman')

@section('content')
    <h1 class="text-2xl font-bold">Sambungkan akun Instagram</h1>

    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="font-bold">Pilih Halaman Facebook</h2>
        <p class="mt-1 text-sm text-neutral-500">
            Akun Instagram Business/Creator harus ter-link ke Halaman Facebook yang Anda kelola.
            Di bawah ini halaman milik akun Facebook yang baru saja login — pilih yang menu terhubung ke Instagram.
        </p>

        @if (count($pages) === 0)
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Tidak ditemukan halaman Facebook pada akun ini.</p>
                <p class="mt-1">Buat Halaman Facebook terlebih dahulu, link ke Instagram (lihat panduan di bawah), lalu klik Hubungkan dengan Facebook lagi.</p>
            </div>
            @include('connect._guide')
        @else
            @php $hasAnyIg = collect($pages)->contains('has_ig', true); @endphp

            <form method="POST" action="{{ route('connect.select') }}" class="mt-4 space-y-3">
                @csrf

                @foreach ($pages as $page)
                    <label class="flex items-start gap-3 rounded-xl border border-neutral-200 px-4 py-3 transition {{ $page['has_ig'] ? 'cursor-pointer hover:border-fuchsia-400 hover:bg-fuchsia-50/50' : 'bg-neutral-50' }}">
                        <input type="radio" name="page_id" value="{{ $page['id'] }}"
                               @disabled(! $page['has_ig'])
                               class="mt-1 size-4 accent-fuchsia-600"
                               @required(! $page['has_ig'])>
                        <span class="flex-1">
                            <span class="block font-medium">{{ $page['name'] }}</span>
                            @if ($page['has_ig'])
                                <span class="mt-0.5 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                                    IG terhubung{{ $page['ig_username'] ? ': @'.$page['ig_username'] : '' }}
                                </span>
                            @else
                                <span class="mt-0.5 inline-block rounded-full bg-neutral-200 px-2 py-0.5 text-xs font-medium text-neutral-600">
                                    belum ter-link ke akun Instagram
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach

                <div class="flex flex-wrap items-center gap-2 pt-2">
                    <button type="submit" @disabled(! $hasAnyIg)
                            class="rounded-xl bg-[#1877F2] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#0f68d6] disabled:cursor-not-allowed disabled:opacity-40">
                        Hubungkan halaman ini
                    </button>

                    <form method="POST" action="{{ route('connect.cancel') }}">
                        @csrf
                        <button type="submit" class="rounded-xl border border-neutral-300 px-5 py-2.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100">
                            Batal
                        </button>
                    </form>
                </div>
            </form>

            @if (! $hasAnyIg)
                @include('connect._guide', ['reason' => 'Belum ada halaman yang terhubung ke akun Instagram.'])
            @endif
        @endif
    </section>
@endsection