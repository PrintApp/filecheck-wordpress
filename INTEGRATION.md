
---

```markdown
# Filecheck — Plugin Integration Reference

> This document is for an AI coding agent (or human developer) building a
> Filecheck plugin for WordPress, Shopify, OpenCart, PrestaShop, or any
> other CMS / e-commerce platform. No access to the Filecheck monorepo is
> required.

---

## 1. What Filecheck is

Filecheck is a **file validation / preflight service**. It sits in front of
an "Upload" button and decides — by configurable rule — whether a file is fit
for purpose, and when possible auto-fixes it.

The integration pattern is always the same: the merchant configures a
**Workflow** in the Filecheck admin (which files are accepted, what validation
runs, what to do on failure). The plugin drops a widget onto the product page
that runs the customer's file through that Workflow before allowing them to
proceed.

---

## 2. Keys

| Key | Prefix | Use |
| --- | --- | --- |
| **Publishable key** | `pk_live_…` / `pk_test_…` | Embedded in page JS. Safe to ship in public code. |
| **Secret key** | `sk_live_…` / `sk_test_…` | **Server-side only.** Never put in browser code. |

Optional: **`agentId`** (`agt_…`) — scopes the element to a specific
sub-tenant / store (useful when one Filecheck account powers multiple stores).

---

## 3. The Filecheck Element

Load via CDN using the **pk-specific URL** (preferred for plugins — embeds your
tenant config so no separate config fetch is needed, cached by CloudFront per key):

```html
<script src="https://cdn.filecheck.io/element/{pk}/filecheck.js" async></script>
```

Replace `{pk}` with the merchant's publishable key, e.g.
`pk_live_abc123`. The generic fallback `https://cdn.filecheck.io/element/v1/filecheck.js`
still works but does not embed tenant config.

Either URL attaches `window.Filecheck` once loaded.

### 3.1 Full public API

```js
const fc = Filecheck('pk_live_...', {
    agentId?:   'agt_...',  // optional sub-tenant scope
    iframeSrc?: '...',      // staging/dev override only
});

const intake = fc.elements.create('intake', {
    workflowId?:   'wf_...',  // required unless resuming with jobId
    jobId?:        'job_...', // resume an existing job (workflowId ignored)
    locale?:       'en-US',
    presentation?: 'inline',  // 'inline' (default) | 'dialog'
    ui?: {
        title?:       string,
        subtitle?:    string,
        theme?:       'light' | 'dark' | 'system',
        accent?:      string,  // hex e.g. '#6366f1'
        layout?:      'inline' | 'modal' | 'drawer' | 'compact',
        showHeader?:  boolean,
        showHelp?:    boolean,
        helpText?:    string,
        submitLabel?: string,
        autoSubmit?:  boolean,
        branding?:    'domain' | 'none' | 'custom',
    },
});

intake.mount('#fc-slot');           // or .mount(htmlElement)

intake.on('ready',   ({ ui })            => { /* iframe ready; ui = resolved IntakeUi */ });
intake.on('status',  (payload)           => { /* IntakeStatusPayload — see §4 */ });
intake.on('ui',      (ui)               => { /* UI changed after update() */ });
intake.on('error',   ({ code, message }) => { /* recoverable iframe error */ });
intake.on('proof',   (payload)           => { /* soft-proof ready — see §5 */ });
intake.on('destroy', ()                  => { /* element unmounted */ });

intake.update({
    workflowId?:   'wf_...',
    jobId?:        'job_...',
    locale?:       'fr-CA',
    ui?:           { theme: 'dark' },
    presentation?: 'dialog',
});

intake.focus();                     // open modal/drawer layout programmatically
intake.blur();
intake.respondToProof(true);        // approve soft-proof gate (see §5)
intake.unmount();
```

`on()` returns an unsubscribe function.

---

## 4. The `status` event

**Gate your "Add to Cart" / "Submit" button on `canProceed`.**

