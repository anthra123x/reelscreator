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
        Schema::create('publishing_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->foreignUuid('clip_id')->constrained('clips')->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->string('platform', 50);
            $table->string('event', 50);
            $table->string('status', 50);
            $table->jsonb('payload')->default('{}');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('publication_id', 'idx_publishing_logs_pub');
            $table->index('clip_id', 'idx_publishing_logs_clip');
            $table->index('social_account_id', 'idx_publishing_logs_acc');
            $table->index(['platform', 'event'], 'idx_publishing_logs_platform_event');
            $table->index(['status', 'created_at'], 'idx_publishing_logs_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publishing_logs');
    }
};
