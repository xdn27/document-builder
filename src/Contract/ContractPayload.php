<?php

namespace Maqiis\DocumentBuilder\Contract;

use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\LabelTranslator;
use Maqiis\DocumentBuilder\Schema\PropCatalog;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;

/**
 * Semua yang dibutuhkan sebuah UI penyusun untuk membangun palette dan
 * inspector, dalam satu array siap json_encode(). Package sengaja TIDAK
 * menyediakan route atau controller (spec §7): kelas ini yang membuat controller
 * milik aplikasi benar-benar tinggal beberapa baris.
 *
 * 'contract' adalah versi BENTUK payload, bukan versi paket dan bukan versi
 * schema. Konsumen wajib memeriksanya dan gagal keras saat tidak cocok, alih-alih
 * merender inspector separuh jadi.
 */
final class ContractPayload
{
    public const VERSION = 1;

    private readonly PropCatalog $catalog;

    public function __construct(private readonly ?LabelTranslator $translator = null)
    {
        $this->catalog = new PropCatalog($this->translator);
    }

    /** @return array<string,mixed> */
    public function forRegistry(VariableRegistry $variables): array
    {
        $blockTypes = [];
        $propSchema = [];

        foreach (BlockType::cases() as $type) {
            $blockTypes[] = ['value' => $type->value, 'label' => $type->label($this->translator)];
            $propSchema[$type->value] = $this->catalog->describe($type);
        }

        return [
            'contract' => self::VERSION,
            'blockTypes' => $blockTypes,
            'blockPropSchema' => $propSchema,
            'fonts' => FontRegistry::all(),
            'variables' => $variables->groups(),
        ];
    }
}
