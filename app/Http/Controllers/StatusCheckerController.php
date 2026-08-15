<?php

namespace App\Http\Controllers;

use DateTime;
use DateInterval;

class StatusCheckerController extends Controller
{
    /**
     * Check real-time script status
     */
    public function checkStatus()
    {
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');
        $logsPath = storage_path('logs/resume_downloader');

        $status = [
            'is_running' => $this->isScriptRunning($logsPath),
            'last_heartbeat' => $this->getLastHeartbeat($basePath),
            'heartbeat_age_minutes' => $this->getHeartbeatAge($basePath),
            'last_log_entry' => $this->getLastLogEntry($logsPath),
            'last_log_age_minutes' => $this->getLastLogAge($logsPath),
            'last_error' => $this->getLastError($logsPath),
            'current_processes' => $this->getRunningProcesses(),
            'script_status' => $this->determineScriptStatus($basePath, $logsPath),
            'recommendations' => $this->getRecommendations($basePath, $logsPath),
            'current_time' => now()->format('Y-m-d H:i:s'),
        ];

        return response()->json($status);
    }

    /**
     * Check if script is currently running
     */
    private function isScriptRunning($logsPath)
    {
        // Get latest log file
        if (!is_dir($logsPath)) {
            return false;
        }

        $logFiles = glob($logsPath . '/http_downloader_*.log');
        if (empty($logFiles)) {
            return false;
        }

        $latestLog = end($logFiles);
        $lastModified = filemtime($latestLog);
        $currentTime = time();

        // If log was modified within last 2 minutes, script is likely running
        return ($currentTime - $lastModified) < 120;
    }

    /**
     * Get last heartbeat timestamp
     */
    private function getLastHeartbeat($basePath)
    {
        $heartbeatFile = $basePath . '/.cron_heartbeat';

        if (file_exists($heartbeatFile)) {
            return trim(file_get_contents($heartbeatFile));
        }

        return 'No heartbeat found';
    }

    /**
     * Get heartbeat age in minutes
     */
    private function getHeartbeatAge($basePath)
    {
        $heartbeatFile = $basePath . '/.cron_heartbeat';

        if (!file_exists($heartbeatFile)) {
            return -1;
        }

        $heartbeatTime = strtotime(trim(file_get_contents($heartbeatFile)));
        $ageSeconds = time() - $heartbeatTime;
        $ageMinutes = round($ageSeconds / 60, 1);

        return $ageMinutes;
    }

    /**
     * Get last log entry
     */
    private function getLastLogEntry($logsPath)
    {
        if (!is_dir($logsPath)) {
            return 'No logs found';
        }

        $logFiles = glob($logsPath . '/http_downloader_*.log');
        if (empty($logFiles)) {
            return 'No logs found';
        }

        $latestLog = end($logFiles);
        $lines = file($latestLog, FILE_IGNORE_NEW_LINES);
        $lastLines = array_slice($lines, -5);

        foreach (array_reverse($lastLines) as $line) {
            if (trim($line)) {
                return trim($line);
            }
        }

        return 'No entries in latest log';
    }

    /**
     * Get age of last log entry in minutes
     */
    private function getLastLogAge($logsPath)
    {
        if (!is_dir($logsPath)) {
            return -1;
        }

        $logFiles = glob($logsPath . '/http_downloader_*.log');
        if (empty($logFiles)) {
            return -1;
        }

        $latestLog = end($logFiles);
        $lastModified = filemtime($latestLog);
        $ageSeconds = time() - $lastModified;

        return round($ageSeconds / 60, 1);
    }

