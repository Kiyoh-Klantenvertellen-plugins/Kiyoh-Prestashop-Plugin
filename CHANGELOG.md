# Changelog

## 1.4.0 — 2026-10-24

### Added
- **Connect with Kiyoh / Klantenvertellen.** Module configuration opens a popup on Kiyoh-Connect 1.1 (`kiyoh-prestashop`). Kiyoh.com uses `https://connect.kiyoh.com` (tenant 98). Klantenvertellen.nl uses `https://connect.klantenvertellen.nl` (tenant 99). Scope `integration:connect`, PKCE S256, and a per-shop `state`.
- The shop registers itself with `POST {issuer}/shops/register` before authorize. The return address is `https://{shop-host}/module/kiyoh/oauth` (the shop subdirectory is included when PrestaShop is not installed at the domain root). The signature is unpadded base64url HMAC-SHA256 over the LF-joined lines `v1`, `register`, client id, shop origin, redirect URI, and unix `issued_at`.
- After the popup returns `code` and `state`, the module exchanges them at `POST {issuer}/token` and reads `GET {issuer}/integration/credentials`. `locationId` and `inviteApiKey` are saved as the module Location ID and API key. `tenantId` must match the selected platform or nothing is saved.
- Refresh tokens are encrypted with AES-256-GCM. Disconnect calls `POST {issuer}/revoke`. Uninstall also calls `POST {issuer}/shops/revoke` (signature action `revoke`) and then deletes module data.
- Manual Location ID and API key remain in the form for existing installs.
- `scripts/build-release.sh` and GitHub Actions build `kiyoh-<version>.zip`. A published GitHub release attaches that zip.
- `PLUGIN_SPEC.md` and this changelog.

### Changed
- Module version is 1.4.0.

### Removed
- `kiyoh.zip` is no longer stored in git.

### Security
- The HMAC key and OAuth client secret are **not** in the module zip. The server must provide `KIYOH_CONNECT_HMAC_KEY` and `KIYOH_CONNECT_CLIENT_SECRET` (environment, or constants in PrestaShop `config/defines_custom.inc.php`). A shared secret inside a distributed PrestaShop package would let anyone who downloads the zip register shops and exchange codes. See `PLUGIN_SPEC.md`.

## 1.3.0 — 2026-10-23

### Added
- **Kiyoh Mobile health.** The module posts `POST {url}/api/integrations/health` for each base URL in **Kiyoh Mobile URL(s)** (default `https://kiyoh-mobile.com`). Headers are `X-Publication-Api-Token` and `X-Location-Id`. Body platform is `prestashop`, with `connection`, `invites`, and `productSync` (up to 500 product codes).
- Reports are sent on configuration save, connection test, invitation result, and product sync, and again from the daily cron when the payload changed or the last accepted report is at least 24 hours old. HTTP 429 is honored. A health failure does not change checkout, orders, or sync results.
