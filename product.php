<?php
require_once __DIR__.'/includes/helpers.php';
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$telegramProductButtonUrl=telegram_product_button_template();
ensure_product_extra_columns();
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();

$slug=trim((string)($_GET['slug'] ?? ''));
$product=null;
if($slug!==''){
    $st=$pdo->prepare('SELECT p.*, c.name category_name, c.slug category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.slug=? AND p.status=1 AND COALESCE(p.is_archived,0)=0 LIMIT 1');
    $st->execute([$slug]);
    $product=$st->fetch() ?: null;
}
if(!$product){ http_response_code(404); }

/* ---------- Review submission — buyer-only (invoice + access password / session) ---------- */
$reviewUnlocked = null;
$reviewVerifyMode = false;
if($product && $_SERVER['REQUEST_METHOD']==='POST' && in_array((string)($_POST['action'] ?? ''),['submit_review','verify_review_access'],true)){
    csrf_check();
    $returnTrack=trim((string)($_POST['return_track'] ?? ''))==='1';
    $trackInvoice=trim((string)($_POST['track_invoice'] ?? ''));
    $reviewRedirect=function() use ($product,$returnTrack,$trackInvoice){
        if($returnTrack){
            $qs=$trackInvoice!=='' ? '?invoice='.rawurlencode($trackInvoice) : '';
            header('Location: /track-order.php'.$qs); exit;
        }
        header('Location: /'.rawurlencode($product['slug']).'#reviews'); exit;
    };
    $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if(rate_limit_hit('product_review',$ip,6,3600)){
        flash('review_error','Terlalu banyak permintaan ulasan. Coba lagi beberapa saat lagi.');
        $reviewRedirect();
    }
    $action=(string)($_POST['action'] ?? '');
    $productId=(int)$product['id'];
    $rInvoice=trim((string)($_POST['review_invoice'] ?? ''));
    $rPassword=trim((string)($_POST['review_access_password'] ?? ''));

    // Unlock path: verify purchase proof, store session, reopen form.
    if($action==='verify_review_access'){
        $resolved=product_review_resolve_order_item($productId,$rInvoice,$rPassword);
        if(empty($resolved['ok'])){
            flash('review_error',$resolved['message'] ?? 'Verifikasi gagal.');
        }else{
            product_review_unlock_session((int)$resolved['order']['id'],$rInvoice,$productId,$rPassword);
            if(!empty($resolved['buyer_name'])){
                if(session_status()!==PHP_SESSION_ACTIVE) @session_start();
                $_SESSION['review_prefill'][$productId]=$resolved['buyer_name'];
            }
            flash('review_ok','Pembelian terverifikasi. Silakan tulis ulasan Anda.');
            $reviewVerifyMode=true;
        }
        $reviewRedirect();
    }

    // Submit path — must already be unlocked (session) or verify inline with password.
    $rName=trim((string)($_POST['review_name'] ?? ''));
    $rRating=(int)($_POST['review_rating'] ?? 0);
    $rComment=trim((string)($_POST['review_comment'] ?? ''));
    $resolved=null;
    $unlocked=product_review_session_unlocked($productId);
    if($unlocked){
        $st=$pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');$st->execute([$unlocked['order_id']]);
        $orderRow=$st->fetch();
        $st2=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? AND product_id=? ORDER BY id LIMIT 1');
        $st2->execute([$unlocked['order_id'],$productId]);
        $itemRow=$st2->fetch();
        if($orderRow && $itemRow && in_array(strtolower((string)$orderRow['status']),['paid','delivered'],true)){
            $resolved=['ok'=>true,'order'=>$orderRow,'item'=>$itemRow,'buyer_name'=>trim((string)($orderRow['buyer_name'] ?? ''))];
        }
    }
    if(empty($resolved['ok']) && $rInvoice!=='' && $rPassword!==''){
        $resolved=product_review_resolve_order_item($productId,$rInvoice,$rPassword);
        if(!empty($resolved['ok'])){
            product_review_unlock_session((int)$resolved['order']['id'],$rInvoice,$productId,$rPassword);
        }
    }
    if(empty($resolved['ok'])){
        flash('review_error', $resolved['message'] ?? 'Hanya pembeli pesanan ini yang dapat mengulas. Buka melalui Lacak Pesanan.');
        $reviewRedirect();
    }
    // Review is per-invoice: name comes from the order (already given at checkout).
    if($rName===''){ $rName=trim((string)($resolved['buyer_name'] ?? '')); }
    if($rName==='' || mb_strlen($rName)>120 || $rRating<1 || $rRating>5 || mb_strlen($rComment)<5 || mb_strlen($rComment)>1000){
        flash('review_error','Rating 1-5 dan ulasan 5-1000 karakter wajib diisi dengan benar.');
        $reviewRedirect();
    }
    $orderId=(int)$resolved['order']['id'];
    $orderItemId=(int)$resolved['item']['id'];
    if(product_review_already_submitted($orderId)){
        flash('review_error','Anda sudah mengulas pesanan ini.');
        $reviewRedirect();
    }
    try{
        $pdo->prepare('INSERT INTO product_reviews(product_id,name,rating,comment,status,ip,created_at,order_id,order_item_id) VALUES(?,?,?,?,"pending",?,NOW(),?,?)')
            ->execute([$productId,$rName,$rRating,$rComment,$ip,(int)$resolved['order']['id'],$orderItemId]);
        flash('review_ok','Ulasan kamu sudah terkirim dan menunggu moderasi admin.');
    }catch(Throwable $e){
        flash('review_error','Gagal menyimpan ulasan. Anda mungkin sudah mengulas produk ini.');
    }
    $reviewRedirect();
}

