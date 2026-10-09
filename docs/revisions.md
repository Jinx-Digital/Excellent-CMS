---
title: Revisions
summary: Every change kept - compare and restore.
parent: concepts
order: 70
---
# Revisions

With **Revisions** switched on in the schema settings (`"revisions": true`, off by default), every change of a record
is kept: one table for all entities (`revision`) with a JSON snapshot of the stored row. Fields are kept by their id,
so renamed fields still match.

- The record page lists the versions (when, who, which fields changed) and compares one with the current state
  field by field. It restores all fields or the chosen ones - through the usual checks (unique values, references,
  required fields). The draft state stays as it is.
- Changes in the admin app, through the content API, updates by import, trash and restore create versions. New
  records from an import and drag & drop do not (the order is no content).
- Records from before revisions were switched on get their former state as first version when they change.
- Fields deleted since then are shown as removed. Files of older versions are kept by the cleanup, so restoring
  brings them back.
- `REVISION_LIMIT` (default 50) versions are kept per record, `0` switches revisions off everywhere. Deleting a
  record for good removes its versions. Switching revisions off keeps the versions there are.

```
GET  /api/v1/entities/{entity}/records/{id}/revisions                      read
GET  /api/v1/entities/{entity}/records/{id}/revisions/{revision}           read: the record as it was + removed fields
POST /api/v1/entities/{entity}/records/{id}/revisions/{revision}/restore   update: {"fields": ["title"]} or all
```
