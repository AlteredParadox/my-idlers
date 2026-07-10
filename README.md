# My idlers

A self-hosted web app for displaying, organizing and storing information about your servers (VPS/Dedi), shared &
reseller hosting, seedboxes,
domains, DNS and misc services.

Despite what the name infers this self-hosted web app isn't just for storing idling server information. By using
a [YABS](https://github.com/masonr/yet-another-bench-script) output you can get disk & network speed values along with
GeekBench 5 & 6 scores to do easier comparing and sorting. Of course storing other services e.g. web hosting is possible
and supported too with My idlers.

[![Generic badge](https://img.shields.io/badge/version-4.1.0+ap.4-blue.svg)](https://shields.io/) [![Generic badge](https://img.shields.io/badge/Laravel-13.18-red.svg)](https://shields.io/) [![Generic badge](https://img.shields.io/badge/PHP-8.4-purple.svg)](https://shields.io/) [![Generic badge](https://img.shields.io/badge/Bootstrap-5.3-pink.svg)](https://shields.io/)

<img src="https://raw.githubusercontent.com/cp6/my-idlers/main/public/My%20Idlers%20logo.jpg" width="128" height="128" />

[View demo site](https://demo.myidlers.com/)

**Note:** Create, Update and Delete are disabled on the demo site.

## Project sponsor

Proudly sponsored by [PikaPods](https://www.pikapods.com/) - Deploy your own My Idlers instance with one click, no server setup required. PikaPods handles all the infrastructure so you can focus on managing your services.

[![PikaPods](https://www.pikapods.com/static/run-button.svg)](https://www.pikapods.com/pods?run=my-idlers)

## Changes from upstream (this fork)

This is a modified fork of [cp6/my-idlers](https://github.com/cp6/my-idlers) with the following additions:

> **Versioning:** this fork publishes its releases as `4.1.0+ap.N` — the upstream base version it
> was in sync with (`4.1.0`) plus an incrementing AlteredParadox fork revision (`ap.1`, `ap.2`, …).
> This keeps fork releases distinct from any version number upstream cp6/my-idlers may later ship.
> The dated `4.0.0` / `4.1.0` sections further down are the shared baseline changelog the fork
> builds on; everything under a **Fork revision** heading is specific to this fork.

### Prometheus live monitoring (optional)

All Prometheus features are gated behind a `prometheus_enabled` toggle and a `prometheus_url` setting on the
settings page — with it disabled the app behaves like upstream.

* Live server status pulled from Prometheus (`node_uname_info`), matching instances to servers by resolving
  instance IPs to hostnames; servers not found in Prometheus are marked accordingly
* Live RAM usage, disk usage and network link utilization percentages in the servers list
* Live uptime/downtime column (downtime shown as negative values), looking back 30 days for offline nodes
* Prometheus monitoring section on the server detail view
* When Prometheus is enabled, hostname links to the server detail page and the status badge is display-only

### Pricing & service lifecycle

* One-time billing option and unlimited-bandwidth support
* One-time priced services excluded from the "due soon" table; inactive services no longer show as due soon
* Active/Inactive state handled consistently across all service types (servers, domains, shared, etc.),
  with an expires column and strikethrough styling for inactive services
* Homepage totals corrected to respect the above

### Servers

* Multiple disk support per server
* New fields: network type, link speed, CPU model, and a Transferrable flag (shown as a column in list views)
* Bandwidth shown in list views, with MB/GB/TB conversions for RAM/disk/bandwidth columns
* Status check uses ping instead of a port-80 probe

### UI

* Condensed, full-width layout with reduced padding
* Theme support and dark-mode consistency fixes across reloads/session expiry
* Sorting fixes for all columns, including inactive servers; configurable default per-page setting
* Show/Hide Stats toggle, persisted (along with the inactive toggle) via localStorage
* Font caching/preload fixes

### Tooling

* `php artisan import:servers <file> [--domain-suffix=example.com]` — CSV import command for bulk-loading servers

## Fork revision `ap.4` — July 2026

_Full web/API parity for servers, persistent per-user table customization, and the deepest
hardening pass yet: the adversarial review loop ran a further 43 rounds until it sealed at two
consecutive clean audits, alongside three cross-model review rounds and two Sonar batches —
every fix pinned by a regression test, mutation-tested, and back-tested against its parent
commit._

**Features**

* **Full REST API / web-form parity for servers** — labels and IP assignments can now be created
  and updated through the API exactly as through the forms; partial PUTs touch only the submitted
  fields, applied atomically with the same locked-read discipline as the web paths
* **Persistent table customization** — column sorting persists, and a new **Columns** menu
  shows/hides columns per table; both are stored per user in the database (new `user_preferences`
  table), so they follow you across browsers and survive redeploys
* **Standardized `Price/yr` column** — every service table can show a normalized
  price-per-year in USD alongside the native billing term
* **Database-backed sessions** — logins survive container redeploys instead of living in
  ephemeral files

**Security & data-integrity hardening**

* **Registration cap race closed** — `MAX_USERS` enforcement serializes on an atomic lock with an
  authoritative re-check inside the transaction: two concurrent sign-ups can no longer both land
  under a cap of 1, and the cap no longer depends on a cacheable settings row existing
* **The duplicate-race 500 class eliminated app-wide** — seven unique-validated write paths
  (registration, account email, the four catalog stores, note re-pointing) turned a lost
  concurrent-duplicate race into a raw database 500; all now return the normal validation error
  through one shared helper
* **Derived-column overflow rejected at validation** — a price whose USD conversion exceeded the
  column (any stronger-than-USD currency) was a MySQL 500 mid-transaction and silent corruption
  on SQLite; USD derivations are now bounds-checked on every pipeline (web, API including partial
  PUTs, import) and rounded identically on both drivers
* **Web date rules hardened to `Y-m-d`** — the bare `date` rule accepted any parseable string
  ("May 2030"), a MySQL 500 and a persistent SQLite dashboard crash once the row came due; the
  web rules now match the API's
* **YABS webhook hardened** — bodies bounded at 64 KB with strict nested validation and array
  caps; replayed deliveries (the signed URL is valid for 12 h) are idempotent, enforced by a new
  unique run index with a legacy-deduplicating migration; interrupted runs with partial fio
  output ingest instead of 500ing; and a rolled-back ingest can no longer report success
* **Atomicity and correctness sweep** — DNS creation is transactional like every other multi-row
  create; favicon replacement survives failure on either side of the filesystem/DB boundary;
  label-assignment errors no longer masquerade as duplicate suppressions; WHOIS enrichment has
  strict timeouts and tolerates sparse responses; `default_server_os` must reference a real OS
* **Browser-security headers in the container** — `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy` and a Content-Security-Policy audited against the app's actual asset origins
  (self-hosted only, no foreign script origins, `object-src 'none'`)

Test suite: **607 tests / 1,974 assertions**, green on both SQLite and MySQL.
`composer audit` and `npm audit` pass clean.

## Fork revision `ap.3` — July 2026

_Patch release for an `ap.2` container regression._

* **SQLite deployments broke under the new php-fpm stack**: PHP now runs as `www-data`
  instead of root, and `/app/database` wasn't writable by it — any save failed with
  "attempt to write a readonly database" (reads worked). The image now grants ownership,
  and bind-mounted database directories need `chown -R 82:82` on the host (see the
  Docker notes). MySQL deployments were unaffected.

## Fork revision `ap.2` — July 2026

_A full-codebase simplification and dead-code pass, another multi-round security review, and a
production-grade container — all independently reviewed (multi-agent + cross-model) with every
fix pinned by a regression test._

**Simplification & dead-code removal** (~2,800 net lines gone)

* Table-driven export layer: one section registry drives the seven per-type exports, the combined
  JSON export and the CSV ZIP; shared pricing/relation transform helpers
* Shared model traits for the session sort scope and pricing sort (was five copied `boot()`s and
  nine copied sort blocks); controllers share validation rules, cache fan-out helpers and the
  pricing/labels update tail
* Shared blade partials for the home-page tables, the DataTables init (14 index pages) and the
  server-list Prometheus/status script (both index variants, one parameterized partial)
* Removed ~45 dead files: legacy laravel/ui auth controllers (and the `laravel/ui` package),
  32 orphaned blade components, empty controllers, unused factories, dead config/scripts, and
  unused composer/npm dependencies
* Drift guard: every CSV header list is asserted per-section against its transform's actual output

**Performance**

* Dashboard summary is one aggregate query (was five full-table fetches summed in PHP)
* Prometheus offline-host resolution batched into a single query, shared by the list and detail
  pages (was one HTTP round-trip per offline node on each)
* Fixed O(n²) CSV header collection; pricing totals via SQL subqueries; dropdown pages fetch only
  the columns they render; halved currency-rate cache reads

**Security & data integrity**

* `make:database` validates identifiers against an allowlist instead of interpolating raw
  input/config into `CREATE DATABASE`
* Every write path — store, update **and destroy** for all service types — now runs in a single
  DB transaction: no partial updates and no orphaned child rows on failure
* The signed YABS API answers malformed payloads with 422 (500 only for genuine server failures);
  API note misses use the JSON 404 contract; the password-reset form no longer reveals whether an
  account exists
* `.dockerignore` keeps `.env`, VCS internals and local artifacts out of image builds;
  `APP_KEY` is now **required** at container start (fail fast — previously a redeploy without a
  persisted volume silently rotated the key, invalidating sessions and signed URLs)
* Production install docs: `--no-dev` (no Ignition/dev tooling), cached config/routes/views, a
  real web server rooted at `public/`, and `SESSION_SECURE_COOKIE` guidance for HTTPS

**Docker: nginx + php-fpm**

* The container serves via nginx + php-fpm under supervisord, replacing `php artisan serve`
  (PHP's single-threaded dev server) — same port 8000, same env vars otherwise
* Healthcheck hits a real HTTP endpoint with the correct `Host` header (the old probe was
  rejected by TrustHosts); container logs now show real client addresses via nginx

Test suite: **498 tests / 1,528 assertions**, run against both SQLite and MySQL.
`composer validate --strict`, `composer audit` and `npm audit` all pass clean.

> **Breaking change for Docker users:** the `APP_KEY` environment variable is now required —
> see [Run using Docker](#run-using-docker).

## Fork revision `ap.1` — July 2026

_This fork's first dedicated release, built on the `4.1.0` baseline below._

**Framework upgrade — Laravel 11 → 13**

* Upgraded Laravel `11.48` → `12.62` → `13.18` (staged, one major at a time), on PHP 8.4
* Upgraded PHPUnit `11` → `12`, Tinker `2` → `3`; refreshed all Composer dependencies
* Renamed the CSRF middleware `VerifyCsrfToken` → `PreventRequestForgery` (Laravel 13 rename;
  adds `Sec-Fetch-Site` origin verification on top of token checking)
* Removed the unused `yajra/laravel-datatables-oracle` package — DataTables is used client-side
  only, so it was server-side dead weight (and the sole dependency forcing a major bump each hop)
* Verified across the whole upgrade: full test suite green on both SQLite and MySQL,
  `config:cache`/`route:cache`/`view:cache`, migrations from zero, and a real file-cache
  round-trip of eager-loaded model collections (the production cache path the test suite,
  which uses the array driver, can't otherwise exercise)

**Security & data-integrity hardening**

An extensive multi-pass review (internal + cross-model) hardened every write path. Highlights:

* **Input validation parity across web forms, the REST API, and CSV import** — closed-set fields
  (server/payment terms, RAM/disk units, booleans) constrained to their real domains; a
  convertible-currency allow-list (unknown or unrated currencies are rejected rather than
  silently converted 1:1 as USD); price and capacity fields bounded to mirror the forms and
  column limits. The three surfaces now share one set of rule definitions
* **CSV import made strict** — every column is parsed from its raw string *before* casting and
  validated against the same invariants the UI enforces; a rejected row is reported and creates
  nothing (no orphaned provider/location/OS catalog rows), all inside a per-row transaction
* **XSS / request-safety fixes** — the shared delete-confirmation modal no longer renders a
  service name via `v-html` (stored-XSS vector) and its "No" button no longer submits the delete
  form; Prometheus-sourced labels are rendered via `textContent`, and the ping tool rejects
  option-style hostnames
* **MySQL-vs-SQLite correctness** — production runs MySQL, so the test suite now runs against
  both engines in CI; this surfaced and fixed a class of bugs invisible under SQLite
  (string-vs-int columns, changed-vs-matched row counts, strict-mode truncation/overflow)
* **Cache-invalidation, null-safety and API-consistency fixes** throughout — stale caches after
  edits, 500s on missing/legacy-orphan records, and catalog endpoints returning `200 []` for
  missing IDs are all resolved
* **Modern `yabs.sh` compatibility** — Geekbench 6-only runs, auto-skipped fio/iperf blocks,
  numeric uptime, and 128+ thread machines all ingest and display correctly

---

# Baseline changelog (`4.1.0`, shared with upstream cp6/my-idlers)

_The history below is the `4.1.0` baseline the fork's `+ap` revisions build on._

## 4.1.0 changes (February 2026):

* Added data export feature for all service types (servers, domains, shared, reseller, seedboxes, DNS, misc)
* Export data in JSON or CSV format
* Added export buttons to all index pages
* Added global export section to settings page for exporting all data at once
* Global CSV export creates a ZIP file with separate CSV files per service type
* Added new API endpoints for data export:
  - `GET /api/export/servers?format=json|csv`
  - `GET /api/export/domains?format=json|csv`
  - `GET /api/export/shared?format=json|csv`
  - `GET /api/export/reseller?format=json|csv`
  - `GET /api/export/seedboxes?format=json|csv`
  - `GET /api/export/dns?format=json|csv`
  - `GET /api/export/misc?format=json|csv`
  - `GET /api/export/all?format=json|csv`
* Added ExportService for centralized export logic
* Added comprehensive test suite for export functionality
* Modernized confirm-password and verify-email auth pages
* Redesigned error pages (401, 403, 404, 500, 503) with card layout and light/dark mode support

## 4.0.0 changes (February 2026):

* Updated Laravel version to 11.48
* Updated Bootstrap version to 5.3.8
* Updated all composer package versions
* Updated all npm package versions
* Fixed security vulnerabilities in dependencies
* Added comprehensive seeders for Users, Servers, Shared, Reseller, Domains, Misc and DNS
* Added YABS seeder with sample benchmark data
* Updated Dockerfile to PHP 8.4 with additional extensions (bcmath, pcntl)
* Improved Dockerfile with production optimizations and health check
* Improved run.sh with better env defaults, caching and optional auto-migrate
* Modernized auth pages (login, register, forgot/reset password) with clean design
* Added light and dark mode support for auth pages
* Added `MAX_USERS` env variable to control registration limit (0 = unlimited)
* Redesigned dashboard with cleaner stat cards, costs and resources sections
* Modernized navbar with improved styling and hover states
* Updated dark mode with new color scheme and page background
* Modernized all index pages with consistent card-based layout and improved table styling
* Improved DataTables styling (search, pagination, dropdown) for light and dark modes
* Fixed DataTables error alerts on empty tables
* Made demo user and sample data seeding optional via `SEED_DEMO_DATA` env variable
* Redesigned all create and edit pages with organized card sections and consistent form styling
* Redesigned all show/detail pages with polished detail cards and visual hierarchy
* Modernized settings page with organized card-based sections
* Modernized account page with profile and API token sections
* Modernized server and YABS comparison pages with improved table styling
* Modernized public server listing page with consistent design
* Added comprehensive test suite with 150 tests covering all major features
* Added servers index card view option in settings (table or cards layout)
* Fixed Vue.js loading issues on comparison pages
* Fixed server comparison null value handling
* Fixed inactive services still being counted in cost calculations and "Due Soon" ([#125](https://github.com/cp6/my-idlers/issues/125), [#112](https://github.com/cp6/my-idlers/issues/112))
* Fixed OS icons showing wrong icons for certain OS IDs ([#123](https://github.com/cp6/my-idlers/pull/123))
* Removed unused legacy auth pages and components

### Test Suite

The application includes a comprehensive test suite — 607 tests / 1,974 assertions as of ap.4 —
run against **both SQLite and MySQL** (production is MySQL, and several bug classes are invisible
under SQLite). Every hardening fix is pinned by a dedicated regression test.

**Feature Tests:**
- Authentication (login, registration, password reset, email verification)
- Servers CRUD operations and validation
- Domains CRUD operations and validation
- DNS records CRUD operations and validation
- Providers, Locations, Labels, OS CRUD operations
- IP addresses CRUD operations with HTTP mocking
- Home dashboard functionality

**Unit Tests:**
- Server model (server types, comparison logic)
- Pricing model (cost calculations, term conversions)
- Settings model (sorting/ordering logic)
- Labels model (assignment operations)
- IPs model (IPv4/IPv6 handling)
- DNS model (type constants)

Run tests with:
```shell
vendor/bin/phpunit
# or with testdox output
vendor/bin/phpunit --testdox
```

### Database Seeding

The seeder is split into two parts:

**Core data (always seeded):** Settings, Providers, Locations, OS, Labels - required for the app to function.

**Demo data (optional):** Admin user and sample services (servers, domains, shared hosting, etc.)

```shell
# Fresh install (core data only - recommended for production)
php artisan migrate:fresh --seed

# Fresh install with demo data
# First add SEED_DEMO_DATA=true to your .env file, then:
php artisan migrate:fresh --seed
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SEED_DEMO_DATA` | `false` | Set to `true` to seed demo user and sample data during `migrate:fresh --seed` |
| `MAX_USERS` | `1` | Registration closes once this many users exist. `0` = unlimited — every account has full access to all stored data, so only raise this deliberately |

### Demo Login Credentials

When `SEED_DEMO_DATA=true` is set, a demo user is created:

| Email | Password |
|-------|----------|
| admin@admin.com | password |

#### Please run the following if updating from an existing install:

```shell
composer update
php artisan migrate
php artisan route:cache
php artisan cache:clear
```

## Requires

* PHP 8.4

## Features

* Add and manage servers, shared hosting, reseller hosting, seedboxes, domains, DNS and misc services
* [Auto get IP's from hostname](https://cdn.write.corbpie.com/wp-content/uploads/2021/01/my-idlers-self-hosted-server-domain-information-ips-from-hostname.gif)
* [Check up/down status](https://cdn.write.corbpie.com/wp-content/uploads/2021/01/my-idlers-self-hosted-server-domain-information-ping-up-feature.gif)
* Get YABS data from output or POST directly from yabs.sh
* Compare servers and YABS benchmarks
* Save & view YABS output with disk and network speeds
* Export data in JSON or CSV format
* Light and dark mode themes
* Next due date tracking with dashboard overview
* Multi currency and payment term support
* Pre-defined operating systems with icons
* Assign labels to services
* Assign server type (KVM, OVZ, LXC & dedi)
* Assign notes to any service
* Public server listing option
* REST API for all resources
* Registration limit control (MAX_USERS)

## Install (development)

* Run `git clone https://github.com/cp6/my-idlers.git` into your directory of choice
* Run `composer install`
* Run `npm install`
* Run `cp .env.example .env`
* Edit MySQL details and other settings in .env
* Run `php artisan key:generate`
* Run `php artisan make:database my_idlers` to create database
* Run `npm run prod` to build assets
* Run `php artisan migrate:fresh --seed` to create tables and seed data
* Run `php artisan serve`

## Install (production, non-Docker)

The steps above are for development: a plain `composer install` also installs dev tooling
(Ignition and its `_ignition/*` routes), and `php artisan serve` is PHP's single-threaded dev
server. For a production host, instead:

* Run `composer install --no-dev --optimize-autoloader`
* In `.env`: set `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_SECURE_COOKIE=true`
  when the app is served over HTTPS (recommended)
* Serve the `public/` directory with a real web server (nginx/Apache/Caddy + PHP-FPM) —
  never expose the repository root
* Run `php artisan config:cache && php artisan route:cache && php artisan view:cache`
  (re-run after any .env change)

## Updating

If you already have at least version 2.0 installed:

* Run `git pull`
* Run `composer install`
* Run `composer update`
* Run `npm install`
* Run `npm run prod`
* Run `php artisan migrate`
* Run `php artisan route:cache`
* Run `php artisan cache:clear`

## Run using Docker

```
docker run \
  -p 8000:8000 \
  --restart unless-stopped \
  -e APP_KEY=base64:... \
  -e APP_URL=https://... \
  -e TRUSTED_PROXIES='*' \
  -e DB_HOST=... \
  -e DB_DATABASE=... \
  -e DB_USERNAME=... \
  -e DB_PASSWORD=... \
  -e MAX_USERS=1 \
  -e SEED_DEMO_DATA=false \
  -e SESSION_SECURE_COOKIE=true \
  -e AUTO_MIGRATE=true \
  ghcr.io/alteredparadox/my-idlers:latest
docker exec -u www-data ... php artisan migrate:fresh --seed --force  # Set up database one time
```

Keep `AUTO_MIGRATE=true` set: new releases can add tables (e.g. `sessions`, `user_preferences`),
and without it an upgraded container serves 500s on every page — including `/login` and the
container healthcheck — until the migrations are run. It is a no-op when there is nothing to
migrate. The one-time setup command must run as `www-data` (`-u www-data`): run as root on a
SQLite setup it creates a root-owned database file that the php-fpm workers cannot write, taking
the app down until the next container restart re-asserts ownership.

The container serves the app with nginx + php-fpm (supervised) on port 8000.

`APP_KEY` is required and must stay the same across redeploys — rotating it invalidates
sessions, signed URLs and encrypted data. Generate one once and keep it with your other
secrets:

```
echo "base64:$(openssl rand -base64 32)"
# or, via the image (the entrypoint must be bypassed):
docker run --rm --entrypoint php ghcr.io/alteredparadox/my-idlers:latest artisan key:generate --show
```

Images are published to GitHub Container Registry on each tagged release:
`ghcr.io/alteredparadox/my-idlers:latest` (or a pinned revision, e.g.
`ghcr.io/alteredparadox/my-idlers:4.1.0-ap.4` — note the Docker tag uses `-ap.4` since `+` is not
a valid Docker tag character).

Notes:

* `APP_URL` must exactly match the scheme and hostname you use to reach the app — requests
  with any other `Host` header are rejected in production (TrustHosts). An `https://` APP_URL
  also switches all generated URLs to https; plain-HTTP LAN installs keep `http://` and work
  without TLS.
* `TRUSTED_PROXIES` is required when TLS terminates at a reverse proxy in front of the
  container (`*` to trust any upstream, or a comma-separated list of proxy IPs/CIDRs).
  Without it, signed YABS URLs fail validation because the app sees requests as plain http.
* `SESSION_SECURE_COOKIE=true` keeps the session cookie HTTPS-only — set it whenever the
  app is reached over HTTPS (drop it only for plain-HTTP LAN setups, where the cookie
  would otherwise never be sent).
* Sessions are stored in the database (SQLite or MySQL, whichever the install uses), so
  logins and per-user view preferences survive container redeploys. Only ephemeral,
  rebuildable state (the file cache and compiled views) remains on the container's disk.
* **SQLite setups** (`DB_CONNECTION=sqlite`): PHP runs as `www-data` (uid 82) since the
  nginx+php-fpm switch, so a bind-mounted database directory must be writable by that
  uid — `chown -R 82:82` the mounted directory (SQLite writes journal files next to the
  db, so the directory itself needs write access, not just the file).
* Custom favicons are stored in the container's webroot, which is ephemeral by design —
  re-upload the favicon after pulling a new image (everything else lives in the database
  and carries over).

## Managed Hosting

Run with a single click on [PikaPods.com](https://www.pikapods.com/)

[![PikaPods](https://www.pikapods.com/static/run-button.svg)](https://www.pikapods.com/pods?run=my-idlers)

## Adding a YABS benchmark

yabs.sh now has JSON formatted response and can POST the output directly from calling the script.

With My idlers you can use the signed YABS URL shown on a server details page to directly POST the benchmark result.

The signed URL is scoped to one server and expires.

Example yabs.sh call to POST the result:

`curl -sL yabs.sh | bash -s -- -s "https://yourdomain.com/api/yabs/SERVERID?expires=...&signature=..."`

If the instance is not reachable from the benchmarked server (private/LAN-only deployments),
use **Add YABS** on the YABS page instead: run `curl -sL yabs.sh | bash -s -- -j` on the
server and paste the JSON it prints.

## Credits

IP who is data provided by [ipwhois.io](https://ipwhois.io/documentation)

## API endpoints

For GET requests the header must have `Accept: application/json` and your API token. Tokens are generated from `/account` and are shown once after rotation.

`Authorization : Bearer API_TOKEN_HERE`

All API requests must be appended with `api/` e.g `mydomain.com/api/servers/gYk8J0a7`

**GET requests:**

| Endpoint | Description |
|----------|-------------|
| `dns/` | Get all DNS records |
| `dns/{id}` | Get DNS record |
| `domains/` | Get all domains |
| `domains/{id}` | Get domain |
| `servers` | Get all servers |
| `servers/{id}` | Get server |
| `IPs/` | Get all IPs |
| `IPs/{id}` | Get IP |
| `labels/` | Get all labels |
| `labels/{id}` | Get label |
| `locations/` | Get all locations |
| `locations/{id}` | Get location |
| `misc/` | Get all misc services |
| `misc/{id}` | Get misc service |
| `networkSpeeds/` | Get all network speeds |
| `networkSpeeds/{id}` | Get network speed |
| `os/` | Get all operating systems |
| `os/{id}` | Get operating system |
| `pricing/` | Get all pricing |
| `pricing/{id}` | Get pricing |
| `providers/` | Get all providers |
| `providers/{id}` | Get provider |
| `reseller/` | Get all reseller hosting |
| `reseller/{id}` | Get reseller hosting |
| `seedbox/` | Get all seedboxes |
| `seedbox/{id}` | Get seedbox |
| `settings/` | Get settings |
| `shared/` | Get all shared hosting |
| `shared/{id}` | Get shared hosting |
| `yabs/` | Get all YABS |
| `yabs/{id}` | Get YABS |
| `note/{id}` | Get note |
| `online/{hostname}` | Check if host is up |
| `dns/{domainName}/{type}` | Get IP for domain |

**Export endpoints (v4.1):**

| Endpoint | Description |
|----------|-------------|
| `export/servers?format=json\|csv` | Export servers |
| `export/domains?format=json\|csv` | Export domains |
| `export/shared?format=json\|csv` | Export shared hosting |
| `export/reseller?format=json\|csv` | Export reseller hosting |
| `export/seedboxes?format=json\|csv` | Export seedboxes |
| `export/dns?format=json\|csv` | Export DNS records |
| `export/misc?format=json\|csv` | Export misc services |
| `export/all?format=json\|csv` | Export all data |

**POST requests**

Create a server

`/servers`

Body content template

```json
{
    "active": 1,
    "show_public": 0,
    "hostname": "test.domain.com",
    "ns1": "ns1",
    "ns2": "ns2",
    "server_type": 1,
    "os_id": 2,
    "provider_id": 10,
    "location_id": 15,
    "ssh_port": 22,
    "bandwidth": 2000,
    "ram": 2024,
    "ram_type": "MB",
    "disk": 30,
    "disk_type": "GB",
    "cpu": 2,
    "cpu_model": "EPYC 7402P",
    "disk_media": "NVMe",
    "link_speed": 1,
    "link_speed_type": "Gbps",
    "network_type": "IPv4+IPv6",
    "labels": ["labelID1", "labelID2"],
    "was_promo": 1,
    "ip1": "127.0.0.1",
    "ip2": null,
    "owned_since": "2022-01-01",
    "currency": "USD",
    "price": 4.00,
    "payment_term": 1,
    "next_due_date": "2022-02-01"
}
```

Validation notes (as of ap.1):

* `ram_as_mb` / `disk_as_gb` / `as_usd` / `usd_per_month` are **derived server-side** from
  `ram`/`ram_type`, `disk`/`disk_type` and `price`/`currency` — don't send them (any supplied
  value is ignored so the stored figures can't contradict their source fields)
* `server_type` and `payment_term` must be `1`–`7`; `ram_type` is `MB`/`GB`, `disk_type` is
  `GB`/`TB`; `active`/`show_public`/`was_promo`/`transferrable` are `0`/`1`
* `currency` must be a currently-convertible code; `price` and capacity fields must be `>= 0`

Web-form parity fields (as of ap.4, all optional on POST and PUT):

* `link_speed` is a value + `link_speed_type` (`Mbps`/`Gbps`) pair — stored as Mbps; sending a
  speed without its unit is rejected
* `network_type` is one of `IPv4`, `IPv6`, `IPv4+IPv6`, `IPv4 NAT`, `IPv4 NAT + IPv6`
* `disk_media` is `SSD`/`HDD`/`NVMe` (defaults to `SSD` on create)
* `labels` is an array of up to 4 existing label IDs (see `GET labels/`) — when sent on PUT it
  **replaces** the assignments (`[]` clears them, absent leaves them untouched)

**PUT requests**

Update a server

`/servers/ID`

Updates are partial: send only the fields you want to change. Body content template

```json
{
    "active": 1,
    "show_public": 0,
    "hostname": "test.domain.com",
    "ns1": "ns1",
    "ns2": "ns2",
    "server_type": 1,
    "os_id": 2,
    "provider_id": 10,
    "location_id": 15,
    "ssh_port": 22,
    "bandwidth": 2000,
    "ram": 2024,
    "ram_type": "MB",
    "disk": 30,
    "disk_type": "GB",
    "disk_media": "SSD",
    "cpu": 2,
    "cpu_model": "EPYC 7402P",
    "link_speed": 1,
    "link_speed_type": "Gbps",
    "network_type": "IPv4+IPv6",
    "labels": ["labelID1", "labelID2"],
    "ips": ["127.0.0.1", "2001:db8::1"],
    "was_promo": 1,
    "owned_since": "2022-01-01"
}
```

`ips` replaces the server's full IP set like the web edit form: addresses already assigned keep
their row (whois data and notes survive), removed addresses are deleted, `[]` clears all IPs and
an absent key leaves them untouched.

Update pricing

`/pricing/ID`

Body content template

```json
{
    "price": 10.50,
    "currency": "USD",
    "term": 1
}
```

**DELETE requests**

Delete a server

`/servers/ID`

## Notes

**Public viewable listings**

If enabled the public viewable table for your server listings is at `/servers/public`
You can configure what you want viewable at ```/settings```

**Due date / due soon**

This is simply just a reminder. If the homepage is requested (viewed) when a service is over due date it will get reset
to plus the term from the old due date.

E.g if the term is a month then the due date gets updated to be 1 month from the old due date.

**Supporting YABS commands:**

```curl -sL yabs.sh | bash```

or

```curl -sL yabs.sh | bash -s -- -r```

Logo icons created by Freepik - Flaticon
