<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ClientController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});



// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {

    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

       // Partner routes
    Route::get('/partner/list', [AdminController::class, 'apiGetAnnouncements']);
    Route::post('/partner/save', [AdminController::class, 'store']);
    Route::put('/partner/update', [AdminController::class, 'update']);
    Route::delete('/partner/delete', [AdminController::class, 'apiDeleteAnnouncement']);

   Route::get('/client/list', [ClientController::class, 'apiClientAnnouncements']);
Route::post('/client/book', [ClientController::class, 'bookCar']);
Route::get('/client/filter-options', [ClientController::class, 'getFilterOptions']);
Route::get('/client/bookings', [ClientController::class, 'getUserBookings']);
Route::post('/client/cart/add', [ClientController::class, 'addToCart']);

Route::get('/client/myRents', [ClientController::class, 'myRents']);
Route::post('/client/cancelRent', [ClientController::class, 'cancelRent']);

Route::get('/partner/incomingRents', [AdminController::class, 'incomingRents']);
    Route::post('/partner/demandes/{id}/{action}', [AdminController::class, 'updateRentStatus']);

    });
});

// Fallback route for undefined API endpoints
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'error' => 'The requested endpoint does not exist'
    ], 404);
});
