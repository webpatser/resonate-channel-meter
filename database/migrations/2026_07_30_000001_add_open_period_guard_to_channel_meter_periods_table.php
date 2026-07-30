<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration.
     *
     * Adds the two guards that keep a channel's periods sane under concurrent
     * and redelivered webhooks:
     *
     * - `open_slot`: a nullable discriminator, 1 while the period is open and
     *   NULL once it is closed. Every supported driver (SQLite, MySQL,
     *   MariaDB, PostgreSQL, SQL Server) treats NULLs as distinct in a unique
     *   index, so a unique index on (app_id, channel, open_slot) allows any
     *   number of closed periods per channel but exactly one open one. A
     *   partial index (`WHERE ended_at IS NULL`) or a generated column would
     *   do the same job on fewer drivers.
     * - `last_event_ms`: the envelope `time_ms` of the newest event applied to
     *   the channel, the per-channel high-water mark that lets the handler
     *   drop out-of-order and redelivered events.
     */
    public function up(): void
    {
        Schema::table('channel_meter_periods', function (Blueprint $table) {
            $table->unsignedTinyInteger('open_slot')->nullable()->after('ended_at');
            $table->unsignedBigInteger('last_event_ms')->nullable()->after('open_slot');
        });

        $this->closeDuplicateOpenPeriods();

        DB::table('channel_meter_periods')->whereNull('ended_at')->update(['open_slot' => 1]);

        Schema::table('channel_meter_periods', function (Blueprint $table) {
            $table->unique(['app_id', 'channel', 'open_slot'], 'channel_meter_periods_open_unique');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('channel_meter_periods', function (Blueprint $table) {
            $table->dropUnique('channel_meter_periods_open_unique');
            $table->dropColumn(['open_slot', 'last_event_ms']);
        });
    }

    /**
     * Resolve the duplicates the old unlocked open() could already have left.
     *
     * Per (app_id, channel) the earliest open period stays open; the extras
     * are closed at their own `started_at`, so they contribute zero billable
     * seconds instead of the runaway time they were accumulating.
     */
    private function closeDuplicateOpenPeriods(): void
    {
        $duplicates = DB::table('channel_meter_periods')
            ->select('app_id', 'channel')
            ->whereNull('ended_at')
            ->groupBy('app_id', 'channel')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keep = DB::table('channel_meter_periods')
                ->where('app_id', $duplicate->app_id)
                ->where('channel', $duplicate->channel)
                ->whereNull('ended_at')
                ->orderBy('started_at')
                ->orderBy('id')
                ->value('id');

            if ($keep === null) {
                continue;
            }

            DB::table('channel_meter_periods')
                ->where('app_id', $duplicate->app_id)
                ->where('channel', $duplicate->channel)
                ->whereNull('ended_at')
                ->where('id', '!=', $keep)
                ->update(['ended_at' => DB::raw('started_at')]);
        }
    }
};
