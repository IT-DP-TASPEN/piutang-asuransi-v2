<?php

namespace App\Actions\GeneratedExport;

use App\Models\CkpnWorkpaper;
use App\Models\CkpnWorkpaperItem;
use App\Models\GeneratedExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class GenerateCkpnWorkpaperSakepExportAction
{
    public function handle(CkpnWorkpaper $workpaper, User $user): GeneratedExport
    {
        if (! $user->can('generateExport', $workpaper)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized accounting users can generate CKPN workpaper exports.',
            ]);
        }

        if ($workpaper->status !== CkpnWorkpaper::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'SAKEP export can only be generated from an approved CKPN workpaper.',
            ]);
        }

        $directory = 'generated-exports/ckpn-workpapers';
        $cutoffDate = $workpaper->period?->toDateString() ?? 'no-cutoff-date';
        $filename = sprintf('ckpn-workpaper-%s-cutoff-%s-%s.xlsx', $workpaper->id, $cutoffDate, now()->format('YmdHis'));
        $relativePath = "{$directory}/{$filename}";
        $disk = Storage::disk(GeneratedExport::DISK);
        $disk->makeDirectory($directory);
        $absolutePath = $disk->path($relativePath);

        try {
            $this->writeXlsx($workpaper, $absolutePath);

            return DB::transaction(fn (): GeneratedExport => $workpaper->generatedExports()->create([
                'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX,
                'file_path' => $relativePath,
                'status' => GeneratedExport::STATUS_GENERATED,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'metadata' => [
                    'format' => 'xlsx',
                    'items_count' => $workpaper->items()->count(),
                    'cutoff_date' => $workpaper->period?->toDateString(),
                ],
            ]));
        } catch (Throwable $exception) {
            return DB::transaction(fn (): GeneratedExport => $workpaper->generatedExports()->create([
                'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX,
                'file_path' => null,
                'status' => GeneratedExport::STATUS_FAILED,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'metadata' => [
                    'format' => 'xlsx',
                    'items_count' => $workpaper->items()->count(),
                    'cutoff_date' => $workpaper->period?->toDateString(),
                    'error' => $exception->getMessage(),
                ],
            ]));
        }
    }

    private function writeXlsx(CkpnWorkpaper $workpaper, string $absolutePath): void
    {
        $writer = new Writer;
        $writer->openToFile($absolutePath);
        $writer->addRow(Row::fromValues($this->headers()));

        $workpaper->items()
            ->orderBy('id')
            ->each(function (CkpnWorkpaperItem $item, int $index) use ($writer): void {
                $snapshot = $item->snapshot ?? [];

                $writer->addRow(Row::fromValues([
                    $index + 1,
                    $item->cif_no,
                    $item->loan_account_number,
                    $item->customer_name,
                    $snapshot['credit_limit'] ?? null,
                    $this->dateValue($snapshot['start_period'] ?? null),
                    '',
                    $this->dateValue($snapshot['end_period'] ?? null),
                    $this->dateValue($snapshot['date_of_death'] ?? null),
                    $snapshot['loan_outstanding'] ?? $item->receivable_amount,
                    $this->dateValue($item->receivable_formation_date),
                    $item->insurance_company_name,
                    $item->claim_status_name,
                    $item->calculated_ckpn_amount,
                    $item->adjusted_ckpn_amount,
                    $item->effective_ckpn_rate,
                    $item->effective_ckpn_amount,
                    $item->effective_ckpn_amount,
                    '',
                    '',
                    '',
                ]));
            });

        $writer->close();
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'no',
            'cif',
            'loan account number',
            'customer name',
            'credit limit',
            'date of realization/start period',
            'tenor/term',
            'maturity/end period',
            'date of death',
            'bade/loan outstanding',
            'receivable formation date',
            'insurance company',
            'claim status',
            'calculated CKPN amount',
            'adjusted CKPN amount',
            'effective CKPN rate',
            'effective CKPN amount',
            'final CKPN amount',
            'maker',
            'checker',
            'approver',
        ];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toDateString')) {
            return $value->toDateString();
        }

        return (string) $value;
    }
}
