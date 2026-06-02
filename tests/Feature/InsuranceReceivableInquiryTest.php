<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\PerformLoanInquiryAction;
use App\Actions\InsuranceReceivable\PrepareInsuranceReceivableDraftAction;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InsuranceReceivableInquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_inquiry_maps_response_and_logs_masked_signature(): void
    {
        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        $this->seedPhaseTwoDependencies();
        $user = $this->branchUser('001');
        $receivable = $this->draftReceivableFor($user);
        $expectedRawBody = '{"accountNumber":"3010001000054745"}';
        $expectedLogRequestBody = json_decode($expectedRawBody, true, flags: JSON_THROW_ON_ERROR);

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'branchCode' => '001',
                    'loanOutStanding' => '230929055.00',
                    'accountNumber' => '3010001000054745',
                    'altNumber' => 'ALT-1',
                    'cifNo' => 'CIF-1',
                    'cifNoAlt' => 'CIF-ALT-1',
                    'customerName' => 'Jane Customer',
                    'collectability' => '1',
                    'dpd' => 7,
                    'productID' => 'PRD',
                    'productName' => 'Loan Product',
                    'startPeriod' => '20250101',
                    'endPeriod' => '2026-01-01',
                    'creditLimit' => '500000000.00',
                ],
            ]),
        ]);

        $result = app(PerformLoanInquiryAction::class)->handle($receivable, $user);

        $this->assertSame('001', $result->branch_code);
        $this->assertSame('Jane Customer', $result->customer_name);
        $this->assertSame('230929055.00', $result->loan_outstanding);
        $this->assertSame('230929055.00', $result->receivable_amount);
        $this->assertSame('500000000.00', $result->credit_limit);
        $this->assertSame('2025-01-01', $result->start_period?->toDateString());
        $this->assertSame('2026-01-01', $result->end_period?->toDateString());
        $this->assertSame(ClaimStatus::DEFAULT_CODE, $result->claimStatus->code);

        Http::assertSent(function (Request $request) use ($expectedRawBody): bool {
            return $request->url() === 'http://core.test/inquiry/detail/loan'
                && $request->body() === $expectedRawBody
                && $request->header('Signature')[0] === hash_hmac('sha256', $expectedRawBody, 'secret-key');
        });

        $log = ApiIntegrationLog::query()->sole();
        $this->assertSame('core_banking', $log->service_name);
        $this->assertSame('/inquiry/detail/loan', $log->endpoint);
        $this->assertSame($expectedLogRequestBody, $log->request_body);
        $this->assertSame('[masked]', $log->request_headers['Signature']);
        $this->assertSame('00', $log->response_body['responseCode']);
        $this->assertTrue($log->is_success);
    }

    public function test_branch_mismatch_fails_validation(): void
    {
        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        $this->seedPhaseTwoDependencies();
        $user = $this->branchUser('001');
        $receivable = $this->draftReceivableFor($user);

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'branchCode' => '002',
                    'loanOutStanding' => '230929055.00',
                    'accountNumber' => '3010001000054745',
                ],
            ]),
        ]);

        $this->expectException(ValidationException::class);

        app(PerformLoanInquiryAction::class)->handle($receivable, $user);
    }

    public function test_super_admin_can_inquire_different_branch(): void
    {
        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        $this->seedPhaseTwoDependencies();
        $user = $this->superAdminUser();
        $receivable = $this->draftReceivableFor($user);

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'branchCode' => '002',
                    'loanOutStanding' => '230929055.00',
                    'accountNumber' => '3010001000054745',
                    'customerName' => 'Jane Customer',
                ],
            ]),
        ]);

        $result = app(PerformLoanInquiryAction::class)->handle($receivable, $user);

        $expectedBranch = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        $this->assertSame('002', $result->branch_code);
        $this->assertSame($expectedBranch->id, $result->branch_office_id);
        $this->assertSame('Jane Customer', $result->customer_name);
    }

    public function test_draft_defaults_claim_status_to_on_process(): void
    {
        $this->seedPhaseTwoDependencies();
        $user = $this->branchUser('001');
        $receivable = $this->draftReceivableFor($user);

        $this->assertSame(ClaimStatus::DEFAULT_CODE, $receivable->claimStatus->code);
        $this->assertSame('001', $receivable->branch_code);
        $this->assertSame($user->id, $receivable->created_by);
    }

    private function seedPhaseTwoDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function branchUser(string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole('branch_maker');

        return $user;
    }

    private function superAdminUser(): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', '000')->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function draftReceivableFor(User $user): InsuranceReceivable
    {
        $data = app(PrepareInsuranceReceivableDraftAction::class)->handle([
            'loan_account_number' => '3010001000054745',
            'date_of_death' => '2026-01-15',
            'insurance_company_id' => InsuranceCompany::query()->where('name', 'SDI')->firstOrFail()->id,
        ], $user);

        return InsuranceReceivable::query()->create($data);
    }
}
