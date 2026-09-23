<?php

namespace Maqiis\DocumentBuilder\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * tools/preview.php adalah separuh PHP dari verify-print. Mode stdin/stdout
 * membuatnya bisa dijalankan dari mana pun tanpa berbagi direktori dengan
 * pemanggil; mode dua-argumen lama tetap ada untuk pemakaian manual.
 */
class PreviewScriptTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../tools/preview.php';

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/surat-satu-halaman.json');
    }

    /** @return array{0:int,1:string,2:string} kode keluar, stdout, stderr */
    private function runPreview(array $args, string $stdin = ''): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::SCRIPT], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function test_stdin_mode_writes_a_full_html_document_to_stdout(): void
    {
        [$code, $out, $err] = $this->runPreview(['-'], $this->fixture());

        $this->assertSame(0, $code, $err);
        $this->assertStringStartsWith('<!DOCTYPE html>', $out);
        $this->assertStringContainsString('doc-root', $out);
        $this->assertSame('', $err, 'stdout harus berisi HTML saja; pesan ke stderr');
    }

    public function test_a_schema_over_the_size_guard_still_previews(): void
    {
        // preview.php adalah jalur BACA — sama seperti membuka builder, batas
        // ukuran tidak berlaku di sini (lihat perbaikan ec3ed6193).
        $raw = json_decode($this->fixture(), true);
        $raw['zones']['body']['blocks'][] = ['id' => 'besar', 'type' => 'image', 'props' => [
            'src' => 'data:image/png;base64,'.str_repeat('A', 300_000), 'alt' => '', 'widthMm' => 50.0, 'align' => 'left',
        ]];

        [$code, $out, $err] = $this->runPreview(['-'], json_encode($raw));

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('doc-root', $out);
    }

    public function test_invalid_json_fails_with_a_message_on_stderr(): void
    {
        [$code, $out, $err] = $this->runPreview(['-'], '{bukan json');

        $this->assertNotSame(0, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('JSON', $err);
    }

    public function test_the_legacy_two_argument_mode_still_writes_a_file(): void
    {
        $target = sys_get_temp_dir().'/preview-script-test-'.uniqid().'.html';

        try {
            [$code] = $this->runPreview([__DIR__.'/../fixtures/surat-satu-halaman.json', $target]);

            $this->assertSame(0, $code);
            $this->assertStringStartsWith('<!DOCTYPE html>', (string) file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }
}
