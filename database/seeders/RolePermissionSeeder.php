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
            'Role',
        ];

        $permissions = collect($subjects)
            ->flatMap(fn (string $subject): array => array_map(
                fn (string $action): string => "{$action}:{$subject}",
                $actions,
            ));

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

        $roles
            ->except(['super_admin', 'auditor'])
            ->each(fn (Role $role) => $role->syncPermissions([]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
