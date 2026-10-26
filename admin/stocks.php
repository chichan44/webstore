<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_product_extra_columns();

/* Stok selalu hidup di varian (plan v3):
   - delivery manual  → product_variants.manual_stock (counter admin), available = counter − reservasi pesanan pending
   - delivery instan  → baris product_stocks yang ditandai ke variant_id
   Halaman ini menampilkan keduanya supaya admin tidak perlu membuka form produk. */

$fltProductId=(int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
$fltDelivery=strtolower(trim((string)($_GET['delivery'] ?? $_POST['delivery'] ?? '')));
if(!in_array($fltDelivery,['instant','manual'],true)) $fltDelivery='';
$fltQ=trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$fltStatus=strtolower(trim((string)($_GET['status'] ?? $_POST['status'] ?? '')));
if(!in_array($fltStatus,['available','reserved','sold'],true)) $fltStatus='';

function stocks_filter_query(array $extra=[]): string {
    global $fltProductId,$fltDelivery,$fltQ,$fltStatus;
    $params=array_merge([
        'product_id'=>$fltProductId?:'',
        'delivery'=>$fltDelivery,
        'q'=>$fltQ,
        'status'=>$fltStatus,
    ],$extra);
    $params=array_filter($params,static fn($v)=>$v!=='' && $v!==null);
    return http_build_query($params);
}

/* ---------- Semua varian aktif + stok efektifnya (instan & manual) ---------- */
function stocks_load_variants(PDO $pdo, int $productId, string $delivery, string $q): array {
    $sql='SELECT v.id variant_id, v.product_id, v.name variant_name, v.manual_stock, v.sort_order,
            p.name product_name, p.slug product_slug, p.delivery_type, p.status product_status,
            COALESCE((SELECT COUNT(*) FROM product_stocks s WHERE s.variant_id=v.id AND s.status="available"),0) instant_available,
            COALESCE((SELECT COUNT(*) FROM product_stocks s WHERE s.variant_id=v.id AND s.status="reserved"),0) instant_reserved,
            COALESCE((SELECT COUNT(*) FROM product_stocks s WHERE s.variant_id=v.id AND s.status="sold"),0) instant_sold,
            COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                WHERE oi.variant_id=v.id AND o.status="pending"
                  AND (o.payment_expires_at IS NULL OR o.payment_expires_at>NOW())),0) manual_reserved,
            COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                WHERE oi.variant_id=v.id AND o.status IN ("paid","delivered")),0) sold_qty
        FROM product_variants v
        JOIN products p ON p.id=v.product_id
        WHERE v.status=1';
    $params=[];
    if($productId>0){ $sql.=' AND p.id=?'; $params[]=$productId; }
    if($delivery!==''){ $sql.=' AND p.delivery_type=?'; $params[]=$delivery; }
    if($q!==''){ $sql.=' AND (p.name LIKE ? OR v.name LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; }
    $sql.=' ORDER BY p.name, v.sort_order, v.name';
    $st=$pdo->prepare($sql);
    $st->execute($params);
    $out=[];
    foreach($st->fetchAll() as $row){
        $isManual=((string)$row['delivery_type']==='manual');
        if($isManual){
            $raw=(int)$row['manual_stock'];
            $reserved=max(0,(int)$row['manual_reserved']);
            $available=max(0,$raw-$reserved);
            $sold=max(0,(int)$row['sold_qty']);
        }else{
            $raw=(int)$row['instant_available']+(int)$row['instant_reserved'];
            $reserved=(int)$row['instant_reserved'];
            $available=(int)$row['instant_available'];
            $sold=(int)$row['instant_sold'];
        }
        $row['is_manual']=$isManual;
        $row['stock_available']=$available;
        $row['stock_reserved']=$reserved;
        $row['stock_sold']=$sold;
        $row['stock_raw']=$raw;
        $out[]=$row;
    }
    return $out;
}

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=(string)($_POST['action'] ?? 'add');
    try{
        if($action==='delete'){
            $st=$pdo->prepare('DELETE FROM product_stocks WHERE id=? AND status="available"');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            admin_redirect('/admin/stocks.php'.($fltProductId?'?'.stocks_filter_query():''),$st->rowCount()?'Stok berhasil dihapus.':'Stok tidak ditemukan atau sudah terjual.',$st->rowCount()?'success':'error');
        }
        if($action==='delete_selected' || $action==='delete_all'){
            if($action==='delete_selected'){
                $ids=array_values(array_filter(array_map('intval',$_POST['ids'] ?? [])));
                if(!$ids) throw new RuntimeException('Pilih minimal satu stok tersedia terlebih dahulu.');
                $ph=implode(',',array_fill(0,count($ids),'?'));
                $st=$pdo->prepare("DELETE FROM product_stocks WHERE status='available' AND id IN ($ph)");
                $st->execute($ids);
            }else{
                /* Scoped delete: hanya baris yang cocok dengan filter aktif (produk/tipe/status). */
                $sql='DELETE s FROM product_stocks s
                    JOIN products p ON p.id=s.product_id
                    LEFT JOIN product_variants v ON v.id=s.variant_id
                    WHERE s.status="available"';
                $params=[];
                if($fltProductId>0){ $sql.=' AND s.product_id=?'; $params[]=$fltProductId; }
                if($fltDelivery!==''){ $sql.=' AND p.delivery_type=?'; $params[]=$fltDelivery; }
                if($fltQ!==''){ $sql.=' AND (p.name LIKE ? OR v.name LIKE ?)'; $params[]="%$fltQ%"; $params[]="%$fltQ%"; }
                $st=$pdo->prepare($sql);
                $st->execute($params);
            }
            admin_redirect('/admin/stocks.php'.(stocks_filter_query()!==''?'?'.stocks_filter_query():''),$st->rowCount().' stok tersedia berhasil dihapus.');
        }
        if($action==='update_manual_stock'){
            $variantId=(int)($_POST['variant_id'] ?? 0);
            $qty=max(0,(int)($_POST['manual_stock'] ?? 0));
            $st=$pdo->prepare('SELECT v.id, v.name, p.name product_name, p.delivery_type
                FROM product_variants v JOIN products p ON p.id=v.product_id
                WHERE v.id=? AND v.status=1 LIMIT 1');
            $st->execute([$variantId]);
            $variant=$st->fetch();
            if(!$variant) throw new InvalidArgumentException('Varian tidak ditemukan atau tidak aktif.');
            if((string)$variant['delivery_type']!=='manual') throw new InvalidArgumentException('Stok manual hanya untuk produk dengan pengiriman manual. Produk instan memakai stok digital.');
            $pdo->prepare('UPDATE product_variants SET manual_stock=?, updated_at=NOW() WHERE id=?')->execute([$qty,$variantId]);
            admin_redirect('/admin/stocks.php'.(stocks_filter_query()!==''?'?'.stocks_filter_query():''),'Stok manual varian "'.e($variant['name']).'" disimpan: '.number_format($qty).'.');
        }
        if($action==='add'){
            $products=$pdo->query("SELECT id,name FROM products WHERE delivery_type='instant' AND status=1 ORDER BY name")->fetchAll();
            if(!$products) throw new RuntimeException('Belum ada produk instan aktif. Tambahkan produk instan terlebih dahulu sebelum mengisi stok digital.');
            $productId=(int)($_POST['product_id'] ?? 0);
            $check=$pdo->prepare("SELECT COUNT(*) FROM products WHERE id=? AND delivery_type='instant'");
            $check->execute([$productId]);
            if(!(int)$check->fetchColumn()) throw new InvalidArgumentException('Produk yang dipilih tidak ditemukan atau bukan produk instan.');
            $variantId=(int)($_POST['variant_id'] ?? 0);
            if($variantId<=0) throw new InvalidArgumentException('Varian wajib dipilih — stok digital ditandai ke varian.');
            $vcheck=$pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE id=? AND product_id=? AND status=1");
            $vcheck->execute([$variantId,$productId]);
            if(!(int)$vcheck->fetchColumn()) throw new InvalidArgumentException('Varian yang dipilih tidak ditemukan atau tidak aktif.');
            $contents=trim((string)($_POST['contents'] ?? ''));
            if($contents==='') throw new InvalidArgumentException('Isi stok digital wajib diisi.');
            $lines=preg_split('/\R+/u',$contents) ?: [];
            $lines=array_values(array_unique(array_filter(array_map('trim',$lines),static fn($line)=>$line!=='')));
            if(!$lines) throw new InvalidArgumentException('Tidak ada baris stok yang valid.');
            $pdo->beginTransaction();
            $insert=$pdo->prepare('INSERT INTO product_stocks(product_id,variant_id,content,status,created_at) VALUES(?,?,?,"available",NOW())');
            foreach($lines as $line) $insert->execute([$productId,$variantId,$line]);
            $pdo->commit();
            admin_redirect('/admin/stocks.php'.(stocks_filter_query()!==''?'?'.stocks_filter_query(['variant_id'=>$variantId]):'?variant_id='.$variantId),count($lines).' stok digital berhasil ditambahkan.');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $err=$e->getMessage();
    }
}

$variantRows=stocks_load_variants($pdo,$fltProductId,$fltDelivery,$fltQ);

/* Ringkasan stok digital (baris product_stocks) — dipertahankan untuk status per baris */
$summary=['available'=>0,'reserved'=>0,'sold'=>0,'void'=>0];
$sSql='SELECT s.status,COUNT(*) total FROM product_stocks s JOIN products p ON p.id=s.product_id LEFT JOIN product_variants v ON v.id=s.variant_id WHERE 1';
$sParams=[];
if($fltProductId>0){ $sSql.=' AND s.product_id=?'; $sParams[]=$fltProductId; }
if($fltDelivery!==''){ $sSql.=' AND p.delivery_type=?'; $sParams[]=$fltDelivery; }
if($fltQ!==''){ $sSql.=' AND (p.name LIKE ? OR v.name LIKE ?)'; $sParams[]="%$fltQ%"; $sParams[]="%$fltQ%"; }
$sSql.=' GROUP BY s.status';
$stSum=$pdo->prepare($sSql); $stSum->execute($sParams);
foreach($stSum->fetchAll() as $row) $summary[strtolower((string)$row['status'])]=(int)$row['total'];

/* Total stok efektif seluruh varian (instan + manual) */
$tAvail=0; $tReserved=0; $tSold=0; $tOut=0;
foreach($variantRows as $vr){
    $tAvail+=(int)$vr['stock_available'];
    $tReserved+=(int)$vr['stock_reserved'];
    $tSold+=(int)$vr['stock_sold'];
    if((int)$vr['stock_available']<=0) $tOut++;
}

/* Daftar baris stok digital (instan) — terfilter */
$dSql='SELECT s.*,p.name product,p.delivery_type,v.name variant_name FROM product_stocks s
    LEFT JOIN products p ON p.id=s.product_id
    LEFT JOIN product_variants v ON v.id=s.variant_id
    WHERE 1';
$dParams=[];
if($fltProductId>0){ $dSql.=' AND s.product_id=?'; $dParams[]=$fltProductId; }
if($fltDelivery!==''){ $dSql.=' AND p.delivery_type=?'; $dParams[]=$fltDelivery; }
if($fltQ!==''){ $dSql.=' AND (p.name LIKE ? OR v.name LIKE ?)'; $dParams[]="%$fltQ%"; $dParams[]="%$fltQ%"; }
if($fltStatus!==''){ $dSql.=' AND s.status=?'; $dParams[]=$fltStatus; }
$dSql.=' ORDER BY s.id DESC LIMIT 300';
$stRows=$pdo->prepare($dSql); $stRows->execute($dParams);
$rows=$stRows->fetchAll();

/* Form tambah: produk instan + varian (tetap instan saja, manual memakai counter) */
$instantProducts=$pdo->query("SELECT id,name FROM products WHERE delivery_type='instant' AND status=1 ORDER BY name")->fetchAll();
$variantsByProduct=[];
foreach($pdo->query("SELECT v.id,v.product_id,v.name,v.sort_order,p.name product_name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.status=1 AND p.delivery_type='instant' ORDER BY p.name,v.sort_order,v.name")->fetchAll() as $v){
    $variantsByProduct[(int)$v['product_id']][]=$v;
}
$preselectVariant=(int)($_GET['variant_id'] ?? 0);
$preselectProduct=$fltProductId;
if($preselectVariant>0 && !$preselectProduct){
    foreach($variantsByProduct as $pid=>$list){
        foreach($list as $v){ if((int)$v['id']===$preselectVariant){ $preselectProduct=$pid; break 2; } }
    }
}

admin_page_start('Stok Produk','Stok selalu dihitung per varian. Produk manual memakai counter stok varian; produk instan memakai baris stok digital yang dikirim otomatis.');
?>
<?php if($msg):?><div class="alert ok"><span>✓</span><div><?=e($msg)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>
<?php if($err):?><div class="alert err"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>

<style>
/* ── KPI tiles ──────────────────────────────────────────────────────── */
body.admin-modern.admin-page-stocks .stock-summary-grid{margin:0 0 12px}

/* ── Filter bar ─────────────────────────────────────────────────────── */
body.admin-modern.admin-page-stocks .stock-filter-strip{display:grid;grid-template-columns:2fr 1.2fr 1fr auto;gap:10px;align-items:end;margin:0 0 14px;padding:12px 14px;border:1px solid var(--adm-border);border-radius:14px;background:var(--adm-card)}
body.admin-modern.admin-page-stocks .stock-filter-strip .field{margin:0!important;min-width:0}
body.admin-modern.admin-page-stocks .stock-filter-strip .field label{display:block;margin:0 0 4px;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--adm-muted);line-height:1.2}
body.admin-modern.admin-page-stocks .stock-filter-strip select,
body.admin-modern.admin-page-stocks .stock-filter-strip input{width:100%;height:38px!important;min-height:38px!important;max-height:38px!important;padding:0 10px!important;border:1px solid var(--adm-border);border-radius:9px;font-size:13px;background:var(--adm-card);line-height:38px}
body.admin-modern.admin-page-stocks .stock-filter-actions{display:flex;gap:8px;align-items:center;padding-bottom:0}
body.admin-modern.admin-page-stocks .stock-filter-actions .adm-btn{height:38px;min-height:38px;padding:0 14px;font-size:12.5px;white-space:nowrap}

