<?php
require_once __DIR__.'/includes/helpers.php';
ensure_blog_tables();
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();

/* Detail artikel blog ala koalastore.digital/blogs/{slug}: kembali · pill kategori · judul ·
   meta author/tanggal/durasi · cover · konten HTML · artikel terkait. */
$slug=trim((string)($_GET['slug'] ?? ''));
$post=null;
if($slug!==''){
    $st=$pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM blog_posts p LEFT JOIN blog_categories c ON c.id=p.category_id
        WHERE p.slug=? AND p.status=1 AND (p.published_at IS NULL OR p.published_at<=NOW()) LIMIT 1');
    $st->execute([$slug]);
    $post=$st->fetch();
}
if(!$post){ http_response_code(404); $post=['title'=>t('Artikel tidak ditemukan'),'content'=>'<p>'.t('Artikel yang kamu cari belum tersedia atau sudah dinonaktifkan.').'</p>','slug'=>'404','excerpt'=>'','cover_image'=>'','cover_color'=>'#6366f1','author'=>'','read_time'=>0,'published_at'=>null,'created_at'=>null,'category_name'=>'','category_id'=>null]; }

$pub=strtotime((string)($post['published_at'] ?: $post['created_at'])) ?: time();
$cover=trim((string)$post['cover_image']);
$color=trim((string)$post['cover_color']) ?: '#6366f1';
$is404=$post['slug']==='404';

$related=[];
if(!$is404){
    $relArgs=[(int)$post['id']];
    $relWhere='p.status=1 AND (p.published_at IS NULL OR p.published_at<=NOW()) AND p.id<>?';
    if(!empty($post['category_id'])){ $relWhere.=' AND p.category_id=?'; $relArgs[]=(int)$post['category_id']; }
    $st=$pdo->prepare("SELECT p.* FROM blog_posts p WHERE $relWhere ORDER BY COALESCE(p.published_at,p.created_at) DESC, p.id DESC LIMIT 3");
    $st->execute($relArgs);
    $related=$st->fetchAll();
    if(!$related && !empty($post['category_id'])){
        $st=$pdo->prepare('SELECT p.* FROM blog_posts p WHERE p.status=1 AND (p.published_at IS NULL OR p.published_at<=NOW()) AND p.id<>? ORDER BY COALESCE(p.published_at,p.created_at) DESC, p.id DESC LIMIT 3');
        $st->execute([(int)$post['id']]);
        $related=$st->fetchAll();
    }
}

$metaTitle=trim((string)($post['meta_title'] ?? '')) ?: $post['title'];
$metaDesc=trim((string)($post['meta_description'] ?? '')) ?: mb_strimwidth(trim(strip_tags((string)($post['excerpt'] ?: $post['content']))),0,160,'...','UTF-8');
$clockIcon=ui_icon('clock');
?>
<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta([
    'site'=>$site,
    'title'=>$metaTitle.' - '.$site,
    'description'=>$metaDesc,
    'path'=>'/blog/'.$post['slug'],
    'type'=>$is404 ? 'website' : 'article',
    'image'=>$cover,
    'robots'=>$is404 ? 'noindex, follow' : 'index, follow',
    'json_ld'=>$is404 ? [] : [[
        '@context'=>'https://schema.org','@type'=>'BlogPosting',
        'headline'=>seo_clean_text($post['title'],110),
        'description'=>$metaDesc,
        'datePublished'=>date('c',$pub),
        'author'=>['@type'=>'Person','name'=>$post['author'] ?: $site],
        'publisher'=>['@type'=>'Organization','name'=>$site],
        'mainEntityOfPage'=>seo_url('/blog/'.$post['slug']),
    ]],
]); render_storefront_styles('page'); ?>
<link rel="stylesheet" href="/assets/css/blog-v1.css?v=20261116-blog1">
</head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap">
    <article class="blog-article">
        <a class="blog-back" href="/blog.php"><?=ui_icon('chevron-down','blog-back-ico')?> <?=e(t('Semua Artikel'))?></a>
        <?php if(!empty($post['category_name'])): ?><span class="blog-pill"><?=e($post['category_name'])?></span><?php endif; ?>
        <h1><?=e($post['title'])?></h1>
        <?php if(!$is404): ?>
        <div class="blog-article-meta">
            <span><?=e($post['author'])?></span><span>·</span><span><?=e(date('d M Y',$pub))?></span>
            <span class="blog-read"><?=$clockIcon?><?=e(t('{n} min baca', ['n'=>(int)$post['read_time']]))?></span>
        </div>
        <?php endif; ?>
        <div class="blog-article-cover" style="background-color:<?=e($color)?>">
            <?php if($cover!==''): ?><img src="<?=e($cover)?>" alt="<?=e($post['title'])?>"><?php endif; ?>
        </div>
        <div class="blog-prose"><?=safe_blog_html((string)$post['content'])?></div>
    </article>

    <?php if($related): ?>
    <section class="blog-related">
        <h2><?=e(t('Artikel Terkait'))?></h2>
        <div class="blog-related-grid">
            <?php foreach($related as $r):
                $rCover=trim((string)$r['cover_image']);
                $rColor=trim((string)$r['cover_color']) ?: '#6366f1';
            ?>
            <a class="blog-related-card" href="/blog/<?=e($r['slug'])?>">
                <div class="blog-related-cover" style="background-color:<?=e($rColor)?>">
                    <?php if($rCover!==''): ?><img src="<?=e($rCover)?>" alt="<?=e($r['title'])?>" loading="lazy"><?php endif; ?>
                </div>
                <p><?=e($r['title'])?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total><?=e(format_money(0))?></b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261112-feat1"></script></body></html>
