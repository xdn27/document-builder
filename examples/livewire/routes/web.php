<?php

use App\Http\Controllers\LetterTemplateBuilderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('letter-templates/{letterTemplate}/builder', LetterTemplateBuilderController::class)
        ->name('letter-templates.builder');
});
