<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('channel_meter_periods', function (Blueprint $table) {
            $table->id();
            $table->string('app_id');
            $table->string('channel');
            // String morphs so UUIDs, slugs, and large numeric ids all fit.
            $table->string('model_type')->nullable();
            $table->string('model_id')->nullable();
            $table->index(['model_type', 'model_id']);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'channel', 'ended_at']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_meter_periods');
    }
};
