<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Notifications;

use HonkMe\Message;

/**
 * Implement on notifications that use the honk channel. Implementations may narrow the return
 * type, e.g. `public function toHonk(object $notifiable): HonkMessage`. Return null to skip.
 */
interface ToHonk
{
    public function toHonk(object $notifiable): ?Message;
}
