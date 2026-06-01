<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget;

class FullWidthAccountInfoWidget extends AccountWidget
{
    protected int|string|array $columnSpan = 'full';
}
