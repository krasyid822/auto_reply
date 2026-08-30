<div class="{{ isset($reason) ? 'mt-4 ' : '' }}rounded-xl border border-dashed border-neutral-300 p-4 text-sm text-neutral-600">
    @isset($reason)
        <p class="mb-2 font-semibold text-neutral-800">{{ $reason }}</p>
    @endisset
    <p class="font-semibold text-neutral-800">Panduan: sambungkan Instagram ke Halaman Facebook</p>
    <ol class="mt-2 list-inside list-decimal space-y-1">
        <li>Buat Halaman Facebook bila belum punya: <a href="https://www.facebook.com/pages/create" target="_blank" rel="noopener" class="text-fuchsia-600 underline">facebook.com/pages/create</a>.</li>
        <li>Ubah akun Instagram ke tipe profesional (Business/Creator): menu <em>Settings</em> di aplikasi Instagram → <em>Account type</em>.</li>
        <li>Link ke halaman: Instagram <em>Settings</em> → <em>Connections</em> / <em>Account Center</em> → <em>Facebook</em> → pilih halaman. (Alternatif lewat <a href="https://business.facebook.com" target="_blank" rel="noopener" class="text-fuchsia-600 underline">Meta Business Suite</a> → <em>Business settings</em> → link IG ke halaman.)</li>
        <li>Kembali ke aplikasi, klik <em>Hubungkan dengan Facebook</em> lagi dan pilih halaman yang diinginkan.</li>
    </ol>
    <p class="mt-2 text-xs text-neutral-400">
        Halaman yang terhubung akan ditandai <span class="font-medium text-emerald-700">"IG terhubung"</span>.
    </p>
</div>