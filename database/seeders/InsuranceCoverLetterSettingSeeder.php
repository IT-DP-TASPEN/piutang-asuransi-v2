<?php

namespace Database\Seeders;

use App\Models\InsuranceCoverLetterSetting;
use Illuminate\Database\Seeder;

class InsuranceCoverLetterSettingSeeder extends Seeder
{
    public function run(): void
    {
        InsuranceCoverLetterSetting::query()->updateOrCreate(
            ['id' => InsuranceCoverLetterSetting::SINGLETON_ID],
            ['sequence_base' => 0],
        );
    }
}
