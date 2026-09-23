/**
 * Bentuk payload yang dihasilkan Maqiis\DocumentBuilder\Contract\ContractPayload.
 * Berkas ini ditulis tangan dan harus diperbarui bersama ContractPayload; yang
 * menjaga keduanya tetap sinkron adalah ContractPayloadTest, yang memaku kunci
 * tingkat atas dan bentuk per properti.
 */

export type BlockTypeValue =
    | 'letterhead'
    | 'letterhead-image'
    | 'letter-meta'
    | 'paragraph'
    | 'signature'
    | 'table'
    | 'list'
    | 'image'
    | 'qrcode'
    | 'spacer'
    | 'divider'
    | 'recipient';

export type PropType = 'string' | 'float' | 'bool' | 'enum' | 'image' | 'rows' | 'matrix';

export interface PropDefinition {
    type: PropType;
    default: string | number | boolean | unknown[];
    label: string;
    min?: number;
    max?: number;
    /** Hanya untuk type 'rows': bentuk satu baris beserta nilai awal tiap selnya. */
    keys?: Record<string, string | number>;
    /** Hanya untuk type 'enum'. */
    values?: string[];
    /** Hanya untuk type 'enum': nilai → label tampilan. */
    valueLabels?: Record<string, string>;
}

/** Nama grup (mis. "Konten") → properti di dalamnya. */
export type DescribedProps = Record<string, Record<string, PropDefinition>>;

export interface FontDefinition {
    label: string;
    css: string;
    mpdf: string;
}

export interface VariableDefinition {
    path: string;
    label: string;
    sample: string;
    group: string;
}

export interface BuilderContract {
    /** Versi BENTUK payload. Gagal keras kalau bukan 1. */
    contract: number;
    blockTypes: Array<{ value: BlockTypeValue; label: string }>;
    blockPropSchema: Record<BlockTypeValue, DescribedProps>;
    fonts: Record<string, FontDefinition>;
    /** Nama grup variabel → daftar variabelnya. */
    variables: Record<string, VariableDefinition[]>;
}

/** Balasan endpoint preview milik aplikasi konsumen (spec §9). */
export interface PreviewResponse {
    html: string;
    css: string;
    /** Dikembalikan apa adanya dari request; buang respons yang lebih tua (spec §9.1). */
    revision: number;
}
