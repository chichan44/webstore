<?php
require_once __DIR__.'/includes/helpers.php';
ensure_blog_tables();
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();

/* Blog listing ala koalastore.digital/blogs: pencarian + chip kategori + grid kartu artikel.
   Kartu: cover (fallback warna aksen) · pill kategori · judul · excerpt · meta author/tanggal · durasi baca. */
$q=trim((string)($_GET['q'] ?? ''));
$catSlug=trim((string)($_GET['cat'] ?? ''));
$page=max(1,(int)($_GET['page'] ?? 1));
$perPage=9;

$blogCats=$pdo->query('SELECT * FROM blog_categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();
$activeCat=null;
foreach($blogCats as $bc){ if($catSlug!=='' && $bc['slug']===$catSlug){ $activeCat=$bc; break; } }

$where=['p.status=1','(p.published_at IS NULL OR p.published_at<=NOW())'];
$args=[];
if($q!==''){
    $where[]='(p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)';
    $like='%'.$q.'%'; $args[]=$like; $args[]=$like; $args[]=$like;
}
if($activeCat){ $where[]='p.category_id=?'; $args[]=(int)$activeCat['id']; }
$whereSql=implode(' AND ',$where);

$st=$pdo->prepare("SELECT COUNT(*) FROM blog_posts p WHERE $whereSql");
$st->execute($args);
$total=(int)$st->fetchColumn();
$lastPage=max(1,(int)ceil($total/$perPage));
if($page>$lastPage) $page=$lastPage;
$offset=($page-1)*$perPage;

$st=$pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
    FROM blog_posts p LEFT JOIN blog_categories c ON c.id=p.category_id
    WHERE $whereSql ORDER BY COALESCE(p.published_at,p.created_at) DESC, p.id DESC
    LIMIT $perPage OFFSET $offset");
$st->execute($args);
$posts=$st->fetchAll();

$blogIcon=ui_icon('newspaper');
$clockIcon=ui_icon('clock');
?>
<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta(['site'=>$site,'title'=>t('Blog & Artikel').' - '.$site,'description'=>t('Tips, panduan, dan update terbaru seputar produk digital, bot, dan platform kami.'),'path'=>'/blog.php','robots'=>'index, follow']); render_storefront_styles('page'); ?>
<link rel="stylesheet" href="/assets/css/blog-v1.css?v=20261117-share1">
</head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap">
    <section class="page-hero blog-hero">
        <h1><?=$blogIcon?> <?=e(t('Blog & Artikel'))?></h1>
        <p><?=e(t('Tips, panduan, dan update terbaru seputar produk digital, bot, dan platform kami.'))?></p>
    </section>

    <form class="blog-toolbar" method="get" action="/blog.php" role="search">
        <?php if($activeCat): ?><input type="hidden" name="cat" value="<?=e($activeCat['slug'])?>"><?php endif; ?>
        <label class="blog-search">
            <span class="blog-search-ico"><?=ui_icon('search')?></span>
            <input type="search" name="q" value="<?=e($q)?>" placeholder="<?=e(t('Cari artikel…'))?>" autocomplete="off">
        </label>
    </form>

    <nav class="blog-chips" aria-label="<?=e(t('Kategori'))?>">
        <a class="blog-chip<?=$activeCat===null?' active':''?>" href="/blog.php<?=($q!==''?'?q='.rawurlencode($q):'')?>"><?=e(t('Semua'))?></a>
        <?php foreach($blogCats as $bc): ?>
        <a class="blog-chip<?=$activeCat && $activeCat['id']===$bc['id']?' active':''?>" href="/blog.php?cat=<?=e($bc['slug'])?><?=($q!==''?'&q='.rawurlencode($q):'')?>"><?=e($bc['name'])?></a>
        <?php endforeach; ?>
    </nav>

    <?php if(!$posts): ?>
    <div class="blog-empty">
        <span class="blog-empty-ico"><?=$blogIcon?></span>
        <b><?=e($q!==''||$activeCat ? t('Artikel tidak ditemukan') : t('Belum ada artikel'))?></b>
        <small><?=e($q!==''||$activeCat ? t('Coba kata kunci lain atau pilih kategori “Semua”.') : t('Artikel terbaru akan tampil di sini.'))?></small>
    </div>
    <?php else: ?>
    <div class="blog-grid">
        <?php foreach($posts as $p):
            $cover=trim((string)$p['cover_image']);
            $color=trim((string)$p['cover_color']) ?: '#6366f1';
            $pub=strtotime((string)($p['published_at'] ?: $p['created_at'])) ?: time();
            $excerpt=trim(strip_tags((string)$p['excerpt'] ?: (string)$p['content']));
        ?>
        <a class="blog-card" href="/blog/<?=e($p['slug'])?>">
            <div class="blog-card-cover" style="background-color:<?=e($color)?>">
                <?php if($cover!==''): ?><img src="<?=e($cover)?>" alt="<?=e($p['title'])?>" loading="lazy"><?php endif; ?>
            </div>
            <div class="blog-card-body">
                <?php if(!empty($p['category_name'])): ?><span class="blog-pill"><?=e($p['category_name'])?></span><?php endif; ?>
                <h3><?=e($p['title'])?></h3>
                <p><?=e(mb_strimwidth($excerpt,0,140,'…','UTF-8'))?></p>
                <div class="blog-card-meta">
                    <span><?=e($p['author'])?> · <?=e(date('d M Y',$pub))?></span>
                    <span class="blog-read"><?=$clockIcon?><?=e(t('{n} min', ['n'=>(int)$p['read_time']]))?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if($lastPage>1): $qs=[]; if($q!=='')$qs['q']=$q; if($activeCat)$qs['cat']=$activeCat['slug'];
        $pageUrl=function(int $pg) use($qs){ $qs['page']=$pg; return '/blog.php?'.http_build_query($qs); }; ?>
    <nav class="blog-pagination" aria-label="<?=e(t('Navigasi halaman'))?>">
        <?php if($page>1): ?><a class="blog-page-btn" href="<?=$pageUrl($page-1)?>"><?=ui_icon('chevron-down','blog-page-prev')?> <?=e(t('Sebelumnya'))?></a><?php endif; ?>
        <span class="blog-page-info"><?=e(t('Halaman {page} dari {total}', ['page'=>$page,'total'=>$lastPage]))?></span>
        <?php if($page<$lastPage): ?><a class="blog-page-btn" href="<?=$pageUrl($page+1)?>"><?=e(t('Berikutnya'))?> <?=ui_icon('chevron-down','blog-page-next')?></a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total><?=e(format_money(0))?></b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261118-theme1"></script><script src="/assets/js/blog-v1.js?v=20261117-share1"></script></body></html>
