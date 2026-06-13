<x-filament-panels::page>
    {{-- Inputs --}}
    <x-filament::section>
        <x-slot name="heading">ตั้งค่าการคำนวณ</x-slot>

        <div class="space-y-4">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Listing-Variant (ร้าน × สินค้า)</label>
                <select
                    wire:model.live="listingVariantId"
                    class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                >
                    <option value="">— เลือก Listing-Variant —</option>
                    @foreach ($listingVariants as $lv)
                        <option value="{{ $lv->id }}">
                            {{ $lv->platform_sku }} — {{ $lv->variant?->master_sku }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="text-sm font-medium">ทิศทาง</label>
                <select
                    wire:model.live="direction"
                    class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                >
                    <option value="forward">เป้ากำไร → ราคาขายที่แนะนำ</option>
                    <option value="symmetric">ราคาขาย → กำไรที่ได้</option>
                </select>
            </div>

            @if ($direction === 'forward')
                <div class="flex flex-wrap items-center gap-4">
                    <select
                        wire:model.live="targetType"
                        class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                    >
                        <option value="percent">% ของต้นทุน</option>
                        <option value="fixed">กำไรคงที่ (บาท)</option>
                    </select>
                    <input
                        type="text"
                        inputmode="decimal"
                        wire:model.live.debounce.400ms="targetValue"
                        placeholder="{{ $targetType === 'percent' ? 'เช่น 30' : 'เช่น 50.00' }}"
                        class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                    />
                    <span class="text-sm text-gray-500">{{ $targetType === 'percent' ? '%' : '฿' }}</span>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <label class="text-sm font-medium">ราคาขาย (Effective Price)</label>
                    <input
                        type="text"
                        inputmode="decimal"
                        wire:model.live.debounce.400ms="effectivePriceBaht"
                        placeholder="เช่น 144.44"
                        class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                    />
                    <span class="text-sm text-gray-500">฿</span>
                </div>
            @endif
        </div>
    </x-filament::section>

    {{-- Result --}}
    @if ($result !== null)
        <x-filament::section>
            <x-slot name="heading">ผลการคำนวณ</x-slot>

            @if ($result['error'] !== null)
                <div class="flex items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-danger-500" />
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $result['error'] }}</p>
                </div>
            @else
                <table class="w-full text-sm">
                    <tbody>
                        @if ($direction === 'forward')
                            <tr class="border-b border-gray-100 dark:border-gray-700">
                                <td class="py-1 pr-4">ราคาขายที่แนะนำ (Effective Price)</td>
                                <td class="py-1 text-right font-semibold tabular-nums">
                                    ฿{{ $result['recommended_price']?->toBaht() }}
                                </td>
                            </tr>
                        @else
                            <tr class="border-b border-gray-100 dark:border-gray-700">
                                <td class="py-1 pr-4">ราคาขาย (Effective Price)</td>
                                <td class="py-1 text-right tabular-nums">
                                    ฿{{ $result['recommended_price']?->toBaht() }}
                                </td>
                            </tr>
                        @endif
                        <tr class="font-semibold">
                            <td class="py-2 pr-4">กำไรที่ได้ (Implied Profit)</td>
                            <td class="py-2 text-right tabular-nums">
                                @if ($result['implied_profit']?->isNegative())
                                    −฿{{ \App\Support\Money::fromSatang(abs($result['implied_profit']->satang))->toBaht() }}
                                @else
                                    ฿{{ $result['implied_profit']?->toBaht() }}
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
