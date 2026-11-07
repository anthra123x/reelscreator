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
        Schema::create('videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('source_url');
            $table->string('title', 500)->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('file_path', 1024)->nullable();
            $table->double('duration')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_videos_user_id');
            $table->index('status', 'idx_videos_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
