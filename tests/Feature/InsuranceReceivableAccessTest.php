<?php

namespace Tests\Feature;

use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsuranceReceivableAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_role_only_sees_own_branch_records(): void
    {
        $this->seedDependencies();

        $branchOne = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $branchTwo = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        $branchUser = User::factory()->create(['branch_office_id' => $branchOne->id]);
        $branchUser->assignRole('branch_maker');

        $ownRecord = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branchOne->id,
            'branch_code' => '001',
        ]);
        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branchTwo->id,
            'branch_code' => '002',
        ]);

        $this->actingAs($branchUser);

        $this->assertSame(
            [$ownRecord->id],
            InsuranceReceivableResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_central_roles_view_all_receivables_and_api_logs(): void
    {
        $this->seedDependencies();

        $branchOne = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $branchTwo = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        $centralBranch = BranchOffice::query()->where('branch_code', '000')->firstOrFail();
        $centralUser = User::factory()->create(['branch_office_id' => $centralBranch->id]);
        $centralUser->assignRole('it_user');

        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branchOne->id,
            'branch_code' => '001',
        ]);
        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branchTwo->id,
            'branch_code' => '002',
        ]);
        ApiIntegrationLog::query()->create([
            'service_name' => 'core_banking',
            'endpoint' => '/inquiry/detail/loan',
            'method' => 'POST',
            'is_success' => true,
        ]);

        $this->actingAs($centralUser);

        $this->assertCount(2, InsuranceReceivableResource::getEloquentQuery()->get());
        $this->assertTrue($centralUser->can('viewAny', InsuranceReceivable::class));
        $this->assertTrue($centralUser->can('viewAny', ApiIntegrationLog::class));
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }
}
