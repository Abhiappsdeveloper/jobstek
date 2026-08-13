<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ResumeS3Url;

class SyncResumesToDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resumes:sync-to-db {--file= : Specific S3 URLs text file path} {--continuous : Run continuously in background, syncing new URLs in real-time}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Sync S3 URLs from text file to database for recovery tracking';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $file = $this->option('file') ?? storage_path('resumes/resume_s3_urls.txt');
            $isContinuous = $this->option('continuous');

            if (!file_exists($file)) {
                $this->error("File not found: $file");
                $this->info("Expected location: storage/resumes/resume_s3_urls.txt");
                return 1;
            }

            // If continuous mode, run monitoring loop
            if ($isContinuous) {
                $this->runContinuousSync($file);
                return 0;
            }

            // ===== ORIGINAL SYNC LOGIC (UNCHANGED) =====
            $this->info('Starting S3 URLs sync to database...');
            $this->info("Reading from: $file");

            $synced = 0;
            $skipped = 0;
            $failed = 0;

            // Read file line by line
            $handle = fopen($file, 'r');
            if (!$handle) {
                $this->error("Could not open file: $file");
                return 1;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                // Skip comments and empty lines
                if (empty($line) || strpos($line, '#') === 0) {
                    $skipped++;
                    continue;
                }

                // Parse line format: resume_id|filename|s3_url
                $parts = explode('|', $line);
                if (count($parts) < 3) {
                    $this->warn("Skipping invalid line: $line");
                    $skipped++;
                    continue;
                }

                try {
                    $resume_id = trim($parts[0]);
                    $filename = trim($parts[1]);
                    $s3_url = trim($parts[2]);

                    if (empty($resume_id) || empty($filename) || empty($s3_url)) {
                        $skipped++;
                        continue;
                    }

                    // Store or update in database
                    ResumeS3Url::storeOrUpdate($resume_id, $filename, $s3_url);
                    $synced++;

                } catch (\Exception $e) {
                    $this->error("Error processing line: $line - " . $e->getMessage());
                    $failed++;
                }
            }

            fclose($handle);

            // Display summary
            $this->line('');
            $this->info('=== Sync Summary ===');
            $this->line("✅ Synced: $synced");
            $this->line("⏭️  Skipped: $skipped");
            $this->line("❌ Failed: $failed");
            $this->line('');

            // Show database stats
            $total = ResumeS3Url::getTotalDownloaded();
            $size = ResumeS3Url::getTotalSize();
            $size_formatted = $this->formatBytes($size);

            $this->info('=== Database Stats ===');
            $this->line("Total resumes in DB: $total");
            $this->line("Total size: $size_formatted");
            $this->line('');

            $this->info('✅ Sync complete!');
            return 0;

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Run continuous sync monitoring (NEW - doesn't modify existing logic)
     */
    private function runContinuousSync($file)
    {
        $statusFile = storage_path('resumes/.sync_status');

        $this->info('🔄 Starting continuous S3 URL sync service...');
        $this->info("Monitoring: $file");
        $this->info("Status file: $statusFile");
        $this->line('');
        $this->info('Press CTRL+C to stop');
        $this->line('');

        // Get last synced line
        $lastSyncedLine = $this->getLastSyncedLine($statusFile);
        $this->line("Starting from line: $lastSyncedLine");

        // Continuous loop - sync every 60 seconds
        while (true) {
            try {
                $lastSyncedLine = $this->syncNewLines($file, $lastSyncedLine, $statusFile);
                sleep(60);
            } catch (\Exception $e) {
                $this->error("Error: " . $e->getMessage());
                sleep(60);
            }
        }
    }

    /**
     * Get last synced line number (NEW - doesn't modify existing logic)
     */
    private function getLastSyncedLine($statusFile)
    {
        if (!file_exists($statusFile)) {
            file_put_contents($statusFile, "5\n");
            return 5;
        }
        return (int)trim(file_get_contents($statusFile)) ?: 5;
    }

    /**
     * Sync only new lines since last sync (NEW - doesn't modify existing logic)
     */
    private function syncNewLines($file, $lastSyncedLine, $statusFile)
    {
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new \Exception("Could not open file: $file");
        }

        $lineNumber = 0;
        $synced = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;

            // Skip lines already synced
            if ($lineNumber <= $lastSyncedLine) {
                continue;
            }

            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse line format: resume_id|filename|s3_url
            $parts = explode('|', $line);
            if (count($parts) < 3) {
                continue;
            }

            try {
                $resume_id = trim($parts[0]);
                $filename = trim($parts[1]);
                $s3_url = trim($parts[2]);

                if (empty($resume_id) || empty($filename) || empty($s3_url)) {
                    continue;
                }

                // Store or update in database
                ResumeS3Url::storeOrUpdate($resume_id, $filename, $s3_url);
                $synced++;

                // Show progress
                if ($synced % 10 == 0) {
                    $timestamp = date('Y-m-d H:i:s');
                    $total = ResumeS3Url::getTotalDownloaded();
                    $this->line("[$timestamp] ✅ Synced $synced new | Total in DB: $total");
                }

            } catch (\Exception $e) {
                $this->warn("Error on line $lineNumber: " . $e->getMessage());
            }
        }

        fclose($handle);

        // Update tracking file
        file_put_contents($statusFile, (string)$lineNumber);

        // Show summary if any synced
        if ($synced > 0) {
            $timestamp = date('Y-m-d H:i:s');
            $total = ResumeS3Url::getTotalDownloaded();
            $this->line("[$timestamp] 🎉 Batch complete: $synced new synced | Total: $total");
        }

        return $lineNumber;
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
