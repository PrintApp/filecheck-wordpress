=== Filecheck ===
Contributors: filecheck
Tags: preflight, print, file validation, custom print, artwork check, print shop, woocommerce preflight, pdf checker, resolution check, file upload, bleed check, cmyk check
Requires at least: 4.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A seamless preflight checking and file validation solution for your online print store. Dynamically gates the "Add to Cart" button until customers upload valid, high-resolution artwork that meets your precise bleed, safety, and color constraints.

== Description ==

Elevate your customers' online print ordering experience and completely eliminate prepress artwork errors before order placement. 

**Filecheck** integrates the lightweight, beautiful, and highly responsive **Filecheck Element** preflight widget directly onto your WooCommerce product pages. Instead of tedious back-and-forth emails correcting resolutions, crop marks, and sizing mistakes, your customers receive instant, real-time feedback on their source PDF, PNG, or JPEG files in the browser before they can buy.

Through our Software as a Service validation engine, our platform performs automatic validation checks:
* **Resolution (DPI) Check** - Identifies blurry, low-resolution graphics.
* **Bleed & Critical Safety margins** - Ensures text and crucial art elements won't get cut at the guillotine.
* **Color Space and Profiles** - Verifies CMYK compliance versus RGB files.
* **Dimensions & Aspect Ratio** - Confirms the uploaded file matches your selected product size.
* **Fonts and Swatches** - Detects missing fonts or un-embedded vectors.

Once validated, the widget clears the customer to purchase, generates high-resolution print-ready proof thumbnails in the cart, and securely attaches a unique `jobId` to the order. After checkout, authorized store managers can view complete preflight reports and download optimized print-ready production files directly within the standard WooCommerce order dashboard.

== Benefits for Your Print Store ==

* **Drastically Reduce Refunds** - Prevent ordering with un-printable art.
* **Eliminate Prepress Back-and-Forth** - Save hours of manual prepress review per designer.
* **Smooth Checkout Integrity** - The widget features dynamic, seamless client-side button gating.
* **Secure Production Downloads** - High-resolution print outputs are transferred directly to a secured storage bucket in your WordPress upload folder, completely shielded from direct view.
* **Zero Configuration UI** - The beautiful preflight interface configures itself on the CDN based on your chosen workflow, blending perfectly into your existing theme rules.

== Installation ==

= Minimum Requirements =
* WordPress 4.0 or greater
* PHP version 7.4 or greater
* WooCommerce 4.0 or greater

= Automatic Installation =
1. Log in to your WordPress dashboard.
2. Navigate to the **Plugins** menu and click **Add New**.
3. In the search box, type `Filecheck` and click search.
4. Locate the plugin and click **Install Now**.
5. Once installed, click **Activate**.

= Manual Installation =
1. Download the plugin package.
2. Extract the archive and upload the `filecheck-wordpress` folder to your `/wp-content/plugins/` directory via SFTP or your hosting provider's file explorer.
3. Access your WordPress admin, head to the **Plugins** screen, and click **Activate** under **Filecheck**.

= Setup & Credentials =
1. Go to the newly created **Filecheck** page found in your left admin menu (or under **WooCommerce > Filecheck**).
2. Enter your **Publishable Key** and **Secret Key** created in your [Filecheck backoffice](https://admin.filecheck.io).
3. If necessary, provide your optional sub-tenant **Agent ID**.
4. Click **Test Connection** to immediately verify your keys communicate with our REST API.
5. Select your default global workflow from the dropdown menu and click **Save Settings**.
6. (Optional) To override the workflow or tie dynamic connectors on specific products, navigate to the product's edit screen and configure the settings within the custom **Filecheck** product data tab.

== Frequently Asked Questions ==

= Does it support mobile uploads? =
Yes. The Filecheck Element is fully responsive, optimized for touch interaction, and works beautifully across iOS, Android, and tablets.

= Where are the high-res files hosted and saved? =
Uploads are processed on Filecheck's high-speed preflight servers. When an order is completed, the plugin automatically streams the print-optimized production files back to your WordPress server into a hidden physical directory (`wp-content/uploads/filecheck-secure/`) protected by custom server rules.

= Will it break with other custom product option plugins? =
No. The widget uses a non-gating local storage session resume mechanism combined with an AJAX-synchronized WooCommerce session fallback. It doesn't modify native cart serialization, allowing it to co-exist cleanly with customization editors like PitchPrint or design template customizers.

== Screenshots ==

1. Preflight Element embedded inline on the WooCommerce product template page.
2. Filecheck Settings menu featuring credential configuration and automatic connection tests.
3. Per-product Filecheck management panel showing workflow assignment overrides and connector configurations.
4. Secured order details metabox presenting preflight reports, active file outcomes, and instant admin high-resolution download shortcuts.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Implemented async Filecheck Elements CDN integration.
* Added native form POST capture alongside robust WooCommerce AJAX session backup for guest and custom checkouts.
* Built product administration meta panels and global configuration screens.
* Built automatic server-side secure file fulfillment, streaming finalized outputs to protected directory structures.
