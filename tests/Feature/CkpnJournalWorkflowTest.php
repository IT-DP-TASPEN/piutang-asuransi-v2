<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\SubmitCkpnJournalAction;
use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use App\Filament\Resources\CkpnJournals\Pages\EditCkpnJournal;
use App\Filament\Resources\CkpnJournals\Pages\ListCkpnJournals;
use App\Filament\Resources\CkpnJournals\Pages\ViewCkpnJournal;
use App\Jobs\ExecuteGlToGlJob;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CkpnJournalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_maker_submits_ckpn_journal_from_view_page(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $journal = $this->journal(CkpnJournal::STATUS_DRAFT, '1000.00');
        $journal->forceFill(['total_amount' => '1.00'])->save();

        Livewire::actingAs($maker)
            ->test(ViewCkpnJournal::class, ['record' => $journal->id])
            ->assertActionVisible('submit')
            ->callAction('submit');

        $journal = $journal->refresh();

        $this->assertSame(CkpnJournal::STATUS_SUBMITTED, $journal->status);
        $this->assertSame('1000.00', $journal->total_amount);
        $this->assertSame(ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL, ApprovalRequest::query()->sole()->workflow_code);
    }

    public function test_accounting_approver_approves_rejects_and_returns_from_view_page(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');

        Queue::fake();
        $approved = app(SubmitCkpnJournalAction::class)->handle($this->journal(), $maker);
        Livewire::actingAs($approver)
            ->test(ViewCkpnJournal::class, ['record' => $approved->id])
            ->assertActionVisible('approve')
            ->callAction('approve');
        Queue::assertPushed(ExecuteGlToGlJob::class, fn (ExecuteGlToGlJob $job): bool => $job->ckpnJournalId === $approved->id);
        $this->assertSame(CkpnJournal::STATUS_GL_TO_GL_QUEUED, $approved->refresh()->status);

        $rejected = app(SubmitCkpnJournalAction::class)->handle($this->journal(), $maker);
        Livewire::actingAs($approver)
            ->test(ViewCkpnJournal::class, ['record' => $rejected->id])
            ->assertActionVisible('reject')
            ->callAction('reject');
        $this->assertSame(CkpnJournal::STATUS_REJECTED, $rejected->refresh()->status);

        $returned = app(SubmitCkpnJournalAction::class)->handle($this->journal(), $maker);
        Livewire::actingAs($approver)
            ->test(ViewCkpnJournal::class, ['record' => $returned->id])
            ->assertActionVisible('returnRequest')
            ->callAction('returnRequest');
        $this->assertSame(CkpnJournal::STATUS_RETURNED, $returned->refresh()->status);
    }

    public function test_submitted_ckpn_journal_is_not_editable_and_returned_edit_ignores_total_amount(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $journal = app(SubmitCkpnJournalAction::class)->handle($this->journal(), $maker);

        $this->assertFalse($maker->can('update', $journal));
        $this->actingAs($maker)
            ->get(CkpnJournalResource::getUrl('edit', ['record' => $journal]))
            ->assertForbidden();

        $journal->forceFill(['status' => CkpnJournal::STATUS_RETURNED])->save();

        Livewire::actingAs($maker)
            ->test(EditCkpnJournal::class, ['record' => $journal->id])
            ->set('data.debit_account', 'D-NEW')
            ->set('data.credit_account', 'C-NEW')
            ->set('data.debit_narrative', 'Debit new')
            ->set('data.credit_narrative', 'Credit new')
            ->set('data.description', 'Description new')
            ->set('data.total_amount', '999999.00')
            ->call('save');

        $journal = $journal->refresh();

        $this->assertSame('D-NEW', $journal->debit_account);
        $this->assertSame('C-NEW', $journal->credit_account);
        $this->assertSame('Debit new', $journal->debit_narrative);
        $this->assertSame('Credit new', $journal->credit_narrative);
        $this->assertSame('Description new', $journal->description);
        $this->assertSame('1000.00', $journal->total_amount);
    }

    public function test_list_page_bulk_submit_action_visibility_uses_submit_permission(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $businessMaker = $this->userWithRole('business_maker', '000');

        Livewire::actingAs($maker)
            ->test(ListCkpnJournals::class)
            ->assertTableBulkActionVisible('submitSelectedJournals')
            ->assertTableBulkActionHasLabel('submitSelectedJournals', 'Submit Selected Journals');

        Livewire::actingAs($businessMaker)
            ->test(ListCkpnJournals::class)
            ->assertTableBulkActionHidden('submitSelectedJournals');
    }

    public function test_list_page_bulk_submit_action_submits_selected_draft_and_returned_journals(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $draft = $this->journal(CkpnJournal::STATUS_DRAFT, '1000.00', '2027-01-31', '001');
        $returned = $this->journal(CkpnJournal::STATUS_RETURNED, '2000.00', '2027-01-31', '002');
        $draft->approvalRequests()->create([
            'workflow_code' => ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL,
            'status' => ApprovalRequest::STATUS_REJECTED,
            'submitted_by' => $maker->id,
            'submitted_at' => now(),
        ]);
        $returned->approvalRequests()->create([
            'workflow_code' => ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL,
            'status' => ApprovalRequest::STATUS_RETURNED,
            'submitted_by' => $maker->id,
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($maker)
            ->test(ListCkpnJournals::class)
            ->callTableBulkAction('submitSelectedJournals', [$returned, $draft])
            ->assertNotified('2 CKPN Journals have been submitted for approval.');

        $this->assertSame(CkpnJournal::STATUS_SUBMITTED, $draft->refresh()->status);
        $this->assertSame(CkpnJournal::STATUS_SUBMITTED, $returned->refresh()->status);
        $this->assertSame('1000.00', $draft->total_amount);
        $this->assertSame('2000.00', $returned->total_amount);
        $this->assertSame(
            [ApprovalRequest::STATUS_REJECTED, ApprovalRequest::STATUS_SUBMITTED],
            $draft->approvalRequests()->orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame(
            [ApprovalRequest::STATUS_RETURNED, ApprovalRequest::STATUS_SUBMITTED],
            $returned->approvalRequests()->orderBy('id')->pluck('status')->all(),
        );
        Queue::assertNotPushed(ExecuteGlToGlJob::class);
    }

    public function test_list_page_bulk_submit_action_notifies_validation_failure_without_partial_submit(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $draft = $this->journal(CkpnJournal::STATUS_DRAFT, '1000.00', '2027-02-28', '001');
        $rejected = $this->journal(CkpnJournal::STATUS_REJECTED, '1000.00', '2027-02-28', '002');

        Livewire::actingAs($maker)
            ->test(ListCkpnJournals::class)
            ->callTableBulkAction('submitSelectedJournals', [$draft, $rejected])
            ->assertNotified('1 selected CKPN Journals are not eligible for submission.');

        $this->assertSame(CkpnJournal::STATUS_DRAFT, $draft->refresh()->status);
        $this->assertSame(CkpnJournal::STATUS_REJECTED, $rejected->refresh()->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_bulk_submit_requires_same_cutoff_and_blocks_active_approval_requests(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $first = $this->journal(CkpnJournal::STATUS_DRAFT, '1000.00', '2027-03-31', '001');
        $second = $this->journal(CkpnJournal::STATUS_DRAFT, '1000.00', '2027-04-30', '002');

        try {
            app(SubmitCkpnJournalAction::class)->handleMany([$first, $second], $maker);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame('Selected CKPN Journals must have the same cutoff date.', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(CkpnJournal::STATUS_DRAFT, $first->refresh()->status);
        $this->assertSame(CkpnJournal::STATUS_DRAFT, $second->refresh()->status);

        $second->ckpnWorkpaper->forceFill(['period' => '2027-03-31'])->save();
        $second->approvalRequests()->create([
            'workflow_code' => ApprovalRequest::WORKFLOW_CKPN_JOURNAL_APPROVAL,
            'status' => ApprovalRequest::STATUS_SUBMITTED,
            'submitted_by' => $maker->id,
            'submitted_at' => now(),
        ]);

        try {
            app(SubmitCkpnJournalAction::class)->handleMany([$first->refresh(), $second->refresh()], $maker);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame('One or more selected CKPN Journals already have active approval requests.', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(CkpnJournal::STATUS_DRAFT, $first->refresh()->status);
        $this->assertSame(CkpnJournal::STATUS_DRAFT, $second->refresh()->status);
        $this->assertSame(1, ApprovalRequest::query()->count());
    }

    public function test_submit_fails_clearly_when_journal_has_no_workpaper(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $journal = new CkpnJournal([
            'branch_office_id' => $branch->id,
            'journal_date' => '2027-05-31',
            'total_amount' => '1000.00',
            'status' => CkpnJournal::STATUS_DRAFT,
        ]);

        try {
            app(SubmitCkpnJournalAction::class)->handle($journal, $maker);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame('Selected CKPN Journals must have related CKPN Workpapers.', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function journal(
        string $status = CkpnJournal::STATUS_DRAFT,
        string $effectiveTotal = '1000.00',
        ?string $period = null,
        string $branchCode = '001',
    ): CkpnJournal {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $month = CkpnWorkpaper::query()->count() + 1;
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => $period ?? sprintf('2026-%02d-01', $month),
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_APPROVED,
            'total_receivable_amount' => '10000.00',
            'total_calculated_ckpn_amount' => $effectiveTotal,
            'total_adjustment_delta' => '0.00',
            'total_effective_ckpn_amount' => $effectiveTotal,
            'total_ckpn_amount' => $effectiveTotal,
        ]);

        return CkpnJournal::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'branch_office_id' => $branch->id,
            'journal_date' => '2026-05-31',
            'total_amount' => $effectiveTotal,
            'status' => $status,
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
