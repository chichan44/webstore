<?php
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/casaku.php';
require_once __DIR__.'/includes/payments.php';
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
$invoice=$_GET['invoice']??''; $order=null; $accessPassword='';
ensure_order_security_columns();
ensure_product_extra_columns();
$orderProvider='';
$gatewayLabel='QRIS';
$expiredMinutes=tokopay_expired_minutes();
if($invoice){
    $st=$pdo->prepare('SELECT * FROM orders WHERE invoice=?');$st->execute([$invoice]);$order=$st->fetch();
    if($order){
        $orderProvider=strtolower(trim((string)($order['payment_provider']??'')));
        if($orderProvider==='')$orderProvider='qris_static';
        $gatewayLabel=$orderProvider==='demo'?'Demo':($orderProvider==='casaku'?'Casaku':($orderProvider==='tokopay'?'Tokopay':($orderProvider==='nowpayments'?'Crypto':'QRIS Manual')));
        $expiredMinutes=$orderProvider==='casaku'?casaku_expired_minutes():($orderProvider==='nowpayments'?nowpayments_expired_minutes():tokopay_expired_minutes());
    }
    if($order && $order['status']==='pending' && !empty($order['payment_expires_at'])){
        $expireTs=strtotime((string)$order['payment_expires_at']);
        $failAfter=$expireTs===false?false:$expireTs+($orderProvider==='casaku'?180:0);
        if($failAfter!==false && $failAfter<time()){
            $pdo->beginTransaction();
            try{
                $pdo->prepare('UPDATE orders SET status="failed" WHERE id=? AND status="pending"')->execute([$order['id']]);
                release_order_stock_reservations((int)$order['id']);
                $pdo->commit();
                $order['status']='failed';
            }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); }
        }
    }
    if($order) $accessPassword=session_order_access($order['invoice']);
}
$isPaymentExpired = $order && $order['status'] !== 'pending';
if($order && $order['status']==='pending' && !empty($order['payment_expires_at'])) $isPaymentExpired = strtotime((string)$order['payment_expires_at']) < time();
$maintenanceActive = !$order && maintenance_mode_enabled();
$maintenanceMessage = maintenance_mode_message();
?><!doctype html>
<html lang="<?=e(lang_resolve())?>" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php
render_seo_meta([
  'site'=>$site,
  'title'=>t('Checkout Produk Digital - {site}',['site'=>$site]),
  'description'=>t('Checkout pesanan produk digital di {site} dengan pembayaran QRIS otomatis dan proses pengiriman yang aman.',['site'=>$site]),
  'path'=>'/checkout.php',
  'robots'=>$order ? 'noindex, nofollow' : 'noindex, follow',
  'json_ld'=>[
    '@context'=>'https://schema.org',
    '@type'=>'WebPage',
    'name'=>'Checkout '.$site,
    'url'=>seo_url('/checkout.php'),
    'isPartOf'=>['@type'=>'WebSite','name'=>$site,'url'=>seo_url('/')]
  ]
]);
?>
<?php render_storefront_styles('index'); ?>
<link rel="stylesheet" href="/assets/css/checkout-v24.css?v=20260830-casaku-v1">
<link rel="stylesheet" href="/assets/css/checkout-wizard-v1.css?v=20261011-header-v7">
</head>
<body>

<?php
if(!function_exists('ds_icon')){function ds_icon($name){$n=mb_strtolower((string)$name); if(str_contains($n,'akun')||str_contains($n,'premium')) return '♛'; if(str_contains($n,'book')||str_contains($n,'ebook')) return '▭'; if(str_contains($n,'template')) return '▤'; if(str_contains($n,'tool')) return '⌘'; if(str_contains($n,'voucher')) return '⌁'; return '▦';}}
?>
<?php render_storefront_header($site,$desc,$cats,['q'=>$_GET['q']??'']); ?>

