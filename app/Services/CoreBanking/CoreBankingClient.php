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
     *     log_id: int|null,
     *     error_message: string|null
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
     * @return array{
     *     ok: bool,
     *     status: int|null,
     *     response_code: string|null,
     *     description: string|null,
     *     data: array<string, mixed>,
     *     raw_body: string|null,
     *     log_id: int|null,
     *     error_message: string|null
     * }
     */
    public function inquireBalance(string $account, ?Model $related = null, ?User $requestedBy = null): array
    {
        $endpoint = '/saving/inq/balance';
        $query = ['accountNumber' => $account];
        $headers = ['Accept' => 'application/json'];
        $status = null;
        $responseBody = null;
        $responseCode = null;
        $description = null;
        $data = [];
        $errorMessage = null;
        $responseBodyForLog = [];

        try {
            $response = Http::withHeaders($headers)->get($this->url($endpoint), $query);

            $status = $response->status();
            $responseBody = $response->body();
            $decoded = $this->decodeResponse($responseBody);
            $responseBodyForLog = $this->responseBodyForLog($responseBody);
            $responseCode = isset($decoded['responseCode']) ? (string) $decoded['responseCode'] : null;
            $description = isset($decoded['description']) ? (string) $decoded['description'] : null;
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }

        $ok = $responseCode === '00' && $errorMessage === null;

        $log = ApiIntegrationLog::query()->create([
            'service_name' => 'core_banking',
            'endpoint' => $endpoint,
            'method' => 'GET',
            'request_headers' => $headers,
            'request_body' => $query,
            'response_status' => $status,
            'response_body' => $responseBodyForLog,
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
            'error_message' => $errorMessage,
        ];
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
     *     log_id: int|null,
     *     error_message: string|null
     * }
     */
    public function earlyTerminateLoan(array $payload, ?Model $related = null, ?User $requestedBy = null): array
    {
        return $this->post(
            endpoint: '/loan/earlytermination/',
            payload: $payload,
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
     *     log_id: int|null,
     *     error_message: string|null
     * }
     */
    public function transferGlToGl(array $payload, ?Model $related = null, ?User $requestedBy = null): array
    {
        return $this->post(
            endpoint: '/trx/transfer/gl-to-gl',
            payload: $payload,
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
     *     log_id: int|null,
     *     error_message: string|null
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
        $responseBodyForLog = [];

        try {
            $response = Http::withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post($this->url($endpoint));

            $status = $response->status();
            $responseBody = $response->body();
            $decoded = $this->decodeResponse($responseBody);
            $responseBodyForLog = $this->responseBodyForLog($responseBody);
            $responseCode = isset($decoded['responseCode']) ? (string) $decoded['responseCode'] : null;
            $description = isset($decoded['description']) ? (string) $decoded['description'] : null;
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
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
            'request_body' => $payload,
            'response_status' => $status,
            'response_body' => $responseBodyForLog,
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
            'error_message' => $errorMessage,
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

    /**
     * @return array<string, mixed>
     */
    private function responseBodyForLog(?string $responseBody): array
    {
        if ($responseBody === null || $responseBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['raw' => $responseBody];
        }

        return is_array($decoded) ? $decoded : ['raw' => $responseBody];
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
