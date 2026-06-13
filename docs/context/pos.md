# POS

The physical point-of-sale: Registers and Shifts, cash movements, Payments and Receipts, POS Returns, Manual Discounts and Parked Sales (ADR 0009).

## Language

**Register**:
A physical checkout point (counter / till) inside a `pos` Shop — the thing a Shift is opened on. **Fixed-till** model: a Shift belongs to one Register and is never moved between Registers. Fields: `ref_shop_id` (must be a `pos` Shop), `name`, `active`. A `pos` Shop **auto-provisions one default Register** on creation, so a single-counter shop never has to think about Registers; adding/naming a second counter is a later UI affordance, but the model carries Register from day one so the "one open Shift per Register" rule never has to be retrofitted.
_Also_: เครื่องขาย, จุดชำระเงิน, เคาน์เตอร์, till
_Avoid_: Terminal (hardware-specific), POS (that's the Platform type, not one counter), drawer (the cash drawer is part of a Register, not the Register itself)

**Shift**:
One Cashier's session at one Register, from open to close — the **unit of cash accountability** (whoever opened the Shift owns its over/short). **Invariant: at most one *open* Shift per Register at a time** (so multiple Registers can each have an open Shift simultaneously). A POS Order can only be rung when a Shift is open on that Register, and each POS Order is **attributed to the Shift** (hence to the Cashier). Fields:
- `ref_register_id`, `cashier` (the User who opened it), `opened_at`, `closed_at`, `status` (`open` / `closed`).
- `opening_float` — counted starting cash declared at open (the baseline for reconciliation).
- `counted_cash` — the cash the Cashier physically counts at close. Entered **blind**: the Cashier counts *before* the system reveals what it expected (anti-fudging).
- `expected_cash` — derived = `opening_float + cash sales (net of change given) + paid-in − paid-out − cash refunds`. (Cash sales = the `cash`-tender Payment Lines on the Shift's Orders, less change handed back — see Payment.)
- `over_short` — `counted_cash − expected_cash`; surfaced on the Shift report, the figure management watches. It posts to the P&L as a **Cash Over/Short** line (the standard income-statement account): a net shortage is an other-expense, a net overage is other-income.

**Paid-in / Paid-out**: cash added to or removed from the drawer for a non-sale reason (making change, a supplier cash payment, a bank drop). Each is recorded on the open Shift **before** the cash physically moves, so `expected_cash` stays truthful. (Modelled as lightweight cash movements on the Shift, distinct from Stock Movements.)
_Also_: กะ, รอบขาย, เปิด-ปิดกะ
_Avoid_: Session (too generic), batch (payment-processor term for settling card transactions — different concept), Z report (that's the *printout* of a closed Shift, not the Shift)

**Payment**:
How money was collected for a **POS** Order — modelled as one or more **Payment Lines** so a single Order can be settled with **split tender** (e.g. part cash, part transfer). **POS-only**: marketplace money is tracked via the Accounting Entry / Actual Net, and social Orders in MVP are simply assumed paid — neither uses Payment. Each Payment Line carries `tender_type` and `amount`. `tender_type` (4): `cash`, `promptpay_qr`, `bank_transfer`, `card`.

- The Payment Lines' total must be **≥** the Order total; any excess is **change** — only `cash` gives change.
- **All tenders are cashier-confirmed manually** in MVP — the Cashier eyeballs the QR/transfer/card result and marks it received. There is **no automatic verification** (no payment-gateway webhook, no bank/slip-verification API). Auto-confirmation — a slip-verification API (e.g. SlipOK-style, checking the slip against the Bank of Thailand; the preferred future path because it keeps the seller's own PromptPay and adds no settlement layer) or a dynamic-QR gateway — is **deferred post-MVP**.
- `cash` Payment Lines feed the open Shift's `expected_cash` (and change reduces net cash in the drawer); non-cash tenders do not affect the cash reconciliation.
_Also_: การชำระเงิน, รับเงิน, ช่องทางจ่าย
_Avoid_: Tender as a separate entity (a tender is a `tender_type` on a Payment Line, not its own table), Settlement (that's the Platform releasing marketplace money — see Settlement Date), COD (a marketplace/social concept, not a POS tender)

**Receipt**:
The proof-of-payment document handed to a walk-in buyer when a POS Order closes. In MVP this is a plain **ใบเสร็จรับเงิน** (receipt) — **not** a tax invoice. It is **not its own entity**: it is *rendered* from the POS Order + its Payment Lines (items, totals, tender breakdown, change, shop info, optional embedded PromptPay QR), and is reprintable. The only new field it requires is a `receipt_no` on the POS Order — a running number assigned at close (`สำเร็จ`), sequential per `pos` Shop.

**Why plain receipt only:** the target sellers are **not VAT-registered** (annual revenue under the ฿1.8M threshold), and Thai law **forbids a non-VAT business from issuing any tax invoice**. So the VAT documents are deliberately out of MVP and **deferred** until VAT-registered sellers are supported:
- **ใบกำกับภาษีอย่างย่อ (ABB)** — what a VAT-registered retail shop issues at the counter; needs the "ใบกำกับภาษีอย่างย่อ/TAX INV(ABB)" mark, 13-digit tax ID, a legally-compliant running number, and "ราคารวมภาษีมูลค่าเพิ่มแล้ว"; the POS machine issuing it needs Revenue Department approval.
- **ใบกำกับภาษีเต็มรูป** — full tax invoice on buyer request (captures buyer tax details).
- **e-Tax Invoice / e-Receipt** (Easy E-Receipt) — the Revenue Department's electronic-submission layer; a separate integration entirely.

_Also_: ใบเสร็จ, ใบเสร็จรับเงิน, บิล
_Avoid_: Tax invoice / ใบกำกับภาษี (a non-VAT seller must not issue one — different document, deferred), Invoice (pre-payment billing doc — a Receipt is post-payment), ABB (deferred VAT variant)

**POS Return**:
A face-to-face refund/return at the counter — modelled as a **linked negative-line POS Order**, *not* a Return entity (see ADR 0009). A POS sale already *is* an Order; a POS return is the same shape with the sign flipped, carrying a `ref_order_id` to the original sale (bound to its `receipt_no` — returns must reference the original sale, the standard fraud/inventory control). Mechanics, all sign-inverted from a sale:
- **Stock**: each negative Order Line fires a `RECEIVE` (the inverse of the sale's `SHIP`); the Cashier's condition check routes good stock to On-Hand and damaged stock to Damaged (a `DAMAGE` instead).
- **Money**: a negative Payment Line refunds the buyer; a `cash` refund is cash out of the drawer and feeds the open Shift's `expected_cash` (the "− cash refunds" term).
- **Approval**: refunds are Admin-gated (a Cashier needs Admin approval — see Role).
- **Exchange** = a negative Order (the return) **plus** a positive Order (the new sale), settling the net difference — the standard way to compute an exchange's price delta.

There is **no** Inbound Scan, Return Sub-Status, or platform-closure lifecycle here — those exist only for the marketplace gap the POS counter doesn't have.

*MVP scope:* return must reference an original POS Order; **no-receipt returns and store credit are deferred.*
_Also_: คืนเงินหน้าร้าน, รีฟันด์ POS, คืนของหน้าร้าน
_Avoid_: Return (that's the marketplace/social entity with a lifecycle — a POS Return is a negative Order), Void (cancelling a sale before it closes is not the same as refunding a completed one), Store credit (deferred)

**Manual Discount**:
An ad-hoc price reduction a Cashier applies **at the counter** during a POS sale (haggling, a regular-customer courtesy) — deliberately a **different concept from a Promotion / Deal Price**, which is a scheduled, per-Listing price set up in advance for a Shop (the two are not one reused mechanism; see ADR 0010). A Manual Discount can be applied **per Order Line or to the whole cart**, as either a **percentage or a Baht amount**, and is recorded on the Order/Order Line so reporting and profit reflect the margin given away. When a Cashier applies one it is **Admin-gated** (discount is one of the three most-abused POS actions — see Role). In MVP a POS sale prices from **List Price + Manual Discount**; active Promotions are **not** auto-applied to POS (linking Promotions into POS is deferred).
_Also_: ส่วนลดสด, ส่วนลดหน้าร้าน, ลดราคา
_Avoid_: Promotion / Deal Price (scheduled, per-Listing — a Manual Discount is ad-hoc at the counter), Voucher (buyer-side platform code, not a counter discount)

**Parked Sale**:
A POS Order **held before payment** so the Cashier can serve the next customer (the buyer steps aside to grab more items) and resume it later. It sits in the pre-payment `รอชำระ` state — POS normally goes `รอชำระ → สำเร็จ` in one step, and a Parked Sale simply pauses at `รอชำระ`. Because POS only moves stock at `สำเร็จ` (immediate deduction, no reservation), **a Parked Sale touches no stock and no money until it is resumed and closed** — so any number of bills can be parked on a Register at once with zero accounting effect. Belongs to the open Shift / Register it was started on.
_Also_: พักบิล, บิลพัก, ค้างบิล
_Avoid_: Draft order (implies an editable saved order across channels — Parked Sale is the POS-counter hold specifically), Reserved (parking reserves no stock)
