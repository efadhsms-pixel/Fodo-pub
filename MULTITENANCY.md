# OpenCart Multi-Tenancy

This adds **multi-tenancy** to OpenCart 3.0.2.0 using a **shared database /
shared schema** model. Every tenant (shop) is identified by a **sub-domain**
(`shop1.example.com`) and its data is isolated by a `tenant_id` discriminator
column added to every tenant-scoped table.

> Status: **Phase 1 — foundation.** Tenant resolution, schema migration, and
> transparent query scoping are in place. See *Limitations* for what still
> needs hardening before production use.

## How it works

```
request  ->  index.php / admin/index.php
         ->  system/startup.php  ->  system/framework.php
              | creates core DB
              v
         system/multitenant/bootstrap.php
              1. Tenant resolves the sub-domain -> tenant_id   (system/multitenant/tenant.php)
              2. publishes registry 'tenant' + TENANT_ID
              3. strict check: unknown sub-domain -> "Store not found"
              4. wraps registry 'db' in TenantDB                (system/multitenant/tenantdb.php)
              v
         all models query through TenantDB, which rewrites SQL
         so reads/writes touch only the current tenant's rows.
```

- **Storefront** (`catalog`) always requires a resolved tenant.
- **Bare base domain** (`example.com`) is the *platform context*: the DB is
  **not** scoped and is reserved for a future super-admin / landing page.
- Tables **not** listed as tenant-scoped (e.g. `country`, `zone`, `language`,
  `session`, and `tenant` itself) are shared reference data and never rewritten.

## Files

| File | Purpose |
|------|---------|
| `system/multitenant/config.php` | Base domain, reserved sub-domains, and the list of tenant-scoped tables. |
| `system/multitenant/tenant.php` | Resolves the current tenant from the request host. |
| `system/multitenant/tenantdb.php` | DB decorator that rewrites SQL to scope it by `tenant_id`. |
| `system/multitenant/bootstrap.php` | Wires the above into `system/framework.php`. |
| `system/multitenant/migrate.php` | CLI migration: creates `tenant` table, adds `tenant_id` columns. |
| `system/multitenant/tenantcache.php` | Cache decorator that namespaces keys per tenant. |
| `system/multitenant/tests/rewrite_test.php` | Unit tests for the SQL rewriter. |
| `system/framework.php` | Calls `multitenant_bootstrap()` / `multitenant_scope_cache()`. |
| `platform/` | Super-admin console for managing tenants (login, CRUD). |

## Setup

1. **Install OpenCart normally** (so `config.php` / `admin/config.php` exist and
   the database is populated).

2. **Set the base domain.** Edit `system/multitenant/config.php`
   (`'base_domain'`) or export `MT_BASE_DOMAIN`:

   ```bash
   export MT_BASE_DOMAIN=example.com
   ```

3. **Run the migration** to add the schema and seed the first tenant:

   ```bash
   php system/multitenant/migrate.php --default-tenant=1 \
       --seed-subdomain=shop1 --seed-name="My First Shop"
   ```

   - Existing rows are assigned to `--default-tenant` (default `1`).
   - Re-running is safe (idempotent).

4. **Point DNS / vhost** for `*.example.com` at the OpenCart document root so
   every sub-domain hits the same code base.

5. Visit `https://shop1.example.com` — it now resolves to the seeded tenant and
   serves only that tenant's data.

## Adding a tenant

### Option A — Platform console (recommended)

A self-contained super-admin console lives in `platform/` and runs on the base
domain (`https://example.com/platform/`). Set it up once:

```bash
php platform/setup.php <username> <password>
```

Then sign in to create, edit, enable/disable, and delete tenants from the UI.
The console is CSRF-protected, uses a hashed password, talks to the DB via
prepared statements, and (by default) only answers on the base domain.

`platform/config.php` holds the hashed credentials and is git-ignored.

### Option B — SQL

Insert a row in the `tenant` table (replace `oc_` with your prefix):

```sql
INSERT INTO oc_tenant SET subdomain = 'shop2', name = 'Second Shop',
    status = 1, date_added = NOW();
```

New tenants share the migrated schema; their data is created the first time
their admin configures the shop. Uploaded images are isolated per tenant under
`image/catalog/t<tenant_id>/`.

## Running the tests

```bash
php system/multitenant/tests/rewrite_test.php
```

## Limitations (Phase 2 backlog)

The SQL rewriter handles OpenCart's common patterns reliably:

- ✅ `INSERT ... SET` and `INSERT ... (cols) VALUES (...)` (single row)
- ✅ `UPDATE` / `DELETE` (predicate added to / created in `WHERE`)
- ✅ `SELECT` scoped on its **primary** `FROM` table (alias-aware), with
  trailing `GROUP BY` / `ORDER BY` / `LIMIT` and sub-queries preserved.

Done in later phases:

- ✅ **Multi-table SELECT reads** — each scoped table joined with `JOIN ... ON`
  now gets the tenant predicate added to its ON clause (LEFT/INNER-safe), in
  addition to the primary FROM table.
- ✅ **Per-tenant cache** — `TenantCache` namespaces every cache key by tenant
  id, preventing catalog cache bleed across drivers.
- ✅ **Per-tenant image storage** — uploads live under
  `image/catalog/t<tenant_id>/`; the admin file manager is confined to it.
- ✅ **Super-admin console** — `platform/` for tenant CRUD.

Still to harden before production:

1. **Multi-row `INSERT ... VALUES (...),(...)`** is not rewritten (logged
   instead). Convert such inserts or extend the rewriter.
2. **Comma joins / `USING(...)`** in SELECTs are not auto-scoped (logged); the
   common `JOIN ... ON` form is. Audit any comma-style joins.
3. **Sessions & cookies.** Sub-domain cookies are host-scoped by default;
   confirm session isolation for your deployment.
4. **`DIR_UPLOAD` (product download files)** uses hashed filenames and the
   `upload` table is tenant-scoped; physical partitioning is optional.

Anything the rewriter cannot confidently handle is **passed through unchanged
and written to the error log** (prefixed `Multi-Tenant:`) so it can be found
and addressed.
