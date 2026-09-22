<?php

namespace Maqiis\DocumentBuilder\Schema;

enum BlockType: string
{
    case Letterhead = 'letterhead';
    case LetterheadImage = 'letterhead-image';
    case LetterMeta = 'letter-meta';
    case Paragraph = 'paragraph';
    case Signature = 'signature';
    case Table = 'table';
    case ListBlock = 'list';
    case Image = 'image';
    case QrCode = 'qrcode';
    case Spacer = 'spacer';
    case Divider = 'divider';

    public function label(?LabelTranslator $translator = null): string
    {
        $fallback = match ($this) {
            self::Letterhead => 'Kop Surat',
            self::LetterheadImage => 'Kop Gambar (Full Width)',
            self::LetterMeta => 'Meta Surat',
            self::Paragraph => 'Paragraf',
            self::Signature => 'Tanda Tangan',
            self::Table => 'Tabel',
            self::ListBlock => 'Daftar',
            self::Image => 'Gambar',
            self::QrCode => 'QR Code',
            self::Spacer => 'Jarak',
            self::Divider => 'Garis',
        };

        return $translator?->translate("block.{$this->value}", $fallback) ?? $fallback;
    }
}
