(function(){
  function readJson(id){
    try{ const node=document.getElementById(id); return node?JSON.parse(node.textContent||'null'):null; }
    catch(e){ return null; }
  }
  const methodsData=readJson('checkoutMethodsJson')||[];
  const i18n=readJson('checkoutWizardI18n')||{};
  const csrfToken=(document.querySelector('input[name="csrf"]')||{}).value||'';

  const form=document.getElementById('checkoutWizard');
  if(!form) return;

  const stepEls=document.querySelectorAll('[data-cw-step]');
  const panels={
    1:document.getElementById('cwPanel1'),
    2:document.getElementById('cwPanel2'),
    3:document.getElementById('cwPanel3'),
  };
  const summaryItems=document.getElementById('cwSummaryItems');
  const summarySubtotal=document.querySelector('[data-cw-subtotal]');
  const summaryDiscountRow=document.getElementById('cwDiscountRow');
  const summaryDiscount=document.querySelector('[data-cw-discount]');
  const summaryTotal=document.querySelector('[data-cw-total]');
  const promoInput=document.getElementById('cwPromoInput');
  const promoApply=document.getElementById('cwPromoApply');
  const promoMsg=document.getElementById('cwPromoMsg');
  const methodsWrap=document.getElementById('cwMethods');
  const confirmGrid=document.getElementById('cwConfirmGrid');
  const termsAgree=document.getElementById('cwTermsAgree');
  const submitBtn=document.getElementById('cwSubmitBtn');
  const backBtn=document.getElementById('cwBackBtn');
  const backBtn2=document.getElementById('cwBackBtn2');
  const nextBtn=document.getElementById('cwNextBtn');
  const step1Next=document.getElementById('cwStep1Next');
  const alertBox=document.getElementById('cwAlert');
  const emptyBox=document.getElementById('cwEmpty');

  let step=1;
  let selectedMethod='';
  let promoCode='';
  let promoDiscount=0;
  let cart=[];

  const rupiah=n=>'Rp '+new Intl.NumberFormat('id-ID').format(Number(n||0));

  function showAlert(message,type){
    if(!alertBox) return;
    alertBox.hidden=false;
    alertBox.className='cw-alert '+(type||'err');
    alertBox.textContent=message||'';
  }
  function hideAlert(){ if(alertBox){ alertBox.hidden=true; alertBox.textContent=''; } }

  function readCart(){
    try{
      const raw=JSON.parse(localStorage.getItem('ds_cart')||'[]');
      return Array.isArray(raw)?raw:[];
    }catch(e){ return []; }
  }

  function setFieldError(name,message){
    const field=document.querySelector('[data-cw-field="'+name+'"]');
    if(!field) return;
    const err=field.querySelector('.cw-field-error');
    if(message){
      field.classList.add('has-error');
      if(err) err.textContent=message;
    }else{
      field.classList.remove('has-error');
      if(err) err.textContent='';
    }
  }

  function cartPayload(){
    return cart.map(i=>({id:String(i.id),qty:Math.max(1,Number(i.qty)||1),variant_id:String(i.variant_id||'')}));
  }

  function lineTotal(i){ return (Number(i.price)||0)*(Number(i.qty)||0); }

  function renderSummary(){
    const subtotal=cart.reduce((s,i)=>s+lineTotal(i),0);
    const discount=Math.min(promoDiscount,subtotal);
    const total=Math.max(0,subtotal-discount);
    if(summaryItems){
      if(!cart.length){
        summaryItems.innerHTML='<p class="cw-sum-variant">'+(i18n.emptyCart||'Keranjang kosong')+'</p>';
      }else{
        summaryItems.innerHTML=cart.map(i=>{
          const thumb=String(i.thumb||'');
          const thumbHtml=(thumb&&thumb!=='DIGITAL')
            ? '<img src="'+String(thumb).replace(/"/g,'&quot;')+'" alt="">'
            : 'DIGITAL';
          return '<div class="cw-sum-item">'+
            '<div class="cw-sum-thumb">'+thumbHtml+'</div>'+
            '<div><div class="cw-sum-name">'+escapeHtml(i.name||'')+'</div>'+
            '<div class="cw-sum-variant">'+escapeHtml(i.variant_name||'')+' · ×'+(Number(i.qty)||1)+'</div></div>'+
            '<div class="cw-sum-price">'+rupiah(lineTotal(i))+'</div>'+
          '</div>';
        }).join('');
      }
    }
    if(summarySubtotal) summarySubtotal.textContent=rupiah(subtotal);
    const hasDiscount=discount>0;
    if(summaryDiscountRow) summaryDiscountRow.hidden=!hasDiscount;
    if(summaryDiscount) summaryDiscount.textContent=hasDiscount?('-'+rupiah(discount)):'';
    if(summaryTotal) summaryTotal.textContent=rupiah(total);
    return {subtotal,discount,total};
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function renderMethods(){
    if(!methodsWrap) return;
    const list=(methodsData||[]).filter(m=>m&&m.enabled);
    if(!list.length){
      methodsWrap.innerHTML='<div class="cw-alert err">Metode pembayaran belum tersedia. Hubungi admin.</div>';
      return;
    }
    if(!selectedMethod || !list.some(m=>m.id===selectedMethod)){
      selectedMethod=list[0].id;
    }
    methodsWrap.innerHTML=list.map(m=>{
      const icon=m.id==='demo'?'⚡':(m.id==='nowpayments'?'₿':'💳');
      return '<button type="button" class="cw-method'+(m.id===selectedMethod?' is-active':'')+'" data-cw-method="'+escapeHtml(m.id)+'" aria-pressed="'+(m.id===selectedMethod?'true':'false')+'">'+
        '<span class="cw-method-icon" aria-hidden="true">'+icon+'</span>'+
        '<span><span class="cw-method-name">'+escapeHtml(m.name||m.id)+'</span>'+
        '<span class="cw-method-desc">'+escapeHtml(m.description||'')+'</span></span>'+
        '<span class="cw-method-radio" aria-hidden="true"></span>'+
      '</button>';
    }).join('');
  }

  function renderConfirm(){
    if(!confirmGrid) return;
    const t=renderSummary();
    const methodLabel=(methodsData||[]).find(m=>m.id===selectedMethod)?.name||selectedMethod||'-';
    const rows=[
      ['Nama', document.getElementById('cwName')?.value||'-'],
      ['Email', document.getElementById('cwEmail')?.value||'-'],
      ['WhatsApp', document.getElementById('cwWa')?.value||'-'],
      ['Metode Bayar', methodLabel],
      ['Total', rupiah(t.total)],
    ];
    confirmGrid.innerHTML=rows.map(([k,v])=>'<div class="cw-confirm-row"><span>'+escapeHtml(k)+'</span><b>'+escapeHtml(v)+'</b></div>').join('');
    updateSubmitState();
  }

  function setStep(n){
    step=n;
    stepEls.forEach(el=>{
      const s=Number(el.dataset.cwStep);
      el.classList.toggle('is-active',s===n);
      el.classList.toggle('is-done',s<n);
    });
    Object.keys(panels).forEach(k=>{
      if(panels[k]) panels[k].hidden=Number(k)!==n;
    });
    if(step1Next) step1Next.hidden=n!==1;
    if(nextBtn) nextBtn.hidden=n!==2;
    if(backBtn) backBtn.hidden=n!==2;
    if(backBtn2) backBtn2.hidden=n!==3;
    if(submitBtn) submitBtn.hidden=n!==3;
    hideAlert();
    if(n===2) renderMethods();
    if(n===3) renderConfirm();
    const top=form.getBoundingClientRect().top+window.scrollY-72;
    window.scrollTo({top:Math.max(0,top),behavior:'smooth'});
  }

  function validateStep1(){
    let ok=true;
    const name=(document.getElementById('cwName')?.value||'').trim();
    const email=(document.getElementById('cwEmail')?.value||'').trim();
    const wa=(document.getElementById('cwWa')?.value||'').trim();
    setFieldError('name','');
    setFieldError('email','');
    setFieldError('wa','');
    if(!name){ setFieldError('name',i18n.errName||'Nama wajib diisi.'); ok=false; }
    if(email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ setFieldError('email',i18n.errEmail||'Email tidak valid.'); ok=false; }
    if(wa && !/^[0-9+\-\s()]{8,20}$/.test(wa)){ setFieldError('wa',i18n.errWa||'Nomor WhatsApp tidak valid.'); ok=false; }
    if(!email && !wa){
      setFieldError('email',i18n.errContact||'Isi email atau WhatsApp (minimal salah satu).');
      ok=false;
    }
    return ok;
  }

  async function applyPromo(){
    if(!promoApply) return false;
    const code=(promoInput?.value||'').trim().toUpperCase();
    promoCode=code;
    if(!code){
      promoDiscount=0;
      if(promoMsg){ promoMsg.textContent=''; promoMsg.className='cw-promo-msg'; }
      renderSummary();
      return true;
    }
    promoApply.disabled=true;
    if(promoMsg){ promoMsg.textContent='Memeriksa promo…'; promoMsg.className='cw-promo-msg'; }
    try{
      const fd=new FormData();
      fd.set('cart',JSON.stringify(cartPayload()));
      fd.set('promo_code',code);
      const res=await fetch('/api/promo_check.php',{method:'POST',body:fd});
      const data=await res.json();
      if(data.ok && data.discount!=null){
        promoDiscount=Number(data.discount)||0;
        if(promoMsg){ promoMsg.textContent=data.message||('Promo '+code+' aktif'); promoMsg.className='cw-promo-msg ok'; }
        renderSummary();
        return true;
      }
      promoDiscount=0;
      if(promoMsg){ promoMsg.textContent=data.message||'Promo tidak valid'; promoMsg.className='cw-promo-msg err'; }
      renderSummary();
      return false;
    }catch(e){
      promoDiscount=0;
      if(promoMsg){ promoMsg.textContent='Gagal memeriksa promo'; promoMsg.className='cw-promo-msg err'; }
      renderSummary();
      return false;
    }finally{
      promoApply.disabled=false;
    }
  }

  function updateSubmitState(){
    if(!submitBtn) return;
    const ready=!!(termsAgree && termsAgree.checked && cart.length && selectedMethod);
    submitBtn.disabled=!ready;
    submitBtn.setAttribute('aria-disabled',ready?'false':'true');
  }

  async function submitOrder(){
    hideAlert();
    if(!cart.length){ showAlert(i18n.emptyCart||'Keranjang kosong'); return; }
    if(!validateStep1()){ setStep(1); return; }
    if(!selectedMethod){ showAlert(i18n.errMethod||'Pilih metode pembayaran.'); setStep(2); return; }
    if(promoInput && promoInput.value.trim()){
      const valid=await applyPromo();
      if(!valid && promoInput.value.trim()){ showAlert(i18n.errPromo||'Kode promo tidak valid.'); setStep(1); return; }
    }
    if(!termsAgree || !termsAgree.checked){ showAlert(i18n.errTerms||'Anda harus menyetujui syarat & ketentuan produk.'); return; }
    submitBtn.disabled=true;
    const t=renderSummary();
    try{
      const fd=new FormData();
      fd.set('cart',JSON.stringify(cartPayload()));
      fd.set('buyer_name',(document.getElementById('cwName')?.value||'').trim());
      fd.set('buyer_email',(document.getElementById('cwEmail')?.value||'').trim());
      fd.set('buyer_whatsapp',(document.getElementById('cwWa')?.value||'').trim());
      fd.set('promo_code',promoCode||'');
      fd.set('terms_accepted','1');
      fd.set('payment_method',selectedMethod);
      if(csrfToken) fd.set('csrf',csrfToken);
      const res=await fetch('/api/checkout.php',{method:'POST',body:fd});
      const data=await res.json();
      if(data.ok){
        localStorage.removeItem('ds_cart');
        location.href=data.payment_url||('/checkout.php?invoice='+encodeURIComponent(data.invoice||''));
        return;
      }
      showAlert(data.message||'Checkout gagal');
      submitBtn.disabled=false;
      updateSubmitState();
    }catch(e){
      showAlert(i18n.errNetwork||'Gagal terhubung ke server. Coba lagi.');
      submitBtn.disabled=false;
      updateSubmitState();
    }
  }

  form.addEventListener('click',async e=>{
    const methodBtn=e.target.closest('[data-cw-method]');
    if(methodBtn){
      selectedMethod=methodBtn.dataset.cwMethod||'';
      renderMethods();
      return;
    }
    if(e.target.closest('#cwPromoApply')){ await applyPromo(); return; }
  });

  if(step1Next) step1Next.addEventListener('click',()=>{
    if(!validateStep1()) return;
    setStep(2);
  });
  if(nextBtn) nextBtn.addEventListener('click',()=>{
    if(!selectedMethod){ showAlert(i18n.errMethod||'Pilih metode pembayaran.'); return; }
    setStep(3);
  });
  if(backBtn) backBtn.addEventListener('click',()=>{
    if(step===3) setStep(2);
    else if(step===2) setStep(1);
  });
  if(submitBtn) submitBtn.addEventListener('click',e=>{ e.preventDefault(); submitOrder(); });
  if(termsAgree) termsAgree.addEventListener('change',updateSubmitState);
  if(promoApply) promoApply.addEventListener('click',async e=>{ e.preventDefault(); await applyPromo(); });
  form.addEventListener('submit',e=>{ e.preventDefault(); submitOrder(); });

  // Init
  cart=readCart();
  if(emptyBox && !cart.length){
    emptyBox.hidden=false;
    form.hidden=true;
    if(summaryItems) summaryItems.innerHTML='<p class="cw-sum-variant">'+(i18n.emptyCart||'Keranjang kosong')+'</p>';
  }else{
    renderSummary();
    setStep(1);
    updateSubmitState();
  }
})();
