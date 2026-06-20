<?php

namespace App\Jobs;

use App\Services\Notification\WhatsAppNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use RuntimeException;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $to,
        public string $message,
        public array $meta = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('whatsapp-global'),
        ];
    }

    public function handle(WhatsAppNotificationService $whatsApp): void
    {
        $sent = $whatsApp->send($this->to, $this->message, $this->meta);
        if (! $sent) {
            throw new RuntimeException('Failed to deliver WhatsApp message from queue.');
        }
    }
}
