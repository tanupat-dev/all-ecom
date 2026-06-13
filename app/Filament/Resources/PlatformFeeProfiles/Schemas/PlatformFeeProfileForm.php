<?php

namespace App\Filament\Resources\PlatformFeeProfiles\Schemas;

use App\Enums\AccountingLineCategory;
use App\Models\PlatformFeeProfile;
use App\Models\Shop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PlatformFeeProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->label('ร้าน (Shop)')
                    ->options(fn (): array => Shop::query()->pluck('name', 'id')->all())
                    ->required(),
                Select::make('category')
                    ->label('หมวดค่าธรรมเนียม')
                    // Only the fee-side categories carry a predicted rate — a
                    // Fee Profile never predicts the gross sale or refunds.
                    ->options(self::feeSideOptions())
                    ->required(),
                TextInput::make('rate_bps')
                    ->label('อัตราค่าธรรมเนียม (basis points — 321 = 3.21%)')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                TextInput::make('fixed_satang')
                    ->label('ค่าธรรมเนียมคงที่ต่อออเดอร์ (สตางค์)')
                    ->numeric()
                    ->integer()
                    ->default(0)
                    ->required(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function feeSideOptions(): array
    {
        $options = [];

        foreach (PlatformFeeProfile::FEE_SIDE_CATEGORIES as $category) {
            $options[$category->value] = self::label($category);
        }

        return $options;
    }

    private static function label(AccountingLineCategory $category): string
    {
        return match ($category) {
            AccountingLineCategory::Commission => 'ค่าคอมมิชชั่น (Commission)',
            AccountingLineCategory::PaymentFee => 'ค่าธรรมเนียมชำระเงิน (Payment fee)',
            AccountingLineCategory::ShippingSellerPaid => 'ค่าส่งที่ผู้ขายจ่าย (Shipping)',
            AccountingLineCategory::ShippingReturn => 'ค่าส่งคืนสินค้า (Return shipping)',
            AccountingLineCategory::MarketingFee => 'ค่าการตลาด/แคมเปญ (Marketing)',
            AccountingLineCategory::AffiliateFee => 'ค่าพันธมิตร (Affiliate)',
            AccountingLineCategory::TaxWithheld => 'ภาษีหัก ณ ที่จ่าย (Tax withheld)',
            AccountingLineCategory::Other => 'อื่นๆ (Other)',
            default => $category->value,
        };
    }
}
