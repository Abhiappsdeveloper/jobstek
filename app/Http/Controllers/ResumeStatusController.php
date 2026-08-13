<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResumeStatusController extends Controller
{
    /**
     * Get resume download status and statistics
     */
    public function status()
    {
        try {
            $storagePath = storage_path('resumes');
            $logsPath = storage_path('logs/resume_downloader');

            // Get resume files
            $resumeFiles = [];
            $totalSize = 0;

            if (is_dir($storagePath)) {
                $files = array_diff(scandir($storagePath), ['.', '..']);

                foreach ($files as $file) {
                    $fullPath = $storagePath . '/' . $file;
                    $extension = pathinfo($file, PATHINFO_EXTENSION);

                    if (in_array($extension, ['pdf', 'doc', 'docx'])) {
                        $fileSize = filesize($fullPath);
                        $totalSize += $fileSize;

                        $resumeFiles[] = [
                            'name' => $file,
                            'size' => $fileSize,
                            'size_formatted' => $this->formatBytes($fileSize),
                            'modified' => filemtime($fullPath),
                            'modified_formatted' => date('Y-m-d H:i:s', filemtime($fullPath))
                        ];
                    }
                }
            }

            // Sort by modified time (newest first)
            usort($resumeFiles, function ($a, $b) {
                return $b['modified'] - $a['modified'];
            });

            // Get cron status
            $cronStatus = $this->getCronStatus();

            // Get recent logs
            $recentLogs = $this->getRecentLogs($logsPath);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'resume_count' => count($resumeFiles),
                    'total_size' => $totalSize,
                    'total_size_formatted' => $this->formatBytes($totalSize),
                    'storage_path' => $storagePath,
                    'recent_resumes' => array_slice($resumeFiles, 0, 10),
                    'cron_status' => $cronStatus,
                    'recent_logs' => $recentLogs,
                    'disk_usage' => $this->getDiskUsage(),
                    'timestamp' => now()->toDateTimeString()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting resume status', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all resumes with pagination
     */
    public function listResumes(Request $request)
    {
        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 50);

            $storagePath = storage_path('resumes');
            $resumeFiles = [];

            if (is_dir($storagePath)) {
                $files = array_diff(scandir($storagePath), ['.', '..']);

                foreach ($files as $file) {
                    $fullPath = $storagePath . '/' . $file;
                    $extension = pathinfo($file, PATHINFO_EXTENSION);

                    if (in_array($extension, ['pdf', 'doc', 'docx'])) {
                        $fileSize = filesize($fullPath);

                        $resumeFiles[] = [
                            'name' => $file,
                            'size' => $fileSize,
                            'size_formatted' => $this->formatBytes($fileSize),
                            'modified' => filemtime($fullPath),
                            'modified_formatted' => date('Y-m-d H:i:s', filemtime($fullPath)),
                            'url' => route('resume.download', $file)
                        ];
                    }
                }
            }

            // Sort by modified time (newest first)
            usort($resumeFiles, function ($a, $b) {
                return $b['modified'] - $a['modified'];
            });

            // Paginate
            $total = count($resumeFiles);
            $offset = ($page - 1) * $perPage;
            $paginatedFiles = array_slice($resumeFiles, $offset, $perPage);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'resumes' => $paginatedFiles,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => ceil($total / $perPage),
                        'from' => $offset + 1,
                        'to' => min($offset + $perPage, $total)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing resumes', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually trigger resume download
     */
    public function download(Request $request)
    {
        try {
            $pythonScript = base_path('http_downloader_hostinger.py');

            if (!file_exists($pythonScript)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Python script not found'
                ], 404);
            }

            // Run in background
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows
                exec("start /B python3 \"$pythonScript\" > NUL 2>&1");
            } else {
                // Linux/Unix
                exec("python3 \"$pythonScript\" > /dev/null 2>&1 &");
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Download process started in background',
                'timestamp' => now()->toDateTimeString()
            ]);
        } catch (\Exception $e) {
            Log::error('Error starting download', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get cron job status
     */
    protected function getCronStatus()
    {
        try {
            $statusFile = storage_path('logs/resume_downloader/cron_status.log');

            if (!file_exists($statusFile)) {
                return [
                    'configured' => false,
                    'last_run' => null,
                    'last_status' => null
                ];
            }

            $content = file_get_contents($statusFile);
            $lines = array_reverse(explode("\n", trim($content)));

            // Get last status line
            $lastStatus = null;
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $lastStatus = trim($line);
                    break;
                }
            }

            // Extract timestamp and status
            $parts = explode('] ', $lastStatus, 2);

            return [
                'configured' => true,
                'last_run' => $parts[0] ?? null,
                'last_status' => $parts[1] ?? $lastStatus,
                'last_update' => filemtime($statusFile)
            ];
        } catch (\Exception $e) {
            return [
                'configured' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get recent logs
     */
    protected function getRecentLogs($logsPath, $lines = 20)
    {
        try {
            if (!is_dir($logsPath)) {
                return [];
            }

            $logFile = glob($logsPath . '/downloader_*.log');

            if (empty($logFile)) {
                return [];
            }

            // Get most recent log file
            usort($logFile, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            $file = $logFile[0];
            $content = file_get_contents($file);
            $logLines = explode("\n", trim($content));

            // Get last N lines
            return array_slice(array_reverse($logLines), 0, $lines);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get disk usage
     */
    protected function getDiskUsage()
    {
        try {
            $storagePath = storage_path('resumes');

            if (!is_dir($storagePath)) {
                return null;
            }

            // Get total size
            $totalSize = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($storagePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalSize += $file->getSize();
                }
            }

            // Get disk free space
            $diskFree = disk_free_space($storagePath);
            $diskTotal = disk_total_space($storagePath);
            $diskUsed = $diskTotal - $diskFree;

            return [
                'storage_resumes_size' => $this->formatBytes($totalSize),
                'storage_resumes_size_bytes' => $totalSize,
                'disk_total' => $this->formatBytes($diskTotal),
                'disk_used' => $this->formatBytes($diskUsed),
                'disk_free' => $this->formatBytes($diskFree),
                'disk_usage_percentage' => round(($diskUsed / $diskTotal) * 100, 2)
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Format bytes to human readable format
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
