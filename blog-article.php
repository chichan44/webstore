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

/* Artikel terkait: hanya artikel dengan kategori yang sama persis. */
$related=[];
if(!$is404 && !empty($post['category_id'])){
    $st=$pdo->prepare('SELECT p.* FROM blog_posts p
        WHERE p.status=1 AND (p.published_at IS NULL OR p.published_at<=NOW())
          AND p.category_id=? AND p.id<>?
        ORDER BY COALESCE(p.published_at,p.created_at) DESC, p.id DESC LIMIT 3');
    $st->execute([(int)$post['category_id'],(int)$post['id']]);
    $related=$st->fetchAll();
}

/* URL & teks untuk tombol share. */
$shareUrl=seo_url('/blog/'.$post['slug']);
$shareText=$post['title'];
$shareLinks=[
    'whatsapp'=>'https://wa.me/?text='.rawurlencode($shareText.' '.$shareUrl),
    'facebook'=>'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($shareUrl),
    'telegram'=>'https://t.me/share/url?url='.rawurlencode($shareUrl).'&text='.rawurlencode($shareText),
    'x'=>'https://twitter.com/intent/tweet?url='.rawurlencode($shareUrl).'&text='.rawurlencode($shareText),
];

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
<link rel="stylesheet" href="/assets/css/blog-v1.css?v=20261117-share1">
</head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap">
    <article class="blog-article">
        <a class="blog-back" href="/blog.php"><?=ui_icon('chevron-down','blog-back-ico')?> <?=e(t('Semua Artikel'))?></a>
        <h1><?=e($post['title'])?></h1>
        <?php if(!$is404): ?>
        <div class="blog-article-meta">
            <div class="blog-article-meta-main">
                <span><?=e($post['author'])?></span><span>·</span><span><?=e(date('d M Y',$pub))?></span>
                <span class="blog-read"><?=$clockIcon?><?=e(t('{n} min baca', ['n'=>(int)$post['read_time']]))?></span>
            </div>
            <?php if(!empty($post['category_name'])): ?><span class="blog-pill blog-pill-cat"><?=e($post['category_name'])?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="blog-article-cover" style="background-color:<?=e($color)?>">
            <?php if($cover!==''): ?><img src="<?=e($cover)?>" alt="<?=e($post['title'])?>"><?php endif; ?>
        </div>
        <div class="blog-prose"><?=safe_blog_html((string)$post['content'])?></div>

        <?php if(!$is404): ?>
        <!-- Bagikan -->
        <div class="blog-share">
            <span class="blog-share-label">SHARE:</span>
            <a class="blog-share-btn blog-share-wa" href="<?=e($shareLinks['whatsapp'])?>" target="_blank" rel="noopener" aria-label="WhatsApp">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
            </a>
            <a class="blog-share-btn blog-share-fb" href="<?=e($shareLinks['facebook'])?>" target="_blank" rel="noopener" aria-label="Facebook">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073"/></svg>
            </a>
            <a class="blog-share-btn blog-share-tg" href="<?=e($shareLinks['telegram'])?>" target="_blank" rel="noopener" aria-label="Telegram">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42"/></svg>
            </a>
            <a class="blog-share-btn blog-share-x" href="<?=e($shareLinks['x'])?>" target="_blank" rel="noopener" aria-label="X">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/></svg>
            </a>
            <button class="blog-share-btn blog-share-copy" type="button" data-copy-link="<?=e($shareUrl)?>" data-copied-label="<?=e(t('Tautan disalin!'))?>" title="<?=e(t('Salin tautan'))?>" aria-label="<?=e(t('Salin tautan'))?>">
                <?=ui_icon('link','blog-share-ico-link')?><?=ui_icon('check','blog-share-ico-check')?>
            </button>
        </div>
        <?php endif; ?>
    </article>

    <?php if($related): ?>
    <section class="blog-related">
        <h2><?=ui_icon('books','blog-related-ico')?> <?=e(t('Artikel Terkait'))?></h2>
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
<script id="productsJson" type="application/json">[]</script><script src="/assets/js/front-mobile-v7.js?v=20261112-feat1"></script><script src="/assets/js/blog-v1.js?v=20261117-share1"></script></body></html>