<main class="ds-shell page-wrap">
  <section class="page-hero checkout-page-hero">
    <div class="checkout-title-row">
      <span class="checkout-title-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 8.5h11l1 11h-13l1-11Z"></path><path d="M9 9V6.8a3 3 0 0 1 6 0V9"></path></svg>
      </span>
      <h1>Checkout</h1>
    </div>
  </section>
  <?php if($order): ?>
  <section class="checkout-pay-panel <?=($isPaymentExpired?'is-payment-expired':'')?>" id="checkoutPayPanel">
    <div class="checkout-pay-main">
      <span class="checkout-step"><?=t('Invoice dibuat')?></span>
      <h2><?=e($order['invoice'])?></h2>
      <?php if($orderProvider==='demo'): ?>
      <div class="cw-demo-success">
        <span class="cw-demo-success-badge">✓ <?=t('Pembayaran Demo lunas otomatis')?></span>
        <p><?=t('Pesanan demo langsung diproses. Simpan invoice dan kata sandi untuk membuka produk di lacak pesanan.')?></p>
      </div>
      <?php else: ?>
      <p class="checkout-desc"><?=t('Selesaikan pembayaran QRIS melalui <b>{gateway}</b> dalam <b>{minutes} menit</b>. Produk baru diproses setelah pembayaran terverifikasi lunas oleh server.',['gateway'=>$gatewayLabel,'minutes'=>intval($expiredMinutes)])?></p>
      <?php endif; ?>
      <div class="checkout-total-card">
        <small><?=t('Total Pembayaran')?></small>
        <strong><?=rupiah($order['payment_total'] ?: $order['total'])?></strong>
        <span class="status-pill" id="payStatus"><?=e($order['status'])?></span>
      </div>
      <div class="checkout-security-note">
        <div class="security-note-head"><b>🔐 <?=t('Data Akses Produk')?></b><small><?=t('Simpan invoice dan kata sandi ini untuk membuka produk di halaman lacak pesanan.')?></small></div>
        <div class="access-copy-grid">
          <div class="access-copy-item">
            <span><?=t('Nomor Invoice')?></span>
            <code><?=e($order['invoice'])?></code>
            <button type="button" class="copy-mini" data-copy-text="<?=e($order['invoice'])?>" title="Salin invoice">⧉</button>
          </div>
          <?php if($accessPassword): ?>
          <div class="access-copy-item">
            <span><?=t('Kata Sandi Produk')?></span>
            <code><?=e($accessPassword)?></code>
            <button type="button" class="copy-mini" data-copy-text="<?=e($accessPassword)?>" title="Salin kata sandi">⧉</button>
          </div>
          <?php else: ?>
          <small><?=t('Kata sandi hanya ditampilkan pada browser yang membuat pesanan. Jika hilang, gunakan data pembelian untuk verifikasi manual admin.')?></small>
          <?php endif; ?>
        </div>
      </div>
      <div class="checkout-pay-actions">
        <?php if($orderProvider==='demo' && in_array($order['status'],['paid','delivered'],true)): ?>
          <a class="btn primary pay-now" href="/track-order.php?invoice=<?=e($order['invoice'])?>"><?=t('Buka Produk & Lacak')?></a>
        <?php elseif(!empty($order['payment_url']) && $order['status']==='pending' && !$isPaymentExpired): ?>
          <a class="btn primary pay-now" id="gatewayPayButton" href="<?=e($order['payment_url'])?>" target="_blank" rel="noopener"><?=t('Bayar Sekarang via {gateway}',['gateway'=>$gatewayLabel])?></a>
        <?php elseif($order['status']==='failed' || $isPaymentExpired): ?>
          <a class="btn primary pay-now" href="/checkout.php"><?=t('Buat Pesanan Baru')?></a>
        <?php endif; ?>
        <a class="btn soft" href="/track-order.php?invoice=<?=e($order['invoice'])?>"><?=t('Lacak Pesanan')?></a>
      </div>
    </div>
    <aside class="checkout-pay-side">
      <?php if($orderProvider!=='demo'): ?>
      <div class="pay-timer-box">
        <small><?=t('Batas waktu QRIS')?></small>
        <strong id="checkoutTimer">05:00</strong>
        <span><?=t('Expired otomatis bila belum dibayar.')?></span>
      </div>
      <?php endif; ?>
      <?php
        $qrisValue = trim((string)($order['qris_payload'] ?: setting('qris_payload','')));
        $qrisImage = '';
        if ($qrisValue !== '' && preg_match('~^https?://~i', $qrisValue)) {
            $qrisImage = $qrisValue;
        }
      ?>
      <div class="gateway-card qris-gateway-card" id="gatewayQrisCard">
        <b><?=e($gatewayLabel)?> QRIS</b>
        <?php if($isPaymentExpired): ?>
          <div class="qris-box qris-expired-box"><div class="qris-inner">QRIS<br>EXPIRED</div></div>
          <p><?=t('Waktu pembayaran sudah habis. QRIS dan tombol bayar dinonaktifkan. Silakan buat pesanan baru.')?></p>
        <?php elseif($qrisImage): ?>
          <div class="qris-live-box"><img src="<?=e($qrisImage)?>" alt="QRIS <?=e($gatewayLabel)?>" loading="lazy"></div>
          <p><?=t('Scan QRIS resmi {gateway} untuk menyelesaikan pembayaran. Status akan diperiksa otomatis oleh server.',['gateway'=>$gatewayLabel])?></p>
        <?php elseif($orderProvider==='casaku' && $qrisValue): ?>
          <div class="qris-live-box"><div id="casakuQrCanvas" aria-label="QRIS Casaku"></div></div>
          <p><?=t('Scan QRIS Casaku di atas. Produk hanya akan tersedia setelah transaksi terkonfirmasi <b>paid</b>.')?></p>
          <button type="button" class="copy-qris" data-copy-text="<?=e($qrisValue)?>">⧉ <?=t('Salin Kode QRIS')?></button>
        <?php else: ?>
          <div class="qris-box"><div class="qris-inner">QRIS<br><?=e(setting('qris_merchant_name','DIGITAL STORE'))?></div></div>
          <?php if($qrisValue): ?>
            <p><?=t('Gateway mengirim payload QRIS tanpa gambar. Gunakan tombol bayar bila tersedia atau salin kode QRIS.')?></p>
            <button type="button" class="copy-qris" data-copy-text="<?=e($qrisValue)?>">⧉ <?=t('Salin Kode QRIS')?></button>
          <?php else: ?>
            <p><?=t('QRIS belum diterima dari gateway. Cek kembali pengaturan payment gateway.')?></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </aside>
    <script>
      window.dsPaymentExpiresAt = <?=json_encode($order['payment_expires_at'] ?? '')?>;
      window.dsPaymentExpiresIn = <?=intval(!empty($order['payment_expires_at']) ? max(0, strtotime($order['payment_expires_at']) - time()) : ($expiredMinutes * 60))?>;
      window.dsPaymentInvoice = <?=json_encode($order['invoice'])?>;
      window.dsPaymentInitialStatus = <?=json_encode($order['status'])?>;
      window.dsPaymentProvider = <?=json_encode($orderProvider)?>;
      window.dsCasakuQrisPayload = <?=json_encode(($orderProvider==='casaku' && !$isPaymentExpired)?$qrisValue:'')?>;
      setInterval(()=>checkPayment('<?=e($order['invoice'])?>'),8000);
    </script>
  </section>
  <?php else: ?>
  <?php if($maintenanceActive): ?>
  <section class="checkout-maintenance-card" role="status">
    <div class="checkout-maintenance-icon">🛠</div>
    <div><span><?=t('Maintenance Mode')?></span><h2><?=t('Checkout sementara dinonaktifkan')?></h2><p><?=e($maintenanceMessage)?></p><div class="checkout-maintenance-actions"><a class="btn primary" href="/#produk"><?=t('Kembali ke Produk')?></a><a class="btn soft" href="/track-order.php"><?=t('Lacak Pesanan')?></a></div></div>
  </section>
  <?php else: ?>
  <div class="cw-wrap">
    <section class="cw-empty" id="cwEmpty" hidden>
      <div class="cw-empty-icon" aria-hidden="true">🛒</div>
      <h2><?=t('Keranjang kosong')?></h2>
      <p><?=t('Belum ada produk di keranjang. Pilih produk dulu untuk melanjutkan checkout.')?></p>
      <a class="btn primary" href="/#produk"><?=t('Kembali ke Produk')?></a>
    </section>

    <?php $checkoutMethods = checkout_available_payment_methods(); ?>
    <form id="checkoutWizard" class="cw-layout" method="post" novalidate>
      <?=csrf_field()?>
      <div class="cw-progress-strip">
        <ol class="cw-steps" aria-label="Langkah checkout">
          <li class="cw-step is-active" data-cw-step="1"><span class="cw-step-dot">1</span><span class="cw-step-label"><?=t('Data')?></span></li>
          <li class="cw-step-line" aria-hidden="true"></li>
          <li class="cw-step" data-cw-step="2"><span class="cw-step-dot">2</span><span class="cw-step-label"><?=t('Bayar')?></span></li>
          <li class="cw-step-line" aria-hidden="true"></li>
          <li class="cw-step" data-cw-step="3"><span class="cw-step-dot">3</span><span class="cw-step-label"><?=t('Selesai')?></span></li>
        </ol>
      </div>

      <div class="cw-card cw-progress-card">
        <div id="cwAlert" class="cw-alert err" hidden role="alert"></div>

        <div class="cw-panel" id="cwPanel1" data-cw-panel="1">
          <div class="cw-card-head">
            <div class="cw-card-icon" aria-hidden="true">👤</div>
            <div><h2><?=t('Informasi Pembeli')?></h2><p><?=t('Isi nama, lalu email dan/atau WhatsApp untuk pengiriman produk.')?></p></div>
          </div>
          <div class="cw-fields">
            <div class="cw-field cw-field-full" data-cw-field="name">
              <label for="cwName"><?=t('Nama Lengkap')?> <span class="req">*</span></label>
              <input id="cwName" name="buyer_name" required maxlength="120" placeholder="Budi Santoso" autocomplete="name">
              <small class="cw-field-error"></small>
            </div>
            <div class="cw-field" data-cw-field="email">
              <label for="cwEmail"><?=t('Email Aktif')?></label>
              <input id="cwEmail" name="buyer_email" type="email" maxlength="190" placeholder="email@gmail.com" autocomplete="email">
              <small><?=t('Untuk pengiriman email.')?></small>
              <small class="cw-field-error"></small>
            </div>
            <div class="cw-field" data-cw-field="wa">
              <label for="cwWa"><?=t('Nomor WhatsApp')?></label>
              <input id="cwWa" name="buyer_whatsapp" inputmode="tel" autocomplete="tel" maxlength="40" placeholder="08123456789">
              <small><?=t('Untuk pengiriman WhatsApp.')?></small>
              <small class="cw-field-error"></small>
            </div>
          </div>
          <div class="cw-actions">
            <button type="button" class="cw-btn-primary" id="cwStep1Next"><?=t('Lanjut ke Pembayaran')?></button>
          </div>
        </div>

        <div class="cw-panel" id="cwPanel2" data-cw-panel="2" hidden>
          <div class="cw-card-head">
            <div class="cw-card-icon" aria-hidden="true">💳</div>
            <div><h2><?=t('Metode Pembayaran')?></h2><p><?=t('Pilih metode pembayaran untuk pesanan ini.')?></p></div>
          </div>
          <div class="cw-methods" id="cwMethods"></div>
          <div class="cw-actions cw-actions-split">
            <button type="button" class="cw-btn-soft" id="cwBackBtn"><?=t('Kembali')?></button>
            <button type="button" class="cw-btn-primary" id="cwNextBtn"><?=t('Lanjut ke Konfirmasi')?></button>
          </div>
        </div>

        <div class="cw-panel" id="cwPanel3" data-cw-panel="3" hidden>
          <div class="cw-card-head">
            <div class="cw-card-icon" aria-hidden="true">✓</div>
            <div><h2><?=t('Konfirmasi Pesanan')?></h2><p><?=t('Periksa kembali data Anda sebelum memesan.')?></p></div>
          </div>
          <div class="cw-confirm-grid" id="cwConfirmGrid"></div>
          <label class="cw-terms-agree">
            <input type="checkbox" id="cwTermsAgree" name="terms_accepted" value="1">
            <span><?=t('Saya telah membaca dan menyetujui syarat & ketentuan produk.')?></span>
          </label>
          <div class="cw-actions cw-actions-split">
            <button type="button" class="cw-btn-soft" id="cwBackBtn2" onclick="document.getElementById('cwBackBtn')?.click()"><?=t('Kembali')?></button>
            <button type="submit" class="cw-btn-primary" id="cwSubmitBtn" disabled aria-disabled="true"><?=t('Bayar Sekarang')?></button>
          </div>
        </div>
      </div>

      <aside class="cw-card cw-summary-card" aria-label="<?=t('Ringkasan Pesanan')?>">
        <div class="cw-card-head cw-summary-head">
          <div class="cw-card-icon" aria-hidden="true">🧾</div>
          <div><h2><?=t('Ringkasan Pesanan')?></h2><p><?=t('Periksa kembali produk dan total belanja Anda.')?></p></div>
        </div>
        <div id="cwSummaryItems" class="cw-summary-items"></div>
        <div class="cw-summary-lines">
          <p><span><?=t('Subtotal')?></span><b data-cw-subtotal>Rp 0</b></p>
          <p id="cwDiscountRow" class="discount-row" hidden><span><?=t('Diskon Promo')?></span><b data-cw-discount></b></p>
        </div>
        <div class="cw-promo-block">
          <label for="cwPromoInput"><?=t('Kode Promo')?></label>
          <div class="cw-promo-row">
            <input id="cwPromoInput" name="promo_code" placeholder="<?=t('Opsional')?>" autocomplete="off" maxlength="40">
            <button type="button" id="cwPromoApply"><?=t('Terapkan')?></button>
          </div>
          <small id="cwPromoMsg" class="cw-promo-msg" aria-live="polite"></small>
        </div>
        <div class="cw-summary-foot"><span><?=t('Total')?></span><b data-cw-total>Rp 0</b></div>
        <p class="cw-summary-secure">🔒 <?=t('Pembayaran aman & terenkripsi')?></p>
      </aside>
    </form>

    <script id="checkoutMethodsJson" type="application/json"><?=json_encode($checkoutMethods, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script>
    <script id="checkoutWizardI18n" type="application/json"><?=json_encode([
      'emptyCart'=>t('Keranjang kosong'),
      'errName'=>t('Nama wajib diisi.'),
      'errEmail'=>t('Email tidak valid.'),
      'errWa'=>t('Nomor WhatsApp tidak valid.'),
      'errContact'=>t('Isi email atau WhatsApp (minimal salah satu).'),
      'errMethod'=>t('Pilih metode pembayaran.'),
      'errPromo'=>t('Kode promo tidak valid.'),
      'errTerms'=>t('Anda harus menyetujui syarat & ketentuan produk.'),
      'errNetwork'=>t('Gagal terhubung ke server. Coba lagi.'),
    ], JSON_UNESCAPED_UNICODE)?></script>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats,['q'=>$_GET['q']??'']); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b><?=t('Keranjang Belanja')?> (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span><?=t('Subtotal')?></span><b data-cart-total>Rp 0</b></p><a href="/checkout.php" class="btn primary"><?=t('Lanjut Checkout')?></a></div></aside></div>
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261014-reviews-v1"></script>
<?php if($order && $orderProvider==='casaku' && !$isPaymentExpired && !empty($qrisValue)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function(){
  var el=document.getElementById('casakuQrCanvas');
  var payload=window.dsCasakuQrisPayload||'';
  if(el && payload && window.QRCode){
    new QRCode(el,{text:payload,width:260,height:260,correctLevel:QRCode.CorrectLevel.M});
  }
})();
</script>
<?php endif; ?>
<?php if(!$order): ?>
<script src="/assets/js/checkout-wizard.js?v=20261011-header-v7"></script>
<?php else: ?>
<script src="/assets/js/checkout-v24.js?v=20260603manualstockfix1"></script>
<?php endif; ?>
</body></html>
