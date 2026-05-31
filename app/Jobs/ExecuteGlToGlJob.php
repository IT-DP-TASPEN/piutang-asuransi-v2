<?php

namespace App\Jobs;

use App\Actions\CkpnJournal\ExecuteGlToGlTransferAction;
use App\Models\CkpnJournal;
use App\Models\GlToGlTransaction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteGlToGlJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $ckpnJournalId,
        public readonly ?int $requestedBy = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ExecuteGlToGlTransferAction $action): void
    {
        $journal = CkpnJournal::query()->findOrFail($this->ckpnJournalId);
        $actor = $this->requestedBy ? User::query()->find($this->requestedBy) : null;

        $journal->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_PROCESSING])->save();

        try {
            $transaction = $action->handle($journal, $actor);

            $journal->forceFill([
                'status' => $transaction->status === GlToGlTransaction::STATUS_SUCCESS
                    ? CkpnJournal::STATUS_GL_TO_GL_EXECUTED
                    : CkpnJournal::STATUS_GL_TO_GL_FAILED,
            ])->save();
        } catch (Throwable $exception) {
            $journal->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_FAILED])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $journal = CkpnJournal::query()->find($this->ckpnJournalId);

        if ($journal instanceof CkpnJournal) {
            $journal->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_FAILED])->save();
        }
    }
}
