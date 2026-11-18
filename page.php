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
/* Konten CMS lama sering mengulang judul & baris "Last updated" di awal konten —
   buang agar judul di area header tidak tampil dua kali. */
$contentHtml=(string)$page['content'];
$titleKey=preg_replace('/\s+/u',' ',mb_strtolower(trim((string)$page['title'])));
for($n=0;$n<3;$n++){
    $contentHtml=preg_replace('/^\s+/u','',$contentHtml);
    if(preg_match('/^<(h[1-6])\b[^>]*>(.*?)<\/\1>/isu',$contentHtml,$m)){
        if(preg_replace('/\s+/u',' ',mb_strtolower(trim(html_entity_decode(strip_tags($m[2])))))!==$titleKey)break;
        $contentHtml=substr($contentHtml,strlen($m[0]));
        continue;
    }
    if(preg_match('/^<p\b[^>]*>(.*?)<\/p>/isu',$contentHtml,$m)){
        if(!preg_match('/^(last\s*updated|terakhir\s*diperbarui|diperbarui)\b/iu',trim(html_entity_decode(strip_tags($m[1])))))break;
        $contentHtml=substr($contentHtml,strlen($m[0]));
        continue;
    }
    break;
}
$pageUpdatedTs=strtotime((string)($page['updated_at']??''))?:strtotime((string)($page['created_at']??''))?:0;
?>
<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta(['site'=>$site,'title'=>$page['title'].' - '.$site,'description'=>mb_strimwidth(trim(strip_tags((string)$page['content'])),0,160,'...'),'path'=>'/page.php?slug='.$page['slug'],'robots'=>'index, follow']); render_storefront_styles('page'); ?></head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap"><article class="static-page-article"><header class="static-page-head"><span class="static-page-ico" aria-hidden="true"><?=ui_icon('file-text')?></span><h1><?=e($page['title'])?></h1></header><?php if($pageUpdatedTs>0): ?><p class="static-page-meta"><?=ui_icon('clock')?><?=e(t('Terakhir diperbarui: {date}',['date'=>date('d M Y',$pageUpdatedTs)]))?></p><?php endif; ?><div class="static-page-prose"><?=safe_footer_html($contentHtml)?></div></article></main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total><?=e(format_money(0))?></b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261118-theme1"></script></body></html>
