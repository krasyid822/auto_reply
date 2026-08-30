@extends('layouts.app')

@section('title', 'Aturan Balasan')

@section('content')
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Aturan Balasan</h1>
        <span class="text-sm text-neutral-500">
            Pencocokan: komentar mengandung keyword (tanpa peduli huruf besar/kecil).
        </span>
    </div>

    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="font-bold">{{ $editing ? 'Edit aturan' : 'Aturan baru' }}</h2>
        <form method="POST"
              action="{{ $editing ? route('rules.update', $editing) : route('rules.store') }}"
              class="mt-4 grid gap-4 sm:grid-cols-[1fr_2fr_auto]">
            @csrf
            <div>
                <label class="mb-1 block text-xs font-semibold text-neutral-500">Keyword</label>
                <input name="keyword" required maxlength="255" value="{{ old('keyword', $editing?->keyword) }}"
                       placeholder="mis. harga"
                       class="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-neutral-500">Template balasan</label>
                <input name="reply_text" required maxlength="1000" value="{{ old('reply_text', $editing?->reply_text) }}"
                       placeholder="mis. Untuk info harga silakan DM ya!"
                       class="w-full rounded-xl border border-neutral-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:outline-none focus:ring-2 focus:ring-fuchsia-100">
            </div>
            <div class="flex items-end gap-2">
                @if ($editing)
                    <input type="hidden" name="is_active" value="1">
                    <a href="{{ route('rules.index') }}" class="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-medium hover:bg-neutral-100">Batal</a>
                @endif
                <button class="rounded-xl bg-neutral-900 px-5 py-2 text-sm font-semibold text-white hover:bg-neutral-700">
                    {{ $editing ? 'Simpan' : 'Tambah' }}
                </button>
            </div>
        </form>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-xs uppercase tracking-wide text-neutral-500">
                        <th class="py-2 pr-4">Keyword</th>
                        <th class="py-2 pr-4">Balasan</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($rules as $rule)
                        <tr>
                            <td class="max-w-[10rem] py-3 pr-4 font-medium">{{ $rule->keyword }}</td>
                            <td class="max-w-md truncate py-3 pr-4 text-neutral-600">{{ $rule->reply_text }}</td>
                            <td class="py-3 pr-4">
                                <form method="POST" action="{{ route('rules.toggle', $rule) }}">
                                    @csrf
                                    <button class="rounded-full px-3 py-1 text-xs font-medium {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-neutral-200 text-neutral-600' }}">
                                        {{ $rule->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </button>
                                </form>
                            </td>
                            <td class="py-3">
                                <div class="flex gap-2">
                                    <a href="{{ route('rules.index', ['edit' => $rule->id]) }}"
                                       class="rounded-lg border border-neutral-300 px-3 py-1 text-xs font-medium hover:bg-neutral-100">Edit</a>
                                    <form method="POST" action="{{ route('rules.destroy', $rule) }}"
                                          onsubmit="return confirm('Hapus aturan ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-lg border border-red-200 px-3 py-1 text-xs font-medium text-red-600 hover:bg-red-50">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-sm text-neutral-500">
                                Belum ada aturan balasan. Tambahkan aturan pertama di atas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection