---
title: Editorial review
summary: Authors write drafts, a reviewer approves them - with a status field limited to a role, events, e-mails and scheduled publishing.
parent: knowledge-base
order: 50
---
# Editorial review

Authors write articles, but only the editor-in-chief decides what goes live. The CMS has no fixed workflow engine.
A status field that only one role may change, and a few events, are enough.

## 1. Roles

*Administration › Roles*:

- **Authors** (`authors`): `articles`: read, create, `update_own`. They change only their own articles.
- **Review** (`review`): `articles`: read, update. Contains the role *Authors*.

## 2. The entity

**Articles** (`articles`), with drafts:

| Field | Type | |
| --- | --- | --- |
| `title`, `slug`, `body` … | | the content |
| `status` | selection: `writing` (In progress), `submitted` (Submitted), `approved` (Approved), `rejected` (Changes needed) | editable by **Review** only, except `submitted` (see below) |
| `review_note` | text | editable by **Review** only |

In the field dialog, *Editable by* limits `status` and `review_note` to the role *Review*. Other users see the
fields but cannot change them (422).

Authors still need a way to say "done". Give them a second field, `submit` (boolean, *Ready for review*), and let an
event turn it into the status.

## 3. The events

**Submitted** - an author ticks *Ready for review*:

```json
{
  "name": "Submitted for review",
  "entity": "articles",
  "actions": ["update"],
  "condition": { "submit": true, "_changed": ["submit"] },
  "steps": [
    { "type": "update", "entity": "articles", "where": { "id": "{{record.id}}" }, "data": { "status": "submitted", "submit": false } },
    { "type": "email", "to": "user:<id of the editor-in-chief>", "subject": "To review: {{record.title}}", "body": "{{project.url}}" }
  ]
}
```

Events write every field, whatever the roles say, so the status changes although the author may not change it.

**Approved** - the reviewer sets the status to *Approved*: publish it and tell the author.

```json
{
  "name": "Approved",
  "entity": "articles",
  "actions": ["update"],
  "condition": { "status": "approved", "_changed": ["status"] },
  "steps": [
    { "type": "update", "entity": "articles", "where": { "id": "{{record.id}}" }, "data": { "draft": false } },
    { "type": "email", "to": "{{record.created_by}}", "subject": "Published: {{record.title}}", "body": "Thank you!" }
  ]
}
```

- `"draft": false` publishes the article, which starts the events of `publish` (for example a cache flush or a
  [shop sync](shop-sync.md)).
- `{{record.created_by}}` is `user:<id>` of the author, and the e-mail goes to their address at the time of sending.

**Changes needed** - the same with `{"status": "rejected", "_changed": ["status"]}` and an e-mail with
`{{record.review_note}}`.

**A limit to know:** there is no separate permission to publish. Whoever may update a record can also save it as
published. If authors must never publish themselves, add a guard: an event on `publish` with the condition
`{"status": {"ne": "approved"}}` and the step `update` with `{"draft": true}` turns it back into a draft at once. The
`publish` and `unpublish` events of other events still run in between, so limit those to approved articles too
(`"status": "approved"` in their condition).

## 4. Publishing later

Instead of publishing at once, the reviewer sets *Publish at* in the record (scheduling). `./yii schedule:run`
publishes it at that time, and the `publish` event runs then. Leave out the first step of *Approved* in that case.

## What else helps

- **Revisions** show who changed what. Changes of events appear as *Event: Approved*.
- **Locks** keep the author and the reviewer from overwriting each other. Roles with *take over records* can take
  over a lock.
- **Working copies:** a published article is changed with *Save for later* and stays live until the changes are
  published.
- **Only for some authors:** *Only for entries by* in the event limits it to chosen users, e.g. new authors whose
  articles always need a review.
