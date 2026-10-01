# ContentBridge API v1

Status: release candidate. Runtime evidence, rollout status and exact accepted SHAs must be recorded before calling a site migrated.

The canonical implementation is `plugins/comitement-content-bridge/includes/class-contentbridge-v1.php`. Website plugins vendor this file unchanged using `scripts/vendor_contentbridge.py`; the generated loader contains only existing content-credential selection and legacy image/source metadata mappings. No new individual publishing implementation is introduced. Existing routes and pipelines remain installed.

## Transport and authentication

All routes are under `/wp-json/contentbridge/v1`. TLS is mandatory. The existing **content** secret is reused. An observer-only token never grants content writes. Connect uses the canonical Fleet allowlist and the Vault key `contentbridge_<site-id>_secret`; only Streamtechnik can reuse its Fleet token because its audited plugin uses that exact credential for content too. Secrets never appear in tool results, audit payloads or errors.

Every request includes:

- `X-Comitement-Timestamp`: Unix seconds, maximum 300 seconds skew
- `X-Comitement-Nonce`: unique 16–128 character ASCII nonce
- `X-Comitement-Signature`: lowercase hex HMAC-SHA256

The signed string is `METHOD + "\n" + REST_ROUTE + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + BODY`. `REST_ROUTE` includes `/contentbridge/v1/...`, excluding `/wp-json`; GET has an empty body. HTTP method and route are bound to the signature. Nonce insertion is atomic in the WordPress options table; nonce records expire after ten minutes. Redirects are disabled. No user-supplied URL, server path, arbitrary WordPress meta key, SQL or code is accepted.

Connect retains `comitement.connect.read`. Content mutations additionally require `comitement.connect.content.write` and current `manage_options` or `manage_comitement` permission. Existing read access/refresh tokens remain read-only. Request both scopes and reconnect for write consent. The existing owner Vault bearer credential remains an owner credential. PKCE, exact ChatGPT callback allowlisting, refresh rotation, rate limits and audit logging remain in use.

## Routes

| Method | Path | Behavior |
|---|---|---|
| GET | `/capabilities` | Version, limits, operations and up to 100 existing categories/tags each |
| POST | `/posts` | Create draft only |
| GET | `/posts/{post_id}` | Read actual remote state |
| PATCH | `/posts/{post_id}` | Update supplied fields; omitted fields preserved; explicit publish/schedule |
| POST | `/media` | Upload validated base64 raster image |
| DELETE | `/test-fixtures/{id}` | Signed acceptance-only cleanup; exact marker required; cannot delete ordinary content |

Each mutation requires `idempotency_key` (8–128 characters: letters, digits, `.`, `_`, `:`, `-`). The ledger is scoped to method, route and key. Exact-body retries return the stored result without reapplying the mutation. A different body is a conflict. Concurrent/pending operations fail closed; crashed pending operations require reconciliation after reading remote state. Completed ledger entries persist; do not delete them while retries are possible.

## Content schema

```json
{
  "idempotency_key": "article-2026-10-01-create",
  "title": "Article title",
  "content": "<p>Article body.</p>{{image:diagram.png}}",
  "excerpt": "Summary",
  "slug": "article-slug",
  "author": 1,
  "categories": [1, "Tutorials"],
  "tags": ["OBS", "Streaming"],
  "seo": {
    "seo_title": "Search title",
    "meta_description": "Search description",
    "canonical": "https://example.com/article-slug/",
    "focus_keyword": "OBS"
  },
  "featured_image": {"attachment_id": 42, "file": "cover.png", "alt_text": "Cover", "caption": "Caption"},
  "inline_images": [{"attachment_id": 43, "file": "diagram.png", "alt_text": "Diagram", "title": "Diagram", "caption": "Caption", "credit": "Creator"}],
  "placeholder_fallback": false
}
```

Only `post` is supported by v1. Legacy page/custom-post-type imports remain on their existing routes. Create requires nonempty title and HTML body. HTML is sanitized with WordPress KSES and slashed before insertion. Status defaults to `draft`; an existing post keeps its status. The API accepts `draft`, `publish`, `future`; scheduling requires `publish_at` as an ISO-8601 timestamp with timezone, at least one minute in the future. Connect create/update tools deliberately reject status fields; use the explicit publishing tools. Explicit slug collisions fail rather than silently suffixing a new slug. Author must be an existing user with `edit_posts` capability.

Taxonomy accepts up to 50 existing positive integer IDs or nonempty names; empty arrays clear that taxonomy. Names resolve/create terms. Categories/tags have their native WordPress semantics. Supported SEO fields map to the existing Yoast keys; unspecified metadata, including other SEO/plugin fields, is untouched. Unknown supplied SEO keys are rejected. `canonical` must be an HTTP(S) URL. There is no arbitrary meta write surface.

## Media

```json
{
  "idempotency_key": "article-cover-upload",
  "file": "cover.png",
  "mime_type": "image/png",
  "data_base64": "BASE64_BYTES",
  "alt_text": "Description",
  "title": "Image title",
  "caption": "Image caption",
  "source": "Source attribution",
  "credit": "Creator credit"
}
```

Native input is a base64 JPEG, PNG, GIF or WebP, or an existing image `attachment_id`. Maximum decoded upload: 5 MiB; maximum request: 7 MiB. Actual image MIME must match declared MIME and filename extension. Maximum dimension: 12,000 px per side, 40 million pixels total. SVG, arbitrary file paths, URLs, remote redirects and URL imports are unsupported. Therefore URL-based SSRF is unavailable. Uploads use server-generated temporary files and the native WordPress sideload pipeline, with cleanup in `finally`.

