(function(){
  function copyText(text, btn){
    text = String(text || '');
    if(!text) return;
    const done = ()=>{
      if(!btn) return;
      const old = btn.textContent;
      btn.textContent = '✓';
      btn.classList.add('copied');
      setTimeout(()=>{btn.textContent = old; btn.classList.remove('copied');}, 1200);
    };
    if(navigator.clipboard && window.isSecureContext){
      navigator.clipboard.writeText(text).then(done).catch(()=>fallback());
    }else fallback();
    function fallback(){
      const ta=document.createElement('textarea');
      ta.value=text;
      ta.setAttribute('readonly','');
      ta.style.position='fixed';
      ta.style.left='-9999px';
      document.body.appendChild(ta);
      ta.select();
      try{document.execCommand('copy'); done();}catch(e){}
      ta.remove();
    }
  }
  document.addEventListener('click',function(e){
    const b=e.target.closest('[data-copy-text]');
    if(!b) return;
    e.preventDefault();
    copyText(b.getAttribute('data-copy-text'), b);
  });
})();
