<?php

namespace Maqiis\DocumentBuilder\Pdf;

use Maqiis\DocumentBuilder\Render\RenderedDocument;

interface PdfEngine
{
    public function name(): string;

    /**
     * @return string bytes PDF
     *
     * @throws PdfRenderingException
     */
    public function render(RenderedDocument $document): string;
}
