<?php
require_once __DIR__.'/includes/helpers.php';
ensure_pages_table();
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$slug=trim((string)($_GET['slug'] ?? ''));
$page=null;
if($slug!==''){
    $st=$pdo->prepare('SELECT * FROM pages WHERE slug=? AND status=1 LIMIT 1');
    $st->execute([$slug]);
    $page=$st->fetch();
}
if(!$page){ http_response_code(404); $page=['title'=>'Halaman tidak ditemukan','content'=>'<p>Halaman yang kamu cari belum tersedia atau sudah dinonaktifkan.</p>','slug'=>'404']; }
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
if(!function_exists('ds_icon')){function ds_icon($name){$n=mb_strtolower((string)$name); if(str_contains($n,'akun')||str_contains($n,'premium')) return '♛'; if(str_contains($n,'book')||str_contains($n,'ebook')) return '▭'; if(str_contains($n,'template')) return '▤'; if(str_contains($n,'tool')) return '⌘'; if(str_contains($n,'voucher')) return '⌁'; return '▦';}}
?>
<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta(['site'=>$site,'title'=>$page['title'].' - '.$site,'description'=>mb_strimwidth(trim(strip_tags((string)$page['content'])),0,160,'...'),'path'=>'/page.php?slug='.$page['slug'],'robots'=>'index, follow']); render_storefront_styles('page'); ?></head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap"><section class="page-hero"><h1><?=e($page['title'])?></h1><p>Informasi resmi dari <?=e($site)?></p></section><article class="static-page-card"><?=safe_footer_html((string)$page['content'])?></article></main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total>Rp 0</b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261014-reviews-v1"></script></body></html>
