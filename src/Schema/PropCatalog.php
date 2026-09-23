<?php

namespace Maqiis\DocumentBuilder\Schema;

/**
 * Pemilik tunggal metadata tampilan properti blok: label field, sektor
 * accordion, dan label nilai enum. Dulu berupa konstanta privat di
 * BlockPropSchema; dipindahkan ke kelas yang bisa diinstansiasi supaya sebuah
 * LabelTranslator bisa disuntikkan tanpa global state, dan supaya describe()
 * (lihat Task 2) punya tempat yang wajar.
 *
 * BlockPropSchema tetap pemilik DEFINISI properti (dipakai validator dan
 * renderer); kelas ini hanya soal bagaimana properti itu ditampilkan.
 */
final class PropCatalog
{
    /** Label ramah tiap properti untuk ditampilkan di panel penyunting. */
    private const LABELS = [
        'showLogo' => 'Tampilkan logo', 'logo' => 'Sumber logo', 'logoHeightMm' => 'Tinggi logo (mm)',
        'line1' => 'Baris 1', 'line2' => 'Baris 2', 'line3' => 'Baris 3', 'line4' => 'Baris 4',
        'align' => 'Perataan', 'rule' => 'Garis bawah', 'rows' => 'Baris', 'labelWidthMm' => 'Lebar label (mm)',
        'separator' => 'Pemisah', 'rightText' => 'Teks kanan (mis. tempat, tanggal)', 'rightAlign' => 'Perataan teks kanan',
        'text' => 'Teks', 'indentMm' => 'Indentasi baris pertama (mm)', 'direction' => 'Arah teks',
        'spaceBeforeMm' => 'Jarak atas (mm)', 'spaceAfterMm' => 'Jarak bawah (mm)', 'columns' => 'Kolom',
        'spaceMm' => 'Ruang tanda tangan (mm)', 'textAlign' => 'Perataan teks', 'repeatHeader' => 'Ulangi header di tiap halaman',
        'headerBold' => 'Header tebal', 'border' => 'Garis tabel', 'fontSizePt' => 'Ukuran huruf (pt, 0 = ikut dokumen)',
        'items' => 'Butir', 'style' => 'Gaya', 'src' => 'Sumber gambar', 'alt' => 'Teks alternatif',
        'widthMm' => 'Lebar (mm)', 'payload' => 'Isi QR', 'sizeMm' => 'Ukuran (mm)', 'heightMm' => 'Tinggi (mm)',
        'thicknessMm' => 'Tebal (mm)', 'widthPercent' => 'Lebar (%)',
        'marginTopMm' => 'Margin atas (mm)', 'marginRightMm' => 'Margin kanan (mm)',
        'marginBottomMm' => 'Margin bawah (mm)', 'marginLeftMm' => 'Margin kiri (mm)',
        'showHeader' => 'Tampilkan header',
        'imageScalePercent' => 'Skala gambar tanda tangan (%)',
        'imageOffsetYMm' => 'Geser vertikal gambar (mm)',
        'imageOffsetXMm' => 'Geser horizontal gambar (mm)',
    ];

    /**
     * Grup context tiap properti untuk sektor collapsible di panel penyunting
     * (mis. accordion), meniru BlockType::label() — metadata tampilan properti
     * berada di sini, sejajar dengan metadata tampilan tipe blok.
     */
    private const GROUPS = [
        'logo' => 'Konten', 'line1' => 'Konten', 'line2' => 'Konten', 'line3' => 'Konten', 'line4' => 'Konten',
        'rows' => 'Konten', 'separator' => 'Konten', 'rightText' => 'Konten', 'text' => 'Konten', 'columns' => 'Konten',
        'items' => 'Konten', 'src' => 'Konten', 'alt' => 'Konten', 'payload' => 'Konten',
        'align' => 'Tata Letak', 'rightAlign' => 'Tata Letak', 'direction' => 'Tata Letak', 'textAlign' => 'Tata Letak',
        'logoHeightMm' => 'Ukuran & Jarak', 'labelWidthMm' => 'Ukuran & Jarak', 'indentMm' => 'Ukuran & Jarak',
        'spaceBeforeMm' => 'Ukuran & Jarak', 'spaceAfterMm' => 'Ukuran & Jarak', 'spaceMm' => 'Ukuran & Jarak',
        'fontSizePt' => 'Ukuran & Jarak', 'widthMm' => 'Ukuran & Jarak', 'sizeMm' => 'Ukuran & Jarak',
        'heightMm' => 'Ukuran & Jarak', 'thicknessMm' => 'Ukuran & Jarak', 'widthPercent' => 'Ukuran & Jarak',
        'marginTopMm' => 'Ukuran & Jarak', 'marginRightMm' => 'Ukuran & Jarak',
        'marginBottomMm' => 'Ukuran & Jarak', 'marginLeftMm' => 'Ukuran & Jarak',
        'imageScalePercent' => 'Ukuran & Jarak', 'imageOffsetYMm' => 'Ukuran & Jarak', 'imageOffsetXMm' => 'Ukuran & Jarak',
        'showLogo' => 'Tampilan', 'showHeader' => 'Tampilan', 'rule' => 'Tampilan', 'repeatHeader' => 'Tampilan', 'headerBold' => 'Tampilan',
        'border' => 'Tampilan', 'style' => 'Tampilan',
    ];

