<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\ReceivableFormationJournal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnInsuranceReceivableApprovalAction
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable, User $user, ?string $notes = null): InsuranceReceivable
    {
        return DB::transaction(function () use ($insuranceReceivable, $user, $notes): InsuranceReceivable {
            $request = $this->activeRequestFor($insuranceReceivable);
            $request = $this->approvalService->returnCurrentStep($request, $user, $notes);

            if ($request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION) {
                $insuranceReceivable->receivableFormationJournals()
                    ->where('status', ReceivableFormationJournal::STATUS_SUBMITTED)
                    ->latest('id')
                    ->first()
                    ?->forceFill(['status' => ReceivableFormationJournal::STATUS_RETURNED])
                    ->save();
            }

            $insuranceReceivable->forceFill([
                'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
            ])->save();

            $this->stageLogger->log(
                receivable: $insuranceReceivable,
                event: $request->workflow_code === ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION
                    ? 'accounting_validation_returned'
                    : 'approval_returned',
                fromStatus: null,
                toStatus: InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
                description: $notes ?: 'Approval returned.',
                actor: $user,
                approvalRequest: $request,
            );

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
