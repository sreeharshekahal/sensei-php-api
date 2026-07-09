<?php

// use App\Http\Controllers\AccessRoleController;
use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserRoleAccountController;

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
Route::middleware('auth.token')->group(function () {
    Route::delete('/user-role-accounts/{id}', [UserRoleAccountController::class, 'destroy']);
    Route::post('/user-role-accounts/bulk_delete', [UserRoleAccountController::class, 'bulkDelete']);
    Route::get('/user-role-accounts', [UserRoleAccountController::class, 'index']);
    Route::get('/user-role-accounts/{id}', [UserRoleAccountController::class, 'show']);
    Route::post('/user-role-accounts', [UserRoleAccountController::class, 'store']);
    Route::patch('/user-role-accounts/{id}', [UserRoleAccountController::class, 'update']);

    Route::post('/attachments', [AttachmentController::class, 'store']);
});


// Route::post('/user-role-accounts-2', [UserRoleAccountController::class, 'storeUser']);
// Route::get('/user-role-accounts',[UserRoleAccountController::class, 'index']);
// Route::get('/roles',[AccessRoleController::class,'index']);