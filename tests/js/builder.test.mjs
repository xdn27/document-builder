import { test } from 'node:test';
import assert from 'node:assert/strict';

import { initBuilder } from '../../resources/js/builder.mjs';

/**
 * Yang diuji di sini hanya disiplin transport: apakah handle applyPreview()
 * memasang HTML/CSS yang diberikan, dan apakah payload dengan revision lebih
 * tua dibuang (spec §9.1). Paginasi sungguhan tidak dijalankan — kanvas palsu
 * di bawah tidak punya .doc-flow, jadi paginateDocument() langsung mengembalikan
 * null tanpa mengukur apa pun.
 */
function fakeElement(extra = {}) {
    return {
        innerHTML: '',
        textContent: '',
        classList: { toggle() {}, add() {}, remove() {} },
        addEventListener() {},
        querySelectorAll: () => [],
        querySelector: () => null,
        contains: () => false,
        ...extra,
    };
}

function fakeDocument({ canvas, style }) {
    return {
        querySelector: (selector) => (selector === '#db-canvas' ? canvas : selector === '#db-document-css' ? style : null),
        addEventListener() {},
        fonts: null,
    };
}

test('applyPreview memasang html dan css yang diberikan', async () => {
    const canvas = fakeElement();
    const style = fakeElement();
    const handle = initBuilder({ document: fakeDocument({ canvas, style }) });

    await handle.applyPreview({ html: '<div class="doc-root"></div>', css: 'body{margin:0}', revision: 1 });

    assert.equal(canvas.innerHTML, '<div class="doc-root"></div>');
    assert.equal(style.textContent, 'body{margin:0}');
    assert.equal(handle.currentRevision(), 1);
});

test('payload dengan revision lebih tua dibuang, kanvas tidak ikut mundur', async () => {
    const canvas = fakeElement();
    const style = fakeElement();
    const handle = initBuilder({ document: fakeDocument({ canvas, style }) });

    await handle.applyPreview({ html: 'baru', css: '', revision: 5 });
    await handle.applyPreview({ html: 'kedaluwarsa', css: '', revision: 3 });

    assert.equal(canvas.innerHTML, 'baru', 'respons lama menimpa kanvas — inilah bug yang revision cegah');
    assert.equal(handle.currentRevision(), 5);
});

test('revision yang sama diterapkan ulang, supaya pengiriman ulang tidak hilang', async () => {
    const canvas = fakeElement();
    const handle = initBuilder({ document: fakeDocument({ canvas, style: fakeElement() }) });

    await handle.applyPreview({ html: 'a', css: '', revision: 2 });
    await handle.applyPreview({ html: 'b', css: '', revision: 2 });

    assert.equal(canvas.innerHTML, 'b');
});

test('payload tanpa revision tetap diterapkan, untuk konsumen yang belum memakainya', async () => {
    const canvas = fakeElement();
    const handle = initBuilder({ document: fakeDocument({ canvas, style: fakeElement() }) });

    await handle.applyPreview({ html: 'tanpa revisi', css: '' });

    assert.equal(canvas.innerHTML, 'tanpa revisi');
});

test('initBuilder tidak meledak saat kanvas tidak ada di halaman', () => {
    const handle = initBuilder({ document: fakeDocument({ canvas: null, style: null }) });

    assert.equal(typeof handle.applyPreview, 'function');
});
