<?php

use App\Http\Controllers\FeatureController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\FeatureEnabled;
use Illuminate\Support\Facades\Route;

// Scenario 1 — Webhook Processing
Route::post('/webhooks/{provider}', [WebhookController::class, 'receive']);
Route::get('/webhooks/{id}', [WebhookController::class, 'show']);

// Scenario 2 — Orders API
Route::get('/orders', [OrderController::class, 'index']);

// Scenario 3 — CSV Import
Route::post('/imports', [ImportController::class, 'store']);
Route::get('/imports/{id}', [ImportController::class, 'show']);

// Scenario 4 — Feature Flags
Route::get('/features', [FeatureController::class, 'index']);
Route::post('/features/{feature}/assign/{user}', [FeatureController::class, 'assign']);
Route::get('/beta', [FeatureController::class, 'beta'])->middleware(FeatureEnabled::class . ':beta');
