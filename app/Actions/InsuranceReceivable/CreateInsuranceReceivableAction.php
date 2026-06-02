<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInsuranceReceivableAction
{
    public function __construct(
        private readonly PrepareInsuranceReceivableDraftAction $prepareInsuranceReceivableDraftAction,
        private readonly InsuranceReceivableInquiryDispatcher $inquiryDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $user): InsuranceReceivable
    {
        $documents = $this->requiredDocumentsFrom($data);
        $receivableData = $this->receivableDataFrom($data);

        $receivable = DB::transaction(function () use ($receivableData, $documents, $user): InsuranceReceivable {
            $receivable = InsuranceReceivable::query()->create(
                $this->prepareInsuranceReceivableDraftAction->handle($receivableData, $user),
            );

            foreach ($documents as $document) {
                $receivable->documents()->create([
                    ...$document,
                    'uploaded_by' => $user->id,
                ]);
            }

            return $receivable->refresh();
        });

        $this->inquiryDispatcher->dispatch($receivable);

        return $receivable->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{document_type: string, file_path: string, original_filename: string|null, mime_type: string|null}>
     */
    private function requiredDocumentsFrom(array $data): array
    {
        $documents = [];

        foreach (InsuranceReceivable::REQUIRED_DOCUMENT_TYPES as $documentType) {
            $path = $this->singleFilePath($data["{$documentType}_file_path"] ?? null);

            if (blank($path)) {
                throw ValidationException::withMessages([
                    "{$documentType}_file_path" => 'Required supporting document must be uploaded.',
                ]);
            }

            $documents[] = [
                'document_type' => $documentType,
                'file_path' => $path,
                'original_filename' => $this->singleFilePath($data["{$documentType}_original_filename"] ?? null),
                'mime_type' => null,
            ];
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function receivableDataFrom(array $data): array
    {
        foreach (InsuranceReceivable::REQUIRED_DOCUMENT_TYPES as $documentType) {
            unset(
                $data["{$documentType}_file_path"],
                $data["{$documentType}_original_filename"],
            );
        }

        return $data;
    }

    private function singleFilePath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
