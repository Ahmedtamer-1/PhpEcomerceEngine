/**
 * LUMEEGY — Main JavaScript (V3)
 */
(function(){
'use strict';
const BASE = (document.getElementById('lume-base-url')||{}).value || '';
const CSRF = (document.getElementById('lume-csrf')||{}).value || '';
const CURRENCY = document.querySelector('meta[name="lume-currency-symbol"]')?.content || 'EGP ';
window.__lumeSymbol = CURRENCY;
// Escape text before it goes into an innerHTML template.
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const imgUrl = src => String(src || '').startsWith('http') ? src : BASE + '/' + String(src || '').replace(/^\//, '');
// Resized WebP through thumb.php (full-size originals average ~1.5MB each).
const thumbSrc = (src, w) => { src = String(src || ''); if (!src || /^https?:/.test(src) || /\.svg(\?|$)/i.test(src)) return imgUrl(src); return BASE + '/thumb.php?src=' + encodeURIComponent(src.replace(/^\//, '')) + '&w=' + w; };
window.lumeThumbSrc = thumbSrc;
// Skeleton placeholder rows (cart drawer, search) while data loads.
const skelRows = n => Array.from({length: n}, () =>
  '<div class="lume-skel-row" aria-hidden="true"><div class="lume-skel lume-skel-row__img"></div><div class="lume-skel-row__lines"><div class="lume-skel lume-skel-line lume-skel-line--w60"></div><div class="lume-skel lume-skel-line lume-skel-line--w40"></div><div class="lume-skel lume-skel-line lume-skel-line--w25"></div></div></div>').join('');
// Safety net: never leave an image hidden behind its skeleton.
const revealAllImages = () => document.querySelectorAll('img:not(.is-loaded)').forEach(i => { if (i.complete) i.classList.add('is-loaded'); });
window.addEventListener('load', revealAllImages);
setTimeout(() => document.querySelectorAll('img:not(.is-loaded)').forEach(i => i.classList.add('is-loaded')), 6000);

// ─── Header scroll ───
const header = document.getElementById('lume-header');
if(header){
  window.addEventListener('scroll',()=>{
    header.classList.toggle('scrolled', window.scrollY > 50);
  },{passive:true});
}

// ─── Mobile menu ───
const mobileMenu = document.getElementById('lume-mobile-menu');
const hamburger = document.getElementById('lume-hamburger');
const mobileClose = document.getElementById('mobile-menu-close');
const mobileOverlay = document.getElementById('mobile-menu-overlay');
function openMobile(){mobileMenu&&mobileMenu.classList.add('active');document.body.style.overflow='hidden';}
function closeMobile(){mobileMenu&&mobileMenu.classList.remove('active');document.body.style.overflow='';}
hamburger&&hamburger.addEventListener('click',openMobile);
mobileClose&&mobileClose.addEventListener('click',closeMobile);
mobileOverlay&&mobileOverlay.addEventListener('click',closeMobile);

// ─── Search overlay ───
const searchOverlay = document.getElementById('lume-search-overlay');
const searchInput = document.getElementById('lume-search-input');
const searchResults = document.getElementById('lume-search-results');
const searchCloseBtn = document.getElementById('search-close');
const btnSearch = document.getElementById('btn-search');
let searchTimer;
function openSearch(){searchOverlay&&searchOverlay.classList.add('active');searchInput&&searchInput.focus();}
function closeSearch(){searchOverlay&&searchOverlay.classList.remove('active');if(searchInput)searchInput.value='';if(searchResults)searchResults.innerHTML='';}
// Attach listeners immediately (script is at end of body, DOM is ready)
btnSearch&&btnSearch.addEventListener('click',openSearch);
searchCloseBtn&&searchCloseBtn.addEventListener('click',closeSearch);
searchOverlay&&searchOverlay.addEventListener('click',(e)=>{if(e.target===searchOverlay)closeSearch();});
// Also re-bind on DOMContentLoaded as a safety-net for any edge case
document.addEventListener('DOMContentLoaded',function(){
  const bs=document.getElementById('btn-search');
  if(bs&&!bs.dataset.lumebound){bs.dataset.lumebound='1';bs.addEventListener('click',openSearch);}
  const sc=document.getElementById('search-close');
  if(sc&&!sc.dataset.lumebound){sc.dataset.lumebound='1';sc.addEventListener('click',closeSearch);}
});
if(searchInput){
  searchInput.addEventListener('input',()=>{
    clearTimeout(searchTimer);
    const q=searchInput.value.trim();
    if(q.length<2){searchResults.innerHTML='';return;}
    searchResults.innerHTML=skelRows(3);
    searchTimer=setTimeout(()=>{
      fetch(BASE+'/api/search.php?q='+encodeURIComponent(q),{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(data=>{
        if(!data.results||data.results.length===0){
          searchResults.innerHTML='<div class="lume-search-empty">No results found</div>';return;
        }
        searchResults.innerHTML=data.results.map(p=>`
          <a href="${BASE}/product.php?slug=${encodeURIComponent(p.slug)}" class="lume-search-result">
            <img src="${esc(imgUrl(p.image))}" class="lume-search-thumb" alt="${esc(p.name)}">
            <div class="lume-search-info">
              <span class="lume-search-type">${esc(p.category_name||'Product')}</span>
              <span class="lume-search-title">${esc(p.name)}</span>
              <span class="lume-search-price">${p.display_price}</span>
            </div>
          </a>`).join('');
      }).catch(()=>{searchResults.innerHTML='<div class="lume-search-empty">Search error</div>';});
    },300);
  });
}

// ─── Account dropdown ───
const btnAccount = document.getElementById('btn-account');
const accountDD = document.getElementById('lume-account-dropdown');
function toggleAccountDD(e){e.stopPropagation();accountDD&&accountDD.classList.toggle('active');}
btnAccount&&btnAccount.addEventListener('click',toggleAccountDD);
document.addEventListener('click',()=>{accountDD&&accountDD.classList.remove('active');});
document.addEventListener('DOMContentLoaded',function(){
  const ba=document.getElementById('btn-account');
  if(ba&&!ba.dataset.lumebound){ba.dataset.lumebound='1';ba.addEventListener('click',toggleAccountDD);}
});

// ─── Cart drawer ───
const cartDrawer = document.getElementById('lume-cart-drawer');
const cartClose = document.getElementById('cart-close');
const drawerOverlay = document.getElementById('lume-drawer-overlay');
const btnCart = document.getElementById('btn-cart');
const cartItems = document.getElementById('cart-drawer-items');
const cartFooter = document.getElementById('cart-drawer-footer');
const cartTotal = document.getElementById('cart-drawer-total');
const cartBadge = document.getElementById('cart-count-badge');

function openCart(){
  cartDrawer&&cartDrawer.classList.add('active');
  drawerOverlay&&drawerOverlay.classList.add('active');
  document.body.style.overflow='hidden';
  loadCart();
}
function closeCart(){
  cartDrawer&&cartDrawer.classList.remove('active');
  drawerOverlay&&drawerOverlay.classList.remove('active');
  document.body.style.overflow='';
}
btnCart&&btnCart.addEventListener('click',openCart);
cartClose&&cartClose.addEventListener('click',closeCart);
drawerOverlay&&drawerOverlay.addEventListener('click',closeCart);
document.addEventListener('DOMContentLoaded',function(){
  const bc=document.getElementById('btn-cart');
  if(bc&&!bc.dataset.lumebound){bc.dataset.lumebound='1';bc.addEventListener('click',openCart);}
  const cc=document.getElementById('cart-close');
  if(cc&&!cc.dataset.lumebound){cc.dataset.lumebound='1';cc.addEventListener('click',closeCart);}
});

function loadCart(){
  // Show placeholder rows until the cart arrives (only if nothing is shown yet).
  if(cartItems && !cartItems.querySelector('.lume-cart-item')) cartItems.innerHTML=skelRows(2);
  fetch(BASE+'/api/cart.php?action=get',{headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(r=>r.json()).then(data=>{
    if(!data.items||data.items.length===0){
      cartItems.innerHTML='<div class="lume-search-empty">Your bag is empty</div>';
      cartFooter.style.display='none';updateBadge(0);return;
    }
    cartItems.innerHTML=data.items.map(i=>`
      <div class="lume-cart-item" data-cart-id="${parseInt(i.id,10)}">
        <img src="${esc(imgUrl(i.image))}" alt="${esc(i.name)}">
        <div class="lume-cart-item-info">
          <span class="lume-cart-item-name">${esc(i.name)}</span>
          ${(i.variant_size||i.variant_color)?`<span class="lume-cart-variant-meta">${esc(i.variant_size||'')} ${i.variant_size&&i.variant_color?'/':''} ${esc(i.variant_color||'')}</span>`:''}
          <span class="lume-cart-item-qty">Qty: ${parseInt(i.quantity,10)}</span>
          <span class="lume-cart-item-price">${i.display_price}</span>
          <button class="lume-cart-item-remove" onclick="removeCartItem(${parseInt(i.id,10)})">Remove</button>
        </div>
      </div>`).join('');
    cartTotal.innerHTML=data.total_display;
    const dRow=document.getElementById('cart-drawer-discount');
    if(dRow){
      dRow.hidden=!data.discount;
      if(data.discount){
        document.getElementById('cart-drawer-discount-label').textContent=data.discount.label;
        document.getElementById('cart-drawer-discount-amount').innerHTML=data.discount.display;
      }
    }
    cartFooter.style.display='block';
    updateBadge(data.count);
  }).catch(()=>{});
}

function updateBadge(n){
  if(cartBadge){cartBadge.textContent=n;cartBadge.style.display=n>0?'flex':'none';}
}

window.addToCart=function(productId,qty,variantId,pixelData){
  qty=qty||1;
  const fd=new FormData();fd.append('action','add');fd.append('product_id',productId);fd.append('quantity',qty);fd.append('csrf',CSRF);
  if(variantId)fd.append('variant_id',variantId);
  fetch(BASE+'/api/cart.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(r=>r.json()).then(data=>{
    if(data.success){
      if(window.showToast)showToast('✓ Added to bag');
      // ── Meta Pixel: AddToCart ──
      // eventID matches the server-side Conversions API call api/cart.php
      // just made (data.meta_event_id), so Meta dedupes the two into one event.
      if(typeof fbq==='function'){
        const pd=pixelData||{};
        // Include variant ID in content_ids when available
        const contentId = variantId ? String(variantId) : String(productId);
        fbq('track','AddToCart',{
          content_ids:[contentId],
          content_type:'product',
          value:pd.price||0,
          currency:CURRENCY.trim()||'EGP',
          content_name:pd.name||'',
          num_items:qty
        }, data.meta_event_id ? {eventID:data.meta_event_id} : undefined);
      }
      // "Buy Now" sets this flag before triggering the add — skip the cart
      // drawer and go straight to checkout.
      if(window.__lumeAfterAdd === 'checkout'){
        window.__lumeAfterAdd = null;
        window.location.href = BASE + '/checkout.php';
        return;
      }
      openCart();
    }else{window.__lumeAfterAdd=null;alert(data.message||'Error');}
  }).catch(()=>{window.__lumeAfterAdd=null;alert('Connection error');});
};
window.removeCartItem=function(cartId){
  const fd=new FormData();fd.append('action','remove');fd.append('cart_id',cartId);fd.append('csrf',CSRF);
  fetch(BASE+'/api/cart.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(r=>r.json()).then(()=>loadCart()).catch(()=>{});
};

// ─── Scroll reveal ───
function initReveal(){
  const els=document.querySelectorAll('.lume-reveal,.lume-reveal-left,.lume-reveal-right,.lume-reveal-scale');
  els.forEach(el=>el.classList.add('js-hidden'));
  const cards=document.querySelectorAll('.lume-product-card');
  cards.forEach(el=>el.classList.add('js-hidden'));
  const obs=new IntersectionObserver((entries)=>{
    entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('visible');obs.unobserve(e.target);}});
  },{threshold:0.1,rootMargin:'0px 0px -40px 0px'});
  els.forEach(el=>obs.observe(el));
  const cardObs=new IntersectionObserver((entries)=>{
    const visible=entries.filter(e=>e.isIntersecting);
    visible.forEach((e,i)=>{setTimeout(()=>{e.target.classList.add('visible');cardObs.unobserve(e.target);},i*120);});
  },{threshold:0.08,rootMargin:'0px 0px -20px 0px'});
  cards.forEach(el=>cardObs.observe(el));
}
document.addEventListener('DOMContentLoaded',initReveal);

// ─── Hero parallax ───
// Parallax removed due to scroll performance.

// ─── Page transition ───
(function(){
  const curtain=document.createElement('div');curtain.className='lume-curtain';
  // Set pointer-events:none inline so buttons are never blocked,
  // even if components.css hasn't fully applied yet.
  curtain.style.pointerEvents='none';
  document.body.prepend(curtain);
  function removeCurtain(){if(curtain.parentNode)curtain.remove();}
  curtain.addEventListener('animationend',removeCurtain);
  // Safety net: hard-remove after 800ms regardless of animation state
  setTimeout(removeCurtain,800);
})();

// ─── Toast ───
(function(){
  const toast=document.createElement('div');toast.className='lume-toast';toast.id='lume-toast';
  document.body.appendChild(toast);
  window.showToast=function(msg,duration){
    duration=duration||2500;toast.textContent=msg;toast.classList.add('visible');
    clearTimeout(toast._timer);toast._timer=setTimeout(()=>toast.classList.remove('visible'),duration);
  };
})();

// ─── Qty buttons ───
document.addEventListener('click',(e)=>{
  const btn=e.target.closest('.lume-qty-btn');if(!btn)return;
  const wrap=btn.closest('.lume-product-single__qty');
  const val=wrap&&wrap.querySelector('.lume-qty-val');if(!val)return;
  let n=parseInt(val.textContent)||1;
  let max=val.dataset.max ? parseInt(val.dataset.max) : 99;
  if(btn.dataset.dir==='minus')n=Math.max(1,n-1);
  else n=Math.min(max,n+1);
  val.textContent=n;
});

// ─── Contact form ───
const contactForm = document.getElementById('contact-form');
if(contactForm){
  contactForm.addEventListener('submit',(e)=>{
    e.preventDefault();
    const fd=new FormData(contactForm);const msg=document.getElementById('contact-msg');
    fetch(BASE+'/api/contact.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(data=>{
      if(msg){msg.textContent=data.message;msg.className='lume-alert '+(data.success?'lume-alert--success':'lume-alert--error');}
      if(data.success)contactForm.reset();
    }).catch(()=>{if(msg){msg.textContent='Connection error';msg.className='lume-alert lume-alert--error';}});
  });
}

// ─── Counter animation ───
function initCounters(){
  const counters=document.querySelectorAll('[data-count-to]');if(!counters.length)return;
  const obs=new IntersectionObserver((entries)=>{
    entries.forEach(e=>{
      if(!e.isIntersecting)return;const el=e.target;const target=parseFloat(el.dataset.countTo)||0;
      const dur=1200;const start=performance.now();
      function tick(now){const p=Math.min((now-start)/dur,1);el.textContent=Math.floor(target*(1-Math.pow(1-p,3))).toLocaleString();if(p<1)requestAnimationFrame(tick);else el.textContent=target.toLocaleString();}
      requestAnimationFrame(tick);obs.unobserve(el);
    });
  },{threshold:0.3});
  counters.forEach(el=>obs.observe(el));
}
document.addEventListener('DOMContentLoaded',initCounters);

// ─── Meta Pixel helpers ───
window.lumePixel={
  initiateCheckout:function(o){
    if(typeof fbq==='function')fbq('track','InitiateCheckout',{
      value:o.total||0,
      currency:'EGP',
      num_items:o.num_items||1
    });
  },
  purchase:function(o){
    if(typeof fbq==='function')fbq('track','Purchase',{
      value:o.total||0,
      currency:'EGP',
      num_items:o.num_items||1
    });
  }
};

// ─── Auto-fire InitiateCheckout on checkout page ───
(function(){
  const checkoutConfig = document.getElementById('checkout-config');
  if(!checkoutConfig) return; // not on checkout page
  try {
    const cfg = JSON.parse(checkoutConfig.textContent);
    if(cfg && typeof fbq === 'function') {
      // eventID matches the server-side Conversions API call checkout.php
      // already made (cfg.event_id), so Meta dedupes the two into one event.
      fbq('track','InitiateCheckout',{
        value: cfg.subtotal||0,
        currency: CURRENCY.trim()||'EGP',
        num_items: cfg.num_items||1,
        content_ids: (cfg.product_ids||[]).map(String)
      }, cfg.event_id ? {eventID:cfg.event_id} : undefined);
    }
  } catch(e){}
})();

// ═══════════════════════════════════════════
// SIZE CHART MODAL
// ═══════════════════════════════════════════
(function(){
  const btn = document.getElementById('btn-size-chart');
  const modal = document.getElementById('sizechart-modal');
  if(!btn || !modal) return;
  const closeBtn = document.getElementById('sizechart-close');
  const backdrop = document.getElementById('sizechart-backdrop');
  function openSC(){ modal.classList.add('active'); document.body.style.overflow='hidden'; }
  function closeSC(){ modal.classList.remove('active'); document.body.style.overflow=''; }
  btn.addEventListener('click', openSC);
  closeBtn && closeBtn.addEventListener('click', closeSC);
  backdrop && backdrop.addEventListener('click', closeSC);
  document.addEventListener('keydown', e=>{ if(e.key==='Escape' && modal.classList.contains('active')) closeSC(); });
})();

// ═══════════════════════════════════════════
// PRODUCT GALLERY (color-specific + lightbox)
// ═══════════════════════════════════════════
(function(){
  const dataEl = document.getElementById('gallery-data');
  if(!dataEl) return;
  const galleryData = JSON.parse(dataEl.textContent);
  if(!galleryData) return;

  const mainImg   = document.getElementById('gallery-main-img');
  const mainWrap  = document.getElementById('gallery-main-wrap');
  const thumbWrap = document.getElementById('gallery-thumbs');
  const prevBtn   = document.getElementById('gallery-prev');
  const nextBtn   = document.getElementById('gallery-next');

  let currentImages = []; // current array of image paths
  let currentIndex = 0;

  function setGallery(images) {
    if(!images || !images.length) images = galleryData.general || [];
    currentImages = images;
    currentIndex = 0;
    renderThumbs();
    showImage(0);
    // Show/hide arrows
    if(prevBtn) prevBtn.style.display = images.length > 1 ? '' : 'none';
    if(nextBtn) nextBtn.style.display = images.length > 1 ? '' : 'none';
  }

  function renderThumbs() {
    if(!thumbWrap) return;
    if(currentImages.length <= 1) { thumbWrap.innerHTML = ''; return; }
    thumbWrap.innerHTML = currentImages.map((src, i) =>
      `<button class="lume-gallery__thumb ${i===0?'active':''}" data-index="${i}" aria-label="View image ${i+1}">
        <img src="${esc(thumbSrc(src, 200))}" alt="" loading="lazy">
      </button>`
    ).join('');
    thumbWrap.querySelectorAll('.lume-gallery__thumb').forEach(t => {
      t.addEventListener('click', () => showImage(parseInt(t.dataset.index)));
    });
  }

  function showImage(idx) {
    if(idx < 0) idx = currentImages.length - 1;
    if(idx >= currentImages.length) idx = 0;
    currentIndex = idx;
    if(mainImg) {
      mainImg.style.opacity = '0';
      setTimeout(() => {
        mainImg.src = thumbSrc(currentImages[idx], 1000);
        mainImg.style.opacity = '1';
      }, 150);
    }
    if(thumbWrap) {
      thumbWrap.querySelectorAll('.lume-gallery__thumb').forEach((t, i) =>
        t.classList.toggle('active', i === idx)
      );
    }
  }

  // Switch gallery by color name
  /* Find a colour's images.
     Keys in galleryData.colors come from two different places — the
     product's color_galleries JSON, and product_variants.color_name — and
     those are hand-entered, so they drift ("Green" vs "green" vs "Green ").
     An exact-key lookup silently missed those and fell back to the main
     gallery, which is why picking Green kept showing the white photos.
     Try exact first, then a trimmed/case-insensitive match. */
  function colorImages(colorName) {
    if(!colorName || !galleryData.colors) return null;
    const exact = galleryData.colors[colorName];
    if(exact && exact.length) return exact;
    const want = String(colorName).trim().toLowerCase();
    for(const key of Object.keys(galleryData.colors)) {
      if(String(key).trim().toLowerCase() === want) {
        const imgs = galleryData.colors[key];
        if(imgs && imgs.length) return imgs;
      }
    }
    return null;
  }

  function filterByColor(colorName) {
    const imgs = colorImages(colorName);
    setGallery(imgs && imgs.length ? imgs : galleryData.general);
  }

  window.lumeGallery = { filterByColor, showImage, setGallery };

  prevBtn && prevBtn.addEventListener('click', () => showImage(currentIndex - 1));
  nextBtn && nextBtn.addEventListener('click', () => showImage(currentIndex + 1));

  // Touch swipe
  if(mainWrap) {
    let tx = 0, startY = 0, swiping = false;
    mainWrap.addEventListener('touchstart', e => { tx = e.touches[0].clientX; startY = e.touches[0].clientY; swiping = true; }, {passive:true});
    mainWrap.addEventListener('touchmove', e => { if(!swiping) return; if(Math.abs(e.touches[0].clientY - startY) > Math.abs(e.touches[0].clientX - tx)) swiping = false; }, {passive:true});
    mainWrap.addEventListener('touchend', e => { if(!swiping) return; const diff = tx - e.changedTouches[0].clientX; if(Math.abs(diff) > 50) showImage(diff > 0 ? currentIndex + 1 : currentIndex - 1); swiping = false; });
  }

  // ── Lightbox ──
  const lightbox  = document.getElementById('lume-lightbox');
  const lbImg     = document.getElementById('lightbox-img');
  const lbClose   = document.getElementById('lightbox-close');
  const lbPrev    = document.getElementById('lightbox-prev');
  const lbNext    = document.getElementById('lightbox-next');
  const lbCounter = document.getElementById('lightbox-counter');

  function openLightbox() {
    if(!lightbox || !currentImages.length) return;
    lbImg.src = thumbSrc(currentImages[currentIndex], 1800);
    if(lbCounter) lbCounter.textContent = (currentIndex+1) + ' / ' + currentImages.length;
    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  function closeLightbox() { if(!lightbox) return; lightbox.classList.remove('active'); document.body.style.overflow = ''; }
  function lbGo(dir) {
    showImage(currentIndex + dir);
    lbImg.src = thumbSrc(currentImages[currentIndex], 1800);
    if(lbCounter) lbCounter.textContent = (currentIndex+1) + ' / ' + currentImages.length;
  }

  if(mainWrap) mainWrap.addEventListener('click', e => { if(!e.target.closest('.lume-gallery__arrow')) openLightbox(); });
  if(lbClose) lbClose.addEventListener('click', closeLightbox);
  if(lbPrev) lbPrev.addEventListener('click', () => lbGo(-1));
  if(lbNext) lbNext.addEventListener('click', () => lbGo(1));
  if(lightbox) {
    lightbox.addEventListener('click', e => { if(e.target === lightbox) closeLightbox(); });
    document.addEventListener('keydown', e => {
      if(!lightbox.classList.contains('active')) return;
      if(e.key === 'Escape') closeLightbox();
      if(e.key === 'ArrowLeft') lbGo(-1);
      if(e.key === 'ArrowRight') lbGo(1);
    });
  }

  // Initialize with first color or general
  if(galleryData.firstColor && galleryData.colors && galleryData.colors[galleryData.firstColor]) {
    setGallery(galleryData.colors[galleryData.firstColor]);
  } else {
    setGallery(galleryData.general);
  }
})();

// ═══════════════════════════════════════════
// PRODUCT VARIANT SELECTION
// ═══════════════════════════════════════════
(function(){
  const dataEl = document.getElementById('variant-data');
  if(!dataEl) return;
  const variants = JSON.parse(dataEl.textContent);
  const configEl = document.getElementById('product-config');
  const config = configEl ? JSON.parse(configEl.textContent) : { showStock: true, lowThreshold: 5 };
  const galleryDataEl = document.getElementById('gallery-data');
  const galleryData = galleryDataEl ? JSON.parse(galleryDataEl.textContent) : null;

  const sizeSwatches = document.querySelectorAll('.lume-size-swatch');
  const swatches     = document.querySelectorAll('.lume-color-swatch');
  const btnAdd       = document.getElementById('btn-add-single');
  const variantMsg   = document.getElementById('variant-msg');
  const stockInfo    = document.getElementById('stock-info');
  const skuInfo      = document.getElementById('variant-sku');
  const priceEl      = document.getElementById('product-price-display');
  const defaultPrice = priceEl ? priceEl.innerHTML : '';

  const selected = { size: null, color: galleryData?.firstColor || null };

  const needsSize  = sizeSwatches.length > 0;
  const needsColor = swatches.length > 0;

  // Auto-select first in-stock variant
  const inStockVariant = variants.find(v => v.stock > 0);
  if (inStockVariant) {
    if (needsColor) selected.color = inStockVariant.color_name;
    if (needsSize) selected.size = inStockVariant.size;

    if (needsColor && selected.color) {
      swatches.forEach(s => {
        if (s.dataset.color === selected.color) s.classList.add('is-active');
      });
      const lbl = document.getElementById('selected-color-label');
      if (lbl) lbl.textContent = selected.color;
    }
    if (needsSize && selected.size) {
      sizeSwatches.forEach(s => {
        if (s.dataset.size === selected.size) s.classList.add('is-active');
      });
      const lbl = document.getElementById('selected-size-label');
      if (lbl) lbl.textContent = selected.size;
    }

    /* Sync the gallery to the colour we just auto-selected.
       The gallery IIFE above initialises itself to galleryData.firstColor —
       the first colour in the list — while this block picks the first
       IN-STOCK variant, which is a different colour whenever the first one
       is sold out. Without this call the page loaded showing "Green" on the
       swatch and label but the white photos in the gallery. Clicking a
       swatch looked like it "fixed" it only because that path does call
       filterByColor. */
    if (needsColor && selected.color && window.lumeGallery) {
      window.lumeGallery.filterByColor(selected.color);
    }
  }

  // Must mirror money() in includes/functions.php exactly:
  //   <span class="lume-price-val">1,234.56</span><span class="lume-price-cur">EGP</span>
  // This previously emitted `CURRENCY + amount` inside a bare .price span,
  // which rendered as "EGP219.99" — currency on the wrong side, no space,
  // no thousand separators, and none of the val/cur sizing. So the price
  // visibly changed format the moment a variant was selected.
  // toLocaleString matches PHP's number_format (which does group digits:
  // 1200 must read "1,200.00", not "1200.00").
  function formatPrice(amount) {
    const val = parseFloat(amount).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
    return '<div class="price">'
         + '<span class="lume-price-val">' + val + '</span>'
         + '<span class="lume-price-cur">' + (CURRENCY.trim() || 'EGP') + '</span>'
         + '</div>';
  }

  function stockHtml(stock) {
    if(!config.showStock) return '';
    if(stock <= 0) return '&#10005; Out of stock';
    return '&#10003; In stock';
  }

  // Should this size/colour option be greyed out?
  // Normally: no such variant, or that variant is out of stock.
  // On a pre-order product a zero-stock variant is still orderable, so only
  // a combination that does not exist at all is disabled — otherwise every
  // option on a fully sold-out product greys out, nothing can be selected,
  // and the Pre-order button can never be reached.
  const isUnavailable = v => config.preorder ? !v : (!v || v.stock <= 0);

  function update() {
    if(needsSize) {
      if(needsColor && selected.color) {
        sizeSwatches.forEach(opt => {
          const sz = opt.dataset.size;
          const v = variants.find(v => v.color_name === selected.color && v.size === sz);
          opt.classList.toggle('is-disabled', isUnavailable(v));
        });
      } else if(!needsColor) {
        sizeSwatches.forEach(opt => {
          const sz = opt.dataset.size;
          const v = variants.find(v => v.size === sz);
          opt.classList.toggle('is-disabled', isUnavailable(v));
        });
      } else {
        sizeSwatches.forEach(opt => opt.classList.remove('is-disabled'));
      }
    }

    if(needsColor) {
      if(needsSize && selected.size) {
        swatches.forEach(s => {
          const v = variants.find(v => v.size === selected.size && v.color_name === s.dataset.color);
          s.classList.toggle('is-disabled', isUnavailable(v));
        });
      } else if(!needsSize) {
        swatches.forEach(s => {
          const v = variants.find(v => v.color_name === s.dataset.color);
          s.classList.toggle('is-disabled', isUnavailable(v));
        });
      } else {
        swatches.forEach(s => s.classList.remove('is-disabled'));
      }
    }

    let matched = null;
    if((!needsSize || selected.size) && (!needsColor || selected.color)) {
      matched = variants.find(v =>
        (!needsSize  || v.size       === selected.size) &&
        (!needsColor || v.color_name === selected.color)
      );
    }

    if(matched) {
      if(variantMsg) variantMsg.style.display = 'none';
      if(priceEl) priceEl.innerHTML = matched.price !== null ? formatPrice(matched.price) : defaultPrice;

      if(matched.stock > 0) {
        btnAdd.disabled = false;
        btnAdd.style.opacity = '1';
        btnAdd.style.cursor  = 'pointer';
        btnAdd.textContent   = 'Add to Bag';
        const qtyVal = document.getElementById('qty-val');
        if(qtyVal) {
            qtyVal.dataset.max = matched.stock;
            let currentN = parseInt(qtyVal.textContent) || 1;
            if (currentN > matched.stock) qtyVal.textContent = Math.max(1, matched.stock);
        }
        btnAdd.onclick = () => {
          const qty = parseInt(document.getElementById('qty-val')?.textContent || '1');
          const pixelPrice = matched.price !== null ? matched.price : (config.productPrice || 0);
          window.addToCart(btnAdd.dataset.productId, qty, matched.id, {
            name: config.productName || '',
            price: pixelPrice
          });
        };
      } else if (config.preorder) {
        // Out of stock, but this product allows pre-orders — keep the button
        // live and relabel it. api/cart.php skips its stock ceiling for
        // products flagged preorder_enabled, so this add will succeed.
        btnAdd.disabled      = false;
        btnAdd.style.opacity = '';
        btnAdd.style.cursor  = '';
        btnAdd.textContent   = 'Pre-order';
        btnAdd.onclick = () => {
          const qty = parseInt(document.getElementById('qty-val')?.textContent || '1');
          const pixelPrice = matched.price !== null ? matched.price : (config.productPrice || 0);
          window.addToCart(btnAdd.dataset.productId, qty, matched.id, {
            name: config.productName || '',
            price: pixelPrice
          });
        };
      } else {
        btnAdd.disabled      = true;
        btnAdd.style.opacity = '.4';
        btnAdd.style.cursor  = 'not-allowed';
        btnAdd.textContent   = 'Sold Out';
      }

      if(stockInfo) {
        stockInfo.innerHTML = stockHtml(matched.stock);
        stockInfo.className = 'lume-product-single__stock ' + (matched.stock > 0 ? 'in-stock' : 'out-of-stock');
        stockInfo.style.display = config.showStock ? '' : 'none';
      }
      if(skuInfo) {
        if(matched.sku) { skuInfo.style.display='block'; skuInfo.textContent='SKU: '+matched.sku; }
        else skuInfo.style.display = 'none';
      }
    } else {
      // Need both selections for a match when both exist
      if(needsSize && !selected.size) {
        btnAdd.disabled      = true;
        btnAdd.style.opacity = '.4';
        btnAdd.style.cursor  = 'not-allowed';
        btnAdd.textContent   = 'Add to Bag';
        if(variantMsg) { variantMsg.style.display = ''; variantMsg.textContent = 'Please select a size'; }
      } else {
        btnAdd.disabled      = true;
        btnAdd.style.opacity = '.4';
        btnAdd.style.cursor  = 'not-allowed';
        btnAdd.textContent   = 'Add to Bag';
        if(variantMsg) { variantMsg.style.display = ''; variantMsg.textContent = 'Please select your options above'; }
      }
      if(stockInfo)  stockInfo.innerHTML = '';
      if(skuInfo)    skuInfo.style.display = 'none';
      if(priceEl)    priceEl.innerHTML = defaultPrice;
    }
  }

  // Size swatches
  sizeSwatches.forEach(swatch => {
    swatch.addEventListener('click', () => {
      if(swatch.classList.contains('is-disabled')) return;
      // Don't allow deselection — clicking active size does nothing
      if(swatch.classList.contains('is-active')) return;
      sizeSwatches.forEach(s => s.classList.remove('is-active'));
      swatch.classList.add('is-active');
      selected.size = swatch.dataset.size;
      const lbl = document.getElementById('selected-size-label');
      if(lbl) lbl.textContent = selected.size;
      update();
    });
  });

  // Color swatches — also switch gallery
  swatches.forEach(swatch => {
    swatch.addEventListener('click', () => {
      // NOTE: sold-out colours are deliberately still clickable.
      // This used to bail on `is-disabled`, which meant clicking a sold-out
      // colour never reached filterByColor() below — so the gallery kept
      // showing the previous colour's photos (usually the main/white ones)
      // and the customer could never see the colour they picked.
      // Selecting it is safe: update() disables Add to Cart and shows
      // "Sold Out" whenever the matched variant has no stock.
      // Don't allow deselection — clicking active color does nothing
      if(swatch.classList.contains('is-active')) return;
      swatches.forEach(s => s.classList.remove('is-active'));
      swatch.classList.add('is-active');
      selected.color = swatch.dataset.color;
      const lbl = document.getElementById('selected-color-label');
      if(lbl) lbl.textContent = selected.color;
      if(window.lumeGallery) window.lumeGallery.filterByColor(selected.color);
      update();
    });
  });

  // Run initial update (first color is already selected)
  update();
})();

// ═══════════════════════════════════════════
// SHOP CARD — Hover image swap + color swatch hover/click
// ═══════════════════════════════════════════
(function(){
  document.querySelectorAll('.lume-product-card').forEach(card => {
    const mainImg = card.querySelector('.lume-product-card__img');
    if(!mainImg) return;
    const originalSrc = mainImg.src;
    const hoverSrc = mainImg.dataset.hoverSrc;

    // Store original src for restoration
    mainImg.dataset.originalSrc = originalSrc;

    // Hover on card => show second image
    if(hoverSrc) {
      card.addEventListener('mouseenter', () => {
        // Only swap if not showing a swatch-selected image
        if(!card.dataset.swatchActive) mainImg.src = hoverSrc;
      });
      card.addEventListener('mouseleave', () => {
        if(!card.dataset.swatchActive) mainImg.src = originalSrc;
      });
    }

    // Color swatches: hover shows preview, click persists selection
    card.querySelectorAll('.lume-product-card__swatch-dot').forEach(dot => {
      const colorImg = dot.dataset.image;
      if(!colorImg) return;

      dot.addEventListener('mouseenter', () => { mainImg.src = colorImg; });
      dot.addEventListener('mouseleave', () => {
        // Restore to swatch-selected or original
        mainImg.src = card.dataset.swatchActive || originalSrc;
      });

      dot.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        // Toggle: if same swatch clicked, deselect
        if(card.dataset.swatchActive === colorImg) {
          delete card.dataset.swatchActive;
          mainImg.src = originalSrc;
          card.querySelectorAll('.lume-product-card__swatch-dot').forEach(d => d.style.outline = '');
        } else {
          card.dataset.swatchActive = colorImg;
          mainImg.src = colorImg;
          card.querySelectorAll('.lume-product-card__swatch-dot').forEach(d => d.style.outline = '');
          dot.style.outline = '2px solid var(--gold)';
          dot.style.outlineOffset = '2px';
        }
      });
    });
  });
})();

// ─── Drag to Scroll for Carousels ───
(function(){
  const sliders = document.querySelectorAll('.lume-social-carousel, .lume-testimonials');
  sliders.forEach(slider => {
    let isDown = false;
    let startX;
    let scrollLeft;
    slider.addEventListener('mousedown', (e) => {
      isDown = true;
      slider.style.cursor = 'grabbing';
      startX = e.pageX - slider.offsetLeft;
      scrollLeft = slider.scrollLeft;
    });
    slider.addEventListener('mouseleave', () => {
      isDown = false;
      slider.style.cursor = '';
    });
    slider.addEventListener('mouseup', () => {
      isDown = false;
      slider.style.cursor = '';
    });
    slider.addEventListener('mousemove', (e) => {
      if (!isDown) return;
      e.preventDefault();
      const x = e.pageX - slider.offsetLeft;
      const walk = (x - startX) * 1.5;
      slider.scrollLeft = scrollLeft - walk;
    });
  });
})();

// ─── Image Carousel (arrows, dots, scroll-synced active state) ───
(function(){
  document.querySelectorAll('.lume-image-carousel').forEach(car => {
    const track = car.querySelector('.lume-image-carousel__track');
    const dots  = car.querySelectorAll('.lume-image-carousel__dot');
    const slides = track ? track.querySelectorAll('.lume-image-carousel__slide') : [];
    if (!track || !slides.length) return;

    if (slides.length > 1) {
      const obs = new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if (entry.intersectionRatio > 0.6) {
            const idx = Array.from(slides).indexOf(entry.target);
            dots.forEach((d, i) => d.classList.toggle('active', i === idx));
          }
        });
      }, { root: track, threshold: 0.6 });
      slides.forEach(s => obs.observe(s));
    }

    car.querySelector('.lume-image-carousel__arrow--prev')?.addEventListener('click', () => {
      track.scrollBy({ left: -track.clientWidth, behavior: 'smooth' });
    });
    car.querySelector('.lume-image-carousel__arrow--next')?.addEventListener('click', () => {
      track.scrollBy({ left: track.clientWidth, behavior: 'smooth' });
    });
    dots.forEach((d, i) => d.addEventListener('click', () => {
      track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
    }));
  });
})();

// ─── Testimonial Spotlight (auto-rotating single quote) ───
(function(){
  document.querySelectorAll('.lume-testimonial-carousel').forEach(car => {
    const slides = car.querySelectorAll('.lume-testimonial-carousel__slide');
    const dots   = car.querySelectorAll('.lume-testimonial-carousel__dot');
    if (slides.length < 2) return;
    let idx = 0;
    const interval = parseInt(car.dataset.interval, 10) || 6000;
    function show(i) {
      slides.forEach((s, n) => s.classList.toggle('active', n === i));
      dots.forEach((d, n) => d.classList.toggle('active', n === i));
      idx = i;
    }
    let timer = setInterval(() => show((idx + 1) % slides.length), interval);
    dots.forEach((d, i) => d.addEventListener('click', () => {
      show(i);
      clearInterval(timer);
      timer = setInterval(() => show((idx + 1) % slides.length), interval);
    }));
  });
})();

// ─── Video Gallery Lightbox ───
(function(){
  function closeModal(modal) {
    modal.classList.remove('active');
    const media = modal.querySelector('.lume-video-gallery__modal-media');
    if (media) media.innerHTML = '';
    document.body.style.overflow = '';
  }
  document.addEventListener('click', e => {
    const thumb = e.target.closest('.lume-video-gallery__thumb');
    if (thumb) {
      const modal = document.getElementById(thumb.dataset.modal);
      const media = modal?.querySelector('.lume-video-gallery__modal-media');
      if (!modal || !media) return;
      media.innerHTML = '';
      let el;
      if (thumb.dataset.embed === '1') {
        el = document.createElement('iframe');
        el.src = thumb.dataset.src + (thumb.dataset.src.includes('?') ? '&' : '?') + 'autoplay=1';
        el.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        el.allowFullscreen = true;
      } else {
        el = document.createElement('video');
        el.src = thumb.dataset.src;
        el.controls = true;
        el.autoplay = true;
        el.playsInline = true;
      }
      media.appendChild(el);
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
      return;
    }
    const modalEl = e.target.closest('.lume-video-gallery__modal');
    if (modalEl && (e.target.closest('.lume-video-gallery__modal-close') || e.target === modalEl)) {
      closeModal(modalEl);
    }
  });
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.lume-video-gallery__modal.active').forEach(closeModal);
  });
})();

})();

