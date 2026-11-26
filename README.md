# iquesters/payment

Payments of `iquesters/commerce` orders for organisations: people pay by **UPI** (a QR per order with the exact amount and the order reference), send the transaction ID and a screenshot, and the organisation's admins verify them against the bank statement. Admins also record **cash** or UPI paid at the desk. When a payment is verified the order is paid, and other modules react to commerce's `OrderPaid` (e.g. an event registration is confirmed, a membership starts).

No payment gateway: no fees, no gateway account. Gateways can be added later as another payment method.

## Install

```bash
composer require iquesters/payment
php artisan migrate
php artisan payment:seed
```

Requires `iquesters/foundation`, `iquesters/user-interface`, `iquesters/organisation` (2.1+) and `iquesters/commerce` (2.x).

## Docs

- [Taking payments](docs/user/payments.md) (admins and payers)
- [For developers](docs/developer/payments.md) (sending a buyer to pay, access rules, settings)
- [CHANGELOG](CHANGELOG.md)
