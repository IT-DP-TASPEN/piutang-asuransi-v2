<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Filament\Resources\CkpnWorkpapers\Pages\EditCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\Pages\ListCkpnWorkpapers;
use App\Filament\Resources\CkpnWorkpapers\Pages\ViewCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\RelationManagers\ItemsRelationManager;
use App\Models\BranchOffice;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $maker = $this->userWithRole('business_maker', '000');
        $draft = $this->workpaper(CkpnWorkpaper::STATUS_DRAFT);
        $generated = $this->workpaper(CkpnWorkpaper::STATUS_GENERATED);

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $draft->id])
            ->assertSee('Workpaper')
            ->assertActionVisible('generate')
            ->assertActionVisible('recalculate')
            ->assertActionHidden('submit');

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $generated->id])
            ->assertActionVisible('submit');
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
        $maker = $this->userWithRole('business_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED);

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertSee('Output')
            ->assertActionVisible('createJournal')
            ->assertActionVisible('generateSakepExport');

        $this->journal($workpaper, CkpnJournal::STATUS_DRAFT);

        Livewire::actingAs($maker)
            ->test(ViewCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertActionHidden('createJournal');
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
        $maker = $this->userWithRole('business_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_DRAFT);

        Livewire::actingAs($maker)
            ->test(EditCkpnWorkpaper::class, ['record' => $workpaper->id])
            ->assertActionDoesNotExist('generate')
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
        $maker = $this->userWithRole('business_maker', '000');
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
        return CkpnWorkpaper::create([
            'period' => '2026-06-30',
            'status' => $status,
            'total_receivable_amount' => '1000.00',
            'total_ckpn_amount' => '100.00',
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
