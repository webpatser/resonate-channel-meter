<?php

namespace Webpatser\ResonateChannelMeter;

use Illuminate\Support\ServiceProvider;
use Webpatser\ResonateChannelMeter\Console\SweepCommand;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\Resolvers\ChannelResolver;
use Webpatser\ResonateChannelMeter\Resolvers\ConfigChannelResolver;

/**
 * Wires the channel meter into a host Laravel application.
 *
 * The package does not register its own route: a host registers the
 * controller wherever it wants, with the signature-verification middleware.
 */
class ChannelMeterServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/resonate-channel-meter.php', 'resonate-channel-meter');

        $this->app->bind(ChannelResolver::class, function ($app) {
            return new ConfigChannelResolver(
                (array) $app['config']->get('resonate-channel-meter.patterns', [])
            );
        });

        // The default counter never gates: fully-occupied metering needs a host
        // to bind a real MembershipCounter (e.g. one backed by the roster).
        $this->app->bindIf(MembershipCounter::class, NullMembershipCounter::class);
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SweepCommand::class]);

            $this->publishes([
                __DIR__.'/../config/resonate-channel-meter.php' => $this->app->configPath('resonate-channel-meter.php'),
            ], 'resonate-channel-meter-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'resonate-channel-meter-migrations');
        }
    }
}
