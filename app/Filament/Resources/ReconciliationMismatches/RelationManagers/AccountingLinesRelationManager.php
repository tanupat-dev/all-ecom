<?php

namespace App\Filament\Resources\ReconciliationMismatches\RelationManagers;

use App\Models\AccountingEntryLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Drilldown to the mismatched Order's Accounting Entry lines (CONTEXT.md:
 * Accounting Entry) — one row per Platform-native field (`source_field`),
 * grouped by statement cycle, so the investigator can see WHICH deduction
 * caused Actual Net to diverge from Expected Net. Read-only; gated on
 * `accounting.view` via AccountingEntryLinePolicy::viewAny (ADR 0012).
 */
class AccountingLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'accountingEntryLines';

    protected static ?string $title = 'รายการบัญชี (Accounting Entry)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('statement_cycle')
                    ->label('รอบบิล')
                    ->sortable(),
                TextColumn::make('source_field')
                    ->label('ฟิลด์ต้นทาง')
                    ->searchable(),
                TextColumn::make('category')
                    ->label('หมวด')
                    ->badge(),
                TextColumn::make('amount')
                    ->label('จำนวน (บาท)')
                    ->state(fn (AccountingEntryLine $record): string => $record->amount->toBaht()),
            ])
            ->defaultSort('statement_cycle');
    }
}
