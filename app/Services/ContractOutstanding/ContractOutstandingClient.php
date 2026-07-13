<?php

namespace App\Services\ContractOutstanding;

use App\Data\ContractOutstandingResult;
use App\Models\ApiIntegrationLog;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class ContractOutstandingClient
{
    public function inquire(
        string $accountNumber,
        CarbonInterface|string $asOf,
        ?Model $related = null,
        ?User $requestedBy = null,
    ): ContractOutstandingResult {
        $baseUrl = trim((string) config('services.contract_outstanding.base_url'));
        $endpoint = trim((string) config('services.contract_outstanding.endpoint'));
        $token = trim((string) config('services.contract_outstanding.token'));

        if ($baseUrl === '' || $endpoint === '' || $token === '') {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding configuration is incomplete.',
            ]);
        }

        $requestedAt = now();
        $startedAt = hrtime(true);
        $asOfDate = $asOf instanceof CarbonInterface
            ? $asOf->toDateString()
            : CarbonImmutable::parse($asOf)->toDateString();
        $payload = [
            'account_number' => $accountNumber,
            'as_of' => $asOfDate,
        ];
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$token}",
        ];
        $status = null;
        $responseBody = null;
        $responseForLog = [];
        $errorMessage = null;
        $decoded = null;

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('services.contract_outstanding.timeout', 30))
                ->retry(
                    (int) config('services.contract_outstanding.retry_times', 0),
                    (int) config('services.contract_outstanding.retry_sleep_ms', 250),
                )
                ->post($this->url($baseUrl, $endpoint), $payload);

            $status = $response->status();
            $responseBody = $response->body();
            $decoded = $this->decodeResponse($responseBody);
            $responseForLog = $decoded;
        } catch (JsonException $exception) {
            $errorMessage = 'Malformed JSON response from Contract Outstanding API.';
            $responseForLog = ['raw' => $responseBody];
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }

        $completedAt = now();
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $isHttpSuccess = $status !== null && $status >= 200 && $status < 300 && $errorMessage === null;
        $log = ApiIntegrationLog::query()->create([
            'service_name' => 'contract_outstanding',
            'endpoint' => $endpoint,
            'method' => 'POST',
            'request_headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'request_body' => $payload,
            'response_status' => $status,
            'response_body' => is_array($responseForLog) ? $responseForLog : [],
            'is_success' => false,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'requested_by' => $requestedBy?->id,
            'requested_at' => $requestedAt,
            'completed_at' => $completedAt,
        ]);

        if (! $isHttpSuccess || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => $errorMessage ?: 'Contract Outstanding API failed.',
            ]);
        }

        try {
            $result = $this->parseResult($decoded, $accountNumber, $asOfDate, $log);
        } catch (Throwable $exception) {
            $log->forceFill(['error_message' => $exception->getMessage()])->save();

            throw $exception;
        }

        $log->forceFill(['is_success' => true])->save();

        return $result;
    }

    private function parseResult(array $decoded, string $accountNumber, string $asOfDate, ApiIntegrationLog $log): ContractOutstandingResult
    {
        $body = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $result = $body['result'] ?? null;
        $loan = $body['loan'] ?? [];

        if (! is_array($result)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding response must include result.',
            ]);
        }

        $bakiDebet = $this->bakiDebet($result['BakiDebet'] ?? null);
        $this->assertAccountMatches($accountNumber, $result['AccountNumber'] ?? null, 'result.AccountNumber');
        $this->assertAccountMatches($accountNumber, is_array($loan) ? ($loan['AccountNumber'] ?? null) : null, 'loan.AccountNumber');
        $returnedAsOf = $this->asOfDate($result['AsOf'] ?? null);

        return new ContractOutstandingResult(
            accountNumber: $accountNumber,
            requestedAsOf: $asOfDate,
            returnedAsOf: $returnedAsOf,
            bakiDebet: $bakiDebet,
            loanProduct: is_array($loan) ? $this->stringValue($loan['Product'] ?? null) : null,
            loanAccountNumber: is_array($loan) ? $this->stringValue($loan['AccountNumber'] ?? null) : null,
            loanAltNumber: is_array($loan) ? $this->stringValue($loan['AltNumber'] ?? null) : null,
            apiLogId: $log->id,
        );
    }

    private function bakiDebet(mixed $value): BigDecimal
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding response must include result.BakiDebet.',
            ]);
        }

        if (is_float($value)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding API must return BakiDebet as an integer or numeric string to avoid precision loss.',
            ]);
        }

        if (! is_int($value) && ! is_string($value)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding BakiDebet must be numeric.',
            ]);
        }

        $normalized = trim((string) $value);

        if (preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding BakiDebet must be a non-negative integer or numeric string.',
            ]);
        }

        $decimal = BigDecimal::of($normalized);

        if ($decimal->isLessThan('0')) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding BakiDebet cannot be negative.',
            ]);
        }

        return $decimal;
    }

    private function assertAccountMatches(string $requested, mixed $returned, string $field): void
    {
        if ($returned === null || $returned === '') {
            return;
        }

        if (trim((string) $returned) !== $requested) {
            throw ValidationException::withMessages([
                'contract_outstanding' => "Contract Outstanding {$field} does not match requested account.",
            ]);
        }
    }

    private function asOfDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding result.AsOf is not parseable.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(?string $body): array
    {
        if ($body === null || $body === '') {
            throw new JsonException('Empty JSON response.');
        }

        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('JSON response must be an object.');
        }

        return $decoded;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function url(string $baseUrl, string $endpoint): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($endpoint, '/');
    }
}
