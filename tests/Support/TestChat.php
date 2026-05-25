<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Webpatser\ResonateChannelMeter\Concerns\HasChannelMeter;

/**
 * A minimal domain model that opts into the channel meter, used by the
 * feature tests to exercise the polymorphic relation and the trait.
 */
class TestChat extends Model
{
    use HasChannelMeter;

    /**
     * The table associated with the model.
     */
    protected $table = 'test_chats';

    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];
}
