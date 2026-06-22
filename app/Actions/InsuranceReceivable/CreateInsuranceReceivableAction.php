<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;

class CreateInsuranceReceivableAction
{
    public function __construct(
        private readonly PrepareInsuranceReceivableDraftAction $prepareInsuranceReceivableDraftAction,
        private readonly InsuranceReceivableInquiryDispatcher $inquiryDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $user): InsuranceReceivable
    {
        $receivable = InsuranceReceivable::query()->create(
            $this->prepareInsuranceReceivableDraftAction->handle($data, $user),
        );

        $this->inquiryDispatcher->dispatch($receivable);

        return $receivable->refresh();
    }
}
