<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| KaziBora Platform Core API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
|
| Route groups:
|   /api/auth/*          — Authentication (register, login, profile)
|   /api/jobs/*          — Job posting management + candidate ranking
|   /api/candidates/*    — CV upload and candidate profiles
|   /api/payments/*      — M-Pesa subscription payments
|
*/

// ─── Health Check ────────────────────────────────────────────
Route::get('/health', fn() => response()->json([
    'status' => 'ok',
    'service' => 'KaziBora Platform Core',
    'timestamp' => now()->toIso8601String(),
]));

// ─── Auth Routes (Public) ────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Protected
    Route::middleware('jwt.auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// ─── Job Routes (Protected) ─────────────────────────────────
Route::prefix('jobs')->middleware('jwt.auth')->group(function () {
    Route::get('/', [JobController::class, 'index']);
    Route::post('/', [JobController::class, 'store']);
    Route::get('/{id}', [JobController::class, 'show']);
    Route::put('/{id}', [JobController::class, 'update']);
    Route::delete('/{id}', [JobController::class, 'destroy']);

    // Candidate ranking for a specific job
    Route::get('/{id}/candidates', [JobController::class, 'rankedCandidates']);
    Route::post('/{id}/score-all', [JobController::class, 'scoreAllCandidates']);
});

// ─── Candidate Routes (Protected) ───────────────────────────
Route::prefix('candidates')->middleware('jwt.auth')->group(function () {
    Route::get('/', [CandidateController::class, 'index']);
    Route::post('/upload', [CandidateController::class, 'upload']);
    Route::post('/upload-bulk', [CandidateController::class, 'uploadBulk']);
    Route::get('/{id}', [CandidateController::class, 'show']);
});

// ─── Payment Routes ─────────────────────────────────────────
Route::prefix('payments')->group(function () {
    // Public: M-Pesa callback (called by Safaricom servers)
    Route::post('/mpesa/callback', [PaymentController::class, 'mpesaCallback']);

    // Public: Available plans
    Route::get('/plans', [PaymentController::class, 'plans']);

    // Protected: Payment operations
    Route::middleware('jwt.auth')->group(function () {
        Route::post('/initiate', [PaymentController::class, 'initiate']);
        Route::get('/status/{checkoutRequestId}', [PaymentController::class, 'status']);
        Route::get('/history', [PaymentController::class, 'history']);
        Route::get('/subscription', [PaymentController::class, 'subscription']);
    });
});
