<?php

use App\Http\Controllers\Api\Admin\AdminDocumentController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| IDR API (all routes are prefixed with /api by bootstrap/app.php)
|--------------------------------------------------------------------------
| Logged-in requests send:  Authorization: Bearer <token>
| (the token comes from /api/auth/register or /api/auth/login).
|
|   public   anyone, no token
|   auth     any logged-in user (student or admin)
|   admin    logged-in user with role "admin"
*/

// Every {artifactId}, {sessionId} and {userId} is a 24-character MongoDB id;
// anything else is a 404 before any controller runs.
Route::pattern('artifactId', '[0-9a-fA-F]{24}');
Route::pattern('sessionId', '[0-9a-fA-F]{24}');
Route::pattern('userId', '[0-9a-fA-F]{24}');

// ── Public ──────────────────────────────────────────────────────────────
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::get('/search', SearchController::class)->middleware('throttle:search')->name('search');

Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
// Approved files: anyone. Pending/rejected: uploader (with token) or admin.
Route::get('/documents/{artifactId}', [DocumentController::class, 'show'])->name('documents.show');
Route::get('/documents/{artifactId}/preview', [DocumentController::class, 'preview'])->name('documents.preview');

// ── Logged in ───────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::post('/documents/upload', [DocumentController::class, 'upload'])->middleware('throttle:uploads')->name('documents.upload');
    Route::get('/documents/mine', [DocumentController::class, 'mine'])->name('documents.mine');
    Route::get('/documents/{artifactId}/status', [DocumentController::class, 'status'])->name('documents.status');
    Route::get('/documents/{artifactId}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documents/{artifactId}/certificate', [DocumentController::class, 'certificate'])->name('documents.certificate');

    Route::post('/chat/message', [ChatController::class, 'message'])->middleware('throttle:chat')->name('chat.message');
    Route::get('/chat/sessions', [ChatController::class, 'index'])->name('chat.sessions.index');
    Route::get('/chat/sessions/{sessionId}', [ChatController::class, 'show'])->name('chat.sessions.show');
});

// ── Admin ───────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/documents', [AdminDocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/pending', [AdminDocumentController::class, 'pending'])->name('documents.pending');
    Route::get('/documents/by-code/{code}', [AdminDocumentController::class, 'findByCode'])
        ->where('code', '[A-Za-z0-9 \-]{4,40}')->name('documents.by-code');
    Route::get('/documents/{artifactId}', [AdminDocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{artifactId}/preview', [AdminDocumentController::class, 'preview'])->name('documents.preview');
    Route::patch('/documents/{artifactId}/status', [AdminDocumentController::class, 'updateStatus'])->name('documents.status');
    Route::match(['put', 'patch'], '/documents/{artifactId}', [AdminDocumentController::class, 'update'])->name('documents.update');
    Route::delete('/documents/{artifactId}', [AdminDocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{artifactId}/reprocess', [AdminDocumentController::class, 'reprocess'])->name('documents.reprocess');
    Route::post('/documents/{artifactId}/hard-copy', [AdminDocumentController::class, 'markHardCopyReceived'])->name('documents.hard-copy');
    Route::delete('/documents/{artifactId}/hard-copy', [AdminDocumentController::class, 'clearHardCopy'])->name('documents.hard-copy.clear');

    Route::get('/search', [SearchController::class, 'admin'])->middleware('throttle:search')->name('search');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::patch('/users/{userId}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
});
