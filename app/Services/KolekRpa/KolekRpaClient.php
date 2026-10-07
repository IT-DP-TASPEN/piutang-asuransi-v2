<?php

namespace App\Services\KolekRpa;

use App\Models\ApiIntegrationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Client for the kolek-rpa service. Both operations only create pending changes
 * in Fincloud; someone still has to approve them there.
 */
class KolekRpaClient
{
    /**
     * @return array<string, mixed>
     */
    public function setRepaymentAccount(string $loanAccount, string $savingAccount, ?Model $related = null, ?User $requestedBy = null): array
    {
        return $this->post('/api/v1/repayment-account', [
            'loan_account' => $loanAccount,
            'saving_account' => $savingAccount,
        ], $related, $requestedBy);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCollectability(string $loanAccount, int $kolek, string $changeType, ?Model $related = null, ?User $requestedBy = null): array
    {
        $data = $this->post('/api/v1/kolek', [
            'kolek' => $kolek,
            'change_type' => $changeType,
            'accounts' => [$loanAccount],
        ], $related, $requestedBy, function (array $data): ?string {
            $result = $data['results'][0] ?? null;

            return match ($result['status'] ?? null) {
                'SUCCESS', 'SKIPPED' => null,
                default => 'Collectability RPA failed: '.($result['error'] ?? $result['reason'] ?? 'no result returned').'.',
            };
        });

        return $data['results'][0];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  (callable(array<string, mixed>): ?string)|null  $businessError
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $body, ?Model $related, ?User $requestedBy, ?callable $businessError = null): array
    {
        $baseUrl = trim((string) config('services.kolek_rpa.base_url'));
        $apiKey = trim((string) config('services.kolek_rpa.api_key'));

        if ($baseUrl === '' || $apiKey === '') {
            throw ValidationException::withMessages(['kolek_rpa' => 'Kolek RPA configuration is incomplete.']);
        }

        $requestedAt = now();
        $startedAt = hrtime(true);
        $status = null;
        $decoded = null;
        $raw = null;
        $errorMessage = null;

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout((int) config('services.kolek_rpa.timeout', 75))
                ->post(rtrim($baseUrl, '/').$endpoint, $body);

            $status = $response->status();
            $raw = $response->body();
            $decoded = $response->json();

            if (! $response->successful() || ($decoded['status'] ?? null) !== 'ok' || ! is_array($decoded['data'] ?? null)) {
                $errorMessage = 'Kolek RPA rejected the request: '.($decoded['error']['message'] ?? "HTTP {$status}").'.';
            } elseif ($businessError !== null) {
                $errorMessage = $businessError($decoded['data']);
            }
        } catch (Throwable) {
            $errorMessage = 'Kolek RPA is unavailable.';
        }

        ApiIntegrationLog::create([
            'service_name' => 'kolek_rpa',
            'endpoint' => $endpoint,
            'method' => 'POST',
            'request_headers' => ['Accept' => 'application/json'],
            'request_body' => $body,
            'response_status' => $status,
            'response_body' => is_array($decoded) ? $decoded : ($raw === null ? [] : ['raw' => $raw]),
            'is_success' => $errorMessage === null,
            'error_message' => $errorMessage,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'requested_by' => $requestedBy?->id,
            'requested_at' => $requestedAt,
            'completed_at' => now(),
        ]);

        if ($errorMessage !== null) {
            throw ValidationException::withMessages(['kolek_rpa' => $errorMessage]);
        }

        return $decoded['data'];
    }
}
