---
title: Reusable event steps
summary: Steps that several events share, kept in one event that the others start - with values passed along.
parent: knowledge-base
order: 70
---
# Reusable event steps

Three events end with the same steps: new orders, new contact messages and new job applications all post to the
team chat and e-mail the office. Copied three times, every change has to be made three times. Instead, the shared
steps go into one event that the others **start** with the step `event` (see [Events](events.md)).

## 1. The building block

*Administration › Events › New*, source **Started by other events**. It has no trigger of its own. It runs only when
another event starts it.

```json
{
  "name": "Notify the office",
  "source": "event",
  "steps": [
    { "type": "email", "to": "office@example.com", "subject": "{{record.kind}}: {{record.subject}}", "body": "{{record.link}}", "continue_on_error": true },
    { "type": "webhook", "url": "https://chat.example.com/hooks/$CHAT_HOOK", "digest": false }
  ]
}
```

It uses `{{record.kind}}`, `{{record.subject}}` and `{{record.link}}`: the values the starting events pass on. That
is its interface - a name like *Notify the office (kind, subject, link)* tells others what to send.

## 2. Starting it

In each event, the step **Start event** with the values the building block expects:

```json
{
  "name": "New order",
  "entity": "orders",
  "actions": ["create"],
  "steps": [
    { "type": "event", "event": "<id of Notify the office>", "data": {
      "kind": "Order",
      "subject": "{{record.number}} from {{record.customer}}",
      "link": "{{project.url}}/orders/{{record.id}}"
    } }
  ]
}
```

The contact form passes `"kind": "Message", "subject": "{{record.name}}"`, the applications
`"kind": "Application", "subject": "{{record.job}}"`.

- The started event gets the records of the run, each with the fields of the record plus the values of `data`.
  Without `data` it gets the records as they are.
- Its own condition is checked on that, e.g. `{"kind": {"ne": "Application"}}` to leave applications out of the
  chat.
- It runs as a run of its own (*Runs* of the building block), in its own mode: a building block in queue mode sends
  in the worker even if the starting event runs directly.

## 3. Testing

- *Test run* of the starting event shows which event the step starts and the values it would pass.
- *Test run* of the building block takes the values as JSON, e.g.
  `{"kind": "Order", "subject": "1001 from Jane", "link": "https://example.com"}`.

## Limits

- Only events with the source *Started by other events* can be started, and they must be switched on.
- Chains are at most 3 levels deep. A building block that starts another one counts as a level.
- No loops: an event that already runs in the chain is not started again. The step fails with that message, so the
  run shows the problem instead of running forever.
- An event cannot start itself, which is refused when it is saved.
