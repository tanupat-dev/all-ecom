<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;

/**
 * Renders the plain ใบเสร็จรับเงิน from the Order + its Payment Lines
 * (CONTEXT.md: Receipt — NOT a tax invoice; reprintable). Thin: no logic
 * beyond deriving change for display.
 */
class ReceiptController extends Controller
{
    public function __invoke(Order $order): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->checkPermissionTo('pos.checkout'), 403);
        abort_if($order->receipt_no === null, 404);

        $tendered = Money::fromSatang((int) $order->payments()->sum('amount'));
        $change = $tendered->subtract($order->total ?? Money::fromSatang(0));

        if ($change->isNegative()) {
            $change = Money::fromSatang(0);
        }

        return view('pos.receipt', [
            'order' => $order->load(['lines.variant.product', 'payments', 'shop']),
            'change' => $change,
        ]);
    }
}
