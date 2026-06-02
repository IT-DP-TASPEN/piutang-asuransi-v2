<?php

namespace App\Filament\Resources\CkpnJournals\Pages;

use App\Filament\Resources\CkpnJournals\CkpnJournalResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCkpnJournal extends EditRecord
{
    protected static string $resource = CkpnJournalResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->getRecord()->isEditable(), 403);
        abort_unless(auth()->user()?->can('update', $this->getRecord()) ?? false, 403);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return collect($data)
            ->only([
                'debit_account',
                'credit_account',
                'debit_narrative',
                'credit_narrative',
                'description',
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
