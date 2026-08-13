<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Log;

class DownloadResumes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resumes:download
                            {--force : Force download even if files exist}
                            {--verbose : Show detailed output}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Download resumes from TekJobs and store in Laravel storage';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $this->info('Starting resume download process...');
            $this->line('');

            // Get Python script path
            $pythonScript = base_path('http_downloader_hostinger.py');

            if (!file_exists($pythonScript)) {
                $this->error('Python script not found: ' . $pythonScript);
                Log::error('Resume download failed: Script not found at ' . $pythonScript);
                return 1;
            }

            // Prepare command
            $command = ['python3', $pythonScript];

            if ($this->option('force')) {
                $this->line('Force mode: Will download even if files exist');
            }

            // Run Python script as process
            $process = new Process($command);
            $process->setTimeout(3600); // 1 hour timeout
            $process->setWorkingDirectory(base_path());

            $this->line('Executing: ' . implode(' ', $command));
            $this->line('');
            $this->line('Process started at: ' . now()->format('Y-m-d H:i:s'));
            $this->line(str_repeat('-', 70));

            // Show output in real-time
            $process->run(function ($type, $buffer) {
                $this->line(rtrim($buffer));
            });

            $this->line(str_repeat('-', 70));
            $this->line('Process completed at: ' . now()->format('Y-m-d H:i:s'));
            $this->line('');

            // Check result
            if ($process->isSuccessful()) {
                $this->info('✓ Resume download completed successfully!');

                // Show statistics
                $this->showStatistics();

                Log::info('Resume download completed successfully via artisan command');
                return 0;
            } else {
                $this->error('✗ Resume download process failed');
                $this->error('Exit code: ' . $process->getExitCode());
                $this->error('Error output: ' . $process->getErrorOutput());

                Log::error('Resume download failed', [
                    'exit_code' => $process->getExitCode(),
                    'error' => $process->getErrorOutput()
                ]);
                return 1;
            }

        } catch (ProcessFailedException $e) {
            $this->error('Process execution failed: ' . $e->getMessage());
            Log::error('Resume download process failed', ['error' => $e->getMessage()]);
            return 1;
        } catch (\Exception $e) {
            $this->error('Unexpected error: ' . $e->getMessage());
            Log::error('Unexpected error in resume download', ['error' => $e->getMessage()]);
            return 1;
        }
    }

    /**
     * Show download statistics
     *
     * @return void
     */
    protected function showStatistics()
    {
        try {
            $storagePath = storage_path('resumes');

            if (!is_dir($storagePath)) {
                return;
            }

            $files = array_diff(scandir($storagePath), ['.', '..']);
            $resumeFiles = array_filter($files, function ($file) {
                return in_array(pathinfo($file, PATHINFO_EXTENSION), ['pdf', 'doc', 'docx']);
            });

            $totalSize = 0;
            foreach ($resumeFiles as $file) {
                $totalSize += filesize($storagePath . '/' . $file);
            }

            $this->line('');
            $this->info('Statistics:');
            $this->line('  Total resumes: ' . count($resumeFiles));
            $this->line('  Total size: ' . $this->formatBytes($totalSize));
            $this->line('  Storage path: ' . $storagePath);

        } catch (\Exception $e) {
            // Silently fail statistics
        }
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
