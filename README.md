# Kiyoh Reviews - PrestaShop User Guide

**Collect more reviews and boost your store's credibility with automated review requests**

## What is Kiyoh Reviews?

Kiyoh Reviews is a PrestaShop module that automatically collects customer reviews for your store and products. It integrates seamlessly with Kiyoh and Klantenvertellen review platforms, helping you build trust and increase conversions.

## Key Benefits

✅ **Automated Review Collection**: Automatically send review requests after orders reach specific statuses  
✅ **Product Reviews**: Collect reviews for specific products customers purchased  
✅ **Perfect Timing**: Send review requests at the optimal time (e.g., 7 days after delivery)  
✅ **Multi-Language**: Automatically detects customer language for personalized emails  
✅ **Easy Setup**: Configure once and let it run automatically  
✅ **Boost SEO**: Product reviews improve search engine rankings  
✅ **Increase Trust**: Display ratings and reviews to build customer confidence  

## System Requirements

### Minimum Requirements
- **PrestaShop**: 1.7.0.0 or higher
- **PHP**: 8.2 or higher
- **Server Extensions**: cURL, JSON (usually pre-installed)

### Tested compatibility
The module declares an explicit upper bound. It does not claim to support every future PrestaShop release.

- **Tested on**: PrestaShop 9.2.0 (Symfony 6.4, PHP 8.2+)
- **PrestaShop range**: 1.7.0.0 through 9.2.0
- **PHP**: 8.2 minimum (8.3 and 8.4 included)

### Recommended for Best Performance
- **PrestaShop**: 9.2.0
- **PHP**: 8.2 or higher
- **SSL Certificate**: For secure API communication

### Compatibility
The module is compatible with:
- ✅ PrestaShop 1.7.0.0 through 9.2.0
- ✅ Multi-store installations
- ✅ Cloud hosting and shared hosting

**Note for older PrestaShop installations:**
- PrestaShop versions below 1.7 are not supported
- We recommend keeping PrestaShop updated for security and performance
- If you're on older versions, contact your technical team about upgrading

## Getting Started

### Step 1: Get Your API Credentials

Navigate to your Kiyoh/Klantenvertellen dashboard to receive:
- Your Location ID
- Your API Key

Keep these credentials handy for the next step.

### Step 2: Install the Module

You can install the module using PrestaShop's built-in module manager. The installation process typically takes 2-5 minutes.

The install file is `kiyoh-<version>.zip`. It is built by GitHub Actions (the `kiyoh-module` artifact on each run, and the file attached to a GitHub Release). It is not stored in the git repository.

**Installation steps:**
1. Download `kiyoh-<version>.zip` from the GitHub Release, or from the Actions artifact on the pull request
2. Log into your PrestaShop admin panel
3. Go to **Modules > Module Manager**
4. Click **Upload a module** at the top of the page
5. Click **select file** and choose that zip
6. Click **Upload this module**
7. Once uploaded, find "Kiyoh Reviews" in the module list
8. Click **Install**
9. Verify installation success (module will show in the installed modules list)

**Alternative method (for technical users):**
- Upload the module files to `/modules/kiyoh/` directory via FTP
- Go to **Modules > Module Manager** and click **Install** next to "Kiyoh Reviews"

Once installed, you can configure the module by clicking **Configure** next to the module name.

Developers can build the same zip locally:

```
bash scripts/build-release.sh
```

That writes `dist/kiyoh-<version>.zip`.

### Step 3: Connect, or enter API credentials

**Connect with Kiyoh / Klantenvertellen** is on the configuration page, above the manual fields. Save the platform first. Kiyoh.com opens `https://connect.kiyoh.com`. Klantenvertellen.nl opens `https://connect.klantenvertellen.nl`. The button opens a popup. Sign in, complete 2FA if the account has it, and pick a location. The module then stores that location's ID and invite API key.

The shop must be HTTPS, with friendly URLs on. The return address is `https://{shop-host}/module/kiyoh/oauth`. In a multistore, select one shop before connecting. Each shop registers its own return address.

