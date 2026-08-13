<?php

/**
 * Resume API Routes
 * Add these routes to routes/api.php
 *
 * These endpoints can be used to monitor and manage the resume downloader
 */

use App\Http\Controllers\ResumeStatusController;

// Prefix with /api for API endpoints
Route::prefix('api')->middleware('auth:sanctum')->group(function () {

    // Get status and statistics
    Route::get('/resumes/status', [ResumeStatusController::class, 'status'])->name('api.resumes.status');

    // List all resumes (with pagination)
    Route::get('/resumes/list', [ResumeStatusController::class, 'listResumes'])->name('api.resumes.list');

    // Manually trigger download
    Route::post('/resumes/download', [ResumeStatusController::class, 'download'])->name('api.resumes.download');

});

/**
 * Usage Examples:
 *
 * Get Status:
 * GET /api/resumes/status
 *
 * Response:
 * {
 *     "status": "success",
 *     "data": {
 *         "resume_count": 1250,
 *         "total_size": "2.5 GB",
 *         "total_size_formatted": "2.5 GB",
 *         "storage_path": "/home/.../storage/resumes",
 *         "recent_resumes": [...],
 *         "cron_status": {...},
 *         "recent_logs": [...],
 *         "disk_usage": {...},
 *         "timestamp": "2024-01-15 14:30:00"
 *     }
 * }
 *
 *
 * List Resumes:
 * GET /api/resumes/list?page=1&per_page=50
 *
 * Response:
 * {
 *     "status": "success",
 *     "data": {
 *         "resumes": [
 *             {
 *                 "name": "resume_abc123.pdf",
 *                 "size": 250000,
 *                 "size_formatted": "244.14 KB",
 *                 "modified": 1705330200,
 *                 "modified_formatted": "2024-01-15 10:30:00",
 *                 "url": "http://yoursite.com/resume/resume_abc123.pdf"
 *             }
 *         ],
 *         "pagination": {
 *             "total": 1250,
 *             "per_page": 50,
 *             "current_page": 1,
 *             "last_page": 25,
 *             "from": 1,
 *             "to": 50
 *         }
 *     }
 * }
 *
 *
 * Trigger Download:
 * POST /api/resumes/download
 *
 * Response:
 * {
 *     "status": "success",
 *     "message": "Download process started in background",
 *     "timestamp": "2024-01-15 14:30:00"
 * }
 */

// Alternative: Add to routes/web.php for web access (without authentication requirement)
//
// Route::get('/resumes/status', [ResumeStatusController::class, 'status'])->name('resumes.status');
// Route::get('/resumes/list', [ResumeStatusController::class, 'listResumes'])->name('resumes.list');
// Route::post('/resumes/download', [ResumeStatusController::class, 'download'])->name('resumes.download');
