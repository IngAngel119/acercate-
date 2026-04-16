<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReflectionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeeklyReflectionController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);

Route::get('quotes', [QuoteController::class, 'index']);
Route::get('quotes/{quote}', [QuoteController::class, 'show']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('users', UserController::class)->only(['show', 'update', 'destroy']);
    Route::apiResource('journal-entries', JournalEntryController::class);
    Route::apiResource('reflections', ReflectionController::class);
    Route::post('reflections/weekly/generate', [WeeklyReflectionController::class, 'store']);
    Route::get('reflections/weekly/current', [WeeklyReflectionController::class, 'current']);
    Route::apiResource('quotes', QuoteController::class)->except(['index', 'show']);
});