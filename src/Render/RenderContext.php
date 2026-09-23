<?php

namespace Maqiis\DocumentBuilder\Render;

use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Qr\NullQrCodeGenerator;
use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Maqiis\DocumentBuilder\Sanitize\HtmlSanitizer;
use Maqiis\DocumentBuilder\Schema\DocumentStyle;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;

final class RenderContext
{
    public function __construct(
        public readonly DocumentStyle $style,
        public readonly PageSetup $page,
        public readonly HtmlSanitizer $sanitizer,
        public readonly VariableSyntax $variables,
        public readonly ImageSourcePolicy $images,
        public readonly QrCodeGenerator $qr,
        // Opsional: dipakai ImageDimensions supaya dimensi gambar yang disimpan
        // sendiri oleh aplikasi (mis. lewat disk lokal) dibaca langsung dari
        // filesystem, bukan lewat fetch HTTP ke URL publiknya sendiri — fetch
        // semacam itu bisa gagal (mis. URL publik tidak bisa dijangkau balik
        // dari dalam container tempat PHP berjalan). Tanpa resolver, perilaku
        // sama seperti sebelumnya: dibaca apa adanya lewat getimagesize().
        public readonly ?ImageResolver $imageResolver = null,
        // Mode sunting inline di kanvas builder: renderer menandai region teks
        // dengan atribut data-edit-* supaya JS tahu teks mana milik prop apa.
        // Mati secara bawaan agar HTML cetak/PDF byte-identical dengan sebelumnya.
        public readonly bool $editable = false,
    ) {}

    /** Konteks siap pakai untuk test renderer. */
    public static function sample(?DocumentStyle $style = null, ?PageSetup $page = null): self
    {
        return new self(
            $style ?? DocumentStyle::fromArray([]),
            $page ?? PageSetup::fromArray([]),
            new HtmlSanitizer,
            new VariableSyntax(new ArrayVariableResolver([])),
            ImageSourcePolicy::permissive(),
            new NullQrCodeGenerator,
        );
    }

    public function withResolver(VariableResolver $resolver): self
    {
        return new self(
            $this->style,
            $this->page,
            $this->sanitizer,
            new VariableSyntax($resolver),
            $this->images,
            $this->qr,
            $this->imageResolver,
            $this->editable,
        );
    }

    public function withQr(QrCodeGenerator $qr): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $this->images, $qr, $this->imageResolver, $this->editable);
    }

    public function withImages(ImageSourcePolicy $images): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $images, $this->qr, $this->imageResolver, $this->editable);
    }

    public function withImageResolver(?ImageResolver $imageResolver): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $this->images, $this->qr, $imageResolver, $this->editable);
    }

    public function withEditable(bool $editable = true): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $this->images, $this->qr, $this->imageResolver, $editable);
    }

    /** Teks yang boleh mengandung <b> <i> <u> <br>. */
    public function rich(string $raw): string
    {
        return $this->variables->apply($this->sanitizer->sanitize($raw));
    }

    /** Teks yang tidak boleh mengandung markup sama sekali. */
    public function plain(string $raw): string
    {
        return $this->variables->apply(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public function escape(string $raw): string
    {
        return htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Atribut penanda region teks yang bisa disunting inline di kanvas builder:
     * prop pemilik, indeks baris/kolom untuk struktur tabel/daftar, kunci untuk
     * properti bertipe rows, dan penanda rich (teks boleh mengandung <b><i><u><br>).
     * String kosong bila konteks tidak editable — HTML cetak/PDF tidak tersentuh.
     *
     * $raw adalah nilai schema region itu. Region yang memuat variabel sengaja
     * tidak ditandai: kanvas menampilkan nilai contoh hasil resolver, sehingga
     * commit inline akan menimpa token {{...}} dengan nilai contohnya. Region
     * semacam itu tetap disunting lewat inspektor.
     */
    public function editAttr(string $prop, string $raw, ?int $row = null, ?int $col = null, ?string $key = null, bool $rich = false): string
    {
        if (! $this->editable || preg_match(VariableSyntax::PATTERN, $raw) === 1) {
            return '';
        }

        $attr = sprintf(' data-edit-prop="%s"', $this->escape($prop));

        if ($row !== null) {
            $attr .= sprintf(' data-edit-row="%d"', $row);
        }

        if ($col !== null) {
            $attr .= sprintf(' data-edit-col="%d"', $col);
        }

        if ($key !== null) {
            $attr .= sprintf(' data-edit-key="%s"', $this->escape($key));
        }

        if ($rich) {
            $attr .= ' data-edit-rich="1"';
        }

        return $attr;
    }

    /** Kotak penanda untuk keadaan yang tidak bisa dirender, tanpa melempar exception. */
    public function marker(string $message): string
    {
        return '<div class="db-marker">'.$this->escape($message).'</div>';
    }
}
