---
title: Users and permissions
summary: Users, roles, permissions per entity and field.
parent: concepts
order: 140
---
# Users and permissions

**Administrators** can do everything: schema, users, roles, API clients and settings. Everyone else works with
**permissions** and **roles**, built on [yiisoft/rbac](https://github.com/yiisoft/rbac) (tables `yii_rbac_*`):

- **Permissions per entity:** `read`, `create`, `update`, `delete`, `import`. Writing implies reading.
  `update_own` and `delete_own` allow only records the user (or API client) created itself. These permissions carry
  the RBAC rule `OwnRecordRule`, which compares `created_by` with the subject. Others' records are refused with 403,
  including bulk deletes and working copies, schedules and locks of them.
- **Roles** (*Administration › Roles*) bundle permissions: per entity, *take over records* (see
  [Record locks](#record-locks)) and *upload / delete media* (API clients). A role can contain other roles, and their
  permissions count too. Circles are refused. The migration creates the role *Redakteur* (`editor`) with *take over
  records*.
- **Users and API clients** get roles *and* permissions of their own. What they may do is both together. In the
  admin app, the permission table shows what comes from roles as ticked and greyed out.
- **Fields limited to roles** (schema, field dialog: *Visible to*, *Editable by*): only these roles (and
  administrators) see or change the field. Everyone else does not get it. It is left out of records, lists, search,
  filters, sort orders, `?fields=`, revisions and the field list of `GET /content`. Changing it is refused with 422,
  but sending it unchanged is allowed (whole records from SDKs). In the content API, a client with the role sees it,
  and requests without a token never do: a limited field is not public. Events and schedules see all fields.

```
GET    /api/v1/admin/roles
POST   /api/v1/admin/roles            {"slug": "products", "name": "Produkt-Team", "roles": ["editor"],
                                       "permissions": {"<entity id>": {"read": true, "update": true}},
                                       "take_over": false, "media_upload": false, "media_delete": false}
PUT    /api/v1/admin/roles/{slug}      only the sent parts change; permissions of other projects stay
DELETE /api/v1/admin/roles/{slug}
```

Users and clients take `"roles": ["products"]`. Users return `roles`, `permissions` (their own) and
`effective_permissions`, and clients return `roles` and `effective`. Fields take `"read_roles"` and `"write_roles"`
(lists of role slugs, empty = like the entity). Logins use a JWT that only carries `sub`/`ver`/`exp`.
Status and permissions are loaded from the database on every request, so changing a password or deactivating a user
ends all of their sessions at once. A login is blocked after 10 failed attempts within 15 minutes.

Users manage their own account under *My account*:

- **Password:** the new password has to be entered twice.
- **E-mail address:** the change needs the current password. It only takes effect when the link sent to the new
  address is opened (valid for 24 hours, works without being logged in). The old address gets a notice.
- **Forgotten password:** *Forgot your password?* on the login page sends a reset link (valid for one hour, works once).
  The answer is the same whether an account exists or not. There are at most 5 mails per address and IP per hour.
  Setting the new password ends all other sessions.

Only SHA-256 hashes of these links are stored, and `./yii cleanup` removes expired ones.

## Record locks

Whoever opens a record to edit it in the admin app locks it, so two people never overwrite each other's work:

- Other users see who is editing it and since when, and can only read it. The list shows a lock next to the record.
  Saving, deleting, working copies and restoring revisions of a locked record are refused with 423 `record_locked`.
- The admin app renews the lock every 30 seconds and releases it when the page is left or the tab is closed. A lock
  nobody renews is free after 2 minutes, for example after a crash. Once it is free, the record opens for editing on
  its own with the saved values.
- **Administrators and roles with *take over records*** (e.g. *Redakteur*) can *take over* a lock. The previous user is told and can only read the record. Their
  unsaved changes stay in the form so they can copy them.
- Locks only apply to users of the admin app. API clients (content API) and imports write as before.

```
POST   /api/v1/entities/{entity}/records/{id}/lock              lock or renew; locked by someone else: their lock, "mine": false
POST   /api/v1/entities/{entity}/records/{id}/lock/take-over    administrators, roles with take_over
DELETE /api/v1/entities/{entity}/records/{id}/lock              release the own lock
```

`GET …/records/{id}` and lists return `_lock` (`{mine, user: {id, name}, locked_at, seen_at}` or `null`).
