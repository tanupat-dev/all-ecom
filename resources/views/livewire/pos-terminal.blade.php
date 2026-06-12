<div>
    <h1>ขายหน้าร้าน (POS)</h1>

    @if ($error !== '')
        <p class="error" data-test="error">{{ $error }}</p>
    @endif

    <form wire:submit="addItem">
        <input type="text" wire:model="code" placeholder="สแกนบาร์โค้ด / พิมพ์ Master SKU"
               autofocus x-data x-init="$el.focus()" data-test="code-input">
        <button type="submit">เพิ่มสินค้า</button>
    </form>

    <table>
        <thead>
        <tr><th>สินค้า</th><th>จำนวน</th><th>ราคา/หน่วย (บาท)</th><th>ส่วนลด (บาท)</th><th></th></tr>
        </thead>
        <tbody>
        @foreach ($cart as $index => $line)
            <tr wire:key="line-{{ $index }}">
                <td>
                    @if (!empty($line['image_url']))
                        <img src="{{ $line['image_url'] }}" alt=""
                             style="width:40px;height:40px;object-fit:cover;vertical-align:middle;border-radius:4px;margin-right:6px">
                    @else
                        <span style="display:inline-block;width:40px;height:40px;background:#e5e7eb;border-radius:4px;margin-right:6px;vertical-align:middle"
                              aria-hidden="true"></span>
                    @endif
                    {{ $line['name'] }} <small>{{ $line['sku'] }}</small>
                </td>
                <td><input type="number" min="1" wire:model.live="cart.{{ $index }}.qty" style="width:4.5rem"></td>
                <td>{{ number_format($line['unit_satang'] / 100, 2) }}</td>
                <td><input type="text" wire:model.live="cart.{{ $index }}.discount_baht" style="width:6rem"></td>
                <td><button type="button" wire:click="removeLine({{ $index }})">ลบ</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p>ส่วนลดท้ายบิล (บาท): <input type="text" wire:model.live="cartDiscount" style="width:6rem"></p>
    <p class="total" data-test="total">ยอดรวม (ก่อนส่วนลด): {{ number_format($totalPreviewSatang / 100, 2) }} บาท</p>

    <h2>รับชำระ</h2>
    @foreach ($tenders as $tender)
        <p>{{ $tender['tender'] }} — {{ $tender['amount'] }} บาท</p>
    @endforeach

    <div x-data="{ tender: 'cash', amount: '' }">
        <select x-model="tender">
            <option value="cash">เงินสด</option>
            <option value="promptpay_qr">พร้อมเพย์ QR</option>
            <option value="bank_transfer">โอนธนาคาร</option>
            <option value="card">บัตร</option>
        </select>
        <input type="text" x-model="amount" placeholder="จำนวนเงิน (บาท)">
        <button type="button" x-on:click="$wire.addTender(tender, amount); amount = ''">เพิ่มช่องทาง</button>
    </div>

    <p>
        <button type="button" wire:click="checkout" data-test="checkout">ปิดบิล</button>
        <button type="button" wire:click="park" data-test="park">พักบิล</button>
    </p>

    <h2>บิลที่พักไว้</h2>
    <ul>
        @foreach ($parkedSales as $parked)
            <li wire:key="parked-{{ $parked->id }}">
                #{{ $parked->id }} — {{ $parked->total?->toBaht() }} บาท
                <button type="button" wire:click="resume({{ $parked->id }})">เรียกคืน</button>
                <button type="button" wire:click="voidParked({{ $parked->id }})">ยกเลิกบิล</button>
            </li>
        @endforeach
    </ul>
</div>
