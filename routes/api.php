<?php

use App\Http\Controllers\AccessRoleController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\ObservationReportController;
use App\Http\Controllers\ObservationStatsController;
use App\Http\Controllers\PropositionController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\RatingSchemeController;
use App\Http\Controllers\RubricController;
use App\Http\Controllers\SubpropositionController;
use App\Http\Controllers\TagController;
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

    // User Role Accounts
    Route::get('/user-role-accounts', [UserRoleAccountController::class, 'index']);
    Route::post('/user-role-accounts', [UserRoleAccountController::class, 'store']);
    Route::get('/user-role-accounts/me', [UserRoleAccountController::class, 'me']);
    Route::get('/user-role-accounts/{id}/user_groups', [UserRoleAccountController::class, 'userGroups']);
    Route::get('/user-role-accounts/{id}', [UserRoleAccountController::class, 'show']);
    Route::patch('/user-role-accounts/{id}', [UserRoleAccountController::class, 'update']);
    Route::delete('/user-role-accounts/{id}', [UserRoleAccountController::class, 'destroy']);
    Route::post('/user-role-accounts/bulk_delete', [UserRoleAccountController::class, 'bulkDelete']);

    // Attachments
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{id}', [AttachmentController::class, 'show']);

    // Groups & Members
    Route::get('/my-group-based-members', [GroupController::class, 'myGroupBasedMembers']);
    Route::get('/groups', [GroupController::class, 'index']);
    Route::get('/groups/{id}', [GroupController::class, 'show']);
    Route::get('/groups/{id}/users', [GroupController::class, 'users']);

    // Observations
    Route::get('/observations', [ObservationController::class, 'index']);
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::get('/observations/{id}', [ObservationController::class, 'show']);
    Route::patch('/observations/{id}', [ObservationController::class, 'update']);
    Route::put('/observations/{id}', [ObservationController::class, 'update']);
    Route::get('/observations/{observationId}/evidences', [EvidenceController::class, 'observationEvidences']);
    Route::post('/observations/{observationId}/evidences', [EvidenceController::class, 'storeObservationEvidence']);

    // Evidences
    Route::get('/evidences', [EvidenceController::class, 'index']);

    // Indicators
    Route::get('/indicators', [IndicatorController::class, 'index']);

    // Tags
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::post('/tags/bulk_delete', [TagController::class, 'bulkDelete']);
    Route::get('/tags/{id}', [TagController::class, 'show']);
    Route::put('/tags/{id}', [TagController::class, 'update']);
    Route::patch('/tags/{id}', [TagController::class, 'update']);
    Route::delete('/tags/{id}', [TagController::class, 'destroy']);

    // Ratings
    Route::get('/ratings', [RatingController::class, 'index']);
    Route::get('/ratings/{id}', [RatingController::class, 'show']);

    // Rating Schemes
    Route::get('/rating-schemes', [RatingSchemeController::class, 'index']);
    Route::post('/rating-schemes', [RatingSchemeController::class, 'store']);
    Route::get('/rating-schemes/{id}', [RatingSchemeController::class, 'show']);
    Route::put('/rating-schemes/{id}', [RatingSchemeController::class, 'update']);
    Route::patch('/rating-schemes/{id}', [RatingSchemeController::class, 'update']);
    Route::delete('/rating-schemes/{id}', [RatingSchemeController::class, 'destroy']);

    // Access Roles
    Route::get('/roles', [AccessRoleController::class, 'index']);
    Route::post('/roles', [AccessRoleController::class, 'store']);
    Route::post('/roles/bulk_delete', [AccessRoleController::class, 'bulkDelete']);
    Route::get('/roles/{id}', [AccessRoleController::class, 'show']);
    Route::put('/roles/{id}', [AccessRoleController::class, 'update']);
    Route::patch('/roles/{id}', [AccessRoleController::class, 'update']);
    Route::delete('/roles/{id}', [AccessRoleController::class, 'destroy']);

    // User Observation Statistics
    Route::get('/user-stats', [UserStatsController::class, 'index']);
    Route::get('/user-stats/{id}', [UserStatsController::class, 'show']);

    // Propositions
    Route::get('/propositions', [PropositionController::class, 'index']);
    Route::post('/propositions', [PropositionController::class, 'store']);
    Route::get('/propositions/{id}', [PropositionController::class, 'show']);
    Route::put('/propositions/{id}', [PropositionController::class, 'update']);
    Route::patch('/propositions/{id}', [PropositionController::class, 'update']);
    Route::delete('/propositions/{id}', [PropositionController::class, 'destroy']);

    // Subpropositions
    Route::get('/subpropositions', [SubpropositionController::class, 'index']);
    Route::post('/subpropositions', [SubpropositionController::class, 'store']);
    Route::get('/subpropositions/{id}', [SubpropositionController::class, 'show']);
    Route::put('/subpropositions/{id}', [SubpropositionController::class, 'update']);
    Route::patch('/subpropositions/{id}', [SubpropositionController::class, 'update']);
    Route::delete('/subpropositions/{id}', [SubpropositionController::class, 'destroy']);

    // Rubrics
    Route::get('/rubrics', [RubricController::class, 'index']);
    Route::post('/rubrics', [RubricController::class, 'store']);
    Route::get('/rubrics/{id}/tree', [RubricController::class, 'tree']);
    Route::get('/rubrics/{id}', [RubricController::class, 'show']);
    Route::put('/rubrics/{id}', [RubricController::class, 'update']);
    Route::patch('/rubrics/{id}', [RubricController::class, 'update']);
    Route::delete('/rubrics/{id}', [RubricController::class, 'destroy']);

    // Observation Statistics Breakdown
    Route::get('/observation-stats', [ObservationStatsController::class, 'breakdown']);

    // Group Member Observation Statistics & Analytics
    Route::get('/groups/{groupId}/observation-stats/{uraId}', [ObservationStatsController::class, 'groupMemberStats']);
    Route::get('/groups/{groupId}/indicator-rating-charts/{uraId}', [ObservationStatsController::class, 'indicatorRatingCharts']);
    Route::post('/group-stats-pdf-report', [ObservationStatsController::class, 'groupStatsPdfReport']);

    // Observation Reports & Advance Analytics
    Route::get('/group-observation-activity-reports/{id}', [ObservationReportController::class, 'groupObservationActivityReport']);
    Route::get('/group-observation-indicators-reports/{id}', [ObservationReportController::class, 'groupObservationIndicatorsReport']);
    Route::get('/groups/{groupId}/member-observation-rating-reports', [ObservationReportController::class, 'memberObservationRatingReports']);
    Route::get('/groups/{groupId}/member-observation-rating-reports/{uraId}', [ObservationReportController::class, 'memberObservationRatingReportDetail']);
    Route::get('/groups/{groupId}/member-observation-indicators-reports/{uraId}', [ObservationReportController::class, 'memberObservationIndicatorsReport']);
    Route::get('/groups/{groupId}/member-observation-activity-reports/{uraId}', [ObservationReportController::class, 'memberObservationActivityReport']);
    Route::get('/groups/{groupId}/observation-advance-report/download_report', [ObservationReportController::class, 'downloadAdvanceReport']);
    Route::get('/groups/{groupId}/observation-advance-report', [ObservationReportController::class, 'advanceReport']);
    Route::get('/groups/{groupId}/advance-trends/{pk}/download_report', [ObservationReportController::class, 'downloadAdvanceTrends']);
    Route::get('/groups/{groupId}/advance-trends/{pk}', [ObservationReportController::class, 'advanceTrends']);
});
