---
title: Syncing a shop by webhook
summary: Products maintained in the CMS, sent to a shop or another system - signed, with secrets from the .env, retried when it fails.
parent: knowledge-base
order: 40
---
# Syncing a shop by webhook

Product texts and images are maintained in the CMS, and the shop (or an ERP, a search index, a cache) has to know
about every change. An event with a `webhook` step does that. Nothing has to poll the CMS.

## 1. The secrets

In the `.env` of the CMS:

```
SHOP_HOOK_SECRET=a-long-random-value
SHOP_TOKEN=token-the-shop-expects
```

Events store only the reference (`$SHOP_TOKEN`), never the value, and resolve it only when the step runs. Unknown or
empty variables are refused when the event is saved.

## 2. The event

*Administration › Events › New*: reacts to *Products*, actions *publish*, *update*, *unpublish*, *delete*.

```json
{
  "name": "Sync with shop",
  "entity": "products",
  "actions": ["publish", "update", "unpublish", "delete"],
  "condition": { "draft": false },
  "mode": "queue",
  "steps": [
    {
      "type": "webhook",
      "url": "https://shop.example.com/cms-hook",
      "secret": "$SHOP_HOOK_SECRET",
      "auth": { "type": "bearer", "token": "$SHOP_TOKEN" },
      "digest": true
    }
  ]
}
```

- **`"draft": false`** keeps drafts out: the shop hears about a product when it is published, not while it is
  written. `unpublish` and `delete` report that it has to go.
- **`"digest": true`** (the default) sends one call per run. An import of 500 products is one request with 500
  records, not 500 requests.
- **`"mode": "queue"`** lets the worker send it (`./yii queue:run events`, see [Deployment](deployment.md)). Editors do
  not wait for the shop, and a slow shop does not slow down the CMS.
- Only changes that matter: `"condition": {"draft": false, "_changed": ["price", "title", "image"]}` leaves out
  updates of other fields. `_changed` matches when one of the fields changed.

## 3. What the shop gets

```json
{
  "project": "shop",
  "event": { "id": "…", "name": "Sync with shop" },
  "source": "entity",
  "entity": { "slug": "products", "name": "Products", "fields": [{ "name": "title", "label": "Title", "type": "string" }] },
  "sent_at": "2026-10-09T14:00:00Z",
  "count": 1,
  "records": [
    { "id": "…", "entity": "products", "action": "update", "data": { "title": "Shoe", "price": 89.9 }, "old": { "title": "Shoe", "price": 99.9 } }
  ]
}
```

`old` is the state before an update, so the shop can tell a price change from a new text.

## 4. Checking the signature

With a secret, every call carries `X-Excellent-Signature: sha256=<HMAC of the body>`. The shop checks it before
trusting the body:

```php
$body = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $body, getenv('SHOP_HOOK_SECRET'));
if (!hash_equals($expected, $_SERVER['HTTP_X_EXCELLENT_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
$payload = json_decode($body, true);
foreach ($payload['records'] as $record) {
    match ($record['action']) {
        'delete', 'unpublish' => $shop->remove($record['id']),
        default => $shop->save($record['id'], $record['data']),
    };
}
```

Answer with a 2xx status. Anything else counts as failed.

## 5. When the shop is down

- The run is marked as failed, with the answer of the shop at the step (*Administration › Events › Runs*).
- *Retry* sends it again with the same records and skips steps that are done.
- More steps after the webhook (e.g. an e-mail to the team) run anyway with **Continue if this step fails**
  (`"continue_on_error": true`) on the webhook. The run still counts as failed, so it is not forgotten.
- The newest 100 runs per event are kept.

## Variations

- **Several systems:** one webhook step per system in the same event, each with its own secret. With
  `continue_on_error` one system being down does not hold up the others.
- **Basic auth** (e.g. an `.htaccess` login): `"auth": {"type": "basic", "username": "cms", "password": "$SHOP_PASSWORD"}`.
- **Flushing a cache:** the same event with the URL of the cache endpoint of the website, e.g. after every change of
  `pages`.
- **Shared by several entities:** see [Reusable event steps](reusable-events.md).
