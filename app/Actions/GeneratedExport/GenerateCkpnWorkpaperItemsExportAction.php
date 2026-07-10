<?php

namespace App\Actions\GeneratedExport;

use App\Models\CkpnWorkpaper;
use App\Models\CkpnWorkpaperItem;
use App\Models\GeneratedExport;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class GenerateCkpnWorkpaperItemsExportAction
{
    private const NO_ITEMS_MESSAGE = 'CKPN workpaper has no generated items to export.';

    public function handle(CkpnWorkpaper $workpaper, User $user): GeneratedExport
    {
        if (! $user->can('generateExport', $workpaper)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized accounting users can generate CKPN workpaper exports.',
            ]);
        }

        $itemsCount = $workpaper->items()->count();

        if ($itemsCount === 0) {
            throw ValidationException::withMessages([
                'items' => self::NO_ITEMS_MESSAGE,
            ]);
        }

        $directory = 'generated-exports/ckpn-workpapers/items';
        $filename = $this->filename($workpaper);
        $relativePath = "{$directory}/{$filename}";
        $disk = Storage::disk(GeneratedExport::DISK);
        $disk->makeDirectory($directory);
        $absolutePath = $disk->path($relativePath);

        try {
            $this->writeXlsx($workpaper, $absolutePath);

            return DB::transaction(fn (): GeneratedExport => $workpaper->generatedExports()->create([
                'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_ITEMS_XLSX,
                'file_path' => $relativePath,
                'status' => GeneratedExport::STATUS_GENERATED,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'metadata' => [
                    'disk' => GeneratedExport::DISK,
                    'format' => 'xlsx',
                    'items_count' => $itemsCount,
                    'cutoff_date' => $workpaper->period?->toDateString(),
                ],
            ]));
        } catch (Throwable $exception) {
            if ($disk->exists($relativePath)) {
                $disk->delete($relativePath);
            }

            return DB::transaction(fn (): GeneratedExport => $workpaper->generatedExports()->create([
                'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_ITEMS_XLSX,
                'file_path' => null,
                'status' => GeneratedExport::STATUS_FAILED,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'metadata' => [
                    'disk' => GeneratedExport::DISK,
                    'format' => 'xlsx',
                    'items_count' => $itemsCount,
                    'cutoff_date' => $workpaper->period?->toDateString(),
                    'error' => $exception->getMessage(),
                ],
            ]));
        }
    }

    private function writeXlsx(CkpnWorkpaper $workpaper, string $absolutePath): void
    {
        $options = new Options;
        $options->DEFAULT_COLUMN_WIDTH = 16;
        $options->setColumnWidth(8, 1);
        $options->setColumnWidth(14, 2, 3, 4);
        $options->setColumnWidth(24, 5, 6, 7, 8, 11, 21);
        $options->setColumnWidth(18, 9, 10, 12);
        $options->setColumnWidth(20, 13, 18, 19, 20);
        $options->setColumnWidth(16, 14, 15, 16, 17);

        $writer = new Writer($options);
        $writer->openToFile($absolutePath);
        $writer->getCurrentSheet()->setName('CKPN Items');
        $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));

        $writer->addRow(Row::fromValues($this->headers(), $this->headerStyle()));

        $styles = $this->columnStyles();

        $workpaper->items()
            ->orderBy('id')
            ->each(function (CkpnWorkpaperItem $item, int $index) use ($writer, $styles): void {
                $writer->addRow(Row::fromValuesWithStyles($this->rowValues($item, $index), columnStyles: $styles));
            });

        $writer->close();
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'No',
            'Cabang',
            'Jenis Piutang / Source',
            'CIF',
            'Nama Debitur',
            'No Rekening Kredit',
            'No Rekening Alternatif',
            'Perusahaan Asuransi',
            'Tanggal Meninggal',
            'Tanggal Pembentukan Piutang',
            'Status Klaim',
            'Keterangan Status Klaim',
            'Umur Piutang',
            'Nominal Piutang',
            'Sisa Piutang',
            'Faktor Asuransi',
            'Faktor Umur Piutang',
            'Faktor Status Klaim Digunakan',
            'Bobot CKPN',
            'Calculated CKPN',
            'Adjustment Delta',
            'Effective CKPN',
            'Catatan Adjustment',
        ];
    }

    /**
     * @return list<bool|DateTimeInterface|float|int|string|null>
     */
    private function rowValues(CkpnWorkpaperItem $item, int $index): array
    {
        $snapshot = $item->snapshot ?? [];

        return [
            $index + 1,
            $item->branch_code ?? $item->branch_name,
            $snapshot['source'] ?? $item->source_label,
            $item->cif_no,
            $item->customer_name,
            $item->loan_account_number,
            $this->alternateAccountNumber($snapshot),
            $item->insurance_company_name,
            $this->dateCell($snapshot['date_of_death'] ?? null),
            $this->dateCell($item->receivable_formation_date),
            $item->claim_status_name,
            $item->claim_status_keterangan,
            $item->age_days,
            $this->decimalCell($item->receivable_amount),
            $this->decimalCell($item->remaining_receivable_amount),
            $this->percentCell($item->insurance_company_weight),
            $this->percentCell($item->age_weight),
            $this->percentCell($item->claim_status_weight),
            $this->percentCell($item->calculated_ckpn_rate),
            $this->decimalCell($item->calculated_ckpn_amount),
            $this->decimalCell($this->adjustmentDelta($item, $snapshot)),
            $this->decimalCell($item->effective_ckpn_amount),
            $item->adjustment_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function alternateAccountNumber(array $snapshot): ?string
    {
        return $this->filledString($snapshot['loan_alt_account_number'] ?? null)
            ?? $this->filledString($snapshot['alt_number'] ?? null)
            ?? $this->filledString($snapshot['alternate_account_number'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function adjustmentDelta(CkpnWorkpaperItem $item, array $snapshot): string
    {
        $storedDelta = $snapshot['adjustment_delta'] ?? $snapshot['adjustment_delta_amount'] ?? $item->getAttribute('adjustment_delta');

        if ($storedDelta !== null && $storedDelta !== '') {
            return (string) BigDecimal::of((string) $storedDelta)->toScale(2, RoundingMode::HalfUp);
        }

        return (string) BigDecimal::of((string) $item->effective_ckpn_amount)
            ->minus((string) $item->calculated_ckpn_amount)
            ->toScale(2, RoundingMode::HalfUp);
    }

    private function decimalCell(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp)->toFloat();
    }

    private function percentCell(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BigDecimal::of((string) $value)
            ->dividedBy('100', 8, RoundingMode::HalfUp)
            ->toFloat();
    }

    private function dateCell(mixed $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }

    private function filename(CkpnWorkpaper $workpaper): string
    {
        $cutoffDate = $workpaper->period?->toDateString() ?? 'no-cutoff-date';
        $name = sprintf(
            'ckpn-workpaper-items-%s-cutoff-%s-%s.xlsx',
            $workpaper->id,
            $cutoffDate,
            now()->format('YmdHis'),
        );

        return Str::of($name)->replaceMatches('/[^A-Za-z0-9._-]+/', '-')->lower()->toString();
    }

    private function filledString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setShouldWrapText()
            ->setCellAlignment(CellAlignment::CENTER);
    }

    /**
     * @return array<int, Style>
     */
    private function columnStyles(): array
    {
        $date = (new Style)->setFormat('yyyy-mm-dd');
        $money = (new Style)->setFormat('#,##0.00');
        $percent = (new Style)->setFormat('0.0000%');

        return [
            8 => $date,
            9 => $date,
            12 => $money,
            13 => $percent,
            14 => $percent,
            15 => $percent,
            16 => $percent,
            17 => $money,
            18 => $money,
            19 => $money,
        ];
    }
}
