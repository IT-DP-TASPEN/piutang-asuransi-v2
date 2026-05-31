<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateCollectabilityAction
{
    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $collectability, ?string $reason = null): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user, $collectability, $reason): InsuranceReceivable {
            $oldValue = $insuranceReceivable->collectability;

            $insuranceReceivable->forceFill([
                'collectability' => $collectability,
            ])->save();

            $insuranceReceivable->fieldChangeLogs()->create([
                'field_name' => 'collectability',
                'old_value' => $oldValue,
                'new_value' => $collectability,
                'changed_by' => $user->id,
                'reason' => $reason,
            ]);

            return $insuranceReceivable->refresh();
        });
    }
}
