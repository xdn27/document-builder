<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
interface BlockRenderer
{
    public function type(): BlockType;

    /** Kembalikan isi blok saja; pembungkus .doc-block ditambahkan registry. */
    public function render(Block $block, RenderContext $context): string;
}
