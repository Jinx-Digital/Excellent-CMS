---
title: Drafts and preview
summary: Drafts, working copies, scheduling and the live preview on the website.
parent: concepts
order: 60
---
# Drafts and preview

## Drafts

With **Drafts** switched on in the schema settings (`"drafts": true`), records can be saved as drafts:

- In the admin app, a record is saved as draft or published. Drafts are marked in the list, which can show all
  records, only drafts or only published ones (`filter[draft]=1` / `0`).
- Drafts may leave required fields empty. They are checked when the record is published, including fields that were
  not sent.
- The content API does not know drafts: they are left out of lists and trees, embedded references keep only the id,
  and `GET /content/<entity>/<id>` answers 404. Files used only by drafts are not public. Records created through
  the content API are published. Clients cannot read, change or delete drafts.
- Switching drafts off is refused while there are drafts. A field called `draft` must be renamed first.

**Schedule:** drafts can be published at a set time, and published records can be unpublished (made a draft again)
at a set time:

- *Publish at* publishes the saved draft. For a published record with a working copy, it publishes the *working copy*,
  e.g. a summer offer from June 1st while the current version stays live until then.
- *Unpublish at* has to come after publishing. Lists show what comes next (*live from …*, *live until …*).
- `./yii schedule:run` carries it out every minute by cron (see [Deployment](deployment.md)), in the name of whoever
  set the schedule. Revisions and events (`publish`, `unpublish`) show it like any other change. Locks do not apply.
- If it cannot be done, for example because required fields of the draft are empty, the record stays as it is. The
  schedule shows the error and is not tried again until it is saved again.

```
PUT /api/v1/entities/{entity}/records/{id}/schedule    {"publish_at": "2026-06-01T08:00:00+02:00", "unpublish_at": null}
```

`GET …/records/{id}` and lists return `_schedule` (`{publish_at, unpublish_at, errors}` or `null`). Times are
ISO 8601, and `null` removes one.

**Working copies:** a published record is changed without taking it offline. This works in every entity: without
drafts, every record is published. *Save for later* keeps the changes in a working copy while the published version
stays live. Work on it as often as needed, then *Publish* (*Save* in entities without drafts) makes it live.

- The admin app opens published records with their working copy. A notice shows when the copy was saved and compares
  it with the live version, and the list marks records that have one. Cmd/Ctrl+S saves the working copy.
- Saving checks the values like a draft does: required fields may stay empty. Publishing checks everything, like any
  other save. Only the sent fields change, in the working copy as in the record.
- *Discard working copy* goes back to the published version. *Back to draft* and deleting the record discard it too.
- Working copies are no revisions: only publishing writes one. Their files are kept by the media cleanup. The content
  API, events and the PHP SDK never see them, except in a [preview](#preview).

```
PUT    /api/v1/entities/{entity}/records/{id}/working-copy           save for later (like PUT …/records/{id})
POST   /api/v1/entities/{entity}/records/{id}/working-copy/publish   make the sent values live, remove the copy
DELETE /api/v1/entities/{entity}/records/{id}/working-copy           discard it
```

`GET …/records/{id}` returns it as `_working_copy` (the record as the copy has it, or `null`), lists return
`"_working_copy": true/false`. Drafts have no working copy (409): they are not live and are saved directly.

## Preview

The website runs elsewhere, so it renders the preview itself, with its real design. Each entity can have a **preview
address** (*Schema › Settings*), the page of the website that shows a record:

```
https://example.com/api/preview?entity={{entity}}&id={{id}}&slug={{record.slug}}&lang={{lang}}&token={{token}}&secret=$PREVIEW_SECRET
```

- `{{token}}` is required, along with `{{id}}`, `{{entity}}`, `{{lang}}`, and the fields as `{{record.<field>}}`. Values are
  URL-encoded. `$NAME` inserts a `.env` variable, e.g. the secret of a draft-mode route of Next.js or Nuxt.
- *Preview* in the record form opens the address **next to the form**, with desktop, tablet and phone widths, or in
  a new tab. It reloads after every save. Unsaved changes are sent to the page as you type
  (`postMessage({type: 'excellent:preview', entity, id, language, record})`) for frontends that render them live.
- The token (`POST /api/v1/entities/{entity}/records/{id}/preview`, for users who may read the entity) is signed,
  works only for its project and expires after `PREVIEW_TTL` seconds (1 hour). With it, the content API
  (`?preview=<token>` or the header `X-Preview-Token`) delivers **drafts and working copies** instead of the live
  state, with `Cache-Control: no-store`. Access rules stay the same. An invalid or expired token answers 401.
- **Live editing:** if the website uses `LiveEdit` of the PHP SDK, editors click a block in the preview, nested ones
  too. Its fields open in a panel next to it, and every change shows on the page at once, before anything is saved.
  A **+** at the top or bottom edge of a block adds one there. The panel offers the block types allowed at that place.
  *Remove* in the toolbar of the selected block deletes it, with *undo*. Saving works as always.
  The page marks its blocks (`data-excellent-block`) and sends the click with `postMessage`
  (`{type: 'excellent:select', field, key}`). The CMS answers with the unsaved values and `excellent:highlight`.
- References included with `include=` show their published state.
- PHP SDK: `$cms->preview($_GET['token'])->entity('pages')->get($id)`.
