<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Maqiis\DocumentBuilder\Laravel\Concerns\IsTemplateRecord;
use Maqiis\DocumentBuilder\Laravel\Concerns\TemplateRecord;

class LetterTemplate extends Model implements TemplateRecord
{
    use IsTemplateRecord;

    protected $table = 'document_templates';

    protected $guarded = ['id'];

    protected $casts = ['schema' => 'array'];

    // Wajib ditulis sendiri: trait sengaja tidak punya default. Aplikasi tanpa
    // scoping cabang/tenant cukup memeriksa login — itu keputusan sadar.
    public function authorizeTemplateView(): void
    {
        abort_unless(auth()->check(), 403);
    }
}