$reviewOk=(string)(flash('review_ok') ?? '');
$reviewError=(string)(flash('review_error') ?? '');
if($product){
    $reviewUnlocked=product_review_session_unlocked((int)$product['id']);
}

/* ---------- Variant + review data (product has >=1 active variant — invariant) ---------- */
$variants=[];
$flashActive=false;
$basePrice=0.0;
$regularPrice=0.0;
$originalPrice=0.0;
$anyStock=0;
$variantCards=[];
$reviewStats=['count'=>0,'avg'=>0.0];
$reviews=[];
$initialVariantId=null;
$heroVariant=null;
if($product){
    $variants=product_active_variants((int)$product['id']);
    $flashActive=product_variant_flash_active($product);
    $cheapestStockVariant=null;
    $cheapestAnyVariant=null;
    foreach($variants as $v){
        $stock=product_variant_stock($product,$v);
        $v['stock']=$stock;
        $v['effective_price']=product_variant_price($product,$v);
        $v['compare_price']=product_variant_compare_price($product,$v);
        $v['regular_price']=product_variant_regular_price($v);
        $v['discount_pct']=product_variant_discount_pct($product,$v);
        /* Garansi kini milik varian, bukan produk */
        $v['warranty_label']=product_variant_warranty_label($v,$product);
        $v['warranty_days']=product_variant_warranty_days($v,$product);
        $variantCards[]=$v;
        $anyStock+=$stock;
        if($cheapestAnyVariant===null || (float)$v['effective_price']<(float)$cheapestAnyVariant['effective_price']){
            $cheapestAnyVariant=$v;
        }
        if($stock>0){
            if($cheapestStockVariant===null || (float)$v['effective_price']<(float)$cheapestStockVariant['effective_price']){
                $cheapestStockVariant=$v;
            }
        }
    }
    /* Default selection = cheapest in-stock variant (hero price sync) */
    if($cheapestStockVariant!==null){
        $initialVariantId=(int)$cheapestStockVariant['id'];
    }elseif($variantCards){
        $initialVariantId=(int)$variantCards[0]['id'];
    }
    /* Hero price = harga varian yang ditampilkan; coret = harga reguler varian itu */
    $heroVariant=$cheapestStockVariant ?: $cheapestAnyVariant;
    if($heroVariant!==null){
        $basePrice=(float)$heroVariant['effective_price'];
        $originalPrice=(float)$heroVariant['compare_price'];
        $regularPrice=(float)$heroVariant['regular_price'];
    }
    /* Rating dinamis dari ulasan yang disetujui */
    $reviewStats['avg']=product_refresh_rating((int)$product['id']);
    $st=$pdo->prepare("SELECT COUNT(*) c, AVG(rating) a FROM product_reviews WHERE product_id=? AND status='approved'");
    $st->execute([(int)$product['id']]);
    $reviewRow=$st->fetch();
    if($reviewRow){
        $reviewStats['count']=(int)$reviewRow['c'];
        if($reviewRow['a']!==null) $reviewStats['avg']=(float)$reviewRow['a'];
    }
    /* Join order contact (little-censored on PDP) — website orders use WA, bot orders may only have telegram_user_id */
    $st=$pdo->prepare("SELECT r.*, o.buyer_whatsapp, tu.username AS telegram_username
        FROM product_reviews r
        LEFT JOIN orders o ON o.id=r.order_id
        LEFT JOIN telegram_users tu ON tu.telegram_user_id=o.telegram_user_id
        WHERE r.product_id=? AND r.status='approved' ORDER BY r.id DESC LIMIT 50");
    $st->execute([(int)$product['id']]);
    $reviews=$st->fetchAll();
}

/* Syarat & ketentuan = daftar bernomor. Admin mengisi satu ketentuan per baris;
   penomoran ditambahkan saat render, jadi angka/bullet di awal baris dibuang. */
$termsLines=[];
$rawTerms=trim((string)($product['product_terms'] ?? ''));
if($rawTerms!==''){
    foreach(preg_split('/\r\n|\r|\n/',$rawTerms) as $line){
        $line=trim(preg_replace('/^[-•*]+\s*/u','',preg_replace('/^\d+[.)]\s*/u','',(string)$line)));
        if($line!=='') $termsLines[]=$line;
    }
}
if(!$termsLines){
    $termsLines=[
        'Produk digital yang sudah terkirim tidak dapat dikembalikan.',
        'Garansi produk mengikuti ketentuan varian yang dipilih.',
        'Komplain wajib menyertakan invoice dan detail lengkap.',
    ];
}
/* Garansi mengikuti varian yang ditampilkan (fallback: varian pertama) */
$heroWarrantySource=$heroVariant ?? ($variantCards[0] ?? []);
$warrantyLabel=trim((string)($heroWarrantySource['warranty_label'] ?? ''));
if($warrantyLabel==='') $warrantyLabel='Garansi';
$warrantyDays=(int)($heroWarrantySource['warranty_days'] ?? warranty_days_from_label($warrantyLabel));
$deliveryType=strtolower((string)($product['delivery_type'] ?? 'instant'))==='manual'?'manual':'instant';
$catName=trim((string)($product['category_name'] ?? '')) ?: 'Digital';
$thumbSrc=trim((string)($product['thumbnail'] ?? ''));
$soldCount=0;
if($product){
    $soldCount=(int)$pdo->query('SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id='.(int)$product['id'].' AND o.status IN ("paid","delivered")')->fetchColumn();
    if($soldCount<1) $soldCount=(int)($product['sold_count'] ?? 0);
}
$availableLabel=$anyStock>0 ? t('Tersedia') : t('Stok Habis');
$jsonLdAvailability=$anyStock>0 ? 'InStock' : 'OutOfStock';
$productUrl=$product ? seo_url('/'.rawurlencode($product['slug'])) : '';
/* Hero + JSON-LD rating must match approved review average (review tab sync) */
$ratingSource=$reviewStats['count']>0 ? (float)$reviewStats['avg'] : (float)($product['rating'] ?? 0);
$ratingNum=max(0,min(5,$ratingSource));
$ratingDisplay=number_format($ratingNum,1);

/* Similar products — same category first; fallback to other products when category empty */
$similarProducts=[];
if($product){
    $similarCategoryId=(int)($product['category_id'] ?? 0);
    $hasArchiveCol=false;
    try{ $hasArchiveCol=(bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'is_archived'")->fetch(); }catch(Throwable $e){}
    $soldExpr='COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ("paid","delivered")),0) sold_success_count';
    $reviewExpr='COALESCE((SELECT COUNT(*) FROM product_reviews r WHERE r.product_id=p.id AND r.status="approved"),0) review_count';
    $similarRows=[];
    if($similarCategoryId>0){
        $sqlSimilar='SELECT p.*, c.name category_name, '.$soldExpr.', '.$reviewExpr.' FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status=1 AND p.category_id=? AND p.id<>?';
        if($hasArchiveCol) $sqlSimilar.=' AND COALESCE(p.is_archived,0)=0';
        $sqlSimilar.=' ORDER BY p.is_flash_sale DESC, sold_success_count DESC, p.id DESC LIMIT 8';
        $stSimilar=$pdo->prepare($sqlSimilar);
        $stSimilar->execute([$similarCategoryId,(int)$product['id']]);
        $similarRows=$stSimilar->fetchAll();
    }
    if(!$similarRows){
        $sqlSimilar='SELECT p.*, c.name category_name, '.$soldExpr.', '.$reviewExpr.' FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status=1 AND p.id<>?';
        if($hasArchiveCol) $sqlSimilar.=' AND COALESCE(p.is_archived,0)=0';
        $sqlSimilar.=' ORDER BY p.is_flash_sale DESC, sold_success_count DESC, p.id DESC LIMIT 8';
        $stSimilar=$pdo->prepare($sqlSimilar);
        $stSimilar->execute([(int)$product['id']]);
        $similarRows=$stSimilar->fetchAll();
    }
    foreach($similarRows as $sp){
        $spPricing=storefront_product_card_pricing($sp);
        $spCheapest=$spPricing['price'];
        $spCompare=(float)($spPricing['originalPrice'] ?? 0);
        $spDisc=(int)($spPricing['discountPct'] ?? 0);
        $similarProducts[]=[
            'id'=>(int)$sp['id'],
            'name'=>(string)$sp['name'],
            'slug'=>(string)$sp['slug'],
            'thumbnail'=>trim((string)($sp['thumbnail']??'')),
            'category'=>trim((string)($sp['category_name']??'')) ?: t('Digital'),
            'price'=>(float)$spCheapest,
            'originalPrice'=>$spCompare,
            'discount'=>$spDisc,
            'maxDiscountPct'=>(int)($spPricing['maxDiscountPct'] ?? 0),
            'rating'=>max(0,min(5,(float)($sp['rating']??0))),
            'sold'=>(int)($sp['sold_success_count']??0),
            'reviews'=>(int)($sp['review_count']??0),
            'hasStock'=>$spPricing['hasStock'],
        ];
    }
}

function pdp_thumb_html($src,$label){
    $src=trim((string)$src);
    $fallback=trim((string)$label);
    if($fallback==='') $fallback='Produk Digital';
    if($src===''){
        return '<b class="ds-default-thumb pp-hero-thumb-fallback" aria-label="Produk tanpa thumbnail: '.e($fallback).'"><span>'.e($fallback).'</span></b>';
    }
    return '<img class="pp-hero-thumb-img" src="'.e($src).'" alt="Thumbnail produk '.e($fallback).'" width="1200" height="750">';
}
?><!doctype html>
<html lang="id" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php
if($product){
    $jsonLdProduct=[
        '@context'=>'https://schema.org',
        '@type'=>'Product',
        'name'=>$product['name'],
        'image'=>$thumbSrc!==''?seo_url($thumbSrc):'',
        'description'=>seo_clean_text((string)($product['description'] ?? ''),160),
        'sku'=>(string)$product['slug'],
        'url'=>$productUrl,
        'offers'=>[
            '@type'=>'Offer',
            'url'=>$productUrl,
            'priceCurrency'=>'IDR',
            'price'=>(string)(int)round($basePrice),
            'availability'=>'https://schema.org/'.$jsonLdAvailability,
        ],
    ];
    if($reviewStats['count']>0){
        $jsonLdProduct['aggregateRating']=[
            '@type'=>'AggregateRating',
            'ratingValue'=>$ratingDisplay,
            'reviewCount'=>(string)$reviewStats['count'],
        ];
    }
    render_seo_meta([
        'site'=>$site,
        'title'=>$product['name'].' - '.$site,
        'description'=>seo_clean_text((string)($product['description'] ?? ''),160) ?: ($desc ?: 'Produk digital terpercaya.'),
        'path'=>'/'.$product['slug'],
        'canonical'=>$productUrl,
        'image'=>$thumbSrc,
        'json_ld'=>$jsonLdProduct,
    ]);
}else{
    render_seo_meta([
        'site'=>$site,
        'title'=>t('Produk tidak ditemukan').' - '.$site,
        'description'=>$desc ?: 'Produk digital terpercaya.',
        'robots'=>'noindex, follow',
    ]);
}
render_storefront_styles('product');
?>
</head>
<body>
<?php render_storefront_header($site,$desc,$cats); ?>

<?php if(!$product): ?>
<main class="ds-shell page-wrap"><section class="page-hero"><h1><?=t('Produk tidak ditemukan')?></h1><p><?=t('Produk yang kamu cari tidak tersedia atau sudah dinonaktifkan.')?></p><p><a class="btn primary" href="/#produk"><?=t('Kembali ke Produk')?></a></p></section></main>
<?php else: ?>
<?php
$warrantyShort=$warrantyDays>0 ? (t('Garansi').' - '.number_format($warrantyDays).' '.t('hari')) : t('Tanpa garansi');
/* Hero price = cheapest variant (stock & price live on variants) */
$cheapestVariantPrice=null;
$cheapestVariantId=null;
$heroOriginal=0.0;
foreach($variantCards as $vc){
    $vp=(float)$vc['effective_price'];
    if($cheapestVariantPrice===null || $vp<$cheapestVariantPrice){
        $cheapestVariantPrice=$vp;
        $cheapestVariantId=(int)$vc['id'];
        $heroOriginal=(float)($vc['compare_price'] ?? 0);
    }
}
if($cheapestVariantPrice===null) $cheapestVariantPrice=$basePrice;
if($heroOriginal<=0 && $originalPrice>$cheapestVariantPrice) $heroOriginal=$originalPrice;
$heroDiscPct=($heroOriginal>0 && $cheapestVariantPrice>0)?(int)round((1-$cheapestVariantPrice/$heroOriginal)*100):0;
$hasMultipleVariants=count($variantCards)>1;
?>
<main class="ds-shell pp-wrap">
  <a class="pp-back" href="/#produk">← <?=t('Kembali ke Produk')?></a>

  <!-- PREMIFY-style hero: small product image + title / cheapest-variant price / rating + sold -->
  <section class="pp-hero-card">
    <div class="pp-hero-thumb" data-pp-hero-thumb><?=pdp_thumb_html($thumbSrc,$product['name'])?></div>
    <div class="pp-hero-info">
      <div class="pp-pills">
        <span class="pp-pill pp-pill-cat"><span aria-hidden="true">🌐</span> <?=e($catName)?></span>
        <?php if($flashActive): ?><span class="pp-pill pp-pill-flash">⚡ <?=t('Flash Sale')?></span><?php endif; ?>
        <span class="pp-pill pp-pill-stock <?=$anyStock>0?'is-available':'is-out'?>"><span class="pp-pill-dot" aria-hidden="true"></span><?=e($availableLabel)?></span>
      </div>
      <h1 class="pp-title"><?=e($product['name'])?></h1>
      <div class="pp-price-row">
        <?php if($hasMultipleVariants): ?><span class="pp-price-from"><?=t('Mulai dari')?></span><?php endif; ?>
        <strong class="pp-price" data-pp-base-price><?=rupiah($cheapestVariantPrice)?></strong>
        <?php if($heroOriginal>0): ?><del class="pp-price-original" data-pp-hero-original><?=rupiah($heroOriginal)?></del><?php endif; ?>
        <?php if($heroDiscPct>0): ?><span class="pp-price-discount" data-pp-hero-discount>-<?=number_format($heroDiscPct)?>%</span><?php endif; ?>
        <?php if($flashActive): ?><span class="pp-flash-badge">⚡ <?=t('Flash Sale')?></span><?php endif; ?>
      </div>
    </div>
    <div class="pp-hero-meta">
      <span class="pp-rating" aria-label="Rating <?=e($ratingDisplay)?> dari 5, <?=number_format($reviewStats['count'])?> ulasan">
        <span class="pp-stars" aria-hidden="true" style="--pp-rate:<?=$ratingNum?>">
          <span class="pp-stars-track">★★★★★</span>
          <span class="pp-stars-fill">★★★★★</span>
        </span>
        <b class="pp-rating-num"><?=e($ratingDisplay)?></b>
        <span class="pp-rating-max">(<?=number_format($reviewStats['count'])?>)</span>
      </span>
      <div class="pp-sold-row">
        <b><?=number_format($soldCount)?></b>
        <span><?=t('Terjual')?></span>
      </div>
    </div>
  </section>

  <div class="pp-layout">
    <div class="pp-main-col">
      <!-- Card 1: Deskripsi + Syarat & Ketentuan (2 tabs) -->
      <section class="pp-card pp-tabs-section" data-pp-tabs="info">
        <div class="pp-tabs" role="tablist">
          <button type="button" class="pp-tab is-active" data-pp-tab="desc" role="tab" aria-selected="true"><?=t('Deskripsi')?></button>
          <button type="button" class="pp-tab" data-pp-tab="terms" role="tab" aria-selected="false"><?=t('Syarat & Ketentuan')?></button>
        </div>
        <div class="pp-tab-panel" data-pp-panel="desc" role="tabpanel">
          <div class="pp-desc"><?=safe_product_html((string)($product['description'] ?: 'Produk digital siap dikirim otomatis setelah pembayaran berhasil.'))?></div>
        </div>
        <div class="pp-tab-panel" data-pp-panel="terms" role="tabpanel" hidden>
          <div class="pp-terms">
            <header class="pp-terms-head">
              <span class="pp-terms-icon" aria-hidden="true">📋</span>
              <div class="pp-terms-head-copy">
                <b><?=t('Syarat & Ketentuan Produk')?></b>
                <small><?=number_format(count($termsLines))?> <?=t('ketentuan berlaku untuk varian yang dipilih')?></small>
              </div>
            </header>
            <ol class="pp-terms-list">
              <?php foreach($termsLines as $i=>$line): ?>
              <li class="pp-terms-item">
                <span class="pp-terms-num" aria-hidden="true"><?=($i+1)?></span>
                <span class="pp-terms-text"><?=e($line)?></span>
              </li>
              <?php endforeach; ?>
            </ol>
          </div>
        </div>
      </section>

      <?php if($telegramProductButtonUrl!==''): ?>
      <a id="ppTelegramBuy" class="pp-telegram-buy" href="#" target="_blank" rel="noopener noreferrer" data-url-template="<?=e($telegramProductButtonUrl)?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 3-7.7 18-4.4-6.4L3 11.2 21 3Z"/><path d="m8.9 14.6 4.1-3.8"/></svg>
        <span><?=t('Beli di Bot Telegram')?></span>
      </a>
      <?php endif; ?>
    </div>

    <!-- Card 2: Varian + Ulasan (2 tabs) — purchase lives in variant panel -->
    <aside class="pp-side-col">
      <section class="pp-card pp-tabs-section pp-buy-card" id="ppVariants" data-pp-tabs="buy">
        <div class="pp-tabs" role="tablist">
          <button type="button" class="pp-tab is-active" data-pp-tab="variants" role="tab" aria-selected="true"><?=t('Varian')?></button>
          <button type="button" class="pp-tab" data-pp-tab="reviews" role="tab" aria-selected="false"><?=t('Ulasan')?> (<?=number_format($reviewStats['count'])?>)</button>
        </div>

        <div class="pp-tab-panel" data-pp-panel="variants" role="tabpanel">
          <div class="pp-section-head">
            <h2 class="pp-section-title"><?=t('Pilih varian')?></h2>
            <?php if($flashActive): ?><span class="pp-section-chip">⚡ <?=t('Flash Sale')?></span><?php endif; ?>
          </div>
          <div class="pp-variant-list" role="radiogroup" aria-label="<?=t('Varian')?>">
            <?php foreach($variantCards as $v): $vid=(int)$v['id']; $isSelected=($initialVariantId!==null && $vid===$initialVariantId); $vPrice=(float)$v['effective_price']; $vStock=(int)$v['stock']; $vCompare=(float)($v['compare_price'] ?? 0); $vDisc=($vCompare>$vPrice && $vPrice>0)?(int)round((1-$vPrice/$vCompare)*100):0; $vWarrantyDays=(int)($v['warranty_days'] ?? 0); $vWarrantyText=$vWarrantyDays>0 ? (t('Garansi').' - '.number_format($vWarrantyDays).' '.t('hari')) : t('Tanpa garansi'); ?>
            <button type="button" class="pp-variant-card <?=$isSelected?'is-active':''?> <?=$vStock<=0?'is-out':''?>" data-pp-variant="<?=$vid?>" data-pp-variant-price="<?=$vPrice?>" data-pp-variant-original="<?=$vCompare?>" data-pp-variant-disc="<?=$vDisc?>" role="radio" aria-checked="<?=$isSelected?'true':'false'?>">
              <span class="pp-variant-main">
                <span class="pp-variant-top">
                  <span class="pp-variant-name-wrap">
                    <span class="pp-variant-radio" aria-hidden="true"></span>
                    <b class="pp-variant-name"><?=e($v['name'])?></b>
                  </span>
                  <span class="pp-variant-meta-right">
                    <small class="pp-variant-stock <?=$vStock>0?'is-available':'is-out'?>"><?= $vStock>0 ? (t('Stok').': '.number_format($vStock)) : t('Stok Habis') ?></small>
                    <small class="pp-variant-warranty"><?=e($vWarrantyText)?></small>
                  </span>
                </span>
                <span class="pp-variant-price-row">
                  <strong class="pp-variant-price"><?=rupiah($vPrice)?></strong>
                  <?php if($vCompare>$vPrice): ?><del class="pp-variant-original"><?=rupiah($vCompare)?></del><?php endif; ?>
                  <?php if($vDisc>0): ?><span class="pp-variant-discount">-<?=number_format($vDisc)?>%</span><?php endif; ?>
                </span>
              </span>
            </button>
            <?php endforeach; ?>
          </div>

          <div class="pp-purchase-block">
            <div class="pp-qty-row" aria-label="<?=t('Jumlah')?>">
              <span class="pp-qty-label"><?=t('Jumlah')?></span>
              <div class="pp-qty-stepper">
                <button type="button" data-pp-qty="down" aria-label="−" disabled>−</button>
                <span data-pp-qty-value>1</span>
                <button type="button" data-pp-qty="up" aria-label="+" disabled>+</button>
              </div>
              <span class="pp-qty-selected" data-pp-selected-name><?=t('Pilih varian terlebih dahulu')?></span>
            </div>
            <button type="button" class="pp-buy-now" id="ppBuyNow" disabled><?=t('Pilih varian terlebih dahulu')?></button>
            <p class="pp-cart-hint" data-pp-cart-hint hidden></p>
            <button type="button" class="pp-secondary-btn pp-add-cart" id="ppAddCart" disabled><?=t('Tambah ke Keranjang')?></button>
            <div class="pp-secondary-actions">
              <button type="button" class="pp-secondary-btn" id="ppWishlist" data-wishlist-toggle="<?=intval($product['id'])?>"><span>♡</span> <?=t('Wishlist')?></button>
              <button type="button" class="pp-secondary-btn" id="ppShare"><span aria-hidden="true">↗</span> <?=t('Bagikan')?></button>
            </div>
            <div class="pp-trust-row" aria-label="Keunggulan">
              <span class="pp-trust-item"><span aria-hidden="true">⚡</span><?= $deliveryType==='manual' ? t('Pengiriman Manual') : t('Aktif Instan') ?></span>
              <span class="pp-trust-item"><span aria-hidden="true">🛡</span><?=e($warrantyShort)?></span>
              <span class="pp-trust-item"><span aria-hidden="true">🎧</span><?=t('Support 24/7')?></span>
            </div>
          </div>
        </div>

        <div class="pp-tab-panel" data-pp-panel="reviews" role="tabpanel" id="reviews" hidden>
          <?php if($reviewStats['count']>0): ?>
          <div class="pp-review-summary">
            <b><?=number_format($reviewStats['avg'],1)?> ★</b>
            <span><?=t('dari')?> <?=number_format($reviewStats['count'])?> <?=t('ulasan')?></span>
          </div>
          <?php endif; ?>
          <?php if($reviewOk!==''): ?><div class="alert ok" role="status"><span>✓</span><div><?=e($reviewOk)?></div></div><?php endif; ?>
          <?php if($reviewError!==''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($reviewError)?></div></div><?php endif; ?>

          <div class="pp-review-list">
            <?php if(!$reviews): ?>
              <p class="pp-review-empty"><?=t('Belum ada ulasan')?></p>
            <?php else: foreach($reviews as $r): $rContact=product_review_censor_contact($r['buyer_whatsapp'] ?? null,$r['telegram_username'] ?? null); ?>
              <article class="pp-review-item">
                <div class="pp-review-head">
                  <b><?=e($r['name'])?></b>
                  <span class="pp-review-stars" aria-label="Rating <?=intval($r['rating'])?> dari 5"><?=str_repeat('★',max(1,min(5,(int)$r['rating']))).str_repeat('☆',max(0,5-(int)$r['rating']))?></span>
                  <small><?=e(display_datetime_gmt7($r['created_at']))?></small>
                </div>
                <?php if($rContact!==''): ?><small class="pp-review-contact" title="<?=t('Kontak pembeli terverifikasi (disamarkan)')?>"><?=e($rContact)?></small><?php endif; ?>
                <p><?=e((string)$r['comment'])?></p>
              </article>
            <?php endforeach; endif; ?>
          </div>

          <div class="pp-review-locked">
            <h4><?=t('Hanya pembeli yang dapat mengulas')?></h4>
            <p><?=t('Beri ulasan lewat Lacak Pesanan setelah menerima produk. Ulasan tampil di sini setelah disetujui admin.')?></p>
            <a class="btn primary" href="/track-order.php"><?=t('Lacak Pesanan & Ulas')?></a>
          </div>
        </div>
      </section>
    </aside>

    <?php if($similarProducts): ?>
    <section class="pp-card pp-similar" aria-label="<?=t('Produk Serupa')?>">
      <div class="pp-section-head">
        <h2 class="pp-section-title"><?=t('Produk Serupa')?></h2>
        <a class="pp-similar-more" href="/#produk"><?=t('Lihat Semua')?> →</a>
      </div>
      <?=storefront_product_grid($similarProducts,['show_category'=>true,'show_wishlist'=>true])?>
    </section>
    <?php endif; ?>
  </div>

  <div class="pp-toast" id="ppToast" role="status" aria-live="polite" hidden></div>
</main>
<?php endif; ?>

<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b><?=t('Keranjang Belanja')?> (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span><?=t('Subtotal')?></span><b data-cart-total>Rp 0</b></p><a href="/checkout.php" class="btn primary"><?=t('Lanjut Checkout')?></a></div></aside></div>

<?php if($product): ?>
<script id="productsJson" type="application/json"><?=json_encode([[
    'id'=>(int)$product['id'],
    'name'=>$product['name'],
    'slug'=>$product['slug'],
    'price'=>(float)$basePrice,
    'originalPrice'=>(float)$originalPrice,
    'category'=>$catName,
    'thumbnail'=>$thumbSrc,
]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script>
<script id="frontI18n" type="application/json"><?=json_encode([
    'modalStock'=>t('Pilih varian'),
    'modalCta'=>t('Lihat Produk & Pilih Varian'),
    'maxStock'=>t('Jumlah melebihi stok tersedia'),
], JSON_UNESCAPED_UNICODE)?></script>
<script id="productPageJson" type="application/json"><?=json_encode([
    'id'=>(int)$product['id'],
    'name'=>$product['name'],
    'slug'=>$product['slug'],
    'url'=>'/'.$product['slug'],
    'price'=>(float)$cheapestVariantPrice,
    'originalPrice'=>$heroOriginal,
    'flashActive'=>$flashActive,
    'thumb'=>$thumbSrc,
    'initialVariantId'=>$initialVariantId,
    'variants'=>array_map(fn($v)=>[
        'id'=>(int)$v['id'],
        'name'=>$v['name'],
        'priceDelta'=>(float)$v['price_delta'],
        'price'=>(float)$v['effective_price'],
        'originalPrice'=>(float)($v['compare_price'] ?? 0),
        'discountPct'=>(($v['compare_price'] ?? 0)>(float)$v['effective_price'] && (float)$v['effective_price']>0)?(int)round((1-(float)$v['effective_price']/(float)$v['compare_price'])*100):0,
        'stock'=>(int)$v['stock'],
    ], $variantCards),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script>
<script id="productPageI18n" type="application/json"><?=json_encode([
    'addCart'=>t('Tambah ke Keranjang'),
    'buyNow'=>t('Beli Sekarang'),
    'outOfStock'=>t('Stok Habis'),
    'chooseVariant'=>t('Pilih varian terlebih dahulu'),
    'added'=>t('Ditambahkan ke keranjang'),
    'addedShort'=>t('✓ Ditambahkan'),
    'inCart'=>t('Item di Keranjang'),
    'maxStock'=>t('Jumlah melebihi stok tersedia'),
    'replaceCart'=>t('Beli Sekarang akan mengganti isi keranjang. Lanjutkan?'),
    'telegramLabel'=>t('Beli di Bot Telegram'),
    'shareCopied'=>t('Tautan produk disalin'),
    'shareFailed'=>t('Gagal membagikan tautan'),
], JSON_UNESCAPED_UNICODE)?></script>
<script src="/assets/js/front-mobile-v7.js?v=20261014-reviews-v1"></script>
<script src="/assets/js/product-page.js?v=20261006-product-page-v9"></script>
<?php else: ?>
<script id="productsJson" type="application/json">[]</script>
<script src="/assets/js/front-mobile-v7.js?v=20261014-reviews-v1"></script>
<?php endif; ?>
</body></html>
