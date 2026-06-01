<?php

namespace App\Actions\LegacyReceivable;

use App\Models\LegacyReceivable;
use App\Models\LegacyReceivablePayment;
use Illuminate\Support\Facades\DB;

class DeleteLegacyReceivablePaymentAction
{
    public function __construct(
        private readonly RecalculateLegacyReceivableRemainingAmountAction $recalculateRemainingAmountAction,
    ) {}

    public function handle(LegacyReceivablePayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $legacyReceivable = LegacyReceivable::query()
                ->whereKey($payment->legacy_receivable_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment->delete();

            $this->recalculateRemainingAmountAction->handle($legacyReceivable);
        });
    }
}
