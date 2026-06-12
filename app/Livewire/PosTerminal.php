<?php

namespace App\Livewire;

use App\Actions\Pos\CheckoutPosSale;
use App\Actions\Pos\FindVariantForPos;
use App\Actions\Pos\ParkSale;
use App\Actions\Pos\VoidParkedSale;
use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Enums\ShiftStatus;
use App\Enums\TenderType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shift;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * The POS screen (CONVENTIONS: POS = Livewire component; business logic
 * stays in the Pos Actions this component composes). State here is UI
 * state only — the cart prices/totals shown are previews; the Action
 * re-prices authoritatively at checkout.
 */
#[Layout('layouts.pos')]
class PosTerminal extends Component
{
    /** @var array<int, array{variant_id: int, sku: string, name: string, image_url: string|null, qty: int, unit_satang: int, discount_baht: string, discount_percent: string}> */
    public array $cart = [];

    public string $code = '';

    public string $cartDiscount = '';

    public string $error = '';

    /** @var list<array{tender: string, amount: string}> */
    public array $tenders = [];

    public ?int $resumeOrderId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->checkPermissionTo('pos.checkout') ?? false, 403);
    }

    public function addItem(): void
    {
        $this->error = '';

        try {
            $variant = app(FindVariantForPos::class)->handle($this->code);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        foreach ($this->cart as $index => $line) {
            if ($line['variant_id'] === $variant->id) {
                $this->cart[$index]['qty']++;
                $this->code = '';

                return;
            }
        }

        // product.primaryImage is eager-loaded by FindVariantForPos — avoids N+1
        $product = $variant->product;

        $this->cart[] = [
            'variant_id' => $variant->id,
            'sku' => $variant->master_sku,
            'name' => ($product !== null ? $product->name : $variant->master_sku).($variant->name !== null ? " ({$variant->name})" : ''),
            'image_url' => $product?->primaryImage?->url,
            'qty' => 1,
            'unit_satang' => $variant->list_price->satang ?? 0,
            'discount_baht' => '',
            'discount_percent' => '',
        ];
        $this->code = '';
    }

    public function removeLine(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    public function addTender(string $tender, string $amount): void
    {
        $this->tenders[] = ['tender' => $tender, 'amount' => $amount];
    }

    public function checkout(): void
    {
        $this->error = '';

        try {
            $resume = $this->resumeOrderId !== null
                ? Order::query()->findOrFail($this->resumeOrderId)
                : null;

            $order = app(CheckoutPosSale::class)->handle(
                $this->openShift(),
                $this->cartItems(),
                array_map(fn (array $tender): array => [
                    'tender' => TenderType::from($tender['tender']),
                    'amount' => Money::fromBaht($tender['amount']),
                ], $this->tenders),
                $this->cartDiscount !== '' ? Money::fromBaht($this->cartDiscount) : null,
                $resume,
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('pos.receipt', ['order' => $order]);
    }

    public function park(): void
    {
        $this->error = '';

        try {
            app(ParkSale::class)->handle($this->openShift(), $this->cartItems(), $this->cartDiscount !== '' ? Money::fromBaht($this->cartDiscount) : null);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('cart', 'tenders', 'cartDiscount', 'resumeOrderId');
    }

    public function resume(int $orderId): void
    {
        $parked = Order::query()
            ->where('platform_type', PlatformType::Pos)
            ->where('status', OrderStatus::PendingPayment)
            ->findOrFail($orderId);

        // Eager-load variant + product + primaryImage to avoid N+1 per cart line
        $this->cart = $parked->lines()->with('variant.product.primaryImage')->get()->map(function (OrderLine $line): array {
            $variant = $line->variant;
            $product = $variant?->product;

            return [
                'variant_id' => $line->variant_id,
                'sku' => $variant !== null ? $variant->master_sku : '',
                'name' => ($product !== null ? $product->name : ($variant !== null ? $variant->master_sku : ''))
                    .($variant !== null && $variant->name !== null ? " ({$variant->name})" : ''),
                'image_url' => $product?->primaryImage?->url,
                'qty' => $line->qty,
                'unit_satang' => $line->unit_price->satang ?? 0,
                'discount_baht' => $line->discount !== null && ! $line->discount->isZero() ? $line->discount->toBaht() : '',
                'discount_percent' => '',
            ];
        })->all();
        $this->cartDiscount = $parked->cart_discount !== null && ! $parked->cart_discount->isZero()
            ? $parked->cart_discount->toBaht()
            : '';
        $this->resumeOrderId = $parked->id;
    }

    public function voidParked(int $orderId): void
    {
        app(VoidParkedSale::class)->handle(Order::query()->findOrFail($orderId));

        if ($this->resumeOrderId === $orderId) {
            $this->reset('cart', 'tenders', 'cartDiscount', 'resumeOrderId');
        }
    }

    public function render(): View
    {
        $parked = Order::query()
            ->where('platform_type', PlatformType::Pos)
            ->where('status', OrderStatus::PendingPayment)
            ->orderByDesc('id')
            ->get();

        return view('livewire.pos-terminal', [
            'parkedSales' => $parked,
            'totalPreviewSatang' => $this->totalPreviewSatang(),
        ]);
    }

    private function openShift(): Shift
    {
        return Shift::query()->where('status', ShiftStatus::Open)->firstOrFail();
    }

    /**
     * @return list<array{variant: Variant, qty: int, discount_baht?: Money, discount_percent?: float}>
     */
    private function cartItems(): array
    {
        $items = [];

        foreach ($this->cart as $line) {
            $item = [
                'variant' => Variant::query()->findOrFail($line['variant_id']),
                'qty' => max(1, $line['qty']),
            ];

            if ($line['discount_baht'] !== '') {
                $item['discount_baht'] = Money::fromBaht($line['discount_baht']);
            }

            if ($line['discount_percent'] !== '') {
                $item['discount_percent'] = (float) $line['discount_percent'];
            }

            $items[] = $item;
        }

        return $items;
    }

    private function totalPreviewSatang(): int
    {
        $total = 0;

        foreach ($this->cart as $line) {
            $total += $line['unit_satang'] * max(1, $line['qty']);
        }

        return $total;
    }
}
