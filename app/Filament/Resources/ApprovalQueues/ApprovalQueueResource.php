<?php

namespace App\Filament\Resources\ApprovalQueues;

use App\Filament\Resources\ApprovalQueues\Pages\ListApprovalQueue;
use App\Filament\Resources\ApprovalQueues\Tables\ApprovalQueueTable;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Services\Approval\ApprovalQueueWorkflowRegistry;
use App\Support\Access\RoleScope;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApprovalQueueResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static ?string $slug = 'approval-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Approval Queue';

    protected static ?string $modelLabel = 'approval request';

    protected static ?string $pluralModelLabel = 'Approval Queue';

    public static function table(Table $table): Table
    {
        return ApprovalQueueTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalQueue::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'submitter',
                'steps.actor',
                'steps.assignedUser',
                'logs.actor',
                'approvable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        InsuranceReceivable::class => ['branchOffice', 'insuranceCompany', 'claimStatus'],
                        ClaimStatusChangeRequest::class => ['insuranceReceivable.branchOffice', 'fromClaimStatus', 'toClaimStatus'],
                        ReceivablePaymentRequest::class => ['insuranceReceivable.branchOffice'],
                        CkpnWorkpaper::class => ['branchOffice'],
                        CkpnJournal::class => ['branchOffice', 'ckpnWorkpaper.branchOffice'],
                        CkpnAdjustment::class => ['ckpnWorkpaper.branchOffice', 'ckpnWorkpaperItem'],
                    ]);
                },
            ]);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && static::canUseQueue($user);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canViewAllPending(User $user): bool
    {
        return $user->can('ViewAllPending:ApprovalRequest');
    }

    public static function canUseQueue(User $user): bool
    {
        return collect([
            'ViewAllPending:ApprovalRequest',
            'ViewAny:ApprovalRequest',
            'View:ApprovalRequest',
            'ViewAny:InsuranceReceivable',
            'View:InsuranceReceivable',
            'Create:InsuranceReceivable',
            'ApproveApproval:InsuranceReceivable',
            'RejectApproval:InsuranceReceivable',
            'ReturnApproval:InsuranceReceivable',
            'ResolveEarlyTermination:InsuranceReceivable',
            'ViewAny:ClaimStatusChangeRequest',
            'Submit:ClaimStatusChangeRequest',
            'Approve:ClaimStatusChangeRequest',
            'Reject:ClaimStatusChangeRequest',
            'Return:ClaimStatusChangeRequest',
            'ViewAny:ReceivablePaymentRequest',
            'Submit:ReceivablePaymentRequest',
            'Approve:ReceivablePaymentRequest',
            'Reject:ReceivablePaymentRequest',
            'ViewAny:CkpnWorkpaper',
            'Submit:CkpnWorkpaper',
            'Approve:CkpnWorkpaper',
            'Reject:CkpnWorkpaper',
            'Return:CkpnWorkpaper',
            'ViewAny:CkpnAdjustment',
            'Submit:CkpnAdjustment',
            'Approve:CkpnAdjustment',
            'Reject:CkpnAdjustment',
            'Return:CkpnAdjustment',
            'ViewAny:CkpnJournal',
            'Submit:CkpnJournal',
            'Approve:CkpnJournal',
            'Reject:CkpnJournal',
            'Return:CkpnJournal',
        ])->contains(fn (string $permission): bool => $user->can($permission));
    }

    /**
     * @return array<string, string>
     */
    public static function workflowOptions(): array
    {
        return [
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => 'Insurance Receivable Initial Approval',
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => 'Accounting Validation',
            ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION => 'Manual Early Termination Verification',
            ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE => 'Claim Status Update',
            ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER => 'CKPN Workpaper',
            ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL => 'CKPN Journal',
            ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT => 'CKPN Adjustment',
            ApprovalRequest::WORKFLOW_RECEIVABLE_PAYMENT => 'Receivable Payment',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            ApprovalRequest::STATUS_DRAFT => 'Draft',
            ApprovalRequest::STATUS_SUBMITTED => 'Submitted',
            ApprovalRequest::STATUS_APPROVED => 'Approved',
            ApprovalRequest::STATUS_REJECTED => 'Rejected',
            ApprovalRequest::STATUS_RETURNED => 'Returned',
            ApprovalRequest::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            ApprovalRequest::STATUS_APPROVED,
            ApprovalRequest::STATUS_REJECTED,
            ApprovalRequest::STATUS_RETURNED,
            ApprovalRequest::STATUS_CANCELLED,
        ];
    }

    public static function scopeToMyPending(Builder $query, User $user): Builder
    {
        $query = static::scopeToCurrentStepForUser($query, $user)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->whereIn('workflow_code', app(ApprovalQueueWorkflowRegistry::class)->supportedWorkflowCodes())
            ->where(fn (Builder $query) => static::applyActionableWorkflowScope($query, $user));

        static::scopeToVisibleBranches($query, $user);

        return $query
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    public static function scopeToAllPending(Builder $query): Builder
    {
        return $query
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    public static function scopeToMyRequests(Builder $query, User $user): Builder
    {
        return $query
            ->where('submitted_by', $user->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }

    public static function scopeToHistory(Builder $query, User $user): Builder
    {
        $query->whereIn('status', static::terminalStatuses());

        if (! static::canViewAllPending($user)) {
            $query->where(function (Builder $query) use ($user): void {
                $query
                    ->where('submitted_by', $user->id)
                    ->orWhereHas('steps', fn (Builder $query) => $query->where('acted_by', $user->id))
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query->whereIn('workflow_code', app(ApprovalQueueWorkflowRegistry::class)->supportedWorkflowCodes());
                        static::scopeToVisibleBranches($query, $user);
                    });
            });
        }

        return $query
            ->orderByDesc('final_approved_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    public static function applySearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('workflow_code', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhereHas('submitter', fn (Builder $query) => $query->where('name', 'like', $like))
                ->orWhereHasMorph('approvable', [InsuranceReceivable::class], function (Builder $query) use ($like): void {
                    $query
                        ->where('customer_name', 'like', $like)
                        ->orWhere('loan_account_number', 'like', $like)
                        ->orWhere('cif_no', 'like', $like);
                })
                ->orWhereHasMorph('approvable', [ClaimStatusChangeRequest::class], function (Builder $query) use ($like): void {
                    $query->whereHas('insuranceReceivable', function (Builder $query) use ($like): void {
                        $query
                            ->where('customer_name', 'like', $like)
                            ->orWhere('loan_account_number', 'like', $like)
                            ->orWhere('cif_no', 'like', $like);
                    });
                })
                ->orWhereHasMorph('approvable', [ReceivablePaymentRequest::class], function (Builder $query) use ($like): void {
                    $query->whereHas('insuranceReceivable', function (Builder $query) use ($like): void {
                        $query
                            ->where('customer_name', 'like', $like)
                            ->orWhere('loan_account_number', 'like', $like)
                            ->orWhere('cif_no', 'like', $like);
                    });
                })
                ->orWhereHasMorph('approvable', [CkpnJournal::class], fn (Builder $query) => $query->where('description', 'like', $like))
                ->orWhereHasMorph('approvable', [CkpnAdjustment::class], function (Builder $query) use ($like): void {
                    $query->whereHas('ckpnWorkpaperItem', function (Builder $query) use ($like): void {
                        $query
                            ->where('customer_name', 'like', $like)
                            ->orWhere('loan_account_number', 'like', $like);
                    });
                });
        });
    }

    public static function applyBranchFilter(Builder $query, int $branchOfficeId): Builder
    {
        return $query->where(fn (Builder $query) => static::whereBranch($query, $branchOfficeId));
    }

    public static function applyCutoffFilter(Builder $query, string $cutoffDate): Builder
    {
        return $query->where(function (Builder $query) use ($cutoffDate): void {
            $query
                ->whereHasMorph('approvable', [CkpnWorkpaper::class], fn (Builder $query) => $query->whereDate('period', $cutoffDate))
                ->orWhereHasMorph('approvable', [CkpnJournal::class], fn (Builder $query) => $query->whereHas('ckpnWorkpaper', fn (Builder $query) => $query->whereDate('period', $cutoffDate)))
                ->orWhereHasMorph('approvable', [CkpnAdjustment::class], fn (Builder $query) => $query->whereHas('ckpnWorkpaper', fn (Builder $query) => $query->whereDate('period', $cutoffDate)));
        });
    }

    public static function runQueueAction(ApprovalRequest $record, string $action, ?string $notes = null): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $request = ApprovalRequest::query()
            ->with(['approvable', 'steps', 'submitter'])
            ->findOrFail($record->id);
        $adapter = app(ApprovalQueueWorkflowRegistry::class)->adapterFor($request);

        if ($request->status !== ApprovalRequest::STATUS_SUBMITTED || ! $adapter->isCurrent($request)) {
            Notification::make()
                ->warning()
                ->title('This approval request is no longer pending.')
                ->send();

            return false;
        }

        $canAct = match ($action) {
            'approve' => $adapter->canApprove($request, $user),
            'return' => $adapter->canReturn($request, $user),
            'reject' => $adapter->canReject($request, $user),
            default => false,
        };

        if (! $canAct) {
            Notification::make()
                ->danger()
                ->title('You are not authorized to perform this action.')
                ->send();

            return false;
        }

        try {
            match ($action) {
                'approve' => $adapter->approve($request, $user, $notes),
                'return' => $adapter->return($request, $user, $notes),
                'reject' => $adapter->reject($request, $user, $notes),
                default => null,
            };
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(static::validationMessage($exception))
                ->send();

            return false;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Approval action failed.')
                ->body($exception->getMessage())
                ->send();

            return false;
        }

        $request->refresh();

        $notification = Notification::make()
            ->title($request->status === ApprovalRequest::STATUS_SUBMITTED ? 'Approval remains pending.' : 'Approval request updated.');

        $request->status === ApprovalRequest::STATUS_SUBMITTED
            ? $notification->warning()
            : $notification->success();

        $notification->send();

        return true;
    }

    protected static function scopeToCurrentStepForUser(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin')) {
            return $query->whereHas('steps', fn (Builder $query) => $query->where('status', ApprovalStep::STATUS_PENDING));
        }

        $roleNames = $user->getRoleNames()->all();

        return $query->whereHas('steps', function (Builder $query) use ($roleNames, $user): void {
            $query
                ->where('status', ApprovalStep::STATUS_PENDING)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('approval_steps as earlier_steps')
                        ->whereColumn('earlier_steps.approval_request_id', 'approval_steps.approval_request_id')
                        ->where('earlier_steps.status', ApprovalStep::STATUS_PENDING)
                        ->whereColumn('earlier_steps.step_order', '<', 'approval_steps.step_order');
                })
                ->where(function (Builder $query) use ($roleNames, $user): void {
                    $query
                        ->where(function (Builder $query) use ($roleNames, $user): void {
                            $query
                                ->where('assigned_user_id', $user->id)
                                ->where(function (Builder $query) use ($roleNames): void {
                                    $query->whereNull('role_name')->orWhereIn('role_name', $roleNames);
                                });
                        })
                        ->orWhere(function (Builder $query) use ($roleNames): void {
                            $query
                                ->whereNull('assigned_user_id')
                                ->where(function (Builder $query) use ($roleNames): void {
                                    $query->whereNull('role_name')->orWhereIn('role_name', $roleNames);
                                });
                        });
                });
        });
    }

    protected static function applyActionableWorkflowScope(Builder $query, User $user): void
    {
        $hasAny = false;

        $orWorkflow = function (callable $callback) use (&$hasAny, $query): void {
            $method = $hasAny ? 'orWhere' : 'where';
            $hasAny = true;
            $query->{$method}($callback);
        };

        if ($user->can('ApproveApproval:InsuranceReceivable') || $user->can('RejectApproval:InsuranceReceivable') || $user->can('ReturnApproval:InsuranceReceivable')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
                ->whereHasMorph('approvable', [InsuranceReceivable::class], fn (Builder $query) => $query->where('workflow_status', InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED)));

            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
                ->whereHasMorph('approvable', [InsuranceReceivable::class], fn (Builder $query) => $query->where('workflow_status', InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION)));
        }

        if ($user->can('ResolveEarlyTermination:InsuranceReceivable')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION)
                ->whereHasMorph('approvable', [InsuranceReceivable::class], fn (Builder $query) => $query
                    ->where('workflow_status', InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED)
                    ->where('system_status', InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED)));
        }

        if ($user->can('Approve:ClaimStatusChangeRequest') || $user->can('Reject:ClaimStatusChangeRequest') || $user->can('Return:ClaimStatusChangeRequest')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE)
                ->whereHasMorph('approvable', [ClaimStatusChangeRequest::class], fn (Builder $query) => $query->where('status', ClaimStatusChangeRequest::STATUS_SUBMITTED)));
        }

        if ($user->can('Approve:ReceivablePaymentRequest') || $user->can('Reject:ReceivablePaymentRequest')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_RECEIVABLE_PAYMENT)
                ->whereHasMorph('approvable', [ReceivablePaymentRequest::class], fn (Builder $query) => $query->whereIn('status', [
                    ReceivablePaymentRequest::STATUS_SUBMITTED,
                    ReceivablePaymentRequest::STATUS_VALIDATION_FAILED,
                    ReceivablePaymentRequest::STATUS_GL_FAILED,
                ])));
        }

        if ($user->can('Approve:CkpnWorkpaper') || $user->can('Reject:CkpnWorkpaper') || $user->can('Return:CkpnWorkpaper')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER)
                ->whereHasMorph('approvable', [CkpnWorkpaper::class], fn (Builder $query) => $query->where('status', CkpnWorkpaper::STATUS_SUBMITTED)));
        }

        if ($user->can('Approve:CkpnJournal') || $user->can('Reject:CkpnJournal') || $user->can('Return:CkpnJournal')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL)
                ->whereHasMorph('approvable', [CkpnJournal::class], fn (Builder $query) => $query->where('status', CkpnJournal::STATUS_SUBMITTED)));
        }

        if ($user->can('Approve:CkpnAdjustment') || $user->can('Reject:CkpnAdjustment') || $user->can('Return:CkpnAdjustment')) {
            $orWorkflow(fn (Builder $query) => $query
                ->where('workflow_code', ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT)
                ->whereHasMorph('approvable', [CkpnAdjustment::class], fn (Builder $query) => $query->where('status', CkpnAdjustment::STATUS_SUBMITTED)));
        }

        if (! $hasAny) {
            $query->whereRaw('1 = 0');
        }
    }

    protected static function scopeToVisibleBranches(Builder $query, User $user): Builder
    {
        if (RoleScope::canViewAllBranches($user)) {
            return $query;
        }

        if (! RoleScope::isBranchScoped($user) || $user->branch_office_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return static::applyBranchFilter($query, (int) $user->branch_office_id);
    }

    protected static function whereBranch(Builder $query, int $branchOfficeId): void
    {
        $query
            ->whereHasMorph('approvable', [InsuranceReceivable::class], fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId))
            ->orWhereHasMorph('approvable', [ClaimStatusChangeRequest::class], fn (Builder $query) => $query->whereHas('insuranceReceivable', fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId)))
            ->orWhereHasMorph('approvable', [ReceivablePaymentRequest::class], fn (Builder $query) => $query->whereHas('insuranceReceivable', fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId)))
            ->orWhereHasMorph('approvable', [CkpnWorkpaper::class], fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId))
            ->orWhereHasMorph('approvable', [CkpnJournal::class], fn (Builder $query) => $query
                ->where('branch_office_id', $branchOfficeId)
                ->orWhereHas('ckpnWorkpaper', fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId)))
            ->orWhereHasMorph('approvable', [CkpnAdjustment::class], fn (Builder $query) => $query->whereHas('ckpnWorkpaper', fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId)));
    }

    private static function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
    }
}
