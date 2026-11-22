<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CameraController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\ViewController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'form'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/s/{token}', [AuthController::class, 'share'])->where('token', '[A-Za-z0-9]+');

// nginx auth_request for the live relay
Route::get('/internal/live-auth', [MediaController::class, 'liveAuth']);

// logged-in users and guests with a temporary link
Route::middleware('viewer')->group(function () {
    Route::get('/', [ViewController::class, 'live']);
    Route::get('/history', [ViewController::class, 'history']);
    Route::get('/api/history/{camera}.m3u8', [MediaController::class, 'playlist']);
    Route::get('/api/coverage', [MediaController::class, 'coverage']);
    Route::get('/rec/{path}', [MediaController::class, 'segment'])->where('path', '.+\.ts');
    Route::get('/exports', [ExportController::class, 'index']);
    Route::get('/api/exports', [ExportController::class, 'list']);
    Route::post('/api/exports', [ExportController::class, 'store']);
    Route::get('/exports/{export}/download', [ExportController::class, 'download']);
});

// administration: logged-in users only
Route::middleware('auth')->group(function () {
    Route::delete('/api/exports/{export}', [ExportController::class, 'destroy']);

    Route::get('/cameras', [CameraController::class, 'index']);
    Route::get('/cameras/create', [CameraController::class, 'create']);
    Route::post('/cameras', [CameraController::class, 'store']);
    Route::post('/cameras/test', [CameraController::class, 'test']);
    Route::get('/cameras/{camera}/edit', [CameraController::class, 'edit']);
    Route::put('/cameras/{camera}', [CameraController::class, 'update']);
    Route::delete('/cameras/{camera}', [CameraController::class, 'destroy']);

    Route::get('/shares', [ShareController::class, 'index']);
    Route::post('/shares', [ShareController::class, 'store']);
    Route::delete('/shares/{share}', [ShareController::class, 'destroy']);

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/telegram-test', [SettingsController::class, 'telegramTest']);
    Route::post('/settings/password', [SettingsController::class, 'password']);
    Route::post('/settings/users', [SettingsController::class, 'addUser']);
    Route::delete('/settings/users/{user}', [SettingsController::class, 'deleteUser']);
});
