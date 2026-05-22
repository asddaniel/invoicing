<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceGeneratorController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/generate-invoice', [InvoiceGeneratorController::class, 'generate']);