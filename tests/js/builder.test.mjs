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

/**
 * Sunting inline fase 1: normalisasi HTML contenteditable dan mesin
 * dblclick → commit / Escape → batal. Node palsu di bawah hanya
 * mengimplementasikan permukaan DOM yang dipakai serializer.
 */
import { initInlineEditing, serializeInlineEdit } from '../../resources/js/builder.mjs';

function fakeText(value) {
    return { nodeType: 3, nodeValue: value };
}

function fakeTag(tag, children = []) {
    return { nodeType: 1, tagName: tag.toUpperCase(), childNodes: children };
}

function fakeEditable({ html = '', text = 'Halo', rich = false, blockId = 'b1', prop = 'text' } = {}) {
    const attrs = {};

    if (rich) {
        attrs['data-edit-rich'] = '1';
    }

    const el = {
        nodeType: 1,
        tagName: 'P',
        childNodes: [fakeText(text)],
        dataset: { editProp: prop },
        innerHTML: html || text,
        blurred: false,
        focused: false,
        closest: (selector) => (selector === '[data-block-id]' ? { dataset: { blockId } } : null),
        contains: (node) => node !== null && node !== undefined,
        setAttribute: (key, value) => { attrs[key] = value; },
        removeAttribute: (key) => { delete attrs[key]; },
        hasAttribute: (key) => key in attrs,
        focus: () => { el.focused = true; },
        blur: () => { el.blurred = true; },
        attrs,
    };

    return el;
}

function fakeCanvas() {
    const listeners = {};

    return {
        contains: (node) => node !== null && node !== undefined,
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        fire: (type, event) => { (listeners[type] || []).forEach((fn) => fn(event)); },
    };
}

test('serializeInlineEdit mempertahankan b/i/u dan mengubah blok menjadi br', () => {
    const root = fakeTag('p', [
        fakeText('Halo '),
        fakeTag('b', [fakeText('dunia')]),
        fakeTag('div', [fakeText('baris dua')]),
        fakeTag('span', [fakeText('polos')]),
        fakeTag('img'),
    ]);

    assert.equal(
        serializeInlineEdit(root, true),
        'Halo <b>dunia</b><br>baris dua<br>polos',
    );
});

test('serializeInlineEdit mengubah strong/em dan membuang nbsp', () => {
    const root = fakeTag('p', [
        fakeTag('strong', [fakeText('tebal')]),
        fakeText(' antara'),
    ]);

    assert.equal(serializeInlineEdit(root, true), '<b>tebal</b> antara');
});

test('serializeInlineEdit non-rich mengembalikan teks polos satu baris', () => {
    const root = fakeTag('p', [
        fakeText('Nama '),
        fakeTag('div', [fakeText('gelar')]),
    ]);

    assert.equal(serializeInlineEdit(root, false), 'Nama gelar');
});

test('dblclick memulai sunting, focusout commit sekali dengan target lengkap', () => {
    const canvas = fakeCanvas();
    const el = fakeEditable({ text: 'Halo' });
    const calls = { edit: [], editing: [] };

    initInlineEditing({
        document: {},
        canvas,
        onInlineEdit: (payload) => calls.edit.push(payload),
        onEditingChange: (editing) => calls.editing.push(editing),
    });

    canvas.fire('dblclick', { target: { closest: () => el }, preventDefault: () => {} });

    assert.equal(el.attrs.contenteditable, 'true');
    assert.deepEqual(calls.editing, [true]);

    el.childNodes = [fakeText('Halo dunia')];
    canvas.fire('focusout', { relatedTarget: null });

    assert.equal(calls.edit.length, 1);
    assert.equal(calls.edit[0].blockId, 'b1');
    assert.equal(calls.edit[0].prop, 'text');
    assert.equal(calls.edit[0].value, 'Halo dunia');
    assert.deepEqual(calls.editing, [true, false]);
});

test('focusout tanpa perubahan tidak memicu onInlineEdit', () => {
    const canvas = fakeCanvas();
    const el = fakeEditable({ text: 'Sama' });
    let edits = 0;

    initInlineEditing({ document: {}, canvas, onInlineEdit: () => { edits += 1; } });

    canvas.fire('dblclick', { target: { closest: () => el }, preventDefault: () => {} });
    canvas.fire('focusout', { relatedTarget: null });

    assert.equal(edits, 0);
});

test('Escape membatalkan sunting dan mengembalikan HTML awal', () => {
    const canvas = fakeCanvas();
    const el = fakeEditable({ html: '<b>Asli</b>', text: 'Asli' });
    const calls = { edit: 0, editing: [] };

    initInlineEditing({
        document: {},
        canvas,
        onInlineEdit: () => { calls.edit += 1; },
        onEditingChange: (editing) => calls.editing.push(editing),
    });

    canvas.fire('dblclick', { target: { closest: () => el }, preventDefault: () => {} });
    el.innerHTML = '<b>Berubah</b>';
    canvas.fire('keydown', { key: 'Escape', preventDefault: () => {} });

    assert.equal(el.innerHTML, '<b>Asli</b>');
    assert.equal(el.blurred, true);
    assert.equal(calls.edit, 0);
    assert.deepEqual(calls.editing, [true, false]);
});