```ts
interface IntakeStatusPayload {
    status:     'idle'        // no files yet (fires once at startup)
              | 'incomplete'  // fewer files than required
              | 'uploading'
              | 'processing'
              | 'ready'       // all files passed
              | 'partial'     // warnings only, or policy = accept_with_warnings
              | 'rejected';   // hard fail
    terminal:   boolean;
    canProceed: boolean;      // ← gate your submit button on THIS
    workflowId: string | null;
    jobId:      string | null;
    files: Array<{
        id:        string;
        name:      string;
        fileRef:   string | null;  // input file CDN ref
        outcome:   'pass' | 'warn' | 'fail' | null;
        status:    string;
        outputRef: string | null;  // auto-fixed output CDN ref (if any)
    }>;
}
```

`canProceed` is `true` only for `ready` and `partial`. Do **not** re-derive
it from `status` or `files` — Filecheck already collapses the workflow's
`onFail` policy into it.

**Minimal pattern:**

```js
intake.on('status', ({ canProceed, jobId }) => {
    submitBtn.disabled = !canProceed;
    hiddenJobIdInput.value = jobId ?? '';
});
```

---

## 5. Soft-proof gate (optional)

When the merchant enables proofing on the Workflow, the element fires `proof`
once pages are rendered:

```ts
interface ProofPayload {
    pages: Array<{ url: string; page: number; width: number; height: number; mimeType: string; bytes: number; key: string }>;
    approvalRequired?: boolean;  // if true, workflow halts until respondToProof() called
    message?:          string;
    affirmButtonText?: string;
}
```

```js
intake.on('proof', ({ pages, approvalRequired }) => {
    if (!approvalRequired) return; // informational only

    showProofGallery(pages, {
        onApprove: () => intake.respondToProof(true),
        onReject:  () => intake.respondToProof(false),
    });
});
```

If you don't implement a custom gallery, ignore the `proof` event — the
built-in iframe gallery handles it automatically.

---

## 6. Loading async

`window.Filecheck` is not available immediately. Poll:

```js
function waitForFilecheck(cb, timeout = 5000) {
    const start = Date.now();
    const t = setInterval(() => {
        if (window.Filecheck) { clearInterval(t); cb(window.Filecheck); }
        else if (Date.now() - start > timeout) { clearInterval(t); console.error('Filecheck failed to load'); }
    }, 50);
}

waitForFilecheck((Filecheck) => {
    const fc = Filecheck('pk_live_...');
    const intake = fc.elements.create('intake', { workflowId: 'wf_...' });
    intake.on('status', ({ canProceed, jobId }) => { /* … */ });
    intake.mount('#fc-slot');
});
```

---

## 7. Sizing

The widget self-sizes. Give the mount `<div>` a **width** only — never set a
fixed height. The iframe is wrapped in Shadow DOM so host CSS does not affect it.

---

## 8. Multiple elements on one page

Track `canProceed` per element separately:

```js
const states = {};
function addIntake(workflowId, slotId) {
    const el = fc.elements.create('intake', { workflowId });
    el.on('status', ({ canProceed, jobId }) => {
        states[slotId] = { canProceed, jobId };
        submitBtn.disabled = !Object.values(states).every(s => s.canProceed);
    });
    el.mount('#' + slotId);
}
```

---

## 9. Server-side: persisting the `jobId`

1. Write `jobId` into a hidden form field / cart attribute when `canProceed` is `true`.
2. Attach it to the order (line item meta, order note, etc.).
3. Post-purchase: use the **secret key** server-side to fetch results:

```
GET https://api.filecheck.io/jobs/{jobId}
GET https://api.filecheck.io/jobs/{jobId}?expand=runs   # full pipeline detail
```

Retrieve `files[n].outputRef` to download the auto-fixed file.

Webhooks (`job.created`, `job.completed`) can be configured in the Filecheck
admin to push results without polling.

---

## 10. WordPress / WooCommerce recipe

Settings page fields: publishable key, secret key, agentId, default
workflowId, per-product override, presentation, "block checkout if not ready".

