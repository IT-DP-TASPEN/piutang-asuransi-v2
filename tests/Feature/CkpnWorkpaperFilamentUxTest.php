<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Filament\Resources\CkpnWorkpapers\Pages\CreateCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\Pages\EditCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\Pages\ListCkpnWorkpapers;
use App\Filament\Resources\CkpnWorkpapers\Pages\ViewCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\RelationManagers\ItemsRelationManager;
use App\Jobs\GenerateCkpnWorkpaperJob;
use App\Models\BranchOffice;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\CkpnWorkpaperItem;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\DatePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CkpnWorkpaperFilamentUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_workpaper_resource_has_view_route_and_items_relation(): void
    {
        $pages = CkpnWorkpaperResource::getPages();

        $this->assertArrayHasKey('view', $pages);
        $this->assertContains(ItemsRelationManager::class, CkpnWorkpaperResource::getRelations());
    }

    public function test_create_page_dispatches_generation_and_redirects_to_view(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        $component = Livewire::actingAs($maker)
            ->test(CreateCkpnWorkpaper::class)
            ->assertSee('Tanggal Cutoff')
            ->set('data.period', '2026-04-30')
            ->set('data.branch_office_id', $branch->id)
            ->call('create');

        $workpaper = CkpnWorkpaper::query()->sole();

        $this->assertSame('2026-04-30', $workpaper->period->toDateString());
        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $workpaper->status);
        Queue::assertPushed(GenerateCkpnWorkpaperJob::class, fn (GenerateCkpnWorkpaperJob $job): bool => $job->ckpnWorkpaperId === $workpaper->id);
        $component->assertRedirect(CkpnWorkpaperResource::getUrl('view', ['record' => $workpaper]));
    }

    public function test_workpaper_pages_label_period_as_tanggal_cutoff(): void
    {
        $this->seedDependencies();
        $superAdmin = $this->userWithRole('super_admin', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_DRAFT);

        Livewire::actingAs($superAdmin)
            ->test(ListCkpnWorkpapers::class)
            ->assertSee('Tanggal Cutoff');

        Livewire::actingAs($superAdmin)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertSee('Tanggal Cutoff');
    }

    public function test_list_page_bulk_create_action_is_visible_to_authorized_user_with_cutoff_only_form(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');

        Livewire::actingAs($maker)
            ->test(ListCkpnWorkpapers::class)
            ->assertActionVisible('createAllBranchWorkpapers')
            ->assertActionHasLabel('createAllBranchWorkpapers', 'Create All Branch Workpapers')
            ->mountAction('createAllBranchWorkpapers')
            ->assertFormFieldExists('period', null, fn (DatePicker $field): bool => $field->getLabel() === 'Tanggal Cutoff')
            ->assertFormFieldDoesNotExist('branch_office_id');
    }

    public function test_list_page_bulk_create_action_is_hidden_from_unauthorized_user(): void
    {
        $this->seedDependencies();
        $businessMaker = $this->userWithRole('business_maker', '000');

        Livewire::actingAs($businessMaker)
            ->test(ListCkpnWorkpapers::class)
            ->assertActionHidden('createAllBranchWorkpapers');
    }

    public function test_list_page_bulk_create_action_queues_all_active_branch_workpapers(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $expectedCount = BranchOffice::query()
            ->where('is_active', true)
            ->where('branch_code', '!=', '000')
            ->count();

        Livewire::actingAs($maker)
            ->test(ListCkpnWorkpapers::class)
            ->callAction('createAllBranchWorkpapers', ['period' => '2026-11-15'])
            ->assertNotified("{$expectedCount} CKPN Workpapers have been queued for generation.");

        $this->assertSame($expectedCount, CkpnWorkpaper::query()->whereDate('period', '2026-11-15')->count());
        $this->assertFalse(CkpnWorkpaper::query()->whereDate('period', '2026-11-15')->whereNull('branch_office_id')->exists());
        Queue::assertPushed(GenerateCkpnWorkpaperJob::class, $expectedCount);
    }

    public function test_list_page_bulk_create_action_notifies_validation_failure(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        CkpnWorkpaper::query()->create([
            'period' => '2026-11-15',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_CANCELLED,
        ]);

        Livewire::actingAs($maker)
            ->test(ListCkpnWorkpapers::class)
            ->callAction('createAllBranchWorkpapers', ['period' => '2026-11-15'])
            ->assertNotified("Cannot create CKPN Workpapers because one or more branches already have workpapers for cutoff date 2026-11-15: {$branch->branch_name}.");

        $this->assertSame(1, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_create_page_notifies_when_pending_receivables_block_workpaper_creation(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'date_of_death' => '2026-04-15',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
        ]);

        Livewire::actingAs($maker)
            ->test(CreateCkpnWorkpaper::class)
            ->set('data.period', '2026-04-30')
            ->set('data.branch_office_id', $branch->id)
            ->call('create')
            ->assertNoRedirect()
            ->assertNotified('Cannot create CKPN Workpaper because 1 Insurance Receivables are still pending.');

        $this->assertSame(0, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_list_page_uses_view_as_primary_action_and_hides_edit_when_not_editable(): void
    {
        $this->seedDependencies();
        $superAdmin = $this->userWithRole('super_admin', '000');
        $draft = $this->workpaper(CkpnWorkpaper::STATUS_DRAFT);
        $submitted = $this->workpaper(CkpnWorkpaper::STATUS_SUBMITTED);
        $approved = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED);
        $locked = $this->workpaper(CkpnWorkpaper::STATUS_LOCKED);

        Livewire::actingAs($superAdmin)
            ->test(ListCkpnWorkpapers::class)
            ->assertTableActionsExistInOrder(['view', 'edit', 'delete'])
            ->assertTableActionVisible('view', $draft)
            ->assertTableActionVisible('edit', $draft)
            ->assertTableActionHidden('edit', $submitted)
            ->assertTableActionHidden('edit', $approved)
            ->assertTableActionHidden('edit', $locked);
    }

    public function test_view_page_exposes_workpaper_actions_by_status_and_permission(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $draft = $this->workpaper(CkpnWorkpaper::STATUS_DRAFT);
        $generated = $this->workpaper(CkpnWorkpaper::STATUS_GENERATED);
        $failed = $this->workpaper(CkpnWorkpaper::STATUS_GENERATION_FAILED);
        $queued = $this->workpaper(CkpnWorkpaper::STATUS_GENERATION_QUEUED);

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $draft->id])
            ->assertSee('Workpaper')
            ->assertActionHidden('retryGeneration')
            ->assertActionVisible('recalculate')
            ->assertActionHidden('submit');

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $generated->id])
            ->assertActionVisible('submit');

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $failed->id])
            ->assertActionVisible('retryGeneration')
            ->assertActionHidden('recalculate');

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $queued->id])
            ->assertActionHidden('recalculate')
            ->assertActionHidden('submit');
    }

    public function test_view_page_exposes_approval_actions_for_pending_workpaper(): void
    {
        $this->seedDependencies();
        $approver = $this->userWithRole('accounting_approver', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_SUBMITTED);

        Livewire::actingAs($approver)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertSee('Approval')
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->assertActionVisible('returnRequest');
    }

    public function test_view_page_exposes_output_actions_for_approved_workpaper(): void
    {
        $this->seedDependencies();
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $businessMaker = $this->userWithRole('business_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED);

        Livewire::actingAs($accountingMaker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertSee('Output')
            ->assertActionVisible('createJournal')
            ->assertActionVisible('generateSakepExport');

        Livewire::actingAs($businessMaker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertActionHidden('generateSakepExport')
            ->assertActionHidden('createJournal');

        $this->journal($workpaper, CkpnJournal::STATUS_DRAFT);

        Livewire::actingAs($accountingMaker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertActionHidden('createJournal');
    }

    public function test_create_journal_action_notifies_when_pending_adjustments_block_creation(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED);
        $item = $this->workpaperItem($workpaper);
        $adjustment = CkpnAdjustment::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'ckpn_workpaper_item_id' => $item->id,
            'adjustment_type' => CkpnAdjustment::TYPE_OVERRIDE_FINAL_CKPN_AMOUNT,
            'calculated_ckpn_rate' => '10.0000',
            'calculated_ckpn_amount' => '100.00',
            'requested_adjusted_ckpn_amount' => '200.00',
            'reason' => 'Pending blocker',
            'status' => CkpnAdjustment::STATUS_DRAFT,
            'requested_by' => $maker->id,
        ]);

        Livewire::actingAs($accountingMaker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertActionVisible('createJournal')
            ->callAction('createJournal', [
                'journal_date' => '2026-06-30',
            ])
            ->assertNotified("Cannot create CKPN Journal because 1 CKPN Adjustments are still pending: #{$adjustment->id} (draft).");

        $this->assertSame(0, CkpnJournal::query()->count());
    }

    public function test_view_page_exposes_retry_gl_to_gl_for_failed_related_journal(): void
    {
        $this->seedDependencies();
        $approver = $this->userWithRole('accounting_approver', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED);
        $this->journal($workpaper, CkpnJournal::STATUS_GL_TO_GL_FAILED);

        Livewire::actingAs($approver)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertSee('System')
            ->assertActionVisible('retryGlToGl');
    }

    public function test_edit_page_does_not_expose_workflow_or_operational_actions(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_DRAFT);

        Livewire::actingAs($maker)
            ->test(EditCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertActionDoesNotExist('generate')
            ->assertActionDoesNotExist('retryGeneration')
            ->assertActionDoesNotExist('recalculate')
            ->assertActionDoesNotExist('submit')
            ->assertActionDoesNotExist('approve')
            ->assertActionDoesNotExist('reject')
            ->assertActionDoesNotExist('returnRequest')
            ->assertActionDoesNotExist('createJournal')
            ->assertActionDoesNotExist('generateSakepExport')
            ->assertActionDoesNotExist('retryGlToGl');
    }

    public function test_duplicate_ckpn_journal_creation_is_blocked(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED);

        app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper, $maker);

        $this->expectException(ValidationException::class);

        app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper->refresh(), $maker);
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create([
            'branch_office_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function workpaper(string $status): CkpnWorkpaper
    {
        $month = CkpnWorkpaper::query()->count() + 1;
        $workpaper = CkpnWorkpaper::create([
            'period' => sprintf('2026-%02d-15', $month),
            'status' => $status,
            'total_receivable_amount' => '1000.00',
            'total_calculated_ckpn_amount' => '100.00',
            'total_adjustment_delta' => '0.00',
            'total_effective_ckpn_amount' => '100.00',
            'total_ckpn_amount' => '100.00',
        ]);

        if ($status === CkpnWorkpaper::STATUS_GENERATED) {
            $this->workpaperItem($workpaper);
        }

        return $workpaper;
    }

    private function workpaperItem(CkpnWorkpaper $workpaper): CkpnWorkpaperItem
    {
        return CkpnWorkpaperItem::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'receivable_type' => User::class,
            'receivable_id' => 1,
            'receivable_amount' => '1000.00',
            'age_days' => 30,
            'insurance_company_weight' => '0.0000',
            'age_weight' => '0.0000',
            'claim_status_weight' => '0.0000',
            'calculated_ckpn_rate' => '10.0000',
            'calculated_ckpn_amount' => '100.00',
            'effective_ckpn_rate' => '10.0000',
            'effective_ckpn_amount' => '100.00',
            'calculation_rule_code' => 'test',
        ]);
    }

    private function journal(CkpnWorkpaper $workpaper, string $status): CkpnJournal
    {
        return CkpnJournal::create([
            'ckpn_workpaper_id' => $workpaper->id,
            'branch_office_id' => $workpaper->branch_office_id,
            'journal_date' => '2026-06-30',
            'total_amount' => '100.00',
            'status' => $status,
        ]);
    }
}
