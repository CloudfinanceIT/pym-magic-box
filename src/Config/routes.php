<?php

use Illuminate\Support\Facades\Route;
use Mantonio84\pymMagicBox\Controllers\pymListenController;

Route::match(['get', 'post'], '/pym-magic-box/{merchant}/{method}/{action}', [pymListenController::class, 'merchantAction'])
    ->name('pym-magic-box.merchant-action');
Route::post('/pym-wb/{merchant}/{method}', [pymListenController::class, 'webhook'])
    ->name('pym-magic-box.webhook');
