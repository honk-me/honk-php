<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

#[Signature('honk:install {--force : Overwrite config/honk.php if it exists}')]
#[Description('Publish config/honk.php and add HONK_URL / HONK_KEY placeholders to .env.example')]
final class InstallCommand extends Command
{
    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'honk-config', '--force' => (bool) $this->option('force')]);

        $example = $this->laravel->basePath('.env.example');
        $existing = $files->exists($example) ? $files->get($example) : '';
        $missing = array_filter(
            ['HONK_URL' => 'HONK_URL=', 'HONK_KEY' => 'HONK_KEY='],
            static fn (string $name) => preg_match('/^' . $name . '=/m', $existing) !== 1,
            ARRAY_FILTER_USE_KEY,
        );
        if ($missing !== []) {
            $block = ($existing === '' || str_ends_with($existing, "\n") ? '' : "\n") . "\n" . implode("\n", $missing) . "\n";
            $files->append($example, $block);
            $this->components->info('Added ' . implode(', ', array_keys($missing)) . ' to .env.example (empty placeholders, no secrets).');
        } else {
            $this->components->info('.env.example already lists HONK_URL and HONK_KEY.');
        }

        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  1. Put your server and a project ingestion key in <comment>.env</comment> (never commit it):');
        $this->line('       HONK_URL=https://honk.example.com');
        $this->line('       HONK_KEY=honk_…');
        $this->line('  2. Check the connection: <comment>php artisan honk:test</comment>');
        $this->line('  3. Notify yourself: a notification with via() [\'honk\'] and toHonk(), or Honk::defer()->light(…)');
        $this->line('  4. Optional: $exceptions->report(Honk::reportable()) in bootstrap/app.php,');
        $this->line('     ->honkOnFailure() on scheduled tasks. See the package README.');

        return self::SUCCESS;
    }
}
