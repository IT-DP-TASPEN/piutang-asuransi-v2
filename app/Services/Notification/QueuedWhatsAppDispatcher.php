<?php

namespace App\Services\Notification;

use App\Jobs\SendWhatsAppMessageJob;
use Illuminate\Support\Facades\Log;

class QueuedWhatsAppDispatcher
{
    public function dispatch(string $to, string $message, array $meta = []): void
    {
        SendWhatsAppMessageJob::dispatch($to, $message, $meta)
            ->onQueue(config('services.whatsapp.queue', 'whatsapp'));

        Log::info('WhatsApp message queued.', [
            'to' => $this->maskPhone($to),
            'type' => $meta['type'] ?? null,
            'meta_keys' => array_keys($meta),
        ]);
    }

    protected function maskPhone(string $phone): string
    {
        $normalized = str_replace('@s.whatsapp.net', '', $phone);

        if (strlen($normalized) <= 4) {
            return str_repeat('*', strlen($normalized));
        }

        return str_repeat('*', max(strlen($normalized) - 4, 1)) . substr($normalized, -4);
    }
}
