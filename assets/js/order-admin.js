(function () {
    'use strict';

    var panel = document.getElementById('filecheck-job-panel');
    if (!panel || typeof filecheck_order_admin === 'undefined') {
        return;
    }

    var i18n = filecheck_order_admin.i18n;
    var orderId = panel.getAttribute('data-order-id');

    function badge(outcome) {
        var map = {
            pass: ['#00a32a', 'PASS'],
            warn: ['#dba617', 'WARN'],
            fail: ['#d63638', 'FAIL']
        };
        var b = map[outcome] || ['#787c82', (outcome || 'PENDING').toUpperCase()];
        return '<span style="display:inline-block;padding:1px 7px;border-radius:3px;font-size:10px;' +
            'font-weight:600;color:#fff;background:' + b[0] + '">' + b[1] + '</span>';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function render(items) {
        if (!items || !items.length) {
            panel.innerHTML = '<p>' + esc(i18n.noFiles) + '</p>';
            return;
        }

        var html = '';
        items.forEach(function (item) {
            html += '<div style="margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #eee;">';
            html += '<strong>' + esc(item.itemName) + '</strong><br>';

            if (item.error) {
                html += '<span style="color:#d63638;font-size:11px;">' + esc(item.error) + '</span><br>';
            } else {
                if (item.status) {
                    html += '<span style="font-size:11px;color:#666;">Status: ' + esc(item.status) + '</span><br>';
                }
                if (item.files?.length) {
                    html += '<ul style="margin:8px 0 0;padding:0;list-style:none;">';
                    item.files.forEach(function (f) {
                        html += '<li style="margin:0 0 10px;">';
                        html += badge(f.outcome) + '<br/><span style="font-size:12px;">' + esc(f.name) + '</span>';

                        if (f.proofs?.length) {
                            html += '<div style="margin:5px 0;display:flex;gap:4px;flex-wrap:wrap;">';
                            f.proofs.forEach(function (p) {
                                html += '<a href="' + esc(p.url) + '" target="_blank" rel="noopener">' +
                                    '<img src="' + esc(p.url) + '" alt="proof" ' +
                                    'style="width:48px;height:48px;object-fit:cover;border:1px solid #ddd;border-radius:3px;"></a>';
                            });
                            html += '</div>';
                        } else {
                            html += '<br/>';
                        }

                        if (f.downloadUrl) {
                            html += '<a href="' + esc(f.downloadUrl) + '" target="_blank" rel="noopener" class="button button-small" style="margin-top:3px;">' +
                                esc(i18n.download) + '</a>';
                        }
                        html += '</li>';
                    });
                    html += '</ul>';
                } else {
                    html += '<span style="font-size:11px;color:#666;">' + esc(i18n.noFiles) + '</span><br>';
                }
            }

            html += '<a href="' + esc(item.adminUrl) + '" target="_blank" rel="noopener" ' +
                'class="button button-small" style="margin-top:5px;">' + esc(i18n.view) + '</a>';
            html += '</div>';
        });

        panel.innerHTML = html;
    }

    var params = new URLSearchParams();
    params.append('action', 'filecheck_get_job_details');
    params.append('nonce', filecheck_order_admin.nonce);
    params.append('order_id', orderId);

    fetch(filecheck_order_admin.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                render(res.data.items);
            } else {
                panel.innerHTML = '<p style="color:#d63638;">' + esc(i18n.error) + '</p>';
            }
        })
        .catch(function () {
            panel.innerHTML = '<p style="color:#d63638;">' + esc(i18n.error) + '</p>';
        });
})();
