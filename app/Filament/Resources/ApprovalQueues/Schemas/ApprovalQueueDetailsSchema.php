<?php

namespace App\Filament\Resources\ApprovalQueues\Schemas;

use App\Filament\Resources\ApprovalQueues\ApprovalQueueResource;
use App\Models\ApprovalLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Services\Approval\ApprovalQueueWorkflowAdapter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class ApprovalQueueDetailsSchema
{
    public static function make(ApprovalRequest $record, ApprovalQueueWorkflowAdapter $adapter): array
    {
        $record->loadMissing(['submitter', 'steps.actor', 'logs.actor']);

        $workflow = $adapter->label($record);
        $reference = $adapter->reference($record);
        $submittedBy = $adapter->submittedByLabel($record);
        $submittedAt = $record->submitted_at;
        $amount = $adapter->amountLabel($record);
        $snapshot = $adapter->snapshot($record);

        return [
            Section::make($workflow)
                ->description(collect([
                    $reference ? "Reference: {$reference}" : null,
                    'Submitted by '.($submittedBy ?: '-').' - '.($submittedAt?->toDateTimeString() ?? '-'),
                ])->filter()->join("\n"))
                ->afterHeader([
                    self::statusEntry('header.status', $record->status)
                        ->hiddenLabel(),
                ])
                ->compact()
                ->columns(2)
                ->schema([
                    TextEntry::make('header.reference')
                        ->label('Reference')
                        ->state($reference)
                        ->placeholder('-'),
                    TextEntry::make('header.summary')
                        ->label('Summary')
                        ->state($adapter->summary($record))
                        ->placeholder('-'),
                ]),

            Section::make('Approval Summary')
                ->columns(3)
                ->schema(array_values(array_filter([
                    TextEntry::make('summary.workflow')
                        ->label('Workflow')
                        ->state($workflow),
                    TextEntry::make('summary.reference')
                        ->label('Reference')
                        ->state($reference)
                        ->placeholder('-'),
                    self::statusEntry('summary.status', $record->status),
                    TextEntry::make('summary.submitted_by')
                        ->label('Submitted By')
                        ->state($submittedBy)
                        ->placeholder('-'),
                    TextEntry::make('summary.submitted_at')
                        ->label('Submitted At')
                        ->state($submittedAt)
                        ->dateTime()
                        ->placeholder('-'),
                    TextEntry::make('summary.age')
                        ->label('Age')
                        ->state($submittedAt?->diffForHumans(null, true, false, 2))
                        ->placeholder('-'),
                    $amount === null ? null : TextEntry::make('summary.amount')
                        ->label('Amount')
                        ->state($amount),
                ]))),

            Section::make('Object Snapshot')
                ->columns(2)
                ->visible($snapshot !== [])
                ->schema(self::snapshotEntries($snapshot)),

            Section::make('Approval Steps')
                ->schema([
                    RepeatableEntry::make('approval_steps')
                        ->hiddenLabel()
                        ->state(self::stepItems($record))
                        ->placeholder('-')
                        ->grid(1)
                        ->schema([
                            TextEntry::make('step')
                                ->label('Step'),
                            TextEntry::make('role')
                                ->label('Role')
                                ->placeholder('-'),
                            self::statusEntry('status', hasConstantState: false)
                                ->label('Status'),
                            TextEntry::make('actor')
                                ->label('Actor')
                                ->placeholder('-'),
                            TextEntry::make('at')
                                ->label('At')
                                ->dateTime()
                                ->placeholder('-'),
                            TextEntry::make('notes')
                                ->label('Notes')
                                ->placeholder('-')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

            Section::make('Timeline')
                ->schema([
                    RepeatableEntry::make('timeline')
                        ->hiddenLabel()
                        ->state(self::timelineItems($record))
                        ->placeholder('-')
                        ->grid(1)
                        ->schema([
                            TextEntry::make('action')
                                ->label('Action')
                                ->formatStateUsing(fn (?string $state): string => self::human($state)),
                            TextEntry::make('actor')
                                ->label('Actor')
                                ->placeholder('-'),
                            TextEntry::make('at')
                                ->label('At')
                                ->dateTime()
                                ->placeholder('-'),
                            TextEntry::make('notes')
                                ->label('Notes')
                                ->placeholder('-')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),
        ];
    }

    /**
     * @param  array<string, string|null>  $snapshot
     * @return array<TextEntry>
     */
    private static function snapshotEntries(array $snapshot): array
    {
        return collect($snapshot)
            ->map(fn (?string $value, string $label): TextEntry => TextEntry::make('snapshot.'.str($label)->slug('_')->toString())
                ->label($label)
                ->state($value)
                ->placeholder('-'))
            ->values()
            ->all();
    }

    /**
     * @return list<array{step: string, role: string, status: string|null, actor: string|null, at: mixed, notes: string|null}>
     */
    private static function stepItems(ApprovalRequest $record): array
    {
        return $record->steps
            ->sortBy('step_order')
            ->values()
            ->map(fn (ApprovalStep $step): array => [
                'step' => 'Step '.$step->step_order,
                'role' => match ($step->role_name) {
                    'branch_approver' => 'BM / Branch Approver',
                    'insurance_approver' => 'Manager Asuransi',
                    'business_approver' => 'Manager Bisnis',
                    null => 'Unassigned role',
                    default => self::human($step->role_name),
                },
                'status' => $step->status,
                'actor' => $step->actor?->name,
                'at' => $step->acted_at,
                'notes' => $step->notes,
            ])
            ->all();
    }

    /**
     * @return list<array{action: string|null, actor: string|null, at: mixed, notes: string|null}>
     */
    private static function timelineItems(ApprovalRequest $record): array
    {
        return $record->logs
            ->sortBy('created_at')
            ->values()
            ->map(fn (ApprovalLog $log): array => [
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'at' => $log->created_at,
                'notes' => $log->notes,
            ])
            ->all();
    }

    private static function statusEntry(string $name, ?string $state = null, bool $hasConstantState = true): TextEntry
    {
        $entry = TextEntry::make($name)
            ->label('Status')
            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
            ->badge()
            ->color(fn (?string $state): string => self::statusColor($state))
            ->placeholder('-');

        return $hasConstantState ? $entry->state($state) : $entry;
    }

    private static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '-';
        }

        return ApprovalQueueResource::statusOptions()[$status] ?? self::human($status);
    }

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            ApprovalRequest::STATUS_SUBMITTED,
            ApprovalStep::STATUS_PENDING,
            ApprovalRequest::STATUS_RETURNED,
            ApprovalStep::STATUS_RETURNED => 'warning',
            ApprovalRequest::STATUS_APPROVED,
            ApprovalStep::STATUS_APPROVED => 'success',
            ApprovalRequest::STATUS_REJECTED,
            ApprovalStep::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    private static function human(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return str($value)->replace('_', ' ')->headline()->toString();
    }
}
