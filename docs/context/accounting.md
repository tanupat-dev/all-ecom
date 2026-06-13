# Accounting & Profitability

Money in and out per settlement cycle (ADR 0007/0020): Cost Price, Platform Fee Profiles, the Accounting Entry and its line categories, Expected vs Actual Net, and Reconciliation.

## Language

**Cost Price**:
The seller's cost to acquire one unit of a Variant — used for profit calculation. Stored with **change history**: each time the seller updates the cost, the previous value is preserved with a `valid_from` timestamp. When computing the profit of a past Order, the system uses the Cost Price that was active on the Order's sale date — not the current cost.
_Also_: ราคาต้นทุน, ทุน
_Avoid_: COGS (a derived per-Order amount, not the per-unit value), purchase price

**Platform Fee Profile**:
The expected/predicted fee rates the system applies when **estimating** the seller's net receivable on a hypothetical sale — used by the Margin Calculator to recommend a Selling Price for a desired profit. Configured per Platform (optionally per Shop or per Variant category). Distinct from Accounting Entries which record what was *actually* charged. May be auto-suggested from historical Accounting Entry averages.
_Also_: ค่าธรรมเนียมคาดการณ์
_Avoid_: Rate card (platform-published, may differ from reality), commission rate (only one of several fields)

**Accounting Line Category**:
The canonical bucket for a single line of a Platform's settlement breakdown when imported from Accounting Excel — spanning both **income** and **fee/deduction** sides, because an Order's settlement contains both (ADR 0020; the cross-industry standard — Stripe `BalanceTransaction`, A2X — keeps the gross sale as its own line, not bucketed among fees). **10 categories** — extensible:
- income / contra side:
  1. `sale_income` — the gross sale (Effective Price) and any buyer-paid shipping income; the positive leg the fees deduct from
  2. `refund` — money returned to the buyer for this Order (broken out as its own line, as A2X does)
- fee / deduction side:
  3. `commission` — platform commission + service fee + infrastructure fee (the "platform take")
  4. `payment_fee` — payment processing
  5. `shipping_seller_paid` — outbound shipping net to seller (after Platform subsidy)
  6. `shipping_return` — return shipping deducted from seller
  7. `marketing_fee` — campaign/flash sale fees, ad spend (GMV Max, Xtra), coupons funded by seller
  8. `affiliate_fee` — paid out to affiliates/partners (TikTok-prominent)
  9. `tax_withheld` — withholding tax, VAT
  10. `other` — catch-all for an unknown fee, for drill-down/triage (never the gross sale)

Each Accounting Entry line maps one Platform-native column to one Category. Signed amount (`+` = seller receives, `−` = seller pays) preserved, so the Order's signed lines **sum to the net the Platform actually transferred** = Actual Net (ADR 0020). The Platform-native field name is kept in `source_field` for drilldown. The enum is `AccountingLineCategory` (renamed from the fee-only `FeeCategory`, ADR 0020).
_Also_: หมวดรายการบัญชี
_Avoid_: Fee Category (fee-only — superseded; some lines are income), Fee type (too generic)

