# Changelog

All notable changes to `iquesters/payment` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added (emails, with iquesters/notifications)
- **The payer gets an email** when their payment details are received (*under review*), when the payment is confirmed (verified, or recorded at the desk), and when it is not accepted (with the reason and the pay link). The messages are declared by `PaymentNotifications` and sent by the notifications module, whose templates each organisation can reword. Without that module nothing is sent (`suggest`).

### Fixed
- **Each member once in the payment panel**: a bundle's lines (the pass and the membership) both carry the member's details, so their batch showed twice.

## [1.0.0] – 2026-11-27

### Changed (the pay page)
- **Payments list rows are cards** (record component `payment.summary`): the order, a **coloured status badge** (yellow *Waiting for verification*, green *Verified*, red *Rejected*), who paid, amount, UTR, when; a resend marked *2nd attempt*; a rejected one with *Rejected: {reason}*; a warning when the amount differs from the order. Waiting payments stay first. The seeder gives existing Payments tables the card (an admin's own `row_component` is kept).
- **The payment panel lists the order's other payments** (e.g. the one rejected before), with their status, UTR and reason, and **the members' details** given at registration (e.g. *Priya Sen · ISC 2005*), when the event asked for them.
- **No *Done* button** under *Paid* or *payment details received*: it only went back to the page they came from.
- **The same item at the same price shows once, with its count** (*2 × Membership 2027*), on the pay page and in the admin's payment summary (`Order::groupedLines()`). The order record keeps a line per person.


### Added
- **Payment methods per organisation** (`payment_methods`): UPI to a UPI ID (a person's UPI ID is fine until the organisation's bank account is ready) and cash at the desk. Set in **Payment settings** (form schema `payment-settings`), with the organisation's **refund policy** (organisation meta `refund_policy`).
- **The pay page** of a commerce order, `GET /pay/{order uid}` (the buyer, or an admin of the order): the items and total, a **UPI QR with the exact amount and the order reference** (`UpiLink`: `upi://pay?pa=…&pn=…&am=…&cu=INR&tn=TTV-MEET-0042`; drawn in the browser with qrcodejs), "Open in a UPI app" on phones, the instructions, the refund policy, and the payments so far. `?return=/path` adds a Done button back to where the buyer came from (e.g. the event).
- **Proof of payment** (form schema `payment-proof`, `POST /pay/{order uid}`): the UPI transaction ID (UTR) and a screenshot (JPG, PNG, WEBP or PDF; both required by default, see `PaymentConf`). The order moves to *Payment under review*. A UTR already used for another live payment in the organisation is refused; one from a rejected payment may be sent again.
- **Payments** (generic table `payment-table`, waiting ones first): expected vs sent amount, UTR, screenshot, the order's items. **Verify** (the order is paid; commerce fires `OrderPaid`) or **Reject** with a reason the buyer sees (form schema `payment-decision`); the buyer can send the details again.
- **Record a payment** (form schema `payment-mark-paid`): cash or UPI at the desk, by order reference; verified at once. Less than the total needs a note. Any proof still waiting for that order is closed as "Paid at the desk".
- **Who:** super-admins and each organisation's org-admins, for their organisations. Other modules can let more people manage the payments of their orders: `PaymentAccess::allow(fn ($user, Order $order): bool => …)` (e.g. event admins).
- Screenshots are stored on the private `local` disk (`payment/proofs/{payment uid}/…`) and served only to the buyer and the order's admins.
- `payment:seed`: the module, the `manage-payments` permission (role `admin`), the Payments and Payment settings menu items, the form schemas.
