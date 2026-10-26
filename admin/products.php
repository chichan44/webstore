<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_product_extra_columns();

function admin_ensure_product_archive_column(PDO $pdo): bool {
    try {
        $st=$pdo->query("SHOW COLUMNS FROM products LIKE 'is_archived'");
        if($st && $st->fetch()) return true;
        $pdo->exec("ALTER TABLE products ADD is_archived TINYINT(1) NOT NULL DEFAULT 0");
        return true;
    } catch(Throwable $e) {
        return false;
    }
}

$archiveEnabled=admin_ensure_product_archive_column($pdo);
$view=(string)($_GET['view'] ?? 'all');
if(!in_array($view,['all','active','archived','out'],true)) $view='all';

$msg='';
$err='';
$cats=$pdo->query('SELECT * FROM categories ORDER BY sort_order,name')->fetchAll();

function delete_product_thumbnail_file(?string $thumb): void {
    if(!$thumb || !str_starts_with($thumb,'/uploads/products/')) return;
    $base=realpath(__DIR__.'/../uploads/products');
    if(!$base) return;
    $file=$base.'/'.basename($thumb);
    if(is_file($file)) @unlink($file);
}

/** Reserved slug prefixes/names that must never route to a product page. */
function admin_slug_is_reserved(string $slug): bool {
    $slug=strtolower(trim($slug));
    if($slug==='') return false;
    $reserved=['admin','api','assets','uploads','includes','install','cron','database','lang','css','js','product','page','ss','index','checkout','wishlist','track-order','login'];
    if(in_array($slug,$reserved,true)) return true;
    // Any existing root PHP entry point filename (without .php)
    foreach(glob(__DIR__.'/../*.php') ?: [] as $file){
        if(strtolower(basename($file,'.php'))===$slug) return true;
    }
    return false;
}

function admin_delete_products(PDO $pdo,array $ids): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
    if(!$ids) return ['deleted'=>0,'skipped'=>0,'thumbs'=>[]];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT id,thumbnail FROM products WHERE id IN ($ph)");
    $st->execute($ids);
    $products=$st->fetchAll();
    if(!$products) return ['deleted'=>0,'skipped'=>0,'thumbs'=>[]];
    $foundIds=array_map('intval',array_column($products,'id'));
    $phFound=implode(',',array_fill(0,count($foundIds),'?'));
    $ref=$pdo->prepare("SELECT DISTINCT product_id FROM order_items WHERE product_id IN ($phFound)");
    $ref->execute($foundIds);
    $referenced=array_map('intval',array_column($ref->fetchAll(),'product_id'));
    $deletable=array_values(array_diff($foundIds,$referenced));
    if(!$deletable) return ['deleted'=>0,'skipped'=>count($referenced),'thumbs'=>[]];
    $phDelete=implode(',',array_fill(0,count($deletable),'?'));
    $thumbs=[];
    foreach($products as $product){
        if(in_array((int)$product['id'],$deletable,true) && !empty($product['thumbnail'])) $thumbs[]=$product['thumbnail'];
    }
    $pdo->prepare("DELETE FROM product_stocks WHERE product_id IN ($phDelete)")->execute($deletable);
    $pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($phDelete)")->execute($deletable);
    $pdo->prepare("DELETE FROM product_reviews WHERE product_id IN ($phDelete)")->execute($deletable);
    $st=$pdo->prepare("DELETE FROM products WHERE id IN ($phDelete)");
    $st->execute($deletable);
    return ['deleted'=>$st->rowCount(),'skipped'=>count($referenced),'thumbs'=>$thumbs];
}

/** Load ALL variants (active + inactive) so admins can toggle status on edit. */
function admin_product_variants(PDO $pdo,int $productId): array {
    $st=$pdo->prepare('SELECT * FROM product_variants WHERE product_id=? ORDER BY sort_order,id');
    $st->execute([$productId]);
    return $st->fetchAll();
}

/** Parse variant_rows[] POST payload into normalized rows (keeps posted order). */
function admin_parse_variant_rows(array $raw): array {
    $rows=[];
    if(!is_array($raw)) return $rows;
    foreach($raw as $key=>$row){
        if(!is_array($row)) continue;
        $warrantyLabel=trim((string)($row['warranty_label'] ?? ''));
        $warrantyDays=max(0,min(3650,(int)($row['warranty_days'] ?? 0)));
        if($warrantyDays<=0) $warrantyDays=warranty_days_from_label($warrantyLabel);
        $rows[]=[
            'key'=>(string)$key,
            'id'=>(int)($row['id'] ?? 0),
            'name'=>trim((string)($row['name'] ?? '')),
            'price'=>max(0.0,(float)($row['price'] ?? 0)),
            'discount_pct'=>max(0.0,min(100.0,(float)($row['discount_pct'] ?? 0))),
            'flash_discount_pct'=>max(0.0,min(100.0,(float)($row['flash_discount_pct'] ?? 0))),
            'warranty_label'=>mb_substr($warrantyLabel,0,100),
            'warranty_days'=>$warrantyDays,
            'manual_stock'=>max(0,(int)($row['manual_stock'] ?? 0)),
            'sort_order'=>(int)($row['sort_order'] ?? 0),
            'status'=>isset($row['status'])?1:0,
            'remove'=>isset($row['remove']),
        ];
    }
    return $rows;
}

/** Harga jual varian dari harga reguler + diskon persen. */
function admin_variant_sale_price(float $regular, float $pct): float {
    $regular=max(0.0,$regular);
    $pct=max(0.0,min(100.0,$pct));
    if($pct<=0 || $regular<=0) return $regular;
    return max(0.0, round($regular * (1 - $pct/100), 2));
}

/**
 * Render satu baris varian. Dipakai untuk baris edit, baris kosong, dan <template>
 * (key '__IDX__') supaya ketiganya tidak pernah berbeda.
 * Semua harga/diskon/garansi ada di varian — produk hanya induk.
 */
function admin_variant_row_html(string $vrKey, array $vr, int $vrId): string {
    $name=e($vr['name'] ?? '');
    $price=e($vr['price'] ?? 0);
    $disc=e($vr['discount_pct'] ?? 0);
    $flash=e($vr['flash_discount_pct'] ?? 0);
    $wLabel=e($vr['warranty_label'] ?? '');
    $wDays=e($vr['warranty_days'] ?? 0);
    $stock=e($vr['manual_stock'] ?? 0);
    $sort=e($vr['sort_order'] ?? 0);
    $checked=($vr['status'] ?? 1)?'checked':'';
    $ph=e($vrKey);
    return '<div class="variant-row" data-variant-row>'
        .'<input type="hidden" name="variant_rows['.$ph.'][id]" value="'.$vrId.'">'
        .'<label class="variant-cell variant-cell-name"><span>Nama varian</span>'
        .'<input class="variant-name" type="text" name="variant_rows['.$ph.'][name]" maxlength="120" placeholder="Contoh: 1 Bulan" value="'.$name.'">'
        .'<small class="variant-preview" data-variant-preview>Harga jual: —</small>'
        .'</label>'
        .'<label class="variant-cell variant-cell-price"><span>Harga</span>'
        .'<input class="no-wheel-number variant-price" type="number" inputmode="decimal" min="0" step="1" name="variant_rows['.$ph.'][price]" placeholder="0" value="'.$price.'">'
        .'</label>'
        .'<label class="variant-cell variant-cell-disc"><span>Diskon %</span>'
        .'<input class="no-wheel-number variant-disc" type="number" inputmode="decimal" min="0" max="100" step="0.5" name="variant_rows['.$ph.'][discount_pct]" placeholder="0" value="'.$disc.'">'
        .'</label>'
        .'<label class="variant-cell variant-cell-flash"><span>Flash %</span>'
        .'<input class="no-wheel-number variant-flash" type="number" inputmode="decimal" min="0" max="100" step="0.5" name="variant_rows['.$ph.'][flash_discount_pct]" placeholder="0" value="'.$flash.'">'
        .'</label>'
        .'<div class="variant-cell variant-cell-warranty"><span>Garansi</span>'
        .'<div class="variant-warranty-pair">'
        .'<input class="variant-warranty-label" type="text" name="variant_rows['.$ph.'][warranty_label]" maxlength="100" placeholder="7 Hari" value="'.$wLabel.'">'
        .'<input class="no-wheel-number variant-warranty-days" type="number" inputmode="numeric" min="0" max="3650" step="1" name="variant_rows['.$ph.'][warranty_days]" placeholder="7" value="'.$wDays.'" aria-label="Lama garansi (hari)">'
        .'</div></div>'
        .'<label class="variant-cell variant-manual-stock"><span>Stok manual</span>'
        .'<input class="no-wheel-number" type="number" inputmode="numeric" min="0" step="1" name="variant_rows['.$ph.'][manual_stock]" placeholder="0" value="'.$stock.'">'
        .'</label>'
        .'<label class="variant-cell variant-cell-sort"><span>Urutan</span>'
        .'<input class="no-wheel-number" type="number" inputmode="numeric" name="variant_rows['.$ph.'][sort_order]" placeholder="0" value="'.$sort.'">'
        .'</label>'
        .'<label class="variant-check"><input type="checkbox" name="variant_rows['.$ph.'][status]" '.$checked.'> Aktif</label>'
        .'<label class="variant-check variant-remove"><input type="checkbox" name="variant_rows['.$ph.'][remove]" value="1"> Hapus</label>'
        .'</div>';
}

$edit=null;
if(isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT * FROM products WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit=$st->fetch() ?: null;
    if(!$edit) $err='Produk yang ingin diedit tidak ditemukan.';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=(string)($_POST['action'] ?? 'save');

    if(in_array($action,['archive','archive_selected','unarchive','unarchive_selected'],true)){
        try{
            if(!$archiveEnabled) throw new RuntimeException('Fitur arsip belum dapat diaktifkan pada database.');
            $archiveValue=in_array($action,['archive','archive_selected'],true)?1:0;
            if(in_array($action,['archive','unarchive'],true)){
                $ids=[(int)($_POST['id'] ?? 0)];
            }else{
                $ids=array_values(array_unique(array_filter(array_map('intval',$_POST['ids'] ?? []))));
                if(!$ids) throw new RuntimeException('Pilih minimal satu produk terlebih dahulu.');
            }
            $ids=array_values(array_filter($ids));
            if(!$ids) throw new RuntimeException('Produk tidak ditemukan.');
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $params=array_merge([$archiveValue],$ids);
            $st=$pdo->prepare("UPDATE products SET is_archived=? WHERE id IN ($ph)");
            $st->execute($params);
            $message=$archiveValue ? ($st->rowCount().' produk berhasil diarsipkan.') : ($st->rowCount().' produk berhasil dipulihkan dari arsip.');
            $redirectView=$archiveValue?'all':'archived';
            admin_redirect('/admin/products.php?view='.$redirectView,$message);
        }catch(Throwable $e){
            $err=$e->getMessage();
        }
    }elseif(in_array($action,['delete','delete_selected','delete_all'],true)){
        try{
            if($action==='delete') $ids=[(int)($_POST['id'] ?? 0)];
            elseif($action==='delete_selected'){
                $ids=array_values(array_filter(array_map('intval',$_POST['ids'] ?? [])));
                if(!$ids) throw new RuntimeException('Pilih minimal satu produk terlebih dahulu.');
            }else{
                $deleteAllSql=$archiveEnabled?'SELECT id FROM products WHERE is_archived=0':'SELECT id FROM products';
                $ids=array_map('intval',array_column($pdo->query($deleteAllSql)->fetchAll(),'id'));
            }
            $pdo->beginTransaction();
            $result=admin_delete_products($pdo,$ids);
            $pdo->commit();
            foreach($result['thumbs'] as $thumb) delete_product_thumbnail_file($thumb);
            if($result['deleted']===0 && $result['skipped']>0){
                admin_redirect('/admin/products.php','Produk tidak dapat dihapus karena sudah digunakan pada riwayat pesanan. Arsipkan produk agar tidak tampil di daftar utama dan halaman index.','error');
            }
            $message=$result['deleted'].' produk berhasil dihapus.';
            if($result['skipped']>0) $message.=' '.$result['skipped'].' produk dilewati karena memiliki riwayat pesanan.';
            admin_redirect('/admin/products.php',$message);
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            $err=$e->getMessage();
        }
    }else{
        $newThumb=null;
        try{
            $id=(int)($_POST['id'] ?? 0);
            $oldRow=null;
            if($id){
                $st=$pdo->prepare('SELECT * FROM products WHERE id=?');
                $st->execute([$id]);
                $oldRow=$st->fetch();
                if(!$oldRow) throw new RuntimeException('Produk tidak ditemukan.');
            }
            $name=trim((string)($_POST['name'] ?? ''));
            $slug=slugify((string)(($_POST['slug'] ?? '') ?: $name));
            $categoryId=(int)($_POST['category_id'] ?? 0);
            $categoryId=$categoryId>0?$categoryId:null;
            $description=(string)($_POST['description'] ?? '');
            $productTerms=trim((string)($_POST['product_terms'] ?? ''));
            $deliveryType=strtolower(trim((string)($_POST['delivery_type'] ?? 'instant')))==='manual'?'manual':'instant';
            /* Produk hanya induk: harga, garansi, diskon & stok semuanya di varian.
               is_flash_sale + flash_ends_at = JENDELA flash (satu event per produk);
               besar diskon flash ada di tiap varian. */
            $isFlash=isset($_POST['is_flash_sale'])?1:0;
            $flashEnds=trim((string)($_POST['flash_ends_at'] ?? '')) ?: null;
            $status=isset($_POST['status'])?1:0;
            $telegramStatus=isset($_POST['telegram_status'])?1:0;
            $telegramSortOrder=(int)($_POST['telegram_sort_order'] ?? 0);

            if($name==='') throw new InvalidArgumentException('Nama produk wajib diisi.');
            if($slug==='') throw new InvalidArgumentException('Slug produk tidak valid.');
            if(admin_slug_is_reserved($slug)) throw new RuntimeException('Slug tidak boleh menggunakan nama sistem.');
            if($categoryId){
                $check=$pdo->prepare('SELECT COUNT(*) FROM categories WHERE id=?');
                $check->execute([$categoryId]);
                if(!(int)$check->fetchColumn()) throw new InvalidArgumentException('Kategori yang dipilih tidak ditemukan.');
            }
            if($flashEnds && strtotime($flashEnds)===false) throw new InvalidArgumentException('Tanggal berakhir flash sale tidak valid.');

            $newThumb=upload_image('thumbnail');
            $removeThumb=!empty($_POST['remove_thumbnail']);
            $oldThumb=$oldRow['thumbnail'] ?? null;
            $savedThumb=$newThumb ?: ($removeThumb?null:$oldThumb);

            $variantRows=admin_parse_variant_rows($_POST['variant_rows'] ?? []);
            $namedVariants=array_values(array_filter($variantRows,static fn($r)=>$r['name']!=='' && !$r['remove']));
            if(!$namedVariants) throw new RuntimeException('Produk harus memiliki minimal satu varian.');
            $activeNamed=array_values(array_filter($namedVariants,static fn($r)=>!empty($r['status'])));
            if(!$activeNamed) throw new RuntimeException('Produk harus memiliki minimal satu varian aktif.');
            $variantNames=array_map(static fn($r)=>mb_strtolower($r['name']),$namedVariants);
            if(count($variantNames)!==count(array_unique($variantNames))) throw new InvalidArgumentException('Nama varian tidak boleh sama. Gunakan nama yang berbeda untuk setiap varian.');
            foreach($namedVariants as $vr){
                if($vr['price']<=0 && $vr['discount_pct']>0) throw new InvalidArgumentException('Varian "'.e($vr['name']).'" punya diskon tetapi harganya masih 0.');
            }
            if($isFlash){
                $flashNamed=array_values(array_filter($namedVariants,static fn($r)=>!empty($r['status']) && $r['flash_discount_pct']>0));
                if(!$flashNamed) throw new InvalidArgumentException('Flash sale aktif — isi Diskon Flash (%) pada minimal satu varian aktif.');
            }

            $pdo->beginTransaction();
            /* products sekarang hanya induk. Kolom legacy (price, original_price, flash_price,
               manual_stock, warranty_label, warranty_days, rating) tidak ditulis lagi dari form ini.
               Rating dihitung otomatis dari ulasan yang disetujui. */
            $data=[$categoryId,$name,$slug,$description,$productTerms,$deliveryType,$isFlash,$flashEnds,$status,$telegramStatus,$telegramSortOrder,$savedThumb];

            if($id){
                $data[]=$id;
                $sql='UPDATE products SET category_id=?,name=?,slug=?,description=?,product_terms=?,delivery_type=?,manual_stock=0,is_flash_sale=?,flash_ends_at=?,status=?,telegram_status=?,telegram_sort_order=?,thumbnail=? WHERE id=?';
                $pdo->prepare($sql)->execute($data);
                $productId=$id;
            }else{
                $sql='INSERT INTO products(category_id,name,slug,description,product_terms,delivery_type,manual_stock,is_flash_sale,flash_ends_at,status,telegram_status,telegram_sort_order,thumbnail,created_at) VALUES(?,?,?,?,?,?,0,?,?,?,?,?,?,?,NOW())';
                $pdo->prepare($sql)->execute($data);
                $productId=(int)$pdo->lastInsertId();
            }

            /* Variant rows: delete remove-checked, update existing, insert new (skip empty names) */
            $insertVariant=$pdo->prepare('INSERT INTO product_variants(product_id,name,price,discount_pct,flash_discount_pct,warranty_label,warranty_days,manual_stock,sort_order,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $updateVariant=$pdo->prepare('UPDATE product_variants SET name=?,price=?,discount_pct=?,flash_discount_pct=?,warranty_label=?,warranty_days=?,manual_stock=?,sort_order=?,status=?,updated_at=NOW() WHERE id=? AND product_id=?');
            $deleteVariant=$pdo->prepare('DELETE FROM product_variants WHERE id=? AND product_id=?');
            $readManualStock=$pdo->prepare('SELECT manual_stock FROM product_variants WHERE id=? AND product_id=?');
            foreach($variantRows as $vr){
                if($vr['remove'] && $vr['id']>0){
                    $deleteVariant->execute([$vr['id'],$productId]);
                    continue;
                }
                if($vr['name']==='') continue;
                /* Untuk produk instan kolom stok manual disembunyikan (dan di-disable di browser),
                   jadi nilainya tidak ikut terkirim. Jangan di-reset ke 0 — counternya tetap
                   dipertahankan bila delivery_type suatu saat dikembalikan ke manual. */
                $manualStock=$vr['manual_stock'];
                if($deliveryType!=='manual'){
                    if($vr['id']>0){
                        $readManualStock->execute([$vr['id'],$productId]);
                        $manualStock=(int)($readManualStock->fetchColumn() ?: 0);
                    }else{
                        $manualStock=0;
                    }
                }
                $variantData=[$vr['name'],$vr['price'],$vr['discount_pct'],$vr['flash_discount_pct'],$vr['warranty_label'],$vr['warranty_days'],$manualStock,$vr['sort_order'],$vr['status']];
                if($vr['id']>0){
                    $updateVariant->execute(array_merge($variantData,[$vr['id'],$productId]));
                }else{
                    $insertVariant->execute(array_merge([$productId],$variantData));
                }
            }

            /* Invariant: at least one ACTIVE variant — rollback everything if not */
            $activeCheck=$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id=? AND status=1');
            $activeCheck->execute([$productId]);
            if((int)$activeCheck->fetchColumn()<1){
                throw new RuntimeException('Produk harus memiliki minimal satu varian aktif.');
            }

            $pdo->commit();
            if(($newThumb || $removeThumb) && $oldThumb && $oldThumb!==$savedThumb) delete_product_thumbnail_file($oldThumb);
            admin_redirect('/admin/products.php','Produk berhasil disimpan.');
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            if($newThumb) delete_product_thumbnail_file($newThumb);
            $message=$e->getMessage();
            $err=str_contains(strtolower($message),'duplicate')?'Slug produk sudah digunakan.':$message;
            $posted=$_POST;
            if(!empty($_POST['id'])){
                $st=$pdo->prepare('SELECT thumbnail FROM products WHERE id=?');
                $st->execute([(int)$_POST['id']]);
                $posted['thumbnail']=$st->fetchColumn() ?: null;
            }
            $edit=$posted;
        }
    }
}

$modalOpen=(bool)$edit || isset($_GET['add']) || $err!=='';
/* Variant-aware list data: harga, diskon, garansi & stok semuanya di varian.
   Manual: SUM(variant.manual_stock) - pending reservations on those variants.
   Instant: COUNT product_stocks JOIN product_variants WHERE status='available' AND v.status=1.
   Harga jual varian = price * (1 - diskon/100); flash memakai flash_discount_pct saat
   jendela flash produk masih berlaku. */
$flashCase='(p.is_flash_sale=1 AND (p.flash_ends_at IS NULL OR p.flash_ends_at>NOW()))';
$variantSaleSql='CASE WHEN '.$flashCase.' AND v.flash_discount_pct>0 THEN ROUND(v.price*(1-v.flash_discount_pct/100),2) WHEN v.discount_pct>0 THEN ROUND(v.price*(1-v.discount_pct/100),2) ELSE v.price END';
$allRows=$pdo->query('SELECT p.*,c.name category,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id=p.id AND v.status=1) variant_count,
    (SELECT MIN('.$variantSaleSql.')
       FROM product_variants v WHERE v.product_id=p.id AND v.status=1) variant_min_price,
    (SELECT MAX('.$variantSaleSql.')
       FROM product_variants v WHERE v.product_id=p.id AND v.status=1) variant_max_price,
    (SELECT MAX(v.price) FROM product_variants v WHERE v.product_id=p.id AND v.status=1
        AND v.price > '.$variantSaleSql.') variant_max_compare,
    CASE
      WHEN p.delivery_type="manual" THEN (
        SELECT COUNT(*) FROM product_variants v WHERE v.product_id=p.id AND v.status=1
          AND v.manual_stock > COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE oi.variant_id=v.id AND o.status="pending"
              AND (o.payment_expires_at IS NULL OR o.payment_expires_at>NOW())),0)
      )
      ELSE (
        SELECT COUNT(DISTINCT s.variant_id) FROM product_stocks s
        JOIN product_variants v ON v.id=s.variant_id
        WHERE s.product_id=p.id AND s.status="available" AND v.status=1
      )
    END AS variant_ready_count,
    CASE
      WHEN p.delivery_type="manual" THEN COALESCE((
        SELECT SUM(v.manual_stock) FROM product_variants v
        WHERE v.product_id=p.id AND v.status=1
      ),0) - COALESCE((
        SELECT SUM(oi.qty) FROM order_items oi
        JOIN product_variants v2 ON v2.id=oi.variant_id
        JOIN orders o ON o.id=oi.order_id
        WHERE v2.product_id=p.id AND o.status="pending"
          AND (o.payment_expires_at IS NULL OR o.payment_expires_at>NOW())
      ),0)
      ELSE COALESCE((
        SELECT COUNT(*) FROM product_stocks s
        JOIN product_variants v ON v.id=s.variant_id
        WHERE s.product_id=p.id AND s.status="available" AND v.status=1
      ), 0)
    END AS stock_total,
    COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ("paid","delivered")),0) sold_success_count
    FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC')->fetchAll();

