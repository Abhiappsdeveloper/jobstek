<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resume_s3_urls', function (Blueprint $table) {
            $table->id();
            $table->string('resume_id')->unique()->index();  // TekJobs resume ID
            $table->string('filename');                       // Downloaded filename
            $table->longText('s3_url');                       // S3 URL for recovery
            $table->integer('file_size')->nullable();         // File size in bytes
            $table->string('status')->default('downloaded');  // Status: downloaded, recovered, pending
            $table->timestamp('downloaded_at')->nullable();   // When downloaded
            $table->timestamp('recovered_at')->nullable();    // When recovered
            $table->text('notes')->nullable();                // Any notes
            $table->timestamps();                             // created_at, updated_at

            // Indexes for querying
            $table->index('status');
            $table->index('downloaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_s3_urls');
    }
};
