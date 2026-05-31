<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\ReceivableFormationJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveInsuranceReceivableApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $request = $this->activeRequestFor($insuranceReceivable);
            $request = $this->approvalService->approveCurrentStep($request, $user, $notes);

            if ($request->status !== ApprovalRequest::STATUS_APPROVED) {
                return $insuranceReceivable->refresh();
            }

            if ($request->workflow_code === ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH) {
                $insuranceReceivable->forceFill([
                    'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_BRANCH_APPROVED,
                    'approved_at' => now(),
                ])->save();

                return $insuranceReceivable->refresh();
            }

            if ($request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION) {
                $journal = $insuranceReceivable->receivableFormationJournals()
                    ->where('status', ReceivableFormationJournal::STATUS_SUBMITTED)
                    ->latest('id')
                    ->first();

                if (! $journal instanceof ReceivableFormationJournal) {
                    throw ValidationException::withMessages([
                        'journal' => 'Submitted receivable formation journal not found.',
                    ]);
                }

                $journal->forceFill([
                    'status' => ReceivableFormationJournal::STATUS_APPROVED,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ])->save();

                $insuranceReceivable->forceFill([
                    'receivable_formation_date' => $journal->journal_date,
                    'receivable_amount' => $journal->amount,
                    'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
                    'approved_at' => now(),
                ])->save();

                return $insuranceReceivable->refresh();
            }

            return $insuranceReceivable->refresh();
        });
    }

    private function activeRequestFor(InsuranceReceivable $insuranceReceivable): ApprovalRequest
    {
        $request = $this->approvalService->latestActiveRequest($insuranceReceivable);

        if (! $request instanceof ApprovalRequest) {
            throw ValidationException::withMessages([
                'approval' => 'Active approval request not found.',
            ]);
        }

        return $request;
    }
}
