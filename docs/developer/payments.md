# Payments for developers

## Send a buyer to pay

Create the order with commerce's `OrderService`, then redirect to the pay page:

```php
$order = app(OrderService::class)->create($organisation, ['user_id' => $user->id, 'name' => …, 'phone' => …], $items, ['prefix' => 'TTV-MEET', 'source' => 'event_registration']);

return redirect()->route('payment.pay', ['uid' => $order->uid, 'return' => '/events/'.$event->uid]);
```

`return` must be a path on the site (`/…`); other values are ignored. A free order (total 0) is paid at once by commerce and needs no pay page.

React to payment in your module with commerce's events, not with payment's:

```php
Event::listen(OrderPaid::class, fn (OrderPaid $e) => …); // $e->order
```

## Who manages payments

`PaymentAccess::canManage($user, $order)`: super-admins and org-admins of the order's organisation, plus any rule another module registers (in its service provider's `boot`):

```php
PaymentAccess::allow(fn ($user, Order $order): bool => $order->ref_type === 'event' && EventAccess::isEventAdmin($user, $order->ref_id));
```

The Payments table and Payment settings themselves are for org-admins (`ensure.payment.manager`); a rule only opens verifying the payments of the matching orders.

## Service

`PaymentService`: `submitProof(Order, ?userId, utr, ?UploadedFile, ?paidOn)`, `verify(Payment, adminId, ?note)`, `reject(Payment, reason, adminId)`, `markPaid(Order, kind, amount, adminId, ?utr, ?note)`. Each throws `DomainException` with a message for the user when the order or payment can't move.

## Settings (`PaymentConf`, DB conf)

| Key | Default | Meaning |
|---|---|---|
| `require_utr` | `true` | The payer must enter the UTR |
| `require_proof_file` | `true` | The payer must attach a screenshot |
| `proof_max_kb` | `5120` | Largest screenshot (KB) |

## Tables

- `payment_methods`: per organisation and kind (`upi_manual`, `cash`), `settings` JSON (`upi_id`, `payee_name`, `instructions`), `is_active`.
- `payments`: order, kind, amount, UTR, paid on, proof file, status (`submitted`, `verified`, `rejected`), note / reason, who sent and who verified.
