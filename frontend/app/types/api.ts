export interface PageMeta {
  page_size: number
  current_page: number
  total_pages: number
  total_items: number
}

export interface ApiFailure {
  status: 'failed'
  success: false
  error: string
  error_code?: string
  error_data?: Record<string, string[]>
}

export interface Paged<T> {
  data: T[]
  meta: PageMeta
}

/** update_own / delete_own: only records one created oneself */
export type PermissionKey = 'read' | 'create' | 'update' | 'delete' | 'import' | 'update_own' | 'delete_own'
export type Permissions = Record<PermissionKey, boolean>

export type FieldTypeKey = 'string' | 'text' | 'integer' | 'decimal' | 'boolean' | 'date' | 'datetime' | 'time' | 'email' | 'url' | 'reference' | 'uuid' | 'autoincrement' | 'media' | 'slug' | 'markdown' | 'group' | 'regex' | 'order' | 'enum' | 'color' | 'phone' | 'daterange' | 'json' | 'code'
export type AccessKey = 'public' | 'oauth'

/** File of the media library: the file plus where it is used */
export interface LibraryFile extends MediaFile {
  uploaded_at: string
  /** Kept in the media library even while nothing uses it */
  kept: boolean
  usage_count: number
  hidden_usages: number
  usages: { entity: string, entity_name: string, field: string, field_label: string, record_id: string, record_label: string, in_trash: boolean }[]
}

/** Choices for the allowed types of media fields (GET /admin/schema-options) */
export interface MediaTypeGroup {
  group: string
  items: { value: string, label: string }[]
}

/** Uploaded file of a media field, as the API returns it */
export interface MediaFile {
  id: string
  url: string
  name: string
  mime_type: string
  size: number
  width: number | null
  height: number | null
  is_image: boolean
  /** Images: address for other sizes and formats (?w=…&h=…), carries the focal point */
  transform_url?: string | null
  /** Images: the point cropped variants keep in view, 0 - 1 from the left and the top */
  focal_point?: { x: number, y: number } | null
}

export interface Field {
  id: string
  name: string
  label: string
  /** A built-in type - or the field type of a plugin ("geo.point", see custom) */
  type: FieldTypeKey | `${string}.${string}`
  /** Field type of a plugin: label, web component and config (null: its plugin is not active) */
  custom?: CustomFieldInfo | null
  length: number | null
  scale: number | null
  /** Type "uuid": version of generated values */
  uuid_version: number | null
  /** Type "media": allowed MIME types ("image/*", "application/pdf" ...), empty = all allowed */
  media_accept: string[] | null
  /** A list of values (in the API an array), with fewest/most items (null = no limit) */
  repeatable?: boolean
  /** Repeatable: the order can be changed by drag & drop */
  sortable?: boolean
  repeat_min?: number | null
  repeat_max?: number | null
  /** One value per language of the project (the others in `_i18n` of a record) */
  translatable?: boolean
  /** Type "group": the field group with its fields */
  group?: FieldGroupDef | null
  /** Group field as blocks (page builder): the groups an item can be of - items carry _type and _key */
  /** Blocks: the block types items can be of - the chosen ones and all of block_categories */
  blocks?: FieldGroupDef[] | null
  /** Blocks: the explicitly chosen ones (ids) */
  block_ids?: string[] | null
  /** Blocks: all blocks of these categories too (also ones added later) */
  block_categories?: string[] | null
  /** A plugin relies on it: cannot be deleted, renamed or change its type */
  locked?: boolean
  /** Type "enum": the allowed values (stored) with their labels (shown) */
  options?: { value: string, label: string }[] | null
  /** Roles (slugs) that may see / change the field - null: everyone with permissions for the entity */
  read_roles?: string[] | null
  write_roles?: string[] | null
  /** Text search (?s=) and filters (filter[field]); not filterable = not searched either */
  searchable?: boolean
  filterable?: boolean
  /** Weight in the search, 1 - 10 */
  search_weight?: number
  /** Admin app: what the current user may do with the field */
  can_read?: boolean
  can_write?: boolean
  /** Type "regex": the pattern values must match, and the message when they do not */
  pattern?: string | null
  pattern_message?: string | null
  // Numbers: allowed range, edited with a slider (needs both ends)
  min_value?: number | null
  max_value?: number | null
  slider?: boolean
  /** Type "slug": text field an empty slug is made from */
  slug_source: string | null
  required: boolean
  unique: boolean
  /** Slug of the referenced entity (type "reference") */
  reference: string | null
  sort_order: number
}

