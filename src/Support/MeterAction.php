<?php

namespace Webpatser\ResonateChannelMeter\Support;

/**
 * The transition a {@see MeterDecision} asks the handler to apply to a
 * channel's metering state.
 */
enum MeterAction
{
    /** Open a fresh period: the channel just became fully occupied. */
    case OpenPeriod;

    /** Record the moment the count dropped below the threshold (start grace). */
    case MarkBelowSince;

    /** Forget a recorded drop: the count recovered to the threshold. */
    case ClearBelowSince;

    /** Close the open period (grace has elapsed). */
    case ClosePeriod;

    /** Nothing to do. */
    case NoOp;
}
