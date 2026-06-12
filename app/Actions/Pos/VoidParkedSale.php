<?php

namespace App\Actions\Pos;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Orders\ApplyOrderMilestones;
use App\Actions\Orders\SetOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Voids a Parked Sale (CONTEXT.md: Order Status — a parked bill voided
 * before payment → ยกเลิก). A parked sale never touched stock or money,
 * so the void moves nothing; voiding a COMPLETED sale is a refund
 * (RefundPosSale), never this.
 */
class VoidParkedSale
{
    public function __construct(
        private readonly SetOrderStatus $setStatus,
        private readonly ApplyOrderMilestones $milestones,
        private readonly RecordAuditLog $audit,
    ) {}

    public function handle(Order $parked): Order
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->checkPermissionTo('sale.void')) {
            throw new AuthorizationException('Voiding a parked sale requires the sale.void permission.');
        }

        if ($parked->platform_type !== PlatformType::Pos || $parked->status !== OrderStatus::PendingPayment) {
            throw new LogicException('Only a parked POS sale (รอชำระ) can be voided — a completed sale needs a refund.');
        }

        $this->setStatus->handle($parked, OrderStatus::Cancelled);
        $this->milestones->handle($parked, ['cancelled_date' => now()]);
        $this->audit->handle('sale.void', $parked);

        return $parked;
    }
}
