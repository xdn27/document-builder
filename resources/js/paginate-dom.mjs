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

/** Ukuran awal watermark sebelum dikecilkan — sama dengan bawaan mpdf. */
export const WATERMARK_MAX_PT = 120;

/**
 * Meniru MpdfEngine (Mpdf::watermark()): mulai dari 120pt, turunkan satu poin
 * sampai teks muat di sisi pendek kertas dikurangi geseran akibat rotasi 45°.
 * Lebar teks diukur, bukan ditaksir: widthAtMax adalah lebar sungguhan pada 120pt,
 * dan lebar teks berbanding lurus dengan ukuran huruf.
 */
export function fitWatermarkSize({ widthAtMax, pageWidth, pageHeight, pxPerPt }) {
    const maxLength = Math.min(pageWidth, pageHeight);
    const shift = Math.sin(Math.PI / 4) * pxPerPt;

    for (let size = WATERMARK_MAX_PT; size > 1; size--) {
        if ((widthAtMax * size) / WATERMARK_MAX_PT <= maxLength - shift * size) return size;
    }

    return 1;
}

/**
 * Cetakan .doc-watermark disalin ke tiap halaman setelah paginasi selesai,
 * sehingga tidak pernah ikut diukur sebagai isi. Diukur sekali di halaman
 * pertama; semua halaman berukuran sama.
 */
function applyWatermark(doc, root, container) {
    const template = root.querySelector('.doc-watermark');

    if (!template) return;

    template.remove();

    let size = null;

    container.querySelectorAll('.doc-page').forEach((pageEl) => {
        const el = doc.createElement('div');
        el.className = 'doc-page__watermark';
        el.setAttribute('aria-hidden', 'true');
        el.style.opacity = template.style.opacity;
        el.textContent = template.textContent;
        pageEl.appendChild(el);

        if (size === null) {
            el.style.fontSize = `${WATERMARK_MAX_PT}pt`;
            // offsetWidth tidak terpengaruh transform: lebar teks sebelum diputar.
            // 1pt = 4/3 px di CSS; ukuran halaman juga dibaca tanpa skala zoom kanvas.
            size = fitWatermarkSize({
                widthAtMax: el.offsetWidth,
                pageWidth: pageEl.offsetWidth,
                pageHeight: pageEl.offsetHeight,
                pxPerPt: 4 / 3,
            });
        }

        el.style.fontSize = `${size}pt`;
    });
}

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

    applyWatermark(doc, root, container);

    const result = { pageCount: pages.length, overflow, truncated };

    // Sinyal selesai untuk pihak luar — builder, alat verifikasi, atau engine
    // berbasis browser — supaya tidak ada yang perlu menebak lewat jeda waktu.
    root.dataset.paginated = String(pages.length);
    doc.dispatchEvent(new CustomEvent('document-builder:paginated', { detail: result }));

    return result;
}
