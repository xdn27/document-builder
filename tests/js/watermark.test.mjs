import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fitWatermarkSize, WATERMARK_MAX_PT } from '../../resources/js/paginate-dom.mjs';

// 1pt = 4/3 px di CSS; A4 potret = 793.7 × 1122.5 px.
const PX_PER_PT = 4 / 3;
const A4 = { pageWidth: 793.7, pageHeight: 1122.5 };

test('teks pendek tetap memakai ukuran maksimum', () => {
    assert.equal(fitWatermarkSize({ widthAtMax: 300, ...A4, pxPerPt: PX_PER_PT }), WATERMARK_MAX_PT);
});

test('teks panjang dikecilkan sampai muat di sisi pendek kertas, seperti mpdf', () => {
    const widthAtMax = 1600;
    const size = fitWatermarkSize({ widthAtMax, ...A4, pxPerPt: PX_PER_PT });
    const offset = Math.sin(Math.PI / 4) * size * PX_PER_PT;

    assert.ok(size < WATERMARK_MAX_PT);
    assert.ok((widthAtMax * size) / WATERMARK_MAX_PT <= A4.pageWidth - offset, 'muat');

    const bigger = size + 1;
    const biggerOffset = Math.sin(Math.PI / 4) * bigger * PX_PER_PT;
    assert.ok((widthAtMax * bigger) / WATERMARK_MAX_PT > A4.pageWidth - biggerOffset, 'satu poin lebih besar sudah tidak muat');
});

test('lanskap memakai tinggi kertas sebagai sisi pendek', () => {
    const portrait = fitWatermarkSize({ widthAtMax: 1600, ...A4, pxPerPt: PX_PER_PT });
    const landscape = fitWatermarkSize({
        widthAtMax: 1600, pageWidth: A4.pageHeight, pageHeight: A4.pageWidth, pxPerPt: PX_PER_PT,
    });

    assert.equal(landscape, portrait);
});

test('ukuran tidak pernah di bawah satu poin', () => {
    assert.equal(fitWatermarkSize({ widthAtMax: 1e9, ...A4, pxPerPt: PX_PER_PT }), 1);
});

test('lebar nol (belum terukur) tidak mengecilkan apa pun', () => {
    assert.equal(fitWatermarkSize({ widthAtMax: 0, ...A4, pxPerPt: PX_PER_PT }), WATERMARK_MAX_PT);
});
