<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\InvoiceImportController;
use App\Http\Controllers\InvoiceConfigController;

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('categories', CategoryController::class);
    Route::get('/transactions/banks', [TransactionController::class, 'getBanks']);
    Route::delete('/transactions/delete-all', [TransactionController::class, 'deleteAll']);
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('recurring-transactions', RecurringTransactionController::class);
    
    Route::get('/invoice-configs/summary', [InvoiceConfigController::class, 'summary']);
    Route::apiResource('invoice-configs', InvoiceConfigController::class);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    
    // Invoice Import routes
    Route::post('/invoice-import/upload', [InvoiceImportController::class, 'upload']);
    Route::post('/invoice-import/preview-keywords', [InvoiceImportController::class, 'previewKeywords']);
    Route::get('/invoice-import/pending', [InvoiceImportController::class, 'getPending']);
    Route::put('/invoice-import/transactions/{transaction}/categorize', [InvoiceImportController::class, 'categorize']);
});

