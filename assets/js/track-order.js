/**
 * Headless Track Order Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('track-form');
    const resultDiv = document.getElementById('track-result');
    const errorDiv = document.getElementById('track-error');
    
    // Parse URL params for pre-filling
    const urlParams = new URLSearchParams(window.location.search);
    const orderEmail = urlParams.get('email');
    
    if (orderEmail && document.getElementById('email')) document.getElementById('email').value = orderEmail;

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = document.getElementById('email').value.trim();
            
            errorDiv.style.display = 'none';
            resultDiv.style.display = 'none';
            
            if (!email) {
                errorDiv.textContent = 'Please provide an email address.';
                errorDiv.style.display = 'block';
                return;
            }

            try {
                const btn = form.querySelector('button');
                const origText = btn.textContent;
                btn.textContent = 'Searching...';
                btn.disabled = true;

                const res = await fetch(`/api/v1/orders?action=track&email=${encodeURIComponent(email)}`);
                const json = await res.json();
                
                btn.textContent = origText;
                btn.disabled = false;

                if (json.status === 200 && json.data && json.data.length > 0) {
                    // Update URL without reload
                    const newUrl = `/track-order.html?email=${encodeURIComponent(email)}`;
                    window.history.pushState({path: newUrl}, '', newUrl);
                    
                    renderOrders(json.data, email);
                } else {
                    errorDiv.textContent = json.error || 'No orders found for this email address.';
                    errorDiv.style.display = 'block';
                }
            } catch (err) {
                errorDiv.textContent = 'A network error occurred. Please try again.';
                errorDiv.style.display = 'block';
            }
        });
    }

    function renderOrders(orders, email) {
        // Hide form, show result
        if(form) form.parentElement.style.display = 'none';
        resultDiv.style.display = 'block';
        
        let allHtml = '';

        orders.forEach(order => {
            const date = new Date(order.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            
            const statuses = ['pending', 'processing', 'shipped', 'delivered'];
            const currentIndex = statuses.indexOf(order.status);
            const isCancelled = ['cancelled', 'refunded'].includes(order.status);
            
            let timelineHtml = '';
            if (isCancelled) {
                timelineHtml = `<div class="lume-alert lume-alert--error" style="border:1px solid var(--terracotta);background:transparent;color:var(--terracotta)">This order has been ${order.status}.</div>`;
            } else {
                timelineHtml += `<div style="display:flex;justify-content:space-between;position:relative">
                    <div style="position:absolute;top:15px;left:0;right:0;height:2px;background:var(--border);z-index:1"></div>`;
                if (currentIndex !== -1) {
                    timelineHtml += `<div style="position:absolute;top:15px;left:0;width:${(currentIndex / 3) * 100}%;height:2px;background:var(--gold);z-index:2;transition:width 1s"></div>`;
                }
                
                statuses.forEach((s, i) => {
                    const active = currentIndex !== -1 && currentIndex >= i;
                    const current = currentIndex === i;
                    timelineHtml += `
                    <div style="position:relative;z-index:3;display:flex;flex-direction:column;align-items:center;width:80px">
                        <div style="width:32px;height:32px;border-radius:50%;background:${active ? 'var(--gold)' : 'var(--surface)'};border:2px solid ${active ? 'var(--gold)' : 'var(--border)'};display:flex;align-items:center;justify-content:center;margin-bottom:8px;color:${active ? '#fff' : 'var(--muted)'}">
                            ${active ? '✓' : (i+1)}
                        </div>
                        <span style="font-size:.8rem;font-weight:${current ? '600' : '400'};color:${active ? 'var(--text)' : 'var(--muted)'};text-align:center;text-transform:capitalize;">${s}</span>
                    </div>`;
                });
                timelineHtml += `</div>`;
            }

            let itemsHtml = '';
            order.items.forEach(item => {
                const variantInfo = (item.variant_size || item.variant_color) 
                    ? `<div style="font-size:.85rem;color:var(--muted);margin-top:4px">${(item.variant_size||'') + ' ' + (item.variant_color||'')}</div>`
                    : '';
                itemsHtml += `
                <div style="display:flex;justify-content:space-between;padding:16px;border-bottom:1px solid var(--border)">
                    <div>
                        <strong>${item.name}</strong> &times; ${item.quantity}
                        ${variantInfo}
                    </div>
                    <div style="font-weight:500">EGP ${parseFloat(item.subtotal).toFixed(2)}</div>
                </div>`;
            });

            let discountHtml = '';
            if (parseFloat(order.discount) > 0) {
                discountHtml = `
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.9rem;color:var(--terracotta)">
                    <span>Discount</span><span>-EGP ${parseFloat(order.discount).toFixed(2)}</span>
                </div>`;
            }
            
            let verifyPaymentBtn = '';
            if (order.status === 'pending_payment') {
                verifyPaymentBtn = `<a href="/instapay-upload.php?order_id=${order.id}" class="lume-btn lume-btn--solid" style="padding:6px 12px;font-size:.8rem;text-decoration:none;white-space:nowrap">Verify Payment</a>`;
            }

            // Raw DB values are snake_case ("pending_payment"), and the badge
            // uppercases them — so it rendered as "PENDING_PAYMENT" and blew
            // past the right edge of the card on narrow screens.
            const statusLabel = String(order.status || '').replace(/_/g, ' ');

            allHtml += `
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:clamp(20px,5vw,32px);margin-bottom:40px;">
                <!-- Header wraps on narrow screens. Without flex-wrap the title
                     column got squeezed to a sliver and the order number broke
                     one hyphen-group per line, while the status badge overflowed
                     off the right edge of the card. -->
                <div style="display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between;align-items:flex-start;border-bottom:1px solid var(--border);padding-bottom:24px;margin-bottom:24px">
                    <div style="flex:1 1 240px;min-width:0">
                        <h2 style="font-family:var(--font-serif);font-size:clamp(1.15rem,4.6vw,1.8rem);line-height:1.25;margin-bottom:8px;overflow-wrap:anywhere">Order <span style="color:var(--gold)">${order.order_number}</span></h2>
                        <p style="color:var(--muted);font-size:.9rem">Placed on ${date}</p>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;flex-shrink:0">
                        ${verifyPaymentBtn}
                        <span style="display:inline-block;padding:6px 12px;border-radius:4px;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;background:rgba(212,163,115,.1);color:var(--gold)">
                            ${statusLabel}
                        </span>
                    </div>
                </div>

                <div style="margin-bottom:40px">
                    <h3 style="font-family:var(--font-serif);font-size:1.2rem;text-transform:uppercase;margin-bottom:16px">Status Updates</h3>
                    ${timelineHtml}
                </div>

                <div style="display:grid;grid-template-columns:1fr;gap:32px">
                    <div>
                        <h3 style="font-family:var(--font-serif);font-size:1.2rem;text-transform:uppercase;margin-bottom:16px">Items Ordered</h3>
                        <div style="border:1px solid var(--border);border-radius:4px">
                            ${itemsHtml}
                            
                            <div style="padding:16px;background:var(--bg)">
                                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.9rem;color:var(--muted)">
                                    <span>Subtotal</span><span>EGP ${parseFloat(order.subtotal).toFixed(2)}</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.9rem;color:var(--muted)">
                                    <span>Shipping</span><span>EGP ${parseFloat(order.shipping_cost).toFixed(2)}</span>
                                </div>
                                ${discountHtml}
                                <div style="display:flex;justify-content:space-between;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);font-size:1.1rem;font-weight:600">
                                    <span>Total</span><span>EGP ${parseFloat(order.total).toFixed(2)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                        <div>
                            <h3 style="font-family:var(--font-serif);font-size:1rem;text-transform:uppercase;margin-bottom:12px">Shipping Details</h3>
                            <p style="font-size:.9rem;line-height:1.6;color:var(--muted)">
                                <strong>${order.shipping_name}</strong><br>
                                ${order.shipping_addr}<br>
                                ${order.shipping_city}<br>
                                ${order.shipping_country}
                            </p>
                        </div>
                        <div>
                            <h3 style="font-family:var(--font-serif);font-size:1rem;text-transform:uppercase;margin-bottom:12px">Contact</h3>
                            <p style="font-size:.9rem;line-height:1.6;color:var(--muted)">
                                ${order.phone || '—'}<br>
                                ${order.guest_email || order.user_email || email}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            `;
        });
        
        allHtml += `
            <div style="margin-top:40px;text-align:center">
                <button class="lume-btn" onclick="window.location.reload()">Track Another Email</button>
            </div>
        `;
        
        resultDiv.innerHTML = allHtml;
    }
    
    // Auto-trigger if URL has params
    if (orderEmail && form) {
        form.dispatchEvent(new Event('submit'));
    }
});