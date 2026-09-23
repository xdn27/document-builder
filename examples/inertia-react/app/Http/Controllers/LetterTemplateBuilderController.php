<?php

namespace App\Http\Controllers;

use App\Models\LetterTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maqiis\DocumentBuilder\Contract\ContractPayload;
use Maqiis\DocumentBuilder\Laravel\DocumentRenderer;
use Maqiis\DocumentBuilder\Schema\SchemaValidationException;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;

/**
 * Model LetterTemplate sama dengan contoh Livewire. Package tidak menyediakan
 * route atau controller — tiga method di bawah ini seluruh integrasinya.
 */
class LetterTemplateBuilderController extends Controller
{
    public function show(LetterTemplate $letterTemplate, ContractPayload $contract, VariableRegistry $variables): Response
    {
        $letterTemplate->authorizeTemplateView();

        return Inertia::render('LetterTemplates/Builder', [
            'template' => ['id' => $letterTemplate->id, 'name' => $letterTemplate->getTemplateName()],
            'schema' => $letterTemplate->getTemplateSchema() ?: Template::blank()->toArray(),
            'contract' => $contract->forRegistry($variables),
        ]);
    }

    /** Stateless: tidak menulis apa pun, aman diulang dan dibatalkan. */
    public function preview(Request $request, LetterTemplate $letterTemplate, DocumentRenderer $renderer): JsonResponse
    {
        $letterTemplate->authorizeTemplateView();
        $revision = (int) $request->input('revision', 0);

        try {
            // Jalur baca: tanpa batas ukuran, seperti membuka builder Livewire.
            $document = $renderer->render(Template::fromArray((array) $request->input('schema', [])));
        } catch (SchemaValidationException $e) {
            return response()->json(['errors' => $e->errors(), 'revision' => $revision], 422);
        }

        // revision dikembalikan apa adanya: klien membuang respons yang lebih tua.
        return response()->json(['html' => $document->flowHtml(), 'css' => $document->css(), 'revision' => $revision]);
    }

    public function save(Request $request, LetterTemplate $letterTemplate): JsonResponse
    {
        $letterTemplate->authorizeTemplateUpdate();

        try {
            // Jalur tulis: batas ukuran ditegakkan di sini, bukan di preview.
            $validated = Template::fromArray((array) $request->input('schema', []), SchemaValidator::MAX_BYTES);
        } catch (SchemaValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $letterTemplate->setTemplateSchema($validated->toArray());

        return response()->json(['schema' => $validated->toArray()]);
    }
}