/* ── Section titles ─────────────────────────────────────────────────── */
body.admin-modern.admin-page-stocks .stock-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:16px 0 8px}
body.admin-modern.admin-page-stocks .stock-section-title h3{font-size:14px;font-weight:820;color:var(--adm-heading)}
body.admin-modern.admin-page-stocks .stock-section-title p{font-size:11.5px;color:var(--adm-muted);margin-top:1px}

/* ── Table cells ────────────────────────────────────────────────────── */
body.admin-modern.admin-page-stocks .variant-stock-avail{font-size:15px;font-weight:850;color:var(--adm-heading)}
body.admin-modern.admin-page-stocks .variant-stock-avail.is-out{color:#c2410c}
body.admin-modern.admin-page-stocks .variant-stock-sub{display:block;font-size:11px;color:var(--adm-muted);margin-top:1px}
body.admin-modern.admin-page-stocks .type-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:800;white-space:nowrap}
body.admin-modern.admin-page-stocks .type-chip.instant{background:#e8f1ff;color:#2857c8}
body.admin-modern.admin-page-stocks .type-chip.manual{background:#fff2e2;color:#b4590b}
body.admin-modern.admin-page-stocks .type-chip.archived{background:#f1f5f9;color:#64748b}
body.admin-modern.admin-page-stocks .stock-quick-form{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
body.admin-modern.admin-page-stocks .stock-quick-form input{width:80px;height:32px;min-height:32px;border:1px solid var(--adm-border);border-radius:8px;padding:0 8px;font-size:13px;background:var(--adm-card)}
body.admin-modern.admin-page-stocks .stock-quick-form button{height:32px;min-height:32px;padding:0 10px;font-size:11.5px}
body.admin-modern.admin-page-stocks .stock-empty-out{color:#c2410c;font-weight:800;font-size:11px}
body.admin-modern.admin-page-stocks .reserved-note{display:block;font-size:10px;color:var(--adm-muted);margin-top:1px}

/* ── Add-stock modal (reuses .product-modal pattern) ────────────────── */
body.admin-modern.admin-page-stocks .stock-modal{position:fixed;inset:0;z-index:120;display:none;align-items:center;justify-content:center;padding:20px;overflow:auto;background:rgba(5,14,29,.58);backdrop-filter:blur(8px)}
body.admin-modern.admin-page-stocks .stock-modal.is-open{display:flex}
body.admin-modern.admin-page-stocks .stock-modal-panel{width:min(560px,100%);max-height:calc(100vh - 40px);overflow:auto;border:1px solid rgba(255,255,255,.3);border-radius:18px;background:var(--adm-card);box-shadow:0 30px 90px rgba(3,10,23,.3)}
body.admin-modern.admin-page-stocks .stock-modal-panel .form-card{margin:0;padding:0 20px 20px;border:0;border-radius:18px;box-shadow:none;background:var(--adm-card)}
body.admin-modern.admin-page-stocks .stock-modal-panel .adm-card-head{position:sticky;top:0;z-index:4;margin:0 -20px 14px;padding:14px 20px;border-radius:18px 18px 0 0;background:var(--adm-card);border-bottom:1px solid var(--adm-border)}
body.admin-modern.admin-page-stocks .stock-modal-panel .adm-card-head h3{font-size:16px;font-weight:820;color:var(--adm-heading)}
body.admin-modern.admin-page-stocks .stock-modal-panel .adm-card-head p{font-size:12px;color:var(--adm-muted);margin-top:2px}
body.admin-modern.admin-page-stocks .stock-modal-close{width:34px;height:34px;padding:0;display:grid;place-items:center;border:1px solid var(--adm-border);border-radius:10px;color:var(--adm-muted);background:var(--adm-card-soft);font-size:20px;cursor:pointer;flex:0 0 auto}
body.admin-modern.admin-page-stocks .stock-modal .field{margin-top:14px}
body.admin-modern.admin-page-stocks .stock-modal .field:first-of-type{margin-top:0}
body.admin-modern.admin-page-stocks .stock-modal .field label{margin:0 0 5px;font-size:11px;font-weight:750;color:var(--adm-heading)}
body.admin-modern.admin-page-stocks .stock-modal .field select,
body.admin-modern.admin-page-stocks .stock-modal .field textarea{width:100%;border:1px solid var(--adm-border);border-radius:10px;font-size:13px;background:var(--adm-card)}
body.admin-modern.admin-page-stocks .stock-modal .field select{height:40px;min-height:40px;padding:0 10px}
body.admin-modern.admin-page-stocks .stock-modal .field textarea{min-height:120px;padding:10px;resize:vertical;line-height:1.5}
body.admin-modern.admin-page-stocks .stock-modal .adm-actions{margin-top:16px;display:flex;gap:8px}

/* ── Bulk bar ───────────────────────────────────────────────────────── */
body.admin-modern.admin-page-stocks .bulk-toolbar{min-height:48px;padding:8px 13px;display:flex;align-items:center;gap:12px;border:1px solid var(--adm-border);border-radius:13px;background:var(--adm-card);margin:0 0 10px}
body.admin-modern.admin-page-stocks .bulk-check{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:740;color:var(--adm-muted)}
body.admin-modern.admin-page-stocks .bulk-check input{width:16px;height:16px}
body.admin-modern.admin-page-stocks .bulk-actions{display:flex;gap:8px;margin-left:auto}
body.admin-modern.admin-page-stocks .bulk-actions .adm-btn{min-height:34px;padding:6px 12px;font-size:11.5px}

/* ── Dark theme ─────────────────────────────────────────────────────── */
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-filter-strip,
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-quick-form input,
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .bulk-toolbar{background:#142033;border-color:#2b3c53}
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-filter-strip select,
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-filter-strip input{background:#101c2e;border-color:#2b3c53;color:#e2e8f0}
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-modal-panel,
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-modal-panel .form-card,
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-modal-panel .adm-card-head{background:#142033;border-color:#2b3c53}
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-modal .field select,
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-modal .field textarea{background:#101c2e;border-color:#2b3c53;color:#e2e8f0}
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .stock-modal{background:rgba(2,7,15,.74)}
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .type-chip.instant{background:rgba(37,99,235,.15);color:#93b4ff}
body.admin-modern[data-admin-theme="dark"].admin-page-stocks .type-chip.manual{background:rgba(217,119,6,.15);color:#fbbf24}

/* ── Responsive ─────────────────────────────────────────────────────── */
@media(max-width:720px){
    body.admin-modern.admin-page-stocks .stock-filter-strip{grid-template-columns:1fr;gap:8px}
    body.admin-modern.admin-page-stocks .stock-filter-actions{width:100%}
    body.admin-modern.admin-page-stocks .stock-filter-actions .adm-btn{flex:1}
    body.admin-modern.admin-page-stocks .bulk-toolbar{flex-direction:column;align-items:stretch}
    body.admin-modern.admin-page-stocks .bulk-actions{margin-left:0;width:100%}
    body.admin-modern.admin-page-stocks .bulk-actions .adm-btn{flex:1}
    body.admin-modern.admin-page-stocks .stock-modal{padding:10px}
    body.admin-modern.admin-page-stocks .stock-modal-panel{max-height:calc(100vh - 20px);border-radius:14px}
    body.admin-modern.admin-page-stocks .stock-modal-panel .form-card{padding:0 14px 14px}
    body.admin-modern.admin-page-stocks .stock-modal-panel .adm-card-head{margin:0 -14px 12px;padding:12px 14px;border-radius:14px 14px 0 0}
}
</style>

<section class="adm-stats stock-summary-grid">
    <div class="adm-stat" style="--tint:#e9f8f1;--color:#119c6b"><div class="adm-stat-icon"><?=admin_icon('layers')?></div><div><span>Stok Tersedia</span><strong><?=number_format($tAvail)?></strong></div></div>
    <div class="adm-stat" style="--tint:#fff7e6;--color:#c47b08"><div class="adm-stat-icon"><?=admin_icon('lock')?></div><div><span>Stok Dipesan</span><strong><?=number_format($tReserved)?></strong></div></div>
    <div class="adm-stat" style="--tint:#eef2f7;--color:#637083"><div class="adm-stat-icon"><?=admin_icon('check-card')?></div><div><span>Stok Terjual</span><strong><?=number_format($tSold)?></strong></div></div>
    <div class="adm-stat" style="--tint:#fdeceb;--color:#c2410c"><div class="adm-stat-icon"><?=admin_icon('help')?></div><div><span>Varian Tanpa Stok</span><strong><?=number_format($tOut)?></strong></div></div>
</section>

<form class="stock-filter-strip" method="get">
    <div class="field"><label for="stockFilterQ">Cari</label><input id="stockFilterQ" type="search" name="q" value="<?=e($fltQ)?>" placeholder="Nama produk atau varian..."></div>
    <div class="field"><label for="stockFilterProduct">Produk</label>
        <select id="stockFilterProduct" name="product_id">
            <option value="">Semua produk</option>
            <?php foreach($pdo->query("SELECT id,name FROM products ORDER BY name")->fetchAll() as $fp):?><option value="<?=$fp['id']?>" <?=$fltProductId===(int)$fp['id']?'selected':''?>><?=e($fp['name'])?></option><?php endforeach;?>
        </select>
    </div>
    <div class="field"><label for="stockFilterDelivery">Tipe</label>
        <select id="stockFilterDelivery" name="delivery">
            <option value="">Semua</option>
            <option value="instant" <?=$fltDelivery==='instant'?'selected':''?>>⚡ Instan</option>
            <option value="manual" <?=$fltDelivery==='manual'?'selected':''?>>✍ Manual</option>
        </select>
    </div>
    <div class="stock-filter-actions">
        <button class="adm-btn primary" type="submit">Terapkan</button>
        <a class="adm-btn soft" href="/admin/stocks.php">Reset</a>
    </div>
</form>

<div class="stock-section-title">
    <div><h3>Stok per Varian</h3><p>Satu-satunya sumber stok. Produk manual memakai counter; produk instan memakai baris digital.</p></div>
    <div style="display:flex;align-items:center;gap:8px">
        <span class="type-chip <?=count($variantRows)>100?'archived':'instant'?>"><?=number_format(count($variantRows))?> varian</span>
        <button class="adm-btn primary" type="button" data-open-stock-modal><?=admin_icon('plus')?>Tambah Stok</button>
    </div>
</div>
<div class="table-wrap responsive-list">
<table>
    <thead><tr><th>Produk</th><th>Varian</th><th>Tipe</th><th>Tersedia</th><th>Dipesan</th><th>Terjual</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if($variantRows):foreach($variantRows as $vr): ?>
        <tr>
            <td data-label="Produk"><div class="cell-value"><b><?=e($vr['product_name'])?></b>
                <?php if(empty($vr['product_status'])):?><span class="type-chip archived">Nonaktif</span><?php endif;?>
                <small><?=e($vr['product_slug'])?></small></div></td>
            <td data-label="Varian"><div class="cell-value"><b><?=e($vr['variant_name'])?></b></div></td>
            <td data-label="Tipe"><div class="cell-value"><span class="type-chip <?=$vr['is_manual']?'manual':'instant'?>"><?=$vr['is_manual']?'✍ Manual':'⚡ Instan'?></span></div></td>
            <td data-label="Tersedia"><div class="cell-value">
                <b class="variant-stock-avail <?=$vr['stock_available']>0?'':'is-out'?>"><?=number_format((int)$vr['stock_available'])?></b>
                <?php if($vr['is_manual']):?>
                    <span class="variant-stock-sub">counter <?=number_format((int)$vr['manual_stock'])?></span>
                <?php else:?>
                    <span class="variant-stock-sub">baris digital</span>
                <?php endif;?>
            </div></td>
            <td data-label="Dipesan"><div class="cell-value"><b><?=number_format((int)$vr['stock_reserved'])?></b>
                <?php if((int)$vr['stock_reserved']>0):?><span class="reserved-note">terkunci pesanan pending</span><?php endif;?>
            </div></td>
            <td data-label="Terjual"><div class="cell-value"><b><?=number_format((int)$vr['stock_sold'])?></b></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions">
                <?php if($vr['is_manual']):?>
                <form class="stock-quick-form" method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="update_manual_stock">
                    <input type="hidden" name="variant_id" value="<?=$vr['variant_id']?>">
                    <input type="hidden" name="product_id" value="<?=$fltProductId?>">
                    <input type="hidden" name="delivery" value="<?=e($fltDelivery)?>">
                    <input type="hidden" name="q" value="<?=e($fltQ)?>">
                    <input class="no-wheel-number" type="number" inputmode="numeric" min="0" step="1" name="manual_stock" value="<?=intval($vr['manual_stock'])?>" aria-label="Stok manual varian <?=e($vr['variant_name'])?>">
                    <button class="adm-btn soft" type="submit">Simpan</button>
                </form>
                <?php else:?>
                    <a class="adm-btn soft" href="/admin/stocks.php?<?=e(http_build_query(array_filter(['product_id'=>$vr['product_id'],'variant_id'=>$vr['variant_id']])) )?>">+ Stok</a>
                <?php endif;?>
            </div></div></td>
        </tr>
    <?php endforeach;else:?>
        <tr><td colspan="7"><div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('layers')?></div><b>Tidak ada varian yang cocok</b><p>Ubah filter di atas, atau tambahkan varian pada halaman Produk.</p></div></div></td></tr>
    <?php endif;?>
    </tbody>
</table>
</div>

<!-- Add-stock modal -->
<div class="stock-modal" id="stockAddModal" aria-hidden="true">
    <div class="stock-modal-panel" role="dialog" aria-modal="true" aria-labelledby="stockModalTitle">
        <form method="post" class="form-card">
            <?=csrf_field()?><input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id_filter" value="<?=$fltProductId?>">
            <div class="adm-card-head" style="display:flex;align-items:flex-start;gap:10px">
                <div style="flex:1"><h3 id="stockModalTitle">Tambah Stok Digital</h3><p>Satu baris = satu stok yang dikirim otomatis ke varian terpilih.</p></div>
                <button class="stock-modal-close" type="button" data-close-stock-modal aria-label="Tutup">×</button>
            </div>
            <div class="field">
                <label>Produk Instan</label>
                <select name="product_id" id="stockProductSelect" <?=$instantProducts?'':'disabled'?>>
                    <?php if(!$instantProducts):?><option value="">Belum ada produk instan aktif</option><?php endif;?>
                    <?php foreach($instantProducts as $product):?><option value="<?=$product['id']?>" data-variant-count="<?=count($variantsByProduct[(int)$product['id']] ?? [])?>" <?=$preselectProduct===(int)$product['id']?'selected':''?>><?=e($product['name'])?></option><?php endforeach;?>
                </select>
            </div>
            <div class="field">
                <label>Varian</label>
                <select name="variant_id" id="stockVariantSelect" <?=$instantProducts?'':'disabled'?>>
                    <option value="">Pilih varian</option>
                    <?php foreach($variantsByProduct as $pid=>$pvlist):?>
                        <optgroup label="<?=e($pvlist[0]['product_name'])?>" data-product-id="<?=$pid?>">
                            <?php foreach($pvlist as $v):?><option value="<?=$v['id']?>" data-product-id="<?=$pid?>" <?=($preselectVariant===(int)$v['id'] && $preselectProduct===$pid)?'selected':''?>><?=e($v['name'])?></option><?php endforeach;?>
                        </optgroup>
                    <?php endforeach;?>
                </select>
                <small>Stok digital selalu ditandai ke varian. Untuk produk manual, atur counter di tabel Stok per Varian.</small>
            </div>
            <div class="field"><label>Isi Stok Digital</label><textarea name="contents" rows="6" placeholder="email:password&#10;kode-lisensi-123&#10;https://link-download.example"><?=e($_POST['contents'] ?? '')?></textarea><small>Satu baris = satu stok. Baris kosong dan duplikat otomatis diabaikan.</small></div>
            <div class="adm-actions">
                <button class="adm-btn primary" type="submit" <?=$instantProducts?'':'disabled'?>><?=admin_icon('plus')?>Tambah Stok</button>
                <button class="adm-btn soft" type="button" data-close-stock-modal>Batal</button>
                <?php if(!$instantProducts):?><a class="adm-btn soft" href="/admin/products.php?add=1">Tambah Produk Dahulu</a><?php endif;?>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    /* ── Product→variant cascade ─────────────────────────────────── */
    const productSel=document.getElementById('stockProductSelect');
    const variantSel=document.getElementById('stockVariantSelect');
    if(productSel&&variantSel){
        function syncVariants(){
            const pid=productSel.value;
            Array.from(variantSel.querySelectorAll('optgroup')).forEach(function(og){
                og.hidden=og.dataset.productId!==pid;
            });
            Array.from(variantSel.querySelectorAll('option[data-product-id]')).forEach(function(opt){
                opt.hidden=opt.dataset.productId!==pid;
            });
            const current=variantSel.value;
            const stillValid=current&&variantSel.querySelector('option[value="'+current+'"]:not([hidden])');
            if(!stillValid) variantSel.value='';
        }
        productSel.addEventListener('change',syncVariants);
        syncVariants();
    }

    /* ── Add-stock modal ─────────────────────────────────────────── */
    const stockModal=document.getElementById('stockAddModal');
    function openStockModal(){
        if(!stockModal) return;
        stockModal.classList.add('is-open');
        stockModal.setAttribute('aria-hidden','false');
        document.body.style.overflow='hidden';
    }
    function closeStockModal(){
        if(!stockModal) return;
        stockModal.classList.remove('is-open');
        stockModal.setAttribute('aria-hidden','true');
        document.body.style.overflow='';
    }
    document.querySelector('[data-open-stock-modal]')?.addEventListener('click',openStockModal);
    stockModal?.querySelectorAll('[data-close-stock-modal]').forEach(function(btn){
        btn.addEventListener('click',closeStockModal);
    });
    stockModal?.addEventListener('click',function(e){
        if(e.target===stockModal) closeStockModal();
    });

    /* Auto-open modal when URL has variant_id preselect */
    <?php if($preselectVariant>0):?>openStockModal();<?php endif;?>

    /* ── Bulk bar reveal ─────────────────────────────────────────── */
    const bulkBar=document.querySelector('.bulk-toolbar');
    const bulkChecks=document.querySelectorAll('.bulk-row-check');
    const bulkAll=document.querySelector('[data-bulk-check-all]');
    function syncBulkBar(){
        if(!bulkBar) return;
        const anyChecked=Array.from(bulkChecks).some(function(c){ return c.checked; })||(bulkAll&&bulkAll.checked);
        bulkBar.style.display=anyChecked?'':'none';
    }
    if(bulkBar) bulkBar.style.display='none';
    bulkChecks.forEach(function(c){ c.addEventListener('change',syncBulkBar); });
    bulkAll?.addEventListener('change',function(){
        bulkChecks.forEach(function(c){ c.checked=bulkAll.checked; });
        syncBulkBar();
    });
})();
</script>

<div class="stock-section-title">
    <div><h3>Baris Stok Digital</h3><p>Setiap baris adalah satu akun/lisensi yang dikirim ke pembeli. Hanya baris <b>Tersedia</b> yang bisa dihapus.</p></div>
    <span class="type-chip instant"><?=number_format(($summary['available'] ?? 0)+($summary['reserved'] ?? 0)+($summary['sold'] ?? 0))?> baris</span>
</div>

<form id="stocksBulkForm" method="post" class="bulk-form">
    <?=csrf_field()?>
    <input type="hidden" name="product_id" value="<?=$fltProductId?>">
    <input type="hidden" name="delivery" value="<?=e($fltDelivery)?>">
    <input type="hidden" name="q" value="<?=e($fltQ)?>">
    <input type="hidden" name="status" value="<?=e($fltStatus)?>">
    <div class="bulk-toolbar">
        <label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="stocksBulkForm"> Pilih semua stok tersedia</label>
        <div class="bulk-actions">
            <button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" onclick="return confirm('Hapus stok tersedia yang dipilih?')">Hapus Terpilih</button>
            <button class="adm-btn danger" type="submit" name="action" value="delete_all" onclick="return confirm('Hapus semua stok tersedia pada filter ini? Stok terjual tetap disimpan.')">Hapus Tersedia (Filter Ini)</button>
        </div>
    </div>
</form>

<div class="table-wrap responsive-list">
<table>
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Produk</th><th>Varian</th><th>Isi Stok</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if($rows):foreach($rows as $stock):?>
        <tr>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><?php if($stock['status']==='available'):?><input form="stocksBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$stock['id']?>"><?php else:?><span class="bulk-muted">-</span><?php endif;?></div></td>
            <td data-label="Produk"><div class="cell-value"><b><?=e($stock['product'] ?: 'Produk dihapus')?></b><small><?=e(display_datetime_gmt7($stock['created_at'] ?? ''))?></small></div></td>
            <td data-label="Varian"><div class="cell-value"><b><?=e($stock['variant_name'] ?: '-')?></b><?php if(empty($stock['variant_name'])):?><span class="stock-empty-out">tanpa varian</span><?php endif;?></div></td>
            <td data-label="Isi Stok"><div class="cell-value"><code><?=e(mb_strimwidth((string)$stock['content'],0,140,'...'))?></code></div></td>
            <td data-label="Status"><div class="cell-value"><?=admin_status_badge($stock['status'])?>
                <?php if($stock['status']==='reserved' && !empty($stock['reserved_order_id'])):?><span class="reserved-note">Order #<?=intval($stock['reserved_order_id'])?><?=!empty($stock['reserved_until'])?' · lepas '.e(display_datetime_gmt7($stock['reserved_until'])):''?></span><?php endif;?>
                <?php if($stock['status']==='sold' && !empty($stock['sold_order_id'])):?><span class="reserved-note">Order #<?=intval($stock['sold_order_id'])?></span><?php endif;?>
            </div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions"><?php if($stock['status']==='available'):?><form method="post" onsubmit="return confirm('Hapus stok ini?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$stock['id']?>"><input type="hidden" name="product_id" value="<?=$fltProductId?>"><input type="hidden" name="delivery" value="<?=e($fltDelivery)?>"><input type="hidden" name="q" value="<?=e($fltQ)?>"><input type="hidden" name="status" value="<?=e($fltStatus)?>"><button class="adm-danger adm-linklike" type="submit">Hapus</button></form><?php else:?><small>Terkunci</small><?php endif;?></div></div></td>
        </tr>
    <?php endforeach;else:?>
        <tr><td colspan="6"><div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('layers')?></div><b>Belum ada stok digital</b><p>Tambahkan stok agar produk instan dapat dibeli.</p></div></div></td></tr>
    <?php endif;?>
    </tbody>
</table>
</div>
<?php admin_page_end(); ?>
