<?php
require_once __DIR__.'/includes/helpers.php';
$site=setting('site_name','DigiSoft Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$telegramProductButtonUrl=telegram_product_button_template();
ensure_product_extra_columns();
$hasArchiveColumn=false;
try {
  $archiveColumn=$pdo->query("SHOW COLUMNS FROM products LIKE 'is_archived'");
  $hasArchiveColumn=(bool)($archiveColumn && $archiveColumn->fetch());
} catch(Throwable $e) {}
ensure_category_icon_column();
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
$banner=$pdo->query('SELECT * FROM banners WHERE status=1 ORDER BY sort_order ASC,id ASC LIMIT 1')->fetch() ?: [];
$bannerTitle=$banner['title'] ?? 'Produk digital otomatis terkirim setelah pembayaran berhasil';
$bannerSubtitle=$banner['subtitle'] ?? 'Akun premium, voucher, template, e-book, tools, dan produk digital lain dalam satu toko. Cepat, aman, dan terpercaya tanpa perlu login.';
$bannerButtonText=($banner['button_text'] ?? '') ?: 'Belanja Sekarang';
$bannerButtonUrl=($banner['button_url'] ?? '') ?: '#produk';

// Promo yang tampil di homepage mengikuti data dan aturan yang sama dengan /admin/promos.php.
$activePromos=[];
try {
  $promoRows=$pdo->query('SELECT * FROM promos WHERE status=1 ORDER BY id DESC')->fetchAll();
  $promoNow=(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->getTimestamp();
  foreach($promoRows as $promoRow){
    $promoStarts=promo_datetime_ts($promoRow['starts_at'] ?? '');
    $promoEnds=promo_datetime_ts($promoRow['ends_at'] ?? '');
    $promoLimit=(int)($promoRow['usage_limit'] ?? 0);
    $promoUsed=(int)($promoRow['used_count'] ?? 0);
    if($promoStarts!==null && $promoStarts>$promoNow) continue;
    if($promoEnds!==null && $promoEnds<$promoNow) continue;
    if($promoLimit>0 && $promoUsed>=$promoLimit) continue;
    if(parse_local_number($promoRow['value'] ?? 0)<=0) continue;
    $activePromos[]=$promoRow;
  }
} catch(Throwable $e) {
  // Homepage tetap normal bila tabel promo belum tersedia pada instalasi lama.
  $activePromos=[];
}
function ds_icon($name){ return category_icon_symbol((string)$name); }
function ds_category_icon($category){ return category_icon_symbol((string)($category['name'] ?? ''),(string)($category['icon'] ?? '')); }
function ds_thumb_html($thumb,$label='Produk Digital'){
  $src=trim((string)$thumb);
  $fallback=trim((string)$label);
  if($fallback==='') $fallback='Produk Digital';
  if($src===''){
    return '<b class="ds-default-thumb" aria-label="Produk tanpa thumbnail: '.e($fallback).'"><span>'.e($fallback).'</span></b>';
  }
  return '<img class="ds-product-thumb-img" src="'.e($src).'" alt="Thumbnail produk '.e($fallback).'" loading="lazy" width="600" height="600" style="width:100%;height:100%;max-width:none;max-height:none;object-fit:cover;object-position:center center;display:block;">';
}
function ds_desc_points($text,$limit=3){ $text=trim(strip_tags((string)$text)); if($text==='') return []; $text=str_replace(["\r\n","\r"],"\n",$text); $parts=preg_split('/\n+|(?:\s*[•\-–]\s+)/u',$text); $out=[]; foreach($parts as $part){ $part=trim(preg_replace('/\s+/u',' ',(string)$part)); if($part!=='') $out[]=$part; if(count($out)>=$limit) break; } if(!$out){ $out[]=mb_strimwidth($text,0,90,'...'); } return $out; }
$categoryIconMap=[];
foreach($cats as $categoryRow){ $categoryIconMap[(int)$categoryRow['id']]=ds_category_icon($categoryRow); }
$q=trim($_GET['q']??'');
$sql='SELECT p.*, c.name category_name, c.slug category_slug, (SELECT COUNT(*) FROM product_variants v WHERE v.product_id=p.id AND v.status=1) variant_count, COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ("paid","delivered")),0) sold_success_count, COALESCE((SELECT COUNT(*) FROM product_reviews r WHERE r.product_id=p.id AND r.status="approved"),0) review_count FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status=1';
if($hasArchiveColumn) $sql.=' AND COALESCE(p.is_archived,0)=0';
$params=[];
if($q!==''){ $sql.=' AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)'; $params=["%$q%","%$q%","%$q%"]; }
$sql.=' ORDER BY p.is_flash_sale DESC, p.id DESC';
$st=$pdo->prepare($sql); $st->execute($params); $products=$st->fetchAll();
$recent=$pdo->query('SELECT oi.product_name, oi.product_id, o.buyer_name, COALESCE(o.paid_at,o.delivered_at,o.created_at) created_at, p.thumbnail FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.id=oi.product_id WHERE o.status IN ("paid","delivered") ORDER BY COALESCE(o.paid_at,o.delivered_at,o.created_at) DESC, oi.id DESC LIMIT 5')->fetchAll();

$productJson=[];
$flashProducts=[];
foreach($products as $p){
  $flashEndsTs=!empty($p['flash_ends_at']) ? strtotime($p['flash_ends_at']) : 0;
  $flashActive=product_variant_flash_active($p);
  $cardPricing=storefront_product_card_pricing($p);
  $price=$cardPricing['price'];
  $orig=(float)($cardPricing['originalPrice'] ?? 0);
  $regularPrice = $orig > 0 ? $orig : $price;
  $catName=$p['category_name']?:'Digital';
  $warrantyLabel=trim((string)($cardPricing['warrantyLabel'] ?? ''));
  if($warrantyLabel==='') $warrantyLabel='Garansi';
  $deliveryType=strtolower(trim((string)($p['delivery_type'] ?? 'instant')))==='manual'?'manual':'instant';
  /* Stock lives only on variants — catalog has no product-level stock; CTAs navigate to PDP */
  $item=[
    'id'=>(int)$p['id'], 'name'=>$p['name'], 'slug'=>$p['slug'], 'description'=>$p['description'] ?: 'Produk digital siap dikirim otomatis setelah pembayaran berhasil.', 'descriptionHtml'=>safe_product_html($p['description'] ?: 'Produk digital siap dikirim otomatis setelah pembayaran berhasil.'),
    'category'=>$catName, 'categorySlug'=>$p['category_slug'] ?: '', 'icon'=>($categoryIconMap[(int)($p['category_id'] ?? 0)] ?? ds_icon($catName)), 'price'=>$price, 'originalPrice'=>$orig, 'hasStock'=>$cardPricing['hasStock'],
    'regularPrice'=>$regularPrice, 'isFlashSale'=>$flashActive, 'flashEndsAt'=>$flashActive && $flashEndsTs ? date('c',$flashEndsTs) : '',
    'discount'=>$orig>$price?('-'.round((1-$price/$orig)*100).'%'):'-0%', 'maxDiscountPct'=>(int)($cardPricing['maxDiscountPct'] ?? 0), 'sold'=>(int)($p['sold_success_count'] ?? 0), 'reviews'=>(int)($p['review_count'] ?? 0),
    'rating'=>max(0,min(5,(float)($p['rating'] ?? 5))), 'warranty'=>$warrantyLabel, 'deliveryType'=>$deliveryType, 'deliveryLabel'=>$deliveryType==='manual'?'Manual':'Instan', 'thumbnail'=>trim((string)($p['thumbnail'] ?? '')), 'descPoints'=>ds_desc_points($p['description'] ?? '',3)
  ];
  $productJson[]=$item;
  if($flashActive){ $flashProducts[]=$item; }
}
?><!doctype html>
<html lang="id" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$seoTitle = $q !== '' ? t('Hasil pencarian {q} - {site}', ['q'=>$q,'site'=>$site]) : t('{site} - Toko Produk Digital Terpercaya', ['site'=>$site]);
$seoDescription = $q !== '' ? t('Temukan produk digital untuk pencarian {q} di {site}. Belanja aman, cepat, dan mudah.', ['q'=>$q,'site'=>$site]) : ($desc ?: t('Belanja akun premium, voucher, hosting, domain, RDP, template, dan produk digital lain dengan proses cepat dan aman.'));
$seoImage = '';
foreach($productJson as $seoProduct){ if(!empty($seoProduct['thumbnail'])){ $seoImage = $seoProduct['thumbnail']; break; } }
$seoJson = [store_structured_data($site, $desc), [
  '@context'=>'https://schema.org',
  '@type'=>'ItemList',
  'name'=>'Produk digital '.$site,
  'itemListElement'=>array_map(function($item,$i){ return [
    '@type'=>'ListItem',
    'position'=>$i+1,
    'url'=>seo_url('/#produk'),
    'name'=>$item['name']
  ]; }, array_slice($productJson,0,12), array_keys(array_slice($productJson,0,12)))
]];
render_seo_meta([
  'site'=>$site,
  'title'=>$seoTitle,
  'description'=>$seoDescription,
  'path'=>'/',
  'robots'=>$q !== '' ? 'noindex, follow' : 'index, follow',
  'image'=>$seoImage,
  'json_ld'=>$seoJson
]);
?>
<?php render_storefront_styles('index'); ?>
<style>
/* Voucher aktif - khusus homepage */
.znl-voucher-section{margin:18px 0 20px;padding:18px 20px;border:1px solid rgba(91,105,145,.16);border-radius:22px;background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(245,248,255,.92));box-shadow:0 12px 34px rgba(38,52,94,.06)}
.znl-voucher-head{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:14px}.znl-voucher-title{display:flex;align-items:center;gap:11px;min-width:0}.znl-voucher-title-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:14px;background:linear-gradient(135deg,#eef2ff,#e9fbf6);color:#4d56e8;font-size:20px;box-shadow:inset 0 0 0 1px rgba(82,91,224,.08)}.znl-voucher-title h2{margin:0;color:#12213a;font-size:20px;line-height:1.15}.znl-voucher-title p{margin:4px 0 0;color:#748096;font-size:13px}.znl-voucher-count{flex:0 0 auto;padding:7px 11px;border-radius:999px;background:#f1f3ff;color:#4d56d9;font-size:12px;font-weight:800}
.znl-voucher-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.znl-voucher-card{position:relative;overflow:hidden;display:grid;grid-template-columns:76px minmax(0,1fr);min-height:122px;border:1px solid rgba(73,89,137,.13);border-radius:18px;background:#fff;box-shadow:0 8px 22px rgba(40,56,94,.045)}.znl-voucher-card:before,.znl-voucher-card:after{content:"";position:absolute;left:68px;width:14px;height:14px;border-radius:50%;background:#f6f8fc;border:1px solid rgba(73,89,137,.1);z-index:2}.znl-voucher-card:before{top:-8px}.znl-voucher-card:after{bottom:-8px}.znl-voucher-mark{display:flex;align-items:center;justify-content:center;border-right:1px dashed rgba(71,92,146,.2);background:linear-gradient(160deg,#eef2ff,#f0fbf8)}.znl-voucher-mark span{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;background:linear-gradient(135deg,#5458ee,#34b9db);color:#fff;font-weight:900;font-size:22px;box-shadow:0 8px 18px rgba(75,89,220,.2)}.znl-voucher-body{min-width:0;padding:14px 14px 13px}.znl-voucher-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.znl-voucher-code{font-size:17px;line-height:1.15;color:#3039c9;font-weight:900;letter-spacing:.25px;overflow-wrap:anywhere}.znl-voucher-offer{display:block;margin-top:4px;color:#24324a;font-size:13px;font-weight:800}.znl-voucher-copy{flex:0 0 auto;border:1px solid #cfd5ff;background:#f7f8ff;color:#4b52dc;border-radius:10px;padding:7px 11px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:.18s ease}.znl-voucher-copy:hover{background:#5157e7;color:#fff;border-color:#5157e7;transform:translateY(-1px)}.znl-voucher-meta{display:flex;flex-wrap:wrap;gap:5px 12px;margin-top:10px;color:#7a8496;font-size:11.5px;line-height:1.35}.znl-voucher-meta span{display:inline-flex;align-items:center;gap:4px}.znl-voucher-meta b{color:#455067;font-weight:800}
html[data-theme="dark"] .znl-voucher-section{background:linear-gradient(135deg,rgba(30,36,55,.96),rgba(25,32,49,.94));border-color:rgba(145,156,198,.15)}html[data-theme="dark"] .znl-voucher-title h2{color:#f3f5fb}html[data-theme="dark"] .znl-voucher-title p,html[data-theme="dark"] .znl-voucher-meta{color:#9ca6ba}html[data-theme="dark"] .znl-voucher-card{background:#20283a;border-color:rgba(158,168,205,.13)}html[data-theme="dark"] .znl-voucher-card:before,html[data-theme="dark"] .znl-voucher-card:after{background:#1b2232;border-color:rgba(158,168,205,.12)}html[data-theme="dark"] .znl-voucher-mark{background:linear-gradient(160deg,#252d48,#203b43);border-color:rgba(158,168,205,.18)}html[data-theme="dark"] .znl-voucher-offer{color:#e7eaf2}html[data-theme="dark"] .znl-voucher-meta b{color:#c6ccda}html[data-theme="dark"] .znl-voucher-copy{background:#282f4b;border-color:#454e85;color:#bfc5ff}
@media(max-width:1100px){.znl-voucher-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.znl-voucher-section{margin:13px 0 16px;padding:14px;border-radius:18px}.znl-voucher-head{align-items:flex-start;margin-bottom:12px}.znl-voucher-title-icon{width:38px;height:38px;border-radius:12px}.znl-voucher-title h2{font-size:17px}.znl-voucher-title p{font-size:12px;line-height:1.4}.znl-voucher-count{display:none}.znl-voucher-grid{display:flex;overflow-x:auto;gap:10px;padding:1px 1px 7px;scroll-snap-type:x proximity;scrollbar-width:none}.znl-voucher-grid::-webkit-scrollbar{display:none}.znl-voucher-card{flex:0 0 min(88vw,360px);grid-template-columns:66px minmax(0,1fr);min-height:116px;scroll-snap-align:start}.znl-voucher-card:before,.znl-voucher-card:after{left:58px}.znl-voucher-mark span{width:42px;height:42px;font-size:20px}.znl-voucher-body{padding:13px 12px}.znl-voucher-code{font-size:16px}.znl-voucher-copy{padding:6px 9px}.znl-voucher-meta{gap:4px 9px;font-size:11px}}
/* Catalog CTAs are product-page links (variants live on PDP) — keep button look */
.znl-flash-buy a,.grid-cart-btn,.table-add,.product-card .product-title,.table-product .product-title{
  display:inline-flex;align-items:center;justify-content:center;text-decoration:none;
}
.znl-flash-buy a{
  border:0;border-radius:12px;padding:11px 14px;background:linear-gradient(135deg,#6448ff,#22c7ee);color:#fff;font:inherit;font-size:13px;font-weight:800;cursor:pointer;
}
.znl-flash-buy a:hover{transform:translateY(-2px);filter:brightness(1.06)}
.product-card .product-title,.table-product .product-title{
  appearance:none;border:0;background:transparent;padding:0;font:inherit;text-align:left;cursor:pointer;color:inherit;
}
.product-card .product-title:hover,.table-product .product-title:hover{color:var(--indigo)}
.modal-qty-wrap,.modal-quantity-wrap{display:none!important}
#modalAddCart{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
</style>
</head>
<body>

<?php render_storefront_header($site,$desc,$cats,['q'=>$q,'category_mode'=>'filter']); ?>
<main class="ds-shell main-wrap">
  <section class="hero-card">
    <div class="hero-copy">
      <div class="hero-badge">✦ <?=t('Toko Produk Digital Terlengkap')?></div>
      <h1><?=e($bannerTitle)?></h1>
      <p><?=e($bannerSubtitle)?></p>
      <div class="hero-buttons"><a href="<?=e($bannerButtonUrl)?>" class="btn primary">🛒 <?=e($bannerButtonText)?></a><a href="/track-order.php" class="btn secondary">⌕ <?=t('Lacak Pesanan')?></a></div>
      <div class="hero-benefits">
        <div><span>♡</span><b><?=t('Produk Original')?></b><small><?=t('100% original & aman')?></small></div>
        <div><span>⚡</span><b><?=t('Otomatis Terkirim')?></b><small><?=t('Langsung ke email/WA')?></small></div>
        <div><span>▦</span><b><?=t('QRIS Otomatis')?></b><small><?=t('Verifikasi 24 Jam')?></small></div>
        <div><span>🔒</span><b><?=t('Pembayaran Aman')?></b><small><?=t('Sistem terpercaya')?></small></div>
      </div>
    </div>
    <div class="hero-art"><i class="float crown">♕</i><div class="digital-card"><span>▱</span><b>DIGITAL</b></div><i class="float zap">⚡</i><i class="spark s1">✦</i><i class="spark s2">✦</i></div>
  </section>

  <?php if($activePromos): ?>
  <section class="znl-voucher-section" aria-labelledby="voucherAktifTitle">
    <div class="znl-voucher-head">
      <div class="znl-voucher-title">
        <span class="znl-voucher-title-icon" aria-hidden="true">🎟</span>
        <div><h2 id="voucherAktifTitle">Kode Voucher Aktif</h2><p>Gunakan kode berikut saat checkout untuk mendapatkan potongan harga.</p></div>
      </div>
      <span class="znl-voucher-count"><?=count($activePromos)?> voucher tersedia</span>
    </div>
    <div class="znl-voucher-grid">
      <?php foreach($activePromos as $promo):
        $promoCode=strtoupper(trim((string)($promo['code'] ?? '')));
        $promoValue=parse_local_number($promo['value'] ?? 0);
        $promoMin=parse_local_number($promo['min_purchase'] ?? 0);
        $promoMax=parse_local_number($promo['max_discount'] ?? 0);
        $promoLimit=(int)($promo['usage_limit'] ?? 0);
        $promoUsed=(int)($promo['used_count'] ?? 0);
        $promoEnds=promo_datetime_ts($promo['ends_at'] ?? '');
        $promoOffer=strtolower((string)($promo['type'] ?? 'fixed'))==='percent' ? ('Diskon '.plain_number($promoValue).'%') : ('Potongan '.rupiah($promoValue));
      ?>
      <article class="znl-voucher-card">
        <div class="znl-voucher-mark" aria-hidden="true"><span>%</span></div>
        <div class="znl-voucher-body">
          <div class="znl-voucher-top">
            <div><div class="znl-voucher-code"><?=e($promoCode)?></div><span class="znl-voucher-offer"><?=e($promoOffer)?></span></div>
            <button type="button" class="znl-voucher-copy" data-copy-voucher="<?=e($promoCode)?>" aria-label="Salin kode voucher <?=e($promoCode)?>">Salin</button>
          </div>
          <div class="znl-voucher-meta">
            <span>🛒 <b><?= $promoMin>0 ? 'Min. '.e(rupiah($promoMin)) : 'Tanpa minimum' ?></b></span>
            <?php if($promoMax>0): ?><span>🏷 Maks. <b><?=e(rupiah($promoMax))?></b></span><?php endif; ?>
            <?php if($promoEnds!==null): ?><span>◷ Berlaku s.d. <b><?=e(date('d M Y H:i',$promoEnds))?> WIB</b></span><?php else: ?><span>◷ <b>Tanpa batas waktu</b></span><?php endif; ?>
            <?php if($promoLimit>0): ?><span>◎ Sisa <b><?=max(0,$promoLimit-$promoUsed)?></b> penggunaan</span><?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section id="produk" class="content-grid znl-product-section">
    <div class="products-side znl-products" id="productsSide" data-view="grid">
      <div class="znl-product-toolbar" id="kategori-section">
        <div class="znl-category-pills category-row">
          <button class="cat-pill active" type="button" data-filter-cat="all"><span>▦</span><?=t('Semua Produk')?></button>
          <?php foreach($cats as $c): ?><button class="cat-pill" type="button" data-filter-cat="<?=e($c['slug'])?>"><span><?=e(ds_category_icon($c))?></span><?=e($c['name'])?></button><?php endforeach; ?>
        </div>
      </div>

      <?php if($flashProducts): ?>
      <section class="znl-flash-banner flash-sale-strip" id="flashSaleStrip" aria-label="Flash sale aktif">
        <?php if(count($flashProducts)>1): ?><button class="znl-flash-nav prev" type="button" data-flash-slide="prev" aria-label="Flash sale sebelumnya">‹</button><button class="znl-flash-nav next" type="button" data-flash-slide="next" aria-label="Flash sale berikutnya">›</button><?php endif; ?>
        <div class="znl-flash-track" id="flashSaleTrack">
        <?php foreach($flashProducts as $fp): ?>
        <article class="znl-flash-inner" data-flash-card data-cat="<?=e($fp['categorySlug'])?>">
          <div class="znl-flash-count">
            <b>⚡ <?=t('Flash Sale')?></b>
            <small><?=t('Promo terbatas, harga akan kembali normal setelah waktu habis.')?></small>
            <div class="znl-countdown flash-countdown" data-countdown="<?=e($fp['flashEndsAt'])?>">
              <div class="cd-box"><strong data-cd-days>00</strong><span><?=t('Hari')?></span></div>
              <div class="cd-sep">:</div>
              <div class="cd-box"><strong data-cd-hours>00</strong><span><?=t('Jam')?></span></div>
              <div class="cd-sep">:</div>
              <div class="cd-box"><strong data-cd-minutes>00</strong><span><?=t('Menit')?></span></div>
              <div class="cd-sep">:</div>
              <div class="cd-box"><strong data-cd-seconds>00</strong><span><?=t('Detik')?></span></div>
            </div>
          </div>
          <div class="znl-flash-thumb" data-discount="<?=e($fp['originalPrice']>$fp['price'] ? $fp['discount'] : "")?>"><?=ds_thumb_html($fp['thumbnail'], $fp['name'])?><span>♛</span></div>
          <div class="znl-flash-detail">
            <h3><button type="button" class="znl-flash-title-link" data-open-product="<?=intval($fp['id'])?>"><?=e($fp['name'])?></button></h3>
            <span class="mini-cat"><?=e($fp['icon'])?> <?=e($fp['category'])?></span>
            <?php $flashBrief = mb_strimwidth(trim(preg_replace('/\s+/u',' ', strip_tags((string)$fp['description']))), 0, 118, '...'); ?>
            <?php if($flashBrief !== ''): ?><p class="znl-flash-desc"><?=e($flashBrief)?></p><?php endif; ?>
          </div>
          <div class="znl-flash-buy">
            <em><?=e($fp['discount'])?></em>
            <strong><?=rupiah($fp['price'])?></strong>
            <?php if($fp['originalPrice']>$fp['price']): ?><del><?=rupiah($fp['originalPrice'])?></del><?php endif; ?>
            <a href="/<?=e($fp['slug'])?>">🛒 <?=t('Beli Sekarang')?></a>
            <small><?=t('Pilih varian')?></small>
          </div>
        </article>
        <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <div class="znl-list-head section-divider">
        <div><h2>🔥 <?=t('Produk Pilihan')?></h2><p><?=t('Pilihan produk digital terbaik untukmu')?></p></div>
        <div class="znl-toolbar-actions tool-row">
          <div class="sort-wrap"><button class="sort-btn" type="button" data-sort-open><span id="sortLabel"><?=t('Terbaru')?></span></button><div class="sort-menu" id="sortMenu"><button type="button" class="active" data-sort="latest"><?=t('Terbaru')?></button><button type="button" data-sort="best"><?=t('Paling Laris')?></button></div></div>
          <div class="view-icons"><button type="button" data-view-mode="grid" class="active" title="Tampilan grid">▦</button><button type="button" data-view-mode="list" title="Tampilan list">☷</button></div>
        </div>
      </div>
      <div class="product-grid znl-product-grid" id="productGrid">
      <?php if(!$products): ?>
        <div class="empty-state"><?=t('Produk tidak ditemukan.')?></div>
      <?php endif; foreach($productJson as $p): ?>
        <article class="product-card znl-product-card" data-product-card data-id="<?=intval($p['id'])?>" data-order="<?=intval($p['id'])?>" data-sold="<?=intval($p['sold'])?>" data-cat="<?=e($p['categorySlug'])?>">
          <div class="thumb znl-card-thumb">
            <?php $cardBadge=storefront_discount_badge($p); ?><?php if($cardBadge!==''): ?><em><?=e($cardBadge)?></em><?php endif; ?>
            <?=ds_thumb_html($p['thumbnail'], $p['name'])?>
            <button class="product-wishlist-btn" type="button" data-wishlist-toggle="<?=intval($p['id'])?>" aria-label="Tambahkan <?=e($p['name'])?> ke wishlist" aria-pressed="false"><span>♡</span></button>
          </div>
          <div class="product-main znl-card-body">
            <a class="product-title" href="/<?=e($p['slug'])?>"><?=e($p['name'])?></a>
            <?=render_product_rating((float)$p['rating'], (int)$p['sold'], (int)($p['reviews'] ?? 0))?>
            <div class="product-price-action-row">
              <div class="product-price-row"><strong><?=rupiah($p['price'])?></strong><?php if($p['originalPrice']>$p['price']): ?><del><?=rupiah($p['originalPrice'])?></del><?php endif; ?></div>
              <a class="grid-cart-btn<?=empty($p['hasStock'])?' is-out':''?>" href="/<?=e($p['slug'])?>"<?php if(empty($p['hasStock'])): ?> aria-disabled="true"<?php endif; ?> aria-label="<?=t('Lihat Produk & Pilih Varian')?>: <?=e($p['name'])?>"><?=empty($p['hasStock'])?t('Habis'):t('Beli')?></a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      </div>

      <div class="product-table-wrap" id="productListView">
        <table class="product-table">
          <thead><tr><th><?=t('Produk')?></th><th><?=t('Kategori')?></th><th><?=t('Harga')?></th><th><?=t('Stok')?></th><th><?=t('Terjual')?></th><th><?=t('Aksi')?></th></tr></thead>
          <tbody>
            <?php foreach($productJson as $p): ?>
            <tr data-product-card data-id="<?=intval($p['id'])?>" data-order="<?=intval($p['id'])?>" data-sold="<?=intval($p['sold'])?>" data-cat="<?=e($p['categorySlug'])?>">
              <td><div class="table-product"><div class="thumb mini"><?php $tableBadge=storefront_discount_badge($p); ?><?php if($tableBadge!==''): ?><em><?=e($tableBadge)?></em><?php endif; ?><?=ds_thumb_html($p['thumbnail'], $p['name'])?></div><div><a class="product-title" href="/<?=e($p['slug'])?>"><?=e($p['name'])?></a><p><?=e(mb_strimwidth(trim(preg_replace('/\s+/u',' ', strip_tags((string)$p['description']))),0,80,'...'))?></p></div></div></td>
              <td><span class="mini-cat"><?=e($p['icon'])?> <?=e($p['category'])?></span></td>
              <td><strong class="table-price"><?=rupiah($p['price'])?></strong><?php if($p['originalPrice']>$p['price']): ?><del><?=rupiah($p['originalPrice'])?></del><?php endif; ?></td>
              <td><?=t('Pilih varian')?></td><td><span class="sold-text"><?=intval($p['sold'])?></span></td>
              <td><a class="table-add" href="/<?=e($p['slug'])?>"><?=t('Lihat')?></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats,['q'=>$q,'category_mode'=>'filter']); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total>Rp 0</b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<div class="product-modal" id="productModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-product></div>
  <section class="product-modal-card">
    <button type="button" class="modal-close-floating" data-close-product aria-label="Tutup popup">×</button>
    <div class="modal-split-layout">
      <div class="modal-visual-wrap">
        <div class="modal-visual">
          <button type="button" data-close-product aria-label="Tutup popup">×</button>
          <img id="modalThumb" class="modal-thumb-img" alt="" loading="lazy">
          <span class="modal-stock-ribbon" id="modalStockRibbon">Produk Habis</span>
          <span class="modal-icon" id="modalIcon">♛</span>
        </div>
        <div class="modal-stats" aria-label="Informasi produk">
          <div class="modal-stat-item"><i class="modal-stat-icon stock" aria-hidden="true">▣</i><div class="modal-stat-copy"><small>Tersedia</small><b id="modalStock">0</b></div></div>
          <div class="modal-stat-item"><i class="modal-stat-icon sold" aria-hidden="true">🛍</i><div class="modal-stat-copy"><small>Terjual</small><b id="modalSold">0</b></div></div>
          <div class="modal-stat-item"><i class="modal-stat-icon send" aria-hidden="true">⚡</i><div class="modal-stat-copy"><small>Pengiriman</small><b id="modalDelivery">Instan</b></div></div>
          <div class="modal-stat-item"><i class="modal-stat-icon warranty" aria-hidden="true">🛡</i><div class="modal-stat-copy"><small>Garansi</small><b id="modalWarranty">Garansi</b></div></div>
        </div>
      </div>
      <div class="modal-body">
        <div class="modal-badges"><span id="modalCategory" class="modal-badge-category">Kategori</span><span id="modalDiscount" class="modal-badge-discount">Diskon -0%</span></div>
        <h3 id="modalName">Produk</h3>
        <p id="modalSubtitle" class="modal-subtitle">Produk digital siap dikirim otomatis setelah pembayaran berhasil.</p>
        <div id="modalRatingRow" class="modal-rating-row" aria-label="Rating 5.0 dari 5"><span class="modal-rating-stars" id="modalRatingStars" aria-hidden="true"><i class="is-full">★</i><i class="is-full">★</i><i class="is-full">★</i><i class="is-full">★</i><i class="is-full">★</i></span><b id="modalRatingValue">5.0</b><small id="modalRatingCount">(12)</small></div>
        <div id="modalDesc" class="modal-desc"></div>
        <button type="button" id="modalDescToggle" class="modal-desc-toggle" hidden>Baca Selengkapnya...</button>
        <div class="modal-feature-list" id="modalFeatureList">
          <div class="modal-feature-item"><i class="feature-mail">✉</i><div><b>Produk Digital</b><p>Siap digunakan setelah pembayaran berhasil.</p></div></div>
          <div class="modal-feature-item"><i class="feature-zap">⚡</i><div><b>Proses Cepat</b><p>Pengiriman dan aktivasi dilakukan secepat mungkin.</p></div></div>
          <div class="modal-feature-item"><i class="feature-check">✔</i><div><b>Aman Digunakan</b><p>Produk dijaga agar tetap aman dan terpercaya.</p></div></div>
          <div class="modal-feature-item"><i class="feature-support">🎧</i><div><b>Support Responsif</b><p>Bantuan cepat jika ada kendala pada pesanan.</p></div></div>
        </div>
        <div class="modal-purchase-row">
          <div class="modal-price"><strong id="modalPrice">Rp 0</strong><del id="modalOriginal"></del></div>
        </div>
        <div class="modal-action-row">
          <button type="button" class="modal-wishlist-btn" id="modalWishlistBtn" data-wishlist-toggle="" aria-pressed="false"><span>♡</span> Wishlist</button>
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
          <span>Beli di Bot Telegram</span>
        </a><br/>
        <div class="modal-mini-features" aria-label="Keamanan transaksi">
          <span class="modal-mini-feature-title">🛡 <b>Transaksi aman &amp; terpercaya</b></span>
          <span>Garansi aktif</span><span>Pengiriman cepat</span><span>Support responsif</span>
        </div>
      </div>
    </div>
  </section>
</div>
<script id="productsJson" type="application/json"><?=json_encode($productJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script>
<script id="frontI18n" type="application/json"><?=json_encode([
    'modalStock'=>t('Pilih varian'),
    'modalCta'=>t('Lihat Produk & Pilih Varian'),
    'maxStock'=>t('Jumlah melebihi stok tersedia'),
], JSON_UNESCAPED_UNICODE)?></script>
<script id="recentSalesJson" type="application/json"><?=json_encode(array_map(function($r){ $ts=strtotime($r['created_at']); $mins=max(1,round((time()-$ts)/60)); return ['product_name'=>$r['product_name'],'product_id'=>(int)$r['product_id'],'buyer_name'=>mb_substr((string)$r['buyer_name'],0,4).'***','time_text'=>$mins<60?$mins.' menit lalu':date('d M H:i',$ts),'thumbnail'=>$r['thumbnail'] ?: '']; }, $recent), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script>
<div class="recent-sale-toast" id="recentSaleToast" aria-live="polite" aria-hidden="true">
  <button type="button" class="recent-sale-close" data-close-recent-sale aria-label="Tutup notifikasi">×</button>
  <span class="recent-sale-thumb" data-recent-sale-thumb>🛒</span>
  <div class="recent-sale-text">
    <small>Baru saja terjual</small>
    <b data-recent-sale-name>Produk digital</b>
    <p><span data-recent-sale-buyer>Pembeli</span> · <i data-recent-sale-time>baru saja</i></p>
  </div>
</div>
<script>
(function(){
  document.addEventListener('click',function(ev){
    var btn=ev.target.closest('[data-copy-voucher]');
    if(!btn) return;
    var code=btn.getAttribute('data-copy-voucher')||'';
    if(!code) return;
    var old=btn.textContent;
    function done(){ btn.textContent='Tersalin'; btn.disabled=true; window.setTimeout(function(){ btn.textContent=old; btn.disabled=false; },1400); }
    if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(code).then(done).catch(function(){ fallback(); }); }
    else fallback();
    function fallback(){
      var input=document.createElement('textarea'); input.value=code; input.setAttribute('readonly',''); input.style.position='fixed'; input.style.opacity='0'; document.body.appendChild(input); input.select();
      try{ document.execCommand('copy'); done(); }catch(e){}
      document.body.removeChild(input);
    }
  });
})();
</script>
<script src="/assets/js/front-mobile-v7.js?v=20261014-reviews-v1"></script>
</body></html>
