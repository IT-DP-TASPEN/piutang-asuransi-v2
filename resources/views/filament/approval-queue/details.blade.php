@php
    $snapshot = $adapter->snapshot($record);
@endphp

<div class="space-y-6 text-sm">
    <div class="grid gap-3 md:grid-cols-2">
        <div>
            <div class="text-xs font-medium text-gray-500">Workflow</div>
            <div>{{ $adapter->label($record) }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Reference</div>
            <div>{{ $adapter->reference($record) ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Submitted By</div>
            <div>{{ $adapter->submittedByLabel($record) ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Submitted At</div>
            <div>{{ $record->submitted_at?->toDateTimeString() ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Status</div>
            <div>{{ \App\Filament\Resources\ApprovalQueues\ApprovalQueueResource::statusOptions()[$record->status] ?? $record->status }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500">Amount</div>
            <div>{{ $adapter->amountLabel($record) ?? '-' }}</div>
        </div>
    </div>

    <div>
        <div class="text-xs font-medium text-gray-500">Summary</div>
        <div>{{ $adapter->summary($record) }}</div>
    </div>

    @if (count($snapshot))
        <div>
            <div class="mb-2 text-xs font-medium text-gray-500">Object Snapshot</div>
            <dl class="grid gap-3 md:grid-cols-2">
                @foreach ($snapshot as $label => $value)
                    <div>
                        <dt class="text-xs font-medium text-gray-500">{{ $label }}</dt>
                        <dd>{{ filled($value) ? $value : '-' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    <div>
        <div class="mb-2 text-xs font-medium text-gray-500">Approval Steps</div>
        <div class="space-y-2">
            @forelse ($record->steps->sortBy('step_order') as $step)
                <div class="rounded border border-gray-200 p-3">
                    <div class="font-medium">
                        Step {{ $step->step_order }} · {{ $step->role_name ?? 'Unassigned role' }} · {{ $step->status }}
                    </div>
                    <div class="text-xs text-gray-500">
                        Actor: {{ $step->actor?->name ?? '-' }} · At: {{ $step->acted_at?->toDateTimeString() ?? '-' }}
                    </div>
                    @if (filled($step->notes))
                        <div class="mt-1">{{ $step->notes }}</div>
                    @endif
                </div>
            @empty
                <div>-</div>
            @endforelse
        </div>
    </div>

    <div>
        <div class="mb-2 text-xs font-medium text-gray-500">Timeline</div>
        <div class="space-y-2">
            @forelse ($record->logs->sortByDesc('created_at') as $log)
                <div class="rounded border border-gray-200 p-3">
                    <div class="font-medium">
                        {{ $log->action }} · {{ $log->actor?->name ?? '-' }}
                    </div>
                    <div class="text-xs text-gray-500">{{ $log->created_at?->toDateTimeString() ?? '-' }}</div>
                    @if (filled($log->notes))
                        <div class="mt-1">{{ $log->notes }}</div>
                    @endif
                </div>
            @empty
                <div>-</div>
            @endforelse
        </div>
    </div>
</div>
