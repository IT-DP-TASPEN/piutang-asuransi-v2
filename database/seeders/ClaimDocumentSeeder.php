<?php

namespace Database\Seeders;

use App\Models\ClaimDocumentRequirement;
use App\Models\ClaimDocumentType;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use Illuminate\Database\Seeder;

class ClaimDocumentSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'claim_submission_form', 'name' => 'Formulir Pengajuan Klaim Asuransi', 'description' => null],
            ['code' => 'stgr_form', 'name' => 'Formulir STGR', 'description' => null],
            ['code' => 'insurance_certificate_copy', 'name' => 'Fotokopi Sertifikat Asuransi', 'description' => null],
            ['code' => 'debtor_id_card', 'name' => 'KTP Debitur', 'description' => null],
            ['code' => 'heir_id_card', 'name' => 'KTP Ahli Waris', 'description' => null],
            ['code' => 'debtor_family_card', 'name' => 'KK Debitur', 'description' => null],
            ['code' => 'heir_family_card', 'name' => 'KK Ahli Waris', 'description' => null],
            ['code' => 'credit_agreement', 'name' => 'Surat Perjanjian Kredit', 'description' => null],
            ['code' => 'account_statement', 'name' => 'Rekening Statement 1 & 2', 'description' => null],
            ['code' => 'installment_schedule', 'name' => 'Jadwal Angsuran', 'description' => null],
            ['code' => 'death_certificate_hospital', 'name' => 'Surat Keterangan dari Rumah Sakit', 'description' => 'Jika debitur meninggal di rumah sakit.'],
            ['code' => 'death_certificate_police', 'name' => 'Surat Keterangan dari Kepolisian', 'description' => 'Jika debitur meninggal karena kecelakaan.'],
            ['code' => 'death_certificate_embassy', 'name' => 'Surat dari Kedutaan', 'description' => 'Jika debitur meninggal dunia di luar negeri.'],
            ['code' => 'death_certificate_local_authority', 'name' => 'Surat Keterangan Kematian dari Kelurahan / kronologis RT/RW', 'description' => 'Bermeterai dan ditandatangani RT/RW bila kejadian di rumah.'],
            ['code' => 'death_certificate_civil_registry', 'name' => 'Akta Kematian dari Dukcapil', 'description' => null],
            ['code' => 'slik_ojk', 'name' => 'SLIK OJK', 'description' => null],
        ];

        $documentTypes = collect($types)->mapWithKeys(function (array $type, int $index): array {
            $model = ClaimDocumentType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'accepted_file_types' => ['application/pdf'],
                    'sort_order' => ($index + 1) * 10,
                    'active' => true,
                ],
            );

            return [$type['code'] => $model];
        });

        $common = [
            'insurance_certificate_copy',
            'debtor_id_card',
            'heir_id_card',
            'debtor_family_card',
            'heir_family_card',
            'credit_agreement',
            'account_statement',
            'installment_schedule',
        ];
        $conditional = [
            'death_certificate_hospital' => InsuranceReceivable::DEATH_DOCUMENT_CONDITION_HOSPITAL,
            'death_certificate_police' => InsuranceReceivable::DEATH_DOCUMENT_CONDITION_ACCIDENT,
            'death_certificate_embassy' => InsuranceReceivable::DEATH_DOCUMENT_CONDITION_OVERSEAS,
            'death_certificate_local_authority' => InsuranceReceivable::DEATH_DOCUMENT_CONDITION_HOME,
            'death_certificate_civil_registry' => InsuranceReceivable::DEATH_DOCUMENT_CONDITION_CIVIL_REGISTRY,
        ];

        $matrix = [
            InsuranceCompany::CLAIM_TYPE_AJK => ['claim_submission_form', ...$common],
            InsuranceCompany::CLAIM_TYPE_CREDIT => ['stgr_form', ...$common, 'slik_ojk'],
        ];

        foreach ($matrix as $claimType => $codes) {
            $order = 10;

            foreach ($codes as $code) {
                $this->seedRequirement($claimType, $documentTypes[$code], false, null, $order);
                $order += 10;
            }

            foreach ($conditional as $code => $conditionKey) {
                $this->seedRequirement($claimType, $documentTypes[$code], true, $conditionKey, $order);
                $order += 10;
            }
        }
    }

    private function seedRequirement(
        string $claimType,
        ClaimDocumentType $documentType,
        bool $conditional,
        ?string $conditionKey,
        int $sortOrder,
    ): void {
        ClaimDocumentRequirement::query()->updateOrCreate(
            [
                'claim_type' => $claimType,
                'claim_document_type_id' => $documentType->id,
            ],
            [
                'is_required' => true,
                'is_conditional' => $conditional,
                'condition_key' => $conditionKey,
                'sort_order' => $sortOrder,
            ],
        );
    }
}
