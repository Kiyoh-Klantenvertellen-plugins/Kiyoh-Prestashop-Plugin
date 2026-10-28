# Changelog

## 1.4.1 — 2026-10-28

### Fixed
- **Connect with Kiyoh / Klantenvertellen** no longer opens the module configuration page with "The admin token is missing or expired." The button posts back to the configure URL that is already open, including both the legacy `token` and the Symfony `_token`, and the popup is sent to `https://connect.kiyoh.com/authorize` (or `https://connect.klantenvertellen.nl/authorize` for Klantenvertellen). PrestaShop 9's `Tools::getAdminTokenLite()` returns the Symfony CSRF token, not the legacy AdminModules hash, and the previous form dropped `_token` from that URL.
- The configure URL keeps HTTPS when a tunnel sends `X-Forwarded-Proto: https,http`, and it uses the first host in `X-Forwarded-Host` instead of the whole comma-separated list.

### Changed
- Module version is 1.4.1.

## 1.4.0 — 2026-10-24

### Added
- **Connect with Kiyoh / Klantenvertellen.** Module configuration opens a popup on Kiyoh-Connect 1.2 (`kiyoh-prestashop`, a public client). Kiyoh.com uses `https://connect.kiyoh.com` (tenant 98). Klantenvertellen.nl uses `https://connect.klantenvertellen.nl` (tenant 99). Scope `integration:connect`, PKCE S256, and a per-shop `state`.
- The shop registers itself with `POST {issuer}/shops/register` before authorize. No shared HMAC key and no shared client secret. The response returns a per-shop credential once (`shop_id` and `shop_secret` in the current wire map). The module stores that pair encrypted and sends it on `/token`, refresh, `/revoke`, and `/shops/revoke`. The return address is `https://{shop-host}/module/kiyoh/oauth` (the shop subdirectory is included when PrestaShop is not installed at the domain root).
- After the popup returns `code` and `state`, the module exchanges them at `POST {issuer}/token` (PKCE S256, the exact redirect URI, and the shop credential) and reads `GET {issuer}/integration/credentials`. `locationId` and `inviteApiKey` are saved as the module Location ID and API key. `tenantId` must match the selected platform or nothing is saved.
- The shop credential and the refresh token are encrypted with AES-256-GCM. Disconnect calls `POST {issuer}/revoke` and keeps the shop credential so a later connect does not need a second secret. Uninstall calls `POST {issuer}/revoke` and `POST {issuer}/shops/revoke`, then deletes module data.
- Manual Location ID and API key remain in the form for existing installs.
- `scripts/build-release.sh` and GitHub Actions build `kiyoh-<version>.zip`. A published GitHub release attaches that zip.
- `PLUGIN_SPEC.md` and this changelog.

### Changed
- Module version is 1.4.0.

### Removed
- `kiyoh.zip` is no longer stored in git.

### Security
- The module does not contain a shared HMAC key or a shared client secret, and it does not ask the merchant for one. Connect issues a shop secret once; the module stores it encrypted in shop configuration. Wire field names are isolated in `KiyohConnect::FIELDS` until Kiyoh-Connect PR #1 reports the final names. See `PLUGIN_SPEC.md`.

## 1.3.0 — 2026-10-23

### Added
- **Kiyoh Mobile health.** The module posts `POST {url}/api/integrations/health` for each base URL in **Kiyoh Mobile URL(s)** (default `https://kiyoh-mobile.com`). Headers are `X-Publication-Api-Token` and `X-Location-Id`. Body platform is `prestashop`, with `connection`, `invites`, and `productSync` (up to 500 product codes).
- Reports are sent on configuration save, connection test, invitation result, and product sync, and again from the daily cron when the payload changed or the last accepted report is at least 24 hours old. HTTP 429 is honored. A health failure does not change checkout, orders, or sync results.
