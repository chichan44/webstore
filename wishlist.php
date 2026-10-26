<?php
require_once __DIR__.'/includes/helpers.php';
$site=setting('site_name','DigiSoft Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$telegramProductButtonUrl=telegram_product_button_template();
ensure_product_extra_columns();
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
function ds_icon($name){$n=mb_strtolower((string)$name); if(str_contains($n,'akun')||str_contains($n,'premium')) return '♛'; if(str_contains($n,'book')||str_contains($n,'ebook')) return '▭'; if(str_contains($n,'template')) return '▤'; if(str_contains($n,'tool')) return '⌘'; if(str_contains($n,'voucher')) return '⌁'; return '▦';}
function ds_thumb_html($thumb,$label='Produk Digital'){$src=trim((string)$thumb);$fallback=trim((string)$label)?:'Produk Digital';if($src==='')return '<b class="ds-default-thumb" aria-label="Produk tanpa thumbnail: '.e($fallback).'"><span>'.e($fallback).'</span></b>';return '<img class="ds-product-thumb-img" src="'.e($src).'" alt="Thumbnail produk '.e($fallback).'" loading="lazy" width="600" height="600">';}
$sql='SELECT p.*, c.name category_name, c.slug category_slug, COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ("paid","delivered")),0) sold_success_count, COALESCE((SELECT COUNT(*) FROM product_reviews r WHERE r.product_id=p.id AND r.status="approved"),0) review_count FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status=1 ORDER BY p.id DESC';
$rows=$pdo->query($sql)->fetchAll();
$products=[];
foreach($rows as $p){
  $flashEndsTs=!empty($p['flash_ends_at'])?strtotime($p['flash_ends_at']):0;
  $flashActive=product_variant_flash_active($p);
  $cardPricing=storefront_product_card_pricing($p);
  $price=$cardPricing['price'];
  $orig=(float)($cardPricing['originalPrice'] ?? 0);
  $regular = $orig > 0 ? $orig : $price;
  $delivery=strtolower(trim((string)($p['delivery_type']??'instant')))==='manual'?'manual':'instant';
  /* Stock lives only on variants — catalog has no product-level stock; CTAs navigate to PDP */
  $products[]=['id'=>(int)$p['id'],'name'=>$p['name'],'slug'=>$p['slug'],'description'=>$p['description']?:'Produk digital siap dikirim otomatis setelah pembayaran berhasil.','descriptionHtml'=>safe_product_html($p['description']?:'Produk digital siap dikirim otomatis setelah pembayaran berhasil.'),'category'=>$p['category_name']?:'Digital','categorySlug'=>$p['category_slug']?:'','icon'=>ds_icon($p['category_name']?:'Digital'),'price'=>$price,'originalPrice'=>$orig,'hasStock'=>$cardPricing['hasStock'],'discount'=>$orig>$price?('-'.round((1-$price/$orig)*100).'%'):'-0%','maxDiscountPct'=>(int)($cardPricing['maxDiscountPct'] ?? 0),'sold'=>(int)($p['sold_success_count']??0),'reviews'=>(int)($p['review_count']??0),'rating'=>max(0,min(5,(float)($p['rating']??5))),'warranty'=>trim((string)($cardPricing['warrantyLabel'] ?? ''))?:'Garansi','deliveryType'=>$delivery,'deliveryLabel'=>$delivery==='manual'?'✍ Manual':'⚡ Instan','thumbnail'=>trim((string)($p['thumbnail']??''))];
}
?><!doctype html><html lang="<?=e(lang_resolve())?>" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta(['site'=>$site,'title'=>t('Wishlist - {site}',['site'=>$site]),'description'=>t('Daftar produk favorit yang kamu simpan di {site}.',['site'=>$site]),'path'=>'/wishlist.php','robots'=>'noindex, follow']);render_storefront_styles('wishlist');?></head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell wishlist-page"><div class="wishlist-page-head"><div><h1>♡ <?=t('♡ Wishlist Saya')?></h1><p><?=t('Produk favorit tersimpan di perangkat ini.')?></p></div><a href="/#produk"><?=t('Lihat Semua Produk')?> →</a></div><div class="wishlist-product-grid" data-wishlist-grid>
<?php foreach($products as $p):?><article hidden class="product-card znl-product-card" data-wishlist-card data-id="<?=intval($p['id'])?>"><div class="thumb znl-card-thumb"><?php $cardBadge=storefront_discount_badge($p);?><?php if($cardBadge!==''):?><em><?=e($cardBadge)?></em><?php endif;?><?=ds_thumb_html($p['thumbnail'],$p['name'])?><button class="product-wishlist-btn" type="button" data-wishlist-toggle="<?=intval($p['id'])?>" aria-pressed="true"><span>♥</span></button></div><div class="product-main znl-card-body"><span class="grid-category-label"><?=e($p['category'])?></span><a class="product-title" href="/<?=e($p['slug'])?>"><?=e($p['name'])?></a><?=render_product_rating((float)$p['rating'], (int)$p['sold'], (int)($p['reviews'] ?? 0))?><div class="product-price-row"><strong><?=rupiah($p['price'])?></strong><?php if($p['originalPrice']>$p['price']):?><del><?=rupiah($p['originalPrice'])?></del><?php endif;?></div><div class="product-meta"><p><?=t('Pilih varian')?></p><small><?=t('Terjual')?>: <?=intval($p['sold'])?></small></div></div><div class="card-bottom znl-card-bottom"><a href="/<?=e($p['slug'])?>">🛒 <?=t('Lihat Produk & Pilih Varian')?></a></div></article><?php endforeach;?></div><section class="wishlist-empty" data-wishlist-empty><span>♡</span><h2><?=t('Wishlist masih kosong')?></h2><p><?=t('Tekan ikon hati pada produk yang ingin kamu simpan.')?></p><a class="btn primary" href="/#produk"><?=t('Mulai Belanja')?></a></section></main>
<?php render_footer_columns($site,$desc);?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b><?=t('Keranjang Belanja')?> (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span><?=t('Subtotal')?></span><b data-cart-total>Rp 0</b></p><a href="/checkout.php" class="btn primary"><?=t('Lanjut Checkout')?></a></div></aside></div>
<div class="product-modal" id="productModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-product></div>
  <section class="product-modal-card">
    <button type="button" class="modal-close-floating" data-close-product aria-label="<?=t('Tutup')?>">×</button>
    <div class="modal-split-layout">
      <div class="modal-visual-wrap">
        <div class="modal-visual">
          <button type="button" data-close-product aria-label="Tutup popup">×</button>
          <img id="modalThumb" class="modal-thumb-img" alt="" loading="lazy">
          <span class="modal-stock-ribbon" id="modalStockRibbon"><?=t('Stok Habis')?></span>
          <span class="modal-icon" id="modalIcon">♛</span>
        </div>
        <div class="modal-stats" aria-label="Informasi produk">
          <div class="modal-stat-item"><i class="modal-stat-icon stock" aria-hidden="true">▣</i><div class="modal-stat-copy"><small><?=t('Stok Tersedia')?></small><b id="modalStock">0</b></div></div>
          <div class="modal-stat-item"><i class="modal-stat-icon sold" aria-hidden="true">🛍</i><div class="modal-stat-copy"><small><?=t('Telah Terjual')?></small><b id="modalSold">0</b></div></div>
          <div class="modal-stat-item"><i class="modal-stat-icon send" aria-hidden="true">⚡</i><div class="modal-stat-copy"><small><?=t('Pengiriman')?></small><b id="modalDelivery">⚡ <?=t('Instan')?></b></div></div>
          <div class="modal-stat-item"><i class="modal-stat-icon warranty" aria-hidden="true">🛡</i><div class="modal-stat-copy"><small><?=t('Garansi')?></small><b id="modalWarranty"><?=t('Garansi')?></b></div></div>
        </div>
      </div>
      <div class="modal-body">
        <div class="modal-badges"><span id="modalCategory" class="modal-badge-category"><?=t('Kategori')?></span><span id="modalDiscount" class="modal-badge-discount"><?=t('Diskon')?> -0%</span></div>
        <h3 id="modalName"><?=t('Produk')?></h3>
        <p id="modalSubtitle" class="modal-subtitle"><?=t('Produk digital siap dikirim otomatis setelah pembayaran berhasil.')?></p>
        <div id="modalRatingRow" class="modal-rating-row" aria-label="Rating 5.0 dari 5"><span class="modal-rating-stars" id="modalRatingStars" aria-hidden="true"><i class="is-full">★</i><i class="is-full">★</i><i class="is-full">★</i><i class="is-full">★</i><i class="is-full">★</i></span><b id="modalRatingValue">5.0</b><small id="modalRatingCount">(12)</small></div>
        <div id="modalDesc" class="modal-desc"></div>
        <button type="button" id="modalDescToggle" class="modal-desc-toggle" hidden><?=t('Baca Selengkapnya')?>...</button>
        <div class="modal-feature-list" id="modalFeatureList">
          <div class="modal-feature-item"><i class="feature-mail">✉</i><div><b><?=t('Produk Digital')?></b><p><?=t('Siap digunakan setelah pembayaran berhasil.')?></p></div></div>
          <div class="modal-feature-item"><i class="feature-zap">⚡</i><div><b><?=t('Proses Cepat')?></b><p><?=t('Pengiriman dan aktivasi dilakukan secepat mungkin.')?></p></div></div>
          <div class="modal-feature-item"><i class="feature-check">✔</i><div><b><?=t('Aman Digunakan')?></b><p><?=t('Produk dijaga agar tetap aman dan terpercaya.')?></p></div></div>
          <div class="modal-feature-item"><i class="feature-support">🎧</i><div><b><?=t('Support Responsif')?></b><p><?=t('Bantuan cepat jika ada kendala pada pesanan.')?></p></div></div>
        </div>
        <div class="modal-purchase-row">
          <div class="modal-price"><strong id="modalPrice">Rp 0</strong><del id="modalOriginal"></del></div>
        </div>
        <div class="modal-action-row">
          <button type="button" class="modal-wishlist-btn" id="modalWishlistBtn" data-wishlist-toggle="" aria-pressed="false"><span>♡</span> <?=t('Wishlist')?></button>
          <a id="modalAddCart" href="#">🛒 <?=t('Lihat Produk & Pilih Varian')?></a>
        </div>
        <a
          id="modalTelegramBuy"
          class="modal-telegram-buy"
          href="#"
          target="_blank"
          rel="noopener noreferrer"
          data-url-template="<?=e($telegramProductButtonUrl)?>"
          <?= $telegramProductButtonUrl === '' ? 'hidden' : '' ?>
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 3-7.7 18-4.4-6.4L3 11.2 21 3Z"/><path d="m8.9 14.6 4.1-3.8"/></svg>
          <span><?=t('Beli di Bot Telegram')?></span>
        </a>
        <div class="modal-mini-features" aria-label="Keamanan transaksi">
          <span class="modal-mini-feature-title">🛡 <b><?=t('Transaksi aman & terpercaya')?></b></span>
          <span><?=t('Garansi aktif')?></span><span><?=t('Pengiriman cepat')?></span><span><?=t('Support responsif')?></span>
        </div>
      </div>
    </div>
  </section>
</div>
<script id="productsJson" type="application/json"><?=json_encode($products,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script><script id="frontI18n" type="application/json"><?=json_encode(['modalStock'=>t('Pilih varian'),'modalCta'=>t('Lihat Produk & Pilih Varian'),'maxStock'=>t('Jumlah melebihi stok tersedia')],JSON_UNESCAPED_UNICODE)?></script><script src="/assets/js/front-mobile-v7.js?v=20261014-reviews-v1"></script></body></html>
