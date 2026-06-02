<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\SubmitCkpnJournalAction;
use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use App\Filament\Resources\CkpnJournals\Pages\EditCkpnJournal;
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

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function journal(string $status = CkpnJournal::STATUS_DRAFT, string $effectiveTotal = '1000.00'): CkpnJournal
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $month = CkpnWorkpaper::query()->count() + 1;
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => sprintf('2026-%02d-01', $month),
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
