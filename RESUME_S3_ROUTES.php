<?php

/**
 * Resume S3 Recovery Routes
 * Add these routes to routes/api.php
 */

use App\Http\Controllers\ResumeRecoveryController;

// Resume S3 URLs API routes
Route::prefix('resumes')->group(function () {

    // Get all resumes with S3 URLs
    Route::get('/s3-urls', [ResumeRecoveryController::class, 'index'])
        ->name('api.resumes.s3-urls');

    // Get resume statistics
    Route::get('/s3-stats', [ResumeRecoveryController::class, 'statistics'])
        ->name('api.resumes.s3-stats');

    // Search resumes
    Route::get('/s3-search', [ResumeRecoveryController::class, 'search'])
        ->name('api.resumes.s3-search');

    // Get recently downloaded resumes (default 24 hours)
    Route::get('/s3-recent/{hours?}', [ResumeRecoveryController::class, 'recent'])
        ->where('hours', '[0-9]+')
        ->name('api.resumes.s3-recent');

    // Get specific resume S3 URL
    Route::get('/s3-url/{resumeId}', [ResumeRecoveryController::class, 'show'])
        ->name('api.resumes.s3-url.show');

    // Download directly from S3
    Route::get('/s3-download/{resumeId}', [ResumeRecoveryController::class, 'downloadFromS3'])
        ->name('api.resumes.s3-download');
});

/**
 * USAGE EXAMPLES:
 *
 * 1. Get all resumes with S3 URLs:
 *    GET /api/resumes/s3-urls?page=1&per_page=50&status=downloaded
 *
 * 2. Get statistics:
 *    GET /api/resumes/s3-stats
 *    Response: {
 *        "total_resumes": 75,
 *        "total_size_formatted": "28.5 MB",
 *        "downloaded_count": 75,
 *        "recovered_count": 0,
 *        "last_24h_count": 45
 *    }
 *
 * 3. Search resumes:
 *    GET /api/resumes/s3-search?q=John&status=downloaded
 *
 * 4. Get recently downloaded (last 24 hours):
 *    GET /api/resumes/s3-recent
 *    GET /api/resumes/s3-recent/48  (last 48 hours)
 *
 * 5. Get specific resume:
 *    GET /api/resumes/s3-url/{resumeId}
 *
 * 6. Download from S3:
 *    GET /api/resumes/s3-download/{resumeId}
 */
