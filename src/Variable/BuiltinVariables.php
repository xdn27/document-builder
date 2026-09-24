<?php

namespace Maqiis\DocumentBuilder\Variable;

use DateTimeInterface;

/**
 * Variabel yang tersedia di setiap aplikasi tanpa perlu didaftarkan: tanggal
 * hari ini (nama hari dan bulan berbahasa Indonesia) dan nomor halaman.
 *
 * Aplikasi selalu menang. register() hanya mengisi path yang belum
 * didefinisikan, dan resolver() hanya dipakai saat resolver aplikasi
 * mengembalikan null — jadi katalog yang sudah punya `today.long` sendiri
 * tidak berubah perilakunya.
 *
 * Waktu diterima dari luar, bukan dibaca sendiri: zona waktu adalah milik
 * aplikasi, dan test butuh tanggal yang tetap.
 */
final class BuiltinVariables
{
    public const DATE_GROUP = 'Tanggal';

    public const PAGE_GROUP = 'Halaman';

    private const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    private const ROMAN_MONTHS = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /** Label panel untuk setiap variabel tanggal, dalam urutan tampil. */
    private const DATE_LABELS = [
        'today.long' => 'Tanggal Hari Ini (panjang)',
        'today.short' => 'Tanggal Hari Ini (pendek)',
        'today.full' => 'Hari & Tanggal Hari Ini',
        'today.day' => 'Nama Hari',
        'today.date' => 'Tanggal (angka)',
        'today.month' => 'Nama Bulan',
        'today.month_roman' => 'Bulan (angka Romawi)',
        'today.year' => 'Tahun Berjalan',
    ];

    /**
     * Diisi paginator, bukan resolver (lihat VariableSyntax::RESERVED). Didaftarkan
     * hanya supaya bisa disisipkan dari panel; contohnya tidak pernah dibaca.
     */
    private const PAGE_LABELS = [
        'page' => ['Nomor Halaman', '1'],
        'pages' => ['Jumlah Halaman', '1'],
    ];

    public function __construct(private readonly DateTimeInterface $now) {}

    /** @return list<string> semua path bawaan, dalam urutan tampil di panel */
    public static function paths(): array
    {
        return [...array_keys(self::DATE_LABELS), ...array_keys(self::PAGE_LABELS)];
    }

    /** @return array<string,string> nilai variabel tanggal untuk waktu yang diberikan */
    public function values(): array
    {
        $day = (int) $this->now->format('j');
        $month = (int) $this->now->format('n');
        $year = $this->now->format('Y');
        $dayName = self::DAYS[(int) $this->now->format('w')];
        $long = sprintf('%d %s %s', $day, self::MONTHS[$month], $year);

        return [
            'today.long' => $long,
            'today.short' => $this->now->format('d/m/Y'),
            'today.full' => $dayName.', '.$long,
            'today.day' => $dayName,
            'today.date' => (string) $day,
            'today.month' => self::MONTHS[$month],
            'today.month_roman' => self::ROMAN_MONTHS[$month],
            'today.year' => $year,
        ];
    }

    /** Menambahkan variabel bawaan yang belum didefinisikan aplikasi. */
    public function register(VariableRegistry $registry): VariableRegistry
    {
        foreach ($this->values() as $path => $value) {
            if (! $registry->has($path)) {
                $registry->define($path, self::DATE_LABELS[$path], $value, self::DATE_GROUP);
            }
        }

        foreach (self::PAGE_LABELS as $path => [$label, $sample]) {
            if (! $registry->has($path)) {
                $registry->define($path, $label, $sample, self::PAGE_GROUP);
            }
        }

        return $registry;
    }

    /** Membungkus resolver aplikasi: nilainya didahulukan, variabel bawaan mengisi yang kosong. */
    public function resolver(VariableResolver $inner): CollectionVariableResolver
    {
        return new class($inner, $this->values()) implements CollectionVariableResolver
        {
            /** @param array<string,string> $values */
            public function __construct(private readonly VariableResolver $inner, private readonly array $values) {}

            public function resolve(string $path): ?string
            {
                return $this->inner->resolve($path) ?? $this->values[$path] ?? null;
            }

            public function collection(string $name): ?array
            {
                return $this->inner instanceof CollectionVariableResolver
                    ? $this->inner->collection($name)
                    : null;
            }
        };
    }
}
