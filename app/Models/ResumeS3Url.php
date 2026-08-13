<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ResumeS3Url extends Model
{
    use HasFactory;

    protected $table = 'resume_s3_urls';

    protected $fillable = [
        'resume_id',
        'filename',
        's3_url',
        'file_size',
        'status',
        'downloaded_at',
        'recovered_at',
        'notes',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'recovered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope: Get all downloaded resumes
     */
    public function scopeDownloaded($query)
    {
        return $query->where('status', 'downloaded');
    }

    /**
     * Scope: Get recovered resumes
     */
    public function scopeRecovered($query)
    {
        return $query->where('status', 'recovered');
    }

    /**
     * Scope: Get resumes by date range
     */
    public function scopeDateRange($query, $start, $end)
    {
        return $query->whereBetween('downloaded_at', [$start, $end]);
    }

    /**
     * Get resume by ID
     */
    public static function findByResumeId($resume_id)
    {
        return self::where('resume_id', $resume_id)->first();
    }

    /**
     * Store or update S3 URL
     */
    public static function storeOrUpdate($resume_id, $filename, $s3_url, $file_size = null)
    {
        return self::updateOrCreate(
            ['resume_id' => $resume_id],
            [
                'filename' => $filename,
                's3_url' => $s3_url,
                'file_size' => $file_size,
                'status' => 'downloaded',
                'downloaded_at' => now(),
            ]
        );
    }

    /**
     * Mark as recovered
     */
    public function markAsRecovered()
    {
        $this->update([
            'status' => 'recovered',
            'recovered_at' => now(),
        ]);
        return $this;
    }

    /**
     * Get total downloaded resumes
     */
    public static function getTotalDownloaded()
    {
        return self::downloaded()->count();
    }

    /**
     * Get total size downloaded (in bytes)
     */
    public static function getTotalSize()
    {
        return self::downloaded()->sum('file_size');
    }

    /**
     * Get recently downloaded (last N hours)
     */
    public static function recentlyDownloaded($hours = 24)
    {
        return self::downloaded()
            ->where('downloaded_at', '>=', now()->subHours($hours))
            ->orderBy('downloaded_at', 'desc')
            ->get();
    }
};
