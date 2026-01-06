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
- **PrestaShop**: 1.7 or higher
- **PHP**: 7.1 or higher
- **Server Extensions**: cURL, JSON (usually pre-installed)

### Recommended for Best Performance
- **PrestaShop**: 1.7.8 or higher (latest version)
- **PHP**: 8.0 or higher
- **SSL Certificate**: For secure API communication

### Compatibility
The module is compatible with:
- ✅ PrestaShop 1.7+ installations
- ✅ All standard PrestaShop installations
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
- Your API Key (X-Publication-Api-Token)

Keep these credentials handy for the next step.

### Step 2: Install the Module

You can install the module using PrestaShop's built-in module manager. The installation process typically takes 2-5 minutes.

**Installation steps:**
1. Log into your PrestaShop admin panel
2. Go to **Modules > Module Manager**
3. Click **Upload a module** at the top of the page
4. Click **select file** and choose the `kiyoh.zip` file
5. Click **Upload this module**
6. Once uploaded, find "Kiyoh Reviews" in the module list
7. Click **Install**
8. Verify installation success (module will show in the installed modules list)

**Alternative method (for technical users):**
- Upload the module files to `/modules/kiyoh/` directory via FTP
- Go to **Modules > Module Manager** and click **Install** next to "Kiyoh Reviews"

Once installed, you can configure the module by clicking **Configure** next to the module name.

### Step 3: Configure the Module

1. Log into your PrestaShop admin panel
2. Go to **Modules > Module Manager**
3. Find "Kiyoh Reviews" and click **Configure**
4. Configure your settings as described below

#### Enter Your API Credentials

In the **General Settings** section:
- **Enable Module**: Set to **Yes**
- **Platform**: Select your platform (Kiyoh.com or Klantenvertellen.nl)
- **Location ID**: Enter your Location ID
- **API Key**: Enter your API Key (X-Publication-Api-Token)
- Click **Save**

#### Test Your Connection

After entering credentials:
- Click the **Test Connection** button
- Wait for the success message
- If you see an error, double-check your credentials

The system will automatically verify your credentials and show your platform and location ID if successful.

#### Configure Product Synchronization

In the **Product Sync Settings** section:
- **Auto Sync Products**: Set to **Yes** (recommended)
  - This automatically updates product information when you edit products
- **Excluded Product Types**: Select product types you don't want to sync (optional)
  - Examples: Virtual products, pack products, downloadable products
  - Products without images or descriptions
  - Out of stock or disabled products
- **Excluded Product Codes**: Enter specific product references/SKUs to exclude (optional)
  - Enter comma-separated values
  - Example: SAMPLE-001, TEST-SKU, DEMO-PRODUCT

**Initial Product Sync:**
- **Important**: First save your API credentials, then proceed with bulk sync
- Click the **Bulk Sync All Products** button to sync all existing products
- **Do not leave the page while the sync is in progress**
- This may take several minutes depending on your catalog size
- You'll see a success/error message when complete
- You only need to do this once

Click **Save** when done.

#### Configure Review Invitations

In the **Review Invitation Settings** section:

**Basic Settings:**
- **Trigger Order Status**: Select when to send invitations (usually "Delivered" or "Payment accepted")
  - Hold Ctrl to select multiple statuses
  - Common choices: "Payment accepted", "Shipped", "Delivered"
- **Invitation Delay (days)**: How many days to wait before sending
  - 0 = send immediately
  - 7 = wait 7 days (recommended for delivery time)
- **Invitation Type**: Choose what to request:
  - **Shop + Product Reviews**: Ask for both store and product reviews (recommended)
  - **Product Reviews Only**: Only ask for product reviews
  - **Shop Reviews Only**: Only ask for store reviews (no product-specific reviews)

**Advanced Settings:**
- **Max Products per Invitation**: How many products to include (1-10, recommended: 3-5)
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
- **Excluded Customer Groups**: Skip certain customer groups (e.g., wholesale customers)
  - Hold Ctrl to select multiple groups
  - Useful for B2B stores or special customer categories

**Choosing the Right Invitation Type:**
- **Shop + Product Reviews**: Best for most stores - gives comprehensive feedback
- **Product Reviews Only**: Good for stores focused on product quality and SEO
- **Shop Reviews Only**: Ideal for service-based businesses or when you want to focus on overall customer experience

