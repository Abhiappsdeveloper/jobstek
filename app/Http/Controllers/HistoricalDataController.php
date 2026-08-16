<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DateTime;

class HistoricalDataController extends Controller
{
    /**
     * Get all historical data from txt files
     */
    public function getAllHistoricalData()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');
        $logsPath = storage_path('logs/resume_downloader');

        $data = [
            'downloaded_resumes' => $this->getDownloadedResumes($basePath),
            'fetched_resume_ids' => $this->getFetchedResumeIds($basePath),
            's3_urls' => $this->getS3Urls($basePath),
            'pages_progress' => $this->getPagesProgress($basePath),
            'errors_timeline' => $this->getErrorsTimeline($logsPath),
            'log_timeline' => $this->getLogTimeline($logsPath),
            'timestamps' => $this->extractTimestamps($logsPath),
        ];

        return response()->json($data);
    }

    /**
     * Get downloaded resumes from file (showing LAST items)
     */
    private function getDownloadedResumes($basePath)
    {
        $file = $basePath . '/downloaded_resumes.txt';
        $data = [];

        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $data[] = trim($line);
            }
        }

        return [
            'count' => count($data),
            'items' => array_slice($data, -50), // Show LAST 50 instead of first
            'file' => $file
        ];
    }

    /**
     * Get fetched resume IDs (showing LAST items)
     */
    private function getFetchedResumeIds($basePath)
    {
        $file = $basePath . '/fetched_resume_ids.txt';
        $data = [];

        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $data[] = trim($line);
            }
        }

        return [
            'count' => count($data),
            'items' => array_slice($data, -100), // Show LAST 100 instead of first
            'total_items' => count($data),
            'file' => $file
        ];
    }

    /**
     * Get S3 URLs (showing LAST items)
     */
    private function getS3Urls($basePath)
    {
        $file = $basePath . '/resume_s3_urls.txt';
        $data = [];

        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (!empty(trim($line)) && strpos($line, '|') !== false) {
                    $parts = explode('|', $line);
                    $data[] = [
                        'resume_id' => $parts[0] ?? '',
                        'filename' => $parts[1] ?? '',
                        's3_url' => $parts[2] ?? ''
                    ];
                }
            }
        }

        return [
            'count' => count($data),
            'items' => array_slice($data, -50), // Show LAST 50 instead of first
            'total_items' => count($data),
            'file' => $file
        ];
    }

    /**
     * Get pages progress history
     */
    private function getPagesProgress($basePath)
    {
        $file = $basePath . '/fetched_pages_progress.txt';
        $currentPage = 0;

        if (file_exists($file)) {
            $currentPage = (int)trim(file_get_contents($file));
        }

        return [
            'current_page' => $currentPage,
            'file' => $file
        ];
    }

    /**
     * Extract error timeline from log files
     */
    private function getErrorsTimeline($logsPath)
    {
        $errors = [];
        $errorsByTime = [];

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    if (strpos($line, '[ERROR]') !== false || strpos($line, 'ERROR') !== false) {
                        // Extract timestamp if available
                        preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);
                        $timestamp = $matches[1] ?? 'unknown';

                        $errors[] = [
                            'timestamp' => $timestamp,
                            'message' => trim($line)
                        ];

                        if (!isset($errorsByTime[$timestamp])) {
                            $errorsByTime[$timestamp] = 0;
                        }
                        $errorsByTime[$timestamp]++;
                    }
                }
            }
        }

        ksort($errorsByTime);

        return [
            'total_errors' => count($errors),
            'errors_by_time' => $errorsByTime,
            'recent_errors' => array_slice($errors, -20), // Last 20 errors
            'all_errors_count' => count($errors)
        ];
    }

    /**
     * Extract log timeline (all log entries with timestamps)
     */
    private function getLogTimeline($logsPath)
    {
        $timeline = [];
        $countByTime = [];

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');
            sort($logFiles);

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    if (trim($line)) {
                        // Extract timestamp
                        preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);

                        if (!empty($matches[1])) {
                            $timestamp = $matches[1];
                            $timeline[] = [
                                'timestamp' => $timestamp,
                                'message' => trim($line)
                            ];

                            if (!isset($countByTime[$timestamp])) {
                                $countByTime[$timestamp] = 0;
                            }
                            $countByTime[$timestamp]++;
                        }
                    }
                }
            }
        }

        return [
            'total_log_entries' => count($timeline),
            'logs_by_minute' => $countByTime,
            'recent_logs' => array_slice($timeline, -50), // Last 50 entries
        ];
    }

    /**
     * Get heartbeat data from logs (last 60 minutes)
     */
    public function getHeartbeatData()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');
        $logsPath = storage_path('logs/resume_downloader');

        $heartbeats = [];
        $heartbeatsByMinute = [];
        $currentTime = time();

        // Extract heartbeats from log files
        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);

                // Look for heartbeat creation lines
                preg_match_all('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}).*\[CRON-HEALTH\].*heartbeat/i', $content, $matches);

                foreach ($matches[1] as $timestamp) {
                    $heartbeats[] = $timestamp;
                }
            }
        }

        // Also check the heartbeat file
        $heartbeatFile = $basePath . '/.cron_heartbeat';
        if (file_exists($heartbeatFile)) {
            $fileHeartbeat = trim(file_get_contents($heartbeatFile));
            if (!in_array($fileHeartbeat, $heartbeats)) {
                $heartbeats[] = $fileHeartbeat;
            }
        }

        // Sort and remove duplicates
        $heartbeats = array_unique($heartbeats);
        sort($heartbeats);

        // Group by minute and count
        foreach ($heartbeats as $hb) {
            $hbTime = strtotime($hb);
            $ageSeconds = $currentTime - $hbTime;
            $ageMinutes = round($ageSeconds / 60, 1);

            // Only include last 60 minutes
            if ($ageMinutes <= 60) {
                $minute = date('H:i', $hbTime);
                if (!isset($heartbeatsByMinute[$minute])) {
                    $heartbeatsByMinute[$minute] = 0;
                }
                $heartbeatsByMinute[$minute]++;
            }
        }

        ksort($heartbeatsByMinute);

        // Calculate correct average: total heartbeats / 60 minutes
        $totalHeartbeatsInWindow = array_sum($heartbeatsByMinute);
        $avgPerMinute = $totalHeartbeatsInWindow > 0 ? round($totalHeartbeatsInWindow / 60, 2) : 0;

        return response()->json([
            'total_heartbeats' => count($heartbeats),
            'heartbeats_last_60min' => array_slice($heartbeats, -60), // Last 60
            'heartbeats_by_minute' => $heartbeatsByMinute,
            'minute_count' => count($heartbeatsByMinute),
            'avg_per_minute' => $avgPerMinute,
            'total_in_window' => $totalHeartbeatsInWindow,
            'timestamps' => [
                'first' => $heartbeats[0] ?? null,
                'last' => end($heartbeats) ?: null,
                'current' => now()->toIso8601String(), // ISO 8601 format for proper JS parsing
            ]
        ]);
    }

    /**
     * Get per-minute error breakdown for last 60 minutes with categorization
     */
    public function getErrorsLastHourMinuteByMinute()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $logsPath = storage_path('logs/resume_downloader');
        $errorsByMinute = [];
        $errorsByType = [];
        $currentTime = time();
        $oneHourAgo = $currentTime - 3600;

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    if (strpos($line, '[ERROR]') !== false || strpos($line, 'ERROR') !== false || strpos($line, 'FAIL') !== false) {
                        preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);

                        if (!empty($matches[1])) {
                            $timestamp = $matches[1];
                            $timeInt = strtotime($timestamp);

                            // Only include last 60 minutes
                            if ($timeInt >= $oneHourAgo && $timeInt <= $currentTime) {
                                $minute = date('H:i', $timeInt);

                                // Initialize if not exists
                                if (!isset($errorsByMinute[$minute])) {
                                    $errorsByMinute[$minute] = [
                                        'total' => 0,
                                        'page_fetch' => 0,
                                        's3_upload' => 0,
                                        'download' => 0,
                                        'other' => 0
                                    ];
                                }

                                // Categorize error
                                $errorsByMinute[$minute]['total']++;

                                if (stripos($line, 'page') !== false || stripos($line, 'fetch') !== false || stripos($line, 'scrape') !== false) {
                                    $errorsByMinute[$minute]['page_fetch']++;
                                } elseif (stripos($line, 's3') !== false || stripos($line, 'aws') !== false || stripos($line, 'upload') !== false) {
                                    $errorsByMinute[$minute]['s3_upload']++;
                                } elseif (stripos($line, 'download') !== false || stripos($line, 'save') !== false || stripos($line, 'file') !== false) {
                                    $errorsByMinute[$minute]['download']++;
                                } else {
                                    $errorsByMinute[$minute]['other']++;
                                }
                            }
                        }
                    }
                }
            }
        }

        ksort($errorsByMinute);

        // Calculate totals by type
        foreach ($errorsByMinute as $minute => $data) {
            $errorsByType['page_fetch'] = ($errorsByType['page_fetch'] ?? 0) + $data['page_fetch'];
            $errorsByType['s3_upload'] = ($errorsByType['s3_upload'] ?? 0) + $data['s3_upload'];
            $errorsByType['download'] = ($errorsByType['download'] ?? 0) + $data['download'];
            $errorsByType['other'] = ($errorsByType['other'] ?? 0) + $data['other'];
        }

        $totalErrors = array_sum(array_column($errorsByMinute, 'total'));

        return response()->json([
            'total_errors_60min' => $totalErrors,
            'errors_by_minute' => $errorsByMinute,
            'errors_by_type' => $errorsByType,
            'minute_count' => count($errorsByMinute),
            'minutes_with_errors' => count(array_filter($errorsByMinute, fn($m) => $m['total'] > 0))
        ]);
    }

    /**
     * Get per-minute downloads for last 60 minutes
     */
    public function getDownloadsLastHourMinuteByMinute()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');
        $downloadedFile = $basePath . '/downloaded_resumes.txt';
        $logsPath = storage_path('logs/resume_downloader');

        $downloadsByMinute = [];
        $currentTime = time();
        $oneHourAgo = $currentTime - 3600;

        // READ FROM SAME SOURCE AS TOTAL: downloaded_resumes.txt
        // This guarantees: per-minute count ≤ total count
        if (file_exists($downloadedFile)) {
            // Get all downloaded resume IDs (same source as total count)
            $downloadedIds = file($downloadedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // For each downloaded resume, find its timestamp in logs
            if (is_dir($logsPath)) {
                $logFiles = glob($logsPath . '/http_downloader_*.log');

                foreach ($downloadedIds as $resumeId) {
                    $found = false;

                    // Search logs for this specific download
                    foreach ($logFiles as $file) {
                        if ($found) break;

                        $content = file_get_contents($file);
                        $lines = explode("\n", $content);

                        foreach ($lines as $line) {
                            // Look for this specific resume ID being downloaded
                            if (stripos($line, $resumeId) !== false &&
                                (stripos($line, 'download') !== false || stripos($line, 'saved') !== false) &&
                                stripos($line, 'error') === false) {

                                // Extract timestamp
                                preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);

                                if (!empty($matches[1])) {
                                    $timestamp = $matches[1];
                                    $timeInt = strtotime($timestamp);

                                    // Only count if within last 60 minutes
                                    if ($timeInt >= $oneHourAgo && $timeInt <= $currentTime) {
                                        $minute = date('H:i', $timeInt);
                                        $downloadsByMinute[$minute] = ($downloadsByMinute[$minute] ?? 0) + 1;
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        ksort($downloadsByMinute);

        return response()->json([
            'total_downloads_60min' => array_sum($downloadsByMinute),
            'downloads_by_minute' => $downloadsByMinute,
            'minute_count' => count($downloadsByMinute),
            'avg_per_minute' => count($downloadsByMinute) > 0 ? round(array_sum($downloadsByMinute) / 60, 2) : 0
        ]);
    }

    /**
     * Get per-minute pages fetched for last 60 minutes
     */
    public function getPagesLastHourMinuteByMinute()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $logsPath = storage_path('logs/resume_downloader');
        $pagesByMinute = [];
        $currentTime = time();
        $oneHourAgo = $currentTime - 3600;

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    // Look for page fetch indicators
                    if ((stripos($line, 'page') !== false || stripos($line, 'fetch') !== false || stripos($line, 'scrape') !== false) &&
                        stripos($line, 'error') === false && stripos($line, 'fail') === false) {

                        preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);

                        if (!empty($matches[1])) {
                            $timestamp = $matches[1];
                            $timeInt = strtotime($timestamp);

                            if ($timeInt >= $oneHourAgo && $timeInt <= $currentTime) {
                                $minute = date('H:i', $timeInt);
                                $pagesByMinute[$minute] = ($pagesByMinute[$minute] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }

        ksort($pagesByMinute);

        return response()->json([
            'total_pages_60min' => array_sum($pagesByMinute),
            'pages_by_minute' => $pagesByMinute,
            'minute_count' => count($pagesByMinute),
            'avg_per_minute' => count($pagesByMinute) > 0 ? round(array_sum($pagesByMinute) / 60, 2) : 0
        ]);
    }

    /**
     * Get per-minute S3 uploads for last 60 minutes
     */
    public function getS3UploadsLastHourMinuteByMinute()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $logsPath = storage_path('logs/resume_downloader');
        $s3ByMinute = [];
        $currentTime = time();
        $oneHourAgo = $currentTime - 3600;

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    // Look for S3 upload indicators
                    if ((stripos($line, 's3') !== false || stripos($line, 'upload') !== false || stripos($line, 'aws') !== false) &&
                        stripos($line, 'error') === false && stripos($line, 'fail') === false) {

                        preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);

                        if (!empty($matches[1])) {
                            $timestamp = $matches[1];
                            $timeInt = strtotime($timestamp);

                            if ($timeInt >= $oneHourAgo && $timeInt <= $currentTime) {
                                $minute = date('H:i', $timeInt);
                                $s3ByMinute[$minute] = ($s3ByMinute[$minute] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }

        ksort($s3ByMinute);

        return response()->json([
            'total_s3_60min' => array_sum($s3ByMinute),
            's3_by_minute' => $s3ByMinute,
            'minute_count' => count($s3ByMinute),
            'avg_per_minute' => count($s3ByMinute) > 0 ? round(array_sum($s3ByMinute) / 60, 2) : 0
        ]);
    }

    /**
     * Get per-minute load data for last 60 minutes from logs
     */
    public function getLoadLastHourMinuteByMinute()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $logsPath = storage_path('logs/resume_downloader');
        $loadByMinute = [];
        $currentTime = time();
        $oneHourAgo = $currentTime - 3600;

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    // Look for load indicators
                    if ((stripos($line, 'load') !== false || stripos($line, 'cpu') !== false)) {
                        preg_match('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches);
                        preg_match('/load[\s:]*(\d+\.?\d*)/', $line, $loadMatch);

                        if (!empty($matches[1])) {
                            $timestamp = $matches[1];
                            $timeInt = strtotime($timestamp);

                            if ($timeInt >= $oneHourAgo && $timeInt <= $currentTime) {
                                $minute = date('H:i', $timeInt);
                                $loadValue = !empty($loadMatch[1]) ? (float)$loadMatch[1] : 0;

                                if (!isset($loadByMinute[$minute])) {
                                    $loadByMinute[$minute] = [];
                                }
                                $loadByMinute[$minute][] = $loadValue;
                            }
                        }
                    }
                }
            }
        }

        // Calculate average per minute
        foreach ($loadByMinute as $minute => $values) {
            $loadByMinute[$minute] = count($values) > 0 ? round(array_sum($values) / count($values), 2) : 0;
        }

        ksort($loadByMinute);

        return response()->json([
            'load_by_minute' => $loadByMinute,
            'minute_count' => count($loadByMinute),
            'avg_load' => count($loadByMinute) > 0 ? round(array_sum($loadByMinute) / count($loadByMinute), 2) : sys_getloadavg()[0]
        ]);
    }

    /**
     * Extract all timestamps from logs to create timeline
     */
    private function extractTimestamps($logsPath)
    {
        $timestamps = [];

        if (is_dir($logsPath)) {
            $logFiles = glob($logsPath . '/http_downloader_*.log');

            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                preg_match_all('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $content, $matches);

                foreach ($matches[1] as $ts) {
                    if (!in_array($ts, $timestamps)) {
                        $timestamps[] = $ts;
                    }
                }
            }
        }

        sort($timestamps);

        return [
            'unique_timestamps' => count($timestamps),
            'timestamps' => $timestamps,
            'first_timestamp' => $timestamps[0] ?? null,
            'last_timestamp' => end($timestamps) ?: null,
        ];
    }
}
