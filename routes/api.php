<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\ApiController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
// Endpoint para obtener consultas pendientes (existente) se consume desde el API ms-jurisia-judicial
Route::get('/v1/consultas-pendientes', [ApiController::class, 'getPendingConsultas']);

// Endpoint para recibir los datos procesados desde el API ms-jurisia-judicial
// y actualizar las tablas correspondientes.
Route::post('/v1/update-consulta', [ApiController::class, 'updateConsulta']);

// Endpoint para manejar los webhooks de Telegram
Route::post('/v1/telegram/webhook', [TelegramController::class, 'handle']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
