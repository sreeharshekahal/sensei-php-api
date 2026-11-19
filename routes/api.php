<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\ObservationStatsController;
use App\Http\Controllers\UserRoleAccountController;
use App\Http\Controllers\UserStatsController;
use Illuminate\Support\Facades\Route;

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

// ─── REST Auth (public) ────────────────────────────────────────
Route::post('/rest-auth/login', [AuthController::class, 'login']);
Route::get('/rest-auth/logout', [AuthController::class, 'logout']);
Route::post('/rest-auth/logout', [AuthController::class, 'logout']);

Route::post('/rest-auth/password/reset', [AuthController::class, 'resetPassword']);
Route::post('/rest-auth/password/reset/confirm', [AuthController::class, 'resetConfirm']);
Route::post('/rest-auth/password/reset/{uidb64}/{token}', [AuthController::class, 'resetConfirm']);

Route::post('/rest-auth/registration', [AuthController::class, 'register']);
Route::post('/rest-auth/registration/verify-email', [AuthController::class, 'verifyEmail']);

// ─── Authenticated Routes ───────────────────────────────────────
Route::middleware('auth.token')->group(function () {
    Route::get('/rest-auth/user', [AuthController::class, 'user']);
    Route::put('/rest-auth/user', [AuthController::class, 'updateUser']);
    Route::patch('/rest-auth/user', [AuthController::class, 'updateUser']);
    Route::post('/rest-auth/password/change', [AuthController::class, 'changePassword']);

    Route::get('/user-role-accounts', [UserRoleAccountController::class, 'index']);
    Route::post('/user-role-accounts', [UserRoleAccountController::class, 'store']);
    Route::get('/user-role-accounts/{id}', [UserRoleAccountController::class, 'show']);
    Route::patch('/user-role-accounts/{id}', [UserRoleAccountController::class, 'update']);
    Route::delete('/user-role-accounts/{id}', [UserRoleAccountController::class, 'destroy']);
    Route::post('/user-role-accounts/bulk_delete', [UserRoleAccountController::class, 'bulkDelete']);

    Route::post('/attachments', [AttachmentController::class, 'store']);

    // Groups & Members
    Route::get('/my-group-based-members', [GroupController::class, 'myGroupBasedMembers']);
    Route::get('/groups', [GroupController::class, 'index']);
    Route::get('/groups/{id}', [GroupController::class, 'show']);
    Route::get('/groups/{id}/users', [GroupController::class, 'users']);

    // Observations
    Route::get('/observations', [ObservationController::class, 'index']);

    // User Observation Statistics
    Route::get('/user-stats', [UserStatsController::class, 'index']);
    Route::get('/user-stats/{id}', [UserStatsController::class, 'show']);

    // Observation Statistics Breakdown
    Route::get('/observation-stats', [ObservationStatsController::class, 'breakdown']);

    // Group Member Observation Statistics
    Route::get('/groups/{groupId}/observation-stats/{uraId}', [ObservationStatsController::class, 'groupMemberStats']);
});