No shared HMAC key or client secret is required, and none is included in the module. Connect returns a shop credential once. The module stores it encrypted and uses it for later sign-in, refresh, and revoke. Disconnect revokes the session and keeps that credential, so connecting again on the same return address does not need a new secret.

**Manual fallback:** Location ID and API key stay on the form. Existing installs keep working without Connect. Saving a different Location ID or API key by hand clears the linked flag and leaves the Connect session in place until you click **Disconnect**. Disconnect revokes the Connect session. When the saved credentials came from Connect, Disconnect also clears the Location ID and API key.

### Step 4: Configure the rest of the module

1. Log into your PrestaShop admin panel
2. Go to **Modules > Module Manager**
3. Find "Kiyoh Reviews" and click **Configure**
4. Configure your settings as described below

#### Enter Your API Credentials

In the **General Settings** section:
- **Enable Module**: Set to **Yes**
- **Platform**: Select your platform (Kiyoh.com or Klantenvertellen.nl)
- **Location ID**: Enter your Location ID
- **API Key**: Enter your API Key
- Click **Save**

#### Test Your Connection

After entering credentials:
- Click the **Test Connection** button
- Wait for the success message
- If you see an error, double-check your credentials

The system will automatically verify your credentials if successful.

#### Configure Product Synchronization

In the **Product Sync Settings** section:
- **Auto Sync Products**: Set to **Yes** (recommended)
  - This automatically updates product information when you edit products
- **Excluded Product Types**: Select product types you don't want to sync (optional)
  - Example: Virtual products, downloadable products, gift cards
- **Excluded Product Codes**: Enter specific SKUs to exclude (optional)
  - Enter one SKU per line or seperated by commas
  - Example: SAMPLE-001, TEST-SKU

**Initial Product Sync:**
- **Important**: First save your API credentials, then proceed with bulk sync
- Click the **Bulk Sync Products** button to sync all existing products
- **Do not leave the page while the sync is in progress**
- This may take several minutes depending on your catalog size
- You'll see a loading indicator during the sync
- You only need to do this once

Click **Save** when done.

#### Configure Review Invitations

In the **Review Invitation Settings** section:

**Basic Settings:**
- **Trigger Order Status**: Select when to send invitations (usually "Completed")
- **Invitation Delay (Days)**: How many days to wait before sending
- **Invitation Type**: Choose what to request:
  - **Shop + Product Reviews**: Ask for both store and product reviews (recommended)
  - **Product Reviews Only**: Only ask for product reviews
  - **Shop Reviews Only**: Only ask for store reviews (no product-specific reviews)

**Advanced Settings:**
- **Max Products per Invitation**: How many products to include (recommended: 3-5)
  - Too many products can overwhelm customers
  - Focus on the most important items
- **Product Sort Order**: Choose how to prioritize products when multiple items are in an order:
  - **Cart Order (Default)**: Use the order items appear in the cart
  - **Price (High to Low)**: Prioritize expensive items first
  - **Price (Low to High)**: Prioritize cheaper items first
  - **Name (A to Z)**: Sort products alphabetically
  - **Name (Z to A)**: Sort products reverse alphabetically
  - **SKU (A to Z)**: Sort by product code alphabetically
  - **SKU (Z to A)**: Sort by product code reverse alphabetically

**Customer Settings:**
- **Excluded Customer Groups**: Skip certain user roles (e.g., wholesale customers)
- **Auto-detect Language**: Automatically detect customer language from PrestaShop locale
- **Fallback Language**: Default language if customer language can't be detected (e.g., "en" for English)

**Choosing the Right Invitation Type:**
- **Shop + Product Reviews**: Best for most stores - gives comprehensive feedback
- **Product Reviews Only**: Good for stores focused on product quality and SEO
- **Shop Reviews Only**: Ideal for service-based businesses or when you want to focus on overall customer experience

Click **Save** when done.

## How It Works

### Automatic Review Requests

Once configured, the module works automatically:

1. **Customer Places Order**: A customer completes a purchase
2. **Order is Completed**: You mark the order as "Completed" (or your configured status)
3. **Waiting Period**: The system waits the configured delay (e.g., 7 days)
4. **Review Request Sent**: Customer receives an email from Kiyoh/Klantenvertellen
5. **Customer Leaves Review**: Customer clicks the link and writes a review
6. **Review Published**: Review appears on your Kiyoh profile and can be displayed on your website

### What Customers Receive

Customers receive a personalized email in their language with:
- A friendly greeting using their name
- A link to leave a review
- The products they purchased (with images) - if product reviews are enabled
- A simple rating system

**Note**: If you choose "Shop Reviews Only", customers will only be asked to review your store, not specific products. This is useful if you prefer to focus on overall service quality rather than individual product feedback.

## Language Support

The module supports 25+ languages including:
- English, Dutch, German, French, Spanish, Italian
- Portuguese, Danish, Swedish, Norwegian, Finnish
- Polish, Czech, Slovak, Hungarian, Romanian
- Chinese, Japanese, Turkish, Greek, Russian
- And many more...

## Managing Reviews

### Where to See Your Reviews

1. **Kiyoh Dashboard**: Log into your Kiyoh/Klantenvertellen account to see all reviews
2. **Email Notifications**: You'll receive email alerts for new reviews
3. **PrestaShop Logs**: Your technical team can check logs for invitation status

### Responding to Reviews

- Log into your Kiyoh/Klantenvertellen dashboard
- Navigate to the reviews section
- Click on a review to respond
- Your response appears publicly below the review

### Handling Negative Reviews

1. **Respond Quickly**: Address concerns within 24-48 hours
2. **Be Professional**: Stay calm and courteous
3. **Offer Solutions**: Provide ways to resolve the issue
4. **Take It Offline**: Invite them to contact you directly
5. **Learn and Improve**: Use feedback to improve your service

## Troubleshooting

### Common Issues

**Module not working:**
- Verify PrestaShop is active and compatible
- Check API credentials are correct
- Test API connection in settings

**Products not syncing:**
- Ensure auto-sync is enabled
- Check if products are excluded by type or code
- Review error logs for specific issues

**Invitations not sending:**
- Verify trigger statuses are configured
- Check if customers are in excluded groups
- Ensure API connection is working

**Bulk sync stuck or failed:**
- **Do not refresh or leave the page during bulk sync**
- If sync fails, wait a few minutes and try again
- Check your internet connection
- Contact support if issues persist

### Debug Information

Enable PrestaShop debug mode to see detailed information about module operations. Check your PrestaShop error logs for messages starting with "Kiyoh".

## About This Module

**Version**: 1.4.0  
**Compatibility**: PrestaShop 1.7.0.0–9.2.0  
**PHP**: 8.2 or higher  
**Developer**: Kiyoh  
**License**: Proprietary 
**Last Updated**: October 2026  

## Support

For technical support:
1. Check your API credentials and connection
2. Review PrestaShop error logs
3. Contact your review platform support team
4. Consult module documentation

## Kiyoh Mobile health

The module reports shop health to Kiyoh Mobile so the plugins page can show whether this store is connected. A report never blocks checkout, order updates, or product saves. Requests time out quickly, and a `429` response is honored instead of retried in the same request.

### Where reports are sent

In **General Settings**, **Kiyoh Mobile URL(s)** is a comma-separated list of Kiyoh Mobile base URLs. The default is `https://kiyoh-mobile.com`. The module posts to `{url}/api/integrations/health` for each URL.

To show the shop on Kiyoh Mobile staging as well, set the field to:

```
https://kiyoh-mobile.com,https://kiyohmobileui-staging.up.railway.app
```

Each request sends:

- `X-Publication-Api-Token`: the API key already saved on this module
- `X-Location-Id`: the Location ID already saved on this module
- `platform`: `prestashop`
- `shopId`: the shop domain (the virtual shop path is included for multistore shops that share a domain)
- `pluginVersion`: the module version
- `connection`, `invites`, and `productSync`

`productSync` can include up to 500 product codes from products that synced successfully. Codes are not stored in the health record; Kiyoh Mobile marks them as imported for Smart Widgets.

