<?php

namespace App\Filament\Pages;

use App\Actions\Reporting\ComputeCombinedPnl;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Combined P&L report across every channel (Issue #72).
 *
 * Displays the period's marketplace Actual Net (by fee category), POS
 * revenue/COGS/net, tenant-level Operating Expenses (direct Expense query —
 * the rollup expense_total is per-order-attributable only; see
 * ComputeCombinedPnl), Cash Over/Short, and the combined net profit.
 *
 * Gated on `report.view`; COGS / profit lines are additionally gated
 * `cost.view` (ADR 0012). A user with only `report.view` sees revenues and
 * fees but NOT anything that reveals margin.
 *
 * Reads from DailyPnlRollup, never the raw ledger (ROADMAP Phase-1).
 */
class CombinedPnl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'P&L รวมทุกช่องทาง';

    protected static ?string $navigationLabel = 'Combined P&L';

    protected string $view = 'filament.pages.combined-pnl';

    /** First day of the report period (YYYY-MM-DD). */
    public string $dateFrom = '';

    /** Last day of the report period (YYYY-MM-DD). */
    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->endOfMonth()->toDateString();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->checkPermissionTo('report.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $from = Carbon::parse($this->dateFrom !== '' ? $this->dateFrom : now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->dateTo !== '' ? $this->dateTo : now()->endOfMonth()->toDateString());

        $canViewCost = auth()->user()?->checkPermissionTo('cost.view') ?? false;

        return [
            'pnl' => app(ComputeCombinedPnl::class)->handle($from, $to, $canViewCost),
        ];
    }
}
