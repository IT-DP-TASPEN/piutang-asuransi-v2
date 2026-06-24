<?php

namespace App\Policies;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Support\Access\RoleScope;

class InsuranceReceivablePolicy
{
    private const SUBJECT = 'InsuranceReceivable';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $insuranceReceivable);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        if (! $this->can($user, 'Update') || ! $this->canAccessRecord($user, $insuranceReceivable)) {
            return false;
        }

        if ($user->hasRole('branch_maker')) {
            return in_array($insuranceReceivable->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
                InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
            ], true) && ! $insuranceReceivable->isTerminal();
        }

        return $insuranceReceivable->isEditable();
    }

    public function delete(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $insuranceReceivable);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $insuranceReceivable);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $insuranceReceivable);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $insuranceReceivable);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    public function runInquiry(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'RunInquiry')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && $insuranceReceivable->canRetryInquiry();
    }

    public function submitForApproval(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'SubmitForApproval')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function approveApproval(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'ApproveApproval')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function rejectApproval(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'RejectApproval')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function returnApproval(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'ReturnApproval')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function confirmCollectabilityChange(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'ConfirmCollectabilityChange')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function submitAccountingValidation(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'SubmitAccountingValidation')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function executeEarlyTermination(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'ExecuteEarlyTermination')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal();
    }

    public function submitManualEarlyTerminationConfirmation(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'SubmitManualEarlyTerminationConfirmation')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && ! $insuranceReceivable->isTerminal()
            && $insuranceReceivable->system_status === InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED
            && in_array($insuranceReceivable->workflow_status, [
                InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
                InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
            ], true);
    }

    public function cancel(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'Cancel')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && $insuranceReceivable->canCancelFailedInquiry();
    }

    public function resolveEarlyTermination(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        return $this->can($user, 'ResolveEarlyTermination')
            && $this->canAccessRecord($user, $insuranceReceivable)
            && $insuranceReceivable->canResolveEarlyTermination();
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, InsuranceReceivable $insuranceReceivable): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $insuranceReceivable->branch_office_id;
    }
}
