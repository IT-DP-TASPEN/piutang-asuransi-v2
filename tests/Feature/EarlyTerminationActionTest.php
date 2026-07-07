<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationAction;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\EarlyTerminationTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EarlyTerminationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_early_termination_payload_signing_storage_and_retry_reuses_reference(): void
    {
        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        Carbon::setTestNow('2026-05-31 10:20:30');
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_approver', '000');
        $receivable = InsuranceReceivable::factory()->create([
            'branch_code' => '001',
            'branch_office_id' => BranchOffice::query()->where('branch_code', '001')->firstOrFail()->id,
            'loan_account_number' => '3010010000000068',
            'alt_number' => 'ALT-1',
            'loan_outstanding' => '230929055.00',
            'receivable_amount' => '230929055.00',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
        ]);
        $expectedRawBody = '{"trxReference":"PA-ET20260531102030","accountNumber":"3010010000000068","altNumber":"ALT-1","principalPaid":230929055,"interestPaid":0,"penaltyPaid":0,"principalWaive":0,"interestWaive":0,"description":"Pelunasan Debitur MD","branchCode":"001"}';
        $expectedLogRequestBody = json_decode($expectedRawBody, true, flags: JSON_THROW_ON_ERROR);

        Http::fake([
            'http://core.test/loan/earlytermination/' => Http::sequence()
                ->push([
                    'responseCode' => '99',
                    'description' => 'Temporary failure',
                    'data' => [],
                ])
                ->push([
                    'responseCode' => '00',
                    'description' => 'Success',
                    'data' => [
                        'transactionId' => 'TRX-1',
                        'journalId' => 'JRN-1',
                        'trxReference' => 'CORE-REF-1',
                        'alternateNumber' => 'ALT-1',
                        'status' => 'SUCCESS',
                    ],
                ]),
        ]);

        $first = app(ExecuteEarlyTerminationAction::class)->handle($receivable, $user);
        $this->assertSame(EarlyTerminationTransaction::STATUS_FAILED, $first->status);
        $this->assertSame('PA-ET20260531102030', $first->trx_reference);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $receivable->refresh()->workflow_status);

        $second = app(ExecuteEarlyTerminationAction::class)->handle($receivable->refresh(), $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('PA-ET20260531102030', $second->trx_reference);
        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $second->status);
        $this->assertSame('TRX-1', $second->transaction_id);
        $this->assertSame('JRN-1', $second->journal_id);
        $this->assertSame('CORE-REF-1', $second->core_trx_reference);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED, $receivable->refresh()->workflow_status);
        $this->assertSame([
            'trxReference' => 'PA-ET20260531102030',
            'accountNumber' => '3010010000000068',
            'altNumber' => 'ALT-1',
            'principalPaid' => 230929055,
            'interestPaid' => 0,
            'penaltyPaid' => 0,
            'principalWaive' => 0,
            'interestWaive' => 0,
            'description' => 'Pelunasan Debitur MD',
            'branchCode' => '001',
        ], $second->request_payload);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($expectedRawBody): bool {
            return $request->url() === 'http://core.test/loan/earlytermination/'
                && $request->body() === $expectedRawBody
                && $request->header('Signature')[0] === hash_hmac('sha256', $expectedRawBody, 'secret-key');
        });

        $this->assertSame(2, ApiIntegrationLog::query()->count());
        ApiIntegrationLog::query()->each(function (ApiIntegrationLog $log) use ($expectedLogRequestBody): void {
            $this->assertSame('/loan/earlytermination/', $log->endpoint);
            $this->assertSame($expectedLogRequestBody, $log->request_body);
            $this->assertSame('[masked]', $log->request_headers['Signature']);
        });

        $logs = ApiIntegrationLog::query()->orderBy('id')->get();
        $this->assertSame('99', $logs[0]->response_body['responseCode']);
        $this->assertSame('00', $logs[1]->response_body['responseCode']);

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

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }
}
