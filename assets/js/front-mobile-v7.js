function setupModalDescription(){const desc=document.getElementById('modalDesc');const btn=document.getElementById('modalDescToggle');if(!desc||!btn)return;desc.classList.remove('is-expanded','is-collapsible');btn.hidden=true;btn.textContent='Baca Selengkapnya...';desc.style.removeProperty('--modal-desc-collapsed-max');const check=()=>{const cs=getComputedStyle(desc);let lh=parseFloat(cs.lineHeight);if(!Number.isFinite(lh)||lh<=0){const fs=parseFloat(cs.fontSize)||14;lh=fs*1.65;}const collapsed=Math.ceil(lh*2);desc.style.setProperty('--modal-desc-collapsed-max',collapsed+'px');const needs=desc.scrollHeight>collapsed+4;if(needs){desc.classList.add('is-collapsible');btn.hidden=false;}else{desc.classList.remove('is-collapsible');btn.hidden=true;}};requestAnimationFrame(()=>requestAnimationFrame(check));}
document.addEventListener('click',function(e){const btn=e.target.closest('#modalDescToggle');if(!btn)return;const desc=document.getElementById('modalDesc');if(!desc)return;desc.classList.add('is-expanded');btn.hidden=true;setTimeout(()=>{desc.scrollTop=0;},0);});const rupiah=n=>'Rp '+new Intl.NumberFormat('id-ID').format(Number(n||0));
let cart=JSON.parse(localStorage.getItem('ds_cart')||'[]');
let wishlist=[];
try{wishlist=JSON.parse(localStorage.getItem('ds_wishlist')||'[]')}catch(e){wishlist=[]}
wishlist=Array.isArray(wishlist)?[...new Set(wishlist.map(String).filter(Boolean))]:[];
let products=[];
try{products=JSON.parse(document.getElementById('productsJson')?.textContent||'[]')}catch(e){products=[]}
function productButtonUrl(template,p){
  const raw=String(template||'').trim();
  if(!raw)return '';
  return raw
    .replace(/\{product_id\}/g,encodeURIComponent(String(p?.id??'')))
    .replace(/\{product_slug\}/g,encodeURIComponent(String(p?.slug??'')))
    .replace(/\{product_name\}/g,encodeURIComponent(String(p?.name??'')));
}
const save=()=>{localStorage.setItem('ds_cart',JSON.stringify(cart));renderCart()};
function cartLineKey(item){return String(item.id)+':'+String(item.variant_id||'')}
function cartPayloadJSON(){return JSON.stringify(cart.map(i=>{const o={id:i.id,qty:i.qty};if(i.variant_id)o.variant_id=Number(i.variant_id);return o}))}
window.dsAddCartLine=function(item,opts){
  opts=opts||{};
  const key=cartLineKey(item);
  const ex=cart.find(i=>cartLineKey(i)===key);
  if(ex)ex.qty=Math.min(ex.qty+item.qty,item.maxStock);
  else cart.push(item);
  save();
  if(opts.openDrawer!==false) document.getElementById('cartDrawer')?.classList.add('open');
};
function frontI18n(key,fallback){
  try{
    const node=document.getElementById('frontI18n');
    const map=node?JSON.parse(node.textContent||'{}'):{};
    return map[key]||fallback;
  }catch(e){return fallback}
}
function renderWishlist(){
  const count=wishlist.length;
  document.querySelectorAll('[data-wishlist-count]').forEach(el=>el.textContent=count);
  document.querySelectorAll('.header-wishlist').forEach(el=>{el.classList.toggle('is-active',count>0);const icon=el.querySelector(':scope>span');if(icon)icon.textContent=count>0?'♥':'♡';});
  document.querySelectorAll('[data-wishlist-toggle]').forEach(btn=>{
    const active=wishlist.includes(String(btn.dataset.wishlistToggle||''));
    btn.classList.toggle('is-active',active);
    btn.setAttribute('aria-pressed',active?'true':'false');
    const icon=btn.querySelector('span'); if(icon) icon.textContent=active?'♥':'♡';
    const product=products.find(x=>String(x.id)===String(btn.dataset.wishlistToggle||''));
    btn.setAttribute('aria-label',(active?'Hapus ':'Tambahkan ')+(product?.name||'produk')+(active?' dari wishlist':' ke wishlist'));
  });
  const wishlistGrid=document.querySelector('[data-wishlist-grid]');
  if(wishlistGrid){
    let visible=0;
    wishlistGrid.querySelectorAll('[data-wishlist-card]').forEach(card=>{
      const show=wishlist.includes(String(card.dataset.id||''));
      card.hidden=!show;
      if(show) visible++;
    });
    wishlistGrid.classList.toggle('is-empty',visible===0);
    document.querySelector('[data-wishlist-empty]')?.classList.toggle('is-visible',visible===0);
  }
}
function toggleWishlist(id){
  id=String(id||''); if(!id)return;
  wishlist=wishlist.includes(id)?wishlist.filter(x=>x!==id):[...wishlist,id];
  localStorage.setItem('ds_wishlist',JSON.stringify(wishlist));
  renderWishlist();
}
let checkoutPromoDiscount=0;
let checkoutPromoTotal=null;
let checkoutTermsMap={};
let checkoutTermsSignature='';
function cartSubtotal(){return cart.reduce((a,b)=>a+b.qty*b.price,0)}
function parseProductTerms(raw){
  const base=[
    'Produk digital yang sudah terkirim tidak dapat dikembalikan.',
    'Garansi produk mengikuti deskripsi yang tertera pada halaman produk.',
    'Komplain wajib menyertakan invoice dan detail lengkap.'
  ];
  const lines=String(raw||'').split(/\r?\n/).map(line=>line.replace(/^[-•*\d.)\s]+/u,'').trim()).filter(Boolean);
  return lines.length?Array.from(new Set(lines)):base;
}
function renderCheckoutTerms(){
  const wrap=document.getElementById('checkoutTermsList');
  if(!wrap) return;
  if(!cart.length){
    wrap.innerHTML='<p class="checkout-terms-empty">Keranjang kosong. Tambahkan produk terlebih dahulu.</p>';
    return;
  }
  const html=cart.map((item,index)=>{
    const meta=checkoutTermsMap[String(item.id)]||{};
    const name=meta.name||item.name||'Produk Digital';
    const terms=parseProductTerms(meta.product_terms);
    const thumb=meta.thumbnail||item.thumb||'';
    const badge=(meta.delivery_type||'instant')==='manual'?'✍ Manual':'⚡ Instan';
    const warranty=String(meta.warranty_label||((Number(meta.warranty_days||0)>0)?(Number(meta.warranty_days)+' Hari'):'Tanpa garansi'));
    const thumbHtml=thumb && thumb!=='DIGITAL'
      ? '<span class="checkout-term-thumb has-image"><img src="'+String(thumb).replace(/"/g,'&quot;')+'" alt=""></span>'
      : '<span class="checkout-term-thumb">'+String(name).trim().charAt(0).toUpperCase()+'</span>';
    const items=terms.map(term=>'<li>'+term.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</li>').join('');
    return '<article class="checkout-term-card">'
      +'<button type="button" class="checkout-term-toggle'+(index===0?' is-open':'')+'" data-checkout-term-toggle aria-expanded="'+(index===0?'true':'false')+'">'
      +thumbHtml
      +'<span class="checkout-term-meta"><b>'+name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</b><small>'+badge+' · 🛡 '+warranty.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+' · '+terms.length+' ketentuan</small></span>'
      +'<span class="checkout-term-chevron">⌄</span>'
      +'</button>'
      +'<div class="checkout-term-content'+(index===0?' is-open':'')+'"><ul>'+items+'</ul></div>'
      +'</article>';
  }).join('');
  wrap.innerHTML=html;
}
async function syncCheckoutTerms(force=false){
  const wrap=document.getElementById('checkoutTermsList');
  if(!wrap) return;
  const ids=[...new Set(cart.map(item=>String(item.id)).filter(Boolean))].sort();
  const signature=ids.join(',');
  if(!ids.length){ checkoutTermsMap={}; checkoutTermsSignature=''; renderCheckoutTerms(); return; }
  if(!force && signature===checkoutTermsSignature){ renderCheckoutTerms(); return; }
  checkoutTermsSignature=signature;
  try{
    const res=await fetch('/api/cart.php?ids='+encodeURIComponent(signature),{headers:{'Accept':'application/json'},cache:'no-store'});
    const data=await res.json();
    const nextMap={};
    (Array.isArray(data.products)?data.products:[]).forEach(prod=>{ nextMap[String(prod.id)]=prod; });
    checkoutTermsMap=nextMap;
  }catch(err){
    checkoutTermsMap={};
  }
  renderCheckoutTerms();
}
function resetCheckoutPromo(msg){
  checkoutPromoDiscount=0; checkoutPromoTotal=null;
  const row=document.getElementById('checkoutDiscountRow'); if(row) row.hidden=true;
  document.querySelectorAll('[data-cart-discount]').forEach(el=>el.textContent='-Rp 0');
  const m=document.getElementById('promoMessage'); if(m){m.textContent=msg||'';m.className='promo-message'}
}
function renderCart(){let c=cart.reduce((a,b)=>a+b.qty,0),t=cartSubtotal();let finalTotal=checkoutPromoTotal!==null?checkoutPromoTotal:t;document.querySelectorAll('[data-cart-count]').forEach(el=>el.textContent=c);document.querySelectorAll('[data-cart-subtotal]').forEach(el=>el.textContent=rupiah(t));document.querySelectorAll('[data-cart-total]').forEach(el=>el.textContent=rupiah(finalTotal));let row=document.getElementById('checkoutDiscountRow');if(row){row.hidden=!(checkoutPromoDiscount>0);document.querySelectorAll('[data-cart-discount]').forEach(el=>el.textContent='-'+rupiah(checkoutPromoDiscount))}function cartLineName(i){return i.name+(i.variant_name?' <small class="cart-variant">'+i.variant_name+'</small>':'')}let box=document.getElementById('cartItems');if(box){box.innerHTML=cart.length?cart.map(i=>`<div class="cart-row">${cartThumbHTML(i.thumb)}<div style="flex:1"><b>${cartLineName(i)}</b><p>${rupiah(i.price)}</p><div class="qty"><button data-qty="${i.id}|${i.variant_id||''}" data-d="-1">-</button><span>${i.qty}</span><button data-qty="${i.id}|${i.variant_id||''}" data-d="1">+</button></div></div></div>`).join(''):'<p style="text-align:center;color:#64748b;padding:40px 0">Keranjang kosong.</p>'}let checkout=document.getElementById('checkoutItems');if(checkout){checkout.innerHTML=cart.map(i=>`<div class="cart-row">${cartThumbHTML(i.thumb)}<div><b>${cartLineName(i)}</b><p>${i.qty} × ${rupiah(i.price)}</p></div></div>`).join('')||'<p>Keranjang kosong.</p>'}let payload=document.getElementById('cartPayload'); if(payload) payload.value=cartPayloadJSON(); if(document.getElementById('checkoutTermsList')) syncCheckoutTerms(); document.getElementById('checkoutSubmitBtn')?.toggleAttribute('disabled', !(document.getElementById('checkoutTermsAgree')?.checked && cart.length)); const btn=document.getElementById('checkoutSubmitBtn'); if(btn) btn.setAttribute('aria-disabled', btn.hasAttribute('disabled') ? 'true' : 'false');}
async function applyCheckoutPromo(showEmptyMessage=false){
  const form=document.getElementById('checkoutForm'); const input=document.getElementById('promoCodeInput');
  if(!form||!input) return true;
  const code=input.value.trim().toUpperCase(); input.value=code;
  if(!code){resetCheckoutPromo(showEmptyMessage?'Masukkan kode promo terlebih dahulu.':'');renderCart();return !showEmptyMessage}
  if(!cart.length){resetCheckoutPromo('Keranjang kosong.');renderCart();return false}
  const btn=document.getElementById('applyPromoBtn'); const msg=document.getElementById('promoMessage');
  if(btn) btn.disabled=true; if(msg){msg.textContent='Mengecek kode promo...';msg.className='promo-message'}
  renderCart();
  try{
    const fd=new FormData(); fd.set('cart', cartPayloadJSON()); fd.set('promo_code', code);
    const res=await fetch('/api/promo_check.php',{method:'POST',body:new URLSearchParams(fd)});
    const data=await res.json();
    if(data.ok){checkoutPromoDiscount=Number(data.discount||0);checkoutPromoTotal=Number(data.total||0);if(msg){msg.textContent=data.message||'Kode promo berhasil diterapkan.';msg.className='promo-message ok'}renderCart();return true}
    resetCheckoutPromo(data.message||'Kode promo tidak valid.'); if(msg) msg.classList.add('err'); renderCart(); return false;
  }catch(e){resetCheckoutPromo('Gagal mengecek kode promo. Coba lagi.'); if(msg) msg.classList.add('err'); renderCart(); return false;}
  finally{if(btn) btn.disabled=false}
}

function setCategory(slug){
  slug=slug||'all';
  document.querySelectorAll('[data-filter-cat]').forEach(btn=>btn.classList.toggle('active',(btn.dataset.filterCat||'all')===slug));
  document.querySelectorAll('[data-product-card]').forEach(card=>{
    const ok=slug==='all'||String(card.dataset.cat||'')===String(slug);
    card.classList.toggle('hidden',!ok);
    card.toggleAttribute('hidden',!ok);
    if(ok){card.style.removeProperty('display')}else{card.style.setProperty('display','none','important')}
  });
  let visibleFlash=0;
  document.querySelectorAll('[data-flash-card]').forEach(card=>{
    const ok=slug==='all'||String(card.dataset.cat||'')===String(slug);
    card.classList.toggle('hidden',!ok);
    card.toggleAttribute('hidden',!ok);
    if(ok){card.style.removeProperty('display'); visibleFlash++;}else{card.style.setProperty('display','none','important')}
  });
  const flashStrip=document.getElementById('flashSaleStrip');
  if(flashStrip) flashStrip.classList.toggle('hidden',visibleFlash===0);
  document.getElementById('categoryPopover')?.classList.remove('open');
  document.getElementById('produk')?.scrollIntoView({behavior:'smooth',block:'start'});
}
function openProduct(id){const p=products.find(x=>String(x.id)===String(id));if(!p)return;const modal=document.getElementById('productModal');const modalThumb=document.getElementById('modalThumb');const modalIcon=document.getElementById('modalIcon');const thumbSrc=(p.thumbnail&&String(p.thumbnail).trim())?String(p.thumbnail).trim():'';if(modalThumb){if(thumbSrc){modalThumb.src=thumbSrc;modalThumb.style.display='block';modalThumb.removeAttribute('aria-hidden');if(modalIcon){modalIcon.textContent='';modalIcon.style.display='none';}}else{modalThumb.removeAttribute('src');modalThumb.style.display='none';modalThumb.setAttribute('aria-hidden','true');if(modalIcon){modalIcon.textContent='♛';modalIcon.style.display='grid';modalIcon.setAttribute('aria-hidden','false');}}}else if(modalIcon){modalIcon.textContent=thumbSrc?(p.icon||'♛'):'♛';modalIcon.style.display='grid';}const rawCategory=String(p.category||'').trim();document.getElementById('modalCategory').textContent=rawCategory&&rawCategory.toLowerCase()!=='digital'?rawCategory:'Akun Premium';const rawDiscount=String(p.discount||'-0%').trim();document.getElementById('modalDiscount').textContent=/^diskon/i.test(rawDiscount)?rawDiscount:('Diskon '+rawDiscount);document.getElementById('modalName').textContent=p.name;const subtitle=document.getElementById('modalSubtitle');if(subtitle)subtitle.textContent=String(p.description||'Produk digital siap dikirim otomatis setelah pembayaran berhasil.').replace(/\s+/g,' ').trim();const modalDesc=document.getElementById('modalDesc');if(modalDesc){modalDesc.innerHTML=p.descriptionHtml||String(p.description||'Produk digital siap dikirim otomatis setelah pembayaran berhasil.').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\r?\n/g,'<br>');}const rating=renderModalRating(p.rating);const rateVal=document.getElementById('modalRatingValue');if(rateVal)rateVal.textContent=rating.toFixed(1);const rateCount=document.getElementById('modalRatingCount');if(rateCount)rateCount.textContent='('+Number(p.sold||0)+')';renderModalFeatures(p);const warranty=String(p.warranty||'Garansi').trim()||'Garansi';const mw=document.getElementById('modalWarranty');if(mw)mw.textContent=warranty;const deliveryType=String(p.deliveryType||'instant').toLowerCase()==='manual'?'manual':'instant';const delivery=document.getElementById('modalDelivery');if(delivery){delivery.textContent=p.deliveryLabel||(deliveryType==='manual'?'✍ Manual':'⚡ Instan');delivery.classList.toggle('manual',deliveryType==='manual');delivery.classList.toggle('instant',deliveryType!=='manual');}document.getElementById('modalStock').textContent=frontI18n('modalStock','Pilih varian');document.getElementById('modalSold').textContent=p.sold;document.getElementById('modalPrice').textContent=rupiah(p.price);const del=document.getElementById('modalOriginal');del.textContent=(Number(p.originalPrice)>Number(p.price))?rupiah(p.originalPrice):'';const add=document.getElementById('modalAddCart');const pdpUrl='/'+encodeURIComponent(String(p.slug||''));if(add){add.href=pdpUrl;add.textContent=frontI18n('modalCta','Lihat Produk & Pilih Varian');add.removeAttribute('disabled');add.removeAttribute('aria-disabled');add.classList.remove('is-out');}const wishBtn=document.getElementById('modalWishlistBtn');if(wishBtn)wishBtn.dataset.wishlistToggle=p.id;const telegramBuy=document.getElementById('modalTelegramBuy');if(telegramBuy){const telegramLabel=telegramBuy.querySelector('span');const telegramUrl=productButtonUrl(telegramBuy.dataset.urlTemplate||'',p);if(telegramUrl){telegramBuy.href=telegramUrl;telegramBuy.hidden=false;telegramBuy.removeAttribute('tabindex');telegramBuy.setAttribute('aria-label','Beli '+String(p.name||'produk')+' di Bot Telegram');telegramBuy.removeAttribute('title');if(telegramLabel)telegramLabel.textContent='Beli di Bot Telegram';}else{telegramBuy.hidden=true;telegramBuy.removeAttribute('href');telegramBuy.removeAttribute('aria-label');telegramBuy.removeAttribute('tabindex');telegramBuy.removeAttribute('title');if(telegramLabel)telegramLabel.textContent='Beli di Bot Telegram';}}modal.classList.remove('is-out');modal.classList.add('in-stock');modal.classList.toggle('has-default-thumb',!thumbSrc);modal.classList.add('open');modal.setAttribute('aria-hidden','false');renderWishlist();setupModalDescription();}
function renderModalRating(value){const rating=Math.max(1,Math.min(5,Number(value)||5));const stars=document.getElementById('modalRatingStars');const row=document.getElementById('modalRatingRow');if(stars){stars.innerHTML='';for(let i=1;i<=5;i++){const star=document.createElement('i');const remaining=rating-(i-1);star.textContent='★';star.className=remaining>=1?'is-full':(remaining>=0.5?'is-half':'is-empty');stars.appendChild(star);}}if(row)row.setAttribute('aria-label','Rating '+rating.toFixed(1)+' dari 5');return rating;}
function renderModalFeatures(p){const wrap=document.getElementById('modalFeatureList');if(!wrap)return;const defaults=[['✉','Produk Digital','Siap digunakan setelah pembayaran berhasil.','feature-mail'],['⚡','Proses Cepat','Pengiriman dan aktivasi dilakukan secepat mungkin.','feature-zap'],['✔','Aman & Terjamin','Produk dijaga agar tetap aman dan terpercaya.','feature-check'],['🎧','Support Responsif','Bantuan cepat jika ada kendala pada pesanan.','feature-support']];const points=Array.isArray(p.descPoints)?p.descPoints.filter(Boolean).slice(0,4):[];const titles=['Produk Digital','Siap Pakai','Aman & Terjamin','Support Responsif'];const classes=['feature-mail','feature-zap','feature-check','feature-support'];const icons=['✉','⚡','✔','🎧'];const items=(points.length?points.map((txt,i)=>[icons[i]||'•',titles[i]||'Fitur',txt,classes[i]||'feature-mail']):defaults).map(item=>`<div class="modal-feature-item"><i class="${item[3]}">${item[0]}</i><div><b>${item[1]}</b><p>${item[2]}</p></div></div>`).join('');wrap.innerHTML=items;}
function closeProduct(){const modal=document.getElementById('productModal');modal?.classList.remove('open');modal?.setAttribute('aria-hidden','true')}
function sortProducts(mode){
  const label=document.getElementById('sortLabel');
  if(label) label.textContent = mode==='best' ? 'Terlaris' : 'Terbaru';
  document.querySelectorAll('[data-sort]').forEach(b=>b.classList.toggle('active',b.dataset.sort===mode));
  const grid=document.getElementById('productGrid');
  if(grid){
    [...grid.querySelectorAll('[data-product-card]')].sort((a,b)=>{
      const av=Number(a.dataset[mode==='best'?'sold':'order']||0), bv=Number(b.dataset[mode==='best'?'sold':'order']||0);
      return bv-av;
    }).forEach(el=>grid.appendChild(el));
  }
  const tbody=document.querySelector('#productListView tbody');
  if(tbody){
    [...tbody.querySelectorAll('[data-product-card]')].sort((a,b)=>{
      const av=Number(a.dataset[mode==='best'?'sold':'order']||0), bv=Number(b.dataset[mode==='best'?'sold':'order']||0);
      return bv-av;
    }).forEach(el=>tbody.appendChild(el));
  }
  document.getElementById('sortMenu')?.classList.remove('open');
}

function cartThumbHTML(thumb){return thumb&&thumb!=='DIGITAL'?`<i class="cart-thumb-img"><img src="${thumb}" alt=""></i>`:'<i>DIGITAL</i>'}
document.addEventListener('click',e=>{const telegramBuy=e.target.closest('#modalTelegramBuy');if(telegramBuy&&telegramBuy.getAttribute('aria-disabled')==='true'){e.preventDefault();e.stopPropagation();return}if(e.target.closest('[data-close-product]')){e.preventDefault();e.stopPropagation();closeProduct();return}let wish=e.target.closest('[data-wishlist-toggle]');if(wish){e.preventDefault();e.stopPropagation();toggleWishlist(wish.dataset.wishlistToggle);return}let q=e.target.closest('[data-qty]');if(q){const parts=String(q.dataset.qty||'').split('|');const key=parts[0]+':'+(parts[1]||'');let it=cart.find(i=>cartLineKey(i)===key);if(it){let next=it.qty+Number(q.dataset.d);if(Number(q.dataset.d)>0&&Number(it.maxStock||0)>0&&next>Number(it.maxStock)){alert(frontI18n('maxStock','Jumlah melebihi stok tersedia.'));return}it.qty=next;if(it.qty<=0)cart=cart.filter(x=>cartLineKey(x)!==key);save()}return}let filter=e.target.closest('[data-filter-cat]');if(filter){e.preventDefault();setCategory(filter.dataset.filterCat||'all');return}let open=e.target.closest('[data-open-product]');if(open){openProduct(open.dataset.openProduct);return}if(e.target.closest('[data-sort-open]')){document.getElementById('sortMenu')?.classList.toggle('open');return}let sort=e.target.closest('[data-sort]');if(sort){sortProducts(sort.dataset.sort||'latest');return}if(!e.target.closest('.sort-wrap'))document.getElementById('sortMenu')?.classList.remove('open');if(e.target.closest('[data-open-cats]')){document.getElementById('categoryPopover')?.classList.toggle('open');return}if(!e.target.closest('.category-menu-wrap'))document.getElementById('categoryPopover')?.classList.remove('open');let sb=e.target.closest('.searchbar');if(sb&&window.matchMedia('(max-width:640px)').matches&&!sb.classList.contains('mobile-open')){e.preventDefault();sb.classList.add('mobile-open');setTimeout(()=>sb.querySelector('input')?.focus(),40);return}if(!e.target.closest('.searchbar'))document.querySelector('.searchbar')?.classList.remove('mobile-open');if(e.target.closest('[data-open-cart]')){if(typeof closeMobileMenu==='function')closeMobileMenu();document.getElementById('cartDrawer')?.classList.add('open')}if(e.target.closest('[data-close-cart]'))document.getElementById('cartDrawer')?.classList.remove('open');if(e.target.closest('[data-theme-toggle]')){let h=document.documentElement;h.dataset.theme=h.dataset.theme==='dark'?'light':'dark';localStorage.setItem('ds_theme',h.dataset.theme)}let vm=e.target.closest('[data-view-mode]');if(vm){e.preventDefault(); if(typeof setProductView==='function'){setProductView(vm.dataset.viewMode||'grid')}else{let side=document.getElementById('productsSide'); if(side) side.dataset.view=vm.dataset.viewMode; document.querySelectorAll('[data-view-mode]').forEach(b=>b.classList.toggle('active',b===vm));} return}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeProduct();document.getElementById('categoryPopover')?.classList.remove('open');document.getElementById('cartDrawer')?.classList.remove('open');document.getElementById('sortMenu')?.classList.remove('open');document.querySelector('.searchbar')?.classList.remove('mobile-open')}});
if(localStorage.getItem('ds_theme'))document.documentElement.dataset.theme=localStorage.getItem('ds_theme');
renderCart();
renderWishlist();
let form=document.getElementById('checkoutForm');if(form){
  const promoInput=document.getElementById('promoCodeInput');
  const applyBtn=document.getElementById('applyPromoBtn');
  const agreeInput=document.getElementById('checkoutTermsAgree');
  const submitBtn=document.getElementById('checkoutSubmitBtn');
  const updateCheckoutSubmitState=()=>{
    if(!submitBtn) return;
    const ready=!!(agreeInput && agreeInput.checked && cart.length);
    submitBtn.disabled=!ready;
    submitBtn.setAttribute('aria-disabled', ready ? 'false' : 'true');
  };
  if(applyBtn) applyBtn.addEventListener('click',()=>applyCheckoutPromo(true));
  if(promoInput){
    promoInput.addEventListener('input',()=>{promoInput.value=promoInput.value.toUpperCase();resetCheckoutPromo('');renderCart();updateCheckoutSubmitState()});
    promoInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();applyCheckoutPromo(true)}});
  }
  if(agreeInput) agreeInput.addEventListener('change',updateCheckoutSubmitState);
  syncCheckoutTerms(true);
  updateCheckoutSubmitState();
  form.addEventListener('submit',async e=>{e.preventDefault();renderCart();updateCheckoutSubmitState();if(!cart.length){alert('Keranjang kosong');return}if(agreeInput && !agreeInput.checked){alert('Silakan baca dan setujui syarat & ketentuan produk terlebih dahulu.');agreeInput.focus();return}if(promoInput&&promoInput.value.trim()){const valid=await applyCheckoutPromo(false);if(!valid)return}let fd=new FormData(form);fd.set('promo_code',(fd.get('promo_code')||'').toString().trim().toUpperCase());let res=await fetch('/api/checkout.php',{method:'POST',body:new URLSearchParams(fd)});let data=await res.json();if(data.ok){cart=[];save();location.href=data.payment_url}else alert(data.message||'Checkout gagal')})
}
document.addEventListener('click',function(e){
  const toggle=e.target.closest('[data-checkout-term-toggle]');
  if(!toggle) return;
  const card=toggle.closest('.checkout-term-card');
  const content=card?card.querySelector('.checkout-term-content'):null;
  const isOpen=toggle.classList.contains('is-open');
  toggle.classList.toggle('is-open',!isOpen);
  toggle.setAttribute('aria-expanded',String(!isOpen));
  if(content) content.classList.toggle('is-open',!isOpen);
});
async function checkPayment(invoice){try{let r=await fetch('/api/payment_status.php?invoice='+encodeURIComponent(invoice));let d=await r.json();let el=document.getElementById('payStatus');if(el)el.textContent=d.status_text||d.status||'-';if(d.status==='delivered'||d.status==='paid'){location.href='/track-order.php?invoice='+encodeURIComponent(invoice)}}catch(e){}}
function closeMobileMenu(){const m=document.getElementById('mobileMenu');m?.classList.remove('open');m?.setAttribute('aria-hidden','true');document.body.classList.remove('mobile-menu-open')}
function openMobileMenu(){const m=document.getElementById('mobileMenu');m?.classList.add('open');m?.setAttribute('aria-hidden','false');document.body.classList.add('mobile-menu-open')}
function toggleMobileMenu(){const m=document.getElementById('mobileMenu');if(m?.classList.contains('open'))closeMobileMenu();else openMobileMenu()}
/* Close drawer when viewport becomes desktop (e.g. un-maximize / resize) */
function syncMobileMenuToViewport(){if(window.matchMedia('(min-width:641px)').matches)closeMobileMenu()}
window.addEventListener('resize',syncMobileMenuToViewport);
window.matchMedia('(min-width:641px)').addEventListener?.('change',syncMobileMenuToViewport);
/* PC header search toggle — short [field][button] in the header */
function closeHeadSearch(){
  const form=document.querySelector('[data-head-search]');
  const btn=document.querySelector('[data-toggle-head-search]');
  if(form){form.hidden=true}
  if(btn){btn.classList.remove('is-active');btn.setAttribute('aria-expanded','false')}
}
function openHeadSearch(){
  const form=document.querySelector('[data-head-search]');
  const btn=document.querySelector('[data-toggle-head-search]');
  if(!form) return;
  form.hidden=false;
  if(btn){btn.classList.add('is-active');btn.setAttribute('aria-expanded','true')}
  const input=form.querySelector('input[name="q"]');
  if(input){input.focus();input.select()}
}
function toggleHeadSearch(){
  const form=document.querySelector('[data-head-search]');
  if(!form) return;
  if(form.hidden) openHeadSearch(); else closeHeadSearch();
}
document.addEventListener('click',function(e){
  if(e.target.closest('[data-toggle-head-search]')){e.preventDefault();toggleHeadSearch();return}
  const form=e.target.closest('[data-head-search]');
  if(!form && !e.target.closest('[data-toggle-head-search]')) closeHeadSearch();
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeHeadSearch()});
document.addEventListener('click',function(e){
  if(e.target.closest('[data-open-mobile-menu]')){e.preventDefault();toggleMobileMenu();return}
  if(e.target.closest('[data-close-mobile-menu]')){closeMobileMenu();return}
  if(e.target.closest('.mobile-menu [data-filter-cat]')){setTimeout(closeMobileMenu,80);return}
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeMobileMenu()});
document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('[data-close-product]').forEach(btn=>btn.addEventListener('click',ev=>{ev.preventDefault();ev.stopPropagation();closeProduct();}));});
function setProductView(mode){
  mode = mode === 'list' ? 'list' : 'grid';
  const side = document.getElementById('productsSide');
  if(!side) return;
  side.dataset.view = mode;
  side.classList.toggle('is-list-view', mode === 'list');
  side.classList.toggle('is-grid-view', mode !== 'list');
  document.querySelectorAll('[data-view-mode]').forEach(btn=>{
    btn.classList.toggle('active', btn.dataset.viewMode === mode);
    btn.setAttribute('aria-pressed', btn.dataset.viewMode === mode ? 'true' : 'false');
  });
}
document.addEventListener('DOMContentLoaded',()=>{
  setProductView(document.getElementById('productsSide')?.dataset.view || 'grid');
  document.querySelectorAll('[data-view-mode]').forEach(btn=>{
    btn.addEventListener('click',ev=>{
      ev.preventDefault();
      ev.stopPropagation();
      setProductView(btn.dataset.viewMode || 'grid');
    }, true);
  });
});
(function(){
  const rememberKey='ds_product_view';
  const oldSet=window.setProductView;
  window.setProductView=function(mode){
    mode = mode === 'list' ? 'list' : 'grid';
    const side=document.getElementById('productsSide');
    if(!side) return;
    side.dataset.view=mode;
    side.classList.toggle('is-list-view',mode==='list');
    side.classList.toggle('is-grid-view',mode!=='list');
    document.querySelectorAll('[data-view-mode]').forEach(btn=>{
      const active=btn.dataset.viewMode===mode;
      btn.classList.toggle('active',active);
      btn.setAttribute('aria-pressed',active?'true':'false');
    });
    localStorage.setItem(rememberKey,mode);
  };
  document.addEventListener('DOMContentLoaded',()=>{
    const saved=localStorage.getItem(rememberKey);
    window.setProductView(saved || document.getElementById('productsSide')?.dataset.view || 'grid');
  });
})();
(function(){
  function expirePaymentUI(){
    const box=document.querySelector('.pay-timer-box');
    box?.classList.add('expired');
    const panel=document.getElementById('checkoutPayPanel');
    panel?.classList.add('is-payment-expired');
    const pay=document.getElementById('gatewayPayButton');
    if(pay){
      pay.removeAttribute('href');
      pay.removeAttribute('target');
      pay.setAttribute('aria-disabled','true');
      pay.classList.add('disabled','is-expired');
      pay.textContent='Pembayaran Expired';
    }
    const qris=document.getElementById('gatewayQrisCard');
    if(qris && !qris.dataset.expiredRendered){
      qris.dataset.expiredRendered='1';
      const label=window.dsPaymentProvider==='casaku'?'Casaku':(window.dsPaymentProvider==='tokopay'?'Tokopay':'QRIS'); qris.innerHTML='<b>'+label+' QRIS</b><div class="qris-box qris-expired-box"><div class="qris-inner">QRIS<br>EXPIRED</div></div><p>Waktu pembayaran sudah habis. QRIS dan tombol bayar dinonaktifkan. Silakan buat pesanan baru.</p>';
    }
  }
  function tick(){
    const el=document.getElementById('checkoutTimer');
    if(!el||!window.dsPaymentExpiresAt) return;
    let left=0;
    if(typeof window.dsPaymentExpiresIn==='number'){
      if(!window.__dsExpireBase) window.__dsExpireBase={at:Date.now(), seconds:window.dsPaymentExpiresIn};
      left=Math.max(0,Math.floor(window.__dsExpireBase.seconds-((Date.now()-window.__dsExpireBase.at)/1000)));
    }else{
      const end=new Date(String(window.dsPaymentExpiresAt).replace(' ','T')).getTime();
      if(!end) return;
      left=Math.max(0,Math.floor((end-Date.now())/1000));
    }
    const m=String(Math.floor(left/60)).padStart(2,'0');
    const s=String(left%60).padStart(2,'0');
    el.textContent=m+':'+s;
    if(left<=0) expirePaymentUI();
  }
  document.addEventListener('DOMContentLoaded',()=>{tick();setInterval(tick,1000)});
})();
(function(){
  document.addEventListener('DOMContentLoaded',()=>{
    const list=document.querySelector('.sales-list');
    if(!list || list.querySelector('.sales-list-inner')) return;
    const items=[...list.children];
    const inner=document.createElement('div');
    inner.className='sales-list-inner';
    items.forEach(x=>inner.appendChild(x));
    items.forEach(x=>inner.appendChild(x.cloneNode(true)));
    list.appendChild(inner);
  });
})();
(function(){
  const hideKey='ds_recent_sale_toast_hidden';
  let items=[];
  let index=0;
  let showTimer=null;
  let hideTimer=null;
  let pollTimer=null;
  let lastSignature='';

  function parseInitialItems(){
    const node=document.getElementById('recentSalesJson');
    if(!node) return [];
    try{
      const parsed=JSON.parse(node.textContent || '[]');
      return Array.isArray(parsed) ? parsed : [];
    }catch(e){ return []; }
  }

  function signature(list){
    return list.map(x=>[x.product_id||'',x.product_name||'',x.created_at||'',x.time_text||''].join('|')).join('~');
  }

  function setThumb(toast,item){
    const thumb=toast.querySelector('[data-recent-sale-thumb]');
    if(!thumb) return;
    if(item.thumbnail){
      thumb.innerHTML='<img src="'+String(item.thumbnail).replace(/"/g,'&quot;')+'" alt="" loading="lazy">';
    }else{
      thumb.textContent='🛒';
    }
  }

  function render(item){
    const toast=document.getElementById('recentSaleToast');
    if(!toast || !item) return;
    const name=toast.querySelector('[data-recent-sale-name]');
    const buyer=toast.querySelector('[data-recent-sale-buyer]');
    const time=toast.querySelector('[data-recent-sale-time]');
    if(name) name.textContent=item.product_name || 'Produk digital';
    if(buyer) buyer.textContent=item.buyer_name || 'Pembeli';
    if(time) time.textContent=item.time_text || 'baru saja';
    setThumb(toast,item);
    toast.classList.add('show');
    toast.setAttribute('aria-hidden','false');
    clearTimeout(showTimer);
    showTimer=setTimeout(()=>{
      toast.classList.remove('show');
      toast.setAttribute('aria-hidden','true');
      clearTimeout(hideTimer);
      hideTimer=setTimeout(next,15000);
    },25000);
  }

  function next(){
    if(localStorage.getItem(hideKey)==='1' || !items.length) return;
    render(items[index % items.length]);
    index=(index+1)%items.length;
  }

  async function poll(){
    if(localStorage.getItem(hideKey)==='1') return;
    try{
      const res=await fetch('/api/recent_sales.php',{headers:{'Accept':'application/json'},cache:'no-store'});
      if(!res.ok) return;
      const data=await res.json();
      if(!data || !Array.isArray(data.items)) return;
      const sig=signature(data.items);
      if(sig && sig!==lastSignature){
        items=data.items;
        lastSignature=sig;
        index=0;
        const toast=document.getElementById('recentSaleToast');
        if(toast && !toast.classList.contains('show')){
          clearTimeout(hideTimer);
          next();
        }
      }
    }catch(e){}
  }

  document.addEventListener('DOMContentLoaded',()=>{
    const toast=document.getElementById('recentSaleToast');
    if(!toast || localStorage.getItem(hideKey)==='1') return;
    items=parseInitialItems();
    lastSignature=signature(items);
    toast.querySelector('[data-close-recent-sale]')?.addEventListener('click',()=>{
      localStorage.setItem(hideKey,'1');
      clearTimeout(showTimer);clearTimeout(hideTimer);clearInterval(pollTimer);
      toast.classList.remove('show');
      toast.setAttribute('aria-hidden','true');
    });
    if(items.length) setTimeout(next,900);
    pollTimer=setInterval(poll,10000);
  });
})();
(function(){
  function pad(n){return String(n).padStart(2,'0')}
  function renderCountdown(el){
    if(!el) return;
    const target=el.getAttribute('data-countdown');
    const daysEl=el.querySelector('[data-cd-days]');
    const hoursEl=el.querySelector('[data-cd-hours]');
    const minutesEl=el.querySelector('[data-cd-minutes]');
    const secondsEl=el.querySelector('[data-cd-seconds]');
    if(!daysEl || !hoursEl || !minutesEl || !secondsEl) return;
    if(!target){daysEl.textContent='00';hoursEl.textContent='00';minutesEl.textContent='00';secondsEl.textContent='00';return}
    const end=new Date(target).getTime();
    if(!end){daysEl.textContent='00';hoursEl.textContent='00';minutesEl.textContent='00';secondsEl.textContent='00';return}
    const left=Math.max(0,Math.floor((end-Date.now())/1000));
    const d=Math.floor(left/86400);
    const h=Math.floor((left%86400)/3600);
    const m=Math.floor((left%3600)/60);
    const sec=left%60;
    daysEl.textContent=pad(d);
    hoursEl.textContent=pad(h);
    minutesEl.textContent=pad(m);
    secondsEl.textContent=pad(sec);
    if(left<=0){
      el.closest('[data-flash-card]')?.classList.add('is-expired');
    }
  }
  function tick(){document.querySelectorAll('[data-countdown]').forEach(renderCountdown)}
  document.addEventListener('DOMContentLoaded',()=>{
    tick();
    setInterval(tick,1000);
  });
})();
(function(){
  document.addEventListener('click',function(e){
    const btn=e.target.closest('[data-flash-slide]');
    if(!btn) return;
    e.preventDefault();
    const track=document.getElementById('flashSaleTrack');
    if(!track) return;
    const direction=btn.dataset.flashSlide==='prev'?-1:1;
    const amount=track.clientWidth+16;
    track.scrollBy({left:direction*amount,behavior:'smooth'});
  });
})();
/* Language switcher */
(function(){
  document.addEventListener('click',function(e){
    var btn=e.target.closest('[data-open-lang]');
    if(btn){
      e.stopPropagation();
      var pop=btn.closest('.lang-switcher').querySelector('.lang-popover');
      if(pop) pop.classList.toggle('open');
      return;
    }
    var opt=e.target.closest('.lang-option');
    if(opt){
      // link navigates naturally with ?lang= param
      return;
    }
    document.querySelectorAll('.lang-popover.open').forEach(function(p){p.classList.remove('open');});
  });
})();