// ─── Video Reels ───
// Only the centred reel plays; the others pause. Sound is one switch per
// section that follows you as you swipe, and turning it on anywhere mutes
// every other reel on the page — so at most one video is ever audible.
(function(){
  const reelsList = document.querySelectorAll('.lume-reels');
  if (!reelsList.length) return;
  let soundOwner = null; // the reels controller that currently has sound

  reelsList.forEach(root => {
    const track  = root.querySelector('.lume-reels__track');
    const cards  = Array.from(root.querySelectorAll('.lume-reels__card'));
    const dots   = root.querySelectorAll('.lume-reels__dot');
    const prev   = root.querySelector('.lume-reels__arrow--prev');
    const next   = root.querySelector('.lume-reels__arrow--next');
    if (!track || !cards.length) return;
    const autoplay = root.dataset.autoplay !== '0';
    const vid = c => c.querySelector('video');
    let active = 0, soundOn = false, inView = false, userPaused = !autoplay, raf = 0;

    const ctl = {
      mute() { soundOn = false; cards.forEach(c => { vid(c).muted = true; }); syncButtons(); }
    };

    function syncButtons() {
      cards.forEach(c => {
        const b = c.querySelector('.lume-reels__sound');
        if (!b) return;
        b.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
        b.setAttribute('aria-label', soundOn ? 'Mute' : 'Turn sound on');
      });
    }

    function play(v) {
      const p = v.play();
      if (p && p.catch) p.catch(() => {
        // Browser refused sound without a fresh tap (iOS) — fall back to muted.
        if (!v.muted) { ctl.mute(); v.play().catch(() => {}); }
      });
    }

    function progress() {
      const c = cards[active], v = vid(c), bar = c.querySelector('.lume-reels__progress span');
      if (bar && v.duration) bar.style.transform = 'scaleX(' + (v.currentTime / v.duration) + ')';
      raf = requestAnimationFrame(progress);
    }

    function apply() {
      cards.forEach((c, i) => {
        const v = vid(c), on = i === active;
        c.classList.toggle('is-active', on);
        if (!on) { v.muted = true; if (!v.paused) v.pause(); }
      });
      dots.forEach((d, i) => d.classList.toggle('active', i === active));
      if (prev) prev.disabled = active === 0;
      if (next) next.disabled = active === cards.length - 1;
      const v = vid(cards[active]);
      v.muted = !soundOn;
      const shouldPlay = inView && !userPaused;
      cards[active].classList.toggle('is-paused', !shouldPlay);
      if (shouldPlay) play(v); else if (!v.paused) v.pause();
      // Warm up the neighbour so swiping to it starts instantly.
      const n = cards[active + 1];
      if (n && vid(n).preload === 'none') vid(n).preload = 'metadata';
    }

    function setActive(i) {
      i = Math.max(0, Math.min(cards.length - 1, i));
      if (i === active) return;
      active = i;
      if (autoplay) userPaused = false;
      apply();
    }

    function goTo(i) {
      const c = cards[Math.max(0, Math.min(cards.length - 1, i))];
      track.scrollTo({ left: c.offsetLeft - (track.clientWidth - c.offsetWidth) / 2, behavior: 'smooth' });
    }

    // Whichever card sits nearest the middle of the strip is the active one.
    let ticking = false;
    track.addEventListener('scroll', () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        ticking = false;
        const mid = track.scrollLeft + track.clientWidth / 2;
        let best = 0, dist = Infinity;
        cards.forEach((c, i) => {
          const d = Math.abs(c.offsetLeft + c.offsetWidth / 2 - mid);
          if (d < dist) { dist = d; best = i; }
        });
        setActive(best);
      });
    }, { passive: true });

    cards.forEach((c, i) => {
      c.addEventListener('click', e => {
        if (e.target.closest('a')) return;
        const btn = e.target.closest('.lume-reels__sound');
        if (btn) {
          soundOn = !soundOn;
          if (soundOn) { if (soundOwner && soundOwner !== ctl) soundOwner.mute(); soundOwner = ctl; }
          syncButtons();
          const v = vid(cards[active]);
          v.muted = !soundOn;
          if (soundOn && v.paused) { userPaused = false; apply(); }
          return;
        }
        if (i !== active) { goTo(i); return; }
        userPaused = !vid(c).paused;
        apply();
      });
    });

    prev?.addEventListener('click', () => goTo(active - 1));
    next?.addEventListener('click', () => goTo(active + 1));
    dots.forEach((d, i) => d.addEventListener('click', () => goTo(i)));

    // Pause everything (and stop the progress loop) while the section is off screen.
    const onView = vis => {
      inView = vis;
      cancelAnimationFrame(raf);
      if (vis) raf = requestAnimationFrame(progress);
      apply();
    };
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(es => es.forEach(e => onView(e.isIntersecting)), { threshold: 0.35 }).observe(root);
    } else onView(true);

    // Open on the first reel, centred.
    requestAnimationFrame(() => { track.scrollLeft = cards[0].offsetLeft - (track.clientWidth - cards[0].offsetWidth) / 2; });
    syncButtons();
    apply();
  });
})();

// ─── Lazy videos ───
// Autoplaying section videos load and play only while on screen, so a page
// with several videos doesn't download them all up front (or burn battery
// playing ones nobody can see).
(function(){
  const vids=document.querySelectorAll('video[data-lazy-video]');
  if(!vids.length) return;
  const play=v=>{ const p=v.play(); if(p&&p.catch) p.catch(()=>{}); };
  if(!('IntersectionObserver' in window)){ vids.forEach(play); return; }
  const io=new IntersectionObserver(entries=>entries.forEach(e=>{
    if(e.isIntersecting) play(e.target); else e.target.pause();
  }),{rootMargin:'200px 0px'});
  vids.forEach(v=>io.observe(v));
})();
