const LIVE_INTERVAL = 15000;

/**
 * Lock input media di Pengaturan (self-lock): textarea media_ids readonly
 * bila "Postingan tertentu" nonaktif; input jumlah postingan global readonly
 * bila tidak ada akun yang memakai "Postingan terbaru". Bisa dua-duanya aktif.
 */
function setupMediaScanLock() {
    const countInput = document.querySelector('[name="max_media_per_cycle"]');
    const idsInputs = document.querySelectorAll('[name="media_ids"]');

    if (idsInputs.length === 0) return;

    function recentActive() {
        return [...document.querySelectorAll('[name="scan_recent"]')].some((el) => el.checked);
    }

    function sync() {
        if (countInput) countInput.readOnly = !recentActive();

        idsInputs.forEach((input) => {
            const form = input.closest('form');
            const specific = form.querySelector('[name="scan_specific"]');

            input.readOnly = !specific || !specific.checked;
        });
    }

    document.querySelectorAll('[name="scan_recent"], [name="scan_specific"]').forEach((toggle) => {
        toggle.addEventListener('change', sync);
    });

    sync();
}

/**
 * Konversi URL Instagram → media_id di textarea "Postingan tertentu".
 * Token berformat URL diterjemahkan via endpoint resolve-media (DB → Graph),
 * lalu ditulis ulang sebagai id numerik di dalam textarea.
 */
function setupMediaIdResolver() {
    const forms = document.querySelectorAll('.js-account-media-form[data-resolve-url]');

    forms.forEach((form) => {
        const input = form.querySelector('[name="media_ids"]');
        const status = form.querySelector('.media-resolve-status');

        if (!input || !status) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        let timer = null;
        let busy = false;

        function tokenize() {
            return input.value
                .split(/[\r\n,]+/)
                .map((token) => token.trim())
                .filter(Boolean);
        }

        function isInstagramUrl(token) {
            return /^https?:\/\/(?:www\.)?instagram\.com\/(?:p|reel|tv)\//i.test(token);
        }

        function setStatus(text, error = false) {
            status.textContent = text;
            status.classList.toggle('text-red-500', error);
            status.classList.toggle('text-emerald-600', !error && text !== '');
        }

        async function resolve() {
            if (busy) return;

            const tokens = tokenize();
            const urls = tokens.filter(isInstagramUrl);

            if (urls.length === 0) {
                setStatus('');

                return;
            }

            busy = true;
            setStatus('Mengonversi URL…');

            try {
                const res = await fetch(form.dataset.resolveUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ tokens: urls }),
                });

                if (!res.ok || res.redirected) throw new Error('redirect');

                const data = await res.json();

                if (!data.ok || !Array.isArray(data.results)) {
                    setStatus('Gagal mengonversi URL.', true);

                    return;
                }

                const map = new Map(
                    data.results.map((item) => [item.value, item]),
                );

                const next = tokens.map((token) => {
                    if (!isInstagramUrl(token)) return token;

                    return map.get(token)?.error ? token : (map.get(token)?.media_id ?? token);
                });

                const failed = data.results.filter((item) => item.error);

                input.value = next.join(', ');

                if (failed.length > 0) {
                    setStatus('Sebagian gagal dikonversi: '.concat(
                        failed.map((item) => item.value).slice(0, 3).join(', '),
                    ), true);
                } else {
                    setStatus('URL dikonversi menjadi media id.');
                }
            } catch (_) {
                setStatus('Gagal menghubungi server.', true);
            } finally {
                busy = false;
            }
        }

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(resolve, 500);
        });

        input.addEventListener('blur', () => {
            window.clearTimeout(timer);
            resolve();
        });
    });
}

/**
 * Auto-refresh Beranda: fetch fragmen HTML dan ganti isi #live-dashboard.
 */
