import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupKindFor, containerNeedsHeader } from '../../resources/js/fragments.mjs';

test('blok tabel dikenali sebagai grup tabel', () => {
    assert.equal(groupKindFor(['doc-block', 'db-table'], 'auto'), 'table');
});

test('blok daftar dikenali sebagai grup daftar', () => {
    assert.equal(groupKindFor(['doc-block', 'db-list'], 'auto'), 'list');
});

test('blok bertanda avoid tidak pernah dipecah meski bertipe tabel', () => {
    assert.equal(groupKindFor(['doc-block', 'db-table'], 'avoid'), null);
});

test('blok lain tidak dipecah', () => {
    assert.equal(groupKindFor(['doc-block', 'db-paragraph'], 'auto'), null);
});

test('header tabel diulang pada wadah lanjutan saat repeatHeader menyala', () => {
    assert.equal(containerNeedsHeader({ repeatHeader: true }, false), true);
});

test('header tabel hanya di wadah pertama saat repeatHeader mati', () => {
    assert.equal(containerNeedsHeader({ repeatHeader: false }, false), false);
    assert.equal(containerNeedsHeader({ repeatHeader: false }, true), true);
});

test('grup bukan tabel tidak pernah butuh header', () => {
    assert.equal(containerNeedsHeader({ repeatHeader: undefined }, false), false);
});
