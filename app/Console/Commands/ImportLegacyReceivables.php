<?php

namespace App\Console\Commands;

use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\LegacyReceivable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportLegacyReceivables extends Command
{
    protected $signature = 'legacy-receivables:import {path=mewgrazie.csv : CSV path}';

    protected $description = 'Import legacy receivables from CSV';

    private const REQUIRED_HEADERS = [
        'cif',
        'customer_name',
        'loan_account_number',
        'loan_alt_account_number',
        'loan_outstanding',
        'insurance_company',
        'date_of_death',
        'receivable_formation_date',
        'original_receivable_amount',
        'remaining_receivable_amount',
        'claim_status',
        'branch_office',
    ];

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));

        if (! is_file($path)) {
            $this->error("CSV file not found: {$path}");

            return self::FAILURE;
        }

        try {
            $count = DB::transaction(fn (): int => $this->import($path));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$count} legacy receivable(s).");

        return self::SUCCESS;
    }

    private function import(string $path): int
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$path}");
        }

        try {
            $headers = $this->readHeaders($handle);
            $count = 0;
            $line = 1;

            while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                $line++;

                if ($this->isBlankRow($row)) {
                    continue;
                }

                if (count($row) !== count($headers)) {
                    throw new RuntimeException("Line {$line}: column count does not match header count.");
                }

                $this->createReceivable($line, array_combine($headers, array_map($this->cleanValue(...), $row)));
                $count++;
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>
     */
    private function readHeaders(mixed $handle): array
    {
        $headers = fgetcsv($handle, 0, ';', '"', '\\');

        if ($headers === false) {
            throw new RuntimeException('CSV file is empty.');
        }

        $headers = array_map($this->normalizeHeader(...), $headers);
        $missing = array_diff(self::REQUIRED_HEADERS, $headers);

        if ($missing !== []) {
            throw new RuntimeException('Missing CSV headers: '.implode(', ', $missing));
        }

        if (count($headers) !== count(array_unique($headers))) {
            throw new RuntimeException('CSV headers must be unique.');
        }

        return $headers;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        return collect($row)->every(fn (?string $value): bool => $this->cleanValue($value) === '');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function createReceivable(int $line, array $row): void
    {
        LegacyReceivable::create([
            'cif' => $row['cif'],
            'customer_name' => $row['customer_name'],
            'loan_account_number' => $row['loan_account_number'],
            'loan_alt_account_number' => $this->blankToNull($row['loan_alt_account_number']),
            'branch_office_id' => $this->branchOfficeId($line, $row['branch_office']),
            'loan_outstanding' => $this->nominal($line, 'loan_outstanding', $row['loan_outstanding']),
            'insurance_company_id' => $this->nameId($line, InsuranceCompany::class, 'insurance company', $row['insurance_company']),
            'date_of_death' => $row['date_of_death'],
            'receivable_formation_date' => $this->blankToNull($row['receivable_formation_date']),
            'original_receivable_amount' => $this->nominal($line, 'original_receivable_amount', $row['original_receivable_amount']),
            'remaining_receivable_amount' => $this->nominal($line, 'remaining_receivable_amount', $row['remaining_receivable_amount']),
            'claim_status_id' => $this->nameId($line, ClaimStatus::class, 'claim status', $row['claim_status']),
        ]);
    }

    private function branchOfficeId(int $line, string $branchCode): int
    {
        if ($branchCode === '') {
            throw new RuntimeException("Line {$line}: branch office is required.");
        }

        $id = BranchOffice::query()
            ->where('branch_code', $branchCode)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException("Line {$line}: branch office not found: {$branchCode}");
        }

        return (int) $id;
    }

    /**
     * @param  class-string<InsuranceCompany|ClaimStatus>  $model
     */
    private function nameId(int $line, string $model, string $label, string $name): int
    {
        if ($name === '') {
            throw new RuntimeException("Line {$line}: {$label} is required.");
        }

        $id = $model::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->value('id');

        if ($id === null) {
            throw new RuntimeException("Line {$line}: {$label} not found: {$name}");
        }

        return (int) $id;
    }

    private function nominal(int $line, string $field, string $value): string
    {
        $value = str_replace('.', '', trim($value));

        if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
            throw new RuntimeException("Line {$line}: {$field} must be a nominal number.");
        }

        return $value;
    }

    private function cleanValue(?string $value): string
    {
        return trim((string) $value);
    }

    private function normalizeHeader(?string $header): string
    {
        return trim(str_replace("\xEF\xBB\xBF", '', (string) $header));
    }

    private function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function resolvePath(string $path): string
    {
        return is_file($path) ? $path : base_path($path);
    }
}
