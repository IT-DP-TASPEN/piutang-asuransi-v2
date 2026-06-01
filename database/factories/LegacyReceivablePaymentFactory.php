<?php

namespace Database\Factories;

use App\Models\LegacyReceivable;
use App\Models\LegacyReceivablePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyReceivablePayment>
 */
class LegacyReceivablePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legacy_receivable_id' => LegacyReceivable::factory(),
            'amount' => '100000.00',
            'paid_at' => now()->toDateString(),
            'created_by' => null,
        ];
    }
}
