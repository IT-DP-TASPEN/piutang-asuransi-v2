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
        if (! in_array($workpaper->status, [
            CkpnWorkpaper::STATUS_GENERATED,
            CkpnWorkpaper::STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only generated or returned CKPN workpapers can be submitted.',
            ]);
        }

        return DB::transaction(function () use ($workpaper, $user, $notes): CkpnWorkpaper {
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
