<?php

namespace App\Actions\CkpnWorkpaper;

use App\Models\ApprovalRequest;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnCkpnWorkpaperAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnWorkpaper $workpaper, User $user, ?string $notes = null): CkpnWorkpaper
    {
        if (! $user->can('returnRequest', $workpaper)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can return CKPN workpapers.',
            ]);
        }

        return DB::transaction(function () use ($workpaper, $user, $notes): CkpnWorkpaper {
            $approvalRequest = $this->activeApprovalRequestFor($workpaper);
            $this->approvalService->returnCurrentStep($approvalRequest, $user, $notes);

            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_RETURNED,
            ])->save();

            return $workpaper->refresh();
        });
    }

    private function activeApprovalRequestFor(CkpnWorkpaper $workpaper): ApprovalRequest
    {
        $approvalRequest = $this->approvalService->latestActiveRequest($workpaper, ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER);

        if (! $approvalRequest instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active CKPN workpaper approval request not found.',
            ]);
        }

        return $approvalRequest;
    }
}
