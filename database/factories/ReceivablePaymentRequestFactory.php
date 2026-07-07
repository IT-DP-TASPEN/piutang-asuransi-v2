<?php

namespace Database\Factories;

use App\Models\InsuranceReceivable;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivablePaymentRequest>
 */
class ReceivablePaymentRequestFactory extends Factory
{
    protected $model = ReceivablePaymentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insurance_receivable_id' => InsuranceReceivable::factory(),
            'requested_by' => User::factory(),
            'amount' => '100000.00',
            'payment_source' => ReceivablePaymentRequest::PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02,
            'status' => ReceivablePaymentRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ];
    }
}
