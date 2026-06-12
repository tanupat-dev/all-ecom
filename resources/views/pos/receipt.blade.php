<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ใบเสร็จรับเงิน #{{ $order->receipt_no }}</title>
    <style>
        body { font-family: ui-monospace, monospace; max-width: 360px; margin: 1rem auto; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: .15rem 0; }
        .right { text-align: right; }
        hr { border: none; border-top: 1px dashed #555; }
    </style>
</head>
<body onload="window.print()">
    {{-- ใบเสร็จรับเงินธรรมดา — ไม่ใช่ใบกำกับภาษี (ผู้ขายไม่จด VAT; CONTEXT.md: Receipt) --}}
    <h3>{{ $order->shop?->name }}</h3>
    <p>ใบเสร็จรับเงิน เลขที่ {{ $order->receipt_no }}<br>
       {{ $order->completed_date?->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>
    <hr>
    <table>
        @foreach ($order->lines as $line)
            <tr>
                <td>{{ $line->variant?->product?->name }} × {{ $line->qty }}</td>
                <td class="right">{{ $line->line_total?->toBaht() }}</td>
            </tr>
        @endforeach
        @if ($order->cart_discount !== null && ! $order->cart_discount->isZero())
            <tr><td>ส่วนลดท้ายบิล</td><td class="right">-{{ $order->cart_discount->toBaht() }}</td></tr>
        @endif
    </table>
    <hr>
    <table>
        <tr><td><strong>ยอดสุทธิ</strong></td><td class="right"><strong>{{ $order->total?->toBaht() }}</strong></td></tr>
        @foreach ($order->payments as $payment)
            <tr><td>{{ $payment->tender_type->value }}</td><td class="right">{{ $payment->amount?->toBaht() }}</td></tr>
        @endforeach
        <tr><td>เงินทอน</td><td class="right">{{ $change->toBaht() }}</td></tr>
    </table>
    <hr>
    <p>ขอบคุณที่อุดหนุนครับ/ค่ะ</p>
</body>
</html>
