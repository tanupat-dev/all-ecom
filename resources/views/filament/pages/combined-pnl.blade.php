<x-filament-panels::page>
    {{-- Period picker --}}
    <x-filament::section>
        <x-slot name="heading">ช่วงเวลา</x-slot>

        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium">จาก</label>
                <input
                    type="date"
                    wire:model.blur="dateFrom"
                    class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                />
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium">ถึง</label>
                <input
                    type="date"
                    wire:model.blur="dateTo"
                    class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                />
            </div>
        </div>
    </x-filament::section>

    {{-- Incompleteness notice --}}
    @if ($pnl['uncosted_pos_orders'] > 0)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-warning-500" />
                <p class="text-sm text-warning-600 dark:text-warning-400">
                    <strong>P&amp;L ยังไม่สมบูรณ์</strong> —
                    ออเดอร์ POS <strong>{{ $pnl['uncosted_pos_orders'] }}</strong> รายการไม่มีราคาต้นทุน ณ วันขาย
                    ออเดอร์เหล่านี้ถูกยกเว้นจากยอด COGS และกำไร
                </p>
            </div>
        </x-filament::section>
    @endif

    {{-- Marketplace breakdown --}}
    <x-filament::section>
        <x-slot name="heading">Marketplace (Shopee / Lazada / TikTok)</x-slot>

        <table class="w-full text-sm">
            <tbody>
                @foreach ($pnl['fee_breakdown'] as $category => $satang)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="py-1 pr-4 text-gray-500">{{ $category }}</td>
                        <td class="py-1 text-right tabular-nums">
                            ฿{{ \App\Support\Money::fromSatang((int) $satang)->toBaht() }}
                        </td>
                    </tr>
                @endforeach

                @if (empty($pnl['fee_breakdown']))
                    <tr>
                        <td class="py-1 text-gray-400" colspan="2">ไม่มีข้อมูล Marketplace ในช่วงนี้</td>
                    </tr>
                @endif
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <td class="py-2 pr-4">ยอดสุทธิ Marketplace (Actual Net)</td>
                    <td class="py-2 text-right tabular-nums">
                        ฿{{ \App\Support\Money::fromSatang($pnl['marketplace_net'])->toBaht() }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </x-filament::section>

    {{-- POS breakdown --}}
    <x-filament::section>
        <x-slot name="heading">POS (หน้าร้าน)</x-slot>

        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b border-gray-100 dark:border-gray-700">
                    <td class="py-1 pr-4">รายได้ POS</td>
                    <td class="py-1 text-right tabular-nums">
                        ฿{{ \App\Support\Money::fromSatang($pnl['pos_revenue'])->toBaht() }}
                    </td>
                </tr>

                @if ($pnl['can_view_cost'])
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="py-1 pr-4">ต้นทุน POS (COGS)</td>
                        <td class="py-1 text-right tabular-nums">
                            ฿{{ \App\Support\Money::fromSatang((int) $pnl['pos_cogs'])->toBaht() }}
                        </td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">กำไร POS (pos_net)</td>
                        <td class="py-2 text-right tabular-nums">
                            ฿{{ \App\Support\Money::fromSatang((int) $pnl['pos_net'])->toBaht() }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </x-filament::section>

    {{-- Summary --}}
    <x-filament::section>
        <x-slot name="heading">สรุปรวม</x-slot>

        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b border-gray-100 dark:border-gray-700">
                    <td class="py-1 pr-4">ค่าใช้จ่ายดำเนินงาน (Operating Expenses)</td>
                    <td class="py-1 text-right tabular-nums text-danger-600">
                        −฿{{ \App\Support\Money::fromSatang($pnl['operating_expenses'])->toBaht() }}
                    </td>
                </tr>
                <tr class="border-b border-gray-100 dark:border-gray-700">
                    <td class="py-1 pr-4">เงินเกิน/ขาด (Cash Over/Short)</td>
                    <td class="py-1 text-right tabular-nums">
                        @if ($pnl['cash_over_short'] >= 0)
                            +฿{{ \App\Support\Money::fromSatang($pnl['cash_over_short'])->toBaht() }}
                        @else
                            −฿{{ \App\Support\Money::fromSatang(abs($pnl['cash_over_short']))->toBaht() }}
                        @endif
                    </td>
                </tr>
            </tbody>

            @if ($pnl['can_view_cost'])
                <tfoot>
                    <tr class="text-base font-bold">
                        <td class="py-2 pr-4">กำไรสุทธิรวม</td>
                        <td class="py-2 text-right tabular-nums">
                            ฿{{ \App\Support\Money::fromSatang((int) $pnl['combined_net'])->toBaht() }}
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </x-filament::section>
</x-filament-panels::page>
