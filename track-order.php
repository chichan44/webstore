<?php
require_once __DIR__.'/includes/helpers.php';
$site=setting('site_name','DigiSoft Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
$invoice=trim((string)($_POST['invoice'] ?? $_GET['invoice'] ?? '')); $order=null; $items=[]; $accessOk=false; $accessError='';
$invoiceReviewed=false; $firstProduct=null; $hasManual=false;
ensure_order_security_columns(); ensure_product_extra_columns();

if($invoice){
    if(order_access_blocked($invoice,'lookup')){
        $accessError='Terlalu banyak percobaan pencarian. Coba lagi beberapa menit lagi.';
    } else {
        $st=$pdo->prepare('SELECT * FROM orders WHERE invoice=? ORDER BY id DESC LIMIT 1');$st->execute([$invoice]);$order=$st->fetch();
        if(!$order) record_order_access_fail($invoice,'lookup',12,900); else clear_order_access_fail($invoice,'lookup');
    }
    if($order && $order['status']==='pending' && !empty($order['payment_expires_at'])){
        $expiryTs=strtotime((string)$order['payment_expires_at']);
        $providerForExpiry=strtolower(trim((string)($order['payment_provider']??'')));
        $failAfter=$expiryTs===false?false:$expiryTs+($providerForExpiry==='casaku'?180:0);
        if($failAfter!==false && $failAfter < time()){
            $pdo->beginTransaction();
            try{$pdo->prepare('UPDATE orders SET status="failed" WHERE id=? AND status="pending"')->execute([$order['id']]);release_order_stock_reservations((int)$order['id']);$pdo->commit();$order['status']='failed';}
            catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}
        }
    }
    if($order){
        if($_SERVER['REQUEST_METHOD']==='POST') csrf_check();
        $postedPassword=trim($_POST['access_password'] ?? '');
        $sessionPassword=session_order_access($order['invoice']);
        $password=$postedPassword !== '' ? $postedPassword : $sessionPassword;
        if($_SERVER['REQUEST_METHOD']==='POST' && order_access_blocked($order['invoice'],'password')){
            $accessOk=false; $accessError='Terlalu banyak percobaan kata sandi. Coba lagi beberapa menit lagi.';
        } else {
            $accessOk=verify_order_access($order,$password);
            if($_SERVER['REQUEST_METHOD']==='POST' && !$accessOk){ record_order_access_fail($order['invoice'],'password',5,900); $accessError='Kata sandi produk salah.'; }
            if($accessOk){
                clear_order_access_fail($order['invoice'],'password');
                if($password!=='') store_order_access_session($order['invoice'],$password);
            }
        }
        if($accessOk && in_array($order['status'],['paid','delivered'],true)){
            $st=$pdo->prepare('SELECT oi.id AS item_id, oi.product_id, oi.product_name, oi.qty, oi.variant_name,
                oi.warranty_label, oi.warranty_days, oi.warranty_started_at, oi.warranty_expires_at,
                p.slug, COALESCE(p.delivery_type,"instant") AS delivery_type
                FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id
                WHERE oi.order_id=? ORDER BY oi.id');
            $st->execute([$order['id']]);
            $itemRows=$st->fetchAll();

            $sst=$pdo->prepare('SELECT order_item_id, content FROM delivered_stocks WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id=?) ORDER BY id');
            $sst->execute([$order['id']]);
            $stockMap=[];
            foreach($sst->fetchAll() as $s){ $stockMap[(int)$s['order_item_id']][]=$s['content']; }

            foreach($itemRows as $row){
                $row['contents']=$stockMap[(int)$row['item_id']] ?? [];
                $items[]=$row;
                if($row['delivery_type']==='manual') $hasManual=true;
                if($firstProduct===null && !empty($row['slug'])) $firstProduct=$row;
            }

            $revSt=$pdo->prepare("SELECT COUNT(*) FROM product_reviews WHERE order_id=? AND status IN ('pending','approved')");
            $revSt->execute([(int)$order['id']]);
            $invoiceReviewed=(int)$revSt->fetchColumn()>0;
        }
    }
}
?><!doctype html>
<html lang="<?=e(lang_resolve())?>" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php
render_seo_meta([
  'site'=>$site,
  'title'=>'Lacak Pesanan - '.$site,
  'description'=>'Lacak status pesanan produk digital di '.$site.' menggunakan nomor invoice dan kata sandi produk.',
  'path'=>'/track-order.php',
  'robots'=>$invoice !== '' ? 'noindex, nofollow' : 'noindex, follow',
  'json_ld'=>[
    '@context'=>'https://schema.org',
    '@type'=>'WebPage',
    'name'=>'Lacak Pesanan '.$site,
    'url'=>seo_url('/track-order.php'),
    'description'=>'Halaman untuk mengecek status pesanan produk digital.',
    'isPartOf'=>['@type'=>'WebSite','name'=>$site,'url'=>seo_url('/')]
  ]
]);
?>
<?php render_storefront_styles('track'); ?>
</head>
<body>

<?php
if(!function_exists('ds_icon')){function ds_icon($name){$n=mb_strtolower((string)$name); if(str_contains($n,'akun')||str_contains($n,'premium')) return '♛'; if(str_contains($n,'book')||str_contains($n,'ebook')) return '▭'; if(str_contains($n,'template')) return '▤'; if(str_contains($n,'tool')) return '⌘'; if(str_contains($n,'voucher')) return '⌁'; return '▦';}}
?>
<?php render_storefront_header($site,$desc,$cats,['q'=>$_GET['q']??'']); ?>

<main class="ds-shell page-wrap">
  <section class="page-hero"><h1>📦 <?=t('Lacak Pesanan')?></h1><p><?=t('Masukkan nomor invoice untuk melihat status pesanan dan produk digital.')?></p></section>
  <section class="track-card secure-track-card">
    <form class="track-form" method="get"><input name="invoice" value="<?=e($invoice)?>" placeholder="Contoh: INV-260519-ABCD1234"><button><?=t('Cari')?></button></form>
    <?php if($invoice && !$order): ?><div class="alert err"><?=t('Pesanan tidak ditemukan.')?></div><?php endif; ?>
    <?php if($order): ?>
      <div class="front-order-result track-result">
        <div>
          <small><?=t('Invoice')?></small>
          <b><?=e($order['invoice'])?></b>
        </div>
        <div>
          <small><?=t('Status')?></small>
          <b class="status-pill"><?=e(strtoupper(t($order['status'])))?></b>
        </div>
        <div>
          <small><?=t('Total')?></small>
          <b class="front-price"><?=rupiah($order['payment_total'] ?: $order['total'])?></b>
        </div>
        <div>
          <small><?=t('Tanggal')?></small>
          <b><?=e($order['created_at'])?></b>
        </div>
      </div>
      <?php $reviewOkMsg=(string)(flash('review_ok') ?? ''); $reviewErrMsg=(string)(flash('review_error') ?? ''); ?>
      <?php if($reviewOkMsg!==''): ?><div class="alert ok" role="status" style="margin-top:12px"><span>✓</span><div><?=e($reviewOkMsg)?></div></div><?php endif; ?>
      <?php if($reviewErrMsg!==''): ?><div class="alert err" role="alert" style="margin-top:12px"><span>!</span><div><?=e($reviewErrMsg)?></div></div><?php endif; ?>
      <?php if($order['status']==='pending'): ?>
        <?php $trackProvider=strtolower((string)($order['payment_provider']??'')); $trackGateway=$trackProvider==='casaku'?'Casaku':($trackProvider==='tokopay'?'Tokopay':'gateway pembayaran'); ?>
        <p class="muted track-info"><?=t('Menunggu pembayaran')?> — <?=e($trackGateway)?>. <?=t('Jika sudah membayar, tunggu beberapa detik lalu refresh halaman.')?></p>
        <?php if(!empty($order['payment_url'])): ?><a class="btn primary" href="<?=e($order['payment_url'])?>" target="_blank" rel="noopener"><?=t('Buka Pembayaran')?></a><?php endif; ?>
      <?php elseif($order['status']==='failed'): ?>
        <div class="alert err"><?=t('Pembayaran expired / gagal')?>. <?=t('Silakan buat pesanan baru.')?></div>
      <?php else: ?>
        <?php if(!$accessOk): ?>
          <div class="track-unlock-card">
            <div class="track-unlock-icon">🔒</div>
            <h3 class="track-unlock-title"><?=t('Buka Produk Digital')?></h3>
            <p class="track-unlock-desc"><?=t('Masukkan kata sandi produk untuk melihat detail dan isi pesanan Anda.')?></p>
            <form class="track-unlock-form" method="post">
              <?=csrf_field()?>
              <input type="hidden" name="invoice" value="<?=e($order['invoice'])?>">
              <input name="access_password" type="password" autocomplete="off" placeholder="<?=t('Kata sandi produk')?>" required>
              <button type="submit"><?=t('Buka Produk')?> →</button>
            </form>
            <?php if($accessError): ?>
              <small class="track-unlock-error"><?=e($accessError)?></small>
            <?php else: ?>
              <small class="track-unlock-hint">💡 <?=t('Kata sandi muncul saat checkout selesai.')?></small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="track-delivered-stack">
            <section class="track-product-panel">
              <div class="track-panel-head">
                <div><span class="track-panel-icon">▣</span><h3><?=t('Produk Digital Anda')?></h3><p><?=t('Produk berhasil dikirim. Salin detail akun di bawah ini.')?></p></div>
                <span class="track-delivered-badge">✓ <?=t('Terkirim')?></span>
              </div>
              <?php if($hasManual): ?>
                <div class="alert ok"><?=t('Pembayaran sudah lunas. Produk manual akan segera dikirim oleh admin. Mohon tunggu dan sertakan invoice jika menghubungi admin.')?></div>
              <?php endif; ?>
              <?php if(!$items): ?>
                <p class="muted"><?=t('Pembayaran sudah diterima, tetapi stok belum berhasil diambil. Hubungi admin dan sertakan invoice.')?></p>
              <?php else: ?>
                <?php foreach($items as $item):
                  $isManual=$item['delivery_type']==='manual';
                  $wDays=max(0,(int)$item['warranty_days']);
                  $wExpires=trim((string)($item['warranty_expires_at'] ?? ''));
                  $wTs=$wExpires!=='' ? (int)strtotime($wExpires) : 0;
                  $wActive=$wDays>0 && $wTs>0 && $wTs>=time();
                ?>
                  <?php if($isManual): ?>
                    <div class="delivered-stock manual-delivery-note">
                      <div class="delivered-stock-head">
                        <div class="track-item-labels">
                          <b><?=e($item['product_name'])?><?=((int)$item['qty']>1?' × '.intval($item['qty']):'')?></b>
                          <?php if(!empty($item['variant_name'])): ?><small class="track-variant-label"><?=e($item['variant_name'])?></small><?php endif; ?>
                        </div>
                      </div>
                      <p><?=t('Qty')?>: <?=intval($item['qty'])?> · <?=t('Pengiriman manual oleh admin.')?></p>
                    </div>
                  <?php elseif($item['contents']): ?>
                    <?php foreach($item['contents'] as $content): ?>
                    <div class="delivered-stock copyable-stock">
                      <div class="delivered-stock-head">
                        <div class="track-item-labels">
                          <b><?=e($item['product_name'])?></b>
                          <?php if(!empty($item['variant_name'])): ?><small class="track-variant-label"><?=e($item['variant_name'])?></small><?php endif; ?>
                        </div>
                        <button class="copy-stock-btn" type="button" data-copy-text="<?=e($content)?>" aria-label="<?=t('Salin produk')?> <?=e($item['product_name'])?>">📋 <span><?=t('Copy')?></span></button>
                      </div>
                      <pre><?=e($content)?></pre>
                    </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="delivered-stock manual-delivery-note">
                      <div class="delivered-stock-head">
                        <div class="track-item-labels"><b><?=e($item['product_name'])?></b></div>
                      </div>
                      <p><?=t('Stok belum tersedia. Hubungi admin dan sertakan invoice.')?></p>
                    </div>
                  <?php endif; ?>
                  <?php if($wDays>0): ?>
                  <div class="track-warranty-chip<?=($wTs>0 && !$wActive)?' is-expired':''?>"<?=$wTs>0?' data-warranty-expires="'.$wTs.'"':''?>>
                    <span class="track-warranty-icon">🛡</span>
                    <div class="track-warranty-meta">
                      <b><?=t('Garansi')?> <?=e(trim((string)($item['warranty_label'] ?? '')) ?: ($wDays.' Hari'))?></b>
                      <small class="track-warranty-countdown"><?php
                        if($wTs>0 && $wActive) echo e(t('Menghitung…'));
                        elseif($wTs>0) echo e(t('Garansi berakhir'));
                        else echo e($wDays.' '.t('hari'));
                      ?></small>
                    </div>
                    <?php if($wActive): ?>
                    <button type="button" class="track-claim-btn" data-claim-warranty><?=t('Klaim Garansi')?></button>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>
            <section class="track-review-panel">
              <?php if($invoiceReviewed): ?>
                <div class="track-review-done">
                  <span>✓</span>
                  <div><b><?=t('Ulasan sudah dikirim')?></b><small><?=t('Terima kasih — ulasan menunggu moderasi admin.')?></small></div>
                </div>
              <?php elseif($firstProduct): ?>
                <div class="track-review-head">
                  <span>⭐</span>
                  <h3><?=t('Beri Ulasan Pesanan')?></h3>
                </div>
                <p class="track-review-desc"><?=t('Bagaimana pengalaman belanja Anda? Ulasan Anda membantu pembeli lain.')?></p>
                <form class="track-review-form" method="post" action="/<?=rawurlencode((string)$firstProduct['slug'])?>">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="submit_review">
                  <input type="hidden" name="return_track" value="1">
                  <input type="hidden" name="track_invoice" value="<?=e($order['invoice'])?>">
                  <select name="review_rating" required aria-label="<?=t('Rating Ulasan')?>">
                    <option value=""><?=t('Beri rating')?></option>
                    <option value="5">5 ★</option><option value="4">4 ★</option><option value="3">3 ★</option><option value="2">2 ★</option><option value="1">1 ★</option>
                  </select>
                  <textarea name="review_comment" required minlength="5" maxlength="1000" rows="4" placeholder="<?=t('Tulis ulasan Anda...')?>"></textarea>
                  <button type="submit"><?=t('Kirim Ulasan')?></button>
                </form>
              <?php endif; ?>
            </section>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats,['q'=>$_GET['q']??'']); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b><?=t('Keranjang Belanja')?> (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span><?=t('Subtotal')?></span><b data-cart-total>Rp 0</b></p><a href="/checkout.php" class="btn primary"><?=t('Lanjut Checkout')?></a></div></aside></div>
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261021-track-v1"></script>
<?php if($order && strtolower(trim((string)($order['payment_provider']??'')))==='casaku' && in_array($order['status'],['pending','failed','paid'],true) && !empty($order['payment_reference'])): ?>
<script>
(function(){
  const invoice=<?=json_encode($order['invoice'])?>;
  const initial=<?=json_encode($order['status'])?>;
  let busy=false;
  async function syncCasaku(){
    if(busy) return; busy=true;
    try{
      const r=await fetch('/api/payment_status.php?invoice='+encodeURIComponent(invoice),{cache:'no-store',credentials:'same-origin'});
      const d=await r.json();
      if(d && d.ok && d.status && d.status!==initial) location.reload();
    }catch(e){}finally{busy=false;}
  }
  syncCasaku();
  setInterval(syncCasaku,5000);
})();
</script>
<?php endif; ?>

<script>
document.addEventListener('click', async function(e){
  const btn = e.target.closest('.copy-stock-btn');
  if(!btn) return;
  const text = btn.getAttribute('data-copy-text') || '';
  const original = btn.innerHTML;
  try{
    if(navigator.clipboard && window.isSecureContext){
      await navigator.clipboard.writeText(text);
    }else{
      const ta=document.createElement('textarea');
      ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.left='-9999px';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    btn.classList.add('copied'); btn.innerHTML='✓ <span>Tersalin</span>';
    setTimeout(()=>{btn.classList.remove('copied'); btn.innerHTML=original;},1400);
  }catch(err){
    btn.innerHTML='! <span>Gagal</span>';
    setTimeout(()=>{btn.innerHTML=original;},1400);
  }
});
(function(){
  function fmtWarranty(ms){
    if(ms<=0) return null;
    const s=Math.floor(ms/1000);
    const d=Math.floor(s/86400), h=Math.floor((s%86400)/3600), m=Math.floor((s%3600)/60);
    if(d>0) return d+' hari '+h+' jam lagi';
    if(h>0) return h+' jam '+m+' menit lagi';
    return m+' menit lagi';
  }
  const chips=document.querySelectorAll('[data-warranty-expires]');
  if(!chips.length) return;
  function tick(){
    const now=Math.floor(Date.now()/1000);
    chips.forEach(function(chip){
      const exp=parseInt(chip.getAttribute('data-warranty-expires'),10)||0;
      if(!exp) return;
      const label=chip.querySelector('.track-warranty-countdown');
      const claim=chip.querySelector('.track-claim-btn');
      const left=(exp-now)*1000;
      if(left<=0){
        chip.classList.add('is-expired');
        if(label) label.textContent='Garansi berakhir';
        if(claim) claim.remove();
      }else{
        const txt=fmtWarranty(left);
        if(label && txt) label.textContent=txt;
      }
    });
  }
  tick();
  setInterval(tick,1000);
})();
document.addEventListener('click', function(e){
  const btn=e.target.closest('[data-claim-warranty]');
  if(!btn) return;
  e.preventDefault();
  let tip=btn.closest('.track-warranty-chip');
  if(tip && !tip.querySelector('.track-claim-tip')){
    const note=document.createElement('small');
    note.className='track-claim-tip';
    note.textContent='Fitur klaim garansi sedang dalam pengembangan. Hubungi admin untuk klaim manual.';
    tip.appendChild(note);
    setTimeout(()=>{note.remove();},5000);
  }
});
</script>
</body></html>
