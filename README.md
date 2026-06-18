# Filecheck WooCommerce Plugin

This plugin integrates the **Filecheck Element** preflight widget directly into WooCommerce product pages. It allows customers to upload and validate print-ready assets before proceeding to add their item to the cart. 

---

## Architecture Overview

The plugin is structured as follows:

- [filecheck.php](filecheck.php)  
  The main entry point of the plugin. Declares standard constants, registers HPOS (High-Performance Order Storage) compatibility, and bootstraps the classes.

- [includes/class-api-client.php](includes/class-api-client.php)  
  Server-side wrapper to connect with the Filecheck REST API. Handles authenticating keys, listing active workflows, registering order details, and fetching job summaries/run outputs.

- [includes/class-settings.php](includes/class-settings.php)  
  Implements the global Wordpress Settings page found under WooCommerce > Filecheck. Handles saving API credentials (Publishable Key, Secret Key, Agent ID, and API Base URL Override) and your default workflow. Features an AJAX test-connection utility to verify credentials.

- [includes/class-product.php](includes/class-product.php)  
  Adds a custom "Filecheck" data panel tab within WooCommerce's standard Product editor. Allows shop administrators to configure a product-specific workflow override, hook up a target connector, or set per-product presentation overrides.

- [includes/class-frontend.php](includes/class-frontend.php)  
  Enqueues the asynchronous CDN element script, styles, and initializers on product pages. Injects the placeholder target div and hidden inputs used to hold progress state.

- [includes/class-cart.php](includes/class-cart.php)  
  Manages cart data persistence, guest cart session fallbacks, and order creation. It captures the unique `jobId` from the browser via both a standard hidden POST input and an AJAX session endpoint to ensure reliability on AJAC-based or heavily optimized checkouts.

- [includes/class-fulfillment.php](includes/class-fulfillment.php)  
  Fulfillment sync that updates the Filecheck API about new order placements, triggers order status updates, and handles downloading finalized print PDFs to a local secure storage directory upon order completion (`wp-content/uploads/filecheck-secure/`).

- [assets/js/frontend.js](assets/js/frontend.js)  
  Controls client-side initialization, mounts the component on the target selectors, and bridges status events between the element and the WooCommerce front-end context.

---

## Installation & Setup

1. Compress this module or copy the folder directly to your WordPress installations `/wp-content/plugins/` directory.
2. Activate **Filecheck** under the WordPress **Plugins** screen.
3. Access WooCommerce / **Filecheck Settings** from the dashboard.
4. Input your **Publishable Key** and **Secret Key**.
5. Click **Test Connection** to verify that your API credentials communicate with the Filecheck endpoints, and then select a default global workflow.
6. Click **Save Settings**.

---

## How It Works

### 1. Initialization
When a user visits a product page with an assigned Filecheck workflow, the plugin enqueues the per-tenant CDN client library:
`https://cdn.filecheck.io/element/{publishable_key}/filecheck.js`

[includes/class-frontend.php](includes/class-frontend.php) injects a target container element with a unique ID:
`<div id="fc-slot-{product_id}"></div>`

### 2. Client Mounting
[assets/js/frontend.js](assets/js/frontend.js) awaits the presence of the `Filecheck` library, constructs the required mounting configurations, and calls `window.Filecheck.mount(config)`.

Passes `'#button-cart'` or `'.single_add_to_cart_button'` as the `cartButtonSelector` configuration option, instructing the Element itself to control the Add to Cart button state, disabling it dynamically until valid files are provided.

### 3. Session Resilience & Redundancy
Because WooCommerce checkouts take various shapes and some plugins hijack the cart form, the plugin uses a dual-gating pattern:
- **Form POST Gating:** Captures the `jobId` inside a hidden input field named `filecheck_job_id`.
- **WooCommerce Session Gating:** Simultaneously pushes the current `jobId` to a nonce-protected backend AJAX action, which saves it inside `WC()->session` under the key `fc_job_{product_id}`.

When standard forms are overridden or AJAX-cart engines bypass DOM inputs, WooCommerce still reads the `jobId` from the active cart session during validation and checkout.

### 4. Resuming Unfinished Uploads
To prevent users losing their progress when reloading the page, the user's current `jobId` is saved to `localStorage` against the product ID on every status tick. When the initialize event detects a stored value, it pre-populates the config object's `jobId` parameter, allowing the element to seamlessly resume.

The `localStorage` key is automatically purged when the user successfully clicks the add-to-cart submit action.

### 5. Order Synchronization & Fulfillment
Upon standard or quick checkout:
- The `_filecheck_job_id` is captured and permanent line-item metadata is created.
- The order status and billing information is posted securely back to Filecheck so you can track job origins in the merchant back-office.
- When an order switches to **Processing** or **Completed**, the fulfillment module automatically fetches the preflighted runs and downloads secure high-resolution outputs to a shielded directory protected by a `.htaccess` direct access rule under [uploads/filecheck-secure/](includes/class-fulfillment.php).
- Merchants can inspect and download verified files directly within the WooCommerce Order dashboard through the custom Filecheck summary container.

