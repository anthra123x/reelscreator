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
        Schema::create('publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clip_id')->constrained('clips')->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->string('status', 50)->default('pending');
            $table->string('platform_post_id', 255)->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('clip_id', 'idx_publications_clip');
            $table->index('social_account_id', 'idx_publications_account');
            $table->index(['status', 'scheduled_at'], 'idx_publications_sched');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
