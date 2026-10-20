---
title: Blog with author profiles
summary: Authors as an entity, connected to CMS users with the plugin "User field", linked to new posts by an event.
parent: knowledge-base
order: 20
---
# Blog with author profiles

A blog wants more about its authors than the CMS knows about its users: a photo, a short bio, a page per author.
CMS users are accounts (e-mail, password, roles) and are never delivered by the content API. So the profiles
are content of their own, and the plugin **User field** (`excellent-plugins/user-field`) connects each profile to
its account. An event then fills in the author of every new post automatically.

```
users (accounts)  ◄── user ──  authors (profiles)  ◄── author ──  posts
```

## 1. The plugin

Install and activate **User field** in the project (*Administration › Plugins*). It adds the field type *User*
(`user-field.user`). It stores the id of a user and is delivered as `{"id": "…", "name": "Jane Doe"}`, never with
the e-mail address.

## 2. The entities

**Authors** (`authors`), public:

| Field | Type | |
| --- | --- | --- |
| `name` | string, required | display field |
| `slug` | slug from `name` | for `/authors/jane-doe` |
| `user` | User (`user-field.user`) | the account this profile belongs to - one profile per account |
| `photo` | media, images | |
| `bio` | text | |

**Posts** (`posts`), public, with drafts:

| Field | Type | |
| --- | --- | --- |
| `title` | string, required | |
| `slug` | slug from `title` | |
| `author` | reference to `authors` | filled in by the event, editors may change it |
| `body` | blocks or Markdown | |

Create a profile for each person who writes and choose their account in `user`. A profile without an account
works too: for guest authors, editors choose the profile by hand.

## 3. The event

*Administration › Events › New*: reacts to *Posts*, action *create*.

```json
{
  "name": "Author of new posts",
  "entity": "posts",
  "actions": ["create"],
  "condition": { "author": { "null": true } },
  "steps": [
    {
      "type": "find",
      "entity": "authors",
      "where": { "user": "{{record.created_by}}" },
      "as": "author"
    },
    {
      "type": "update",
      "entity": "posts",
      "where": { "id": "{{record.id}}" },
      "data": { "author": "{{author.id}}" }
    }
  ]
}
```

- `{{record.created_by}}` is the creator of the post: `user:<id>` for a CMS user, `client:<id>` for an API client.
- **Find records** (`find`) looks for the profile whose field `user` is that user (the first one, if two profiles
  point to the same account). The user field understands `user:<id>`, so the value can be passed on as it is.
- The steps after it get the profile as `{{author.…}}`. `update` writes its id into the post, and further steps
  can use the rest, e.g. an e-mail with `{{author.name}}`. No profile (or a post created by an API client):
  `author` stays empty.
- The condition `{"author": {"null": true}}` leaves posts alone when the editor already chose an author.
- *Test run* with an existing post shows which profile the step finds before anything is saved.
- Only the id is needed? `"author": {"_lookup": {"entity": "authors", "where": {"user": "{{record.created_by}}"}}}`
  in the `update` step does the same without a step of its own.

The change is saved as *Event: Author of new posts* in the revisions of the post, and editors can still pick a
different author afterwards.

## 4. On the website

The post with its author in one request:

```
GET /api/v1/blog/content/posts?filter[slug]=hello-world&include=author
```

```json
{
  "id": "…", "title": "Hello world", "slug": "hello-world",
  "author": { "id": "…", "name": "Jane Doe", "slug": "jane-doe", "photo": { "url": "…" }, "bio": "…",
              "user": { "id": "…", "name": "Jane Doe" } }
}
```

The author page with their posts:

```
GET /api/v1/blog/content/authors?filter[slug]=jane-doe
GET /api/v1/blog/content/posts?filter[author][slug]=jane-doe&sort=-created_at
```

With the [PHP SDK](php-sdk.md):

```php
$post = $cms->entity('posts')->where('slug', 'hello-world')->with('author')->first();
$posts = $cms->entity('posts')->where('author.slug', 'jane-doe')->orderByDesc('created_at')->get();
```

## Variations

- **Several authors:** make `author` repeatable, find all profiles of a team with `"all": true`
  (`{"type": "find", "entity": "authors", "where": {"team": "{{record.team}}"}, "as": "team", "all": true}`) and
  pass their ids on, e.g. with the plugin *Script*: `set ids = team.map(it.id)`, then `"author": "{{ids}}"`.
- **"Last edited by":** a second reference `editor` and an event on `update` with `{{record.updated_by}}`.
- **Notify the author:** an `email` step with `"to": "{{record.created_by}}"` mails the creator of a record,
  for example when a reviewer publishes it (action `publish`).
- **Only for some users:** the field *Only for entries by* in the event (stored as `created_by` in the condition)
  limits the event to chosen accounts.
