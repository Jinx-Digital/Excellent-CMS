---
title: Events
summary: React to changes: webhooks, e-mails, records - directly or queued.
parent: concepts
order: 120
---
# Events

Events react to changes (*Administration › Events*, admins only): what they listen to (`source`), the actions, an
optional condition and the steps.

| `source` | Actions | The record of the event |
| --- | --- | --- |
| `entity` (with `entity`) | `create`, `update`, `delete`, `publish`, `unpublish`, `restore` | the record of the entity |
| `media` | `create` (upload), `update` (renamed, kept or not), `delete` | the file as the API presents it (`id`, `name`, `url`, `mime_type` …) |
| `variables` | `create`, `update`, `delete` | the variable (`name`, `value`, `translations`) |

For media and variables the condition is checked on the data itself (`eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `in`,
`like`, `null`, plus `_changed` and `_old`), e.g. `{"name": "url", "_changed": ["value"]}`. The cleanup of unused
files (`./yii cleanup`) starts no events.

```json
{
  "name": "Variant for a new product",
  "entity": "products",
  "actions": ["create", "publish"],
  "condition": { "draft": false, "price": { "gte": 10 } },
  "mode": "direct",
  "steps": [
    { "type": "create", "entity": "variants", "data": { "product": "{{record.id}}", "title": "{{record.title}} – Standard" } },
    { "type": "webhook", "url": "https://shop.example.com/hook", "secret": "…" },
    { "type": "email", "to": "team@example.com", "subject": "New: {{record.title}}", "body": "{{project.url}}", "digest": true }
  ]
}
```

- **Condition:** the filter language of the content API, checked in the database against the record - every
  operator and filters on references work. `_changed: ["price"]` matches when one of the fields changed,
  `_old: {"price": {"lt": 10}}` checks the state before. For `delete` the condition is checked before the record is
  gone. Empty = always.
- **Steps**, in their order and as many as you like:
  - `webhook` `{url, secret?, digest?, auth?}`: one POST with all records of the run (`digest`, the default) or one
    per record. The payload has the project, the event, the entity (slug, name, fields) and the records with their
    data and their state before. It is signed with `X-Excellent-Signature: sha256=<HMAC>` when a secret is set, and
    the URL may use placeholders. `auth` protects the receiver: `{"type": "bearer", "token": "…"}` sends
    `Authorization: Bearer …`, and `{"type": "basic", "username": "…", "password": "…"}` is basic auth, for example
    an `.htaccess` login.
  - `email` `{to, subject, body, digest?}`: one mail per record, or one for all with `digest`.
- **Secrets from the .env:** the secret, the token and the basic auth fields take a value or a reference
  `$SHOP_TOKEN` to a variable of the `.env`. The URL takes `$SHOP_TOKEN` anywhere in it, for example
  `https://shop.example.com/hook?token=$SHOP_TOKEN`. The name ends at the first character that is no letter, digit
  or `_`. The value is URL-encoded and resolved after the record placeholders. It is resolved only when the step runs, so the value is never
  stored. `$$abc` is the value `$abc`. Unknown or empty variables are refused when saving. Every variable can be
  used, so treat event permissions like access to the `.env`. Events are for administrators only. In the admin app,
  typing `$` suggests the variables (`GET /api/v1/admin/env-vars` returns their names and whether they are set, never
  their values). The service behind it (`EnvVariables`) is meant for every setting that needs secrets.
  - `create` `{entity, data}`, `update` `{entity, where, data}`, `delete` `{entity, where}`: per record; `where`
    is a filter.
- **Placeholders:** `{{record.title}}`, `{{record.image.url}}`, `{{old.price}}`, `{{event.name}}`,
  `{{event.action}}`, `{{project.url}}` or `{{url}}` (project variables), `{{count}}`. A value that is only a placeholder keeps
  its type (number, list, object).
- **One run per request:** everything an event collects in one request is one run. An import of 100 rows is one
  webhook call with 100 records.
- **Direct or queue:** direct runs start after the answer went out. Queue runs wait for the worker
  (`./yii queue:run events`, see [Deployment](deployment.md)).
- **Progress:** every run shows its status and the progress of each step (e.g. *create 37/100*). The first failing
  step stops the run - unless it has **Continue if this step fails** (`"continue_on_error": true`): then the next
  steps run anyway and the run counts as failed. *Retry* starts it again and skips the steps that are done. The newest 100 runs per event are
  kept.
- **Chains:** steps that write records start the events of those entities, one level deeper, at most 3 levels, and an
  event never starts itself again in its own chain. The admin app shows them as workflow.
- Changes made by steps are saved as `event:<id>` (shown as *Event: name*), entity permissions do not apply.
  Failed requests start nothing.
- **Editing:** the admin app edits the steps as form (a card per step: *+ Webhook*, *+ E-mail* …, the fields of the
  target entity to choose) or as JSON - both are the same list, stored as JSON. Keys the form does not know stay.
- **Test run:** for one record, whether the condition matches and what the steps would do. Nothing is sent or saved.

```
GET/POST        /api/v1/admin/events             PUT/DELETE /api/v1/admin/events/{id}
GET             /api/v1/admin/events/{id}/runs   POST /api/v1/admin/events/{id}/test {"record": "<id>"}
POST            /api/v1/admin/event-runs/{id}/retry
```
