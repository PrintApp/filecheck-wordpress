=== Filecheck ===
Contributors: filecheck
Tags: preflight, woocommerce, file validation, print, pdf
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Preflight artwork validation for WooCommerce. Gates "Add to Cart" until the customer uploads a print-ready file that passes your rules.

== Description ==

Eliminate prepress artwork errors before an order is ever placed. **Filecheck** embeds the lightweight, responsive **Filecheck Element** preflight widget directly on your WooCommerce product pages. Instead of back-and-forth emails about resolution, crop marks, and sizing, your customers get instant in-browser feedback on their PDF, PNG, or JPEG files before they can buy.

Validation runs through the Filecheck cloud service and can check:

* **Resolution (DPI)** - flags blurry, low-resolution graphics.
* **Bleed & safety margins** - ensures critical art won't get trimmed.
* **Color space** - verifies CMYK vs. RGB compliance.
* **Dimensions & aspect ratio** - confirms the file matches the selected product size.
* **Fonts & vectors** - detects missing fonts or un-embedded artwork.

Once a file passes, the widget enables checkout, shows print-ready proof thumbnails in the cart, and attaches a unique job ID to the order line item. After checkout, store managers can view the full preflight report and download optimized production files directly from the WooCommerce order screen.

**A Filecheck account is required** to use this plugin. Filecheck is a commercial software-as-a-service product. You can create an account and review pricing at [filecheck.io](https://filecheck.io).

== Benefits ==

* **Reduce refunds** - prevent orders placed with un-printable artwork.
* **Cut prepress review time** - save manual checking per order.
* **Seamless checkout** - dynamic client-side button gating.
* **Secure production downloads** - outputs are streamed into a protected folder in your uploads directory, shielded from direct access.

== External services ==

This plugin connects to the Filecheck cloud service (operated by Filecheck) to validate customer file uploads and synchronize order data. It is required for the plugin to function. The plugin communicates with the following endpoints:

* **https://cdn.filecheck.io** - loads the Filecheck Element widget script on product pages. Your publishable key is included in the script URL.
* **https://api.filecheck.io** - the REST API used to verify your keys, list your workflows/connectors, sync orders, and retrieve job results and output files. Requests are authenticated with your secret key.

**Data sent to Filecheck:**

* When a customer uses the widget on a product page, their uploaded file(s) and the associated workflow/product identifiers are transmitted to Filecheck for validation.
* When an order containing a Filecheck item is placed or changes status, order details are sent to Filecheck, including: order ID and status, currency and totals, line items (product name, SKU, quantity, job ID), the customer's name, email, phone, and shipping address.

This data is sent at the moment a customer interacts with the widget and when orders are created or updated. By using this plugin you are sending the data described above to Filecheck.

For details on how Filecheck handles this data, see:

* Terms of Service: https://filecheck.io/terms
* Privacy Policy: https://filecheck.io/privacy

== Installation ==

= Minimum Requirements =
* WordPress 5.0 or greater
* PHP 7.4 or greater
* WooCommerce 4.0 or greater
* A Filecheck account ([filecheck.io](https://filecheck.io))

= Automatic Installation =
1. In your WordPress dashboard go to **Plugins > Add New**.
2. Search for `Filecheck`.
3. Click **Install Now**, then **Activate**.

= Manual Installation =
1. Upload the `filecheck` folder to `/wp-content/plugins/`.
2. Go to the **Plugins** screen and click **Activate** under **Filecheck**.

= Setup =
1. Open the **Filecheck** menu in the WordPress admin sidebar.
2. Enter your **Publishable Key** and **Secret Key** from your [Filecheck dashboard](https://admin.filecheck.io).
3. (Optional) Enter your **Agent ID**.
4. Click **Test Connection** to verify your keys.
5. Select your default workflow and click **Save Settings**.
6. (Optional) Override the workflow or connector per product from the **Filecheck** tab on the product edit screen.

== Frequently Asked Questions ==

= Do I need a Filecheck account? =
Yes. Filecheck is a commercial cloud service and the plugin requires valid API keys from your Filecheck account to operate. See [filecheck.io](https://filecheck.io).

= Does it support mobile uploads? =
Yes. The Filecheck Element is fully responsive and works on iOS, Android, and tablets.

= Where are the high-res files saved? =
Uploads are processed on Filecheck's servers. When an order reaches processing or completed, the plugin streams the print-optimized output files back into a protected directory (`wp-content/uploads/filecheck-secure/`) that is not directly accessible.

= Will it conflict with other product option plugins? =
No. The widget uses a session-resume mechanism with an AJAX WooCommerce session fallback and does not modify native cart serialization, so it co-exists with customization editors.

== Screenshots ==

1. Preflight Element embedded inline on the WooCommerce product page.
2. Filecheck settings screen with credential configuration and connection test.
3. Per-product Filecheck panel with workflow and connector overrides.
4. Order details metabox showing preflight reports and secure download links.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Filecheck Element CDN integration with client-side Add to Cart gating.
* Native form POST capture with WooCommerce AJAX session fallback for guest and custom checkouts.
* Product and global configuration screens.
* Secure server-side fulfillment streaming output files to a protected directory.
* High-Performance Order Storage (HPOS) compatible.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
