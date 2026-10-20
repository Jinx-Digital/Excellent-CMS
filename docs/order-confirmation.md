---
title: Order confirmation with totals
summary: A script computes subtotal, shipping, VAT and the delivery date of a new order - the e-mail and the record get the results.
parent: knowledge-base
order: 80
---
# Order confirmation with totals

A shop sends orders to the CMS (content API or [PHP SDK](php-sdk.md)): the customer and the line items. The
confirmation needs more than the order holds - the subtotal, shipping, the VAT in it, the total and when it arrives.
The step **Modify values** of the plugin **Script** (`excellent-plugins/script`) works it out, the next steps use it.

```
order created  →  Modify values (script)  →  update: total, delivery date  →  e-mail to the customer
```

## 1. The plugin

Install and activate **Script** in the project (*Administration › Plugins*). It adds the event step *Modify values*.

## 2. The entity

A field group **Line item** (`order_item`, *Administration › Field groups*): `product` (string), `quantity`
(integer), `price` (decimal, the gross price of one piece).

**Orders** (`orders`), access *OAuth only*:

| Field | Type | |
| --- | --- | --- |
| `number` | counter (auto increment) | display field |
| `customer` | string, required | "jane doe" |
| `email` | e-mail, required | |
| `country` | string | "DE", "AT" … |
| `items` | group *Line item*, **repeatable** | the line items |
| `total` | decimal | filled in by the event |
| `delivery_on` | date | filled in by the event |

## 3. The script

*Administration › Events › New*: reacts to *Orders*, action *create*. First step **Modify values**:

```
// Line items: one line per product, the sum of all of them
var subtotal = 0
var lines = []
each item in record.items
	var amount = item.price * item.quantity
	set subtotal = subtotal + amount
	set lines = lines.append(item.quantity + " × " + item.product + ": " + amount.formatNumber(2, ",", ".") + " €")
end

// Free shipping from 100 €, abroad costs more
if subtotal >= 100
	set shipping = 0
else if record.country != "DE"
	set shipping = 9.9
else
	set shipping = 4.9
end
set total = (subtotal + shipping).round(2)
// Prices include 19 % VAT
var vat = total - total / 1.19

// Delivered in 3 working days - Saturday and Sunday do not count
var delivery = record.created_at.date()
var workdays = 0
each day in [1, 2, 3, 4, 5, 6, 7]
	if workdays < 3
		set delivery = delivery.addDays(1)
		if delivery.weekday() <= 5
			set workdays = workdays + 1
		end
	end
end

// Texts for the e-mail
set greeting = "Hello " + record.customer.split().first().capitalize()
set items_text = lines.join("\n")
set money.subtotal = subtotal.formatNumber(2, ",", ".") + " €"
set money.shipping = shipping.formatNumber(2, ",", ".") + " €"
if shipping == 0
	set money.shipping = "free"
end
set money.vat = vat.formatNumber(2, ",", ".") + " €"
set money.total = total.formatNumber(2, ",", ".") + " €"
set delivery_on = delivery                        // for the record: 2026-10-28
set delivery_date = delivery.formatDate("d.m.Y")  // for the e-mail: 28.10.2026
```

- **`var` and `set`:** `subtotal`, `lines`, `vat`, `delivery` and `workdays` are declared with `var` - they are only
  for the calculation. A name declared with `var` stays in the script even when `set` changes it later. Everything
  else set with `set` reaches the next steps: `{{greeting}}`, `{{money.total}}`, `{{delivery_date}}` …
- **`each`** goes through the line items (`item` exists only in the loop), **`if … else if … else`** chooses the
  shipping.
- **Working days:** `addDays(1)` until three days from Monday to Friday (`weekday()` 1 - 5) are counted. Ordered on
  a Friday, it arrives on Wednesday.
- **Numbers as text:** `formatNumber(2, ",", ".")` writes `1.234,50`. Keep the numbers (`total`) for the record and
  the texts (`money.total`) for the e-mail.

## 4. The next steps

```json
[
  { "type": "update", "entity": "orders", "where": { "id": "{{record.id}}" },
    "data": { "total": "{{total}}", "delivery_on": "{{delivery_on}}" } },
  { "type": "email", "to": "{{record.email}}", "subject": "Your order {{record.number}}",
    "body": "{{greeting}},\n\nthank you for your order:\n\n{{items_text}}\n\nSubtotal: {{money.subtotal}}\nShipping: {{money.shipping}}\nTotal: {{money.total}} (incl. {{money.vat}} VAT)\n\nIt arrives on {{delivery_date}}." }
]
```

For an order of Jane (2 × coffee beans at 14,90 €, 1 mug at 9,50 €, Germany, on Friday, 23 October 2026) the
e-mail reads:

```
Hello Jane,

thank you for your order:

2 × Coffee beans: 29,80 €
1 × Mug: 9,50 €

Subtotal: 39,30 €
Shipping: 4,90 €
Total: 44,20 € (incl. 7,06 € VAT)

It arrives on 28.10.2026.
```

`{{total}}` is only a placeholder, so it keeps its type: the record gets the number `44.2`, not a text.

## 5. Trying it

- *Test run* of the event with an existing order shows what the script passes on, then the `update` and the
  e-mail with the values filled in. Nothing is saved or sent.
- A mistake in the script (a missing `end`, an unknown method) is refused when the event is saved, with its line.
- Many orders at once (an import): set the event to *Queue*, so the worker sends the e-mails.

## Variations

- **Discount codes:** a step [Find records](events.md) before the script (`{"entity": "coupons", "where": {"code":
  "{{record.coupon}}"}, "as": "coupon"}`), then in the script `if coupon is not null` …
  `set subtotal = subtotal * (100 - coupon.percent) / 100`.
- **Express shipping:** `if record.express` adds a surcharge and counts one working day instead of three.
- **Holidays:** a list of dates (a [variable](variables.md) or an entity) and `if not holidays.contains(delivery)`
  next to the weekday check.
- **Your own methods:** a shop plugin can add `record.items.vat()` or `record.sku.stock()` to the language - see the
  README of the plugin *Script*.
