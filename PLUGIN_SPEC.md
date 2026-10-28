# PLUGIN_SPEC — Kiyoh Reviews for PrestaShop

**Shape:** A (PrestaShop module) + B (Kiyoh review API and Kiyoh-Connect)
**Owner:** Kiyoh plugins   **Version:** 1.4.2

## Host
- Platform: PrestaShop 1.7.0.0 through 9.2.0
- Runtime: PHP 8.2+ with cURL, JSON, OpenSSL, and mbstring

## Contract
- Inputs: order status hook, product save hook, module configuration, daily cron URL, Kiyoh-Connect popup callback `https://{shop-host}/module/kiyoh/oauth`
- Outputs: review invitations, product registration, Kiyoh Mobile health reports, saved location credentials
- Sample payloads: `tests/fixtures/connect-token.json`, `tests/fixtures/connect-credentials.json`

## External API
- Review API: `https://www.kiyoh.com` or `https://www.klantenvertellen.nl` (existing invite and product calls)
- Kiyoh-Connect 1.2.0 (KVMicroServices/Kiyoh-Connect PR #1). Self-hosted clients are public.
  - Issuer `https://connect.kiyoh.com` tenant 98
  - Issuer `https://connect.klantenvertellen.nl` tenant 99
  - Client `kiyoh-prestashop`, scope `integration:connect`, PKCE S256 + `state`
- Kiyoh Mobile health: `POST {url}/api/integrations/health`, default `https://kiyoh-mobile.com`
- Auth: Connect authorization code. `POST /token` is `application/x-www-form-urlencoded` with `grant_type=authorization_code`, `client_id`, the per-shop credential, `code`, `code_verifier`, and the exact `redirect_uri`. Refresh uses `grant_type=refresh_token` and the same shop credential. There is no shared client secret. Credentials are `GET /integration/credentials` with `Authorization: Bearer`.
- Shop registration: `POST {issuer}/shops/register` JSON with `client_id`, `shop_url`, and `redirect_uri`. No HMAC. The response returns a per-shop credential once. The module stores it encrypted and sends it on `/token`, refresh, `POST /revoke`, and `POST /shops/revoke`. A later register for the same return address may omit the secret; the module keeps the stored one. If the return address changed and no new secret is returned, configuration shows `shop_credential_missing`.
- Wire names are provisional and live only in `KiyohConnect::FIELDS` (`shopId` → `shop_id`, `shopSecret` → `shop_secret`, plus the grant, code, verifier, refresh, and token fields). Change that map when Connect PR #1 reports the final names. Call sites use logical names.
- `shop_url` is `https://{shop-host}` with no path. `redirect_uri` is `https://{shop-host}/module/kiyoh/oauth`, with the PrestaShop base path inserted when the shop is in a subdirectory.
- Rate limits: 408, 425, 429, and 5xx may be retried with full jitter (base 1s, cap 60s, max 5) only when the wait is at most 2 seconds. `Retry-After` and `retryAfterSeconds` win. A longer wait is shown to the merchant. The authorization-code grant is not retried (the code is single-use).
- Pagination: none for Connect
- Webhooks: none
- ToS: credentials are used only to send invites and register products for the location the merchant picked

## Behaviour
- Trigger: merchant clicks **Connect with Kiyoh / Klantenvertellen** (popup), or saves manual credentials. Health also runs on save, invite, product sync, and daily cron.
- Volume: one location per shop. Health product codes capped at 500.
- Failure policy: Connect errors stay on the configuration page and do not change orders. Health errors are logged and swallowed. Invalid tenant discards the tokens and saves nothing.
- Idempotency: register can be repeated. The shop secret is issued once; an unchanged return address reuses the stored credential and skips register. The authorization code is single-use; the pending PKCE record is deleted when `state` matches. Credentials overwrite `KIYOH_LOCATION_ID` and `KIYOH_API_KEY`. A second connect replaces the refresh token. Disconnect revokes the refresh token and keeps the shop credential.

## Permissions requested (and why)
- Back-office employee who can configure modules, plus the admin CSRF token on Connect and Disconnect (`token` on PrestaShop 1.7/8, `_token` on the Symfony configure page in 8/9). Stops another site from starting a sign-in or disconnecting the shop.
- Outbound HTTPS to the two Connect issuers, the review API, and the configured Kiyoh Mobile URLs.
- Scope `integration:connect` only. It is what Connect exchanges for one location's invite key.
- Front route `/module/kiyoh/oauth` is public so Connect can redirect the browser back. It accepts only a `state` that an authenticated employee stored for this shop.
- Front route `/module/kiyoh/cron` requires the module cron token.
- Friendly URLs must be enabled so the return address reaches the module.

## Data stored, retention, uninstall behaviour
- `ps_kiyoh_product_sync` and `ps_kiyoh_invitations` (existing).
- Configuration: module settings, including Location ID and API key (the same fields a merchant can type). Connect also stores the shop origin, return address, issuer, location name, tenant id, an encrypted refresh token, an encrypted shop credential (`shop_id` + `shop_secret`), a short-lived encrypted PKCE pending record, a one-shot result message, and a module-generated AES-256-GCM key.
- There is no shared HMAC key and no shared client secret, in the zip or on the server.
- Uninstall deletes those configuration keys and both tables, and best-effort calls `POST /revoke` and `POST /shops/revoke` with the stored shop credential. It does not delete orders, customers, or products. There is no separate export screen; Location ID is visible on the configuration page. Uninstall is the erase path.
- If the shop credential is missing at uninstall time, remote calls are skipped and local data is still deleted. Network errors do not stop uninstall.

## Acceptance test
1. With no shared secret configured, Connect on `connect.kiyoh.com` saves Location ID, invite API key, and tenant 98. Klantenvertellen saves tenant 99. The shop credential is stored encrypted after register, before the popup.
2. Connecting the same shop again overwrites the same configuration keys and does not create a second location row. The same return address reuses the stored shop credential.
3. A wrong `state`, a cancelled login, a tenant mismatch, and a 401 from credentials leave a clear configuration message and do not throw into checkout.
4. `POST /integration/credentials` returning 429 then 200 is covered by `php tests/KiyohConnectTest.php`.
5. Manual Location ID and API key still save and still pass Test Connection when Connect is not used.
6. Uninstall removes module tables and configuration keys.
7. `python3 scripts/preflight.py .` reports no unjustified high finding. `bash scripts/build-release.sh` produces the zip. `kiyoh.zip` is not in git.

## Security
`kiyoh-prestashop` is a public client. The module does not embed a shared HMAC key or client secret and does not ask the merchant to provide one. `POST /shops/register` returns a per-shop credential once. The module encrypts `{shopId, shopSecret}` with AES-256-GCM under `KIYOH_CONNECT_ENC_KEY` (32 bytes, generated on install, stored in the shop database, not a KMS) and sends that credential on token, refresh, token revoke, and shop revoke. Disconnect keeps the credential so reconnect does not need a second one-time secret. Code exchange also requires PKCE S256 and the exact redirect URI.

The encryption key lives in the shop database. Anyone with database access can decrypt the shop secret and the refresh token. That is the storage available to a self-hosted PrestaShop module.

## Open questions
- Production `connect.kiyoh.com` was still serving Kiyoh-Connect 1.0.0 (`Cannot POST /shops/register`) when this module was written. Merchants can complete the popup only after 1.2.0 is deployed. Wire names in `KiyohConnect::FIELDS` are provisional until that PR reports the final fields.
- The back office has to be HTTPS on the same host as the shop for the popup to land back on the configuration page. Otherwise the callback tells the merchant to reload the configuration page; the result is still stored for that shop.
- IPv6 shop hosts are rejected by the hostname check. Friendly URLs must be on. Group accounts with more than 80 locations can show an empty list in Connect, and picks beyond 40 can fail.