$productCounts=['all'=>0,'active'=>0,'archived'=>0,'out'=>0];
$rows=[];
foreach($allRows as $row){
    $isArchived=$archiveEnabled && !empty($row['is_archived']);
    $visibleStock=max(0,(int)($row['stock_total'] ?? 0));
    if($isArchived){
        $productCounts['archived']++;
    }else{
        $productCounts['all']++;
        if(!empty($row['status'])) $productCounts['active']++;
        if($visibleStock<=0) $productCounts['out']++;
    }
    $matchesView=match($view){
        'active' => !$isArchived && !empty($row['status']),
        'archived' => $isArchived,
        'out' => !$isArchived && $visibleStock<=0,
        default => !$isArchived,
    };
    if($matchesView) $rows[]=$row;
}

/* When edit came from a failed POST save, re-display posted variant rows */
$editVariantRows=[];
if($edit && !empty($edit['variant_rows']) && is_array($edit['variant_rows'])){
    $editVariantRows=admin_parse_variant_rows($edit['variant_rows']);
}elseif($edit && !empty($edit['id'])){
    $editVariantRows=admin_product_variants($pdo,(int)$edit['id']);
}
/* Rating bersifat cache dari ulasan yang disetujui — selalu tampilkan nilai terkini. */
if($edit && !empty($edit['id'])){
    $edit['rating']=product_refresh_rating((int)$edit['id']);
}

admin_page_start('Produk','Tambah, edit, dan kelola produk digital yang dijual di website.');
?>
<?php if($msg):?><div class="alert ok"><span>✓</span><div><?=e($msg)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>
<?php if($err):?><div class="alert err"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>

