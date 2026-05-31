<?php

namespace App\Services\CoreBanking;

use App\Models\ApiIntegrationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class CoreBankingClient
{
    public function __construct(
        private readonly CoreBankingSignatureGenerator $signatureGenerator,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     status: int|null,
     *     response_code: string|null,
     *     description: string|null,
     *     data: array<string, mixed>,
     *     raw_body: string|null,
     *     log_id: int|null
     * }
     */
    public function inquireLoan(string $accountNumber, ?Model $related = null, ?User $requestedBy = null): array
    {
        return $this->post(
            endpoint: '/inquiry/detail/loan',
            payload: ['accountNumber' => $accountNumber],
            related: $related,
            requestedBy: $requestedBy,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     ok: bool,
     *     status: int|null,
     *     response_code: string|null,
     *     description: string|null,
     *     data: array<string, mixed>,
     *     raw_body: string|null,
     *     log_id: int|null
     * }
     */
    private function post(string $endpoint, array $payload, ?Model $related, ?User $requestedBy): array
    {
        $rawBody = $this->encodePayload($payload);
        $signature = $this->signatureGenerator->generate($rawBody);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Signature' => $signature,
        ];

        $status = null;
        $responseBody = null;
        $responseCode = null;
        $description = null;
        $data = [];
        $errorMessage = null;

        try {
            $response = Http::withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post($this->url($endpoint));

            $status = $response->status();
            $responseBody = $response->body();
            $decoded = $this->decodeResponse($responseBody);
            $responseCode = isset($decoded['responseCode']) ? (string) $decoded['responseCode'] : null;
            $description = isset($decoded['description']) ? (string) $decoded['description'] : null;
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }

        $ok = $status !== null
            && $status >= 200
            && $status < 300
            && $responseCode === '00'
            && $errorMessage === null;

        $log = ApiIntegrationLog::query()->create([
            'service_name' => 'core_banking',
            'endpoint' => $endpoint,
            'method' => 'POST',
            'request_headers' => $this->maskedHeaders($headers),
            'request_body' => $rawBody,
            'response_status' => $status,
            'response_body' => $responseBody,
            'response_code' => $responseCode,
            'response_description' => $description,
            'is_success' => $ok,
            'error_message' => $errorMessage,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'requested_by' => $requestedBy?->id,
            'requested_at' => now(),
        ]);

        return [
            'ok' => $ok,
            'status' => $status,
            'response_code' => $responseCode,
            'description' => $description,
            'data' => $data,
            'raw_body' => $responseBody,
            'log_id' => $log->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(?string $responseBody): array
    {
        if ($responseBody === null || $responseBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function url(string $endpoint): string
    {
        return rtrim((string) config('core_banking.base_url'), '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function maskedHeaders(array $headers): array
    {
        return [
            ...$headers,
            'Signature' => '[masked]',
        ];
    }
}
