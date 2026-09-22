<?php

namespace Maqiis\DocumentBuilder\Pdf;

use RuntimeException;
use Throwable;

final class PdfRenderingException extends RuntimeException
{
    public static function engineFailed(string $engine, Throwable $previous): self
    {
        return new self(
            sprintf('Engine PDF "%s" gagal membuat dokumen: %s', $engine, $previous->getMessage()),
            0,
            $previous,
        );
    }

    public static function engineUnavailable(string $engine, string $package): self
    {
        return new self(
            sprintf('Engine PDF "%s" tidak tersedia. Pasang paket %s terlebih dahulu.', $engine, $package),
        );
    }

    public static function fontUnsupported(string $engine, string $font, string $alternativeEngine): self
    {
        return new self(sprintf(
            'Font "%s" tidak didukung engine PDF "%s" (glyph bisa salah/rusak). Ganti template ke font lain, atau pakai engine "%s".',
            $font,
            $engine,
            $alternativeEngine,
        ));
    }
}
