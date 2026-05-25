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
Route::post('/generate-quotation', [InvoiceGeneratorController::class, 'test']);
Route::post('/generate-delivery', [InvoiceGeneratorController::class, 'test']);
Route::post('/linedelivery', [InvoiceGeneratorController::class, 'test']);
Route::post('/delivery-line', [InvoiceGeneratorController::class, 'test']);
Route::post('/deliveryline', [InvoiceGeneratorController::class, 'test']);
Route::post('/lineinvoice', [InvoiceGeneratorController::class, 'test']);
Route::post('/linequotation', [InvoiceGeneratorController::class, 'test']);
Route::post('/linecotation', [InvoiceGeneratorController::class, 'test']);



//Route::post('/generate-invoice', [InvoiceGeneratorController::class, 'generate']);
