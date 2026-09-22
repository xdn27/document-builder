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
        );
    }

    public function withQr(QrCodeGenerator $qr): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $this->images, $qr, $this->imageResolver);
    }

    public function withImages(ImageSourcePolicy $images): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $images, $this->qr, $this->imageResolver);
    }

    public function withImageResolver(?ImageResolver $imageResolver): self
    {
        return new self($this->style, $this->page, $this->sanitizer, $this->variables, $this->images, $this->qr, $imageResolver);
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

    /** Kotak penanda untuk keadaan yang tidak bisa dirender, tanpa melempar exception. */
    public function marker(string $message): string
    {
        return '<div class="db-marker">'.$this->escape($message).'</div>';
    }
}