Images retain alt text, title, caption and optional attribution. Inline images replace existing `{{image:file}}` markers with Gutenberg image blocks. Without an existing marker they append to the body. Featured images use native thumbnail assignment and verify the saved attachment ID. Upload failures return explicit errors; the caller can create/update a **draft** using a safe filename and `placeholder_fallback: true`.

Priority is native assignment, existing asset IDs/legacy upload mechanisms, then explicit placeholders. v1 reports `media_path` as `native`, `placeholder`, `mixed`, or an empty string for unchanged/untracked legacy state. It reports `placeholders_remaining` and `featured_image_pending`. Unresolved placeholders or pending featured-image replacements block publication and scheduling. An old featured image is preserved while its replacement is pending.

Legacy placeholders keep their exact syntax and filenames. The adapter writes the existing image manifest, source marker and managed-draft marker needed by the site's existing ZIP/image UI. Existing article imports and scheduled posts are not rewritten. After using a legacy ZIP mechanism to resolve a pending featured replacement, explicitly assign its new attachment ID through v1 to clear the pending flag.

## Responses and verification

```json
{
  "ok": true, "api_version": 1, "post_id": 123,
  "url": "https://example.com/article-slug/", "status": "draft",
  "title": "Article title", "content": "<p>Actual saved HTML</p>",
  "slug": "article-slug", "categories": [1], "tags": [5],
  "seo": {"seo_title": "Search title", "meta_description": "Description"},
  "featured_image": {"attachment_id": 42, "url": "https://example.com/uploads/cover.png", "alt_text": "Cover", "caption": "Caption"},
  "inline_images": [], "media_path": "native", "placeholders_remaining": 0,
  "featured_image_pending": false, "verified": true, "site": "streamtechnik"
}
```

Connect performs an independent GET after post mutation. It verifies returned post ID and status, and returns the reread content, taxonomy, SEO and media for field-level inspection. `verified` does not imply editorial approval. A draft permalink is not a public-preview guarantee; authenticated WordPress preview is available separately. Metadata failure after insertion reports a partial-write error; inspect remote state before a new key. No fake success is returned.

## Capabilities and Connect tools

Capabilities: `content.create`, `content.get`, `content.update`, `content.publish`, `content.schedule`, `media.upload`, `media.featured`, `media.inline`, `seo.metadata`, `taxonomy.categories`, `taxonomy.tags`, `placeholder.images`. Discovery is authenticated. A missing v1 route reports version 0/LEGACY when a content credential is configured; missing credentials are separately reported. Writes are never guessed against an older route.

| Tool | Required arguments |
|---|---|
| `get_contentbridge_capabilities` | `site` |
| `get_content` | `site`, `post_id` |
| `create_content` | `site`, `idempotency_key`, `content` object |
| `update_content` | `site`, `post_id`, `idempotency_key`, `content` object |
| `publish_content` | `site`, `post_id`, `idempotency_key` |
| `schedule_content` | `site`, `post_id`, `idempotency_key`, `publish_at` |
| `upload_media` | `site`, `idempotency_key`, `media` object |

The `content` tool argument contains the content schema fields; its nested `content` is HTML. Mutation keys belong at tool argument level. Create/update set featured images, inline images, taxonomy and SEO without parallel tools or APIs. Tool annotations distinguish real writes from reads. Expected execution failures use MCP `isError: true` with a stable machine-readable `error`; auth/scope denial is JSON-RPC `-32003`.

## Errors

Native error codes have the prefix `contentbridge_`: `unauthorized` (401), `not_configured` (503), `replay` (409), `payload_too_large` (413), `malformed_payload`, `idempotency_required`, `idempotency_conflict` (409), `in_progress` (409), `not_found` (404), `slug_exists` (409), `invalid_status`, `draft_required` (409), `invalid_schedule`, `invalid_author`, `invalid_taxonomy`, `taxonomy_failed` (422), `invalid_seo`, `invalid_media`, `media_unavailable` (422), `media_unresolved` (409), `mime_mismatch`, `media_too_large` (413), `image_dimensions`, `media_failed` (422/503), `write_failed` (422), `featured_failed` (422), `fixture_protected` (403/409), `cleanup_failed` (500).

Connect additionally reports `UNKNOWN_SITE`, `MALFORMED_PAYLOAD`, `CONTENT_AUTH_UNAVAILABLE`, `UNSUPPORTED_CAPABILITY`, `CONTENTBRIDGE_TRANSPORT`, `INVALID_BRIDGE_RESPONSE`, `VERIFY_FAILED`. Remote error messages and response headers are not echoed; credential details cannot leak through provider exceptions.

## Versioning and migration

v1 is additive; existing routes are not removed. A major version changes namespace. Capabilities determine usable operations; version alone does not authorize writes. Generate adapters using the vendor script, review credential mappings, test legacy calls, use each site's existing deployment workflow, then verify authenticated capabilities before enabling Connect writes. Keep deployed component checksum in `contentbridge-v1-provenance.json`.

CasinoTester.net remains in its explicit create-only protection mode until a scoped migration preserves its existing-content rule. Schwabenpark's current bridge is an observer/core-update service and has no content secret/importer; it requires deliberate content provisioning. Neither receives observer-based publishing rights.

Acceptance uses the real Connect MCP HTTP route on canonical staging and the real Streamtechnik bridge, uploads a fixture image, creates/updates/rereads drafts and tests fallback separately. `test_fixture: "acceptance-..."` marks disposable data; marked drafts cannot be published/scheduled and fixture media cannot be attached to real content. Signed cleanup accepts only an exact marker and only marked drafts/attachments. Cleanup runs in `finally`; ordinary content has no delete tool. Local behavior/security tests cover publishing and scheduling without publishing live test articles.