Click **Save** when done.

#### Configure Language Settings

In the **Language Settings** section:
- **Auto-detect Language**: Automatically detect customer language from their profile
  - **Enabled**: Uses customer's language from their account
  - **Disabled**: Uses fallback language for all customers
- **Fallback Language**: Default language when auto-detection fails
  - Choose from your store's available languages
  - Common choices: English (en), Dutch (nl), German (de)

Click **Save** when done.

## How It Works

### Automatic Review Requests

Once configured, the module works automatically:

1. **Customer Places Order**: A customer completes a purchase
2. **Order Status Changes**: You update the order to your configured trigger status (e.g., "Delivered")
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

The module supports multiple languages including:
- **English** (en)
- **Dutch** (nl) 
- **German** (de)
- **French** (fr)
- And many more supported by Kiyoh/Klantenvertellen

The module automatically detects the customer's language from their PrestaShop account and sends invitations in the appropriate language.

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
- Verify PrestaShop version compatibility (1.7+)
- Check API credentials are correct
- Test API connection in settings
- Ensure module is enabled

**Products not syncing:**
- Ensure auto-sync is enabled
- Check if products are excluded by type or code
- Try manual bulk sync
- Check PrestaShop error logs

**Invitations not sending:**
- Verify trigger statuses are configured
- Check if customers are in excluded groups
- Ensure API connection is working
- Verify order status changes are triggering correctly

**Bulk sync stuck or failed:**
- **Do not refresh or leave the page during bulk sync**
- If sync fails, wait a few minutes and try again
- Check your internet connection
- Reduce the number of products if you have a very large catalog
- Contact support if issues persist

### Debug Information

Enable PrestaShop debug mode to see detailed information about module operations:
1. Go to **Advanced Parameters > Performance**
2. Set **Debug mode** to **Yes**
3. Check error logs in **Advanced Parameters > Logs**

Look for messages starting with "Kiyoh" to see module-specific information.

## Multi-Store Support

The module is compatible with PrestaShop multi-store installations:
- Configure each store independently
- Each store can have different API credentials
- Separate product sync and invitation settings per store

## Security

- API keys are stored securely in PrestaShop configuration
- All user inputs are sanitized and validated
- AJAX requests include security tokens
- Admin access controls prevent unauthorized configuration changes

## About This Module

**Version**: 1.0.0  
**Compatibility**: PrestaShop 1.7+  
**Developer**: Kiyoh  
**License**: Academic Free License 3.0 (AFL-3.0)  
**Last Updated**: January 2025  

## Support

For technical support:
1. Check your API credentials and connection
2. Review PrestaShop error logs
3. Test with a single product/order first
4. Contact your Kiyoh/Klantenvertellen account manager
5. Consult module documentation

## Advanced Configuration

### Custom Product Exclusions

You can exclude products from sync in several ways:
- **By Type**: Virtual, pack, downloadable, customizable products
- **By Status**: Out of stock, disabled products
- **By Content**: Products without images, descriptions, or with zero price
- **By Code**: Specific SKUs or product references

### Invitation Timing Strategy

**Recommended delay settings:**
- **Digital products**: 1-3 days
- **Physical products (fast shipping)**: 5-7 days  
- **Physical products (standard shipping)**: 7-14 days
- **Custom/made-to-order products**: 14-30 days

### Customer Segmentation

Use customer group exclusions for:
- **Wholesale customers**: May have different review needs
- **Test accounts**: Prevent test orders from triggering invitations
- **VIP customers**: Handle manually for personalized service
- **Problematic customers**: Temporarily exclude if needed

## Best Practices

### Product Management
- Keep product information up-to-date for better sync results
- Use high-quality product images (required for Kiyoh sync)
- Write detailed product descriptions
- Maintain accurate pricing and stock levels

### Review Strategy
- Don't overwhelm customers with too many product reviews (max 3-5)
- Time invitations appropriately based on your delivery schedule
- Focus on your best-selling or most important products
- Monitor review response rates and adjust timing if needed

### Customer Communication
- Ensure customers know reviews are coming
- Include review information in order confirmation emails
- Provide excellent customer service to encourage positive reviews
- Respond to all reviews, both positive and negative

This comprehensive guide should help you get the most out of your Kiyoh Reviews PrestaShop module. Remember to test the configuration with a few orders before fully deploying to ensure everything works as expected.