function setupDashboardLive() {
    const container = document.getElementById('live-dashboard');

    if (!container || !container.dataset.url) return;

    const indicator = document.getElementById('live-updated');
    let busy = false;

    async function refresh() {
        if (busy || document.hidden) return;

        busy = true;

        try {
            const res = await fetch(container.dataset.url, { headers: { Accept: 'text/html' } });

            if (!res.ok || res.redirected) return;

            const html = await res.text();

            if (html.trim() === '') return;

            container.innerHTML = html;

            if (indicator) {
                indicator.textContent = 'Diperbarui ' + new Date().toLocaleTimeString('id-ID');
            }
        } catch (_) {
            // Jaringan tidak tersedia — teruskan hidup dengan data lama.
        } finally {
            busy = false;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });

    setInterval(refresh, LIVE_INTERVAL);
}

/**
 * Auto-refresh Riwayat Komentar: pertahankan filter aktif (q/status),
 * ganti isi #logs-list + jumlah total.
 */
function setupLogsLive() {
    const list = document.getElementById('logs-list');

    if (!list || !list.dataset.baseUrl) return;

    const countSpan = document.getElementById('logs-count');
    let busy = false;

    function liveUrl() {
        const params = new URLSearchParams();
        params.set('partial', '1');

        const status = document.querySelector('select[name="status"]');
        const q = document.querySelector('input[name="q"]');

        if (status && status.value) params.set('status', status.value);
        if (q && q.value.trim() !== '') params.set('q', q.value.trim());

        return list.dataset.baseUrl + '?' + params.toString();
    }

    function apply(html) {
        const template = document.createElement('template');
        template.innerHTML = html;

        const nextList = template.content.querySelector('#logs-list');

        if (nextList) {
            list.innerHTML = nextList.innerHTML;
            if (countSpan) countSpan.textContent = nextList.dataset.count + ' komentar';
        }
    }

    async function refresh() {
        if (busy || document.hidden) return;

        busy = true;

        try {
            const res = await fetch(liveUrl(), { headers: { Accept: 'text/html' } });

            if (!res.ok || res.redirected) return;

            apply(await res.text());
        } catch (_) {
            // Jaringan tidak tersedia — biarkan daftar tetap tampil.
        } finally {
            busy = false;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });

    setInterval(refresh, LIVE_INTERVAL);
}

/**
 * Tombol "▶ Cek sekarang": jalankan polling lewat fetch (JSON) tanpa reload.
 */
function setupPollNow() {
    const btn = document.getElementById('poll-now-btn');

    if (!btn) return;

    const status = document.getElementById('poll-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    btn.addEventListener('click', async () => {
        if (btn.disabled) return;

        const original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Memeriksa…';
        status.textContent = '';
        status.className = 'text-sm';

        try {
            const res = await fetch(btn.dataset.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });

            const data = await res.json().catch(() => null);

            if (data && typeof data.message === 'string') {
                status.textContent = data.message;
                status.classList.add(data.ok === false ? 'text-red-600' : 'text-emerald-600');
            } else {
                status.textContent = 'Selesai.';
                status.classList.add('text-emerald-600');
            }
        } catch (_) {
            status.textContent = 'Gagal menghubungi server.';
            status.classList.add('text-red-600');
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    });
}

/**
 * Judul kartu postingan di Riwayat Komentar:
 * hover judul → balloon preview (oEmbed Read via /logs/media-preview);
 * klik judul → buka postingan penuh di tab baru.
 */
