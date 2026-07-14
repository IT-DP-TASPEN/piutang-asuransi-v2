<?php

namespace App\Filament\Resources\ApprovalQueues\Pages;

use App\Filament\Resources\ApprovalQueues\ApprovalQueueResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListApprovalQueue extends ListRecords
{
    protected static string $resource = ApprovalQueueResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'my_pending';
    }

    public function getTabs(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $tabs = [
            'my_pending' => Tab::make('My Pending')
                ->query(fn (Builder $query): Builder => ApprovalQueueResource::scopeToMyPending($query, $user)),
        ];

        if (ApprovalQueueResource::canViewAllPending($user)) {
            $tabs['all_pending'] = Tab::make('All Pending')
                ->query(fn (Builder $query): Builder => ApprovalQueueResource::scopeToAllPending($query));
        }

        $tabs['my_requests'] = Tab::make('My Requests')
            ->query(fn (Builder $query): Builder => ApprovalQueueResource::scopeToMyRequests($query, $user));

        $tabs['history'] = Tab::make('History')
            ->query(fn (Builder $query): Builder => ApprovalQueueResource::scopeToHistory($query, $user));

        return $tabs;
    }
}
