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
        if ($workpaper->status !== CkpnWorkpaper::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'SAKEP export can only be generated from an approved CKPN workpaper.',
            ]);
        }

        $directory = 'generated-exports/ckpn-workpapers';
        $filename = sprintf('ckpn-workpaper-%s-%s.xlsx', $workpaper->id, now()->format('YmdHis'));
        $relativePath = "{$directory}/{$filename}";
        $disk = Storage::disk('public');
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
            ->with(['insuranceReceivable'])
            ->orderBy('id')
            ->each(function (CkpnWorkpaperItem $item, int $index) use ($writer): void {
                $receivable = $item->insuranceReceivable;

                $writer->addRow(Row::fromValues([
                    $index + 1,
                    $item->cif_no,
                    $item->loan_account_number,
                    $item->customer_name,
                    $receivable?->credit_limit,
                    $this->dateValue($receivable?->start_period),
                    '',
                    $this->dateValue($receivable?->end_period),
                    $this->dateValue($receivable?->date_of_death),
                    $receivable?->loan_outstanding,
                    $this->dateValue($item->receivable_formation_date),
                    $item->insurance_company_name,
                    $item->claim_status_name,
                    $item->final_ckpn_rate,
                    $item->ckpn_amount,
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
            'CKPN rate',
            'CKPN amount',
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
