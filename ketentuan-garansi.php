<?php
require_once __DIR__.'/includes/helpers.php';
ensure_product_extra_columns();
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();

/* Katalog untuk drill-down Ketentuan Garansi: produk -> varian -> S&K/garansi */
$catRows=$pdo->query("SELECT c.id,c.name,c.icon,COUNT(p.id) products_count
    FROM categories c
    LEFT JOIN products p ON p.category_id=c.id AND p.status=1 AND COALESCE(p.is_archived,0)=0
    WHERE c.status=1 GROUP BY c.id,c.name,c.icon,c.sort_order ORDER BY c.sort_order,c.name")->fetchAll();
$prodRows=$pdo->query("SELECT p.id,p.name,p.slug,p.thumbnail,p.product_features,p.category_id,c.name category_name,c.icon category_icon
    FROM products p LEFT JOIN categories c ON c.id=p.category_id
    WHERE p.status=1 AND COALESCE(p.is_archived,0)=0 ORDER BY p.name")->fetchAll();
$varRows=$pdo->query('SELECT * FROM product_variants WHERE status=1 ORDER BY sort_order,id')->fetchAll();
$varsByProduct=[];
foreach($varRows as $v){ $varsByProduct[(int)$v['product_id']][]=$v; }

/* Teks S&K/garansi = kolom varian; kosong -> fallback dari data yang sudah ada
   (fitur produk untuk S&K, label/durasi garansi untuk kartu garansi). */
$wtProducts=[];
foreach($prodRows as $p){
    $pid=(int)$p['id'];
    $variants=[];
    foreach(($varsByProduct[$pid] ?? []) as $v){
        $days=product_variant_warranty_days($v,$p);
        $label=product_variant_warranty_label($v,$p);
        if($label===''){ $label=$days>0 ? $days.' Hari' : ''; }
        $tc=trim((string)($v['terms_and_conditions'] ?? ''));
        if($tc==='') $tc=trim((string)($p['product_features'] ?? ''));
        $wt=trim((string)($v['warranty_terms'] ?? ''));
        if($wt===''){
            if($days>0){
                $wt='**'.t('Garansi {label}',['label'=>$label]).'** '.t('terhitung sejak tanggal pembelian')."\n"
                   .t('Klaim garansi dapat diajukan melalui halaman Lacak Pesanan dengan menyertakan nomor invoice, atau hubungi admin toko.');
            }else{
                $wt=t('Varian ini tidak disertai garansi.');
            }
        }
        $variants[]=[
            'id'=>(int)$v['id'],
            'name'=>(string)$v['name'],
            'warrantyLabel'=>$label,
            'warrantyDays'=>$days,
            'termsAndConditions'=>$tc,
            'warrantyTerms'=>$wt,
        ];
    }
    $wtProducts[]=[
        'id'=>$pid,
        'name'=>(string)$p['name'],
        'slug'=>(string)$p['slug'],
        'image'=>(string)$p['thumbnail'],
        'categoryId'=>$p['category_id']!==null?(int)$p['category_id']:null,
        'categoryName'=>(string)($p['category_name'] ?? ''),
        'categoryIcon'=>(string)($p['category_icon'] ?? ''),
        'variants'=>$variants,
    ];
}
$wtCategories=[];
foreach($catRows as $c){
    $wtCategories[]=['id'=>(int)$c['id'],'name'=>(string)$c['name'],'icon'=>(string)($c['icon'] ?? ''),'count'=>(int)$c['products_count']];
}
$wtI18n=[
    'back'=>t('Kembali'),
    'noMatch'=>t('Tidak ada produk cocok.'),
    'clearFilter'=>t('Bersihkan filter'),
    'all'=>t('Semua'),
    'variantCount'=>t('{n} varian'),
    'termsTitle'=>t('Syarat & Ketentuan'),
    'warrantyTitle'=>t('Ketentuan Garansi'),
    'emptyTerms'=>t('Belum ada S&K/garansi khusus untuk varian ini.'),
    'prevPage'=>t('Halaman sebelumnya'),
    'nextPage'=>t('Halaman berikutnya'),
    'warrantyBadge'=>t('Garansi {label}'),
];
$wtPayload=['products'=>$wtProducts,'categories'=>$wtCategories];
?>
<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta(['site'=>$site,'title'=>t('Ketentuan Garansi').' - '.$site,'description'=>t('Syarat dan ketentuan garansi per produk dan varian.'),'path'=>'/ketentuan-garansi.php','robots'=>'index, follow']); render_storefront_styles('page'); ?>
<link rel="stylesheet" href="/assets/css/warranty-terms-v1.css?v=20261113-wt1">
</head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap">
    <section class="page-hero wt-hero">
        <h1><?=ui_icon('shield','wt-hero-icon')?> <?=e(t('Ketentuan Garansi'))?></h1>
        <p><?=e(t('Pilih produk dan varian untuk melihat syarat & ketentuan beserta ketentuan garansi yang berlaku.'))?></p>
    </section>
    <section class="wt-panel" id="wtPanel">
        <div class="wt-search">
            <?=ui_icon('search')?>
            <input type="search" id="wtSearch" placeholder="<?=e(t('Cari produk…'))?>" autocomplete="off">
        </div>
        <div class="wt-chips" id="wtChips"></div>
        <div id="wtView"></div>
        <nav class="wt-pager" id="wtPager" hidden aria-label="<?=e(t('Halaman'))?>"></nav>
    </section>
</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total><?=e(format_money(0))?></b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<script id="productsJson" type="application/json">[]</script>
<script id="wtData" type="application/json"><?=json_encode($wtPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
<script id="wtI18n" type="application/json"><?=json_encode($wtI18n,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
<script src="/assets/js/front-mobile-v7.js?v=20261118-theme1"></script>
<script src="/assets/js/warranty-terms-v1.js?v=20261113-wt1"></script>
</body></html>
