<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Console;

use HonkMe\Exception\HonkException;
use HonkMe\Laravel\HonkManager;
use HonkMe\Message;
use HonkMe\Severity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('honk:test {--severity=light : Horn or canonical severity} {--message= : Message text}')]
#[Description('Send a test message to Honk and print the result')]
final class TestCommand extends Command
{
    public function handle(HonkManager $honk): int
    {
        $option = $this->option('severity');
        $severity = Severity::tryParse(is_string($option) ? $option : '');
        if ($severity === null) {
            $this->components->error('Unknown --severity; use light, beep, loud, long or blast.');

            return self::INVALID;
        }
        $name = config('app.name');
        $app = is_string($name) && $name !== '' ? $name : 'Laravel';
        $text = $this->option('message');
        $message = Message::make(is_string($text) && $text !== '' ? $text : "Test from {$app} (php artisan honk:test).")
            ->title("Honk test · {$app}")
            ->severity($severity)
            ->channel('honk-test');

        try {
            $accepted = $honk->send($message);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (HonkException $e) {
            $this->components->error($e->getMessage());
            if ($e->isRetryable()) {
                $this->line('  The server could not be reached or is busy; it is safe to run this again.');
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('Accepted %s (%s honk%s).', $accepted->id, $severity->horn(), $accepted->duplicate ? ', duplicate' : ''));

        return self::SUCCESS;
    }
}
