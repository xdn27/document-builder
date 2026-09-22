import { paginateDocument } from './paginate-dom.mjs';

/**
 * Jembatan antara panel Livewire dan kanvas. Livewire tidak pernah menyentuh DOM
 * kanvas — ia hanya mengirim HTML baru, dan modul ini yang memasang serta
 * memaginasinya. Pemisahan ini yang membuat paginator bebas mengganti .doc-flow
 * menjadi .doc-pages tanpa mengganggu diffing Livewire.
 *
 * Semua listener dipasang sekali di level document dengan delegasi, sehingga
 * tetap bekerja setelah Livewire mengganti elemen panel.
 */

function highlight(canvas, state) {
    canvas.querySelectorAll('[data-block-id]').forEach((el) => {
        el.classList.toggle('db-selected', el.dataset.blockId === state.selectedId);
    });
}

async function renderInto(doc, canvas, styleEl, state, { html, css }, onRendered) {
    if (typeof css === 'string' && styleEl) {
        styleEl.textContent = css;
    }

    canvas.innerHTML = html;

    const result = await paginateDocument({ document: doc, root: canvas.querySelector('.doc-root') });

    highlight(canvas, state);
    onRendered?.(result);
}

/**
 * Mengembalikan handle, bukan memasang listener sendiri. Nama event dan bentuk
 * transportnya berbeda antara Livewire 2, Livewire 3, dan konsumen non-Blade —
 * jadi kabel itu milik pemanggil (3 baris di Blade), sementara modul ini tetap
 * satu-satunya pemilik logika kanvas.
 */
export function initBuilder({
    document: doc = globalThis.document,
    canvasSelector = '#db-canvas',
    styleSelector = '#db-document-css',
    onSelect,
    onReorder,
    onRendered,
} = {}) {
    const canvas = doc.querySelector(canvasSelector);
    const styleEl = doc.querySelector(styleSelector);

    // Lokal per pemanggilan, bukan modul-level: dua initBuilder() pada halaman
    // yang sama (mis. Livewire remount) tidak boleh saling mewarisi revision —
    // instance baru yang genuinely mulai dari revision 1 akan ditolak sendiri
    // kalau state-nya dibagi dengan instance sebelumnya yang sudah sampai lebih
    // tinggi.
    const state = {
        selectedId: null,
        rendering: Promise.resolve(),
        revision: 0,
    };

    const handle = {
        applyPreview: () => Promise.resolve(),
        currentRevision: () => state.revision,
    };

    if (!canvas) return handle;

    canvas.addEventListener('click', (event) => {
        const blockEl = event.target.closest('[data-block-id]');

        if (!blockEl || !canvas.contains(blockEl)) return;

        state.selectedId = blockEl.dataset.blockId;
        highlight(canvas, state);
        onSelect?.(state.selectedId);
    });

    handle.applyPreview = (detail = {}) => {
        const revision = detail.revision;

        // Respons yang lebih tua dari yang terakhir dilukis dibuang: tanpa ini,
        // dua perubahan beruntun bisa selesai di luar urutan dan kanvas mundur
        // ke keadaan lama (spec §9.1). Revisi yang sama diterapkan ulang,
        // supaya pengiriman ulang yang disengaja tidak hilang.
        if (typeof revision === 'number') {
            if (revision < state.revision) return state.rendering;
            state.revision = revision;
        }

        if ('selectedId' in detail) {
            state.selectedId = detail.selectedId;
        }

        // Pembaruan berurutan: paginasi yang sedang berjalan diselesaikan dulu,
        // supaya dua pembaruan beruntun tidak saling menimpa kanvas setengah jadi.
        state.rendering = state.rendering.then(() => renderInto(doc, canvas, styleEl, state, detail, onRendered));

        return state.rendering;
    };

    initOutlineDragging({ document: doc, onReorder });

    // Kanvas awal sudah dicetak server sebagai .doc-flow; cukup dipaginasi.
    if (canvas.querySelector('.doc-flow')) {
        state.rendering = paginateDocument({ document: doc, root: canvas.querySelector('.doc-root') }).then((result) => {
            highlight(canvas, state);
            onRendered?.(result);
        });
    }

    return handle;
}

/**
 * Menyusun ulang lewat daftar urutan, bukan lewat kanvas: menyeret melintasi
 * batas halaman yang sudah terpaginasi rapuh dan membingungkan.
 */
export function initOutlineDragging({ document: doc = globalThis.document, onReorder } = {}) {
    let dragged = null;

    doc.addEventListener('dragstart', (event) => {
        dragged = event.target.closest?.('[data-outline-id]') ?? null;

        if (dragged) {
            event.dataTransfer.effectAllowed = 'move';
            dragged.classList.add('opacity-50');
        }
    });

    doc.addEventListener('dragover', (event) => {
        if (!dragged) return;

        const target = event.target.closest?.('[data-outline-id]');

        if (!target || target === dragged || target.parentNode !== dragged.parentNode) return;

        event.preventDefault();

        const rect = target.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;

        target.parentNode.insertBefore(dragged, after ? target.nextSibling : target);
    });

    doc.addEventListener('drop', (event) => {
        if (!dragged) return;

        event.preventDefault();
    });

    doc.addEventListener('dragend', () => {
        if (!dragged) return;

        const list = dragged.closest('[data-outline-zone]');
        dragged.classList.remove('opacity-50');

        if (list) {
            const ids = Array.from(list.querySelectorAll('[data-outline-id]')).map((el) => el.dataset.outlineId);
            onReorder?.(list.dataset.outlineZone, ids);
        }

        dragged = null;
    });
}
