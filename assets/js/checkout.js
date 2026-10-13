document.addEventListener('DOMContentLoaded', function() {
    const configEl = document.getElementById('checkout-config');
    if (!configEl) return;
    const config = JSON.parse(configEl.textContent);

    const subtotal = config.subtotal;
    const freeShippingOver = config.freeShippingOver;
    const codFee = config.codFee;
    const currency = config.currency;
    
    const selectZone = document.getElementById('shipping_zone');
    const radios = document.querySelectorAll('input[name="payment_method"]');
    const txtShipping = document.getElementById('txt-shipping');
    const txtTotal = document.getElementById('txt-total');
    const btnSubmit = document.getElementById('checkout-btn');
    const rowCod = document.getElementById('row-cod');
    const labelOnline = document.getElementById('label-online');
    const labelCod = document.getElementById('label-cod');
    const labelInstapay = document.getElementById('label-instapay');

    function formatMoneyHtml(amount) {
        return '<span class="lume-price-val">' + parseFloat(amount).toFixed(2) + '</span><span class="lume-price-cur">' + currency + '</span>';
    }

    function formatMoneyText(amount) {
        return currency + parseFloat(amount).toFixed(2);
    }

    function updateTotal() {
        try {
            let shipping = 0;
            const opt = selectZone.options[selectZone.selectedIndex];
            
            if (opt && opt.value) {
                shipping = parseFloat(opt.getAttribute('data-cost')) || 0;
                if (freeShippingOver > 0 && subtotal >= freeShippingOver) {
                    shipping = 0;
                }
            }

            let total = subtotal - (parseFloat(config.discount) || 0) + shipping;
            let isCod = false;
            
            // Active border styling for radio buttons
            if(labelOnline) labelOnline.style.borderColor = 'var(--border)';
            if(labelCod) labelCod.style.borderColor = 'var(--border)';
            if(labelInstapay) labelInstapay.style.borderColor = 'var(--border)';

            radios.forEach(r => {
                if (r.checked) {
                    if (r.value === 'cod') {
                        isCod = true;
                        total += codFee;
                        if(labelCod) labelCod.style.borderColor = 'var(--terracotta)';
                    } else if (r.value === 'instapay') {
                        if(labelInstapay) labelInstapay.style.borderColor = 'var(--terracotta)';
                    } else {
                        if(labelOnline) labelOnline.style.borderColor = 'var(--terracotta)';
                    }
                }
            });

            // Update UI
            if (!opt || !opt.value) {
                txtShipping.innerHTML = 'Select a city';
            } else {
                txtShipping.innerHTML = shipping === 0 ? 'Free' : formatMoneyHtml(shipping);
            }

            if (rowCod) {
                rowCod.style.display = (isCod && codFee > 0) ? 'flex' : 'none';
            }

            txtTotal.innerHTML = formatMoneyHtml(total);
            btnSubmit.innerText = 'Place Order — ' + formatMoneyText(total);
        } catch (e) {
            btnSubmit.innerText = 'Error: ' + e.message;
        }
    }

    selectZone.addEventListener('change', updateTotal);
    radios.forEach(r => r.addEventListener('change', updateTotal));
    
    try {
        updateTotal(); // initial run
    } catch (e) {
        btnSubmit.innerText = 'Init Error: ' + e.message;
    }

    // ── Save checkout details as the shopper types ──
    // Lets you follow up with people who start checkout but never order.
    // Only sent once there is a phone number or email; see api/checkout-lead.php.
    (function () {
        const form = document.getElementById('checkout-form');
        if (!form) return;
        const ENDPOINT = ((document.getElementById('lume-base-url') || {}).value || '') + '/api/checkout-lead.php';
        const field = id => document.getElementById(id);
        let lastSent = '';
        let timer = null;

        function payload() {
            const zoneOpt = selectZone.options[selectZone.selectedIndex];
            return {
                csrf:  (form.querySelector('input[name="csrf"]') || {}).value || '',
                name:  (field('shipping_name') || {}).value || '',
                email: (field('email') || {}).value || '',
                phone: (field('phone') || {}).value || '',
                city:  zoneOpt && zoneOpt.value ? zoneOpt.text.trim() : ''
            };
        }

        function worthSending(p) {
            const digits = p.phone.replace(/\D/g, '');
            return digits.length >= 8 || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(p.email.trim());
        }

        function send(useBeacon) {
            clearTimeout(timer);
            const p = payload();
            if (!worthSending(p)) return;
            const sig = JSON.stringify([p.name, p.email, p.phone, p.city]);
            if (sig === lastSent) return;
            lastSent = sig;
            const fd = new FormData();
            Object.keys(p).forEach(k => fd.append(k, p[k]));
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(ENDPOINT, fd);
            } else {
                fetch(ENDPOINT, { method: 'POST', body: fd, keepalive: true, credentials: 'same-origin',
                                  headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
            }
        }

        ['shipping_name', 'email', 'phone'].forEach(id => {
            const el = field(id);
            if (!el) return;
            el.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => send(false), 1200); });
            el.addEventListener('blur', () => send(false));
        });
        selectZone.addEventListener('change', () => send(false));
        // Leaving the page or switching away from the Instagram browser.
        window.addEventListener('pagehide', () => send(true));
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') send(true); });
    })();
});
