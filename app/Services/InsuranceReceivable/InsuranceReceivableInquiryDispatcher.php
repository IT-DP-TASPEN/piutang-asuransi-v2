<?php

namespace App\Services\InsuranceReceivable;

use App\Jobs\RunLoanInquiryJob;
use App\Models\InsuranceReceivable;
use Illuminate\Support\Facades\DB;

class InsuranceReceivableInquiryDispatcher
{
    public function dispatch(InsuranceReceivable $receivable, string $event = 'inquiry_queued'): void
    {
        DB::transaction(function () use ($receivable, $event): void {
            $fromStatus = $receivable->system_status;

            $receivable->forceFill([
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED,
                'last_error_message' => null,
            ])->saveQuietly();

            app(InsuranceReceivableStageLogger::class)->log(
                receivable: $receivable,
                event: $event,
                fromStatus: $fromStatus,
                toStatus: InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED,
                description: 'Loan inquiry queued.',
                triggeredByType: 'system',
            );
        });

        RunLoanInquiryJob::dispatch($receivable->id)->afterCommit();
    }
}
