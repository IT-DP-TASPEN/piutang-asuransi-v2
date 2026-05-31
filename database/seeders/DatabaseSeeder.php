<?php

namespace Database\Seeders;

use App\Models\BranchOffice;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
            ClaimStatusSeeder::class,
            CkpnAgeBucketSeeder::class,
            CkpnCalculationRuleSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $centralBranch = BranchOffice::query()
            ->where('branch_code', '000')
            ->firstOrFail();

        $user = User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'branch_office_id' => $centralBranch->id,
                'name' => 'Test User',
                'password' => 'password',
            ],
        );

        $user->syncRoles(['super_admin']);
    }
}
