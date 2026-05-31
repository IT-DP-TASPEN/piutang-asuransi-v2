<?php

namespace App\Actions\CkpnJournal;

use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCkpnJournalFromWorkpaperAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(CkpnWorkpaper $workpaper, User $user, array $data = []): CkpnJournal
    {
        if ($workpaper->status !== CkpnWorkpaper::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'CKPN journal can only be created from an approved workpaper.',
            ]);
        }

        return DB::transaction(fn (): CkpnJournal => $workpaper->journals()->create([
            'branch_office_id' => $workpaper->branch_office_id,
            'journal_date' => $data['journal_date'] ?? now()->toDateString(),
            'total_amount' => $workpaper->total_ckpn_amount,
            'debit_account' => $data['debit_account'] ?? null,
            'credit_account' => $data['credit_account'] ?? null,
            'debit_narrative' => $data['debit_narrative'] ?? null,
            'credit_narrative' => $data['credit_narrative'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => CkpnJournal::STATUS_DRAFT,
            'created_by' => $user->id,
        ]));
    }
}
