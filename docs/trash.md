---
title: Deleting and the trash
summary: What deleting does and how the trash works.
parent: concepts
order: 90
---
# Deleting and the trash

In the list view, several records can be selected and deleted at once, after a confirmation. Records that are still
referenced are skipped and reported, and all others are deleted.

The **trash** can be switched on per entity (schema settings or `"trash": true` when creating an entity). With the
trash on, deleted records get a `deleted_at` timestamp instead of being removed:

- They are hidden everywhere: in lists, in the content API and as reference targets.
- Their unique values stay taken until they are deleted for good, because the database index still counts them.
- Users with the delete permission can open the trash in the list view to restore records, delete them permanently
  or empty the whole trash.
- The trash can only be switched off while it is empty.

```
# admin app, with the header X-Project
POST /api/v1/entities/{entity}/records/delete    {"ids": [...], "permanent": false}  -> {trashed, deleted, failed}
POST /api/v1/entities/{entity}/records/restore   {"ids": [...]}
POST /api/v1/entities/{entity}/trash/empty
GET  /api/v1/entities/{entity}/records?trash=1
```
