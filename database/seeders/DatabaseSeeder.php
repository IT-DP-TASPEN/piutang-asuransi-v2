<?php

namespace Database\Seeders;

use App\Models\BranchOffice;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimDocumentSeeder::class,
            InsuranceCoverLetterSettingSeeder::class,
            ClaimStatusSeeder::class,
            CkpnAgeBucketSeeder::class,
            CkpnCalculationRuleSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $branchOffices = BranchOffice::query()
            ->get()
            ->keyBy('branch_code');

        $centralBranch = $branchOffices->get('000');
        if (! $centralBranch) {
            throw new RuntimeException('Central branch with branch_code 000 not found.');
        }

        $upsertUser = function (
            string $role,
            BranchOffice $branchOffice,
            string $email,
            ?string $name = null,
            ?string $username = null,
        ): User {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'branch_office_id' => $branchOffice->id,
                    'name' => $name ?? Str::headline($role),
                    'username' => $username ?? $role,
                    'password' => 'password',
                ],
            );

            $user->syncRoles([$role]);

            return $user;
        };

        $upsertUser(
            role: 'super_admin',
            branchOffice: $centralBranch,
            email: 'test@example.com',
            name: 'Test User',
            username: 'test',
        );

        $branchOffices
            ->except('000')
            ->each(function (BranchOffice $branchOffice) use ($upsertUser) {
                $code = $branchOffice->branch_code;

                $upsertUser(
                    role: 'branch_maker',
                    branchOffice: $branchOffice,
                    email: "maker_{$code}@example.com",
                    name: "Maker {$code}",
                    username: "maker_{$code}",
                );

                $upsertUser(
                    role: 'branch_approver',
                    branchOffice: $branchOffice,
                    email: "approver_{$code}@example.com",
                    name: "Approver {$code}",
                    username: "approver_{$code}",
                );
            });

        collect([
            'it_user',
            'accounting_maker',
            'accounting_approver',
            'insurance_approver',
            'business_maker',
            'business_approver',
            'auditor',
        ])->each(fn (string $role) => $upsertUser(
            role: $role,
            branchOffice: $centralBranch,
            email: "{$role}@example.com",
        ));
    }
}