export interface Entity {
  id: string
  slug: string
  name: string
  /** Shared by all projects (area "Global") - its schema is edited there */
  global?: boolean
  description: string | null
  access: AccessKey
  label_field: string | null
  unique_together: string[][]
  sort_order: number
  /** Deleted records go to the trash first */
  trash: boolean
  /** Reference field to the entity itself that makes the records a tree */
  tree_field: string | null
  /** The field of type "order" (drag & drop in the list, default sort), null = none */
  order_field: string | null
  /** Languages of the project, the default language first */
  languages?: string[]
  created_at: string | null
  fields: Field[]
  record_count?: number | null
  /** Records in the trash (only for users who may delete) */
  trash_count?: number | null
  /** Records can be saved as drafts (not in the content API) */
  drafts?: boolean
  /** Every change of a record is kept as revision */
  revisions?: boolean
  /** Address of the website that shows a record as preview ({{id}}, {{token}}, {{lang}}, {{record.slug}} …) */
  preview_url?: string | null
  permissions?: Permissions
  referenced_by?: { entity: string, entity_name: string, field: string, field_label: string }[]
  /** Tabs of the record form (form designer) - always at least one, every field in exactly one */
  tabs?: EntityTab[]
  /** Created by this plugin: no deleting, no other slug, its locked fields stay */
  managed_by?: string | null
}

export interface SessionEntity {
  /** Shared by all projects (area "Global") */
  global?: boolean
  id: string
  slug: string
  name: string
  access: AccessKey
  permissions: Permissions
}

/** Field type of a plugin (Field.custom) */
export interface CustomFieldInfo {
  type: string
  plugin: string
  label: string
  description: string
  icon: string
  /** ES module of the plugin that defines the element (tag) */
  component: { tag: string, script: string } | null
  config: Record<string, unknown>
}

/** A field of a plugin: its settings (plugin.json) or the form of its event step */
export interface PluginField {
  key: string
  label: string
  kind: 'text' | 'secret' | 'textarea' | 'select' | 'bool'
  required?: boolean
  placeholder?: string
  help?: string
  default?: string | boolean
  options?: { value: string, label: string }[]
  /** Shown only if the other settings have these values: {"provider": "google"} */
  when?: Record<string, string | boolean | (string | boolean)[]>
}

/** An event step of an active plugin (GET /admin/plugins/steps) */
export interface PluginStepDef {
  type: string
  plugin: string
  label: string
  description: string
  icon: string
  fields: PluginField[]
}

/** A plugin (GET /admin/plugins) */
export interface PluginInfo {
  name: string
  label: string
  description: string
  author: string
  url: string
  /** Version of the files - installed_version differs: an update is waiting */
  version: string | null
  installed_version: string | null
  installed: boolean
  /** Active anywhere: global, or in at least one project */
  active: boolean
  /** global: a pure admin plugin (plugin.json), in every project; project: activated per project */
  scope: 'global' | 'project'
  /** Plugins of projects: where they are active (ids) */
  projects: string[]
  /** Other plugins it needs: name => version constraint (plugin.json "requires") */
  requires: Record<string, string>
  /** Installed plugins that need it */
  required_by: string[]
  update: boolean
  /** Why it was switched off (safe mode) */
  error: string | null
  /** Its files are missing or broken */
  problem: string | null
  migrations: string[]
  pending_migrations: string[]
  fields: PluginField[]
  settings: Record<string, string | boolean>
  secrets: Record<string, { env: string | null, stored: boolean, set: boolean }>
  steps: PluginStepDef[]
  panels?: { key: string, title: string, columns: { key: string, label: string }[], rows: Record<string, unknown>[], error: string | null }[]
}

