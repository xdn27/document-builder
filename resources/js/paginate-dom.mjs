import { fillPages } from './paginator.mjs';
import { describeFragments, openGroupContainer, appendToGroup } from './fragments.mjs';

/**
 * Driver DOM paginator. Tidak ada penaksiran tinggi di sini: setiap fragmen
 * ditempel ke halaman yang sudah memiliki kop dan kakinya sendiri, lalu badan
 * halaman diperiksa apakah meluap. Margin yang kolaps, header tabel yang diulang,
 * kop yang hanya tampil di halaman tertentu, dan metrik font semuanya dihitung
 * oleh mesin tata letak browser — mesin yang sama yang nanti mencetak.
 */

/** Toleransi pembulatan subpiksel sebelum sebuah badan halaman dianggap meluap. */
const OVERFLOW_TOLERANCE_PX = 1;

/** Batas tunggu gambar; gambar yang gagal dimuat tidak boleh menggantung paginasi. */
const IMAGE_TIMEOUT_MS = 5000;

function zoneAppearsOn(repeat, pageIndex) {
    if (repeat === 'first-only') return pageIndex === 1;
    if (repeat === 'except-first') return pageIndex > 1;

    return true;
}

function bodyOverflows(bodyEl) {
    return bodyEl.scrollHeight > bodyEl.clientHeight + OVERFLOW_TOLERANCE_PX;
}

function fillPageNumbers(scope, pageIndex, pageCount) {
    scope.querySelectorAll('.db-var-page').forEach((el) => {
        el.textContent = String(pageIndex);
    });
    scope.querySelectorAll('.db-var-pages').forEach((el) => {
        el.textContent = String(pageCount);
    });
}

/**
 * Gambar tanpa tinggi eksplisit baru punya tinggi setelah selesai dimuat. Tanpa
 * menunggu, halaman diisi dengan tinggi nol lalu meluap begitu gambar muncul.
 */
function imagesSettled(scope, timeoutMs) {
    const pending = Array.from(scope.querySelectorAll('img')).filter((img) => !img.complete);

    if (pending.length === 0) return Promise.resolve();

    const each = pending.map((img) => new Promise((resolve) => {
        img.addEventListener('load', resolve, { once: true });
        img.addEventListener('error', resolve, { once: true });
    }));

    return Promise.race([
        Promise.all(each),
        new Promise((resolve) => setTimeout(resolve, timeoutMs)),
    ]);
}

export async function paginateDocument(options = {}) {
    const doc = options.document || document;
    // Halaman yang memuat lebih dari satu dokumen bisa menunjuk akarnya sendiri.
    const root = options.root || doc.querySelector('.doc-root');

    if (!root) return null;

    const flow = root.querySelector('.doc-flow');

    if (!flow) return null;

    if (doc.fonts && doc.fonts.ready) {
        await doc.fonts.ready;
    }

    await imagesSettled(flow, options.imageTimeoutMs ?? IMAGE_TIMEOUT_MS);

    const headerZone = flow.querySelector('[data-zone="header"]');
    const bodyZone = flow.querySelector('[data-zone="body"]');
    const footerZone = flow.querySelector('[data-zone="footer"]');

    // Kerangka grup (tabel tanpa baris) harus dikloning sebelum baris dipindahkan.
    const fragments = [];
    let counter = 0;

    for (const blockEl of Array.from(bodyZone ? bodyZone.children : [])) {
        for (const descriptor of describeFragments(blockEl)) {
            fragments.push({ id: `frag-${counter++}`, ...descriptor });
        }
    }

    const container = doc.createElement('div');
    container.className = 'doc-pages';
    flow.replaceWith(container);

    const bodies = new Map();
    const seenGroups = new Set();

    const buildZone = (className, zoneEl, pageIndex) => {
        const el = doc.createElement('div');
        el.className = className;

        if (zoneEl && zoneAppearsOn(zoneEl.dataset.repeat, pageIndex)) {
            el.innerHTML = zoneEl.innerHTML;
        }

        if (zoneEl && zoneEl.dataset.height && zoneEl.dataset.height !== 'auto') {
            el.style.height = `${Number.parseFloat(zoneEl.dataset.height) || 0}mm`;
            el.style.overflow = 'hidden';
        }

        return el;
    };

    const openPage = (pageIndex) => {
        const pageEl = doc.createElement('div');
        pageEl.className = 'doc-page';
        pageEl.dataset.pageIndex = String(pageIndex);

        const bodyEl = doc.createElement('div');
        bodyEl.className = 'doc-page__body';

        pageEl.append(
            buildZone('doc-page__header', headerZone, pageIndex),
            bodyEl,
            buildZone('doc-page__footer', footerZone, pageIndex),
        );

        // Jumlah halaman belum diketahui; angka sementara menjaga tinggi kaki tetap stabil.
        fillPageNumbers(pageEl, pageIndex, pageIndex);
        container.appendChild(pageEl);
        bodies.set(pageIndex, bodyEl);
    };

    /** Tempelkan fragmen; kembalikan fungsi untuk membatalkannya. */
    const attach = (fragment, pageIndex) => {
        const bodyEl = bodies.get(pageIndex);

        if (fragment.groupId === null) {
            bodyEl.appendChild(fragment.el);

            return () => fragment.el.remove();
        }

        let groupEl = bodyEl.lastElementChild;
        let created = false;

        if (!groupEl || groupEl.dataset.groupId !== fragment.groupId) {
            groupEl = openGroupContainer(fragment, !seenGroups.has(fragment.groupId));
            groupEl.dataset.groupId = fragment.groupId;
            bodyEl.appendChild(groupEl);
            created = true;
        }

        appendToGroup(groupEl, fragment);

        return () => {
            fragment.el.remove();

            if (created) groupEl.remove();
        };
    };

    const commit = (fragment) => {
        if (fragment.groupId !== null) seenGroups.add(fragment.groupId);
    };

    const { pages, overflow, truncated } = fillPages(fragments, {
        openPage,
        place: (fragment, pageIndex) => {
            const undo = attach(fragment, pageIndex);

            if (bodyOverflows(bodies.get(pageIndex))) {
                undo();

                return false;
            }

            commit(fragment);

            return true;
        },
        forcePlace: (fragment, pageIndex) => {
            attach(fragment, pageIndex);
            fragment.el.classList.add('db-overflow');
            commit(fragment);
        },
    });

    container.querySelectorAll('.doc-page').forEach((pageEl) => {
        fillPageNumbers(pageEl, Number(pageEl.dataset.pageIndex), pages.length);
    });

    const result = { pageCount: pages.length, overflow, truncated };

    // Sinyal selesai untuk pihak luar — builder, alat verifikasi, atau engine
    // berbasis browser — supaya tidak ada yang perlu menebak lewat jeda waktu.
    root.dataset.paginated = String(pages.length);
    doc.dispatchEvent(new CustomEvent('document-builder:paginated', { detail: result }));

    return result;
}
