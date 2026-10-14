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
                'phone' => $this->maskPhone($to),
            ]);

            return false;
        }

        if (config('services.whatsapp.enabled') === false) {
            Log::info('WhatsApp notification disabled by config.', [
                'phone' => $this->maskPhone($to),
            ]);

            return false;
        }

        if (! str_ends_with($to, '@s.whatsapp.net')) {
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

            $response = $request->post($endpoint.'/send/message', $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('WhatsApp notification failed.', [
                'status' => $response->status(),
                'phone' => $this->maskPhone($to),
            ]);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp notification error.', [
                'error' => $exception->getMessage(),
                'phone' => $this->maskPhone($to),
            ]);
        }

        return false;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return '***'.substr($digits, -4);
    }
}
