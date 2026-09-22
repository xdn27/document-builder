<?php

namespace Maqiis\DocumentBuilder\Schema;

final class Template
{
    public const CURRENT_VERSION = 1;

    public function __construct(
        public readonly int $version,
        public readonly PageSetup $page,
        public readonly DocumentStyle $style,
        public readonly Zone $header,
        public readonly Zone $body,
        public readonly Zone $footer,
    ) {}

    /**
     * Jalur BACA — dipakai mount()/preview() builder dan DocumentTemplate::
     * template() (yang menopang cetak & PDF). Tidak menegakkan batas ukuran
     * schema secara bawaan: sekali sebuah template tersimpan melebihi batas,
     * jalur baca yang menolaknya berarti template itu sama sekali tidak bisa
     * dibuka, dicetak, atau diunduh PDF-nya lagi — dan tidak ada cara
     * memperbaikinya dari UI karena UI-nya sendiri yang menolak terbuka.
     *
     * Pemanggil yang MENERIMA tulisan baru (TemplateBuilder::save()) memberi
     * $maxBytes eksplisit — biasanya SchemaValidator::MAX_BYTES — supaya
     * batas tetap ditegakkan tepat di titik itu.
     *
     * @throws SchemaValidationException
     */
    public static function fromArray(array $raw, ?int $maxBytes = null): self
    {
        return SchemaValidator::validate(SchemaMigrator::upgrade($raw), $maxBytes);
    }

    /** Template kosong siap pakai — dipakai saat membuat template baru. */
    public static function blank(): self
    {
        return self::fromArray([
            'version' => self::CURRENT_VERSION,
            'page' => [
                'size' => 'A4',
                'orientation' => 'portrait',
                'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 25],
            ],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
                'body' => ['blocks' => []],
                'footer' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
            ],
        ]);
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'page' => $this->page->toArray(),
            'style' => $this->style->toArray(),
            'zones' => [
                'header' => $this->header->toArray(),
                'body' => $this->body->toArray(),
                'footer' => $this->footer->toArray(),
            ],
        ];
    }
}