```php
// Render mount slot + script on product page
add_action('woocommerce_before_add_to_cart_button', function () {
    global $product;
    $wf = get_post_meta($product->get_id(), '_fc_workflow_id', true)
          ?: get_option('fc_default_workflow_id');
    if (!$wf) return;
    $pk    = esc_js(get_option('fc_publishable_key'));
    $agent = esc_js(get_option('fc_agent_id'));
    $pid   = esc_attr($product->get_id());
    echo "<div id='fc-slot-{$pid}'></div>";
    echo "<input type='hidden' name='fc_job_id' id='fc-job-id-{$pid}' value=''>";
    $pk_attr = esc_attr(get_option('fc_publishable_key'));
    wp_enqueue_script('filecheck', "https://cdn.filecheck.io/element/{$pk_attr}/filecheck.js", [], null, true);
    wp_add_inline_script('filecheck', "
        waitForFilecheck(function(Filecheck) {
            var fc = Filecheck('{$pk}', { agentId: '{$agent}' });
            var el = fc.elements.create('intake', { workflowId: '{$wf}', presentation: 'inline' });
            el.on('status', function(e) {
                document.querySelector('.single_add_to_cart_button').disabled = !e.canProceed;
                document.getElementById('fc-job-id-{$pid}').value = e.jobId || '';
            });
            el.mount('#fc-slot-{$pid}');
        });
    ");
});

// Block cart-add server-side
add_filter('woocommerce_add_to_cart_validation', function ($valid, $product_id) {
    if (get_post_meta($product_id, '_fc_required', true) && empty($_POST['fc_job_id'])) {
        wc_add_notice('Please upload and validate your file first.', 'error');
        return false;
    }
    return $valid;
}, 10, 2);

// Persist jobId on cart → order
add_filter('woocommerce_add_cart_item_data', function ($data) {
    if (!empty($_POST['fc_job_id']))
        $data['fc_job_id'] = sanitize_text_field($_POST['fc_job_id']);
    return $data;
});
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values) {
    if (!empty($values['fc_job_id']))
        $item->update_meta_data('Filecheck Job', $values['fc_job_id']);
}, 10, 3);
```

---

## 11. Shopify recipe

1. **Theme app extension block** — drops `<div id="fc-slot">` + script tag onto
   the product page. Block settings: workflowId, presentation. Per-product via
   metafield `filecheck.workflowId`.
2. **Cart attributes** — write `jobId` to `cart.attributes['Filecheck Job']` via
   AJAX Cart API once `canProceed === true`; disable Add to Cart client-side until then.
3. **Order webhooks** (`orders/paid`) — read `note_attributes['Filecheck Job']`,
   call `GET /jobs/{id}` with the secret key, attach output file via Files API.
4. **Embedded admin** (Polaris + App Bridge) — key entry, workflow picker,
   default presentation, metafield writer.
5. **Billing** — usage-based via Shopify Billing API (per-check).

---

## 12. OpenCart / PrestaShop recipe

Same pattern as WordPress:

- Module admin: publishable key, secret key, agentId, default workflowId,
  per-product override.
- Product page: render `<div>` mount target + inline `<script>` calling
  `waitForFilecheck` → `Filecheck(pk)` → `elements.create('intake', { workflowId })`
  → `mount` → gate submit on `canProceed`.
- Add-to-cart / checkout: validate that `jobId` was submitted; persist as order
  attribute.
- Post-purchase: use the secret key server-side to fetch output and attach to order.

---

## 13. Common mistakes

| Mistake | Correct approach |
| --- | --- |
| `elements.create('intake', { ruleId: '…' })` | Option is `workflowId`, not `ruleId` |
| Re-deriving `canProceed` from `status` or `files` | Use `canProceed` as-is |
| Secret key `sk_…` in browser JS | Server-side only |
| Fixed `height` on the mount div | Set `width` only; widget self-sizes |
| Using `v1/filecheck.js` in a plugin | Use `/{pk}/filecheck.js` — embeds tenant config, one fewer request |
| Calling `Filecheck()` before async script loads | Use the polling helper (§6) |
| Two elements, assuming one controls both | Track `canProceed` per element (§8) |
| Ignoring `proof` event when `approvalRequired: true` | Call `respondToProof()` or workflow stalls |
```
