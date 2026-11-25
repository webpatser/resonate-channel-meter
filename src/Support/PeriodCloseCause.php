<?php

namespace Webpatser\ResonateChannelMeter\Support;

use Webpatser\ResonateChannelMeter\Events\PeriodClosed;

/**
 * Why the handler closed a period, carried on {@see PeriodClosed}.
 */
enum PeriodCloseCause: string
{
    /** A membership webhook found the channel below the threshold past its grace deadline. */
    case GraceExpired = 'grace_expired';

    /** `channel-meter:sweep` found the channel below the threshold past its grace deadline. */
    case SweepGraceExpired = 'sweep_grace_expired';

    /** A `channel_vacated` webhook closed the period (room-occupancy mode). */
    case ChannelVacated = 'channel_vacated';
}
