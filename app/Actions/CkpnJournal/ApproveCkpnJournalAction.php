<?php

namespace App\Actions\CkpnJournal;

use App\Models\ApprovalRequest;
use App\Models\CkpnJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCkpnJournalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnJournal $journal, User $user, ?string $notes = null): CkpnJournal
    {
        return DB::transaction(function () use ($journal, $user, $notes): CkpnJournal {
            $approvalRequest = $this->activeApprovalRequestFor($journal);
            $approvalRequest = $this->approvalService->approveCurrentStep($approvalRequest, $user, $notes);

            if ($approvalRequest->status === ApprovalRequest::STATUS_APPROVED) {
                $journal->forceFill([
                    'status' => CkpnJournal::STATUS_APPROVED,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ])->save();
            }

            return $journal->refresh();
        });
    }

    private function activeApprovalRequestFor(CkpnJournal $journal): ApprovalRequest
    {
        $approvalRequest = $this->approvalService->latestActiveRequest($journal, ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL);

        if (! $approvalRequest instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active CKPN journal approval request not found.',
            ]);
        }

        return $approvalRequest;
    }
}
