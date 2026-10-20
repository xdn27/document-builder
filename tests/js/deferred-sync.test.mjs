import { test, mock } from 'node:test';
import assert from 'node:assert/strict';

import { initDeferredSync } from '../../resources/js/builder.mjs';

/**
 * Isian panel memakai wire:model biasa (deferred): mengetik tidak mengirim
 * request. initDeferredSync() yang memutuskan kapan perubahan itu dikirim —
 * sekali setelah pengguna berhenti mengetik, atau langsung untuk pilihan
 * (select, checkbox) dan saat isian ditinggalkan.
 */
function fakeDocument() {
    const listeners = {};

    return {
        listeners,
        addEventListener(type, handler) {
            (listeners[type] ??= []).push(handler);
        },
        fire(type, target) {
            for (const handler of listeners[type] ?? []) {
                handler({ type, target });
            }
        },
    };
}

function field({ tag = 'INPUT', type = 'text', model = 'wire:model' } = {}) {
    const attributes = model ? { [model]: 'schema.page.margin.top' } : {};

    return {
        tagName: tag,
        type,
        getAttributeNames: () => Object.keys(attributes),
    };
}

function setup(delay = 800) {
    mock.timers.enable({ apis: ['setTimeout'] });

    const doc = fakeDocument();
    const flushes = [];
    const sync = initDeferredSync({ document: doc, delay, onFlush: () => flushes.push(true) });

    return { doc, flushes, sync };
}

test('mengetik tidak mengirim apa pun sampai pengguna berhenti selama jeda', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();
    const input = field();

    doc.fire('input', input);
    mock.timers.tick(500);
    doc.fire('input', input);
    mock.timers.tick(500);

    assert.equal(flushes.length, 0, 'ketikan kedua harus menunda ulang jeda');

    mock.timers.tick(300);

    assert.equal(flushes.length, 1);
});

test('banyak ketikan beruntun hanya menghasilkan satu kiriman', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();
    const input = field({ tag: 'TEXTAREA', type: 'textarea' });

    for (let i = 0; i < 20; i++) {
        doc.fire('input', input);
        mock.timers.tick(50);
    }

    mock.timers.tick(800);

    assert.equal(flushes.length, 1);
});

test('select dan checkbox dikirim langsung saat berubah, tanpa menunggu jeda', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();

    doc.fire('input', field({ tag: 'SELECT', type: 'select-one' }));
    doc.fire('change', field({ tag: 'SELECT', type: 'select-one' }));

    assert.equal(flushes.length, 1, 'input + change dari select yang sama tidak boleh dikirim dua kali');

    doc.fire('change', field({ type: 'checkbox' }));

    assert.equal(flushes.length, 2);
});

test('meninggalkan isian teks mengirim perubahan yang tertunda saat itu juga', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();
    const input = field();

    doc.fire('input', input);
    doc.fire('change', input);

    assert.equal(flushes.length, 1);

    mock.timers.tick(800);

    assert.equal(flushes.length, 1, 'jeda yang sudah dikirim lewat change tidak boleh mengirim lagi');
});

test('change tanpa ketikan sebelumnya pada isian teks tidak mengirim apa pun', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();

    doc.fire('change', field());

    assert.equal(flushes.length, 0);
});

test('isian tanpa wire:model dan input berkas diabaikan', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();

    doc.fire('input', field({ model: null }));
    doc.fire('change', field({ model: null, tag: 'SELECT', type: 'select-one' }));
    doc.fire('change', field({ type: 'file' }));
    mock.timers.tick(800);

    assert.equal(flushes.length, 0);
});

test('modifier wire:model tetap dikenali', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes } = setup();

    doc.fire('change', field({ tag: 'SELECT', type: 'select-one', model: 'wire:model.blur' }));

    assert.equal(flushes.length, 1);
});

test('flush() mengirim perubahan tertunda sekarang dan membatalkan jeda', (t) => {
    t.after(() => mock.timers.reset());
    const { doc, flushes, sync } = setup();

    doc.fire('input', field());

    assert.equal(sync.pending(), true);

    sync.flush();
    mock.timers.tick(800);

    assert.equal(flushes.length, 1);
    assert.equal(sync.pending(), false);
});

test('tanpa document yang valid, handle tetap aman dipanggil', () => {
    const sync = initDeferredSync({ document: null, onFlush: () => assert.fail('tidak boleh terpanggil') });

    sync.flush();

    assert.equal(sync.pending(), false);
});
