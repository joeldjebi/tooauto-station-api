<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StationServiceController;
use App\Http\Controllers\UsagerController;
use App\Http\Controllers\VidangeController;
use App\Http\Controllers\AsignQrCodeController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/update', [AuthController::class, 'update']);
    Route::put('/update-password', [AuthController::class, 'updatePassword']);

    // Route pour créer une nouvelle station de service
    Route::post('/station-services', [StationServiceController::class, 'store']);

    // Route pour récupérer toutes les stations de service
    Route::get('/station-services', [StationServiceController::class, 'index']);

    // Route pour récupérer une station de service spécifique
    Route::get('/station-services/{id}', [StationServiceController::class, 'show']);

    // Route pour mettre à jour une station de service
    Route::match(['put', 'post'], '/station-services/{id}', [StationServiceController::class, 'update']);

    // Route pour supprimer une station de service
    Route::delete('/station-services/{id}', [StationServiceController::class, 'destroy']);

    // Optionnel: route pour supprimer le logo d'une station de service
    Route::patch('/station-services/{id}/logo', [StationServiceController::class, 'removeLogo']);

    // Route pour enregistrer un nouvel usager
    Route::post('/register-usager', [UsagerController::class, 'registerUsager']);
    Route::get('/get-usager-by-station', [UsagerController::class, 'getUsagerByStation']);

    // Routes pour les vidanges
    Route::get('/vidanges', [VidangeController::class, 'index']);
    Route::post('/vidange-by-matricule', [VidangeController::class, 'getLastVidangeByMatricule']);
    Route::post('/vidanges', [VidangeController::class, 'store']);
    Route::post('/vehicule-by-matricule', [VidangeController::class, 'getVehiculeByMatricule']);

    Route::post('/qrcodes/assign-scan', [AsignQrCodeController::class, 'assignByScan']);
    Route::get('/qrcodes/history/{station_id}', [AsignQrCodeController::class, 'historyByStation']);

    Route::post('/store-employe', [AuthController::class, 'registerEmplye']);
    Route::get('/list-employe', [AuthController::class, 'indexEmploye']);

    Route::get('/types-vehicules', [UsagerController::class, 'getTypeDeVehicules']);
    Route::get('/marques', [UsagerController::class, 'getMarques']);
    Route::get('/types-carburants', [UsagerController::class, 'getTypeDeCarburants']);
        
});
