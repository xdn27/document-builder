<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Illuminate\Contracts\Translation\Translator;
use Maqiis\DocumentBuilder\Schema\LabelTranslator;

/**
 * Menyambungkan seam LabelTranslator ke penerjemah Laravel. Terjemahan dibaca
 * dari namespace "document-builder", grup "labels":
 *
 *   lang/vendor/document-builder/en/labels.php
 *   return ['prop' => ['align' => 'Alignment'], 'block' => ['paragraph' => 'Paragraph'], ...];
 *
 * Package tidak mengirim terjemahan apa pun (spec §18) — tanpa berkas itu setiap
 * label tetap teks Indonesia yang sama persis dengan hari ini.
 */
final class TransLabelTranslator implements LabelTranslator
{
    public function __construct(private readonly Translator $translator) {}

    public function translate(string $key, string $fallback): string
    {
        $line = "document-builder::labels.{$key}";
        $translated = $this->translator->get($line);

        // trans() mengembalikan kuncinya sendiri saat terjemahan tidak ada, dan
        // array bila kuncinya menunjuk grup. Keduanya bukan label.
        return is_string($translated) && $translated !== $line ? $translated : $fallback;
    }
}
