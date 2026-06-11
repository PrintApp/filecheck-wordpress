---
name: filecheck-wordpress
description: Reference knowledge for the Filecheck WooCommerce plugin. Use when working on any part of this plugin — frontend widget, settings, cart validation, fulfillment, or API integration.
---

## What this plugin does

Integrates the **Filecheck Element** (a file validation/preflight widget) onto WooCommerce product pages. Customers upload files before adding to cart; the widget runs the file through a configured **Workflow** and gates the add-to-cart button on the result.

---

## Project structure

```
filecheck.php                  — Main plugin entry point, constants, class loader
includes/
  class-api-client.php         — Server-side Filecheck REST API wrapper (secret key)
  class-settings.php           — Admin settings page + AJAX test-connection
  class-product.php            — Per-product Filecheck tab in WooCommerce product editor
  class-frontend.php           — Enqueues assets + renders widget slot on product page
  class-cart.php               — Cart validation, cart item data, order line item meta
  class-fulfillment.php        — Fetches output files from API on order processing; admin metabox
assets/js/frontend.js          — Client-side widget init (waitForFilecheck → mount → status events)
assets/js/admin.js             — Test-connection button handler
assets/css/frontend.css        — Slot wrapper, blocked-button style, dialog trigger button
assets/css/admin.css           — Product tab icon, settings page styles
```

---

## Keys

| Key | Where used |
|---|---|
| `pk_live_…` / `pk_test_…` | Publishable — embedded in CDN script URL and passed to `Filecheck()` in browser JS |
| `sk_live_…` / `sk_test_…` | Secret — server-side only (`class-api-client.php`). Never in browser code. |
| `agt_…` | Optional agentId — scopes to a sub-tenant |

WordPress options: `filecheck_publishable_key`, `filecheck_secret_key`, `filecheck_agent_id`

---

## CDN script

Always use the PK-specific URL — it embeds tenant config and saves a round-trip:

```
https://cdn.filecheck.io/element/{pk}/filecheck.js
```

In `class-frontend.php`, the URL is built with `rawurlencode( $pk )`.

---

## Workflow IDs (not "rule IDs")

The Filecheck API uses **`workflowId`** (`wf_…`). The option name `ruleId` is wrong and will be silently ignored.

- WordPress option: `filecheck_default_workflow_id`
- Per-product post meta: `_filecheck_workflow_id` (values: `'none'` | `'global'` | `wf_…`)
- Localized JS param: `filecheck_params.workflow_id`
- API endpoint for listing: `GET /workflows/` (not `/rules/`)
- `Filecheck_API_Client::get_workflows()` — returns array; response key is `workflows`

---

## Frontend JS pattern

```js
// Wait for async CDN script
whenReady().then(function(Filecheck) {
    var fc = Filecheck(pk, { agentId: agentId }); // agentId optional
    var el = fc.elements.create('intake', {
        workflowId: workflowId,   // 'wf_...'
        presentation: 'inline'    // or 'dialog'
    });
    el.on('status', function(e) {
        // Gate add-to-cart on canProceed — do NOT re-derive it from e.status
        submitBtn.disabled = !e.canProceed;
        jobIdInput.value = e.jobId || '';
    });
    el.on('error', function(err) { /* handle */ });
    el.mount('#fc-slot-' + productId);
});
```

**Dialog mode**: pass `presentation: 'dialog'` to `elements.create()`. The element manages its own dialog UI. Call `el.focus()` from a trigger button and `el.blur()` to dismiss. Do **not** wrap it in a custom modal overlay.

**`canProceed`**: use as-is from the status event — it is `true` for `ready` and `partial` statuses. Never recompute it from `status` or `files`.

---

## Server-side cart flow

1. `woocommerce_add_to_cart_validation` — blocks add if `filecheck_job_id` POST is empty and product has a workflow assigned (`class-cart.php::validate_add_to_cart`)
2. `woocommerce_add_cart_item_data` — copies `filecheck_job_id` into cart item data
3. `woocommerce_checkout_create_order_line_item` — writes `_filecheck_job_id` to order line item meta

---

## Fulfillment

Triggered on `woocommerce_order_status_processing` and `woocommerce_order_status_completed`.

```
GET https://api.filecheck.io/jobs/{jobId}?expand=runs   — fetch job + runs
GET /jobs/{jobId}/runs/{runId}/output                   — stream output file (Bearer sk_…)
```

Output files are saved to `wp-content/uploads/filecheck-secure/` (protected by `.htaccess: Deny from all`). Admin downloads via a nonce-protected `admin_init` handler.

Order is stamped `_filecheck_processed = yes` after first successful run to prevent duplicates.

**HPOS**: metabox is registered for both `add_meta_boxes_shop_order` (legacy CPT) and `add_meta_boxes_woocommerce_page_wc-orders` (HPOS). Plugin declares HPOS compatibility in `filecheck.php`.

---

## Common mistakes to avoid

| Wrong | Correct |
|---|---|
| `ruleId` in `elements.create()` | `workflowId` |
| `/rules/` API endpoint | `/workflows/` |
| Re-deriving `canProceed` from `status` | Use `canProceed` directly |
| Fixed `height` on mount `<div>` | Set `width` only — widget self-sizes |
| `v1/filecheck.js` CDN URL | `/{pk}/filecheck.js` |
| Custom modal wrapping a `presentation: 'dialog'` element | Use `el.focus()` / `el.blur()` |
| Secret key in browser JS | Server-side only |
