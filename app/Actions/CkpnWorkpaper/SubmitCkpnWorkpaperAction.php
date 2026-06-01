<?php

namespace App\Actions\CkpnWorkpaper;

use App\Models\ApprovalRequest;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCkpnWorkpaperAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnWorkpaper $workpaper, User $user, ?string $notes = null): CkpnWorkpaper
    {
        if ($workpaper->status !== CkpnWorkpaper::STATUS_GENERATED) {
            throw ValidationException::withMessages([
                'status' => 'Only successfully generated CKPN workpapers can be submitted.',
            ]);
        }

        if (! $workpaper->items()->exists()) {
            throw ValidationException::withMessages([
                'items' => 'CKPN workpaper must have generated items before submission.',
            ]);
        }

        return DB::transaction(function () use ($workpaper, $user, $notes): CkpnWorkpaper {
            $workpaper = CkpnWorkpaper::query()
                ->whereKey($workpaper->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($workpaper->status !== CkpnWorkpaper::STATUS_GENERATED || ! $workpaper->items()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'CKPN workpaper must be generated with items before submission.',
                ]);
            }

            $this->approvalService->submit(
                approvable: $workpaper,
                workflowCode: ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER,
                actor: $user,
                notes: $notes,
            );

            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_SUBMITTED,
            ])->save();

            return $workpaper->refresh();
        });
    }
}
