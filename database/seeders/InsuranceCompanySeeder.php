<?php

namespace Database\Seeders;

use App\Models\InsuranceCompany;
use Illuminate\Database\Seeder;

class InsuranceCompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            ['name' => 'TASPEN LIFE', 'claim_type' => 'ajk', 'ckpn_weight' => '100', 'legal_name' => 'PT ASURANSI JIWA TASPEN', 'letter_recipient_name' => 'PT ASURANSI JIWA TASPEN', 'letter_recipient_address' => 'Jl. Letjen Suprapto No.45, Cempaka Putih, Jakarta 10520'],
            ['name' => 'PT ASURANSI JIWA NASIONAL', 'claim_type' => 'ajk', 'ckpn_weight' => '50', 'legal_name' => 'PT ASURANSI JIWA NASIONAL', 'letter_recipient_name' => 'PT ASURANSI JIWA NASIONAL', 'letter_recipient_address' => 'Gedung Menara Jamsostek, Menara Utara Lt. 3A, Jl. Jenderal Gatot Subroto No. 38, Jakarta Selatan 12710'],
            ['name' => 'HEKSA INSURANCE', 'claim_type' => 'ajk', 'ckpn_weight' => '0.5', 'legal_name' => 'PT HEKSA SOLUTION INSURANCE', 'letter_recipient_name' => 'PT HEKSA SOLUTION INSURANCE', 'letter_recipient_address' => 'Satrio Tower, 8th Floor, Jl. Prof. DR. Satrio Kav. C4, Kuningan Timur, Setiabudi, Jakarta Selatan 12950'],
            ['name' => 'PT GLOBAL INSURANCE BROKER', 'claim_type' => 'ajk', 'ckpn_weight' => '0.5', 'legal_name' => 'PT ASURANSI JIWA RELIANCE INDONESIA', 'letter_recipient_name' => 'PT GLOBAL INSURANCE BROKER', 'letter_recipient_address' => 'Jl. Senopati No.21, Kebayoran Baru, Jakarta Selatan 12190'],
            ['name' => 'PT ASURANSI BHAKTI BHAYANGKARA', 'claim_type' => 'ajk', 'ckpn_weight' => '50', 'legal_name' => 'PT ASURANSI BHAKTI BHAYANGKARA', 'letter_recipient_name' => 'PT ASURANSI BHAKTI BHAYANGKARA', 'letter_recipient_address' => 'Ruko Mataram Plaza, Jl. MT. Haryono, Jagalan, Kec. Semarang Tengah, Kota Semarang, Jawa Tengah 50613'],
            ['name' => 'VICTORIA ALIFE', 'claim_type' => 'ajk', 'ckpn_weight' => '0', 'legal_name' => 'PT VICTORIA ALIFE INDONESIA', 'letter_recipient_name' => 'PT SINERGI DUTA INSURANCE BROKERS', 'letter_recipient_address' => 'Botany Hills, Fatmawati City Center Soho No.26, Jl. Fatmawati Raya, Jakarta Selatan 12430'],
            ['name' => 'BPJS TK', 'claim_type' => 'ajk', 'ckpn_weight' => '0', 'legal_name' => 'BPJS KETENAGAKERJAAN KANTOR CABANG JAKARTA BUARAN', 'letter_recipient_name' => 'BPJS KETENAGAKERJAAN KANTOR CABANG JAKARTA BUARAN', 'letter_recipient_address' => 'Jalan I Gusti Ngurah Rai No.20 RT 008/RW 012 Bukit Podomoro Jakarta, Bukit Avenue Blok C3, C5, dan C6 Kel. Klender, Kec. Duren Sawit, Jakarta Timur 13470'],
            ['name' => 'ASEI', 'claim_type' => 'credit', 'ckpn_weight' => '0', 'legal_name' => 'PT ASURANSI ASEI INDONESIA', 'letter_recipient_name' => 'PT ASURANSI ASEI INDONESIA', 'letter_recipient_address' => 'Kantor Cabang Bekasi, Ruko Grand Galaxy, Blok RRG 3 No. 77, Jaka Setia, Bekasi Selatan, Kota Bekasi'],
            ['name' => 'MNC ASURANSI', 'claim_type' => 'credit', 'ckpn_weight' => '0', 'legal_name' => 'PT MNC ASURANSI INDONESIA', 'letter_recipient_name' => 'PT MNC ASURANSI INDONESIA', 'letter_recipient_address' => 'MNC Bank Tower Lt. 11, Jalan Kebon Sirih No. 21–27, Jakarta Pusat 10340'],
            ['name' => 'ASKRINDO', 'code' => 'ASKRINDO', 'claim_type' => 'ajk', 'ckpn_weight' => '0.5', 'legal_name' => null, 'letter_recipient_name' => null, 'letter_recipient_address' => null],
            ['name' => 'AA PIALANG', 'claim_type' => 'ajk', 'ckpn_weight' => '0.5', 'legal_name' => null, 'letter_recipient_name' => null, 'letter_recipient_address' => null],
            ['name' => 'MPM ASURANSI', 'claim_type' => 'ajk', 'ckpn_weight' => '0.5', 'legal_name' => null, 'letter_recipient_name' => null, 'letter_recipient_address' => null],
            ['name' => 'JASA RAHARJA PUTERA', 'claim_type' => 'ajk', 'ckpn_weight' => '0.5', 'legal_name' => null, 'letter_recipient_name' => null, 'letter_recipient_address' => null],
        ];

        foreach ($companies as $company) {
            InsuranceCompany::query()->updateOrCreate(
                ['name' => $company['name']],
                [
                    ...$company,
                    'code' => $company['code'] ?? null,
                    'sla_description' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