/** One item of a blocks field */
export interface Block extends Record<string, unknown> {
  _type: string
  _key: string
}

/** Reusable set of fields of a project (field type "group") */
export interface FieldGroupDef {
  id: string
  name: string
  label: string
  /** group: for group fields; block: an item of block lists (page builder) */
  kind: 'group' | 'block'
  /** To organize them (lists, block picker) */
  category?: string | null
  /** Created by this plugin: no deleting, no other name, its locked fields stay */
  managed_by?: string | null
  /** Blocks: the template of their HTML (Twig) - admin API only */
  template?: string | null
  has_template?: boolean
  description: string | null
  fields: Field[]
  /** Admin list: how many fields use the group */
  usage_count?: number
}

export interface ProjectVariable {
  name: string
  translatable: boolean
  value: string | null
  /** Translatable: values of the languages besides the default one */
  translations: Record<string, string | null>
}

export interface Project {
  id: string
  slug: string
  name: string
  description: string | null
  table_prefix: string
  /** Language codes, the default language first (empty = one language) */
  languages: string[]
  default_language: string | null
  /** Placeholders {name} in text values, filled in by the content API */
  variables: ProjectVariable[]
  /** The area "Global": its entities are shared by all projects */
  is_global: boolean
  /** Storage of new uploads (see MediaStorageOption), null = the default one */
  media_storage: string | null
  entity_count?: number
}

/** A storage a project can pick for its uploads (GET /admin/media-storages) */
export interface MediaStorageOption {
  name: string
  label: string
  /** local, s3, r2, hetzner, ftp, sftp, webdav … (see StorageType) */
  type: string
  /** Served by the CMS, protected files only signed - false: a public bucket serves them */
  private: boolean
  default: boolean
  /** builtin: the folder storage/ ("local"), admin: defined in the admin app */
  source: 'builtin' | 'admin'
}

/** A setting of a storage type (GET /admin/storages/types) */
export interface StorageField {
  key: string
  /** text: value or $NAME, secret/key: stored encrypted or $NAME, never returned */
  kind: 'text' | 'secret' | 'key' | 'bool'
  required?: boolean
  placeholder?: string
  default?: boolean
}

export interface StorageType {
  type: string
  label: string
  group: 's3' | 'server'
  fields: StorageField[]
}

/** A storage of the admin page (GET /admin/storages) - the ones of the .env are not editable */
export interface Storage extends MediaStorageOption {
  id: string | null
  editable: boolean
  own_label?: string
  settings?: Record<string, string | boolean>
  /** Per secret: a $NAME variable (env), a stored value or nothing - never the value */
  secrets?: Record<string, { env: string | null, stored: boolean, set: boolean }>
  usage?: { media: number, projects: number }
}

export interface Session {
  /** pending_email: new address waiting for the link in the confirmation mail */
  /** roles: slugs of the user's roles; take_over: may take over records others are editing */
  user: { id: string, name: string, email: string, pending_email: string | null, is_admin: boolean, roles?: string[], take_over?: boolean }
  /** Projects the user works in, and the one the requests go to */
  projects: Project[]
  project: Project | null
  entities: SessionEntity[]
}

/** A user of the admin app or an API client (content API) */
export interface Actor {
  /** event: changed by the steps of an event */
  type: 'user' | 'client' | 'event'
  id: string
  name: string | null
}

export interface RecordRef {
  id: string
  label: string
  entity: string | null
}

