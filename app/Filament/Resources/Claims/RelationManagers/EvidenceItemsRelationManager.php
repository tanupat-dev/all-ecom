<?php

namespace App\Filament\Resources\Claims\RelationManagers;

use App\Actions\Claims\AddClaimEvidenceItem;
use App\Actions\Claims\SetClaimEvidenceChecked;
use App\Models\Claim;
use App\Models\ClaimEvidenceItem;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Evidence Checklist for a Claim (CONTEXT.md: Claim — Evidence Checklist;
 * Issue #82, #84). Shows all evidence items (default-seeded + custom) and
 * lets an authorised user toggle each item's `checked` state, or add a new
 * custom item. Gated on `claim.manage` for all mutations (ADR 0012).
 */
class EvidenceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'evidenceItems';

    protected static ?string $title = 'หลักฐาน (Evidence Checklist)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('หลักฐาน'),
                IconColumn::make('checked')
                    ->label('ตรวจแล้ว')
                    ->boolean(),
                TextColumn::make('is_default')
                    ->label('ชนิด')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'ค่าเริ่มต้น' : 'เพิ่มเอง'),
            ])
            ->headerActions([
                Action::make('addEvidence')
                    ->label('เพิ่มหลักฐาน')
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->schema([
                        TextInput::make('label')
                            ->label('ชื่อหลักฐาน')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        /** @var Claim $claim */
                        $claim = $this->getOwnerRecord();
                        $label = $data['label'];

                        if (! is_string($label)) {
                            throw new \InvalidArgumentException('Expected string for label.');
                        }

                        app(AddClaimEvidenceItem::class)->handle($claim, $label);
                    }),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label('สลับสถานะ')
                    ->icon('heroicon-o-check-circle')
                    ->authorize(fn (ClaimEvidenceItem $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (ClaimEvidenceItem $record): ClaimEvidenceItem => app(SetClaimEvidenceChecked::class)->handle($record, ! $record->checked)),
            ]);
    }
}
