# My idlers

A self-hosted web app for displaying, organizing and storing information about your servers (VPS/Dedi), shared &
reseller hosting, seedboxes,
domains, DNS and misc services.

Despite what the name infers this self-hosted web app isn't just for storing idling server information. By using
a [YABS](https://github.com/masonr/yet-another-bench-script) output you can get disk & network speed values along with
GeekBench 5 & 6 scores to do easier comparing and sorting. Of course storing other services e.g. web hosting is possible
and supported too with My idlers.

[![Generic badge](https://img.shields.io/badge/version-4.1.0+ap.16-blue.svg)](https://shields.io/) [![Generic badge](https://img.shields.io/badge/Laravel-13.33-red.svg)](https://shields.io/) [![Generic badge](https://img.shields.io/badge/PHP-8.5-purple.svg)](https://shields.io/) [![Generic badge](https://img.shields.io/badge/Bootstrap-5.3-pink.svg)](https://shields.io/)

**Release notes:** [CHANGELOG.md](CHANGELOG.md) — every fork revision, newest first. Breaking and behaviour changes are summarised in [Notable and breaking changes](#notable-and-breaking-changes) below.

## Changes from upstream (this fork)

This is a modified fork of [cp6/my-idlers](https://github.com/cp6/my-idlers) with the following additions:

> **Versioning:** this fork publishes its releases as `4.1.0+ap.N` — the upstream base version it
> was in sync with (`4.1.0`) plus an incrementing AlteredParadox fork revision (`ap.1`, `ap.2`, …).
> This keeps fork releases distinct from any version number upstream cp6/my-idlers may later ship.
> The release notes for every fork revision, and upstream's dated `4.0.0` / `4.1.0` baseline
> changelog the fork builds on, are in [CHANGELOG.md](CHANGELOG.md).

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

## Notable and breaking changes

The full release notes live in [CHANGELOG.md](CHANGELOG.md). This list carries only what changes
behaviour, the schema or a deployment default for someone upgrading — every release's
**Upgrading?** callout, newest first. If a release has none, it is not here.

* **[ap.16](CHANGELOG.md#fork-revision-ap16--september-2026)** — New sessions are budgeted per client address (60 a minute; a
  browser keeping its cookie is exempt), and behind a reverse proxy the budget is shared unless
  `TRUSTED_PROXIES` is set. Authenticated pages send `Cache-Control: no-store`. The Prometheus
  charts work in the container again (ApexCharts is bundled; the CSP had been blocking the CDN
  copy). **Repository history was rewritten on 2026-09-26** to purge a committed inventory file:
  every commit after July and every release tag has a new hash, so re-clone or reset local branches
* **[ap.15](CHANGELOG.md#fork-revision-ap15--september-2026)** — php-fpm binds `127.0.0.1:9000` only; anything outside the
  container that spoke FastCGI to port 9000 stops working. `/app/database` is `root:www-data` mode
  `1770`; the entrypoint re-applies it on boot, so existing SQLite bind mounts need no action
* **[ap.12](CHANGELOG.md#fork-revision-ap12--july-2026)** — Adds a migration (the `jobs` and `failed_jobs` tables behind the
  queue worker). `AUTO_MIGRATE=true` applies it; without it the container refuses to start
* **[ap.10](CHANGELOG.md#fork-revision-ap10--july-2026)** — API tokens are read from the `Authorization: Bearer` header
  only; `?api_token=` is gone. For forks: Vue is removed and the delete-confirm modal is a component
  with a `uri` prop
* **[ap.7](CHANGELOG.md#fork-revision-ap7--july-2026)** — For forks: Vite replaces webpack, the per-theme stylesheets become
  one variable-driven `theme.css`, DataTables 2 renames its wrapper classes, and the base image is
  `php:8.5-fpm-alpine`. No behaviour or database change
* **[ap.2](CHANGELOG.md#fork-revision-ap2--july-2026)** — `APP_KEY` is required for Docker; generating one at startup silently
  rotated it on every redeploy

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
  -e TRUSTED_PROXIES=172.16.0.0/12 \
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
`ghcr.io/alteredparadox/my-idlers:4.1.0-ap.16` — note the Docker tag uses `-ap.16` since `+` is not
a valid Docker tag character).

Notes:

* `APP_URL` must exactly match the scheme and hostname you use to reach the app — requests
  with any other `Host` header are rejected in production (TrustHosts). An `https://` APP_URL
  also switches all generated URLs to https; plain-HTTP LAN installs keep `http://` and work
  without TLS.
* `TRUSTED_PROXIES` is required when TLS terminates at a reverse proxy in front of the
  container. Without it, signed YABS URLs fail validation because the app sees requests as
  plain http. **Set it to your proxy's IP or CIDR**, not `*` — the example above uses
  Docker's default bridge range. `*` tells Laravel to believe the `X-Forwarded-*` headers
  on *every* request, so anyone who can reach the container port directly (a published
  port, another container on the same network, the LAN) can choose the client IP the app
  records and rate-limits by, and the scheme and host it builds URLs from. Only use `*`
  when nothing but the proxy can reach the container.
* `SESSION_SECURE_COOKIE=true` keeps the session cookie HTTPS-only — set it whenever the
  app is reached over HTTPS (drop it only for plain-HTTP LAN setups, where the cookie
  would otherwise never be sent).
* Sessions are stored in the database (SQLite or MySQL, whichever the install uses), so
  logins and per-user view preferences survive container redeploys. Only ephemeral,
  rebuildable state (the file cache and compiled views) remains on the container's disk.
  Every request that arrives without a session cookie creates a session row, so their
  creation is budgeted per client address (60 a minute); a browser that keeps its cookie is
  exempt. Behind a reverse proxy that budget is shared by everyone unless `TRUSTED_PROXIES`
  is set, which is one more reason to set it.
* **SQLite setups** (`DB_CONNECTION=sqlite`): PHP runs as `www-data` (uid 82) since the
  nginx+php-fpm switch, so a bind-mounted database directory must be writable by that
  uid. Keep the directory owned by root with a sticky, group-writable mode, then give
  only its top-level files to PHP: `chown root:82 database && chmod 1770 database &&
  find database -maxdepth 1 -type f -exec chown 82:82 {} +`. SQLite can then create
  journal files without making the migration source replaceable by the web worker.
* Custom favicons are stored in the container's webroot, which is ephemeral by design —
  re-upload the favicon after pulling a new image (everything else lives in the database
  and carries over).

## Adding a YABS benchmark

yabs.sh now has JSON formatted response and can POST the output directly from calling the script.

With My idlers you can use the signed YABS URL shown on a server details page to directly POST the benchmark result.

The signed URL is scoped to one server and expires.

Example yabs.sh call to POST the result:

`curl -sL https://yabs.sh | bash -s -- -s "https://yourdomain.com/api/yabs/SERVERID?expires=...&signature=..."`

If the instance is not reachable from the benchmarked server (private/LAN-only deployments),
use **Add YABS** on the YABS page instead: run `curl -sL https://yabs.sh | bash -s -- -j` on the
server and paste the JSON it prints.

## Credits

IP who is data provided by [ipwhois.io](https://ipwhois.io/documentation)

## API endpoints

For GET requests the header must have `Accept: application/json` and your API token. Tokens are generated from `/account` and are shown once after rotation.

`Authorization : Bearer API_TOKEN_HERE`

The token is read from that header **only**. It is not accepted as an `?api_token=` query
parameter, a request-body field or a basic-auth password: a credential in a URL ends up in
access logs, `Referer` headers and browser history, and it would let a plain browser
navigation authenticate an API route.

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

```curl -sL https://yabs.sh | bash```

or

```curl -sL https://yabs.sh | bash -s -- -r```

Logo icons created by Freepik - Flaticon
