import { test } from 'node:test';
import assert from 'node:assert/strict';
import { assignPages, MAX_PAGES } from '../../resources/js/paginator.mjs';

const fragment = (id, heightMm, breakInside = 'auto') => ({ id, heightMm, breakInside });

test('dokumen kosong tetap menghasilkan satu halaman', () => {
    const result = assignPages([], 257);

    assert.equal(result.pages.length, 1);
    assert.deepEqual(result.pages[0].fragmentIds, []);
    assert.equal(result.truncated, false);
});

test('fragmen yang muat semua tinggal di satu halaman', () => {
    const result = assignPages([fragment('a', 100), fragment('b', 100)], 257);

    assert.equal(result.pages.length, 1);
    assert.deepEqual(result.pages[0].fragmentIds, ['a', 'b']);
});

test('fragmen yang melampaui tinggi badan pindah ke halaman berikutnya', () => {
    const result = assignPages([fragment('a', 200), fragment('b', 100)], 257);

    assert.equal(result.pages.length, 2);
    assert.deepEqual(result.pages[0].fragmentIds, ['a']);
    assert.deepEqual(result.pages[1].fragmentIds, ['b']);
});

test('tinggi yang pas persis tidak memicu halaman baru', () => {
    const result = assignPages([fragment('a', 128.5), fragment('b', 128.5)], 257);

    assert.equal(result.pages.length, 1);
});

test('selisih pembulatan sub-milimeter tidak memicu halaman baru', () => {
    const result = assignPages([fragment('a', 257.005)], 257);

    assert.equal(result.pages.length, 1);
    assert.deepEqual(result.overflow, []);
});

test('fragmen yang lebih tinggi dari halaman mendapat halamannya sendiri dan ditandai melimpah', () => {
    const result = assignPages([fragment('a', 50), fragment('besar', 400), fragment('c', 50)], 257);

    assert.deepEqual(result.pages.map((p) => p.fragmentIds), [['a'], ['besar'], ['c']]);
    assert.deepEqual(result.overflow, ['besar']);
});

test('fragmen melimpah di posisi pertama tidak menyisakan halaman kosong', () => {
    const result = assignPages([fragment('besar', 400), fragment('b', 50)], 257);

    assert.deepEqual(result.pages.map((p) => p.fragmentIds), [['besar'], ['b']]);
});

test('nomor halaman berbasis satu dan berurutan', () => {
    const result = assignPages([fragment('a', 200), fragment('b', 200), fragment('c', 200)], 257);

    assert.deepEqual(result.pages.map((p) => p.index), [1, 2, 3]);
});

test('jumlah halaman dibatasi supaya tidak mungkin loop tak berujung', () => {
    const many = Array.from({ length: 500 }, (_, i) => fragment(`f${i}`, 300));
    const result = assignPages(many, 257);

    assert.equal(result.pages.length, MAX_PAGES);
    assert.equal(result.truncated, true);
});

test('batas halaman dapat diturunkan lewat argumen', () => {
    const many = Array.from({ length: 20 }, (_, i) => fragment(`f${i}`, 300));
    const result = assignPages(many, 257, 3);

    assert.equal(result.pages.length, 3);
    assert.equal(result.truncated, true);
});

test('tinggi badan nol atau negatif menaruh semuanya di satu halaman dan menandainya melimpah', () => {
    const result = assignPages([fragment('a', 10), fragment('b', 10)], 0);

    assert.equal(result.pages.length, 1);
    assert.deepEqual(result.overflow, ['a', 'b']);
});

test('fragmen bertinggi nol tidak memicu halaman baru', () => {
    const result = assignPages([fragment('a', 257), fragment('kosong', 0)], 257);

    assert.equal(result.pages.length, 1);
});

// ---- fillPages: pengukuran disuntikkan, sebagaimana driver DOM memakainya ----

import { fillPages } from '../../resources/js/paginator.mjs';

/** Halaman palsu dengan kapasitas berbeda per nomor halaman. */
function fakeIo(capacityOf) {
    const used = new Map();
    const log = [];

    return {
        log,
        io: {
            openPage: (i) => { used.set(i, 0); log.push(`buka ${i}`); },
            place: (f, i) => {
                const next = used.get(i) + f.h;
                if (next > capacityOf(i)) { log.push(`tolak ${f.id}@${i}`); return false; }
                used.set(i, next);
                return true;
            },
            forcePlace: (f, i) => used.set(i, used.get(i) + f.h),
        },
    };
}

test('kapasitas berbeda per halaman dipakai apa adanya, misal kop hanya di halaman pertama', () => {
    // Halaman 1 sempit karena kop; halaman berikutnya lebih lega.
    const { io } = fakeIo((i) => (i === 1 ? 100 : 200));
    const f = (id, h) => ({ id, h });

    const result = fillPages([f('a', 60), f('b', 60), f('c', 60), f('d', 60), f('e', 60)], io);

    assert.deepEqual(result.pages.map((p) => p.fragmentIds), [['a'], ['b', 'c', 'd'], ['e']]);
});

test('fragmen yang ditolak dicoba ulang di halaman baru, bukan dibuang', () => {
    const { io, log } = fakeIo(() => 100);

    const result = fillPages([{ id: 'a', h: 70 }, { id: 'b', h: 70 }], io);

    assert.deepEqual(result.pages.map((p) => p.fragmentIds), [['a'], ['b']]);
    assert.deepEqual(log, ['buka 1', 'tolak b@1', 'buka 2']);
});

test('halaman hanya dibuka saat memang dibutuhkan', () => {
    const { io, log } = fakeIo(() => 100);

    fillPages([{ id: 'a', h: 10 }, { id: 'b', h: 10 }], io);

    assert.deepEqual(log, ['buka 1']);
});
