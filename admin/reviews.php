<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_product_reviews_table();

$view=(string)($_GET['view'] ?? 'pending');
if(!in_array($view,['pending','approved','rejected','all'],true)) $view='pending';
$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=(string)($_POST['action'] ?? '');
    try{
        if(in_array($action,['approve','reject'],true)){
            $ids=[(int)($_POST['id'] ?? 0)];
        }elseif(in_array($action,['approve_selected','reject_selected'],true)){
            $ids=array_values(array_unique(array_filter(array_map('intval',$_POST['ids'] ?? []))));
            if(!$ids) throw new RuntimeException('Pilih minimal satu ulasan terlebih dahulu.');
        }else{
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        $ids=array_values(array_filter($ids));
        if(!$ids) throw new RuntimeException('Ulasan tidak ditemukan.');
        $status=in_array($action,['approve','approve_selected'],true)?'approved':'rejected';
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $st=$pdo->prepare("SELECT DISTINCT product_id FROM product_reviews WHERE id IN ($ph)");
        $st->execute($ids);
        $touchedProductIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
        $st=$pdo->prepare("UPDATE product_reviews SET status=?, moderated_at=NOW() WHERE id IN ($ph)");
        $st->execute(array_merge([$status],$ids));
        /* Keep products.rating in sync with approved review average (PDP + catalog) */
        if($touchedProductIds){
            $avgSt=$pdo->prepare("UPDATE products p LEFT JOIN (SELECT product_id,AVG(rating) avg_rating FROM product_reviews WHERE status='approved' GROUP BY product_id) r ON r.product_id=p.id SET p.rating=COALESCE(ROUND(r.avg_rating,1), p.rating) WHERE p.id=?");
            foreach($touchedProductIds as $pid){ $avgSt->execute([$pid]); }
        }
        $label=$status==='approved'?'disetujui':'ditolak';
        $redirectView=$status==='approved'?'approved':'rejected';
        admin_redirect('/admin/reviews.php?view='.$redirectView,$st->rowCount().' ulasan berhasil '.$label.'.');
    }catch(Throwable $e){
        $err=$e->getMessage();
    }
}

$counts=['pending'=>0,'approved'=>0,'rejected'=>0];
foreach($pdo->query('SELECT status,COUNT(*) total FROM product_reviews GROUP BY status')->fetchAll() as $row){
    if(isset($counts[$row['status']])) $counts[$row['status']]=(int)$row['total'];
}
$counts['all']=array_sum($counts);

if($view==='all'){
    $st=$pdo->query('SELECT r.*,p.name product_name,p.slug product_slug FROM product_reviews r LEFT JOIN products p ON p.id=r.product_id ORDER BY r.id DESC LIMIT 500');
}else{
    $st=$pdo->prepare('SELECT r.*,p.name product_name,p.slug product_slug FROM product_reviews r LEFT JOIN products p ON p.id=r.product_id WHERE r.status=? ORDER BY r.id DESC LIMIT 500');
    $st->execute([$view]);
}
$rows=$st->fetchAll();

admin_page_start('Ulasan Produk','Moderasi ulasan dari Lacak Pesanan. Rating produk mengikuti rata-rata ulasan yang disetujui.');
?>
<?php if($msg):?><div class="alert ok"><span>✓</span><div><?=e($msg)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>
<?php if($err):?><div class="alert err"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>

