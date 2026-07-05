<?php

namespace Database\Factories;

use App\Models\InsuranceReceivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivablePayment>
 */
class ReceivablePaymentFactory extends Factory
{
    protected $model = ReceivablePayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insurance_receivable_id' => InsuranceReceivable::factory(),
            'amount' => '100000.00',
            'paid_at' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function forInsuranceReceivable(): static
    {
        return $this->state(fn (): array => [
            'insurance_receivable_id' => InsuranceReceivable::factory(),
        ]);
    }
}
