<?php

declare(strict_types=1);

namespace HonkMe\Laravel\Notifications;

use HonkMe\Accepted;
use HonkMe\Laravel\HonkManager;
use HonkMe\Message;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Notification channel "honk": via() returns ['honk'] (or HonkChannel::class) and the
 * notification implements ToHonk (toHonk() returning a HonkMessage; arrays of camelCase fields
 * and strings are accepted too). The notifiable may define routeNotificationForHonk() to pick
 * another key or server (see HonkManager::clientFor()); without it the configured key is used,
 * and returning false skips Honk for that notifiable.
 *
 * The idempotency key defaults to "notification-{id}", stable across the retries of a queued
 * notification. Make the notification ShouldQueue so web requests never wait on the network.
 */
class HonkChannel
{
    public function __construct(private readonly Container $container)
    {
    }

    public function send(object $notifiable, Notification $notification): ?Accepted
    {
        if (!method_exists($notification, 'toHonk')) {
            throw new LogicException(get_class($notification) . ' must implement ' . ToHonk::class . ' (toHonk(object $notifiable)) to use the honk channel');
        }
        $message = $notification->toHonk($notifiable);
        if ($message === null) {
            return null;
        }
        $message = match (true) {
            $message instanceof Message => $message,
            is_array($message) => Message::fromArray($message),
            is_string($message) => Message::make($message),
            default => throw new LogicException(get_class($notification) . '::toHonk() must return a HonkMessage, an array, a string or null'),
        };

        $route = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('honk', $notification) : null;
        if ($route === false) {
            return null;
        }
        // Laravel assigns the id when sending and serialises it with queued notifications, so it
        // is stable across the retries of one notification.
        $id = (string) $notification->id;
        $key = $message->idempotencyKey ?? ($id !== '' ? 'notification-' . $id : null);

        // Resolved per send so Honk::fake() is honoured even when the channel was created earlier.
        return $this->container->make(HonkManager::class)->sendTo($route, $message, $key);
    }
}
