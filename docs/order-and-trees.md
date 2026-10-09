---
title: Order and trees
summary: Sorting by drag and drop and records as a tree.
parent: concepts
order: 80
---
# Order and trees

## Order

An entity with a field of type **order** keeps its records in a fixed order:

- The list in the admin app is sorted by it and its rows can be moved by drag & drop (`POST /api/v1/entities/<entity>/records/order`
  with the ids in the new order). Only users who may *update* the entity can do this. In a tree, records are moved
  among their siblings. After a move, the records are numbered 1, 2, 3 … without gaps.
- New records without a value go to the end (highest value + 1) - in the admin app, through the API, the import and
  when copying an entity.
- The content API returns the records in this order unless `?sort=` says otherwise.

## Trees

Records of an entity can form a tree, e.g. pages with subpages. Add a reference field that points to the entity
itself (e.g. `parent`) and choose it as the **parent field** in the schema settings (`"tree_field": "parent"`).

- The list view shows the tree. Top-level records are paged, and children are loaded when a node is opened.
  Searching or filtering switches to a flat list.
- The record page shows the path to the record, its children and a button to add a child.
- A record can never be moved below itself or one of its descendants. Imports are checked as a whole, so rows of the
  same file cannot close a circle together either.
- A record with children cannot be deleted. Move or delete the children first.