### When a report is sent

- Saving the module configuration. Changing the Location ID or API key also checks the review-platform connection before the report is sent. Other setting changes send the report without that extra check.
- Testing the API connection
- A review invitation is sent or fails
- A product sync finishes (including bulk sync)
- Once a day from the health cron URL, or from the PrestaShop cronjobs module

The daily run sends again only when the payload changed, or when the last accepted report for that URL is at least 24 hours old. Kiyoh Mobile treats a shop as stale when it has not heard from the plugin for more than 48 hours.

### Daily cron

Open the module configuration page and copy **Daily health cron**. The link looks like:

```
https://your-shop.example/module/kiyoh/cron?token=YOUR_TOKEN
```

Call that URL once a day. The token is created for this shop and is required. A wrong token gets `403` and does not report anything.

In a multistore, one call reports every active shop, using that shop's own domain and saved credentials.

## Advanced Configuration

### Multisite Support

The module is compatible with PrestaShop multi-store installations. Configure each store independently.

### WPML/Polylang Compatibility

The module works with multilingual plugins and will attempt to detect customer language for invitations.

## Permissions

- **Configure the module.** The employee must be logged into the back office. Connect and Disconnect also check the `AdminModules` token.
- **HTTPS to Kiyoh-Connect.** `connect.kiyoh.com` (Kiyoh, tenant 98) and `connect.klantenvertellen.nl` (Klantenvertellen, tenant 99). Scope `integration:connect` only, so the module can read one location's invite key.
- **HTTPS to the review platform and to each Kiyoh Mobile URL** the merchant saved. Health reports use the stored API key and Location ID.
- **Public return address** `https://{shop-host}/module/kiyoh/oauth`. Connect redirects the browser here. The request is accepted only when `state` matches the sign-in that an employee started for this shop.
- **Daily health URL** `/module/kiyoh/cron`. It requires the module cron token. A wrong token gets `403`.
- Friendly URLs must be enabled, or the return address will not reach the module.

## Data stored

Kept until you uninstall the module:

- Location ID, API key, platform, invitation and product-sync settings, and the Kiyoh Mobile URL list
- Product sync rows (`ps_kiyoh_product_sync`) and invitation rows (`ps_kiyoh_invitations`)
- Health report state and the cron token
- After Connect: shop origin, return address, issuer, location name, tenant id, an encrypted refresh token, an encrypted per-shop credential, and a module-generated encryption key. The PKCE verifier is kept only until the popup returns (at most 15 minutes), and it is encrypted.
- No shared HMAC key and no shared client secret are stored. The shop secret is the credential Connect returns once.

There is no export button. The Location ID is visible on the configuration page. Uninstall is how a shop erases this module's data. Orders, customers, and catalog rows are not part of that data.

## Uninstall

Uninstall drops `ps_kiyoh_product_sync` and `ps_kiyoh_invitations`, deletes every configuration key listed above (including the shop credential), and asks Kiyoh-Connect to revoke the refresh token (`POST /revoke`) and the shop registration (`POST /shops/revoke`) using the stored shop credential. If that credential is missing, the remote calls are skipped. Local data is removed either way. PrestaShop orders and customers stay.

## Developers

```
php tests/KiyohMobileHealthTest.php
php tests/KiyohConnectTest.php
python3 scripts/preflight.py .
bash scripts/build-release.sh
```

The PHP tests do not need a PrestaShop installation. `PLUGIN_SPEC.md` is the contract. `CHANGELOG.md` lists what each version changed.

## Security

- Connect and Disconnect require a logged-in employee and the back-office token
- The OAuth `state` is compared with `hash_equals` and can be used once
- The refresh token and the per-shop credential are encrypted with AES-256-GCM. The encryption key is generated on the shop and stored in the shop database
- The module does not contain a shared HMAC key or client secret, and the configuration page does not ask for one. See `PLUGIN_SPEC.md`
- Review API and Connect calls verify TLS. Connect hosts are limited to the two issuers
- Configuration output is escaped. Health and Connect failures are not written into the order flow
