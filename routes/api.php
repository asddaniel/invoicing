<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceGeneratorController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
Route::get('/generate-invoice', [InvoiceGeneratorController::class, 'test']);
Route::post('/generate-invoice', [InvoiceGeneratorController::class, 'test']);
//Route::post('/generate-invoice', [InvoiceGeneratorController::class, 'generate']);
