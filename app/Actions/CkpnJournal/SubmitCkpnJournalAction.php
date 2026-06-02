<?php

namespace App\Actions\CkpnJournal;

use App\Models\ApprovalRequest;
use App\Models\CkpnJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCkpnJournalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(CkpnJournal $journal, User $user, ?string $notes = null): CkpnJournal
    {
        if (! $user->can('submit', $journal)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can submit CKPN journals.',
            ]);
        }

        if (! in_array($journal->status, [CkpnJournal::STATUS_DRAFT, CkpnJournal::STATUS_RETURNED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or returned CKPN journals can be submitted.',
            ]);
        }

        return DB::transaction(function () use ($journal, $user, $notes): CkpnJournal {
            $journal->loadMissing('ckpnWorkpaper');

            if (blank($journal->derivedTotalAmount())) {
                throw ValidationException::withMessages([
                    'total_amount' => 'CKPN journal amount could not be derived from the workpaper.',
                ]);
            }

            $this->approvalService->submit(
                approvable: $journal,
                workflowCode: ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL,
                actor: $user,
                notes: $notes,
            );

            $journal->forceFill([
                'total_amount' => $journal->derivedTotalAmount(),
                'status' => CkpnJournal::STATUS_SUBMITTED,
            ])->save();

            return $journal->refresh();
        });
    }
}
