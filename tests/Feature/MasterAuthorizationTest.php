<?php

namespace Tests\Feature;

use App\Models\BranchOffice;
use App\Models\CkpnAgeBucket;
use App\Models\CkpnCalculationRule;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\CkpnAgeBucketSeeder;
use Database\Seeders\CkpnCalculationRuleSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_data_permissions_follow_phase_one_roles(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            CkpnAgeBucketSeeder::class,
            CkpnCalculationRuleSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $auditor = User::factory()->create();
        $auditor->assignRole('auditor');

        $branchMaker = User::factory()->create();
        $branchMaker->assignRole('branch_maker');

        foreach ($this->masterRecords() as $modelClass => $record) {
            $this->assertTrue($superAdmin->can('viewAny', $modelClass));
            $this->assertTrue($superAdmin->can('view', $record));
            $this->assertTrue($superAdmin->can('create', $modelClass));
            $this->assertTrue($superAdmin->can('update', $record));
            $this->assertTrue($superAdmin->can('delete', $record));

            $this->assertTrue($auditor->can('viewAny', $modelClass));
            $this->assertTrue($auditor->can('view', $record));
            $this->assertFalse($auditor->can('create', $modelClass));
            $this->assertFalse($auditor->can('update', $record));
            $this->assertFalse($auditor->can('delete', $record));

            $this->assertFalse($branchMaker->can('viewAny', $modelClass));
            $this->assertFalse($branchMaker->can('view', $record));
            $this->assertFalse($branchMaker->can('create', $modelClass));
            $this->assertFalse($branchMaker->can('update', $record));
            $this->assertFalse($branchMaker->can('delete', $record));
        }
    }

    /**
     * @return array<class-string<Model>, Model>
     */
    private function masterRecords(): array
    {
        return [
            BranchOffice::class => BranchOffice::query()->where('branch_code', '000')->firstOrFail(),
            InsuranceCompany::class => InsuranceCompany::query()->where('name', 'ASKRINDO')->firstOrFail(),
            ClaimStatus::class => ClaimStatus::query()->where('code', ClaimStatus::DEFAULT_CODE)->firstOrFail(),
            CkpnAgeBucket::class => CkpnAgeBucket::query()->where('name', '1 - 6 bulan')->firstOrFail(),
            CkpnCalculationRule::class => CkpnCalculationRule::query()
                ->where('code', 'average_three_factors_with_reject_loss_override')
                ->firstOrFail(),
        ];
    }
}
