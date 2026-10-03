<?php

declare(strict_types=1);

namespace HonkMe\Laravel;

use HonkMe\Client;
use HonkMe\Laravel\Console\InstallCommand;
use HonkMe\Laravel\Console\TestCommand;
use HonkMe\Laravel\Jobs\SendHonkMessage;
use HonkMe\Laravel\Notifications\HonkChannel;
use HonkMe\Laravel\Scheduling\ScheduleHonk;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered (composer.json extra.laravel). HonkManager is a #[Singleton] and needs no
 * binding; this provider merges the config, registers the "honk" notification channel, the
 * scheduler macros, the Artisan commands, the queue route and the `php artisan about` section.
 */
final class HonkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/honk.php', 'honk');
        $this->app->alias(HonkManager::class, 'honk');
        $this->app->bind(Client::class, static fn (Application $app) => $app->make(HonkManager::class)->client());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/honk.php' => $this->app->configPath('honk.php')], 'honk-config');
            $this->commands([InstallCommand::class, TestCommand::class]);
        }

        Notification::resolved(static function (ChannelManager $channels): void {
            $channels->extend('honk', fn (Application $app) => $app->make(HonkChannel::class));
        });

        $connection = config('honk.queue.connection');
        $queue = config('honk.queue.queue');
        if (is_string($connection) || is_string($queue)) {
            Queue::route(SendHonkMessage::class, is_string($queue) ? $queue : null, is_string($connection) ? $connection : null);
        }

        ScheduleHonk::register();

        AboutCommand::add('Honk', static fn () => self::about());
    }

    /** @return array<string, string> */
    private static function about(): array
    {
        $key = config('honk.key');
        $url = config('honk.url');
        $connection = config('honk.queue.connection');
        $queue = config('honk.queue.queue');

        return [
            'Version' => Client::VERSION,
            'URL' => is_string($url) && $url !== '' ? $url : '<fg=yellow;options=bold>not set (HONK_URL)</>',
            // Only the public prefix (honk_<id>), never the secret.
            'Key' => is_string($key) && preg_match('/^(honk_[a-z0-9]+)_/', $key, $m) === 1 ? $m[1] . '_…' : '<fg=yellow;options=bold>not set (HONK_KEY)</>',
            'Queue' => (is_string($connection) ? $connection : 'default') . ' / ' . (is_string($queue) ? $queue : 'default'),
            'Context metadata' => config('honk.context.enabled') === true ? 'ON' : 'OFF',
        ];
    }
}
