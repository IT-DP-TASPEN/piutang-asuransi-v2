<?php

namespace App\Actions\ReceivablePayment;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class ValidateReceivablePaymentSavingAccountAction
{
    public function __construct(
        private readonly CoreBankingClient $coreBankingClient,
    ) {}

    /**
     * @return array{
     *     accountNumber: string|null,
     *     customerName: string|null,
     *     productName: string|null,
     *     documentStatus: string|null,
     *     availableBalance: string|null,
     *     ledgerBalance: string|null
     * }
     */
    public function handle(InsuranceReceivable $receivable, string $amount, ?User $user = null, ?Model $related = null): array
    {
        $account = trim((string) $receivable->saving_account_for_loan_repayment);

        if ($account === '') {
            throw ValidationException::withMessages([
                'saving_account_number' => 'Saving account is required for debtor saving payment.',
            ]);
        }

        $result = $this->coreBankingClient->inquireBalance($account, $related ?? $receivable, $user);

        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'saving_account_number' => $result['description']
                    ?: $result['error_message']
                    ?: 'Saving account inquiry failed.',
            ]);
        }

        $data = $result['data'];
        $snapshot = [
            'accountNumber' => $this->stringValue($data['accountNumber'] ?? $data['account'] ?? $account),
            'customerName' => $this->stringValue($data['customerName'] ?? null),
            'productName' => $this->stringValue($data['productName'] ?? null),
            'documentStatus' => $this->stringValue($data['documentStatus'] ?? null),
            'availableBalance' => $this->moneyString($data['availableBalance'] ?? null, 'Available balance'),
            'ledgerBalance' => $this->optionalMoneyString($data['ledgerBalance'] ?? null),
        ];

        if (strtolower((string) $snapshot['documentStatus']) !== 'active') {
            throw ValidationException::withMessages([
                'saving_account_number' => 'Saving account document status must be Active.',
            ]);
        }

        if ($this->isDormant($data)) {
            throw ValidationException::withMessages([
                'saving_account_number' => 'Dormant saving account cannot be used.',
            ]);
        }

        if (BigDecimal::of($snapshot['availableBalance'])->isLessThan(BigDecimal::of($amount))) {
            throw ValidationException::withMessages([
                'amount' => 'Available balance is less than requested payment amount.',
            ]);
        }

        return $snapshot;
    }

    private function isDormant(array $data): bool
    {
        foreach (['accountStatus', 'status', 'documentStatus'] as $key) {
            $value = strtolower((string) ($data[$key] ?? ''));

            if (str_contains($value, 'dormant')) {
                return true;
            }
        }

        return false;
    }

    private function moneyString(mixed $value, string $label): string
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'saving_account_number' => "{$label} is missing from saving account inquiry.",
            ]);
        }

        try {
            return (string) BigDecimal::of(str_replace(',', '', trim((string) $value)))
                ->toScale(2, RoundingMode::HalfUp);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'saving_account_number' => "{$label} must be numeric.",
            ]);
        }
    }

    private function optionalMoneyString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (string) BigDecimal::of(str_replace(',', '', trim((string) $value)))
                ->toScale(2, RoundingMode::HalfUp);
        } catch (Throwable) {
            return null;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
