(function(){
  function readJson(id){
    try{ const node=document.getElementById(id); return node?JSON.parse(node.textContent||'null'):null; }
    catch(e){ return null; }
  }
  const data=readJson('productPageJson');
  const i18n=readJson('productPageI18n')||{};
  if(!data||!Array.isArray(data.variants)||!data.variants.length) return;

  let selectedVariant=null;
  let qty=1;
  let toastTimer=null;

  const nameEl=document.querySelector('[data-pp-selected-name]');
  const qtyValue=document.querySelector('[data-pp-qty-value]');
  const qtyDown=document.querySelector('[data-pp-qty="down"]');
  const qtyUp=document.querySelector('[data-pp-qty="up"]');
  const addBtn=document.getElementById('ppAddCart');
  const buyBtn=document.getElementById('ppBuyNow');
  const basePriceEl=document.querySelector('[data-pp-base-price]');
  const heroOriginalEl=document.querySelector('[data-pp-hero-original]');
  const heroDiscEl=document.querySelector('[data-pp-hero-discount]');
  const cartHint=document.querySelector('[data-pp-cart-hint]');
  const toastEl=document.getElementById('ppToast');
  const rupiah=n=>'Rp '+new Intl.NumberFormat('id-ID').format(Number(n||0));

  /* Hero price follows variant stock/price (init = cheapest variant from server) */
  function syncHeroPrice(price,originalPrice,discPct){
    if(basePriceEl) basePriceEl.textContent=rupiah(price);
    if(heroOriginalEl){
      if(Number(originalPrice)>Number(price)){
        heroOriginalEl.hidden=false;
        heroOriginalEl.textContent=rupiah(originalPrice);
      }else{
        heroOriginalEl.hidden=true;
        heroOriginalEl.textContent='';
      }
    }
    if(heroDiscEl){
      const pct=Number(discPct)||0;
      if(pct>0){
        heroDiscEl.hidden=false;
        heroDiscEl.textContent='-'+pct+'%';
      }else{
        heroDiscEl.hidden=true;
        heroDiscEl.textContent='';
      }
    }
  }

  function setActiveVariantCard(id){
    document.querySelectorAll('[data-pp-variant]').forEach(card=>{
      const on=String(card.dataset.ppVariant)===String(id);
      card.classList.toggle('is-active',on);
      card.setAttribute('aria-checked',on?'true':'false');
    });
  }
  function renderPurchase(){
    if(!selectedVariant){
      if(nameEl) nameEl.textContent=i18n.chooseVariant||'Pilih varian terlebih dahulu';
      if(qtyValue) qtyValue.textContent='1';
      [qtyDown,qtyUp].forEach(b=>{ if(b) b.disabled=true; });
      if(addBtn){ addBtn.disabled=true; addBtn.textContent=i18n.chooseVariant||'Pilih varian terlebih dahulu'; }
      if(buyBtn){ buyBtn.disabled=true; buyBtn.textContent=i18n.chooseVariant||'Pilih varian terlebih dahulu'; }
      return;
    }
    if(nameEl) nameEl.textContent=selectedVariant.name;
    const card=document.querySelector('[data-pp-variant="'+selectedVariant.id+'"]');
    const vPrice=card?Number(card.dataset.ppVariantPrice||selectedVariant.price):Number(selectedVariant.price);
    const vOrig=card?Number(card.dataset.ppVariantOriginal||0):0;
    const vDisc=card?Number(card.dataset.ppVariantDisc||0):0;
    syncHeroPrice(vPrice,vOrig,vDisc);
    const maxStock=Math.max(0,Number(selectedVariant.stock)||0);
    qty=Math.min(Math.max(1,qty),Math.max(1,maxStock));
    if(qtyValue) qtyValue.textContent=String(qty);
    const out=maxStock<=0;
    [qtyDown,qtyUp].forEach(b=>{ if(b) b.disabled=out||maxStock<=1; });
    if(addBtn){
      addBtn.disabled=out;
      addBtn.textContent=out?(i18n.outOfStock||'Stok Habis'):(i18n.addCart||'Tambah ke Keranjang');
      if(!out) addBtn.classList.remove('is-added');
    }
    if(buyBtn){
      buyBtn.disabled=out;
      buyBtn.textContent=out?(i18n.outOfStock||'Stok Habis'):(i18n.buyNow||'Beli Sekarang');
    }
  }
  function selectVariant(id){
    const found=data.variants.find(v=>String(v.id)===String(id));
    if(!found) return;
    selectedVariant=found;
    setActiveVariantCard(found.id);
    renderPurchase();
  }

  function readCart(){
    try{
      const raw=JSON.parse(localStorage.getItem('ds_cart')||'[]');
      return Array.isArray(raw)?raw:[];
    }catch(e){ return []; }
  }
  function cartQtyForProduct(){
    return readCart()
      .filter(i=>String(i.id)===String(data.id))
      .reduce((sum,i)=>sum+(Number(i.qty)||0),0);
  }
  function renderCartHint(){
    if(!cartHint) return;
    const n=cartQtyForProduct();
    if(n>0){
      cartHint.hidden=false;
      cartHint.classList.add('is-on');
      cartHint.textContent=new Intl.NumberFormat('id-ID').format(n)+' '+(i18n.inCart||'Item di Keranjang');
    }else{
      cartHint.hidden=true;
      cartHint.textContent='';
    }
  }

  function showToast(message){
    if(!toastEl) return;
    toastEl.innerHTML='<span class="pp-toast-icon" aria-hidden="true">✓</span><span></span>';
    const text=toastEl.querySelector('span:last-child');
    if(text) text.textContent=message;
    toastEl.hidden=false;
    requestAnimationFrame(()=>toastEl.classList.add('is-on'));
    if(toastTimer) clearTimeout(toastTimer);
    toastTimer=setTimeout(()=>{
      toastEl.classList.remove('is-on');
      setTimeout(()=>{ toastEl.hidden=true; },220);
    },2600);
  }

  /* Tabs — scope each group to its own .pp-tabs-section */
  document.addEventListener('click',function(e){
    const tab=e.target.closest('[data-pp-tab]');
    if(tab){
      const key=tab.dataset.ppTab;
      const section=tab.closest('.pp-tabs-section');
      if(!section) return;
      section.querySelectorAll('[data-pp-tab]').forEach(b=>{
        const on=b===tab;
        b.classList.toggle('is-active',on);
        b.setAttribute('aria-selected',on?'true':'false');
      });
      section.querySelectorAll('[data-pp-panel]').forEach(panel=>{
        panel.hidden=panel.dataset.ppPanel!==key;
      });
      return;
    }
    const variantCard=e.target.closest('[data-pp-variant]');
    if(variantCard){ selectVariant(variantCard.dataset.ppVariant); return; }
    const qtyBtn=e.target.closest('[data-pp-qty]');
    if(qtyBtn){
      if(!selectedVariant) return;
      const maxStock=Math.max(1,Number(selectedVariant.stock)||1);
      qty=Math.min(Math.max(1,qty+(qtyBtn.dataset.ppQty==='up'?1:-1)),maxStock);
      if(qtyValue) qtyValue.textContent=String(qty);
      return;
    }
    const shareBtn=e.target.closest('#ppShare');
    if(shareBtn){
      const url=location.href;
      const title=data.name||document.title;
      if(navigator.share){
        navigator.share({title:title,url:url}).catch(function(){});
        return;
      }
      if(navigator.clipboard&&navigator.clipboard.writeText){
        navigator.clipboard.writeText(url).then(function(){
          showToast(i18n.shareCopied||'Tautan produk disalin');
        }).catch(function(){
          showToast(i18n.shareFailed||'Gagal membagikan tautan');
        });
        return;
      }
      alert(url);
      return;
    }
  });

  function buildCartLine(){
    return {
      id:String(data.id),
      name:data.name,
      price:Math.round(selectedVariant.price),
      thumb:data.thumb||'DIGITAL',
      maxStock:Math.max(0,Number(selectedVariant.stock)||0),
      qty:qty,
      variant_id:String(selectedVariant.id),
      variant_name:selectedVariant.name,
    };
  }
  function validateSelection(){
    if(!selectedVariant){ alert(i18n.chooseVariant||'Pilih varian terlebih dahulu'); return false; }
    if(Number(selectedVariant.stock)<=0){ alert(i18n.outOfStock||'Stok Habis'); return false; }
    return true;
  }
  if(addBtn){
    addBtn.addEventListener('click',function(){
      if(!validateSelection()) return;
      const line=buildCartLine();
      if(typeof window.dsAddCartLine==='function') window.dsAddCartLine(line,{openDrawer:false});
      if(addBtn){
        addBtn.classList.add('is-added');
        addBtn.textContent=i18n.addedShort||'✓ Ditambahkan';
      }
      renderCartHint();
      showToast(i18n.added||'Ditambahkan ke keranjang');
      setTimeout(function(){
        if(addBtn&&!addBtn.disabled){
          addBtn.classList.remove('is-added');
          addBtn.textContent=i18n.addCart||'Tambah ke Keranjang';
        }
      },2200);
    });
  }
  if(buyBtn){
    buyBtn.addEventListener('click',function(){
      if(!validateSelection()) return;
      let existing=[];
      try{ existing=JSON.parse(localStorage.getItem('ds_cart')||'[]'); }catch(e){ existing=[]; }
      if(Array.isArray(existing)&&existing.length){
        if(!confirm(i18n.replaceCart||'Beli Sekarang akan mengganti isi keranjang. Lanjutkan?')) return;
      }
      localStorage.setItem('ds_cart',JSON.stringify([buildCartLine()]));
      location.href='/checkout.php';
    });
  }

  const tg=document.getElementById('ppTelegramBuy');
  if(tg){
    const template=String(tg.dataset.urlTemplate||'').trim();
    if(!template){ tg.hidden=true; }
    else{
      tg.href=template
        .replace(/\{product_id\}/g,encodeURIComponent(String(data.id)))
        .replace(/\{product_slug\}/g,encodeURIComponent(String(data.slug)))
        .replace(/\{product_name\}/g,encodeURIComponent(String(data.name)));
    }
  }

  function openReviewsTab(){
    const btn=document.querySelector('[data-pp-tab="reviews"]');
    if(btn) btn.click();
  }
  if(location.hash==='#reviews') openReviewsTab();
  window.addEventListener('hashchange',function(){
    if(location.hash==='#reviews') openReviewsTab();
  });

  window.addEventListener('storage',function(ev){
    if(ev.key==='ds_cart') renderCartHint();
  });

  /* Prefer cheapest in-stock variant, else first — matches hero "Mulai dari" price */
  const inStock=data.variants.filter(v=>Number(v.stock)>0);
  const pool=inStock.length?inStock:data.variants;
  let initialId=data.initialVariantId;
  if(initialId==null || !data.variants.some(v=>String(v.id)===String(initialId))){
    initialId=(pool.slice().sort((a,b)=>Number(a.price)-Number(b.price))[0]||{}).id;
  }
  if(initialId!=null) selectVariant(initialId);
  else renderPurchase();
  renderCartHint();
})();
