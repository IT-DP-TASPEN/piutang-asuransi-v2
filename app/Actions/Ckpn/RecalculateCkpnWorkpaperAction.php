<?php

namespace App\Actions\Ckpn;

use App\Models\CkpnWorkpaper;
use Illuminate\Validation\ValidationException;

class RecalculateCkpnWorkpaperAction
{
    public function __construct(
        private readonly GenerateMonthlyCkpnWorkpaperAction $generateMonthlyCkpnWorkpaperAction,
    ) {}

    public function handle(CkpnWorkpaper $workpaper): CkpnWorkpaper
    {
        if (! in_array($workpaper->status ?? CkpnWorkpaper::STATUS_DRAFT, [
            CkpnWorkpaper::STATUS_DRAFT,
            CkpnWorkpaper::STATUS_GENERATED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or generated CKPN workpapers can be recalculated.',
            ]);
        }

        return $this->generateMonthlyCkpnWorkpaperAction->handle($workpaper);
    }
}
