<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ResumeMonitorController extends Controller
{
    public function __construct()
    {
        // No middleware - handle auth in methods
    }

    /**
     * Show login page or dashboard
     */
    public function login(Request $request)
    {
        // If already logged in
        if (session('monitor_authenticated')) {
            return $this->dashboard();
        }

        // POST request - validate password
        if ($request->isMethod('post')) {
            $password = $request->input('password', '');

            if ($password === env('MONITOR_PASSWORD', 'monitor@2026')) {
                session(['monitor_authenticated' => true]);
                return redirect()->route('resume.monitor');
            }

            return back()->withErrors(['password' => 'Invalid password']);
        }

        // Show login form
        return view('resume-monitor.login');
    }

    /**
     * Show monitoring dashboard
     */
    public function dashboard()
    {
        // Check if authenticated
        if (!session('monitor_authenticated')) {
            return redirect()->route('monitor.login.show');
        }

        try {
            $basePath = storage_path('resumes');
            $logsPath = storage_path('logs/resume_downloader');

            // HEARTBEAT & CRON STATUS
            $heartbeatFile = $basePath . '/.cron_heartbeat';
            $cronHealthy = false;
            $heartbeatAge = 'UNKNOWN';
            $lastHeartbeat = 'Never';

            if (file_exists($heartbeatFile)) {
                $lastHeartbeat = file_get_contents($heartbeatFile);
                $lastTime = strtotime(trim($lastHeartbeat));
                $age = (time() - $lastTime);
                $heartbeatAge = round($age / 60, 1) . ' minutes';

                if ($age < 120) {
                    $cronHealthy = true;
                }
            }

            // DOWNLOAD STATISTICS
            $downloadedFile = $basePath . '/downloaded_resumes.txt';
            $totalDownloaded = 0;
            if (file_exists($downloadedFile)) {
                $lines = file($downloadedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $totalDownloaded = count($lines);
            }

            // TOTAL SIZE
            $totalSize = 0;
            if (is_dir($basePath)) {
                $files = glob($basePath . '/*.{doc,docx,pdf}', GLOB_BRACE);
                foreach ($files as $file) {
                    $totalSize += filesize($file);
                }
            }
            $totalSizeFormatted = $this->formatBytes($totalSize);

            // FETCHED IDS (PENDING)
            $fetchedIdsFile = $basePath . '/fetched_resume_ids.txt';
            $totalFetched = 0;
            if (file_exists($fetchedIdsFile)) {
                $lines = file($fetchedIdsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $totalFetched = count($lines);
            }

            $pending = $totalFetched - $totalDownloaded;

            // PAGES FETCHED
            $pagesFile = $basePath . '/fetched_pages_progress.txt';
            $pagesFetched = 0;
            if (file_exists($pagesFile)) {
                $pagesFetched = (int)trim(file_get_contents($pagesFile));
            }

            // S3 URLS
            $s3File = $basePath . '/resume_s3_urls.txt';
            $s3Urls = 0;
            if (file_exists($s3File)) {
                $lines = file($s3File, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $s3Urls = count($lines);
            }

            // ERRORS
            $errorCount = 0;
            $recentErrors = [];
            $todayDate = date('Ymd');
            $logFiles = glob($logsPath . '/http_downloader_' . $todayDate . '*.log');

            if (!empty($logFiles)) {
                foreach ($logFiles as $file) {
                    $content = file_get_contents($file);
                    $errorCount += substr_count($content, 'ERROR') + substr_count($content, 'FAIL');

                    // Get recent errors
                    preg_match_all('/\[ERROR\].*|FAIL.*/', $content, $matches);
                    $recentErrors = array_merge($recentErrors, array_slice($matches[0], -5));
                }
            }

            // SERVER STATS
            $load = sys_getloadavg();
            $uptime = exec('uptime');
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            $diskPercent = round(((($diskTotal - $diskFree) / $diskTotal) * 100), 2);

            // RUNNING PROCESSES
            $runningProcesses = (int)trim(shell_exec("ps aux | grep '[p]ython3.*http_downloader.py' | wc -l"));

            // ESTIMATES
            $avgDownloadsPerMin = 2.6;
            $remainingMinutes = $pending > 0 ? (int)($pending / $avgDownloadsPerMin) : 0;
            $estimatedHours = (int)($remainingMinutes / 60);
            $estimatedMins = $remainingMinutes % 60;

            return view('resume-monitor.dashboard-unified', [
                'cronHealthy' => $cronHealthy,
                'heartbeatAge' => $heartbeatAge,
                'lastHeartbeat' => $lastHeartbeat,
                'totalDownloaded' => $totalDownloaded,
                'totalSize' => $totalSizeFormatted,
                'totalFetched' => $totalFetched,
                'pending' => $pending,
                'pagesFetched' => $pagesFetched,
                's3Urls' => $s3Urls,
                'errorCount' => $errorCount,
                'recentErrors' => array_slice($recentErrors, 0, 5),
                'load' => $load,
                'diskPercent' => $diskPercent,
                'diskFree' => $this->formatBytes($diskFree),
                'runningProcesses' => $runningProcesses,
                'estimatedHours' => $estimatedHours,
                'estimatedMins' => $estimatedMins,
                'uptime' => $uptime,
            ]);

        } catch (\Exception $e) {
            Log::error('ResumeMonitor Error: ' . $e->getMessage());
            return view('resume-monitor.error', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Logout
     */
    public function logout()
    {
        session()->forget('monitor_authenticated');
        return redirect()->route('monitor.login.show')->with('success', 'Logged out successfully');
    }

    /**
     * Get live JSON data for AJAX updates
     */
    public function getLiveData()
    {
        // Check if authenticated
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');

        return response()->json([
            'timestamp' => now(),
            'downloaded' => $this->getDownloadCount($basePath),
            'fetched' => $this->getFetchedCount($basePath),
            'pages' => $this->getPagesCount($basePath),
            'load' => sys_getloadavg(),
            'processes' => (int)trim(shell_exec("ps aux | grep '[p]ython3.*http_downloader.py' | wc -l")),
            'healthy' => $this->isCronHealthy($basePath),
        ]);
    }

    /**
     * Get historical data from log files
     */
    public function getHistoricalData()
    {
        // Check if authenticated
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');
        $logsPath = storage_path('logs/resume_downloader');

        // Get current values
        $currentDownloaded = $this->getDownloadCount($basePath);
        $currentPages = $this->getPagesCount($basePath);

        // Get historical values from progress files
        $progressFile = $basePath . '/download_resume_progress.txt';
        $pageProgressFile = $basePath . '/fetched_pages_progress.txt';

        $historicalData = [
            'current' => [
                'downloaded' => $currentDownloaded,
                'pages' => $currentPages,
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ],
            'trend' => [
                'downloads_increasing' => true,
                'pages_increasing' => true,
                'last_update' => now()->format('Y-m-d H:i:s'),
            ]
        ];

        return response()->json($historicalData);
    }

    /**
     * Get current error count
     */
    public function getErrorCount()
    {
        // Check if authenticated
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $logsPath = storage_path('logs/resume_downloader');
        $errorCount = 0;

        // Get all log files from today
        $todayDate = date('Ymd');
        $logFiles = glob($logsPath . '/http_downloader_' . $todayDate . '*.log');

        if (!empty($logFiles)) {
            foreach ($logFiles as $file) {
                $content = file_get_contents($file);
                $errorCount += substr_count($content, 'ERROR') + substr_count($content, 'FAIL');
            }
        }

        return response()->json([
            'error_count' => $errorCount,
            'timestamp' => now(),
        ]);
    }

    /**
     * Get downtime data (minutes when script didn't run)
     */
    public function getDowntimeList()
    {
        // Check if authenticated
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = storage_path('resumes');
        $logsPath = storage_path('logs/resume_downloader');
        $downtime = [];

        // Get all log files from today
        $todayDate = date('Ymd');
        $logFiles = glob($logsPath . '/http_downloader_' . $todayDate . '*.log');

        if (empty($logFiles)) {
            return response()->json(['downtime_minutes' => []]);
        }

        // Extract timestamps from log files
        $executedMinutes = [];
        foreach ($logFiles as $file) {
            preg_match('/' . $todayDate . '_(\d{6})/', $file, $matches);
            if (!empty($matches[1])) {
                // Format: HHMMSS → extract HH:MM
                $time = substr($matches[1], 0, 2) . ':' . substr($matches[1], 2, 2);
                $executedMinutes[$time] = true;
            }
        }

        // Check last 60 minutes for gaps
        $now = now();
        $downtime = [];
        for ($i = 59; $i >= 0; $i--) {
            $checkTime = $now->copy()->subMinutes($i);
            $timeKey = $checkTime->format('H:i');

            if (!isset($executedMinutes[$timeKey])) {
                $downtime[] = $timeKey;
            }
        }

        return response()->json([
            'downtime_minutes' => array_slice($downtime, 0, 20), // Return last 20 downtime minutes
            'total_downtime' => count($downtime),
            'total_runs' => count($executedMinutes),
        ]);
    }

    /**
     * Force run downloader
     */
    public function forceRun()
    {
        // Check if authenticated
        if (!session('monitor_authenticated')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        shell_exec('php ' . base_path('artisan') . ' downloader:check-and-run --force &');

        return response()->json(['message' => 'Script started in background']);
    }

    /**
     * Helper methods
     */
    private function getDownloadCount($basePath)
    {
        $file = $basePath . '/downloaded_resumes.txt';
        return file_exists($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    private function getFetchedCount($basePath)
    {
        $file = $basePath . '/fetched_resume_ids.txt';
        return file_exists($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    private function getPagesCount($basePath)
    {
        $file = $basePath . '/fetched_pages_progress.txt';
        return file_exists($file) ? (int)trim(file_get_contents($file)) : 0;
    }

    private function isCronHealthy($basePath)
    {
        $file = $basePath . '/.cron_heartbeat';
        if (!file_exists($file)) {
            return false;
        }
        $lastTime = strtotime(trim(file_get_contents($file)));
        return (time() - $lastTime) < 120;
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