function setupMediaPreview() {
    const popover = document.getElementById('media-popover');

    if (!popover) return;

    const body = document.getElementById('media-popover-body');
    const openLink = document.getElementById('media-preview-open');

    const cache = new Map();
    let busy = false;
    let embedJsLoaded = false;
    let hoverTimer = null;
    let hideTimer = null;
    let activeTrigger = null;

    function loadEmbedJs() {
        if (embedJsLoaded) return Promise.resolve();

        return new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = 'https://www.instagram.com/embed.js';
            script.async = true;
            script.onload = resolve;
            script.onerror = resolve;
            document.head.appendChild(script);
            embedJsLoaded = true;
        });
    }

    function position() {
        const rect = activeTrigger.getBoundingClientRect();
        const width = popover.offsetWidth || 384;
        const gap = 8;
        const top = rect.bottom + gap;
        const left = Math.min(Math.max(gap, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - gap);

        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
    }

    function show() {
        popover.classList.remove('hidden');
        window.clearTimeout(hideTimer);
    }

    function hide() {
        popover.classList.add('hidden');
        window.clearTimeout(hoverTimer);
        window.clearTimeout(hideTimer);
    }

    function setLoading() {
        body.innerHTML = '';
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 text-neutral-500';
        const spinner = document.createElement('span');
        spinner.className = 'inline-block h-4 w-4 animate-spin rounded-full border-2 border-fuchsia-500 border-t-transparent';
        const label = document.createElement('span');
        label.textContent = 'Memuat preview…';
        row.append(spinner, label);
        body.appendChild(row);
    }

    async function fetchPreview(mediaId) {
        if (cache.has(mediaId)) return cache.get(mediaId);

        const url = popover.dataset.previewBase + '?media_id=' + encodeURIComponent(mediaId);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });

        if (!res.ok || res.redirected) throw new Error('redirect');

        const data = await res.json();
        cache.set(mediaId, data);

        return data;
    }

    async function openPreview(mediaId, trigger) {
        if (busy) return;

        busy = true;
        activeTrigger = trigger;
        setLoading();
        position();
        show();

        try {
            const data = await fetchPreview(mediaId);

            if (data.ok && data.media_url) {
                window.open(data.media_url, '_blank', 'noopener');

                return;
            }

            body.textContent = data.message || 'URL postingan tidak tersedia.';
            openLink.href = '#';
        } catch (_) {
            body.textContent = 'Gagal memuat preview.';
        } finally {
            busy = false;
        }
    }

    async function openHover(mediaId, trigger) {
        if (busy) return;

        busy = true;
        setLoading();

        try {
            const data = await fetchPreview(mediaId);

            activeTrigger = trigger;

            if (!data.ok) {
                body.textContent = data.message || 'Preview tidak tersedia.';
                openLink.href = '#';
                position();
                show();

                return;
            }

            openLink.href = data.media_url;
            body.innerHTML = '';
            const embed = document.createElement('div');
            embed.innerHTML = data.html;
            body.appendChild(embed);

            position();
            show();

            await loadEmbedJs();

            // Beri kesempatan embed.js hadir di DOM sebelum dirender.
            setTimeout(() => {
                try {
                    window.instgrm?.Embeds?.process();
                } catch (_) {
                    // Render embed gagal — halaman Instagram tetap bisa dibuka via link.
                }
            }, 250);
        } catch (_) {
            // Jaringan/lock: biarkan balloon tertutup.
        } finally {
            busy = false;
        }
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('.js-media-preview');

        if (!trigger) return;

        event.preventDefault();
        hide();
        openPreview(trigger.dataset.mediaId, trigger);
    });

    document.addEventListener('mouseover', (event) => {
        const trigger = event.target.closest('.js-media-preview');

        if (!trigger) return;

        if (trigger === activeTrigger && !popover.classList.contains('hidden')) return;

        window.clearTimeout(hoverTimer);
        window.clearTimeout(hideTimer);
        hoverTimer = setTimeout(() => openHover(trigger.dataset.mediaId, trigger), 350);
    });

    document.addEventListener('mouseout', (event) => {
        if (!event.target.closest('.js-media-preview')) return;

        window.clearTimeout(hoverTimer);
        // Jeda biar pengguna bisa pindah cursor ke balloon untuk scroll preview.
        window.clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, 600);
    });

    // Balloon tetap terbuka selama cursor di dalamnya (supaya bisa di-scroll).
    // mouseenter/mouseleave tidak membubble dari child, jadi tepat untuk ini.
    popover.addEventListener('mouseenter', () => {
        window.clearTimeout(hideTimer);
    });

    popover.addEventListener('mouseleave', () => {
        window.clearTimeout(hoverTimer);
        window.clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, 200);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    setupDashboardLive();
    setupLogsLive();
    setupPollNow();
    setupMediaPreview();
    setupMediaScanLock();
    setupMediaIdResolver();
});