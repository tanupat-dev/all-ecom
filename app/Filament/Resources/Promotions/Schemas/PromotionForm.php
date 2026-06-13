<?php

namespace App\Filament\Resources\Promotions\Schemas;

use App\Enums\PromotionType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PromotionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อโปรโมชั่น')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->label('ประเภท')
                    ->options([
                        PromotionType::Base->value => 'ส่วนลดประจำ (base)',
                        PromotionType::Campaign->value => 'แคมเปญตามช่วงเวลา (campaign)',
                    ])
                    ->required()
                    ->live(),
                // A campaign carries a window; a base never does (CONTEXT.md).
                DateTimePicker::make('start_at')
                    ->label('เริ่ม')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('type') === PromotionType::Campaign->value)
                    ->required(fn (Get $get): bool => $get('type') === PromotionType::Campaign->value),
                DateTimePicker::make('end_at')
                    ->label('สิ้นสุด')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('type') === PromotionType::Campaign->value)
                    ->required(fn (Get $get): bool => $get('type') === PromotionType::Campaign->value)
                    ->after('start_at'),
            ]);
    }
}