<style>
body.admin-modern.admin-page-reviews .adm-page-head{margin-bottom:16px}
.review-filter-tabs{display:flex;align-items:center;gap:4px;flex-wrap:wrap;padding:0 4px;margin:0 0 12px;border-bottom:1px solid var(--adm-border)}
.review-filter-tab{min-height:46px;padding:10px 15px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-bottom:2px solid transparent;border-radius:0;color:#64748b;background:transparent;font-size:13px;font-weight:760;text-decoration:none;transition:.16s ease}
.review-filter-tab:hover{color:#2f63f5;background:#f8faff}
.review-filter-tab b{min-width:23px;padding:3px 7px;border-radius:999px;background:#eef2f7;color:#728197;font-size:10.5px;font-weight:800;text-align:center}
.review-filter-tab.is-active{color:#2f63f5;border-bottom-color:#2f63f5;background:transparent}
.review-filter-tab.is-active b{color:#2f63f5;background:#e7efff}
.review-bulk-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 12px;padding:12px 16px;border:1px solid var(--adm-border);border-radius:14px;background:#fff}
.review-bulk-bar label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:740;color:#526176}
.review-bulk-bar .adm-btn{min-height:40px;padding:8px 13px;border-radius:10px;font-size:12px;font-weight:740}
.review-card{border:1px solid var(--adm-border);border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:10px;box-shadow:0 6px 18px rgba(15,23,42,.03)}
.review-card-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.review-card-head b{font-size:14px;color:var(--adm-heading)}
.review-card-head .review-stars{color:#d98b00;font-size:13px;letter-spacing:1px}
.review-card-head small{color:#8390a3;font-size:11.5px}
.review-card-head .review-status{margin-left:auto}
.review-status{padding:4px 10px;border-radius:999px;font-size:11px;font-weight:780}
.review-status.pending{color:#b26700;background:#fff6e9;border:1px solid #f3d6aa}
.review-status.approved{color:#157149;background:#edf9f1;border:1px solid #cde9d8}
.review-status.rejected{color:#c2410c;background:#fff4f5;border:1px solid #f4d2d8}
.review-product{display:inline-flex;align-items:center;gap:6px;color:#2f63f5;font-size:12.5px;font-weight:740;text-decoration:none;margin-bottom:6px}
.review-product:hover{text-decoration:underline}
.review-card p{margin:0 0 10px;color:var(--adm-heading);font-size:13.5px;line-height:1.6}
.review-card-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:#8390a3;font-size:11.5px;margin-bottom:10px}
.review-card-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.review-card-actions form{display:inline-flex;margin:0}
.review-card-actions .adm-btn{min-height:38px;padding:8px 14px;border-radius:10px;font-size:12px;font-weight:740}
.review-approve{color:#157149!important;background:#edf9f1!important;border-color:#cde9d8!important}
.review-reject{color:#c2410c!important;background:#fff4f5!important;border-color:#f4d2d8!important}
body.admin-modern[data-admin-theme="dark"].admin-page-reviews .review-filter-tab,
body.admin-modern[data-admin-theme="dark"].admin-page-reviews .review-bulk-bar,
body.admin-modern[data-admin-theme="dark"].admin-page-reviews .review-card{background:#142033;border-color:#2b3c53}
body.admin-modern[data-admin-theme="dark"].admin-page-reviews .review-card p{color:#e2e8f0}
@media(max-width:640px){
    .review-filter-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding:0;border-bottom:0;gap:7px}
    .review-filter-tab{border:1px solid var(--adm-border);border-radius:11px;justify-content:space-between}
    .review-filter-tab.is-active{border-color:#aac1ff;background:#f5f8ff}
    .review-bulk-bar{flex-direction:column;align-items:stretch}
    .review-bulk-bar .adm-actions{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:8px}
}
</style>

<div class="review-filter-tabs" aria-label="Filter ulasan">
    <a class="review-filter-tab <?=$view==='pending'?'is-active':''?>" href="/admin/reviews.php?view=pending"><span>Menunggu</span><b><?=$counts['pending']?></b></a>
    <a class="review-filter-tab <?=$view==='approved'?'is-active':''?>" href="/admin/reviews.php?view=approved"><span>Disetujui</span><b><?=$counts['approved']?></b></a>
    <a class="review-filter-tab <?=$view==='rejected'?'is-active':''?>" href="/admin/reviews.php?view=rejected"><span>Ditolak</span><b><?=$counts['rejected']?></b></a>
    <a class="review-filter-tab <?=$view==='all'?'is-active':''?>" href="/admin/reviews.php?view=all"><span>Semua</span><b><?=$counts['all']?></b></a>
</div>

<form id="reviewsBulkForm" method="post" class="review-bulk-bar">
    <?=csrf_field()?>
    <input type="hidden" name="view" value="<?=e($view)?>">
    <label><input type="checkbox" data-bulk-check-all data-bulk-target="reviewsBulkForm"> Pilih semua</label>
    <div class="adm-actions" style="display:flex;gap:8px;margin-left:auto;flex-wrap:wrap">
        <button class="adm-btn soft review-approve" type="submit" name="action" value="approve_selected">Setujui Terpilih</button>
        <button class="adm-btn soft review-reject" type="submit" name="action" value="reject_selected">Tolak Terpilih</button>
    </div>
</form>

<?php if(!$rows): ?>
<div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('star')?></div><b>Belum ada ulasan</b><p><?=$view==='pending'?'Ulasan baru dari halaman produk akan muncul di sini.':'Tidak ada ulasan pada filter ini.'?></p></div></div>
<?php else: foreach($rows as $r): ?>
<div class="review-card">
    <div class="review-card-head">
        <b><?=e($r['name'])?></b>
        <span class="review-stars" aria-label="Rating <?=intval($r['rating'])?> dari 5"><?=str_repeat('★',max(1,min(5,(int)$r['rating']))).str_repeat('☆',max(0,5-(int)$r['rating']))?></span>
        <small><?=e(display_datetime_gmt7($r['created_at'] ?? ''))?></small>
        <?php if(!empty($r['order_id'])): ?><span class="review-status approved">Pembeli</span><?php endif; ?>
        <span class="review-status <?=e($r['status'])?>"><?=e($r['status'])?></span>
    </div>
    <?php if(!empty($r['product_slug'])): ?><a class="review-product" href="/<?=e($r['product_slug'])?>" target="_blank" rel="noopener"><?=admin_icon('package')?><?=e($r['product_name'] ?: 'Produk dihapus')?></a>
    <?php else: ?><span class="review-product" style="color:#8390a3"><?=e($r['product_name'] ?: 'Produk dihapus')?></span><?php endif; ?>
    <p><?=e((string)$r['comment'])?></p>
    <div class="review-card-meta">
        <span>IP: <?=e($r['ip'] ?: '-')?></span>
        <span>ID: <?=intval($r['id'])?></span>
        <?php if(!empty($r['moderated_at'])): ?><span>Dimoderasi: <?=e(display_datetime_gmt7($r['moderated_at']))?></span><?php endif; ?>
    </div>
    <div class="review-card-actions">
        <input form="reviewsBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$r['id']?>">
        <?php if($r['status']!=='approved'): ?>
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="adm-btn soft review-approve" type="submit">Setujui</button></form>
        <?php endif; ?>
        <?php if($r['status']!=='rejected'): ?>
        <form method="post" onsubmit="return confirm('Tolak ulasan ini?')"><?=csrf_field()?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="adm-btn soft review-reject" type="submit">Tolak</button></form>
        <?php endif; ?>
        <?php if(!empty($r['product_slug'])): ?><a class="adm-btn soft" href="/<?=e($r['product_slug'])?>#reviews" target="_blank" rel="noopener">Lihat di Produk</a><?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>
<?php admin_page_end(); ?>
