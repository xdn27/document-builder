<?php

namespace Maqiis\DocumentBuilder\Tests\Media;

use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use PHPUnit\Framework\TestCase;

class ImageSourcePolicyTest extends TestCase
{
    private function policy(): ImageSourcePolicy
    {
        return new ImageSourcePolicy(['https://cdn.sekolah.id/', '/var/www/storage/app/public/']);
    }

    public function test_allows_a_source_under_an_allowed_prefix(): void
    {
        $this->assertTrue($this->policy()->isAllowed('https://cdn.sekolah.id/logo.png'));
        $this->assertTrue($this->policy()->isAllowed('/var/www/storage/app/public/kop/logo.png'));
    }

    public function test_rejects_a_host_that_merely_starts_with_the_allowed_host(): void
    {
        // https://cdn.sekolah.id.penyerang.com/ tidak boleh lolos hanya karena
        // awalan stringnya cocok dengan https://cdn.sekolah.id
        $this->assertFalse($this->policy()->isAllowed('https://cdn.sekolah.id.penyerang.com/logo.png'));
    }

    public function test_rejects_the_cloud_metadata_endpoint(): void
    {
        $this->assertFalse($this->policy()->isAllowed('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_rejects_internal_hosts(): void
    {
        $this->assertFalse($this->policy()->isAllowed('http://localhost:8000/x.png'));
        $this->assertFalse($this->policy()->isAllowed('http://127.0.0.1/x.png'));
    }

    public function test_rejects_file_scheme(): void
    {
        $this->assertFalse($this->policy()->isAllowed('file:///etc/passwd'));
    }

    public function test_allows_base64_image_data_uri(): void
    {
        $this->assertTrue($this->policy()->isAllowed('data:image/png;base64,iVBORw0KGgo='));
    }

    public function test_rejects_non_image_data_uri(): void
    {
        $this->assertFalse($this->policy()->isAllowed('data:text/html;base64,PHNjcmlwdD4='));
    }

    public function test_rejects_data_uri_when_disabled(): void
    {
        $policy = new ImageSourcePolicy(['https://cdn.sekolah.id/'], allowDataUri: false);

        $this->assertFalse($policy->isAllowed('data:image/png;base64,iVBORw0KGgo='));
    }

    public function test_rejects_empty_source(): void
    {
        $this->assertFalse($this->policy()->isAllowed(''));
        $this->assertFalse($this->policy()->isAllowed('   '));
    }

    public function test_permissive_policy_allows_any_http_source(): void
    {
        // Dipakai hanya di test renderer, tidak pernah di jalur produksi.
        $this->assertTrue(ImageSourcePolicy::permissive()->isAllowed('https://contoh.test/x.png'));
    }

    public function test_rejects_path_traversal_out_of_an_allowed_prefix(): void
    {
        // Awalan cocok secara string, tetapi segmen ".." membawa jalurnya keluar dari awalan:
        // engine PDF lalu membaca berkas di luar disk yang diizinkan.
        foreach ([
            'https://cdn.sekolah.id/../../private/foto.jpg',
            'https://cdn.sekolah.id/kop/../../../etc/passwd',
            'https://cdn.sekolah.id/%2e%2e/%2e%2e/private/foto.jpg',
            'https://cdn.sekolah.id/%252e%252e/private/foto.jpg',
            'https://cdn.sekolah.id/..%2fprivate/foto.jpg',
            'https://cdn.sekolah.id/kop\\..\\..\\private\\foto.jpg',
            '/var/www/storage/app/public/../private/foto.jpg',
            '/var/www/storage/app/public/..',
        ] as $src) {
            $this->assertFalse($this->policy()->isAllowed($src), $src);
        }
    }

    public function test_dots_inside_a_file_or_folder_name_are_not_traversal(): void
    {
        $this->assertTrue($this->policy()->isAllowed('https://cdn.sekolah.id/v1.2/logo..final.png'));
        $this->assertTrue($this->policy()->isAllowed('https://cdn.sekolah.id/kop/...arsip/logo.png'));
    }

    public function test_the_permissive_policy_still_rejects_traversal(): void
    {
        $this->assertFalse(ImageSourcePolicy::permissive()->isAllowed('https://contoh.test/a/../../b.png'));
    }
}
