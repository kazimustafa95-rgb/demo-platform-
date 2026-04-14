<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AmendmentController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CitizenProposalController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [MobileAuthController::class, 'register']);
    Route::post('/verify-email-code', [MobileAuthController::class, 'verifyEmailCode']);
    Route::post('/resend-verification-code', [MobileAuthController::class, 'resendVerificationCode']);
    Route::post('/login', [MobileAuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

Route::get('/bills', [BillController::class, 'index']);
Route::get('/bills/{bill}', [BillController::class, 'show']);
Route::get('/bills/{bill}/amendments', [AmendmentController::class, 'index']);
Route::get('/amendments/{amendment}', [AmendmentController::class, 'show']);
Route::get('/citizen-proposals', [CitizenProposalController::class, 'index']);
Route::get('/citizen-proposals/{proposal}', [CitizenProposalController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'show']);
    Route::put('/user', [UserController::class, 'update']);
    Route::post('/user/location', [MobileUserController::class, 'saveLocation']);
    Route::post('/user/verify-location', [MobileUserController::class, 'saveLocation']);

    Route::post('/bills/{bill}/vote', [BillController::class, 'vote']);
    Route::delete('/bills/{bill}/vote', [BillController::class, 'deleteVote']);
    Route::get('/bills/{bill}/insights', [BillController::class, 'insights']);

    Route::post('/bills/{bill}/amendments', [AmendmentController::class, 'store']);
    Route::post('/amendments/{amendment}/support', [AmendmentController::class, 'support']);
    Route::delete('/amendments/{amendment}/support', [AmendmentController::class, 'unsupport']);

    Route::post('/citizen-proposals', [CitizenProposalController::class, 'store']);
    Route::post('/citizen-proposals/{proposal}/support', [CitizenProposalController::class, 'support']);
    Route::delete('/citizen-proposals/{proposal}/support', [CitizenProposalController::class, 'unsupport']);

    Route::post('/report', [ReportController::class, 'store']);

    Route::get('/user/votes', [UserController::class, 'votes']);
    Route::get('/user/supports', [UserController::class, 'supports']);
    Route::get('/user/submissions', [UserController::class, 'submissions']);
    Route::get('/user/representatives', [UserController::class, 'representatives']);
});