    private const GROUP_ORDER = ['Konten', 'Tata Letak', 'Ukuran & Jarak', 'Tampilan'];

    /**
     * Label ramah tiap NILAI enum yang dipakai lintas properti (align, rule,
     * border, style, dst) — satu kamus karena beberapa nilai dipakai ulang di
     * beberapa properti berbeda (mis. 'none' di 'rule' maupun 'border').
     * Nilai yang tidak ada di sini ditampilkan apa adanya (lihat valueLabel()).
     */
    private const VALUE_LABELS = [
        '' => 'Ikut posisi blok',
        'left' => 'Kiri', 'center' => 'Tengah', 'right' => 'Kanan', 'justify' => 'Rata kanan-kiri',
        'space-between' => 'Menyebar',
        'ltr' => 'Kiri ke kanan (Latin)', 'rtl' => 'Kanan ke kiri (Arab)',
        'double' => 'Ganda', 'single' => 'Tunggal', 'none' => 'Tanpa',
        'all' => 'Semua sisi', 'horizontal' => 'Mendatar saja',
        'bullet' => 'Bullet', 'number' => 'Nomor',
        'solid' => 'Garis penuh', 'dashed' => 'Garis putus-putus', 'dotted' => 'Garis titik-titik',
    ];

    public function __construct(private readonly ?LabelTranslator $translator = null) {}

    /** Properti yang lupa dipetakan jatuh ke kuncinya sendiri, bukan hilang diam-diam. */
    public function label(string $key): string
    {
        return $this->translate("prop.{$key}", self::LABELS[$key] ?? $key);
    }

    public function valueLabel(string $value): string
    {
        return $this->translate("value.{$value}", self::VALUE_LABELS[$value] ?? $value);
    }

    public function group(string $key): string
    {
        $fallback = self::GROUPS[$key] ?? 'Lainnya';

        return $this->translate("group.{$fallback}", $fallback);
    }

    /**
     * Susun definisi properti ke dalam grup context sesuai GROUP_ORDER, dengan
     * urutan field di dalam tiap grup mengikuti urutan aslinya.
     *
     * Pengelompokan memakai nama grup BAWAAN (bukan hasil terjemahan) sebagai
     * kunci sementara, supaya urutan sektor tetap benar walau labelnya diganti
     * penerjemah; penamaan ulang dilakukan di akhir.
     *
     * @param  array<string,array<string,mixed>>  $definitions
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function grouped(array $definitions): array
    {
        $groups = [];

        foreach ($definitions as $key => $definition) {
            $groups[self::GROUPS[$key] ?? 'Lainnya'][$key] = $definition;
        }

        $ordered = [];

        foreach (self::GROUP_ORDER as $name) {
            if (isset($groups[$name])) {
                $ordered[$this->translate("group.{$name}", $name)] = $groups[$name];
                unset($groups[$name]);
            }
        }

        foreach ($groups as $name => $keys) {
            $ordered[$this->translate("group.{$name}", $name)] = $keys;
        }

        return $ordered;
    }

    /**
     * Definisi properti sebuah tipe blok, sudah tergrup, dengan metadata
     * tampilan menempel di tiap properti dan siap json_encode(). Ini bentuk
     * yang dikonsumsi Blade inspector maupun konsumen non-Blade, sehingga tidak
     * ada label yang perlu didefinisikan dua kali (lihat spec §7).
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function describe(BlockType $type): array
    {
        $described = [];

        foreach ($this->grouped(BlockPropSchema::for($type)) as $group => $definitions) {
            foreach ($definitions as $key => $definition) {
                $entry = $definition + ['label' => $this->label($key)];

                if (($definition['type'] ?? null) === 'enum') {
                    $values = $definition['values'] ?? [];
                    $entry['valueLabels'] = [];

                    foreach ($values as $value) {
                        $entry['valueLabels'][$value] = $this->valueLabel((string) $value);
                    }
                }

                $described[$group][$key] = $entry;
            }
        }

        return $described;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate($key, $fallback) ?? $fallback;
    }
}
