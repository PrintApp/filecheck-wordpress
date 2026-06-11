(function() {
    'use strict';
    
    if (typeof filecheck_params === 'undefined') {
        return;
    }
    
    // Short-polling helper to wait for the async CDN library to attach window.Filecheck
    function whenReady() {
        return new Promise(function(resolve, reject) {
            var started = Date.now();
            var tick = function() {
                if (typeof window.Filecheck === 'function') {
                    return resolve(window.Filecheck);
                }
                if (Date.now() - started > 10000) {
                    return reject(new Error('Filecheck failed to load after 10s'));
                }
                setTimeout(tick, 50);
            };
            tick();
        });
    }
    
    function init() {
        var productId    = filecheck_params.product_id;
        var slotSelector = '#fc-slot-' + productId;
        var slot         = document.querySelector(slotSelector);
        
        if (!slot) {
            return;
        }
        
        var workflowId    = filecheck_params.workflow_id;
        var presentation  = filecheck_params.presentation;
        var agentId       = filecheck_params.agent_id;
        var blockCheckout = filecheck_params.block_checkout;
        
        var form   = slot.closest('form.cart');
        if (!form) {
            return;
        }
        
        var button = form.querySelector('.single_add_to_cart_button');
        
        // If gating is enabled, disable add-to-cart by default on page load
        if (blockCheckout && button) {
            button.disabled = true;
            button.classList.add('fc-blocked');
        }
        
        whenReady().then(function(Filecheck) {
            var fcOpts = {};
            if (agentId) {
                fcOpts.agentId = agentId;
            }
            
            var fc = Filecheck(filecheck_params.publishable_key, fcOpts);
            var el;
            
            if (presentation === 'dialog') {
                // Render trigger button; the element's own dialog UI handles the overlay
                var triggerBtn = document.createElement('button');
                triggerBtn.type = 'button';
                triggerBtn.className = 'button alt fc-trigger-btn';
                triggerBtn.textContent = 'Upload & Verify Files';
                
                if (button) {
                    button.parentNode.insertBefore(triggerBtn, button);
                } else {
                    form.appendChild(triggerBtn);
                }
                
                triggerBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (el) {
                        el.focus();
                    }
                });
                
                // Create intake element in dialog mode — the element manages its own dialog UI
                el = fc.elements.create('intake', {
                    workflowId: workflowId,
                    presentation: 'dialog'
                });
            } else {
                // Inline mode
                el = fc.elements.create('intake', {
                    workflowId: workflowId,
                    presentation: 'inline'
                });
            }
            
            // Mount the element
            el.mount(slotSelector);
            
            // Handle events
            el.on('status', function(e) {
                // Save Job ID & canProceed
                var jobInput = form.querySelector('input[name="filecheck_job_id"]');
                var proceedInput = form.querySelector('input[name="filecheck_can_proceed"]');
                
                if (jobInput) {
                    jobInput.value = e.jobId || '';
                }
                if (proceedInput) {
                    proceedInput.value = e.canProceed ? '1' : '0';
                }
                
                // Update Add to Cart button based on the rule output canProceed flag
                if (button && blockCheckout) {
                    button.disabled = !e.canProceed;
                    if (e.canProceed) {
                        button.classList.remove('fc-blocked');
                    } else {
                        button.classList.add('fc-blocked');
                    }
                }
            });
            
            el.on('error', function(err) {
                console.error('[Filecheck WooCommerce] Widget error:', err);
            });
            
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
