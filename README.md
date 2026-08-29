# NeonLib API

Minimal PHP 8.4 and MySQL 8.4 API for NeonLib accounts and subscriptions.

## Local setup

1. Copy `.env.example` to `.env` and adjust database credentials.
2. Run `php scripts/migrate.php`.
3. Serve the directory through Apache with `mod_rewrite` enabled.

Each installation uses exactly one `.env`: the local machine has its own local
file and the production server has a different production file. Environment
files are ignored by Git. Do not add `.env.local`; the custom loader reads
`.env` only. `.env.example` is the safe local template and
`.env.production.example` is the safe production template.

## Production deployment

The production hostname is `https://neonlib-api.neonbreak.com`. This is a custom
PHP application, not Laravel, and it has no `public` directory. Point the
subdomain's Document Root at the project directory that contains `index.php`
and `.htaccess` (for example `/home/ACCOUNT/neonlib-api`). Apache must allow
`.htaccess` overrides and have `mod_rewrite` enabled.

1. Upload the project without the local `.env` and runtime contents of
   `storage/`.
2. Copy `.env.production.example` to `.env` on the server.
3. Replace every `replace_with_...` value and confirm `WORDPRESS_SITE_ID` is the
   exact site identifier sent by the WordPress backend.
4. Keep `.env` readable by the PHP process but inaccessible over HTTP. The
   supplied `.htaccess` denies dotfiles and direct access to `src`, `database`
   and `scripts`.
5. Run `php scripts/migrate.php` from the project directory using the production
   database credentials.
6. Verify `https://neonlib-api.neonbreak.com/api/v1/health` returns JSON with
   `"status":"ok"`.

Use `ADMIN_PORTAL_MODE=disabled` for the safest production default. Set it to
`readonly` only when production diagnostics are required; never use
`development` in production. `APP_DEBUG` must remain `false` so exceptions and
database details are not returned to clients. Generate `WORDPRESS_CLIENT_SECRET`
as at least 32 cryptographically random bytes and configure the identical secret
in the WordPress backend. The API currently does not implement browser CORS;
the authenticated WordPress integration is server-to-server.

## Public API

- `GET /api/v1/health`
- `GET /api/v1/subscriptions/featured`
- `GET /api/v1/subscriptions/search?q=mobile`
- `GET /api/v1/subscriptions/{packageId}/manifest`
- `GET /api/v1/subscriptions/{packageId}/content`
- `POST /api/v1/accounts/link` (authenticated WordPress server only)

The database stores editable, normalized records. Published package responses are generated from an immutable subscription version.

## Account linking contract (API v1)

WordPress owns registration, login, e-mail verification, user communication and
the mapping stored in `wp_usermeta`. NeonLib owns its accounts, subscriptions,
versions, manifests, entitlements and distribution. A subscription/entitlement
must reference `accounts.account_id`; it must never use a WordPress user ID.

After verifying the signed-in user's e-mail, the WordPress backend sends:

```http
POST /api/v1/accounts/link
Content-Type: application/json
X-NeonLib-Client-Id: wordpress-primary
X-NeonLib-Timestamp: 1787097600
X-NeonLib-Nonce: random-base64url-value
X-NeonLib-Signature: v1=<hex HMAC-SHA256>

{"wordpress_site_id":"example.com","wordpress_user_id":"42","email_verified":true}
```

The signature input is the exact UTF-8 string
`METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256_HEX(BODY)`. The timestamp may differ by
at most 300 seconds and a `(client_id, nonce)` can be used only once. Use HTTPS,
keep `WORDPRESS_CLIENT_SECRET` server-side, use a cryptographically random nonce,
and periodically delete expired rows from `api_request_nonces`. The request's
`wordpress_site_id` must exactly match `WORDPRESS_SITE_ID`, binding the client
credential to one WordPress installation.

On first link the endpoint returns `201`; retries for the same external identity
return `200` and the same ID:

```json
{"requestId":"...","data":{"account_id":"acc_01k...","status":"active","created_at":"...","linked_at":"..."}}
```

`account_id` is `acc_` plus 26 lowercase Crockford Base32 characters encoding
128 random bits. WordPress stores it as opaque text in `wp_usermeta`. WordPress
may create/read only the link for the currently authenticated and verified user;
it cannot select an `account_id`, mutate entitlements, or act for another site.
NeonLib authorization always resolves the external link first and enforces access
against that account. API errors consistently use
`{"requestId":"...","error":{"code":"...","message":"...","details":{...}}}`;
`details` is present only when useful (for example field validation).

Existing subscription response bodies remain unchanged for v1 compatibility.
The historical `mobileai` database and fixture package IDs are also retained so
existing installations can apply migration `006_accounts.sql` without a database
rename. Runtime PHP namespaces and user-facing service branding are NeonLib.

## Linked account and owned subscription CRUD

All routes below require the same HMAC headers. They additionally require
`X-NeonLib-Subject: <wordpress_user_id>`. For these requests the subject is
appended to the signature input as a sixth line, so it cannot be changed without
invalidating the signature.

- `GET /api/v1/accounts/link` returns the subject's active link.
- `PUT /api/v1/accounts/link` with `{"email_verified":true}` refreshes verification.
- `DELETE /api/v1/accounts/link` revokes the link and returns `204`. It does not
  delete the NeonLib account or its subscriptions; linking the same WordPress
  identity again restores access to the same `account_id`.
