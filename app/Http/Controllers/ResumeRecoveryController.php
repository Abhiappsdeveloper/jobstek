<?php

namespace App\Http\Controllers;

use App\Models\ResumeS3Url;
use Illuminate\Http\Request;

class ResumeRecoveryController extends Controller
{
    /**
     * Get all resumes with S3 URLs
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'downloaded');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 50);

        $query = ResumeS3Url::query();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $resumes = $query->orderBy('downloaded_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'resumes' => $resumes->items(),
                'pagination' => [
                    'total' => $resumes->total(),
                    'per_page' => $resumes->perPage(),
                    'current_page' => $resumes->currentPage(),
                    'last_page' => $resumes->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get resume by ID
     */
    public function show($resumeId)
    {
        $resume = ResumeS3Url::findByResumeId($resumeId);

        if (!$resume) {
            return response()->json([
                'status' => 'error',
                'message' => 'Resume not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $resume
        ]);
    }

    /**
     * Get statistics
     */
    public function statistics()
    {
        $total = ResumeS3Url::getTotalDownloaded();
        $size = ResumeS3Url::getTotalSize();
        $downloaded = ResumeS3Url::downloaded()->count();
        $recovered = ResumeS3Url::recovered()->count();

        // Last 24 hours
        $last24h = ResumeS3Url::recentlyDownloaded(24)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_resumes' => $total,
                'total_size_bytes' => $size,
                'total_size_formatted' => $this->formatBytes($size),
                'downloaded_count' => $downloaded,
                'recovered_count' => $recovered,
                'last_24h_count' => $last24h,
                'timestamp' => now()->toDateTimeString()
            ]
        ]);
    }

    /**
     * Search resumes
     */
    public function search(Request $request)
    {
        $query = $request->query('q', '');
        $status = $request->query('status', 'all');

        if (empty($query)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Search query required'
            ], 400);
        }

        $resumes = ResumeS3Url::where(function ($q) use ($query) {
            $q->where('resume_id', 'like', "%$query%")
              ->orWhere('filename', 'like', "%$query%");
        });

        if ($status && $status !== 'all') {
            $resumes->where('status', $status);
        }

        $results = $resumes->orderBy('downloaded_at', 'desc')->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'query' => $query,
                'results' => $results,
                'count' => $results->count()
            ]
        ]);
    }

    /**
     * Download S3 file directly
     */
    public function downloadFromS3($resumeId)
    {
        $resume = ResumeS3Url::findByResumeId($resumeId);

        if (!$resume) {
            return response()->json([
                'status' => 'error',
                'message' => 'Resume not found'
            ], 404);
        }

        try {
            $url = $resume->s3_url;
            return redirect($url);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recently downloaded
     */
    public function recent($hours = 24)
    {
        $resumes = ResumeS3Url::recentlyDownloaded($hours)->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'hours' => $hours,
                'resumes' => $resumes,
                'count' => $resumes->count()
            ]
        ]);
    }

    /**
     * Format bytes
     */
    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
