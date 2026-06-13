<?php

namespace App\Models;

use App\Enums\AccountingLineCategory;
use App\Jobs\RecomputeShopExpectedNet;
use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform Fee Profile (CONTEXT.md: Platform Fee Profile; Issue #65): one
 * expected fee rate for a Shop, on one fee-side Accounting Line Category. The
 * forward-looking input to Expected Net — distinct from the Accounting Entry,
 * which records what was actually charged.
 *
 * `rate_bps` is the expected rate in basis points (integer — 321 = 3.21%,
 * never float, ADR 0015). `fixed_satang` is a raw integer count of satang
 * added straight onto the fee total — deliberately NOT a MoneyCast, because
 * it is consumed as an integer inside ComputeExpectedNet's satang arithmetic
 * (no Money value object crosses that boundary), and is stored signed so a
 * flat credit can be expressed.
 *
 * Only fee-side categories carry a rate (a Fee Profile predicts deductions,
 * not the gross sale or refunds) — see FEE_SIDE_CATEGORIES.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property AccountingLineCategory $category
 * @property int $rate_bps
 * @property int $fixed_satang
 */
class PlatformFeeProfile extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    /**
     * The fee-side Accounting Line Categories a Fee Profile may carry a rate
     * for — never the income side (sale_income / refund are not fees you
     * predict as a rate). Kept here (not on the shared enum) so this slice
     * owns the list; the orchestrator may later promote it to the enum.
     *
     * @var list<AccountingLineCategory>
     */
    public const FEE_SIDE_CATEGORIES = [
        AccountingLineCategory::Commission,
        AccountingLineCategory::PaymentFee,
        AccountingLineCategory::ShippingSellerPaid,
        AccountingLineCategory::ShippingReturn,
        AccountingLineCategory::MarketingFee,
        AccountingLineCategory::AffiliateFee,
        AccountingLineCategory::TaxWithheld,
        AccountingLineCategory::Other,
    ];

    protected $fillable = ['shop_id', 'category', 'rate_bps', 'fixed_satang'];

    protected function casts(): array
    {
        return [
            'category' => AccountingLineCategory::class,
            'rate_bps' => 'integer',
            'fixed_satang' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A Shop's Expected Net is derived from its Fee Profile, so any change
        // to a profile row recomputes that Shop's marketplace Orders — queued
        // and chunked, never a request-time scan. The per-order math lives in
        // ComputeExpectedNet; the Job is only the fan-out.
        static::saved(static function (PlatformFeeProfile $profile): void {
            $profile->dispatchRecompute();
        });

        static::deleted(static function (PlatformFeeProfile $profile): void {
            $profile->dispatchRecompute();
        });
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    private function dispatchRecompute(): void
    {
        if ($this->tenant_id === null) {
            return;
        }

        RecomputeShopExpectedNet::dispatch($this->shop_id, $this->tenant_id);
    }
}
