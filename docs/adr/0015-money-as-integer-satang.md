# Money is stored as integer satang (THB minor units), never float

Every monetary amount in the system — List Price, Deal Price, Effective Price, Cost Price, fee amounts, Payment Line amounts, `over_short`, refund amounts, expected/actual net — is stored and computed as an **integer count of satang** (1 THB = 100 satang), cast to a value object/integer in the model. Floating-point types (`float`, `double`, `REAL`) are **never** used for money anywhere — not in the DB, not in PHP, not in transit. The system is **THB-only**, so a single fixed minor unit (2 decimal places) covers every amount.

## Why

- **Float silently corrupts money.** Binary floating point cannot represent most decimal fractions exactly (`0.1 + 0.2 ≠ 0.3`), and the error accumulates across additions. For a system whose core value is an immutable ledger and reconciliation that must balance to the satang (`Σ line items == total`, Actual Net vs Expected Net within a ฿1 Mismatch Threshold), any drift is a defect, not a rounding nicety. This is the single non-negotiable: **money is never float.**
- **Integer minor units is a payment-industry standard.** Stripe, PayPal, and most payment APIs represent money as an integer count of the currency's smallest unit. Integer arithmetic (+, −, ×) is always exact, so ledger sums and reconciliation never drift from the representation itself.
- **THB-only makes it the simplest correct choice.** With one currency at a fixed 2-decimal precision, there is no multi-currency / variable-precision problem that would favour a richer decimal type. Satang fits a plain integer column exactly, casts trivially in Laravel (no external decimal/`brick/money` dependency), and keeps every in-code calculation exact.
- **It serves the ledger and accounting directly.** Stock Movements, Accounting Entry line items, and Payment Lines all reconcile by summation; integer satang guarantees those sums are exact with no intermediate-rounding drift.

## Considered options

- **`float` / `double` for money.** Rejected outright — accumulates representation error; defeats ledger and reconciliation correctness. The one hard rule.
- **Arbitrary-precision decimal** (`DECIMAL(12,2)` + a value object such as `brick/money`). Also a correct, standard choice that avoids float. Rejected for *this* product not because it is wrong but because, given **THB-only at fixed 2-decimal precision**, it adds a dependency and a richer type for no benefit over integer satang. (If multi-currency or sub-satang precision were ever needed, revisiting this as a superseding ADR — moving to a decimal money type — is the migration path.)
- **Integer satang (chosen).** Exact arithmetic, no dependency, trivial cast, matches the payment-API standard and the ledger's summation model.

## Consequences

- DB columns for money are integer (satang); models cast to an integer/value object. Pint/PHPStan and code review should reject any `float`-typed money path.
- **Rounding must be defined explicitly at every division/percentage point** — Manual Discount `%`, fee rates, Margin Calculator (`effective_price = required_net / (1 − total_fee_rate)`), tax. Integer satang does not remove the need for a rounding policy (which decimal would also require); it forces it to be explicit. Convention: round to whole satang at the defined boundary (line vs cart/total) per the calculation's spec, never carry a fractional satang. Each such site states its rounding direction and boundary.
- Amounts entered/displayed in baht are converted at the UI/import boundary (`baht ↔ satang`), so the integer representation never leaks into user-facing values.
- This is the representation behind the `CONVENTIONS.md` rule "Money = integer สตางค์ ทุกที่ ห้าม float" — now with its reasoning recorded rather than asserted.
