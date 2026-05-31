<?php

namespace App\Actions\CkpnWorkpaper;

use App\Models\ApprovalRequest;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCkpnWorkpaperAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnWorkpaper $workpaper, User $user, ?string $notes = null): CkpnWorkpaper
    {
        return DB::transaction(function () use ($workpaper, $user, $notes): CkpnWorkpaper {
            $approvalRequest = $this->activeApprovalRequestFor($workpaper);
            $approvalRequest = $this->approvalService->approveCurrentStep($approvalRequest, $user, $notes);

            if ($approvalRequest->status === ApprovalRequest::STATUS_APPROVED) {
                $workpaper->forceFill([
                    'status' => CkpnWorkpaper::STATUS_APPROVED,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ])->save();
            }

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
