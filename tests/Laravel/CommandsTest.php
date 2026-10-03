<?php

declare(strict_types=1);

namespace HonkMe\Tests\Laravel;

use HonkMe\Client;
use HonkMe\Tests\Support\MockServer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

final class CommandsTest extends TestCase
{
    public function testInstallPublishesConfigAndAddsPlaceholdersToEnvExample(): void
    {
        $config = config_path('honk.php');
        $example = base_path('.env.example');
        $original = File::exists($example) ? File::get($example) : null;
        File::delete($config);
        File::put($example, "APP_NAME=Laravel\n");
        try {
            $this->artisan('honk:install')->assertSuccessful()->expectsOutputToContain('php artisan honk:test');
            $this->assertFileExists($config);
            $this->assertSame("APP_NAME=Laravel\n\nHONK_URL=\nHONK_KEY=\n", File::get($example));

            $this->artisan('honk:install')->assertSuccessful();  // idempotent
            $this->assertSame("APP_NAME=Laravel\n\nHONK_URL=\nHONK_KEY=\n", File::get($example));
        } finally {
            File::delete($config);
            $original === null ? File::delete($example) : File::put($example, $original);
        }
    }

    public function testTestCommandSendsALightHonk(): void
    {
        $this->artisan('honk:test')->assertSuccessful()->expectsOutputToContain('Accepted msg_01k6h3w4z5x6y7z8a9b0c1d2e3 (light honk)');
        $body = json_decode($this->server->requests()[0]['body'], true);
        $this->assertSame('info', $body['severity']);
        $this->assertSame('honk-test', $body['channel']);
    }

    public function testTestCommandReportsFailures(): void
    {
        $this->server->script([MockServer::error(401, 'invalid_key')]);
        $this->artisan('honk:test', ['--severity' => 'blast'])->assertFailed()->expectsOutputToContain('invalid_key');
        $this->artisan('honk:test', ['--severity' => 'fatal'])->assertExitCode(2);
    }

    public function testAboutShowsUrlAndOnlyTheKeyPrefix(): void
    {
        Artisan::call('about', ['--only' => 'honk', '--json' => true]);
        $about = json_decode(Artisan::output(), true);
        $this->assertSame(Client::VERSION, $about['honk']['version']);
        $this->assertSame($this->server->url, $about['honk']['url']);
        $this->assertSame('honk_ab12cd34ef56_…', $about['honk']['key']);
        $this->assertStringNotContainsString('0123456789abcdef', Artisan::output());
    }
}