**Accounting Entry**:
The complete financial record for one Order, built from a Platform's accounting Excel — the Platform's full **settlement breakdown** for that Order: a positive **income** line (the gross sale, `sale_income`), one signed line per **fee** deducted, and any **refund** line (ADR 0020). Each line has a `category` (Accounting Line Category), `amount` (signed THB, `+` = seller receives, `−` = seller pays), `source_field` (Platform's original column name), and the `statement_cycle` it came from. The Order's signed lines **sum to the net the Platform actually transferred** = Actual Net, and the importer fail-loud cross-checks that sum against the file's own transferred-total column (ADR 0005/0020). Always attached to one Order (`ref_order_id` is required, never null). **Marketplace Orders only** — an Accounting Entry exists to capture the platform's settle-later money flow (the gap that needs reconciling). A **POS Order has no Accounting Entry**: its money is collected in hand at the point of sale with no platform fees, so its contribution to the P&L is computed **directly** — revenue = the Payment total, COGS = the Cost Price of the Variants sold (recognised at the same moment, per the matching principle), no fee leg. The combined P&L sums a per-Order net across channels (marketplace = Actual Net from the Accounting Entry; POS = Payment − COGS). Each Platform structures its accounting file differently — one wide row per Order (Shopee Income report; TikTok), or multiple rows per Order (Lazada transaction journal) — the importer normalises all into line items under one Accounting Entry per Order.

**Import is cycle-aware, not "immutable once imported"** (see ADR 0007): accounting files are issued per **statement cycle** (`รหัสรอบบิล` / settlement period), and an Order's line items can be split across several cycles — e.g. the sale posts in one cycle, a return deduction in a later one. So re-importing the **same cycle** replaces that cycle's line items for the Order (idempotent — no double-count), while a **new cycle appends** its line items. The Accounting Entry's totals (and Actual Net) are the sum across **all** of the Order's cycles.
_Also_: รายการบัญชี
_Avoid_: Transaction (overloaded with Order/Stock Movement), journal entry (accounting jargon)

**Expense**:
Money the seller spends on something that is **not** Cost Price and **not** a Platform fee — i.e., operating expenses entered manually by the seller. Has `date`, `category` (free-form: packaging, free gifts, paper, utilities, rent, staff, etc.), `amount`, `note`, and optional `ref_order_id` (for per-order attributable costs like a free gift sent with a specific Order). Used in period-level (monthly) P&L. *MVP focuses on non-attributable Operating Expenses; per-Order packaging cost allocation is a later refinement.*
_Also_: ค่าใช้จ่ายร้าน, OPEX
_Avoid_: Cost (means Cost Price), spending (vague)

**Margin Calculator**:
A tool that, given Cost Price + target profit (as `%` or fixed THB) + Platform Fee Profile, computes the **Effective Price** the seller must set to achieve the target after fees. Symmetric: given an Effective Price, shows the implied profit. Operates per Listing-Variant because different Platforms have different fee structures → different recommended prices.

Formula direction (target → price):
```
required_net = cost + target_profit
effective_price = required_net / (1 − total_fee_rate)
```
_Also_: คำนวณราคาขาย
_Avoid_: Price suggester (vague)

**Expected Net**:
The Effective Price net of Platform fees the seller *expects* to receive for an Order — derived from Effective Price minus the Platform Fee Profile applied to that sale. The forward-looking number used to set prices and to be checked against reality.
_Also_: เงินที่คาดว่าจะได้
_Avoid_: Net revenue (more ambiguous)

**Actual Net**:
The Effective Price net of Platform fees the seller *actually* received for an Order — the total of all signed amounts in the Order's Accounting Entry (the positive `sale_income` line plus the negative fee/refund lines, summed across all cycles), which equals the net the Platform actually transferred (ADR 0020). The backward-looking number that grounds the Expected Net check.
_Also_: เงินที่ได้จริง
_Avoid_: Realized revenue, payout (overloaded)

**Reconciliation Status**:
A per-Order flag comparing Expected Net to Actual Net — **marketplace Orders only**. A POS Order has no Reconciliation Status: the money is already in hand at the sale (no hold, no settlement, nothing to reconcile). Three values:
- `not_yet_paid` — no Actual Net yet (Order still within Platform hold period, or accounting Excel not yet imported)
- `paid_ok` — Actual Net imported, |Actual − Expected| ≤ Mismatch Threshold
- `paid_mismatch` — Actual Net imported, |Actual − Expected| > Mismatch Threshold; surfaces in a Mismatch list for investigation, with auto-suggestion of a Claim type if the pattern matches

There is intentionally **no `Payout` entity** in the model — Wallet→Bank withdrawals are seller-controlled cash flow, not a source of reconciliation error. (Future: a manual "verified" checkbox on bulk withdrawals if needed.)
_Also_: สถานะตรวจสอบ
_Avoid_: Settlement status (overloaded), payment status

**Hold Period**:
The number of days the Platform typically holds the Actual Net before crediting the seller's wallet — used to set the `expected_payout_date` of an Order (`payout_anchor_date + hold_period`, where the anchor is the milestone named by the Shop's Payout Anchor — `completed_date` for Shopee, `delivered_date` for TikTok/Lazada) so the system knows when to expect reconciliation. Configured per Shop with a default value, and auto-tuned over time from historical median (`payout_anchor_date → settlement_date`) once Accounting Excel has been imported repeatedly — but only where the Platform exposes a Settlement Date; otherwise the manual value stands.
_Also_: ระยะเวลาถือเงิน, hold time
_Avoid_: Settlement period (more specific accounting term)

**Expected Payout Date**:
The date the system predicts an Order's money will be settled into the seller's Platform balance — derived as `payout_anchor_date + hold_period` (from Shop Settings). Its job is not display but **detection**: once this date passes and no Settlement Date has been imported for the Order (Reconciliation still `not_yet_paid`), the Order is **overdue** and surfaced so the seller can chase the money or file a Claim **before the Platform's claim window closes**. A null `payout_anchor_date` (goods not yet delivered/completed) means no Expected Payout Date yet.
_Also_: วันคาดว่าเงินเข้า, กำหนดเงินเข้า
_Avoid_: Payout date (that's the actual event), due date (vague)

**Settlement Date**:
The date the Platform **released an Order's money from hold into the seller's withdrawable Platform balance** — i.e. the moment the funds became the seller's, read from the accounting Excel. **Not** the date the money was withdrawn from the Platform into the seller's bank account: that withdrawal is seller-controlled cash flow (manual on TikTok, automatic on Shopee/Lazada) and is deliberately ignored — consistent with there being no Payout entity. Used to (1) auto-tune Hold Period (median of `payout_anchor_date → settlement_date`) and (2) confirm money actually arrived for Reconciliation. Availability differs per Platform: Lazada exposes it (`วันที่ปรับปรุงเข้ายอดของฉัน`), Shopee in its wallet/income report; **TikTok's export may not contain it at all** — when absent, auto-tune is disabled for that Shop and the manually-set `hold_period` is used instead (we never guess the date — see ADR 0005).
_Also_: วันเงินเข้ายอด, วันปล่อยเงิน
_Avoid_: wallet_credit_date (conflated with bank withdrawal), payout date, withdrawal date (that's the ignored Wallet→Bank step)

**Mismatch Threshold**:
The absolute Thai Baht amount within which Actual Net and Expected Net are considered equal — set per Shop, default ฿1 to absorb rounding. Differences above this threshold flip Reconciliation Status to `paid_mismatch`.
_Also_: เกณฑ์รับได้
_Avoid_: Tolerance (vague)

