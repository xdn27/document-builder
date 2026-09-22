/**
 * Logika keputusan halaman, bebas DOM supaya bisa diuji tanpa browser.
 *
 * Paginator tidak menaksir tinggi. Pemanggil menyuntikkan `place`, yang benar-benar
 * menempelkan fragmen ke halaman dan melaporkan apakah halaman itu meluap. Dengan
 * begitu margin yang kolaps, header tabel yang diulang, dan kop yang hanya muncul di
 * halaman tertentu dihitung oleh mesin tata letak itu sendiri, bukan oleh perkiraan.
 */

export const MAX_PAGES = 200;

/** Toleransi pembulatan: px→mm menghasilkan pecahan yang tidak pernah bulat. */
export const EPSILON_MM = 0.01;

/**
 * @template {{id: string}} F
 * @param {F[]} fragments
 * @param {{
 *   openPage: (pageIndex: number) => void,
 *   place: (fragment: F, pageIndex: number) => boolean,
 *   forcePlace: (fragment: F, pageIndex: number) => void,
 *   maxPages?: number,
 * }} io
 *   `place` menempelkan fragmen dan mengembalikan true bila halaman tidak meluap;
 *   bila meluap, `place` wajib melepas kembali fragmennya dan mengembalikan false.
 *   `forcePlace` menempelkan tanpa pemeriksaan — dipakai untuk fragmen yang
 *   sendirian pun tidak muat.
 * @returns {{pages: {index: number, fragmentIds: string[]}[], overflow: string[], truncated: boolean}}
 */
export function fillPages(fragments, { openPage, place, forcePlace, maxPages = MAX_PAGES }) {
    const pages = [{ index: 1, fragmentIds: [] }];
    const overflow = [];
    let truncated = false;

    openPage(1);

    for (const fragment of fragments) {
        const current = pages[pages.length - 1];

        if (place(fragment, current.index)) {
            current.fragmentIds.push(fragment.id);
            continue;
        }

        // Halaman masih kosong tetapi fragmen tetap tidak muat: biarkan melimpah di
        // halamannya sendiri dan jangan pernah mencoba memecahnya berulang.
        if (current.fragmentIds.length === 0) {
            forcePlace(fragment, current.index);
            current.fragmentIds.push(fragment.id);
            overflow.push(fragment.id);
            continue;
        }

        if (pages.length >= maxPages) {
            truncated = true;
            break;
        }

        const next = { index: pages.length + 1, fragmentIds: [] };
        pages.push(next);
        openPage(next.index);

        if (place(fragment, next.index)) {
            next.fragmentIds.push(fragment.id);
        } else {
            forcePlace(fragment, next.index);
            next.fragmentIds.push(fragment.id);
            overflow.push(fragment.id);
        }
    }

    return { pages, overflow, truncated };
}

/**
 * Varian berbasis tinggi yang sudah diketahui. Tidak dipakai driver DOM, tetapi
 * mempertahankan kontrak lama sebagai test regresi untuk `fillPages`.
 *
 * @param {{id: string, heightMm: number}[]} fragments
 * @param {number} bodyHeightMm
 * @param {number} [maxPages]
 */
export function assignPages(fragments, bodyHeightMm, maxPages = MAX_PAGES) {
    if (!(bodyHeightMm > 0)) {
        return {
            pages: [{ index: 1, fragmentIds: fragments.map((f) => f.id) }],
            overflow: fragments.map((f) => f.id),
            truncated: false,
        };
    }

    const used = new Map();
    const heightOf = (f) => (Number.isFinite(f.heightMm) ? Math.max(0, f.heightMm) : 0);

    return fillPages(fragments, {
        maxPages,
        openPage: (index) => used.set(index, 0),
        place: (f, index) => {
            const next = used.get(index) + heightOf(f);

            if (next > bodyHeightMm + EPSILON_MM) {
                return false;
            }

            used.set(index, next);
            return true;
        },
        forcePlace: (f, index) => used.set(index, used.get(index) + heightOf(f)),
    });
}
