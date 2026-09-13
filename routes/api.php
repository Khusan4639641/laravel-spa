<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationReviewController;
use App\Http\Controllers\Api\OrganizationSnapshotController;
use App\Http\Controllers\Api\ParsingRunController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::post('/organization', [OrganizationController::class, 'store'])->middleware('throttle:sync');
    Route::post('/organization/sync', [OrganizationController::class, 'sync'])->middleware('throttle:sync');
    Route::get('/parsing-runs/{parsingRun}', [ParsingRunController::class, 'show']);
    Route::get('/organization/snapshots', [OrganizationSnapshotController::class, 'index']);
    Route::get('/organization/reviews', [OrganizationReviewController::class, 'index']);
});
