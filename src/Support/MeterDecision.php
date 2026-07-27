<?php

namespace Webpatser\ResonateChannelMeter\Support;

use Illuminate\Support\Carbon;

/**
 * The pure decision at the heart of fully-occupied metering.
 *
 * Given the current metering state of one channel (is a period open, when did
 * the count last drop below the threshold) and the live member count, it
 * decides the single transition to apply. It performs no I/O, so the open /
 * close / grace rules can be unit-tested exhaustively in isolation from the
 * webhook plumbing, the database, and the membership source.
 */
final readonly class MeterDecision
{
    public function __construct(
        public MeterAction $action,
        public ?Carbon $endedAt = null,
    ) {}

    /**
     * Decide what to do for a channel right now.
     *
     * @param  bool  $isOpen  whether a period is currently open for the channel
     * @param  ?Carbon  $belowSince  when the count last dropped below the threshold, if it is below
     * @param  int  $memberCount  the live distinct member count
     * @param  int  $minMembers  members required to keep a period open
     * @param  int  $graceSeconds  how long to hold an open period below the threshold
     * @param  Carbon  $now  the moment to evaluate against
     */
    public static function for(
        bool $isOpen,
        ?Carbon $belowSince,
        int $memberCount,
        int $minMembers,
        int $graceSeconds,
        Carbon $now,
    ): self {
        if ($memberCount >= $minMembers) {
            if (! $isOpen) {
                return new self(MeterAction::OpenPeriod);
            }

            if ($belowSince !== null) {
                return new self(MeterAction::ClearBelowSince);
            }

            return new self(MeterAction::NoOp);
        }

        // Below the threshold.
        if (! $isOpen) {
            return new self(MeterAction::NoOp);
        }

        $effectiveBelowSince = $belowSince ?? $now;
        $deadline = $effectiveBelowSince->copy()->addSeconds($graceSeconds);

        if ($now >= $deadline) {
            return new self(MeterAction::ClosePeriod, $deadline);
        }

        if ($belowSince === null) {
            return new self(MeterAction::MarkBelowSince, $now);
        }

        return new self(MeterAction::NoOp);
    }
}