- `GET /api/v1/account/subscriptions` lists only the linked account's subscriptions.
- `POST /api/v1/account/subscriptions` creates a draft owned by that account.
- `GET /api/v1/account/subscriptions/{packageId}` reads one owned subscription.
- `PATCH /api/v1/account/subscriptions/{packageId}` changes supplied editable fields.
- `DELETE /api/v1/account/subscriptions/{packageId}` deletes the owned subscription
  and its versions/documents through existing foreign-key cascades.

Create accepts `package_id`, `title`, `description`, `language`, and `visibility`
(`public` or `private`). PATCH accepts any non-empty subset. Package IDs remain
globally unique. Every item query includes `owner_account_id`; requests for a
foreign package return `404` rather than revealing that it exists. Account status
must be `ACTIVE`. WordPress never supplies or chooses `owner_account_id`.

## Publishing document JSON

WordPress handles file upload, file-type checks and text extraction. It sends the
resulting UTF-8 JSON to NeonLib:

```http
POST /api/v1/account/subscriptions/example.private/versions
X-NeonLib-Subject: 42
Content-Type: application/json

{"documents":[{"id":"intro","title":"Introduction","content":"Extracted plain text"}]}
```

NeonLib publishes this as the next immutable version in one transaction, stores
the documents, calculates the canonical content SHA-256 and byte size, and moves
the subscription's `published_version_id` to the new version. Older versions are
never modified.

- `GET /api/v1/account/subscriptions/{packageId}/versions` lists owned versions.
- `GET /api/v1/account/subscriptions/{packageId}/versions/{version}` returns one
  owned version with its documents.
- `POST /api/v1/account/subscriptions/{packageId}/versions` publishes a new version.

A publish request supports 1–500 documents, at most 1 MiB of content per document,
8 MiB total document content and a 10 MiB HTTP body. Document IDs must be unique
within the version. Published versions intentionally have no PATCH or DELETE API.

## Admin JSON API

The HTML `/admin` portal remains a separate emergency/debugging interface. The
admin JSON API uses revocable bearer tokens and never accepts WordPress HMAC
credentials or browser portal sessions. Tokens are stored only as SHA-256 hashes
and work only while their owner is an active `SUPERADMIN`.

Create a token locally and copy the displayed secret immediately:

```bash
php scripts/create_admin_token.php admin@example.com "Operations laptop"
```

Send it as `Authorization: Bearer nlat_...` to:

- `GET /api/v1/admin/accounts?q=acc_...&status=active`
- `GET /api/v1/admin/accounts/{accountId}`
- `PATCH /api/v1/admin/accounts/{accountId}` with an `active`, `suspended`, or
  `deleted` status
- `GET /api/v1/admin/subscriptions?account_id=acc_...&status=published`
- `GET /api/v1/admin/subscriptions/{packageId}`
- `PATCH /api/v1/admin/subscriptions/{packageId}` with any subset of `status`,
  `visibility`, and boolean `is_featured`

Lists are capped at 100 newest records. Admin mutations write the acting admin,
event, target, IP address and change details to `admin_audit_log`. A token can be
revoked by setting its `revoked_at`; expiry is enforced when `expires_at` is set.

## Administrator portal

The administrator portal has three explicit modes. An absent or invalid value
is treated as `disabled`:

- `disabled`: `/admin` and all subpaths return the generic API `404`.
- `readonly`: a super administrator can diagnose one exact Package ID at a time.
- `development`: the local publisher and publish tool is available.

For local development, set:

```dotenv
APP_ENV=development
APP_DEBUG=true
ADMIN_PORTAL_MODE=development
```

Production must use:

```dotenv
APP_ENV=production
APP_DEBUG=false
ADMIN_PORTAL_MODE=disabled
```

Production diagnostics require HTTPS and should use `ADMIN_PORTAL_MODE=readonly`.
The read-only portal accepts only exact Package IDs and exposes subscription
metadata, versions, published documents, API URLs and recorded publish errors.
It has no create, update, publish or delete routes. Sessions expire after 15
minutes, failed logins are rate-limited, and logins and package views are audited.

Create or update a local publisher account from the command line:

```bash
php scripts/create_admin.php admin@example.com "Display name"
```

The command creates or promotes a `SUPERADMIN` account and asks for the password
without storing it in the repository. Run migration `004_admin_diagnostics.sql`
before using it. In `development` mode, open `/admin`, sign in, enter subscription
metadata and select **Publish new version**. Publishing remains transactional.

To apply one new migration to an existing development database without replaying seed files:

```bash
php scripts/migrate.php 003_featured_subscriptions.sql
```

## Acceptance-test catalog

The idempotent `005_seed_subscription_test_catalog.sql` fixture provides:

- `mobileai.android-guide`: public, published and non-featured
- `mobileai.security-guide`: public, published and featured
- `mobileai.private-diagnostics`: private and published
- `mobileai.draft-guide`: public draft without a published version
- `mobileai.archived-guide`: public archived package

Apply it explicitly on development, staging or a production acceptance-test
catalog:

```bash
php scripts/migrate.php 005_seed_subscription_test_catalog.sql
```

Only the first two packages are visible through the public API. The remaining
fixtures verify that private, draft and archived content stays hidden.
