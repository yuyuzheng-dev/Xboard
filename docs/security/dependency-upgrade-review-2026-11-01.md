# Security dependency and runtime upgrade review — 2026-11-01

Baseline: `yuyuzheng-dev/Xboard` `master` commit `6a18ddb447f816651583cac2ac7630025218ef21`, with registration-OTP hardening subsequently merged as PR #6.

## Important distinction

A clean `composer audit --locked` proves only that the resolved Composer packages have no **known advisories reported by the audit provider at that time**. It does **not** assess the PHP/Swoole image, Alpine OS packages, Redis, Caddy, nginx, Docker host, custom plugins, or vulnerabilities that do not yet have advisories. Never infer production safety from dependency ages alone.

| Component / constraint | Locked / configured version | Assessment and recommendation |
|---|---|---|
| PHP runtime / Docker | `phpswoole/swoole:php8.2-alpine`, runtime assertion **>=8.2.35** | **High operational priority.** PHP 8.2 gets security fixes only through **2026-12-31** (php.net). Maintain >=8.2.35 immediately, then test a PHP **8.3** (or 8.4) Swoole image with equivalent extensions. This PR only adds PHP 8.3 Composer/PHPUnit CI, **not** a production runtime switch. |
| Laravel framework | **12.70.1** / `^12.70.1` | Currently within Laravel 12 security support through **2027-02-24** (Laravel). Do not jump to 13 without integration tests for Octane, Horizon, middleware and custom service providers. Plan a separate upgrade. |
| Symfony Mime | **7.4.22** | Already patched for CVE-2026-45070 in prior PR #5. Keep current. |
| Symfony HTTP Foundation / YAML / HTTP Client | **7.4.21** / 7.4.x | Current 7.4 patch line; let Composer audit and update jobs track patched 7.4 releases. |
| Guzzle | **7.16.0** | Reasonably current; update within 7.x after regression checks, not a forced major bump. |
| Stripe SDK | **7.128.0** (`stripe/stripe-php:^7.36.1`; release 2022) | **Compatibility debt.** Current SDK major is 22 (Oct 2026). Changes in API objects, webhooks and API version defaults can break payment processing; do **not** upgrade production in this PR. Audit whether Stripe is actually used by any enabled payment plugin; if used, port gateway adapters in a separate PR and test sandbox payments, 3DS, webhook signatures, currencies, refunds, idempotency and retry. If unused, consider removing instead. |
| php-curl-class | **8.10.0** (`^8.6`; release 2021) | **Aged library**, newer major 13 available. Search actual call sites and API differences first; don't blindly upgrade as it may break plugin HTTP behavior. If unused, remove. |
| Laravel Octane / Workerman | Octane **2.11.0**, Workerman **5.2.3** | Check extension/API compatibility on PHP 8.3 or PHP 8.4 before replacing the Swoole base. Release 2.11.0 of Octane dates 2025 but is not by itself proof of an unpatched CVE. |
| Horizon / Sanctum | Horizon **5.50.1**, Sanctum **4.3.3** | Remain on compatible supported major versions. Any framework major upgrade must also check these. |
| Docker registry images | Redis: `redis:8-alpine` copied into single image; split compose: `redis:7-alpine`; Caddy `caddy:2-alpine`; MySQL CI `mysql:8.0` | **Supply-chain drift** from floating major tags / apk repositories. Pin image **digests** or vetted patch tags after testing architecture, persistence and Redis RDB format. Upgrade split Redis 7->8 only after backup and restoration test; do not directly overwrite existing Redis data. |
| Docker binary provenance | Dockerfile re-clones a GitHub repo during build | CI previously passed `REPO_URL` and branch name, which pointed at the fork, but a force-pushed branch or concurrent changes could produce an image not matching the reviewed source. This PR pins and verifies the SHA supplied by GitHub Actions; Dockerfile default source also points at the fork. |
| Frontend package.json | `chokidar:^4.0.3`; no frontend lockfile at root | Small root dependency set. Avoid unpinned `npm install` in production and check whether the package is necessary to runtime; any independently built Admin distribution has its **own** supply-chain and copyright audit. |
| Composer dev dependency | Debugbar **3.16.5** | Docker `composer install --no-dev` excludes it. Ensure production `APP_DEBUG=false` and that dev tools are not unintentionally enabled or shipped. |

## Runtime migration plan (do not skip production smoke tests)

1. Back up database, Redis persistence, `.env`, plugin configs and current container image digest. Confirm the PHP CLI/Web server/Octane/Horizon versions all match expectations.
2. Rebuild **staging** on vetted PHP 8.3 Swoole image + matching extensions (pdo_mysql, redis, swoole, igbinary, pcntl, etc.). Run `composer validate --no-check-publish`, `composer audit --locked`, `composer check-platform-reqs`, and full tests against SQLite and MySQL.
3. Start Octane, Horizon, Redis, Caddy, WebSocket, queue and scheduler in a realistic staging environment. Verify login, sign up, reset email, client subscription, plan purchase, zero-payment discount, paid gateway callback, plugins and Telegram (if enabled).
4. Test real gateway sandbox callbacks and the actual Docker multi-arch build; PHP PHPUnit success **does not** mean the Swoole image will boot.
5. Roll out on a maintenance window, keep the old image for rollback, and monitor exceptions, payment failures, queues, Redis, active sessions and webhook responses.

Sources:
- https://www.php.net/supported-versions.php
- https://laravel.com/docs/12.x/releases
- https://github.com/stripe/stripe-php/releases
- https://github.com/stripe/stripe-php/wiki
- https://packagist.org/packages/php-curl-class/php-curl-class
