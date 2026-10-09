---
title: Forms and messages
summary: Contact forms with the plugin "Forms" - forms and messages are content, events do the work.
parent: knowledge-base
order: 10
---
# Forms and messages

The plugin **Forms** (`excellent-plugins/forms`, see [Plugins](plugins.md)) adds forms that editors design with
blocks. It does not have its own storage for submissions or its own mail settings. It uses what the CMS already has:

| Part | What it is in the CMS |
| --- | --- |
| A form | A **record** of the entity `forms`: name, slug, a block field with the fields, text of the button, confirmation, redirect. Published = active, draft = inactive. |
| The fields of a form | **Blocks** (text, e-mail, select, checkbox …) in that block field, next to the block *Columns* and any other block. |
| A form on a page | The block **Form** (field type `forms.form`) in the blocks of a page. |
| A submission | An **event** of the source *Form submissions* (`forms.submission`, action `submit`). |
| A message | Whatever the steps of that event make of it - usually a **record** of an entity like `contact_message`, plus e-mails. |

So forms get everything content has: drafts, revisions, the trash, permissions, the content API. And what happens
with a submission is configured, not programmed.

## What the plugin sets up

When the plugin is activated in a project, it creates (once, editable afterwards):

- the entity **Forms** (`forms`) with the tabs *Form* and *Settings*,
- the form **Contact** (name and e-mail side by side, message, consent, a *Response* block),
- the entity **Contact messages** (`contact_message`: name, e-mail, message),
- the event **Contact form**: on every submission of *Contact* it creates the message, e-mails the
  administrators and thanks the sender.

That event, as JSON (*Administration › Events*):

```json
{
  "name": "Contact form",
  "source": "forms.submission",
  "target": "<id of the form Contact>",
  "actions": ["submit"],
  "mode": "direct",
  "steps": [
    { "type": "create", "entity": "contact_message",
      "data": { "name": "{{record.name}}", "email": "{{record.email}}", "message": "{{record.message}}" },
      "continue_on_error": true },
    { "type": "email", "to": "user:<admin id>", "subject": "New message from {{record.name}}",
      "body": "{{record.name}} <{{record.email}}> wrote:\n\n{{record.message}}", "continue_on_error": true },
    { "type": "email", "to": "{{record.email}}", "subject": "Thank you for your message",
      "body": "Hello {{record.name}},\n\nthank you for your message - we will get back to you soon." }
  ]
}
```

- `{{record.<name>}}` is the value of the form field with that name. Also available: `{{record.form}}` (slug),
  `{{record.form_id}}`, `{{record.submitted_at}}`.
- `"target"` limits the event to one form. Without it, the event reacts to every form of the project.
- `continue_on_error` keeps one failing step (a mail server refusing an address) from stopping the others.
- `user:<id>` sends to a CMS user at the address they have when the mail goes out.

## Example: a newsletter sign-up

A second form with its own entity and event - nothing to install.

1. **Entity** `subscribers` (*Schema › New entity*): `email` (type e-mail, unique), `name`, `confirmed`
   (boolean). Access *OAuth only*, so the list is not public.
2. **Form** *Newsletter* (*Forms › New*): blocks *E-mail* (name `email`, required), *Text* (name `name`),
   *Checkbox* (name `consent`, required), *Response*. Publish it.
3. **Event** *Newsletter sign-up*: reacts to *Form submissions*, form *Newsletter*:

```json
[
  { "type": "create", "entity": "subscribers", "data": { "email": "{{record.email}}", "name": "{{record.name}}", "confirmed": false } },
  { "type": "webhook", "url": "https://mailer.example.com/hooks/subscribe", "auth": { "type": "bearer", "token": "$MAILER_TOKEN" } }
]
```

The `create` step fails for an address that is already on the list (the field is unique). The run is then marked as
failed and shows why, and the webhook is not called twice. `$MAILER_TOKEN` comes from the `.env` (see
[Events](events.md)).

4. **Page:** add the block *Form* to a page and choose *Newsletter*.

## More ideas with the same pieces

- **Events of the message:** `contact_message` is an ordinary entity, so it can have events of its own, for
  example a chat notification (plugin *notifier*) for every new message, or a field `status` (new / answered) and an
  event on `update` with the condition `{"status": "answered", "_changed": ["status"]}`.
- **One form, different teams:** several events on the same form with conditions, e.g.
  `{"topic": "sales"}` mails sales, `{"topic": "support"}` creates a ticket by webhook.
- **Job applications:** an entity `applications` with a reference to `jobs`, and a hidden field `job` in the form
  that the job page fills with the id of the job: `"job": "{{record.job}}"` in the `create` step links them.

## On the website

The block *Form* delivers the form with everything needed to show and send it (fields, button text, `action`,
`token`). There are two ways:

- **HTML from the CMS:** ask for the blocks with `?render=html` (PHP SDK: `->html()`), output the `_html` of the
  block. The plugin's template renders the fields, the spam protection and the sending in the background.
- **Own markup:** render `fields` yourself and post the values plus `_token` to
  `POST /api/v1/<project>/plugins/forms/submit/<form>`. The answer is `200 {id, message, redirect}` or
  `422 {error_data: {field: [messages]}}`.

Details (tokens, the honeypot field `_hp`, CSS classes): the README of the plugin.
