import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

/**
 * Contoh React harus lolos parser JSX dan setiap impor dari alias
 * '@document-builder/…' harus menunjuk modul serta ekspor yang benar-benar ada
 * di resources/js — rename di paket tidak boleh diam-diam mematikan contoh.
 */
const pkg = new URL('../../', import.meta.url);
const page = new URL('examples/inertia-react/resources/js/Pages/LetterTemplates/Builder.jsx', pkg);

let esbuild = null;

try {
    esbuild = await import('esbuild');
} catch {
    // esbuild hanya ada di repo monorepo; di luar itu test parser dilewati.
}

test('contoh React lolos parser JSX', { skip: esbuild ? false : 'esbuild tidak tersedia' }, () => {
    esbuild.transformSync(readFileSync(page, 'utf8'), { loader: 'jsx' });
});

test('setiap impor @document-builder di contoh menunjuk ekspor yang ada', async () => {
    const source = readFileSync(page, 'utf8');
    const imports = [...source.matchAll(/import\s*\{([^}]+)\}\s*from\s*'@document-builder\/([^']+)'/g)];

    assert.ok(imports.length > 0, 'contoh tidak mengimpor apa pun dari paket');

    for (const [, names, file] of imports) {
        const module = new URL(`resources/js/${file}`, pkg);
        assert.ok(existsSync(module), `${file} tidak ada di resources/js`);

        const exported = await import(module.href);

        for (const name of names.split(',').map((n) => n.trim()).filter(Boolean)) {
            assert.equal(typeof exported[name], 'function', `${file} tidak mengekspor ${name}`);
        }
    }
});

test('alias Vite di contoh menunjuk resources/js paket di vendor', () => {
    const config = readFileSync(new URL('examples/inertia-react/vite.config.js', pkg), 'utf8');

    assert.match(config, /'@document-builder'/);
    assert.match(config, /vendor\/maqiis\/document-builder\/resources\/js/);
});
