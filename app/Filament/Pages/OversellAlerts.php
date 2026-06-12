<?php

namespace App\Filament\Pages;

use App\Actions\Stock\ListOversellConflicts;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class OversellAlerts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $title = 'แจ้งเตือนขายเกิน (Oversell)';

    protected string $view = 'filament.pages.oversell-alerts';

    public static function canAccess(): bool
    {
        return auth()->user()?->checkPermissionTo('stock.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['alerts' => app(ListOversellConflicts::class)->handle()];
    }
}