/** Scheduled publishing and unpublishing (ISO 8601), errors of times that could not be carried out */
export interface RecordSchedule {
  publish_at: string | null
  unpublish_at: string | null
  errors: { publish?: string, unpublish?: string }
}

/** Lock of a record being edited (see POST /entities/{entity}/records/{id}/lock) */
export interface RecordLock {
  /** The current user holds it */
  mine: boolean
  user: { id: string, name: string }
  locked_at: string
  seen_at: string
}

export type ContentRecord = Record<string, unknown> & {
  id: string
  /** Entities with drafts: the record is a draft (admin app only) */
  draft?: boolean
  /** Published records of entities with drafts: changes saved for later - the record as the working copy has it (detail), or whether there is one (list) */
  _working_copy?: ContentRecord | boolean | null
  /** Admin app: who is editing the record right now (null: nobody) */
  _lock?: RecordLock | null
  /** Entities with drafts: scheduled publishing / unpublishing (null: none) */
  _schedule?: RecordSchedule | null
  created_at: string
  updated_at: string | null
  /** Admin app: who created / changed / deleted the record */
  created_by?: Actor | null
  updated_by?: Actor | null
  deleted_by?: Actor | null
  /** Only records in the trash */
  deleted_at?: string
  /** Tree entities: number of children and the ancestors (root first, detail only) */
  _children?: number
  /** Translatable fields: values of the languages besides the default one */
  _i18n?: Record<string, Record<string, unknown>>
  _path?: { id: string, label: string }[]
  _refs?: Record<string, RecordRef | RecordRef[]>
}

/** Result of deleting several records */
export interface DeleteResult {
  trashed: number
  deleted: number
  failed: { id: string, label: string, message: string, code: string }[]
}

export interface Option {
  value: string
  label: string
}

export interface SchemaOptions {
  field_types: Option[]
  access: Option[]
  permissions: Option[]
}

/** A role (yiisoft/rbac): permissions per entity, contained roles, media and take-over permissions */
export interface Role {
  slug: string
  name: string
  /** Slugs of the roles it contains (inherits from) */
  roles: string[]
  permissions: Record<string, Partial<Permissions>>
  media_upload: boolean
  media_delete: boolean
  take_over: boolean
  users: number
  clients: number
}

export interface User {
  id: string
  name: string
  email: string
  is_admin: boolean
  /** Slugs of its roles; permissions are its own, effective_permissions with those of the roles */
  roles: string[]
  effective_permissions: Record<string, Partial<Permissions>>
  take_over: boolean
  is_active: boolean
  permissions: Record<string, Partial<Permissions>>
  /** Ids of the projects the user works in */
  projects: string[]
  last_login_at: string | null
  created_at: string | null
}

export interface ApiClient {
  id: string
  name: string
  client_id: string
  is_active: boolean
  rate_limit: number | null
  /** Entities the client may read - and write if allowed */
  entities: { id: string, slug: string, name: string, create: boolean, update: boolean, delete: boolean, update_own?: boolean, delete_own?: boolean }[]
  /** Media of the project: upload (POST /media) and delete unused files */
  media: { upload: boolean, delete: boolean }
  /** Slugs of its roles; effective: what it may do with them */
  roles: string[]
  effective?: { entities: Record<string, Partial<Permissions>>, media: { upload: boolean, delete: boolean } }
  last_used_at: string | null
  created_at: string | null
  /** Only right after creating it / a new secret */
  client_secret?: string
}

export interface RateLimit {
  enabled: boolean
  requests: number
  window: number
}

export interface ColumnSuggestion {
  field: string
  label: string
  type: FieldTypeKey
  length: number | null
  scale: number | null
  uuid_version: number | null
  required: boolean
  unique: boolean
  reference: string | null
  match: string | null
  /** Yes/no columns: values that mean yes and no, and whether an empty cell means no */
  true_values?: string[]
  false_values?: string[]
  empty_false?: boolean
}

