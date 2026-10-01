<?php

use App\Http\Controllers\EventosController;
use Illuminate\Support\Facades\Route;

// O health check fica em /up (bootstrap/app.php).
Route::get('/eventos', EventosController::class);
