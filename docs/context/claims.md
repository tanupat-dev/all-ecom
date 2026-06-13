# Claims

Recovering money the Platform owes the seller: Return Reason fault classification and the Claim lifecycle — evidence checklist + append-only timeline (ADR 0022).

## Language

**Return Reason**:
The platform-supplied field in a return/refund export indicating why the buyer requested a return — stored on the **Return** header. Classified into two buckets for Claim auto-flagging. Classification is **fail-loud**: a reason text not found in the platform's known list is **not** silently bucketed (neither buyer- nor seller-fault) — it is surfaced as "unrecognised reason — ระบบไม่รองรับ" for the seller to classify manually, so a platform changing/adding reason texts can't silently mis-flag Claims.

- **buyer-fault** — buyer changed mind, no longer needed, return in perfect condition. No Claim implications.
- **seller-fault** — received wrong item, damaged, doesn't match description, incomplete, not received. Triggers Claim auto-flagging.

Each platform stores reasons differently:
- **Shopee**: static text. buyer-fault = `ฉันต้องการคืนสินค้าในสภาพสมบูรณ์` only. All other codes (สินค้าหมดอายุ, ทำงานไม่สมบูรณ์, แตกหัก, รอยขีดข่วน/บุบ, ความเสียหายอื่นๆ, ได้รับสินค้าผิด, สินค้าแตกต่างจากที่สั่ง, ไม่ได้รับพัสดุ, สินค้าไม่ครบ/ชิ้นส่วนไม่ครบ, กล่องเปล่า) = seller-fault.
- **TikTok**: static text. buyer-fault = `ไม่ต้องการอีกต่อไป` / `No longer needed`. seller-fault = `สินค้าไม่ตรงกับคำอธิบาย`, `สินค้าไม่ถูกต้อง/ส่งสินค้าผิด`, `สินค้ามีตำหนิหรือใช้งานไม่ได้`, `ได้รับพัสดุแต่มีสินค้าขาดหาย`, `พัสดุหรือสินค้าเสียหาย`, `ไม่ได้รับพัสดุ`, `สงสัยว่าเป็นของปลอม`, `สินค้าหมดอายุ`, `บรรจุภัณฑ์เสียหาย`. Pre-shipment cancellation reasons that appear in the same file but are NOT returns (no Claim logic): `สินค้ามาถึงไม่ตรงเวลา`, `ต้องการเปลี่ยนวิธีการชำระเงิน`, `จำเป็นต้องเปลี่ยนที่อยู่จัดส่ง`, `มีราคาที่ดีกว่า` — importer must skip Claim flagging for these.
- **Lazada**: static English text (current list as of 2026). buyer-fault = `Change of mind` only. seller-fault = `Expired items`, `Missing items in the parcel delivered`, `Item physically damaged upon opening parcel`, `Outer Packaging of the item is damage`, `Item size is not advertised`, `Missing accessories or freebies`, `Counterfeit items`, `Item is defective or not working as intended`, `Wrong items delivered`, `Item/ quality doesn't match description or pictures`. ⚠️ Thai-language version of this list not yet confirmed — verify at importer build time. Older reason texts (from pre-2021 exports) are stale and should not be used for classification.

⚠️ Buyers sometimes choose a seller-fault code when the fault is their own (e.g., "ได้รับสินค้าไม่ตรง" when they ordered the wrong size). The auto-flag is a prompt for the seller to verify — not a confirmed finding of fault. The system must display Return Reason and Buyer Note side-by-side to help the seller assess whether a Claim is warranted.
_Also_: เหตุผลในการขอคืนสินค้า
_Avoid_: Cancellation reason (separate field on Shopee cancelled orders, different concept)

**Claim**:
A request the seller files with a Platform to recover money the Platform or its courier deducted incorrectly. Our system **does not file** Claims; it scaffolds the work around them. ⚠️ Claim flows differ per Platform — must be researched per importer.

Two `claim_type`s:
- **`return_fee`** — attaches to a specific **Return** whose Return Reason mapped to the seller-fault bucket on import, prompting the seller to verify whether they actually shipped correctly. If yes → file Claim to recover return shipping + deductions. If the seller confirms it was their own fault → close without claiming.
- **`shipping_overcharge`** — the courier billed more shipping than the parcel's true weight warrants, and the **seller bore the excess**. Recover it. Detection is **per-Platform and subsidy-aware**, against each Platform's own *disclosed actual* shipping — never a single uniform formula (ADR 0024): **TikTok** from the billed-weight vs parcel-weight discrepancy in its accounting export; **Shopee** from the income report's per-order net shipping the seller actually bore (buyer-paid + Shopee subsidy + actual charge — a fully-subsidised order nets to zero and is *not* a Claim); **Lazada** from the per-billing-cycle Shipping Fee statement (`lsf < csf`, wrong-weight adjustment). A catalogue-derived expectation (ADR 0022) is the **fallback** when a Platform/Shop exposes no per-order actual. Auto-flag is fail-safe: a missing input yields no flag (ADR 0005). Normally attaches to the **Order** (Lazada's is statement-scoped, or created by hand). The recoverable shown is an **estimate** to decide whether to file; the **actual recovered** amount is read from the Platform's own compensation field on import.

A Claim attaches to one Order (a `return_fee` Claim additionally to the specific Return that triggered it; a Lazada `shipping_overcharge` may instead reference a billing-cycle statement) and tracks three things:

1. **Claim Status** (6 values, two-stage lifecycle). Where a Platform discloses its own dispute state in an export (TikTok `Dispute Status`/`Appeal Status`, Shopee `สถานะการเคลมค่าจัดส่ง`), that state **informs** Claim Status on import; manual transition stays the authority for stages the Platform doesn't cover (e.g. `abandoned`) and for overrides (ADR 0024):
   - `eligible` — auto-flagged on import when return reason ≠ "เปลี่ยนใจ/ไม่ต้องการ"; nothing submitted yet
   - `submitted_initial` — first-round Claim filed with Platform, awaiting decision (e.g., TikTok's initial claim flow — no chance to add evidence at this stage; evidence must be ready beforehand)
   - `submitted_ticket` — initial Claim rejected, seller opened a support ticket as stage 2; Platform may request additional evidence within the ticket
   - `approved` — won at any stage *(terminal)*
   - `rejected` — lost at final stage or seller chose not to escalate to ticket *(terminal)*
   - `abandoned` — manually closed without resolution *(terminal)*

2. **Evidence Checklist**: structured proof items the seller collects. Default items: outgoing packing/shipping video, incoming unboxing video, weight on scale (before/after), photos of received goods. Seller can extend per Claim. Most critical *before* `submitted_initial` since some Platforms (TikTok) don't allow adding evidence post-submission.

3. **Claim Timeline**: append-only log of manual entries (date, action, note, optional ticket #) — submission, decisions, info requests in ticket stage, evidence updates, payout amounts. A won **payout** is read from the Platform's own compensation field on import (TikTok `Compensation Amount`, Shopee `เงินชดเชยให้ผู้ขาย`) rather than hand-typed, where disclosed (ADR 0024).

_Also_: เคลม, เคลมแพลตฟอร์ม
_Avoid_: Refund (Refunds flow Platform→Buyer; Claims flow Platform→Seller), dispute (broader), case (overloaded)

