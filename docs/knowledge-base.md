---
title: Knowledge base
summary: Worked examples - how entities, events and plugins fit together for real tasks.
order: 60
---
# Knowledge base

The other pages describe each part of the CMS on its own. The knowledge base puts them together: each article
solves one task from start to finish, with the schema, the events and what the website does.

| Article | What it shows |
| --- | --- |
| [Forms and messages](forms-and-messages.md) | Contact forms with the plugin "Forms": forms and messages are ordinary content, an event turns a submission into a record and e-mails. |
| [Blog with author profiles](blog-authors.md) | Authors as an entity of their own, connected to CMS users with the plugin "User field" - an event links every new post to the profile of whoever wrote it. |
| [Multilingual pages](multilingual-pages.md) | A page tree in several languages: slug paths per language, routing and language switcher on the website, the preview in the right language. |
| [Syncing a shop by webhook](shop-sync.md) | Every change of a product reaches the shop: signed webhooks, secrets from the `.env`, the queue, retries. |
| [Editorial review](editorial-workflow.md) | Authors write drafts, a reviewer approves them: a status field limited to a role, events that publish and send e-mails. |
| [Shared data for several websites](global-data.md) | Countries, currencies or locations kept once in the area "Global" and used by every project. |
| [Reusable event steps](reusable-events.md) | Steps several events share, kept in one event that the others start with values passed along. |

**The common thread:** the CMS has few special cases. A form is a record, a message is a record, an author is a
record. Events connect them, and the website reads all of it through the same [Content API](content-api.md).
