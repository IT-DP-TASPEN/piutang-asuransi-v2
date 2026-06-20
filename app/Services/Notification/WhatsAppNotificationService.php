<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public function send(string $to, string $message, array $meta = []): bool
    {
        $endpoint = config('services.whatsapp.endpoint');
        if (! $endpoint) {
            Log::warning('WhatsApp notification skipped: endpoint not configured.', [
                'phone' => $to,
            ]);

            return false;
        }

        if (config('services.whatsapp.enabled') === false) {
            Log::info('WhatsApp notification disabled by config.', [
                'phone' => $to,
            ]);

            return false;
        }

        if (!str_ends_with($to, '@s.whatsapp.net')) {
            $to .= '@s.whatsapp.net';
        }

        $payload = array_merge([
            'phone' => $to,
            'message' => $message,
        ], $meta);

        $timeout = (int) config('services.whatsapp.timeout', 10);
        $token = config('services.whatsapp.token');
        $deviceId = config('services.whatsapp.device_id');

        try {
            $request = Http::timeout($timeout)->acceptJson();

            if ($token) {
                $request = $request->withToken($token);
            }

            if ($deviceId) {
                $request = $request->withHeaders([
                    'X-Device-ID' => $deviceId,
                ]);
            }

            $response = $request->post($endpoint . '/send/message', $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('WhatsApp notification failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'phone' => $to,
            ]);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp notification error.', [
                'error' => $exception->getMessage(),
                'phone' => $to,
            ]);
        }

        return false;
    }
}
