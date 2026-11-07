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
        Schema::create('clips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('file_path', 1024);
            $table->string('thumbnail_path', 1024)->nullable();
            $table->string('title', 500)->nullable();
            $table->double('start_time');
            $table->double('end_time');
            $table->double('duration');
            $table->string('status', 50)->default('ready');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index('video_id', 'idx_clips_video_id');
            $table->index('status', 'idx_clips_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
