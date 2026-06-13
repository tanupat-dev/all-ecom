<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Order;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('วันที่')
                    ->required()
                    ->default(now()),
                TextInput::make('category')
                    ->label('หมวดหมู่ (เช่น บรรจุภัณฑ์, ค่าเช่า, ค่าแรง)')
                    ->required()
                    ->maxLength(255),
                // Entered/displayed in baht; converted to integer satang (ADR 0015).
                TextInput::make('amount')
                    ->label('จำนวนเงิน (บาท)')
                    ->required()
                    ->rule('regex:/^\d+(\.\d{1,2})?$/')
                    ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state)
                    ->dehydrateStateUsing(fn (?string $state): ?Money => $state !== null && $state !== '' ? Money::fromBaht($state) : null),
                Textarea::make('note')
                    ->label('หมายเหตุ')
                    ->nullable()
                    ->rows(3),
                Select::make('ref_order_id')
                    ->label('อ้างอิงออเดอร์ (ถ้ามี)')
                    ->options(fn (): array => Order::query()
                        ->whereNotNull('platform_order_id')
                        ->pluck('platform_order_id', 'id')
                        ->all())
                    ->nullable()
                    ->searchable(),
            ]);
    }
}
