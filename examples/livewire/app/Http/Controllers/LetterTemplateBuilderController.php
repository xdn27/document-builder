<?php

namespace App\Http\Controllers;

use App\Models\LetterTemplate;

class LetterTemplateBuilderController extends Controller
{
    public function __invoke(LetterTemplate $letterTemplate)
    {
        $letterTemplate->authorizeTemplateView();

        return view('letter-templates.builder', ['template' => $letterTemplate]);
    }
}
