<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guardName = config('auth.defaults.guard', 'web');
        $actions = [
            'ViewAny',
            'View',
            'Create',
            'Update',
            'Delete',
            'DeleteAny',
            'Restore',
            'ForceDelete',
            'ForceDeleteAny',
            'RestoreAny',
            'Replicate',
            'Reorder',
        ];
        $subjects = [
            'BranchOffice',
            'InsuranceCompany',
            'ClaimDocumentType',
            'ClaimDocumentRequirement',
            'InsuranceCoverLetterSetting',
            'ClaimStatus',
            'CkpnAgeBucket',
            'CkpnCalculationRule',
            'ApprovalRequest',
            'ApprovalStep',
            'ApprovalLog',
            'InsuranceReceivable',
            'InsuranceReceivableDocument',
            'InsuranceReceivableFieldChangeLog',
            'LegacyReceivable',
            'ReceivablePayment',
            'ReceivableFormationJournal',
            'EarlyTerminationTransaction',
            'ClaimStatusChangeRequest',
            'InsuranceCoverLetter',
            'CkpnWorkpaper',
            'CkpnWorkpaperItem',
            'CkpnAdjustment',
            'CkpnJournal',
            'GeneratedExport',
            'GlToGlTransaction',
            'ApiIntegrationLog',
            'Role',
            'User',
        ];

        $permissions = collect($subjects)
            ->flatMap(function (string $subject) use ($actions): array {
                $subjectActions = $subject === 'ReceivablePayment'
                    ? ['ViewAny', 'View', 'Create']
                    : $actions;

                return array_map(
                    fn (string $action): string => "{$action}:{$subject}",
                    $subjectActions,
                );
            })
            ->merge([
                'RunInquiry:InsuranceReceivable',
                'SubmitForApproval:InsuranceReceivable',
                'ApproveApproval:InsuranceReceivable',
                'RejectApproval:InsuranceReceivable',
                'ReturnApproval:InsuranceReceivable',
                'ConfirmCollectabilityChange:InsuranceReceivable',
                'SubmitAccountingValidation:InsuranceReceivable',
                'SubmitManualEarlyTerminationConfirmation:InsuranceReceivable',
                'ExecuteEarlyTermination:InsuranceReceivable',
                'Cancel:InsuranceReceivable',
                'ResolveEarlyTermination:InsuranceReceivable',
                'Submit:ClaimStatusChangeRequest',
                'Approve:ClaimStatusChangeRequest',
                'Reject:ClaimStatusChangeRequest',
                'Return:ClaimStatusChangeRequest',
                'Cancel:ClaimStatusChangeRequest',
                'Generate:InsuranceCoverLetter',
                'Generate:CkpnWorkpaper',
                'Recalculate:CkpnWorkpaper',
                'Submit:CkpnWorkpaper',
                'Approve:CkpnWorkpaper',
                'Reject:CkpnWorkpaper',
                'Return:CkpnWorkpaper',
                'Submit:CkpnAdjustment',
                'Approve:CkpnAdjustment',
                'Reject:CkpnAdjustment',
                'Return:CkpnAdjustment',
                'Cancel:CkpnAdjustment',
                'CreateJournal:CkpnWorkpaper',
                'GenerateExport:CkpnWorkpaper',
                'Submit:CkpnJournal',
                'Approve:CkpnJournal',
                'Reject:CkpnJournal',
                'Return:CkpnJournal',
                'ExecuteGlToGl:CkpnJournal',
            ]);

        $permissions->each(fn (string $permission): Permission => Permission::query()->firstOrCreate([
            'name' => $permission,
            'guard_name' => $guardName,
        ]));

        $roles = collect([
            'super_admin',
            'branch_maker',
            'branch_approver',
            'it_user',
            'accounting_maker',
            'accounting_approver',
            'business_maker',
            'business_approver',
            'auditor',
        ])->mapWithKeys(fn (string $role): array => [
            $role => Role::query()->firstOrCreate(['name' => $role, 'guard_name' => $guardName]),
        ]);

        $roles->get('super_admin')->syncPermissions(
            Permission::query()
                ->where('guard_name', $guardName)
                ->pluck('name')
                ->all(),
        );

        /** @var Collection<int, string> $viewPermissions */
        $viewPermissions = $permissions->filter(
            fn (string $permission): bool => str_starts_with($permission, 'ViewAny:')
                || str_starts_with($permission, 'View:'),
        );

        $roles->get('auditor')->syncPermissions($viewPermissions->values()->all());

        $centralViewPermissions = [
            'ViewAny:InsuranceReceivable',
            'View:InsuranceReceivable',
            'ViewAny:InsuranceReceivableDocument',
            'View:InsuranceReceivableDocument',
            'ViewAny:ApprovalRequest',
            'View:ApprovalRequest',
            'ViewAny:ApprovalStep',
            'View:ApprovalStep',
            'ViewAny:ApprovalLog',
            'View:ApprovalLog',
            'ViewAny:InsuranceReceivableFieldChangeLog',
            'View:InsuranceReceivableFieldChangeLog',
            'ViewAny:LegacyReceivable',
            'View:LegacyReceivable',
            'ViewAny:ReceivablePayment',
            'View:ReceivablePayment',
            'ViewAny:ReceivableFormationJournal',
            'View:ReceivableFormationJournal',
            'ViewAny:EarlyTerminationTransaction',
            'View:EarlyTerminationTransaction',
            'ViewAny:ClaimStatusChangeRequest',
            'View:ClaimStatusChangeRequest',
            'ViewAny:InsuranceCoverLetter',
            'View:InsuranceCoverLetter',
            'ViewAny:CkpnWorkpaper',
            'View:CkpnWorkpaper',
            'ViewAny:CkpnWorkpaperItem',
            'View:CkpnWorkpaperItem',
            'ViewAny:CkpnAdjustment',
            'View:CkpnAdjustment',
            'ViewAny:CkpnJournal',
            'View:CkpnJournal',
            'ViewAny:GeneratedExport',
            'View:GeneratedExport',
            'ViewAny:ApiIntegrationLog',
            'View:ApiIntegrationLog',
        ];

        $roles->get('it_user')->syncPermissions([
            ...$centralViewPermissions,
            'ConfirmCollectabilityChange:InsuranceReceivable',
        ]);

        $roles->get('accounting_maker')->syncPermissions([
            ...$centralViewPermissions,
            'SubmitAccountingValidation:InsuranceReceivable',
            'SubmitManualEarlyTerminationConfirmation:InsuranceReceivable',
            'Create:ReceivableFormationJournal',
            'Update:ReceivableFormationJournal',
            'Create:LegacyReceivable',
            'Update:LegacyReceivable',
            'Create:ReceivablePayment',
            'Create:CkpnWorkpaper',
            'Update:CkpnWorkpaper',
            'Generate:CkpnWorkpaper',
            'Recalculate:CkpnWorkpaper',
            'Submit:CkpnWorkpaper',
            'GenerateExport:CkpnWorkpaper',
            'Create:GeneratedExport',
            'Create:CkpnAdjustment',
            'Update:CkpnAdjustment',
            'Submit:CkpnAdjustment',
            'Cancel:CkpnAdjustment',
            'CreateJournal:CkpnWorkpaper',
            'Create:CkpnJournal',
            'Update:CkpnJournal',
            'Submit:CkpnJournal',
        ]);

        $roles->get('accounting_approver')->syncPermissions([
            ...$centralViewPermissions,
            'ApproveApproval:InsuranceReceivable',
            'RejectApproval:InsuranceReceivable',
            'ReturnApproval:InsuranceReceivable',
            'ExecuteEarlyTermination:InsuranceReceivable',
            'ResolveEarlyTermination:InsuranceReceivable',
            'Update:ReceivableFormationJournal',
            'Create:EarlyTerminationTransaction',
            'Update:EarlyTerminationTransaction',
        ]);

        $roles->get('business_maker')->syncPermissions([
            ...$centralViewPermissions,
            'Create:ClaimStatusChangeRequest',
            'Update:ClaimStatusChangeRequest',
            'Submit:ClaimStatusChangeRequest',
            'Cancel:ClaimStatusChangeRequest',
            'Create:InsuranceCoverLetter',
            'Generate:InsuranceCoverLetter',
            'Create:LegacyReceivable',
            'Update:LegacyReceivable',
            'Create:ReceivablePayment',
        ]);

        $roles->get('business_approver')->syncPermissions([
            ...$centralViewPermissions,
            'Approve:ClaimStatusChangeRequest',
            'Reject:ClaimStatusChangeRequest',
            'Return:ClaimStatusChangeRequest',
        ]);

        $roles->get('accounting_approver')->givePermissionTo([
            'Approve:CkpnWorkpaper',
            'Reject:CkpnWorkpaper',
            'Return:CkpnWorkpaper',
            'Approve:CkpnAdjustment',
            'Reject:CkpnAdjustment',
            'Return:CkpnAdjustment',
            'Approve:CkpnJournal',
            'Reject:CkpnJournal',
            'Return:CkpnJournal',
            'ExecuteGlToGl:CkpnJournal',
        ]);

        $roles->get('branch_maker')->syncPermissions([
            'ViewAny:InsuranceReceivable',
            'View:InsuranceReceivable',
            'Create:InsuranceReceivable',
            'Update:InsuranceReceivable',
            'RunInquiry:InsuranceReceivable',
            'Cancel:InsuranceReceivable',
            'ViewAny:InsuranceReceivableDocument',
            'View:InsuranceReceivableDocument',
            'ViewAny:ClaimStatusChangeRequest',
            'View:ClaimStatusChangeRequest',
            'ViewAny:LegacyReceivable',
            'View:LegacyReceivable',
            'ViewAny:ReceivablePayment',
            'View:ReceivablePayment',
            'ViewAny:InsuranceCoverLetter',
            'View:InsuranceCoverLetter',
            'ViewAny:CkpnWorkpaper',
            'View:CkpnWorkpaper',
            'ViewAny:CkpnWorkpaperItem',
            'View:CkpnWorkpaperItem',
            'ViewAny:CkpnAdjustment',
            'View:CkpnAdjustment',
            'ViewAny:CkpnJournal',
            'View:CkpnJournal',
            'ViewAny:GeneratedExport',
            'View:GeneratedExport',
            'Create:InsuranceReceivableDocument',
            'Update:InsuranceReceivableDocument',
            'Delete:InsuranceReceivableDocument',
            'DeleteAny:InsuranceReceivableDocument',
        ]);

        $roles->get('branch_approver')->syncPermissions([
            'ViewAny:InsuranceReceivable',
            'View:InsuranceReceivable',
            'ApproveApproval:InsuranceReceivable',
            'RejectApproval:InsuranceReceivable',
            'ReturnApproval:InsuranceReceivable',
            'ViewAny:InsuranceReceivableDocument',
            'View:InsuranceReceivableDocument',
            'ViewAny:ClaimStatusChangeRequest',
            'View:ClaimStatusChangeRequest',
            'ViewAny:LegacyReceivable',
            'View:LegacyReceivable',
            'ViewAny:ReceivablePayment',
            'View:ReceivablePayment',
            'ViewAny:InsuranceCoverLetter',
            'View:InsuranceCoverLetter',
            'ViewAny:CkpnWorkpaper',
            'View:CkpnWorkpaper',
            'ViewAny:CkpnWorkpaperItem',
            'View:CkpnWorkpaperItem',
            'ViewAny:CkpnAdjustment',
            'View:CkpnAdjustment',
            'ViewAny:CkpnJournal',
            'View:CkpnJournal',
            'ViewAny:GeneratedExport',
            'View:GeneratedExport',
            'ViewAny:ApprovalRequest',
            'View:ApprovalRequest',
            'ViewAny:ApprovalStep',
            'View:ApprovalStep',
            'ViewAny:ApprovalLog',
            'View:ApprovalLog',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
