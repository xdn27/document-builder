<?php

namespace Maqiis\DocumentBuilder\Sanitize;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Whitelist berbasis DOMDocument, bukan regex. Regex atas HTML selalu kalah pada
 * kasus tepi, dan di sini keluarannya masuk ke dokumen resmi yang dicetak.
 */
final class HtmlSanitizer
{
    private const ALLOWED = ['b', 'i', 'u', 'br'];

    private const VOID = ['br'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="db-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('db-root');

        return $root === null ? '' : $this->renderChildren($root);
    }

    private function renderChildren(DOMNode $node): string
    {
        $output = '';

        foreach (iterator_to_array($node->childNodes) as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            // Komentar, instruksi pemrosesan, dan CDATA dibuang seluruhnya.
            return '';
        }

        $tag = strtolower($node->tagName);

        // Isi <script> dan <style> adalah kode, bukan teks — buang berikut isinya.
        if ($tag === 'script' || $tag === 'style') {
            return '';
        }

        if (! in_array($tag, self::ALLOWED, true)) {
            return $this->renderChildren($node);
        }

        if (in_array($tag, self::VOID, true)) {
            return "<{$tag} />";
        }

        return "<{$tag}>".$this->renderChildren($node)."</{$tag}>";
    }
}
