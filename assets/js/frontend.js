(function() {
    'use strict';

    if (typeof filecheck_params === 'undefined') return;

    // Short-polling helper to wait for the async CDN library to attach window.Filecheck
    function whenReady() {
        return new Promise(function(resolve, reject) {
            var started = Date.now();
            (function tick() {
                if (window.Filecheck && window.Filecheck.mount) return resolve();
                if (Date.now() - started > 10000) return reject(new Error('Filecheck failed to load after 10s'));
                setTimeout(tick, 50);
            })();
        });
    }

    var STORAGE_KEY_PREFIX = 'fc_job_';

    function getStoredJobId(productId) {
        try {
            return localStorage.getItem(STORAGE_KEY_PREFIX + productId) || null;
        } catch (e) {
            return null;
        }
    }

    function storeJobId(productId, jobId) {
        try {
            if (jobId) {
                localStorage.setItem(STORAGE_KEY_PREFIX + productId, jobId);
            } else {
                localStorage.removeItem(STORAGE_KEY_PREFIX + productId);
            }
        } catch (e) { /* storage unavailable */ }
    }

    function saveJobIdToSession(productId, jobId) {
        var body = new URLSearchParams({
            action:     'filecheck_save_job',
            nonce:      filecheck_params.nonce,
            product_id: productId,
            job_id:     jobId || '',
        });
        fetch(filecheck_params.ajax_url, {
            method:      'POST',
            credentials: 'same-origin',
            body:        body,
        }).catch(function(err) {
            console.warn('[Filecheck WooCommerce] Session save failed:', err);
        });
    }

    function init() {
        var p            = filecheck_params;
        var slotSelector = '#fc-slot-' + p.product_id;
        var slot         = document.querySelector(slotSelector);

        if (!slot) return;

        var form = slot.closest('form.cart');

        whenReady().then(function() {
            var config = {
                ...(window?.FILECHECK_CONFIG || {}),
                publishableKey:     p.publishable_key,
                workflowId:         p.workflow_id,
                mountSelector:      slotSelector,
                agentId:            p.agent_id || null,
            };
            if (p.connector_id) config.connectorId = p.connector_id;

            // Resume a previous job if the customer refreshes the page
            var resumeJobId = getStoredJobId(p.product_id);
            if (resumeJobId) config.jobId = resumeJobId;

            var el = window.Filecheck.mount(config);

            if (el) {
                el.on('status', function(e) {
                    // Persist jobId locally so a page refresh can resume
                    storeJobId(p.product_id, e.jobId || null);

                    // Persist to WC session so AJAX cart plugins can read it server-side
                    saveJobIdToSession(p.product_id, e.jobId || null);

                    // Keep hidden input in sync for server-side cart validation
                    if (form) {
                        var jobInput = form.querySelector('input[name="filecheck_job_id"]');
                        if (jobInput) jobInput.value = e.jobId || '';
                    }
                });
            }

            // Clear stored jobId once the customer successfully adds to cart,
            // so the next visit starts fresh
            if (form) {
                form.addEventListener('submit', function() {
                    storeJobId(p.product_id, null);
                }, { once: true });
            }

        }).catch(function(err) {
            console.error('[Filecheck WooCommerce] Initialization error:', err);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
