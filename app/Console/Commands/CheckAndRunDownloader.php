<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAndRunDownloader extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'downloader:check-and-run {--force : Force run even if cron is working}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Check if cron job is running, auto-restart if stuck. Bulletproof fallback.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $basePath = base_path();
            $heartbeatFile = storage_path('resumes/.cron_heartbeat');
            $downloadedFile = storage_path('resumes/downloaded_resumes.txt');
            $progressFile = storage_path('resumes/fetched_pages_progress.txt');

            $this->info('=== DOWNLOADER HEALTH CHECK ===');

            // Check cron heartbeat
            $cronHealthy = false;
            $heartbeatAge = 'UNKNOWN';

            if (file_exists($heartbeatFile)) {
                $lastHeartbeat = file_get_contents($heartbeatFile);
                $lastTime = strtotime(trim($lastHeartbeat));
                $age = (time() - $lastTime) / 60;
                $heartbeatAge = round($age, 1) . ' minutes';

                if ($age < 2) {
                    $this->info("✅ Cron is HEALTHY (heartbeat age: {$heartbeatAge})");
                    $cronHealthy = true;
                } else {
                    $this->warn("⚠️  Cron may be STUCK (heartbeat age: {$heartbeatAge})");
                    $cronHealthy = false;
                }
            } else {
                $this->warn("⚠️  No heartbeat file found - cron may never run");
                $cronHealthy = false;
            }

            // Check if downloads are increasing
            $downloadCount = 0;
            if (file_exists($downloadedFile)) {
                $downloadCount = count(array_filter(explode("\n", file_get_contents($downloadedFile))));
            }

            $this->line("Downloaded resumes: {$downloadCount}");

            $pageCount = 0;
            if (file_exists($progressFile)) {
                $pageCount = (int)trim(file_get_contents($progressFile));
            }

            $this->line("Pages fetched: {$pageCount}");

            // Decide if we need to run
            $shouldRun = false;

            if ($this->option('force')) {
                $this->info('--force flag detected, running script anyway...');
                $shouldRun = true;
            } elseif (!$cronHealthy) {
                $this->warn('⚠️  Cron not healthy, will attempt to run script...');
                $shouldRun = true;
            } else {
                $this->info('✅ Cron is running fine, skipping manual run.');
                $shouldRun = false;
            }

            // Run the script if needed
            if ($shouldRun) {
                $this->line('');
                $this->info('[FALLBACK] Running http_downloader.py manually...');

                $pythonPath = '/usr/bin/python3';
                $scriptPath = $basePath . '/http_downloader.py';

                if (!file_exists($scriptPath)) {
                    $this->error("❌ Script not found: {$scriptPath}");
                    return 1;
                }

                // Set timeout to 1800 seconds (30 minutes) like cron
                $command = "timeout 1800 {$pythonPath} {$scriptPath}";

                // Run in background
                if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin') {
                    $command .= ' >> ' . storage_path('logs/resume_downloader/fallback_run.log') . ' 2>&1 &';
                }

                $this->line("Running: {$command}");
                // Use shell_exec for background execution
                shell_exec($command);

                $this->info('✅ Script started in background (check logs for output)');
                Log::info('[DOWNLOADER-FALLBACK] Manually triggered download script');

                return 0;
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            Log::error('[DOWNLOADER-CHECK] Error: ' . $e->getMessage());
            return 1;
        }
    }
}
