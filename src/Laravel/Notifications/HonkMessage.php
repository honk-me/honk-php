<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Notifications;

use HonkMe\Message;

/**
 * The message a notification's toHonk() returns. Same fluent API as HonkMe\Message.
 *
 *     public function toHonk(object $notifiable): HonkMessage
 *     {
 *         return HonkMessage::create("{$this->request->name} asked for a quote")
 *             ->title('New request')
 *             ->light()
 *             ->groupKey("requests/{$this->request->id}");
 *     }
 */
class HonkMessage extends Message
{
}