export interface ReferenceCandidate {
  entity: string
  name: string
  match: string
  match_label: string
  found: number
  total: number
}

/** Records the import adds to other entities: a new entity from a column, or missing values */
export interface ImportNewReference {
  entity: string
  slug: string
  /** The entity is new (made from the column) */
  new: boolean
  count: number
  values: string[]
}

export interface ImportAnalysis {
  import_id: string
  file_name: string
  format: string
  sheets: string[]
  sheet: string | null
  delimiter: string | null
  row_count: number
  /** values: the different values of the column (only for up to 20) */
  /** references: entities the values could point to, best first (found of total sample values) */
  columns: { column: string, samples: string[], empty: number, distinct: number, values?: string[], suggestion: ColumnSuggestion, references?: ReferenceCandidate[] }[]
  rows: (string | null)[][]
  entity: { slug: string, name: string, access: AccessKey, label_field: string | null }
  existing: { entity: string, name: string, columns: Record<string, string | null>, key: string | null, score: number }[]
  may_create: boolean
}

export interface ImportRow {
  line: number
  action: 'create' | 'update' | 'error'
  label: string
  message: string | null
  values?: Record<string, unknown>
}

export interface ImportResult {
  summary: { create: number, update: number, error: number }
  rows: ImportRow[]
  errors: ImportRow[]
  fields: { column: string, name: string, label: string, type: FieldTypeKey }[]
  new_fields: string[]
  new_references?: Record<string, ImportNewReference>
  entity?: string
}

/** A revision of a record (GET /entities/{entity}/records/{id}/revisions) */
export interface RevisionItem {
  id: string
  action: 'create' | 'update' | 'trash' | 'restore'
  /** Names of the fields that changed ("draft" for the draft state) */
  changed: string[]
  created_by: Actor | null
  created_at: string
}

/** One revision with the record as it was */
export interface RevisionDetail extends Omit<RevisionItem, 'changed'> {
  record: ContentRecord
  /** Fields of then that are gone now */
  removed: { name: string, label: string, value: unknown }[]
}

/** Progress of one step of an event run */
export interface EventRunStep {
  type: string
  status: 'waiting' | 'running' | 'done' | 'failed'
  done: number
  total: number
  error: string | null
}

/** One execution of an event: the records of one request, the progress of every step */
export interface EventRun {
  id: string
  status: 'queued' | 'running' | 'done' | 'failed'
  depth: number
  count: number
  steps: EventRunStep[]
  error: string | null
  created_at: string
  started_at: string | null
  finished_at: string | null
}

/** An event of the project (GET /admin/events) */
export interface EventItem {
  id: string
  name: string
  /** What it listens to: records of an entity, the media or the variables of the project */
  source: 'entity' | 'media' | 'variables' | 'event' | `${string}.${string}`
  /** Slug of the entity it listens to (source "entity") */
  entity: string | null
  /** Sources of plugins: limited to one of their targets (e.g. a form) - null: all */
  target?: string | null
  actions: string[]
  condition: Record<string, unknown> | null
  steps: Record<string, unknown>[]
  mode: 'direct' | 'queue'
  active: boolean
  last_run: EventRun | null
}


/** Something events of plugins react to, e.g. "forms.submission" */
export interface PluginEventSource {
  source: string
  plugin: string
  label: string
  /** action => label */
  actions: Record<string, string>
  /** What an event may be limited to (e.g. the forms) - with the placeholders of their items */
  targets: { value: string, label: string, fields?: string[] }[] | null
  target_label: string
}

/** A page of a plugin in the admin app */
export interface PluginPage {
  plugin: string
  key: string
  label: string
  icon: string
  component: { tag: string, script: string }
  config: Record<string, unknown>
}

/** A tab of the record form: its fields by name */
export interface EntityTab {
  key: string
  /** Empty if it is the only tab */
  label: string
  fields: string[]
}
