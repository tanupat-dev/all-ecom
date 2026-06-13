<?php

namespace App\Filament\Resources\Claims\RelationManagers;

use App\Actions\Claims\AppendClaimTimelineEntry;
use App\Models\Claim;
use App\Models\ClaimTimelineEntry;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Append-only Claim Timeline (CONTEXT.md: Claim — Claim Timeline; Issue #83,
 * #84). Sellers log submission events, ticket references, and payout amounts
 * here; corrections are always new entries. No EditAction or DeleteAction is
 * exposed — the model's booted() hooks throw on any attempt anyway.
 * Gated on `claim.manage` for appending (ADR 0012).
 */
class TimelineEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timelineEntries';

    protected static ?string $title = 'ไทม์ไลน์ (Claim Timeline)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('เวลา')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->label('การดำเนินการ'),
                TextColumn::make('note')
                    ->label('หมายเหตุ')
                    ->limit(60)
                    ->placeholder('-'),
                TextColumn::make('ticket_no')
                    ->label('เลขที่ Ticket')
                    ->placeholder('-'),
                TextColumn::make('payout_amount')
                    ->label('ยอดได้รับ (บาท)')
                    ->state(fn (ClaimTimelineEntry $record): string => $record->payout_amount?->toBaht() ?? '-'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([
                Action::make('appendEntry')
                    ->label('เพิ่มรายการ')
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => auth()->user()?->can('create', ClaimTimelineEntry::class) ?? false)
                    ->schema([
                        TextInput::make('action')
                            ->label('การดำเนินการ')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('note')
                            ->label('หมายเหตุ')
                            ->rows(3),
                        TextInput::make('ticket_no')
                            ->label('เลขที่ Ticket'),
                        TextInput::make('payout_amount')
                            ->label('ยอดได้รับ (บาท, ถ้ามี)')
                            ->rule('regex:/^\d+(\.\d{1,2})?$/'),
                        DateTimePicker::make('occurred_at')
                            ->label('เวลาที่เกิดขึ้น (ว่างไว้ = ตอนนี้)'),
                    ])
                    ->action(function (array $data): void {
                        /** @var Claim $claim */
                        $claim = $this->getOwnerRecord();

                        $payoutValue = $data['payout_amount'] ?? null;
                        $payoutAmount = is_string($payoutValue) && $payoutValue !== ''
                            ? Money::fromBaht($payoutValue)
                            : null;

                        $occurredAtValue = $data['occurred_at'] ?? null;
                        $occurredAt = is_string($occurredAtValue) && $occurredAtValue !== ''
                            ? Carbon::parse($occurredAtValue)
                            : null;

                        $action = $data['action'];

                        if (! is_string($action)) {
                            throw new \InvalidArgumentException('Expected string for action.');
                        }

                        $noteValue = $data['note'] ?? null;
                        $note = is_string($noteValue) && filled($noteValue) ? $noteValue : null;

                        $ticketNoValue = $data['ticket_no'] ?? null;
                        $ticketNo = is_string($ticketNoValue) && filled($ticketNoValue) ? $ticketNoValue : null;

                        app(AppendClaimTimelineEntry::class)->handle(
                            $claim,
                            $action,
                            $note,
                            $ticketNo,
                            $payoutAmount,
                            $occurredAt,
                        );
                    }),
            ])
            ->recordActions([
                // APPEND-ONLY — no edit, no delete. Model::booted() throws
                // on any update/delete attempt (Issue #83).
            ]);
    }
}
