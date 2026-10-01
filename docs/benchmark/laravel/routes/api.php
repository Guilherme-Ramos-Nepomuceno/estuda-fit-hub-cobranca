<?php

use App\Http\Controllers\CobrancaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true]);
Route::get('/alunos/{id}/situacao', [CobrancaController::class, 'situacao'])->whereNumber('id');
Route::post('/webhooks/pagamento', [CobrancaController::class, 'webhook']);