    /**
     * Get last error
     */
    private function getLastError($logsPath)
    {
        if (!is_dir($logsPath)) {
            return 'No errors found';
        }

        $logFiles = glob($logsPath . '/http_downloader_*.log');
        $allErrors = [];

        foreach ($logFiles as $file) {
            $content = file_get_contents($file);
            preg_match_all('/(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}).*\[ERROR\](.+)/i', $content, $matches);

            for ($i = 0; $i < count($matches[0]); $i++) {
                $allErrors[] = [
                    'time' => $matches[1][$i] ?? '',
                    'message' => trim($matches[2][$i] ?? '')
                ];
            }
        }

        if (!empty($allErrors)) {
            $lastError = end($allErrors);
            return $lastError['time'] . ' | ' . substr($lastError['message'], 0, 100);
        }

        return 'No errors found';
    }

    /**
     * Get running processes
     */
    private function getRunningProcesses()
    {
        $output = trim(shell_exec("ps aux | grep '[p]ython3.*http_downloader.py' | wc -l"));
        return (int)$output;
    }

    /**
     * Determine script status
     */
    private function determineScriptStatus($basePath, $logsPath)
    {
        $isRunning = $this->isScriptRunning($logsPath);
        $heartbeatAge = $this->getHeartbeatAge($basePath);
        $logAge = $this->getLastLogAge($logsPath);
        $processes = $this->getRunningProcesses();

        if ($processes > 0) {
            return [
                'status' => 'RUNNING',
                'color' => 'green',
                'message' => 'Script is currently executing'
            ];
        }

        if ($isRunning) {
            return [
                'status' => 'JUST_COMPLETED',
                'color' => 'green',
                'message' => 'Script just completed'
            ];
        }

        if ($heartbeatAge > 0 && $heartbeatAge < 120) {
            return [
                'status' => 'RUNNING_NORMALLY',
                'color' => 'green',
                'message' => 'Script running every minute'
            ];
        }

        if ($heartbeatAge > 120 && $heartbeatAge < 300) {
            return [
                'status' => 'DELAYED',
                'color' => 'yellow',
                'message' => "Heartbeat is ${heartbeatAge} minutes old - running late or stuck"
            ];
        }

        if ($heartbeatAge > 300) {
            return [
                'status' => 'STOPPED',
                'color' => 'red',
                'message' => "Script NOT running - heartbeat is ${heartbeatAge} minutes old"
            ];
        }

        return [
            'status' => 'UNKNOWN',
            'color' => 'gray',
            'message' => 'Unable to determine status'
        ];
    }

    /**
     * Get recommendations
     */
    private function getRecommendations($basePath, $logsPath)
    {
        $heartbeatAge = $this->getHeartbeatAge($basePath);
        $recommendations = [];

        if ($heartbeatAge < 0) {
            $recommendations[] = [
                'severity' => 'CRITICAL',
                'action' => 'Heartbeat file missing - create new heartbeat',
                'command' => 'php artisan downloader:check-and-run --force'
            ];
        } elseif ($heartbeatAge > 300) {
            $recommendations[] = [
                'severity' => 'CRITICAL',
                'action' => 'Script has not run for ' . $heartbeatAge . ' minutes',
                'command' => 'php artisan downloader:check-and-run --force'
            ];
        } elseif ($heartbeatAge > 120) {
            $recommendations[] = [
                'severity' => 'WARNING',
                'action' => 'Script is running late (' . $heartbeatAge . ' minutes)',
                'command' => 'Check server load and resources'
            ];
        }

        // Check for errors in recent logs
        $lastError = $this->getLastError($logsPath);
        if (strpos($lastError, 'ERROR') !== false) {
            $recommendations[] = [
                'severity' => 'WARNING',
                'action' => 'Recent errors detected',
                'command' => 'Check log files: ' . $logsPath
            ];
        }

        // Check if processes are running
        $processes = $this->getRunningProcesses();
        if ($processes == 0 && $heartbeatAge > 60) {
            $recommendations[] = [
                'severity' => 'CRITICAL',
                'action' => 'No Python processes running',
                'command' => 'Restart cron: sudo systemctl restart crond'
            ];
        }

        return empty($recommendations) ? [['severity' => 'INFO', 'action' => 'System running normally', 'command' => 'No action needed']] : $recommendations;
    }
}
