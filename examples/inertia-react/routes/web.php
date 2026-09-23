<?php

use App\Http\Controllers\LetterTemplateBuilderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')
    ->prefix('letter-templates/{letterTemplate}')
    ->name('letter-templates.')
    ->controller(LetterTemplateBuilderController::class)
    ->group(function () {
        Route::get('builder', 'show')->name('builder');
        Route::post('preview', 'preview')->name('preview');
        Route::put('schema', 'save')->name('save');
    });
