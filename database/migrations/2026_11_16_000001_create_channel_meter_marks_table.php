<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration.
     *
     * Moves the per-channel out-of-order high-water mark off the period rows
     * into its own table. On a period row the mark only exists once a channel
     * has a period, so a late `channel_occupied` retried after a
     * `channel_vacated` on a fresh channel had nothing to be checked against
     * and opened a period nothing would close. One row per (app_id, channel)
     * exists from the first event on, and gives the handler a row to lock.
     *
     * Existing channels are seeded with the newest `last_event_ms` recorded on
     * any of their periods, so the guard carries on where it left off.
     */
    public function up(): void
    {
        Schema::create('channel_meter_marks', function (Blueprint $table) {
            $table->id();
            $table->string('app_id');
            $table->string('channel');
            $table->unsignedBigInteger('last_event_ms')->nullable();

            $table->unique(['app_id', 'channel'], 'channel_meter_marks_channel_unique');
        });

        DB::table('channel_meter_marks')->insertUsing(
            ['app_id', 'channel', 'last_event_ms'],
            fn (Builder $query) => $query
                ->from('channel_meter_periods')
                ->select('app_id', 'channel')
                ->selectRaw('max(last_event_ms)')
                ->whereNotNull('last_event_ms')
                ->groupBy('app_id', 'channel'),
        );
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_meter_marks');
    }
};
