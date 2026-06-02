<?php

namespace App\Filament\Resources\CkpnWorkpapers\Schemas;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\GeneratedExport;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CkpnWorkpaperInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->schema([
                        TextEntry::make('period')->date(),
                        TextEntry::make('branchOffice.branch_name')
                            ->label('Branch')
                            ->placeholder('All branches'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('generation_status')
                            ->label('Generation status')
                            ->state(fn (CkpnWorkpaper $record): string => self::generationStatus($record))
                            ->badge(),
                        TextEntry::make('generation_message')
                            ->label('Generation message')
                            ->state(fn (CkpnWorkpaper $record): ?string => self::generationMessage($record))
                            ->visible(fn (CkpnWorkpaper $record): bool => self::generationMessage($record) !== null)
                            ->columnSpanFull(),
                        TextEntry::make('total_receivable_amount')
                            ->label('Total receivable')
                            ->numeric(2),
                        TextEntry::make('total_calculated_ckpn_amount')
                            ->label('Total calculated CKPN')
                            ->numeric(2),
                        TextEntry::make('total_adjustment_delta')
                            ->label('Total adjustment delta')
                            ->numeric(2),
                        TextEntry::make('total_effective_ckpn_amount')
                            ->label('Total effective CKPN')
                            ->numeric(2),
                        TextEntry::make('total_ckpn_amount')
                            ->label('Final CKPN')
                            ->numeric(2),
                        TextEntry::make('creator.name')->label('Created by'),
                        TextEntry::make('approver.name')->label('Approved by'),
                        TextEntry::make('approved_at')->dateTime(),
                        TextEntry::make('generated_at')->dateTime(),
                    ])
                    ->columns(4),
                Section::make('Pending CKPN adjustments')
                    ->visible(fn (CkpnWorkpaper $record): bool => self::pendingAdjustmentCount($record) > 0)
                    ->schema([
                        TextEntry::make('pending_adjustment_summary')
                            ->label('Blocking adjustments')
                            ->state(fn (CkpnWorkpaper $record): string => self::pendingAdjustmentSummary($record))
                            ->columnSpanFull(),
                    ]),
                Section::make('Pending approval')
                    ->visible(fn (CkpnWorkpaper $record): bool => $record->approvalRequests()
                        ->where('status', ApprovalRequest::STATUS_SUBMITTED)
                        ->exists())
                    ->schema([
                        TextEntry::make('latest_approval_summary')
                            ->label('Request')
                            ->state(fn (CkpnWorkpaper $record): ?string => self::approvalSummary($record))
                            ->columnSpanFull(),
                    ]),
                Section::make('Output status')
                    ->schema([
                        TextEntry::make('latest_journal_summary')
                            ->label('Latest journal')
                            ->state(fn (CkpnWorkpaper $record): string => self::journalSummary($record))
                            ->columnSpanFull(),
                        TextEntry::make('latest_export_summary')
                            ->label('Latest export')
                            ->state(fn (CkpnWorkpaper $record): string => self::exportSummary($record))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function generationStatus(CkpnWorkpaper $record): string
    {
        return match ($record->status) {
            CkpnWorkpaper::STATUS_GENERATION_QUEUED => 'generation_queued',
            CkpnWorkpaper::STATUS_GENERATION_PROCESSING => 'generation_processing',
            CkpnWorkpaper::STATUS_GENERATION_FAILED => 'generation_failed',
            CkpnWorkpaper::STATUS_GENERATED => 'generated',
            default => $record->status,
        };
    }

    private static function generationMessage(CkpnWorkpaper $record): ?string
    {
        return match ($record->status) {
            CkpnWorkpaper::STATUS_GENERATION_QUEUED,
            CkpnWorkpaper::STATUS_GENERATION_PROCESSING => 'CKPN generation is being processed in the background.',
            CkpnWorkpaper::STATUS_GENERATION_FAILED => $record->last_error_message ?: 'CKPN generation failed.',
            default => null,
        };
    }

    private static function pendingAdjustmentCount(CkpnWorkpaper $record): int
    {
        return $record->adjustments()
            ->whereIn('status', [
                CkpnAdjustment::STATUS_DRAFT,
                CkpnAdjustment::STATUS_SUBMITTED,
                CkpnAdjustment::STATUS_RETURNED,
            ])
            ->count();
    }

    private static function pendingAdjustmentSummary(CkpnWorkpaper $record): string
    {
        $adjustments = $record->adjustments()
            ->whereIn('status', [
                CkpnAdjustment::STATUS_DRAFT,
                CkpnAdjustment::STATUS_SUBMITTED,
                CkpnAdjustment::STATUS_RETURNED,
            ])
            ->orderBy('id')
            ->get(['id', 'status']);

        $summary = $adjustments
            ->take(10)
            ->map(fn (CkpnAdjustment $adjustment): string => "#{$adjustment->id} ({$adjustment->status})")
            ->join(', ');

        $suffix = $adjustments->count() > 10 ? ', ...' : '';

        return "{$adjustments->count()} pending adjustment(s): {$summary}{$suffix}";
    }

    private static function approvalSummary(CkpnWorkpaper $record): ?string
    {
        $approval = $record->approvalRequests()
            ->with(['steps', 'submitter'])
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->first();

        if (! $approval instanceof ApprovalRequest) {
            return null;
        }

        $pendingStep = $approval->steps
            ->first(fn (ApprovalStep $step): bool => $step->status === ApprovalStep::STATUS_PENDING);

        return collect([
            "Workflow: {$approval->workflow_code}",
            "Status: {$approval->status}",
            $pendingStep ? "Pending step: {$pendingStep->role_name}" : null,
            $approval->submitter ? "Submitted by: {$approval->submitter->name}" : null,
            $approval->submitted_at ? "Submitted at: {$approval->submitted_at->toDateTimeString()}" : null,
        ])->filter()->join("\n");
    }

    private static function journalSummary(CkpnWorkpaper $record): string
    {
        $journal = $record->journals()
            ->with('approver')
            ->latest('id')
            ->first();

        if (! $journal instanceof CkpnJournal) {
            return 'No CKPN journal created.';
        }

        return collect([
            "Journal #{$journal->id}",
            "Status: {$journal->status}",
            "Amount: {$journal->total_amount}",
            $journal->approver ? "Approved by: {$journal->approver->name}" : null,
            $journal->approved_at ? "Approved at: {$journal->approved_at->toDateTimeString()}" : null,
        ])->filter()->join("\n");
    }

    private static function exportSummary(CkpnWorkpaper $record): string
    {
        $export = $record->generatedExports()
            ->latest('id')
            ->first();

        if (! $export instanceof GeneratedExport) {
            return 'No export generated.';
        }

        return collect([
            "Export #{$export->id}",
            "Type: {$export->export_type}",
            "Status: {$export->status}",
            $export->file_path ? 'File: '.basename($export->file_path) : null,
            $export->generated_at ? "Generated at: {$export->generated_at->toDateTimeString()}" : null,
        ])->filter()->join("\n");
    }
}
