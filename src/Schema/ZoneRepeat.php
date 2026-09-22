<?php

namespace Maqiis\DocumentBuilder\Schema;

enum ZoneRepeat: string
{
    case All = 'all';
    case FirstOnly = 'first-only';
    case ExceptFirst = 'except-first';

    /** Apakah zona ini tampil pada halaman ke-$page (berbasis 1). */
    public function appearsOn(int $page): bool
    {
        return match ($this) {
            self::All => true,
            self::FirstOnly => $page === 1,
            self::ExceptFirst => $page > 1,
        };
    }
}
