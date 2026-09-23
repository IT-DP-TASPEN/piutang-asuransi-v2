<?php

namespace App\Services\ContractOutstanding;

use App\Data\ContractOutstandingResult;
use App\Models\ApiIntegrationLog;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
        $token = trim((string) config('services.contract_outstanding.token'));
        $accountNumber = trim($accountNumber);
        $asOfDate = $asOf instanceof CarbonInterface ? $asOf->toDateString() : $asOf;

        if ($baseUrl === '' || $token === '') {
            throw ValidationException::withMessages(['contract_outstanding' => 'TRS contractual API configuration is incomplete.']);
        }

        if ($accountNumber === '' || ! $this->validDate($asOfDate)) {
            throw ValidationException::withMessages(['contract_outstanding' => 'TRS contractual request requires an account and YYYY-MM-DD business date.']);
        }

        $endpoint = '/api/v1/loans/'.rawurlencode($accountNumber).'/contractual';
        $requestedAt = now();
        $startedAt = hrtime(true);
        $status = null;
        $responseBody = null;
        $decoded = null;
        $native = null;
        $errorMessage = null;

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout((int) config('services.contract_outstanding.timeout', 30))
                ->retry(
                    1 + min(3, max(0, (int) config('services.contract_outstanding.retry_times', 1))),
                    (int) config('services.contract_outstanding.retry_sleep_ms', 250),
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()),
                    throw: false,
                )
                ->get(rtrim($baseUrl, '/').$endpoint, ['as_of' => $asOfDate]);

            $status = $response->status();
            $responseBody = $response->body();
            [$decoded, $native] = $this->decodeResponse($responseBody);
        } catch (JsonException) {
            $errorMessage = 'Malformed JSON response from TRS contractual API.';
        } catch (Throwable) {
            $errorMessage = 'TRS contractual API is unavailable.';
        }

        if ($status !== null && ($status < 200 || $status >= 300)) {
            $errorMessage = $this->httpError($status);
        }

        $code = is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
        $log = ApiIntegrationLog::create([
            'service_name' => 'contract_outstanding',
            'endpoint' => $endpoint,
            'method' => 'GET',
            'request_headers' => ['Accept' => 'application/json'],
            'request_body' => ['account' => $accountNumber, 'as_of' => $asOfDate],
            'response_status' => $status,
            'response_body' => $responseBody === null ? [] : ['raw' => $responseBody],
            'response_code' => $code,
            'is_success' => false,
            'error_message' => $errorMessage,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'requested_by' => $requestedBy?->id,
            'requested_at' => $requestedAt,
            'completed_at' => now(),
        ]);

        if ($errorMessage !== null || ! is_array($decoded) || ! is_array($native)) {
            throw ValidationException::withMessages(['contract_outstanding' => $errorMessage ?? 'TRS contractual API failed.']);
        }

        try {
            $result = $this->parseResult($decoded, $native, $accountNumber, $asOfDate, $log->id);
        } catch (ValidationException $exception) {
            $log->forceFill(['error_message' => $exception->errors()['contract_outstanding'][0]])->save();

            throw $exception;
        }

        $log->forceFill(['is_success' => true])->save();

        return $result;
    }

    private function parseResult(array $body, array $native, string $account, string $asOf, int $logId): ContractOutstandingResult
    {
        if (! is_string($body['requested_account'] ?? null) || trim($body['requested_account']) !== $account) {
            $this->invalid('TRS requested account does not match the inquiry.');
        }

        $primary = is_string($body['primary_account'] ?? null) ? trim($body['primary_account']) : '';
        if ($primary === '') {
            $this->invalid('TRS primary account is missing.');
        }

        if (! is_string($body['as_of'] ?? null) || ! $this->validDate($body['as_of']) || $body['as_of'] !== $asOf) {
            $this->invalid('TRS contractual as_of does not match the requested business date.');
        }

        $source = is_string($body['position_source'] ?? null) ? trim($body['position_source']) : '';
        if ($source === '') {
            $this->invalid('TRS position source is missing.');
        }

        if (! is_array($body['repayment_history'] ?? null) || ! array_is_list($body['repayment_history'])) {
            $this->invalid('TRS repayment history is malformed.');
        }

        foreach ($body['repayment_history'] as $index => $payment) {
            if (! is_array($payment)
                || ! is_string($payment['payment_date'] ?? null)
                || ! $this->validDate($payment['payment_date'])
                || ! is_string($payment['journal_number'] ?? null)) {
                $this->invalid('TRS repayment history is malformed.');
            }

            foreach (['principal', 'interest', 'penalty', 'early_termination_penalty', 'dwp', 'total_payment'] as $field) {
                $this->decimal($payment[$field] ?? null, $native['repayment_history'][$index][$field] ?? null, $field, signed: true);
            }
        }

        return new ContractOutstandingResult(
            requestedAccount: $account,
            primaryAccount: $primary,
            requestedAsOf: $asOf,
            returnedAsOf: $body['as_of'],
            contractualOutstanding: $this->decimal($body['contractual_outstanding'] ?? null, $native['contractual_outstanding'] ?? null, 'contractual_outstanding'),
            contractRate: $this->decimal($body['contract_rate'] ?? null, $native['contract_rate'] ?? null, 'contract_rate'),
            positionSource: $source,
            repaymentHistory: $body['repayment_history'],
            apiLogId: $logId,
        );
    }

    private function decimal(mixed $text, mixed $native, string $field, bool $signed = false): BigDecimal
    {
        if (! is_string($text) || (! is_int($native) && ! is_float($native))
            || preg_match($signed ? '/^-?(?:0|[1-9]\d*)(?:\.\d{1,2})?$/' : '/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $text) !== 1) {
            $this->invalid("TRS {$field} must be a JSON number with at most two decimal places.");
        }

        return BigDecimal::of($text)->toScale(2);
    }

    private function decodeResponse(?string $body): array
    {
        if ($body === null || $body === '') {
            throw new JsonException('Empty JSON response.');
        }

        $native = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($native) || array_is_list($native)) {
            throw new JsonException('JSON response must be an object.');
        }

        // Preserve numeric JSON tokens before PHP converts decimal values to binary floats.
        $quotedNumbers = preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/',
            static fn (array $match): string => $match[0][0] === '"' ? $match[0] : '"'.$match[0].'"',
            $body,
        );

        return [json_decode($quotedNumbers, true, flags: JSON_THROW_ON_ERROR), $native];
    }

    private function validDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    private function httpError(int $status): string
    {
        return match ($status) {
            400 => 'TRS rejected the contractual request.',
            401 => 'TRS contractual API authentication failed; check the integration token.',
            404 => 'TRS contractual position was not found.',
            409 => 'TRS found multiple loans for this account.',
            422 => 'TRS cannot calculate this contractual position.',
            503 => 'TRS contractual API is temporarily unavailable.',
            default => $status >= 500 ? 'TRS contractual API failed internally.' : 'TRS contractual API rejected the request.',
        };
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['contract_outstanding' => $message]);
    }
}
