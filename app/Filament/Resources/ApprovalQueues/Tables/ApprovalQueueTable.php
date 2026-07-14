<?php

namespace App\Filament\Resources\ApprovalQueues\Tables;

use App\Filament\Resources\ApprovalQueues\ApprovalQueueResource;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\User;
use App\Services\Approval\ApprovalQueueWorkflowRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApprovalQueueTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('submitted_at')
                    ->label('Submitted At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('age')
                    ->label('Age')
                    ->badge()
                    ->state(fn (ApprovalRequest $record): string => $record->submitted_at?->diffForHumans(null, true, false, 2) ?? '-')
                    ->color(function (ApprovalRequest $record): string {
                        $submittedAt = $record->submitted_at;

                        if ($submittedAt === null || $submittedAt->diffInDays(now()) < 1) {
                            return 'gray';
                        }

                        return $submittedAt->diffInDays(now()) <= 3 ? 'warning' : 'danger';
                    }),
                TextColumn::make('workflow_code')
                    ->label('Workflow')
                    ->badge()
                    ->state(fn (ApprovalRequest $record): string => static::adapter($record)->label($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => ApprovalQueueResource::applySearch($query, $search)),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->state(fn (ApprovalRequest $record): ?string => static::adapter($record)->reference($record))
                    ->placeholder('-'),
                TextColumn::make('summary')
                    ->label('Summary')
                    ->state(fn (ApprovalRequest $record): string => static::adapter($record)->summary($record))
                    ->limit(60)
                    ->tooltip(fn (ApprovalRequest $record): string => static::adapter($record)->summary($record)),
                TextColumn::make('branch')
                    ->label('Branch')
                    ->state(fn (ApprovalRequest $record): ?string => static::adapter($record)->branchLabel($record))
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (ApprovalRequest $record): ?string => static::adapter($record)->amountLabel($record))
                    ->placeholder('-'),
                TextColumn::make('submitter.name')
                    ->label('Submitted By')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ApprovalQueueResource::statusOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ApprovalRequest::STATUS_SUBMITTED => 'warning',
                        ApprovalRequest::STATUS_APPROVED => 'success',
                        ApprovalRequest::STATUS_REJECTED => 'danger',
                        ApprovalRequest::STATUS_RETURNED => 'gray',
                        ApprovalRequest::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('workflow_code')
                    ->label('Workflow')
                    ->options(ApprovalQueueResource::workflowOptions()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ApprovalQueueResource::statusOptions()),
                SelectFilter::make('branch')
                    ->label('Branch')
                    ->options(fn (): array => BranchOffice::query()->orderBy('branch_code')->pluck('branch_name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? ApprovalQueueResource::applyBranchFilter($query, (int) $data['value'])
                        : $query),
                Filter::make('submitted_date')
                    ->schema([
                        DatePicker::make('from')->label('Submitted from'),
                        DatePicker::make('until')->label('Submitted until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('submitted_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('submitted_at', '<=', $date))),
                SelectFilter::make('submitted_by')
                    ->label('Submitted By')
                    ->relationship('submitter', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('cutoff_date')
                    ->schema([
                        DatePicker::make('cutoff_date')->label('CKPN cutoff date'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['cutoff_date'] ?? null)
                        ? ApprovalQueueResource::applyCutoffFilter($query, (string) $data['cutoff_date'])
                        : $query),
            ])
            ->recordActions([
                static::detailsAction(),
                static::viewOriginalAction(),
                static::approveAction(),
                static::returnAction(),
                static::rejectAction(),
            ])
            ->toolbarActions([]);
    }

    private static function detailsAction(): Action
    {
        return Action::make('details')
            ->label('Details')
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->modalHeading('Approval Request')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (ApprovalRequest $record) => view('filament.approval-queue.details', [
                'record' => $record,
                'adapter' => static::adapter($record),
            ]));
    }

    private static function viewOriginalAction(): Action
    {
        return Action::make('viewOriginal')
            ->label('View Original')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (ApprovalRequest $record): ?string => static::adapter($record)->detailRoute($record))
            ->visible(function (ApprovalRequest $record): bool {
                $user = auth()->user();

                return $user instanceof User
                    && static::adapter($record)->detailRoute($record) !== null
                    && static::adapter($record)->canView($record, $user);
            });
    }

    private static function approveAction(): Action
    {
        return static::mutationAction('approve', 'Approve', 'success', Heroicon::OutlinedCheckCircle);
    }

    private static function returnAction(): Action
    {
        return static::mutationAction('return', 'Return', 'warning', Heroicon::OutlinedArrowUturnLeft);
    }

    private static function rejectAction(): Action
    {
        return static::mutationAction('reject', 'Reject', 'danger', Heroicon::OutlinedXCircle);
    }

    private static function mutationAction(string $name, string $label, string $color, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription(fn (ApprovalRequest $record): ?string => static::adapter($record)->confirmationDescription($name, $record))
            ->form(fn (ApprovalRequest $record): array => [
                Textarea::make('notes')
                    ->label('Notes')
                    ->required(fn (): bool => static::adapter($record)->notesRequired($name, $record))
                    ->maxLength(65535),
            ])
            ->visible(function (ApprovalRequest $record) use ($name): bool {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return false;
                }

                $adapter = static::adapter($record);

                return match ($name) {
                    'approve' => $adapter->canApprove($record, $user),
                    'return' => $adapter->canReturn($record, $user),
                    'reject' => $adapter->canReject($record, $user),
                    default => false,
                };
            })
            ->action(fn (ApprovalRequest $record, array $data): bool => ApprovalQueueResource::runQueueAction(
                record: $record,
                action: $name,
                notes: $data['notes'] ?? null,
            ));
    }

    private static function adapter(ApprovalRequest $request)
    {
        return app(ApprovalQueueWorkflowRegistry::class)->adapterFor($request);
    }
}
