<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class DemoActions
{
    public function run(string $action, Closure $callback, int $cooldown = 15): mixed
    {
        if (! config('demo.enabled')) {
            return $callback();
        }

        $lock = Cache::lock('demo:heavy-action', 300);
        if (! $lock->get()) {
            Notification::make()->warning()->title('Another demo action is running')->body('Please try again in a moment.')->send();

            return null;
        }

        try {
            $key = 'demo:action:'.$action;
            if (RateLimiter::tooManyAttempts($key, 1)) {
                $seconds = RateLimiter::availableIn($key);
                Notification::make()->warning()->title('Please wait before trying again')->body("This shared action is available again in {$seconds} seconds.")->send();

                return null;
            }
            RateLimiter::hit($key, $cooldown);

            return $callback();
        } finally {
            $lock->release();
        }
    }
}
