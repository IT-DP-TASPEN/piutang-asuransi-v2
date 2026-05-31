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
            'ClaimStatus',
            'CkpnAgeBucket',
            'CkpnCalculationRule',
            'InsuranceReceivable',
            'InsuranceReceivableDocument',
            'ApiIntegrationLog',
            'Role',
        ];

        $permissions = collect($subjects)
            ->flatMap(fn (string $subject): array => array_map(
                fn (string $action): string => "{$action}:{$subject}",
                $actions,
            ))
            ->merge([
                'RunInquiry:InsuranceReceivable',
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
            'ViewAny:ApiIntegrationLog',
            'View:ApiIntegrationLog',
        ];

        collect([
            'it_user',
            'accounting_maker',
            'accounting_approver',
            'business_maker',
            'business_approver',
        ])->each(fn (string $role) => $roles->get($role)->syncPermissions($centralViewPermissions));

        $roles->get('branch_maker')->syncPermissions([
            'ViewAny:InsuranceReceivable',
            'View:InsuranceReceivable',
            'Create:InsuranceReceivable',
            'Update:InsuranceReceivable',
            'RunInquiry:InsuranceReceivable',
            'ViewAny:InsuranceReceivableDocument',
            'View:InsuranceReceivableDocument',
            'Create:InsuranceReceivableDocument',
            'Update:InsuranceReceivableDocument',
            'Delete:InsuranceReceivableDocument',
            'DeleteAny:InsuranceReceivableDocument',
        ]);

        $roles->get('branch_approver')->syncPermissions([
            'ViewAny:InsuranceReceivable',
            'View:InsuranceReceivable',
            'ViewAny:InsuranceReceivableDocument',
            'View:InsuranceReceivableDocument',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
