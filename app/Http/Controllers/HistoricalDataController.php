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
     * Get downloaded resumes from file
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
            'items' => $data,
            'file' => $file
        ];
    }

    /**
     * Get fetched resume IDs
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
            'items' => array_slice($data, 0, 100), // Show first 100
            'total_items' => count($data),
            'file' => $file
        ];
    }

    /**
     * Get S3 URLs
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
            'items' => array_slice($data, 0, 50),
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
