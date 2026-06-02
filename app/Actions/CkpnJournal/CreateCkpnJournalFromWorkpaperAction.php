<?php

namespace App\Actions\CkpnJournal;

use App\Actions\Ckpn\ValidateCkpnJournalCreationAction;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCkpnJournalFromWorkpaperAction
{
    public function __construct(
        private readonly ValidateCkpnJournalCreationAction $validateCkpnJournalCreationAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(CkpnWorkpaper $workpaper, User $user, array $data = []): CkpnJournal
    {
        if (! $user->can('createJournal', $workpaper)) {
            throw ValidationException::withMessages([
                'permission' => 'Only accounting maker can create CKPN journals.',
            ]);
        }

        if ($workpaper->status !== CkpnWorkpaper::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'CKPN journal can only be created from an approved workpaper.',
            ]);
        }

        $this->validateCkpnJournalCreationAction->handle($workpaper);

        return DB::transaction(function () use ($workpaper, $user, $data): CkpnJournal {
            $workpaper = $workpaper->newQuery()
                ->where('id', $workpaper->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateCkpnJournalCreationAction->handle($workpaper);

            if ($workpaper->journals()->exists()) {
                throw ValidationException::withMessages([
                    'ckpn_journal' => 'CKPN journal already exists for this workpaper.',
                ]);
            }

            $hasAdjustments = $workpaper->items()->whereNotNull('adjusted_ckpn_amount')->exists();
            $description = $data['description'] ?? null;

            if ($hasAdjustments) {
                $description = collect([
                    $description,
                    'Includes approved CKPN adjustments.',
                ])->filter()->join("\n");
            }

            return $workpaper->journals()->create([
                'branch_office_id' => $workpaper->branch_office_id,
                'journal_date' => $data['journal_date'] ?? now()->toDateString(),
                'total_amount' => $workpaper->total_effective_ckpn_amount,
                'debit_account' => $data['debit_account'] ?? null,
                'credit_account' => $data['credit_account'] ?? null,
                'debit_narrative' => $data['debit_narrative'] ?? null,
                'credit_narrative' => $data['credit_narrative'] ?? null,
                'description' => $description,
                'status' => CkpnJournal::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);
        });
    }
}
