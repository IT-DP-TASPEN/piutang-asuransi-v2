<?php

namespace App\Actions\CkpnAdjustment;

use App\Models\CkpnAdjustment;
use App\Models\CkpnWorkpaperItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PrepareCkpnAdjustmentDataAction
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(array $data, User $user): array
    {
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'CKPN adjustment reason is required.',
            ]);
        }

        $item = isset($data['ckpn_workpaper_item_id'])
            ? CkpnWorkpaperItem::query()->find($data['ckpn_workpaper_item_id'])
            : null;

        return [
            ...$data,
            'insurance_receivable_id' => $data['insurance_receivable_id'] ?? $item?->insurance_receivable_id,
            'ckpn_workpaper_id' => $data['ckpn_workpaper_id'] ?? $item?->ckpn_workpaper_id,
            'original_rate' => $data['original_rate'] ?? $item?->final_ckpn_rate,
            'original_amount' => $data['original_amount'] ?? $item?->ckpn_amount,
            'status' => $data['status'] ?? CkpnAdjustment::STATUS_DRAFT,
            'requested_by' => $data['requested_by'] ?? $user->id,
        ];
    }
}
