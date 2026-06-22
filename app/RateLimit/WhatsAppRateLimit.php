<?php

namespace App\RateLimit;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppRateLimit
{
    public static function onAppBoot(): void
    {
        RateLimiter::for('whatsapp-global', fn () => Limit::perMinute(
            (int) config('services.whatsapp.rate_limit_per_minute', 5)
        )->by(
            (string) config('services.whatsapp.rate_limit_key', 'global')
        ));
    }
}