<style>
/* Products v6 — clean layout based on approved design */
body.admin-modern.admin-page-products .adm-page-head{margin-bottom:16px}
body.admin-modern.admin-page-products .adm-page-icon{display:none}
body.admin-modern.admin-page-products .adm-page-title-wrap{align-items:flex-start}
body.admin-modern.admin-page-products .adm-page-head h2{font-size:32px;line-height:1.15;letter-spacing:-.03em}
body.admin-modern.admin-page-products .adm-breadcrumb{font-size:12px!important;margin-bottom:4px!important}
body.admin-modern.admin-page-products .adm-page-subtitle{margin-top:6px!important;font-size:14px!important;line-height:1.5}

/* summary */
body.admin-modern.admin-page-products .product-overview-grid{
    display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0 0 16px
}
body.admin-modern.admin-page-products .product-summary-card{
    min-height:102px;padding:17px 18px;display:flex;align-items:center;gap:14px;position:relative;
    color:inherit;text-decoration:none;border:1px solid var(--adm-border);border-radius:18px;background:#fff;
    box-shadow:0 8px 24px rgba(15,23,42,.035);transition:.18s ease
}
body.admin-modern.admin-page-products .product-summary-card:hover{transform:translateY(-1px);border-color:#cfdbef;box-shadow:0 12px 28px rgba(15,23,42,.06)}
body.admin-modern.admin-page-products .product-summary-icon{
    width:54px;height:54px;display:grid;place-items:center;flex:0 0 54px;border-radius:15px;
    color:#2f63f5;background:linear-gradient(145deg,#f3f7ff,#e5edff)
}
body.admin-modern.admin-page-products .product-summary-icon svg{width:24px;height:24px}
body.admin-modern.admin-page-products .product-summary-card.archive .product-summary-icon{color:#8b5cf6;background:linear-gradient(145deg,#faf7ff,#efe7ff)}
body.admin-modern.admin-page-products .product-summary-card.out .product-summary-icon{color:#e67a00;background:linear-gradient(145deg,#fff9ef,#ffecd2)}
body.admin-modern.admin-page-products .product-summary-copy{min-width:0}
body.admin-modern.admin-page-products .product-summary-copy small{display:block;margin-bottom:3px;color:#64748b;font-size:12.5px;font-weight:760}
body.admin-modern.admin-page-products .product-summary-copy b{display:block;color:var(--adm-heading);font-size:24px;line-height:1;font-weight:820;letter-spacing:-.025em}
body.admin-modern.admin-page-products .product-summary-copy span{display:block;margin-top:6px;color:var(--adm-muted);font-size:12px}
body.admin-modern.admin-page-products .product-summary-arrow{margin-left:auto;color:#9aa8bc;display:grid;place-items:center}
body.admin-modern.admin-page-products .product-summary-arrow svg{width:16px;height:16px}
body.admin-modern.admin-page-products .product-archive-info{display:none!important}

/* tabs + search */
body.admin-modern.admin-page-products .product-filter-strip{margin:0 0 10px}
body.admin-modern.admin-page-products .product-filter-tabs{display:flex;align-items:center;gap:4px;flex-wrap:wrap;padding:0 4px;border-bottom:1px solid var(--adm-border)}
body.admin-modern.admin-page-products .product-filter-tab{
    min-height:46px;padding:10px 15px;display:inline-flex;align-items:center;justify-content:center;gap:8px;
    border:0;border-bottom:2px solid transparent;border-radius:0;color:#64748b;background:transparent;
    font-size:13px;font-weight:760;text-decoration:none;transition:.16s ease
}
body.admin-modern.admin-page-products .product-filter-tab:hover{color:#2f63f5;background:#f8faff}
body.admin-modern.admin-page-products .product-filter-tab b{
    min-width:23px;padding:3px 7px;border-radius:999px;background:#eef2f7;color:#728197;font-size:10.5px;font-weight:800;text-align:center
}
body.admin-modern.admin-page-products .product-filter-tab.is-active{color:#2f63f5;border-bottom-color:#2f63f5;background:transparent;box-shadow:none}
body.admin-modern.admin-page-products .product-filter-tab.is-active b{color:#2f63f5;background:#e7efff}

body.admin-modern.admin-page-products .product-page-toolbar{
    display:grid;grid-template-columns:minmax(320px,1fr) auto;align-items:center;gap:12px;margin:0 0 12px;padding:4px 0 0
}
body.admin-modern.admin-page-products .product-search-wrap{margin:0;position:relative;max-width:none}
body.admin-modern.admin-page-products .product-search-wrap input{
    width:100%;height:50px;padding:0 48px 0 16px;border:1px solid var(--adm-border);border-radius:14px;background:#fff;font-size:14px
}
body.admin-modern.admin-page-products .product-search-wrap .search-icon{right:16px;top:50%;transform:translateY(-50%)}
body.admin-modern.admin-page-products .product-toolbar-actions{display:flex;align-items:center;gap:10px}
body.admin-modern.admin-page-products .product-toolbar-actions .adm-btn{
    min-height:50px;padding:10px 17px;border-radius:13px;font-size:13px;font-weight:760;white-space:nowrap
}
body.admin-modern.admin-page-products .product-toolbar-actions .adm-btn.primary{min-width:166px;box-shadow:0 8px 18px rgba(47,99,245,.16)}
body.admin-modern.admin-page-products .archive-view-btn{min-width:126px;justify-content:center}

/* bulk actions */
body.admin-modern.admin-page-products .bulk-form{margin:0 0 10px}
body.admin-modern.admin-page-products .bulk-toolbar{
    min-height:62px;padding:10px 13px 10px 17px;display:flex;align-items:center;gap:12px;
    border:1px solid var(--adm-border);border-radius:15px;background:#fff;box-shadow:none
}
body.admin-modern.admin-page-products .bulk-check{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:740;color:#526176}
body.admin-modern.admin-page-products .bulk-check input{width:18px;height:18px}
body.admin-modern.admin-page-products .bulk-actions{display:flex;align-items:center;gap:8px;margin-left:auto;flex-wrap:wrap}
body.admin-modern.admin-page-products .bulk-actions .adm-btn{min-height:40px;padding:8px 13px;border-radius:10px;font-size:12px;font-weight:740}
body.admin-modern.admin-page-products .bulk-actions .archive-bulk{color:#40546e!important;border-color:#d7e0eb!important;background:#f7f9fc!important}
body.admin-modern.admin-page-products .bulk-actions .restore-bulk{color:#17694e!important;border-color:#cce8dc!important;background:#eff9f4!important}

/* table */
body.admin-modern.admin-page-products .table-wrap{
    border:1px solid var(--adm-border);border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.035);overflow:hidden
}
body.admin-modern.admin-page-products #adminProductsTable{width:100%!important;min-width:0!important;table-layout:fixed!important;border-collapse:collapse}
body.admin-modern.admin-page-products #adminProductsTable thead th{
    padding:13px 10px!important;background:#f7f9fc!important;color:#65758b!important;font-size:11px!important;font-weight:800!important;
    letter-spacing:.045em!important;line-height:1.2!important;white-space:nowrap!important;text-transform:uppercase!important
}
body.admin-modern.admin-page-products #adminProductsTable tbody td{
    padding:14px 10px!important;vertical-align:middle!important;font-size:13px!important;line-height:1.4!important;border-top:1px solid #edf1f6
}
body.admin-modern.admin-page-products #adminProductsTable tbody tr:first-child td{border-top:0}
body.admin-modern.admin-page-products #adminProductsTable tbody tr:hover{background:#fbfcff}

/* exact desktop column balance — leaves enough room for 3 labeled actions */
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(1),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(1){width:4%!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(2),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(2){width:29%!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(3),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(3){width:12%!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(4),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(4){width:10%!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(5),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(5){width:6%!important;text-align:center!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(6),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(6){width:7%!important;text-align:center!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(7),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(7){width:10%!important;text-align:center!important}
body.admin-modern.admin-page-products #adminProductsTable th:nth-child(8),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(8){width:22%!important;text-align:center!important}

body.admin-modern.admin-page-products .product-table-main{display:flex;align-items:center;gap:12px;min-width:0}
body.admin-modern.admin-page-products .product-list-thumb{width:58px!important;height:58px!important;flex:0 0 58px;border-radius:12px;background:#f7f9fc}
body.admin-modern.admin-page-products .product-table-copy{min-width:0;text-align:left}
body.admin-modern.admin-page-products .product-table-copy>b{display:block;overflow:hidden;text-overflow:ellipsis;color:var(--adm-heading);font-size:13.5px!important;font-weight:780;white-space:nowrap}
body.admin-modern.admin-page-products .product-table-copy>small{display:block;margin-top:2px;overflow:hidden;text-overflow:ellipsis;color:#8390a3;font-size:11px!important;white-space:nowrap}
body.admin-modern.admin-page-products .product-table-meta{display:flex;align-items:center;flex-wrap:wrap;gap:5px;margin-top:6px}
body.admin-modern.admin-page-products .product-table-meta span{padding:3px 7px;border:1px solid #e4ebf4;border-radius:999px;background:#f7f9fc;color:#66768b;font-size:9.5px!important;line-height:1.2}
body.admin-modern.admin-page-products .product-table-meta .admin-rating-value{color:#d98b00;background:#fff9ee;border-color:#f6e8c7}
body.admin-modern.admin-page-products .archived-meta{color:#8058ba!important;background:#f8f3ff!important;border-color:#e6daf8!important}

body.admin-modern.admin-page-products .product-status-pill{
    min-width:86px;min-height:30px;padding:5px 9px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:999px;
    font-size:11px;font-weight:780;white-space:nowrap
}
body.admin-modern.admin-page-products .product-status-pill.active{color:#157149;background:#edf9f1;border-color:#cde9d8}
body.admin-modern.admin-page-products .product-status-pill.out{color:#b26700;background:#fff6e9;border-color:#f3d6aa}
body.admin-modern.admin-page-products .product-status-pill.archived{color:#7b45b7;background:#f5f0ff;border-color:#e0d3f7}
body.admin-modern.admin-page-products .product-status-pill.inactive{color:#56657a;background:#f4f6fa;border-color:#dae2ec}
body.admin-modern.admin-page-products td[data-label="Status"] .cell-value{display:flex;justify-content:center}

/* exactly three action buttons: Edit, Arsipkan/Pulihkan, Hapus */
body.admin-modern.admin-page-products .product-table-actions{
    width:100%!important;display:flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;flex-wrap:nowrap!important
}
body.admin-modern.admin-page-products .product-table-actions form,
body.admin-modern.admin-page-products .product-table-actions>a{
    display:inline-flex!important;width:auto!important;min-width:0!important;flex:0 0 auto!important;margin:0!important
}
body.admin-modern.admin-page-products .product-table-actions .product-action-btn{
    width:auto!important;height:36px!important;min-height:36px!important;padding:0 9px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;
    gap:5px!important;border:1px solid!important;border-radius:10px!important;font-size:10.5px!important;font-weight:760!important;line-height:1!important;white-space:nowrap!important;text-decoration:none!important
}
body.admin-modern.admin-page-products .product-table-actions .product-action-btn svg{width:12px!important;height:12px!important;flex:0 0 12px!important}
body.admin-modern.admin-page-products .product-action-btn.edit{min-width:60px!important;color:#2f63f5!important;background:#f4f8ff!important;border-color:#d7e3ff!important}
body.admin-modern.admin-page-products .product-action-btn.archive{min-width:82px!important;color:#536278!important;background:#f7f9fc!important;border-color:#dbe3ed!important}
body.admin-modern.admin-page-products .product-action-btn.restore{min-width:82px!important;color:#17694e!important;background:#eff9f4!important;border-color:#cce8dc!important}
body.admin-modern.admin-page-products .product-action-btn.delete{min-width:66px!important;color:#df445a!important;background:#fff4f5!important;border-color:#f4d2d8!important}
body.admin-modern.admin-page-products .product-action-btn:hover{filter:brightness(.985);transform:translateY(-1px)}
body.admin-modern.admin-page-products td[data-label="Aksi"] .cell-value{width:100%;min-width:0}

body.admin-modern.admin-page-products .product-empty-search{margin-top:10px;font-size:12px}

/* variant repeater */
body.admin-modern.admin-page-products .variant-block{border:1px solid var(--adm-border);border-radius:14px;padding:14px;background:#fff}
body.admin-modern.admin-page-products .variant-block-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
body.admin-modern.admin-page-products .variant-block-head b{font-size:14px;color:var(--adm-heading);font-weight:800}
body.admin-modern.admin-page-products .variant-block-help{display:block;color:#64748b;font-size:12px;line-height:1.5;margin-bottom:12px}
body.admin-modern.admin-page-products .variant-rows{display:flex;flex-direction:column;gap:8px}
body.admin-modern.admin-page-products .variant-row{display:grid;grid-template-columns:minmax(140px,1.35fr) 92px 78px 78px minmax(150px,1.1fr) 86px 68px auto auto;gap:8px;align-items:end;padding:10px;border:1px solid #e6ecf4;border-radius:10px;background:#f9fbfe}
body.admin-modern.admin-page-products .variant-cell{display:flex;flex-direction:column;gap:4px;min-width:0}
body.admin-modern.admin-page-products .variant-cell>span{font-size:10px;font-weight:850;letter-spacing:.04em;text-transform:uppercase;color:#7a879b}
body.admin-modern.admin-page-products .variant-cell input[type=text],body.admin-modern.admin-page-products .variant-cell input[type=number]{height:36px;border:1px solid var(--adm-border);border-radius:8px;padding:0 9px;font-size:13px;background:#fff;min-width:0;width:100%}
body.admin-modern.admin-page-products .variant-warranty-pair{display:grid;grid-template-columns:minmax(0,1fr) 58px;gap:5px}
body.admin-modern.admin-page-products .variant-warranty-pair input{height:36px;border:1px solid var(--adm-border);border-radius:8px;padding:0 8px;font-size:12.5px;background:#fff;min-width:0;width:100%}
body.admin-modern.admin-page-products .variant-preview{display:block;font-size:10.5px;font-weight:800;color:#2857c8;line-height:1.35;min-height:14px}
body.admin-modern.admin-page-products .variant-preview.is-warn{color:#c2410c}
body.admin-modern.admin-page-products .variant-cell.is-hidden{opacity:.32;pointer-events:none}
body.admin-modern.admin-page-products .rating-readonly{display:flex;flex-direction:column;gap:3px;padding:9px 11px;border:1px dashed var(--adm-border);border-radius:9px;background:#f7f9fc}
body.admin-modern.admin-page-products .rating-readonly b{font-size:15px;color:#b45309;font-weight:900;letter-spacing:.01em}
body.admin-modern.admin-page-products .rating-readonly small{font-size:11px;line-height:1.45;color:#64748b}
body.admin-modern.admin-page-products .product-stock-link{display:inline-block;margin-top:3px;font-size:11px;font-weight:800;color:#2857c8;text-decoration:none}
body.admin-modern.admin-page-products .product-stock-link:hover{text-decoration:underline}
body.admin-modern[data-admin-theme="dark"].admin-page-products .product-stock-link{color:#93b4ff}
body.admin-modern.admin-page-products .variant-check{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:740;color:#526176;white-space:nowrap;cursor:pointer;padding-bottom:9px}
body.admin-modern.admin-page-products .variant-check input{width:15px;height:15px}
body.admin-modern.admin-page-products .variant-remove{color:#c2410c!important}
body.admin-modern[data-admin-theme="dark"].admin-page-products .variant-block{background:#142033;border-color:#2b3c53}
body.admin-modern[data-admin-theme="dark"].admin-page-products .variant-row{background:#18263a;border-color:#2b3c53}
body.admin-modern[data-admin-theme="dark"].admin-page-products .variant-cell input[type=text],
body.admin-modern[data-admin-theme="dark"].admin-page-products .variant-cell input[type=number],
body.admin-modern[data-admin-theme="dark"].admin-page-products .variant-warranty-pair input{background:#101c2e;border-color:#2b3c53;color:#e2e8f0}
body.admin-modern[data-admin-theme="dark"].admin-page-products .variant-preview{color:#93b4ff}
body.admin-modern[data-admin-theme="dark"].admin-page-products .rating-readonly{background:#101c2e;border-color:#2b3c53}
body.admin-modern[data-admin-theme="dark"].admin-page-products .rating-readonly b{color:#fbbf24}
body.admin-modern[data-admin-theme="dark"].admin-page-products .rating-readonly small{color:#9fb0c5}
@media(max-width:1180px){
    body.admin-modern.admin-page-products .variant-row{grid-template-columns:minmax(140px,1.4fr) 1fr 1fr 1fr;gap:8px}
    body.admin-modern.admin-page-products .variant-row .variant-cell-name{grid-column:1/-1}
    body.admin-modern.admin-page-products .variant-row .variant-cell-warranty{grid-column:span 2}
}
@media(max-width:900px){
    body.admin-modern.admin-page-products .variant-row{grid-template-columns:1fr 1fr;gap:8px}
    body.admin-modern.admin-page-products .variant-row .variant-cell-name,
    body.admin-modern.admin-page-products .variant-row .variant-cell-warranty{grid-column:1/-1}
    body.admin-modern.admin-page-products .variant-check{padding-top:6px}
}

/* dark mode */
body.admin-modern[data-admin-theme="dark"].admin-page-products .product-summary-card,
body.admin-modern[data-admin-theme="dark"].admin-page-products .product-search-wrap input,
body.admin-modern[data-admin-theme="dark"].admin-page-products .bulk-toolbar,
body.admin-modern[data-admin-theme="dark"].admin-page-products .table-wrap{background:#142033!important;border-color:#2b3c53!important}
body.admin-modern[data-admin-theme="dark"].admin-page-products #adminProductsTable thead th{background:#18263a!important;color:#9fb0c5!important}
body.admin-modern[data-admin-theme="dark"].admin-page-products #adminProductsTable tbody td{border-top-color:#26364b}
body.admin-modern[data-admin-theme="dark"].admin-page-products #adminProductsTable tbody tr:hover{background:#17243a}

/* laptop */
@media(max-width:1280px) and (min-width:901px){
    body.admin-modern.admin-page-products .product-overview-grid{gap:10px}
    body.admin-modern.admin-page-products .product-summary-card{padding:14px 15px;min-height:94px}
    body.admin-modern.admin-page-products .product-summary-icon{width:48px;height:48px;flex-basis:48px}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(2),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(2){width:27%!important}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(3),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(3){width:11%!important}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(4),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(4){width:10%!important}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(5),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(5){width:6%!important}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(6),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(6){width:7%!important}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(7),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(7){width:10%!important}
    body.admin-modern.admin-page-products #adminProductsTable th:nth-child(8),body.admin-modern.admin-page-products #adminProductsTable td:nth-child(8){width:25%!important}
    body.admin-modern.admin-page-products .product-table-actions{gap:5px!important}
    body.admin-modern.admin-page-products .product-table-actions .product-action-btn{padding:0 7px!important;font-size:10px!important}
    body.admin-modern.admin-page-products .product-action-btn.edit{min-width:56px!important}
    body.admin-modern.admin-page-products .product-action-btn.archive,body.admin-modern.admin-page-products .product-action-btn.restore{min-width:76px!important}
    body.admin-modern.admin-page-products .product-action-btn.delete{min-width:62px!important}
}

/* tablet / mobile: existing responsive-list becomes cards; keep 3 actions in one neat row */
@media(max-width:900px){
    body.admin-modern.admin-page-products .adm-page-head h2{font-size:28px}
    body.admin-modern.admin-page-products .product-overview-grid{grid-template-columns:1fr;gap:9px}
    body.admin-modern.admin-page-products .product-summary-card{min-height:88px;padding:14px 15px}
    body.admin-modern.admin-page-products .product-filter-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding:0;border-bottom:0;gap:7px}
    body.admin-modern.admin-page-products .product-filter-tab{min-height:42px;padding:8px 11px;border:1px solid var(--adm-border);border-radius:11px;justify-content:space-between}
    body.admin-modern.admin-page-products .product-filter-tab.is-active{border-color:#aac1ff;background:#f5f8ff}
    body.admin-modern.admin-page-products .product-page-toolbar{grid-template-columns:1fr;gap:8px}
    body.admin-modern.admin-page-products .product-toolbar-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    body.admin-modern.admin-page-products .product-toolbar-actions .adm-btn{width:100%;min-width:0;justify-content:center}
    body.admin-modern.admin-page-products .bulk-toolbar{align-items:stretch;flex-direction:column}
    body.admin-modern.admin-page-products .bulk-actions{margin-left:0;width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}
    body.admin-modern.admin-page-products .bulk-actions .adm-btn{width:100%;justify-content:center}
    body.admin-modern.admin-page-products .responsive-list .product-table-actions{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:6px!important}
    body.admin-modern.admin-page-products .responsive-list .product-table-actions form,
    body.admin-modern.admin-page-products .responsive-list .product-table-actions>a{display:block!important;width:100%!important}
    body.admin-modern.admin-page-products .responsive-list .product-table-actions .product-action-btn{width:100%!important;min-width:0!important;padding:0 6px!important;font-size:10.5px!important}
}
@media(max-width:560px){
    body.admin-modern.admin-page-products .product-toolbar-actions{grid-template-columns:1fr}
    body.admin-modern.admin-page-products .bulk-actions{grid-template-columns:1fr}
    body.admin-modern.admin-page-products .product-list-thumb{width:56px!important;height:56px!important;flex-basis:56px}
    body.admin-modern.admin-page-products .responsive-list .product-table-actions .product-action-btn span{display:inline}
}
</style>

<div class="product-overview-grid">
    <a class="product-summary-card" href="/admin/products.php?view=all">
        <span class="product-summary-icon"><?=admin_icon('package')?></span>
        <div class="product-summary-copy"><small>Produk Utama</small><b><?=number_format($productCounts['all'])?></b><span>Produk aktif</span></div>
        <i class="product-summary-arrow"><?=admin_icon('chevron-right')?></i>
    </a>
    <a class="product-summary-card archive" href="/admin/products.php?view=archived">
        <span class="product-summary-icon"><?=admin_icon('box')?></span>
        <div class="product-summary-copy"><small>Diarsipkan</small><b><?=number_format($productCounts['archived'])?></b><span>Produk diarsipkan</span></div>
        <i class="product-summary-arrow"><?=admin_icon('chevron-right')?></i>
    </a>
    <a class="product-summary-card out" href="/admin/products.php?view=out">
        <span class="product-summary-icon"><?=admin_icon('help')?></span>
        <div class="product-summary-copy"><small>Stok Habis</small><b><?=number_format($productCounts['out'])?></b><span>Produk kehabisan stok</span></div>
        <i class="product-summary-arrow"><?=admin_icon('chevron-right')?></i>
    </a>
</div>

<div class="product-filter-strip">
    <div class="product-filter-tabs" aria-label="Filter produk">
        <a class="product-filter-tab <?=in_array($view,['all','active','out'],true)?'is-active':''?>" href="/admin/products.php?view=all"><span>Produk Utama</span><b><?=$productCounts['all']?></b></a>
        <a class="product-filter-tab <?=$view==='archived'?'is-active':''?>" href="/admin/products.php?view=archived"><span>Diarsipkan</span><b><?=$productCounts['archived']?></b></a>
        <a class="product-filter-tab product-filter-pill <?=$view==='active'?'is-active':''?>" href="/admin/products.php?view=active"><span>Aktif</span><b><?=$productCounts['active']?></b></a>
        <a class="product-filter-tab product-filter-pill <?=$view==='out'?'is-active':''?>" href="/admin/products.php?view=out"><span>Stok Habis</span><b><?=$productCounts['out']?></b></a>
    </div>
</div>

<div class="product-page-toolbar">
    <div class="product-search-wrap"><input id="adminProductSearch" type="search" autocomplete="off" placeholder="Cari produk, kategori, atau slug..." aria-label="Cari produk"><span class="search-icon"><?=admin_icon('search')?></span></div>
    <div class="product-toolbar-actions">
        <a class="adm-btn soft archive-view-btn" href="/admin/products.php?view=archived"><?=admin_icon('box')?>Lihat Arsip</a>
        <button class="adm-btn primary" type="button" data-open-product-modal><?=admin_icon('plus')?>Tambah Produk</button>
    </div>
</div>

<div class="product-modal <?=$modalOpen?'is-open':''?>" id="productFormModal" aria-hidden="<?=$modalOpen?'false':'true'?>">
    <div class="product-modal-panel" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
        <form method="post" enctype="multipart/form-data" class="form-card">
            <?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id'] ?? '')?>">
            <div class="adm-card-head">
                <div><h3 id="productModalTitle"><?=!empty($edit['id'])?'Edit Produk':'Tambah Produk'?></h3><p>Produk adalah induk. Harga, diskon, garansi, dan stok diisi pada tiap varian di bawah.</p></div>
                <button class="product-modal-close" type="button" data-close-product-modal aria-label="Tutup popup">×</button>
            </div>
            <div class="form-grid">
                <div class="field"><label>Nama Produk</label><input name="name" required value="<?=e($edit['name'] ?? '')?>" placeholder="Contoh: Canva Pro"></div>
                <div class="field"><label>Slug / URL</label><input name="slug" value="<?=e($edit['slug'] ?? '')?>" placeholder="canva-pro"><small>Kosongkan untuk membuat slug otomatis.</small></div>
                <div class="field"><label>Kategori</label><select name="category_id"><option value="">Tanpa kategori</option><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>" <?=($edit['category_id'] ?? '')==$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach;?></select></div>
                <div class="field"><label>Pengiriman</label><div class="delivery-choice"><label><input type="radio" name="delivery_type" value="instant" <?=($edit['delivery_type'] ?? 'instant')!=='manual'?'checked':''?>><span><b>⚡ Instan</b></span></label><label><input type="radio" name="delivery_type" value="manual" <?=($edit['delivery_type'] ?? 'instant')==='manual'?'checked':''?>><span><b>✍ Manual</b></span></label></div></div>
                <div class="field product-thumb-field">
                    <label>Thumbnail</label>
                    <?php if(!empty($edit['thumbnail'])):?><div class="current-thumb-box"><span class="current-thumb-preview"><img src="<?=e($edit['thumbnail'])?>" alt="Thumbnail saat ini"></span><div class="current-thumb-meta"><b>Thumbnail saat ini</b><label class="remove-thumb-check"><input type="checkbox" name="remove_thumbnail" value="1"> Hapus saat disimpan</label></div></div><?php endif;?>
                    <input class="soft-file-input" type="file" name="thumbnail" accept="image/png,image/jpeg,image/webp">
                    <small><?=!empty($edit['thumbnail'])?'Pilih gambar baru untuk mengganti thumbnail.':'Format JPG, PNG, atau WEBP.'?></small>
                </div>
                <div class="field"><label>Rating Produk</label><div class="rating-readonly"><b>★ <?=($edit && isset($edit['rating']))?number_format((float)$edit['rating'],1):'—'?></b><small><?=($edit && isset($edit['rating']))?'Dihitung otomatis dari ulasan pembeli yang disetujui — tidak bisa diisi manual.':'Rating akan dihitung otomatis setelah ada ulasan pembeli yang disetujui.'?></small></div></div>
                <div class="field"><label>Urutan di Bot Telegram</label><input class="no-wheel-number" type="number" inputmode="numeric" name="telegram_sort_order" value="<?=e($edit['telegram_sort_order'] ?? 0)?>"><small>Angka lebih kecil ditampilkan lebih dulu.</small></div>
                <div class="field"><label>Flash Sale Berakhir</label><input type="datetime-local" name="flash_ends_at" value="<?=!empty($edit['flash_ends_at'])?e(date('Y-m-d\TH:i',strtotime($edit['flash_ends_at']))):''?>"><small>Jendela flash sale untuk semua varian. Besar diskonnya diisi per varian (kolom Flash %).</small></div>
            </div>

            <!-- Variant repeater: every product has >=1 active variant; stock lives on variants -->
            <div class="variant-block" style="margin-top:16px">
                <div class="variant-block-head">
                    <b>Varian Produk</b>
                    <button class="adm-btn soft" type="button" data-variant-add>+ Tambah Varian</button>
                </div>
                <small class="variant-block-help">Produk hanya sebagai induk — <b>harga, diskon, garansi, dan stok diisi per varian</b>. <b>Harga</b> adalah harga reguler (yang dicoret). <b>Diskon %</b> memotong harga itu menjadi harga jual. <b>Flash %</b> dipakai saat jendela flash sale di bawah aktif. <b>Stok manual</b> hanya untuk produk manual; produk instan memakai stok digital di menu <a href="/admin/stocks.php">Stok Produk</a>.</small>
                <div class="variant-rows" data-variant-rows>
                    <?php
                    /* Pre-render: posted rows on failed save, existing rows on edit, else one empty row */
                    if($editVariantRows){
                        foreach($editVariantRows as $vr):
                            $vrId=(int)($vr['id'] ?? 0);
                            $vrKey=$vrId>0?('v'.$vrId):('n'.(string)($vr['key'] ?? uniqid()));
                            echo admin_variant_row_html($vrKey,$vr,$vrId);
                        endforeach;
                    }else{
                        echo admin_variant_row_html('n0',[],0);
                    }
                    ?>
                </div>
                <template id="variantRowTemplate"><?=admin_variant_row_html('__IDX__',[],0)?></template>
            </div>

            <div class="field" style="margin-top:16px"><label>Deskripsi</label><textarea name="description" placeholder="Jelaskan detail produk, fitur, dan informasi penting."><?=e($edit['description'] ?? '')?></textarea></div>
            <div class="field" style="margin-top:16px"><label>Syarat & Ketentuan Produk</label><textarea name="product_terms" placeholder="Tulis satu ketentuan per baris."><?=e($edit['product_terms'] ?? '')?></textarea><small>Ketentuan tampil pada halaman lacak pesanan setelah produk terkirim.</small></div>
            <div class="adm-checks"><label><input type="checkbox" name="is_flash_sale" <?=!empty($edit['is_flash_sale'])?'checked':''?>> Aktifkan flash sale</label><label><input type="checkbox" name="status" <?=($edit['status'] ?? 1)?'checked':''?>> Produk aktif</label><label><input type="checkbox" name="telegram_status" <?=($edit['telegram_status'] ?? 1)?'checked':''?>> Tampilkan di bot Telegram</label></div>
            <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?>Simpan Produk</button><a class="adm-btn soft" href="/admin/products.php">Batal</a></div>
        </form>
    </div>
</div>

<form id="productsBulkForm" method="post" class="bulk-form">
    <?=csrf_field()?>
    <input type="hidden" name="return_view" value="<?=e($view)?>">
    <div class="bulk-toolbar">
        <label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="productsBulkForm"> Pilih semua</label>
        <div class="bulk-actions">
            <?php if($view==='archived'): ?>
                <button class="adm-btn soft restore-bulk" type="submit" name="action" value="unarchive_selected" onclick="return confirm('Pulihkan produk terpilih dari arsip?')"><?=admin_icon('refresh')?>Pulihkan Terpilih</button>
            <?php else: ?>
                <button class="adm-btn soft archive-bulk" type="submit" name="action" value="archive_selected" onclick="return confirm('Arsipkan produk terpilih? Produk tidak akan tampil di daftar utama dan halaman index website.')"><?=admin_icon('box')?>Arsipkan Terpilih</button>
            <?php endif; ?>
            <button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" onclick="return confirm('Hapus produk terpilih? Produk yang memiliki riwayat pesanan tidak akan dihapus.')">Hapus Terpilih</button>
            <?php if($view==='all'): ?><button class="adm-btn danger" type="submit" name="action" value="delete_all" onclick="return confirm('Hapus semua produk di daftar utama yang tidak memiliki riwayat pesanan? Produk arsip tidak akan ikut dihapus.')">Hapus Semua</button><?php endif; ?>
        </div>
    </div>
</form>

<div class="table-wrap responsive-list">
<table id="adminProductsTable">
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Terjual</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if($rows):foreach($rows as $row):
        $isManual=(($row['delivery_type'] ?? 'instant')==='manual');
        $visibleStock=max(0,(int)($row['stock_total'] ?? 0));
        $variantCount=(int)($row['variant_count'] ?? 0);
        $variantReady=(int)($row['variant_ready_count'] ?? 0);
        /* Harga tampil = rentang harga jual varian (sudah termasuk diskon & flash) */
        $priceMin=($row['variant_min_price']!==null && $row['variant_min_price']!=='')?(float)$row['variant_min_price']:0.0;
        $priceMax=($row['variant_max_price']!==null && $row['variant_max_price']!=='')?(float)$row['variant_max_price']:$priceMin;
        $priceMax=max($priceMax,$priceMin);
        /* Harga coret = harga reguler terbesar yang masih di atas harga jual (kolom ini
           disamakan dengan badge diskon storefront). Produk level sudah legacy. */
        $slash=(float)($row['variant_max_compare'] ?? 0);
        if($slash>0 && $slash<=$priceMin) $slash=0.0;
    ?>
        <tr data-product-row>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><input form="productsBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$row['id']?>"></div></td>
            <td data-label="Produk">
                <div class="cell-value product-table-main">
                    <div class="product-list-thumb"><?php if(!empty($row['thumbnail'])):?><img class="product-list-thumb-img" src="<?=e($row['thumbnail'])?>" alt="Thumbnail <?=e($row['name'])?>" loading="lazy"><?php else:?><span class="admin-default-thumb">DIGITAL</span><?php endif;?></div>
                    <div class="product-table-copy">
                        <b><?=e($row['name'])?></b>
                        <small><?=e($row['slug'])?></small>
                        <div class="product-table-meta">
                            <span class="admin-rating-value">★ <?=number_format((float)($row['rating'] ?? 5),1)?></span>
                            <span><?=$isManual?'✍ Manual':'⚡ Instan'?></span>
                            <span><?=number_format($variantCount)?> varian</span>
                            <?php if($archiveEnabled && !empty($row['is_archived'])):?><span class="archived-meta">Diarsipkan</span><?php endif;?>
                            <?php if($row['is_flash_sale']):?><span>Flash aktif</span><?php endif;?><?php if(!empty($row['telegram_status'])):?><span>Telegram</span><?php endif;?>
                        </div>
                    </div>
                </div>
            </td>
            <td data-label="Kategori"><div class="cell-value"><?=e($row['category'] ?: 'Tanpa kategori')?></div></td>
            <td data-label="Harga"><div class="cell-value">
                <b><?=rupiah($priceMin)?><?=$priceMax>$priceMin?' – '.rupiah($priceMax):''?></b>
                <?php if($slash>$priceMin):?><small><s><?=rupiah($slash)?></s></small><?php endif;?>
                <?php if($priceMax>$priceMin):?><small><?=number_format($variantCount)?> varian</small><?php endif;?>
            </div></td>
            <td data-label="Stok"><div class="cell-value">
                <b><?=$visibleStock?></b>
                <small><?=$variantReady?> dari <?=number_format($variantCount)?> varian siap<?=$isManual?' · manual':''?></small>
                <a class="product-stock-link" href="/admin/stocks.php?product_id=<?=intval($row['id'])?>">Kelola stok →</a>
            </div></td>
            <td data-label="Terjual"><div class="cell-value"><b><?=e($row['sold_success_count'])?></b></div></td>
            <td data-label="Status"><div class="cell-value"><?php if($archiveEnabled && !empty($row['is_archived'])): ?><span class="product-status-pill archived">Diarsipkan</span><?php elseif(empty($row['status'])): ?><span class="product-status-pill inactive">Nonaktif</span><?php elseif($visibleStock<=0): ?><span class="product-status-pill out">Stok Habis</span><?php else: ?><span class="product-status-pill active">Aktif</span><?php endif; ?></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions product-table-actions">
                <a class="product-action-btn edit" href="?view=<?=e($view)?>&edit=<?=$row['id']?>"><?=admin_icon('edit')?><span>Edit</span></a>
                <form method="post" onsubmit="return confirm('<?=($archiveEnabled && !empty($row['is_archived']))?'Pulihkan produk ini dari arsip?':'Arsipkan produk ini? Produk tidak akan tampil di daftar utama dan halaman index website.'?>')"><?=csrf_field()?><input type="hidden" name="action" value="<?=($archiveEnabled && !empty($row['is_archived']))?'unarchive':'archive'?>"><input type="hidden" name="id" value="<?=$row['id']?>"><button class="adm-linklike product-action-btn <?=($archiveEnabled && !empty($row['is_archived']))?'restore':'archive'?>" type="submit"><?=admin_icon(($archiveEnabled && !empty($row['is_archived']))?'refresh':'box')?><span><?=($archiveEnabled && !empty($row['is_archived']))?'Pulihkan':'Arsipkan'?></span></button></form>
                <form class="product-delete-form" method="post" onsubmit="return confirm('Hapus produk ini? Produk dengan riwayat pesanan tidak dapat dihapus.')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$row['id']?>"><button class="adm-danger adm-linklike product-action-btn delete" type="submit"><?=admin_icon('trash')?><span>Hapus</span></button></form>
            </div></div></td>
        </tr>
    <?php endforeach;else:?>
        <tr><td colspan="8"><div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon($view==='archived'?'box':'package')?></div><b><?=$view==='archived'?'Belum ada produk diarsipkan':'Belum ada produk'?></b><p><?=$view==='archived'?'Produk yang Anda arsipkan akan muncul di sini.':'Tambahkan produk pertama untuk mulai berjualan.'?></p></div></div></td></tr>
    <?php endif;?>
    </tbody>
</table>
</div>
<div id="productSearchEmpty" class="product-empty-search">Produk tidak ditemukan. Coba kata kunci lain.</div>
<script>
(function(){
    const rowsHost=document.querySelector('[data-variant-rows]');
    const addBtn=document.querySelector('[data-variant-add]');
    const tpl=document.getElementById('variantRowTemplate');
    const modal=document.getElementById('productFormModal');

    function rupiah(n){
        const v=Math.round(Number(n)||0);
        return 'Rp '+new Intl.NumberFormat('id-ID').format(v);
    }
    /* Model varian: price = harga reguler (harga coret), discount_pct = diskon harian,
       flash_discount_pct = diskon saat jendela flash sale produk aktif.
       Harga jual = price * (1 - pct/100); coret ditampilkan hanya bila price > harga jual. */
    function previewRow(row){
        const out=row.querySelector('[data-variant-preview]');
        if(!out) return;
        const regular=Math.max(0,Number(row.querySelector('.variant-price')?.value)||0);
        const disc=Math.min(100,Math.max(0,Number(row.querySelector('.variant-disc')?.value)||0));
        const flash=Math.min(100,Math.max(0,Number(row.querySelector('.variant-flash')?.value)||0));
        const flashOn=!!modal?.querySelector('input[name="is_flash_sale"]')?.checked;
        const pct=(flashOn&&flash>0)?flash:disc;
        if(regular<=0){
            out.textContent=pct>0?'Isi harga varian dulu':'Harga jual: —';
            out.classList.toggle('is-warn',pct>0);
            return;
        }
        const sale=Math.max(0,Math.round(regular*(1-pct/100)*100)/100);
        const slash=regular>sale?regular:0;
        out.textContent=slash>0
            ? 'Jual '+rupiah(sale)+' · coret '+rupiah(slash)
            : (pct>0?('Jual '+rupiah(sale)):('Jual '+rupiah(regular)));
        out.classList.remove('is-warn');
    }
    function previewAll(){ rowsHost?.querySelectorAll('[data-variant-row]').forEach(previewRow); }

    if(rowsHost&&addBtn&&tpl){
        let counter=rowsHost.querySelectorAll('[data-variant-row]').length;
        addBtn.addEventListener('click',function(){
            counter++;
            const html=tpl.innerHTML.replace(/__IDX__/g,'n'+counter+'_'+Date.now());
            rowsHost.insertAdjacentHTML('beforeend',html);
            const last=rowsHost.lastElementChild;
            const nameInput=last&&last.querySelector('.variant-name');
            if(nameInput) nameInput.focus();
            syncVariantStockFields();
            previewAll();
        });
        rowsHost.addEventListener('input',function(e){
            const row=e.target.closest('[data-variant-row]');
            if(row) previewRow(row);
        });
    }

    /* Stok manual hanya relevan untuk delivery manual; produk instan memakai stok digital */
    function syncVariantStockFields(){
        const manual=(modal?.querySelector('input[name="delivery_type"]:checked')?.value==='manual');
        modal?.querySelectorAll('.variant-manual-stock').forEach(function(cell){
            cell.classList.toggle('is-hidden',!manual);
            const input=cell.querySelector('input');
            if(input) input.disabled=!manual;
        });
    }
    modal?.querySelectorAll('input[name="delivery_type"]').forEach(function(input){
        input.addEventListener('change',syncVariantStockFields);
    });

    ['input','change'].forEach(function(evt){
        modal?.addEventListener(evt,function(e){
            if(e.target&&e.target.name==='is_flash_sale') previewAll();
        });
    });

    syncVariantStockFields();
    previewAll();
})();
</script>
<?php admin_page_end(); ?>
