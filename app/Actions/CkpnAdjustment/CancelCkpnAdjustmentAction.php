<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\CkpnAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelCkpnAdjustmentAction
{
    public function handle(CkpnAdjustment $adjustment, User $user): CkpnAdjustment
    {
        return DB::transaction(function () use ($adjustment): CkpnAdjustment {
            $adjustment = CkpnAdjustment::query()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($adjustment->status, [
                CkpnAdjustment::STATUS_DRAFT,
                CkpnAdjustment::STATUS_RETURNED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft or returned CKPN adjustments can be cancelled.',
                ]);
            }

            $adjustment->forceFill([
                'status' => CkpnAdjustment::STATUS_CANCELLED,
            ])->save();

            return $adjustment->refresh();
        });
    }
}
