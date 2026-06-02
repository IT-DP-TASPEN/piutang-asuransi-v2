<?php

namespace App\Actions\Ckpn;

use App\Models\CkpnWorkpaper;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RecalculateCkpnWorkpaperAction
{
    public function __construct(
        private readonly GenerateMonthlyCkpnWorkpaperAction $generateMonthlyCkpnWorkpaperAction,
    ) {}

    public function handle(CkpnWorkpaper $workpaper, ?User $user = null): CkpnWorkpaper
    {
        if ($user instanceof User && ! $user->can('recalculate', $workpaper)) {
            throw ValidationException::withMessages([
                'permission' => 'Only authorized accounting users can recalculate CKPN workpapers.',
            ]);
        }

        if (! in_array($workpaper->status ?? CkpnWorkpaper::STATUS_DRAFT, [
            CkpnWorkpaper::STATUS_DRAFT,
            CkpnWorkpaper::STATUS_GENERATED,
            CkpnWorkpaper::STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft, generated, or returned CKPN workpapers can be recalculated.',
            ]);
        }

        return $this->generateMonthlyCkpnWorkpaperAction->handle($workpaper);
    }
}
