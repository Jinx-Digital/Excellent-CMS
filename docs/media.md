---
title: Media
summary: Uploads, storages, image sizes, focal points and protected files.
parent: concepts
order: 110
---
# Media

Files of media fields are uploaded in the admin app (`POST /api/v1/media`) and stored with
[Flysystem](https://flysystem.thephpleague.com/). The built-in storage `local` is the folder `storage/`, served by PHP
under `/media/…` (see [Protected files](#protected-files)). More storages are defined in the admin app, and each
project picks where its new uploads go (*Projects › Media storage*, default `local`).

`MEDIA_URL` is the base URL where the CMS serves files (local storages and private buckets). The API always returns
absolute URLs. A relative value (default `/media`) gets the scheme and host of the request. Behind a reverse proxy or
load balancer that terminates TLS, set the full URL (e.g. `https://cms.example.com/media`). `MEDIA_MAX_SIZE` limits
uploads (MB). PHP's `upload_max_filesize` and `post_max_size` must allow that size too.

**Storages** (*Administration › Storages*), defined by admins for all projects:

| Type | Settings |
| --- | --- |
| Local folder | a folder relative to the CMS or absolute (e.g. a mounted network drive) |
| Amazon S3, MinIO / S3 compatible | bucket, region, endpoint, key, secret, prefix, path style, ACL |
| Cloudflare R2 | account ID (endpoint follows; jurisdiction `eu` optional), bucket, key, secret |
| Hetzner, IONOS, DigitalOcean Spaces, Scaleway, Wasabi, Backblaze B2 | region (the endpoint follows), bucket, key, secret |
| FTP / FTPS, SFTP, WebDAV | with the plugin `storage-servers` |

Every setting takes a value or a `$NAME` variable of the `.env` (typing `$` suggests them), read whenever the storage
is used - so credentials can stay in the environment. Secrets (keys, passwords, private keys) entered as values are
encrypted with `APP_ENCRYPTION_KEY` (libsodium; `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`) and never
returned by the API. Sent back empty, they stay as they are. Without the key, secrets can only be `$NAME` references.

A bucket is **public** by default: it serves its files itself at its URL (fast, e.g. through Cloudflare's CDN for R2),
but they are not protected. No ACL is sent, because new AWS buckets and R2 reject them (old buckets that need one set
it in the storage, e.g. `public-read`). A **private** storage is read by the CMS, which serves the files under
`/media/…` like local ones, with the same protection. That is slower, because every file goes through PHP once
(public files are cached for a year after that). *Test* writes, reads and deletes a small file. The name is what files
refer to: it can not change, and a storage that files or projects use can not be deleted.
API: `GET|POST /api/v1/admin/storages`, `GET|PUT|DELETE /api/v1/admin/storages/{id}`, `POST …/{id}/test`,
`GET /api/v1/admin/storages/types`, `GET /api/v1/admin/media-storages` (the choices for projects).

Every file remembers the storage it was written to. When a project switches, only new uploads go to the new storage.
Older files stay where they are and keep working, as long as that storage stays configured. Copying an entity with
its records into a project with another storage transfers the files there. Deleting a storage is refused while files use it;
a storage whose settings stop working makes its files unreachable: the API returns them without a `url`, and the cleanup leaves them alone.

By default these file types can be uploaded (admins change it in the media library under **File types**,
`GET/PUT /api/v1/admin/settings/media-types`):
- images: JPG, PNG, GIF, WebP, AVIF, TIFF, BMP, HEIC
- PDF, Office and OpenDocument files, RTF, EPUB, text, CSV, JSON
- archives: ZIP, GZ, TAR, 7Z, RAR
- audio and video: MP3, WAV, OGG, FLAC, AAC, M4A, MP4, WebM, MOV, MPEG, OGV, AVI, MKV

- optional, off by default: **SVG** - it can contain scripts, so the CMS serves it with
  `Content-Security-Policy: sandbox` (opened directly, nothing runs; in `<img>` scripts never run). SVG counts as an
  image but is not transformed.

Each media field can narrow this down to a list of MIME types: single types (`image/png`, `application/pdf`) or
main types (`image/*`, `video/*`). An empty list allows all allowed types. The admin app checks the type right at the
upload, and the API checks it again when the record is saved.

The type is detected from the file content, and the stored file gets the extension of that type, so an upload can
never become a script or an HTML page. Local files are kept
outside the document root, so the web server never runs or lists them.

## Protected files

Files of `local` are stored in `storage/` and served by PHP under `/media/<path>`
(`public/.htaccess` sends `/media/` to `public/api/index.php`; for nginx see [Deployment](deployment.md)):

- A file used by a record of a **public** entity (not in the trash) is served to everyone and cached for a year.
- Every other file (protected entities, the admin app, unused files of the media library) needs a **signed address**:
  `/media/<path>?expires=…&signature=…`. The API signs the `url` of every file it returns outside a public entity, so
  whoever may read the record can simply use the URL, also in an `<img>`. Without a valid signature the answer is 403.
- Signed addresses are valid for `MEDIA_SIGNED_TTL` to twice that long (default 1 day). Within one window everyone
  gets the same address, so browsers and CDNs can cache it. Websites that store the URLs (e.g. in a static page cache)
  should download the file or ask the API again after that time.
- The signature uses a key derived from `JWT_SECRET`. Changing it invalidates all signed addresses.

This applies to the local folder and to private buckets. Files of public buckets are served by the bucket and are
not protected.

Moving an installation that kept its files in `public/media`: `mv public/media/* storage/` (without its `.htaccess`). The URLs
stay the same.

The API returns media fields as objects:

```json
"logo": {"id": "…", "url": "https://cms.example.com/media/2026/10/….png", "name": "logo.png",
         "mime_type": "image/png", "size": 12345, "width": 400, "height": 300, "is_image": true,
         "transform_url": "https://cms.example.com/media/2026/10/….png?fp=0.3,0.6", "focal_point": {"x": 0.3, "y": 0.6}}
```

Repeatable media fields return an array of these objects in their order.

## Image transformations

Images (jpg, png, gif, webp) can be fetched in another size and format: add parameters to their `transform_url`.
Websites don't need their own image scaling.

```
https://cms.example.com/media/main/2026/10/….png?w=800&h=450&fit=cover&format=webp
```

| Parameter | Values | |
| --- | --- | --- |
| `w`, `h` | 1 – `MEDIA_TRANSFORM_MAX_SIZE` (default 4000) | width and height in pixels |
| `fit` | `cover` (default with `w` and `h`) | fills `w` × `h` and cuts off what sticks out |
| | `contain` | fits into `w` × `h` and fills the rest with `bg` (whitespace) |
| | `inside` (default with only `w` or `h`) | fits into `w` and/or `h`, keeps the proportions |
| `pos` | `center` (default), `top`, `bottom`, `left`, `right` | which part `cover` keeps |
| `fp` | `x,y` from 0 to 1 (e.g. `0.3,0.6`) | focal point `cover` keeps in view, wins over `pos`. The `transform_url` of an image with a focal point carries it already |
| `bg` | hex color (`fff`, `ffffff`, `ffffff80`) or `transparent` | fill of `contain`; default transparent, white for jpg |
| `format` | `jpg`, `png`, `webp`, `gif`, `avif` (if the server's GD has it) | default: the format of the original |
| `q` | 1 – 100 (default 82) | quality of jpg, webp and avif |

- Images are never enlarged. `contain` always returns `w` × `h`. `cover` returns smaller originals in the
  proportions of `w` × `h`. `inside` returns them in their own size. JPEG photos are turned upright using their EXIF
  orientation.
- Every variant is made once with GD. It is then served from `runtime/media-variants/<file id>/` and cached by
  browsers for a year, because files never change. Deleting a file deletes its variants, and `./yii cleanup` removes
  leftovers.
- Access is the same as for the file itself. Files of public entities are open. For all others, `transform_url` is
  signed and the parameters are added to it (`…?expires=…&signature=…&w=400`).
- `transform_url` always points to the CMS, including files of public buckets: they are read from the bucket for the
  transformation. For files served by the CMS it is the same as `url`. Files that cannot be transformed (SVG, PDF …)
  have `null`.
- Wrong parameters return 400, files that are not images 415.
- **Focal point:** in the media library, a click into an image sets the point that cropped variants keep in view.
  Previews show 16:9, 1:1 and 3:4. `cover` crops so that the point is as near the middle as the edges allow. The
  API returns it as `focal_point` (`{"x": 0.3, "y": 0.6}` or `null`), and `PATCH /media/{id}` sets it
  (`{"focal_point": {"x": 0.3, "y": 0.6}}`, `null` removes it). Because the `transform_url` carries it (`fp=0.3,0.6`),
  moving the point gives new addresses, and variants cached by browsers never show the old crop.

To save a record, send the file's `id` (or the object as it came from the API). For several files, send an array of
ids or objects in the wanted order. Media fields cannot be filled by an
import or converted to another type.

The **media library** (*Media* in the admin app) lists all files of the project with their type and size and shows
where each one is used: entity, field and record. Usages in entities the user may not read are only counted.

- Files can be uploaded **in the library** (button or drag & drop) or **in a media field** of a record.
- Files uploaded in the library are **kept** even while nothing uses them. Any file can be marked or unmarked as kept,
  and renamed.
- Media fields have **Choose from the library**: a picker with search that only shows the file types the field allows,
  with multiple selection for repeatable fields. The same file can be used in any number of records.
- Files that are not kept are removed by `./yii cleanup` 24 hours after nothing uses them any more. Administrators can
  delete unused files at any time.

```
GET    /api/v1/media?s=&kind=image|file&usage=used|unused&accept=image/*&kept=1    library with usages
POST   /api/v1/media                                         multipart "file"; keep=1: into the library
PATCH  /api/v1/media/{id}                                    {"kept": true, "name": "…", "focal_point": {"x": 0.3, "y": 0.6}}
GET    /api/v1/media/{id}
DELETE /api/v1/media/{id}                                    admins, unused files only
```
