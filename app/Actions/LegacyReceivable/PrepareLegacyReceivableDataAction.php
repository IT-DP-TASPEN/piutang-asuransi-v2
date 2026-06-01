<?php

namespace App\Actions\LegacyReceivable;

use App\Models\ClaimStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PrepareLegacyReceivableDataAction
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(array $data, ?User $user = null): array
    {
        $claimStatusId = $data['claim_status_id'] ?? ClaimStatus::query()
            ->where('code', ClaimStatus::DEFAULT_CODE)
            ->value('id');

        if ($claimStatusId === null) {
            throw ValidationException::withMessages([
                'claim_status_id' => 'Default claim status on_process is not configured.',
            ]);
        }

        $originalAmount = $data['original_receivable_amount'] ?? null;

        if ($originalAmount === null || $originalAmount === '') {
            throw ValidationException::withMessages([
                'original_receivable_amount' => 'Original receivable amount is required.',
            ]);
        }

        return [
            ...$data,
            'claim_status_id' => $claimStatusId,
            'remaining_receivable_amount' => $data['remaining_receivable_amount'] ?? $originalAmount,
            'created_by' => $data['created_by'] ?? $user?->id,
        ];
    }
}
