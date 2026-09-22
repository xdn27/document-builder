<?php

namespace Maqiis\DocumentBuilder\Tests\Media;

use Maqiis\DocumentBuilder\Media\ImageDimensions;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use PHPUnit\Framework\TestCase;

class ImageDimensionsTest extends TestCase
{
    public function test_ratio_is_null_for_an_empty_source(): void
    {
        $this->assertNull(ImageDimensions::ratio(''));
        $this->assertNull(ImageDimensions::ratio('   '));
    }

    public function test_ratio_is_null_when_the_data_uri_cannot_be_decoded(): void
    {
        $this->assertNull(ImageDimensions::ratio('data:image/png;base64,'.base64_encode('bukan gambar')));
    }

    public function test_ratio_reads_a_png_data_uri(): void
    {
        $this->assertEqualsWithDelta(5.0, ImageDimensions::ratio($this->pngDataUri(400, 80)), 0.001);
    }

    public function test_ratio_reads_svg_dimensions_from_width_and_height_attributes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="60"></svg>';

        $this->assertEqualsWithDelta(5.0, ImageDimensions::ratio($this->svgDataUri($svg)), 0.001);
    }

    public function test_ratio_reads_svg_dimensions_from_view_box_when_width_and_height_are_missing(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 42"></svg>';

        $this->assertEqualsWithDelta(5.0, ImageDimensions::ratio($this->svgDataUri($svg)), 0.001);
    }

    public function test_ratio_is_null_for_svg_without_readable_dimensions(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';

        $this->assertNull(ImageDimensions::ratio($this->svgDataUri($svg)));
    }

    public function test_ratio_reads_via_the_resolver_instead_of_fetching_the_raw_source(): void
    {
        // "https://tidak-terjangkau.invalid/..." tidak pernah benar-benar
        // diakses: resolver menggantinya jadi path lokal sebelum getimagesize()
        // dipanggil — inilah yang membuat kop gambar tetap terbaca walau URL
        // publiknya sendiri tidak bisa dijangkau balik oleh proses PHP (mis.
        // dari dalam container).
        $path = $this->pngFile(400, 80);

        $resolver = new class($path) implements ImageResolver
        {
            public function __construct(private readonly string $path) {}

            public function resolve(string $src): string
            {
                return $this->path;
            }
        };

        $this->assertEqualsWithDelta(
            5.0,
            ImageDimensions::ratio('https://tidak-terjangkau.invalid/kop.png', $resolver),
            0.001,
        );

        unlink($path);
    }

    public function test_ratio_ignores_the_resolver_for_data_uri_sources(): void
    {
        $resolver = new class implements ImageResolver
        {
            public function resolve(string $src): string
            {
                throw new \RuntimeException('resolver tidak seharusnya dipanggil untuk data URI');
            }
        };

        $this->assertEqualsWithDelta(5.0, ImageDimensions::ratio($this->pngDataUri(400, 80), $resolver), 0.001);
    }

    private function pngFile(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $path = tempnam(sys_get_temp_dir(), 'db-image-dimensions-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function pngDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function svgDataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
