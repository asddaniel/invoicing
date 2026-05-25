<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceGeneratorController;
use App\Http\Controllers\Api\OdooWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
// Route::get('/generate-invoice', [InvoiceGeneratorController::class, 'test']);
Route::post('/generate-invoice', [OdooWebhookController::class, 'handle']);
Route::post('/generate-quotation', [OdooWebhookController::class, 'handle']);
Route::post('/generate-delivery', [OdooWebhookController::class, 'handle']);

Route::post('/linedelivery', [OdooWebhookController::class, 'handle']);
Route::post('/delivery-line', [OdooWebhookController::class, 'handle']);
Route::post('/deliveryline', [OdooWebhookController::class, 'handle']);
Route::post('/lineinvoice', [OdooWebhookController::class, 'handle']);
Route::post('/linequotation', [OdooWebhookController::class, 'handle']);
Route::post('/linecotation', [OdooWebhookController::class, 'handle']);



//Route::post('/generate-invoice', [InvoiceGeneratorController::class, 'generate']);
