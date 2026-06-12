<x-filament-panels::page>
    @if ($alerts === [])
        <x-filament::section>
            <p class="text-sm">ไม่มีสินค้าขายเกินตอนนี้ — Available ทุกตัวไม่ติดลบ</p>
        </x-filament::section>
    @endif

    @foreach ($alerts as $alert)
        <x-filament::section>
            <x-slot name="heading">
                {{ $alert['balance']->variant?->master_sku }}
                — {{ $alert['balance']->location?->name }}
                (Available {{ $alert['balance']->available }})
            </x-slot>

            <x-slot name="description">
                ระบบไม่ยกเลิกออเดอร์ให้เอง — ไปยกเลิกบนแพลตฟอร์มแล้ว import ซ้ำ
                (ออเดอร์ที่ขึ้น ยกเลิก จะคืนยอดจองเอง) หรือเติมสต็อกให้ทัน
            </x-slot>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-1">ออเดอร์</th>
                        <th class="py-1">ร้าน</th>
                        <th class="py-1">จองไว้</th>
                        <th class="py-1">มูลค่า (฿)</th>
                        <th class="py-1">สั่งเมื่อ</th>
                        <th class="py-1">ผู้ซื้อ</th>
                        <th class="py-1">แนะนำ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($alert['conflicts'] as $conflict)
                        <tr>
                            <td class="py-1">{{ $conflict['order']->platform_order_id }}</td>
                            <td class="py-1">{{ $conflict['order']->shop?->name }}</td>
                            <td class="py-1">{{ $conflict['held'] }}</td>
                            <td class="py-1">{{ $conflict['order']->total?->toBaht() }}</td>
                            <td class="py-1">{{ $conflict['order']->created_date?->diffForHumans() }}</td>
                            <td class="py-1">{{ $conflict['order']->buyer_name ?? '—' }}</td>
                            <td class="py-1">
                                @if ($conflict['suggested'])
                                    <x-filament::badge color="danger">ตัวเลือกยกเลิก</x-filament::badge>
                                @else
                                    <x-filament::badge color="success">มาก่อน — ควรส่ง</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
