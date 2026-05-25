<?php

namespace Webpatser\ResonateChannelMeter\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;
use Webpatser\ResonateChannelMeter\ChannelMeterServiceProvider;
use Webpatser\ResonateChannelMeter\Http\Controllers\WebhookController;
use Webpatser\ResonateChannelMeter\Http\Middleware\VerifyPusherSignature;
use Webpatser\ResonateChannelMeter\Tests\Support\TestChat;

class TestCase extends Testbench
{
    /**
     * Get the package providers.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [ChannelMeterServiceProvider::class];
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // One configured Resonate application, the canonical test shape.
        $app['config']->set('reverb.apps.apps', [
            [
                'key' => 'app-key',
                'secret' => 'app-secret',
                'app_id' => 'app-id',
            ],
        ]);

        // Map presence-chat.{id} to the test model so the resolver has work to do.
        $app['config']->set('resonate-channel-meter.patterns', [
            'presence-chat.{id}' => TestChat::class,
        ]);
    }

    /**
     * Define the database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('test_chats', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Define the routes.
     */
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('/test-webhook', WebhookController::class)
            ->middleware(VerifyPusherSignature::class);
    }
}
