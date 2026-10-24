# PLUGIN_SPEC — Kiyoh Reviews for PrestaShop

**Shape:** A (PrestaShop module) + B (Kiyoh review API and Kiyoh-Connect)
**Owner:** Kiyoh plugins   **Version:** 1.4.0

## Host
- Platform: PrestaShop 1.7.0.0 through 9.2.0
- Runtime: PHP 8.2+ with cURL, JSON, OpenSSL, and mbstring

## Contract
- Inputs: order status hook, product save hook, module configuration, daily cron URL, Kiyoh-Connect popup callback `https://{shop-host}/module/kiyoh/oauth`
- Outputs: review invitations, product registration, Kiyoh Mobile health reports, saved location credentials
- Sample payloads: `tests/fixtures/connect-token.json`, `tests/fixtures/connect-credentials.json`

## External API
- Review API: `https://www.kiyoh.com` or `https://www.klantenvertellen.nl` (existing invite and product calls)
- Kiyoh-Connect 1.1.0 (KVMicroServices/Kiyoh-Connect PR #1, branch `cursor/per-shop-redirect-clients-64d3`)
  - Issuer `https://connect.kiyoh.com` tenant 98
  - Issuer `https://connect.klantenvertellen.nl` tenant 99
  - Client `kiyoh-prestashop`, scope `integration:connect`, PKCE S256 + `state`
- Kiyoh Mobile health: `POST {url}/api/integrations/health`, default `https://kiyoh-mobile.com`
- Auth: Connect authorization code. `POST /token` is `application/x-www-form-urlencoded` with `grant_type`, `client_id`, `client_secret`, `code`, `code_verifier`, `redirect_uri`. Refresh uses `grant_type=refresh_token`. Credentials are `GET /integration/credentials` with `Authorization: Bearer`.
- Shop registration: `POST {issuer}/shops/register` JSON `client_id`, `shop_url`, `redirect_uri`, `issued_at`, `signature`. Signature is base64url (no padding) HMAC-SHA256 over LF-joined lines `v1`, `register`, client id, shop origin, redirect URI, unix `issued_at`. Uninstall uses the same body with action `revoke` on `POST {issuer}/shops/revoke`.
- `shop_url` and the signature's shop origin are `https://{shop-host}` with no path. `redirect_uri` is `https://{shop-host}/module/kiyoh/oauth`, with the PrestaShop base path inserted when the shop is in a subdirectory.
- Rate limits: 408, 425, 429, and 5xx may be retried with full jitter (base 1s, cap 60s, max 5) only when the wait is at most 2 seconds. `Retry-After` and `retryAfterSeconds` win. A longer wait is shown to the merchant. The authorization-code grant is not retried (the code is single-use).
- Pagination: none for Connect
- Webhooks: none
- ToS: credentials are used only to send invites and register products for the location the merchant picked

## Behaviour
- Trigger: merchant clicks **Connect with Kiyoh / Klantenvertellen** (popup), or saves manual credentials. Health also runs on save, invite, product sync, and daily cron.
- Volume: one location per shop. Health product codes capped at 500.
- Failure policy: Connect errors stay on the configuration page and do not change orders. Health errors are logged and swallowed. Invalid tenant discards the tokens and saves nothing.
- Idempotency: register can be repeated. The authorization code is single-use; the pending PKCE record is deleted when `state` matches. Credentials overwrite `KIYOH_LOCATION_ID` and `KIYOH_API_KEY`. A second connect replaces the refresh token.

## Permissions requested (and why)
- Back-office employee who can configure modules, plus the `AdminModules` token on Connect and Disconnect. Stops another site from starting a sign-in or disconnecting the shop.
- Outbound HTTPS to the two Connect issuers, the review API, and the configured Kiyoh Mobile URLs.
- Scope `integration:connect` only. It is what Connect exchanges for one location's invite key.
- Front route `/module/kiyoh/oauth` is public so Connect can redirect the browser back. It accepts only a `state` that an authenticated employee stored for this shop.
- Front route `/module/kiyoh/cron` requires the module cron token.
- Friendly URLs must be enabled so the return address reaches the module.

## Data stored, retention, uninstall behaviour
- `ps_kiyoh_product_sync` and `ps_kiyoh_invitations` (existing).
- Configuration: module settings, including Location ID and API key (the same fields a merchant can type). Connect also stores the shop origin, return address, issuer, location name, tenant id, an encrypted refresh token, a short-lived encrypted PKCE pending record, a one-shot result message, and a module-generated AES-256-GCM key.
- The HMAC key and client secret are not stored and are not in the zip. Environment variable wins over a PHP constant of the same name.
- Uninstall deletes those configuration keys and both tables, and best-effort calls `POST /revoke` and `POST /shops/revoke`. It does not delete orders, customers, or products. There is no separate export screen; Location ID is visible on the configuration page. Uninstall is the erase path.
- If the HMAC key is missing at uninstall time, local data is still deleted and the remote shop registration cannot be revoked.

## Acceptance test
1. With the server secrets set, Connect on `connect.kiyoh.com` saves Location ID, invite API key, and tenant 98. Klantenvertellen saves tenant 99.
2. Connecting the same shop again overwrites the same configuration keys and does not create a second location row.
3. A wrong `state`, a cancelled login, a tenant mismatch, and a 401 from credentials leave a clear configuration message and do not throw into checkout.
4. `POST /integration/credentials` returning 429 then 200 is covered by `php tests/KiyohConnectTest.php`.
5. Manual Location ID and API key still save and still pass Test Connection when Connect is not used.
6. Uninstall removes module tables and configuration keys.
7. `python3 scripts/preflight.py .` reports no unjustified high finding. `bash scripts/build-release.sh` produces the zip. `kiyoh.zip` is not in git.

## Security concern — shared Connect secrets
`kiyoh-prestashop` is a confidential client: shop registration is HMAC-signed and the token request includes `client_secret`. This module is a zip installed on many merchants' servers. Putting the HMAC key or client secret in that zip would publish them to anyone who downloads the release. The module therefore refuses to embed them and reads `KIYOH_CONNECT_HMAC_KEY` and `KIYOH_CONNECT_CLIENT_SECRET` from the server only.

That does not make a single shared secret safe. Every shop that can sign can register an arbitrary return address for this client. The secret is only as protected as the least-protected shop. A public PKCE client (no HMAC key, no client secret in the module), or a per-shop key that Connect issues without a global secret in the package, would match how this module is distributed. Until then, operators must inject the secrets outside the zip and treat them as extractable.

## Open questions
- Production `connect.kiyoh.com` was still serving Kiyoh-Connect 1.0.0 (`Cannot POST /shops/register`) when this module was written. Merchants can complete the popup only after 1.1.0 is deployed.
- The back office has to be HTTPS on the same host as the shop for the popup to land back on the configuration page. Otherwise the callback tells the merchant to reload the configuration page; the result is still stored for that shop.
