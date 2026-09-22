<?php

namespace Maqiis\DocumentBuilder\Schema;

enum PageSize: string
{
    case A4 = 'A4';
    case F4 = 'F4';
    case Letter = 'Letter';
    case Legal = 'Legal';

    /** Dimensi potret dalam mm. F4 memakai ukuran folio yang lazim di Indonesia. */
    public function widthMm(): float
    {
        return match ($this) {
            self::A4 => 210.0,
            self::F4 => 215.0,
            self::Letter => 215.9,
            self::Legal => 215.9,
        };
    }

    public function heightMm(): float
    {
        return match ($this) {
            self::A4 => 297.0,
            self::F4 => 330.0,
            self::Letter => 279.4,
            self::Legal => 355.6,
        };
    }
}
