<?php

namespace App\Services\CoreBanking;

use App\Models\CoreTransactionReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class CoreTransactionReferenceRegistry
{
    public function reserve(
        string $reference,
        string $serviceAction,
        ?string $operationKey,
        ?User $user = null,
    ): CoreTransactionReference {
        try {
            return CoreTransactionReference::query()->create([
                'reference' => $reference,
                'service_action' => $serviceAction,
                'operation_key' => $operationKey,
                'created_by' => $user?->id,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw ValidationException::withMessages([
                    'core_reference' => "Core reference {$reference} has already been reserved.",
                ]);
            }

            throw $exception;
        }
    }

    public function link(CoreTransactionReference $reservation, Model $source): void
    {
        $reservation->forceFill([
            'source_type' => $source->getMorphClass(),
            'source_table' => $source->getTable(),
            'source_id' => $source->getKey(),
        ])->save();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || $exception->getCode() === '23505'
            || str_contains(strtoupper($exception->getMessage()), 'UNIQUE');
    }
}
