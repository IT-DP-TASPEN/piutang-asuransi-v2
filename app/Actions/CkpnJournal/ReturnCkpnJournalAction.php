<?php

namespace App\Actions\CkpnJournal;

use App\Models\ApprovalRequest;
use App\Models\CkpnJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnCkpnJournalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnJournal $journal, User $user, ?string $notes = null): CkpnJournal
    {
        if (! $user->can('returnRequest', $journal)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting approver can return CKPN journals.',
            ]);
        }

        return DB::transaction(function () use ($journal, $user, $notes): CkpnJournal {
            $this->approvalService->returnCurrentStep($this->activeApprovalRequestFor($journal), $user, $notes);
            $journal->forceFill(['status' => CkpnJournal::STATUS_RETURNED])->save();

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
