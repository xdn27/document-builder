<?php

namespace Maqiis\DocumentBuilder\Laravel\Doctor;

/**
 * Satu hasil pemeriksaan document-builder:doctor.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class DoctorCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
    ) {}

    public static function ok(string $name, string $message): self
    {
        return new self($name, self::OK, $message);
    }

    public static function warn(string $name, string $message): self
    {
        return new self($name, self::WARN, $message);
    }

    public static function fail(string $name, string $message): self
    {
        return new self($name, self::FAIL, $message);
    }
}
