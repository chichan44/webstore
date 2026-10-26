<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
require_once __DIR__.'/../includes/telegram_bot.php';
ensure_order_security_columns();
ensure_product_extra_columns();
cleanup_legacy_order_admin_pin_setting();
telegram_ensure_schema();

$msg='';
$err='';
$revealedOrderPassword='';
$statusOptions=[
    ''=>'Semua Status',
    'success'=>'Dibayar + Terkirim',
    'pending'=>'Menunggu',
    'paid'=>'Dibayar',
    'delivered'=>'Terkirim',
    'cancelled'=>'Dibatalkan',
    'failed'=>'Gagal',
];

function admin_delete_order_ids(PDO $pdo,array $ids): int {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
    if(!$ids) return 0;
    $ph=implode(',',array_fill(0,count($ids),'?'));

    /* Stok selalu hidup di varian. Saat pesanan dihapus, stok yang sudah dikonsumsi
       harus dikembalikan — kalau tidak, menu Stok terus menghitung baris "sold"
       dan counter manual yang tidak lagi punya pesanan. */
    $manualItems=$pdo->prepare("SELECT oi.product_id, oi.variant_id, SUM(oi.qty) qty
        FROM order_items oi
        JOIN orders o ON o.id=oi.order_id
        JOIN products p ON p.id=oi.product_id
        WHERE oi.order_id IN ($ph) AND oi.variant_id IS NOT NULL
          AND o.status IN ('paid','delivered') AND p.delivery_type='manual'
        GROUP BY oi.product_id, oi.variant_id");
    $manualItems->execute($ids);
    $restoreVariant=[];
    $restoreSold=[];
    foreach($manualItems->fetchAll() as $mi){
        $qty=max(0,(int)$mi['qty']);
        if($qty<=0) continue;
        $vid=(int)$mi['variant_id'];
        $pid=(int)$mi['product_id'];
        $restoreVariant[$vid]=($restoreVariant[$vid] ?? 0)+$qty;
        $restoreSold[$pid]=($restoreSold[$pid] ?? 0)+$qty;
    }
    $bump=$pdo->prepare('UPDATE product_variants SET manual_stock=manual_stock+?, updated_at=NOW() WHERE id=?');
    foreach($restoreVariant as $vid=>$qty) $bump->execute([$qty,$vid]);
    $unSold=$pdo->prepare('UPDATE products SET sold_count=GREATEST(0, sold_count-?) WHERE id=?');
    foreach($restoreSold as $pid=>$qty) $unSold->execute([$qty,$pid]);

    /* Baris stok digital yang sudah terjual kembali tersedia (transaksinya dihapus). */
    $pdo->prepare("UPDATE product_stocks SET status='available',sold_order_id=NULL,sold_at=NULL WHERE status='sold' AND sold_order_id IN ($ph)")->execute($ids);
    $pdo->prepare("UPDATE product_stocks SET status='available',reserved_order_id=NULL,reserved_order_item_id=NULL,reserved_at=NULL,reserved_until=NULL WHERE status='reserved' AND reserved_order_id IN ($ph)")->execute($ids);
    $pdo->prepare("DELETE FROM telegram_delivery_queue WHERE order_id IN ($ph)")->execute($ids);
    try{$pdo->prepare("DELETE FROM order_delivery_queue WHERE order_id IN ($ph)")->execute($ids);}catch(Throwable $e){}
    $pdo->prepare("DELETE ds FROM delivered_stocks ds INNER JOIN order_items oi ON ds.order_item_id=oi.id WHERE oi.order_id IN ($ph)")->execute($ids);
    $pdo->prepare("DELETE FROM order_items WHERE order_id IN ($ph)")->execute($ids);
    $st=$pdo->prepare("DELETE FROM orders WHERE id IN ($ph)");
    $st->execute($ids);
    return $st->rowCount();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=(string)($_POST['action'] ?? '');
    $invoice=trim((string)($_POST['invoice'] ?? ''));

    if($action==='paid' && $invoice!==''){
        try{
            $paidOrder=mark_order_paid($invoice,'manual_admin');
            if(!empty($paidOrder['_fulfillment_new'])) telegram_after_order_paid($paidOrder);
            admin_redirect('/admin/orders.php?detail='.rawurlencode($invoice),'Pesanan berhasil diproses dan stok digital telah dikirim.');
        }catch(Throwable $e){
            $err=$e->getMessage();
            $_GET['detail']=$invoice;
        }
    }elseif($action==='cancel' && $invoice!==''){
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT id FROM orders WHERE invoice=? AND status="pending" FOR UPDATE');
        $st->execute([$invoice]);
        $cancelId=(int)($st->fetchColumn() ?: 0);
        if($cancelId){
            $pdo->prepare('UPDATE orders SET status="cancelled" WHERE id=?')->execute([$cancelId]);
            release_order_stock_reservations($cancelId);
            $pdo->commit();
            admin_redirect('/admin/orders.php','Pesanan berhasil dibatalkan.');
        }
        if($pdo->inTransaction()) $pdo->rollBack();
        $err='Pesanan tidak dapat dibatalkan karena statusnya bukan pending atau data tidak ditemukan.';
    }elseif($action==='delete' && $invoice!==''){
        try{
            $pdo->beginTransaction();
            $st=$pdo->prepare('SELECT id FROM orders WHERE invoice=? FOR UPDATE');
            $st->execute([$invoice]);
            $orderId=(int)($st->fetchColumn() ?: 0);
            if(!$orderId) throw new RuntimeException('Pesanan tidak ditemukan.');
            admin_delete_order_ids($pdo,[$orderId]);
            $pdo->commit();
            admin_redirect('/admin/orders.php','Pesanan berhasil dihapus.');
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            $err=$e->getMessage();
        }
    }elseif($action==='delete_selected'){
        $invoices=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),$_POST['invoices'] ?? [])));
        if(!$invoices){
            $err='Pilih minimal satu pesanan terlebih dahulu.';
        }else{
            try{
                $pdo->beginTransaction();
                $ph=implode(',',array_fill(0,count($invoices),'?'));
                $st=$pdo->prepare("SELECT id FROM orders WHERE invoice IN ($ph) FOR UPDATE");
                $st->execute($invoices);
                $ids=array_map('intval',array_column($st->fetchAll(),'id'));
                $deleted=admin_delete_order_ids($pdo,$ids);
                $pdo->commit();
                admin_redirect('/admin/orders.php',$deleted.' pesanan berhasil dihapus.');
            }catch(Throwable $e){
                if($pdo->inTransaction()) $pdo->rollBack();
                $err=$e->getMessage();
            }
        }
    }elseif($action==='delete_all'){
        try{
            $pdo->beginTransaction();
            $ids=array_map('intval',array_column($pdo->query('SELECT id FROM orders FOR UPDATE')->fetchAll(),'id'));
            $deleted=admin_delete_order_ids($pdo,$ids);
            $pdo->commit();
            admin_redirect('/admin/orders.php',$deleted.' pesanan berhasil dihapus.');
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            $err=$e->getMessage();
        }
    }
}

$detail=null;
$stocks=[];
$warranties=[];
$contactDeliveries=[];
if(isset($_GET['detail']) && trim((string)$_GET['detail'])!==''){
    $detailInvoice=trim((string)$_GET['detail']);
    $st=$pdo->prepare('SELECT * FROM orders WHERE invoice=?');
    $st->execute([$detailInvoice]);
    $detail=$st->fetch();
    if($detail){
        $st=$pdo->prepare('SELECT oi.*,ds.content FROM order_items oi LEFT JOIN delivered_stocks ds ON ds.order_item_id=oi.id WHERE oi.order_id=? ORDER BY oi.id,ds.id');
        $st->execute([$detail['id']]);
        $stocks=$st->fetchAll();
        $wst=$pdo->prepare('SELECT product_name,qty,warranty_label,warranty_days,warranty_started_at,warranty_expires_at FROM order_items WHERE order_id=? ORDER BY id');
        $wst->execute([$detail['id']]);
        $warranties=$wst->fetchAll();
        try{
            $dst=$pdo->prepare('SELECT channel,recipient,status,attempts,last_error,sent_at FROM order_delivery_queue WHERE order_id=? ORDER BY id');
            $dst->execute([$detail['id']]);
            $contactDeliveries=$dst->fetchAll();
        }catch(Throwable $e){}
        $revealedOrderPassword=get_admin_order_password($detail);
    }else{
        $err='Pesanan yang diminta tidak ditemukan.';
    }
}

$q=trim((string)($_GET['q'] ?? ''));
$status=trim((string)($_GET['status'] ?? ''));
if(!array_key_exists($status,$statusOptions)) $status='';
$where=[];
$params=[];
if($q!==''){
    $where[]='(o.invoice LIKE ? OR o.buyer_name LIKE ? OR o.buyer_email LIKE ? OR EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id=o.id AND oi2.product_name LIKE ?))';
    $like='%'.$q.'%';
    array_push($params,$like,$like,$like,$like);
}
if($status==='success'){
    $where[]='o.status IN ("paid","delivered")';
}elseif($status!==''){
    $where[]='o.status=?';
    $params[]=$status;
}
$sql="SELECT o.*,(SELECT GROUP_CONCAT(CONCAT(oi.product_name,' x',oi.qty) ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id=o.id) AS product_names FROM orders o".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY o.id DESC LIMIT 500";
$st=$pdo->prepare($sql);
$st->execute($params);
$rows=$st->fetchAll();
$closeParams=[];
if($q!=='') $closeParams['q']=$q;
if($status!=='') $closeParams['status']=$status;
$closeUrl='/admin/orders.php'.($closeParams?'?'.http_build_query($closeParams):'');

admin_page_start('Pesanan','Kelola pembayaran, status, dan stok digital pembeli.');
?>
<?php if($msg):?><div class="alert ok" role="status"><span>✓</span><div><?=e($msg)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>
<?php if($err):?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>

<div class="orders-filterbar">
    <form class="adm-search orders-search" method="get">
        <span><?=admin_icon('search')?></span>
        <input type="search" name="q" value="<?=e($q)?>" placeholder="Cari invoice, nama, email, atau produk...">
        <?php if($status!==''):?><input type="hidden" name="status" value="<?=e($status)?>"><?php endif;?>
        <button class="adm-btn soft" type="submit">Cari</button>
    </form>
    <form class="orders-status-filter" method="get">
        <?php if($q!==''):?><input type="hidden" name="q" value="<?=e($q)?>"><?php endif;?>
        <label for="orderStatusFilter">Status</label>
        <select id="orderStatusFilter" name="status" onchange="this.form.submit()">
            <?php foreach($statusOptions as $value=>$label):?><option value="<?=e($value)?>" <?=$status===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?>
        </select>
    </form>
</div>

<?php if($detail):?>
<section class="adm-card order-detail-card">
    <div class="adm-card-head">
        <div><h3>Detail <?=e($detail['invoice'])?></h3><p><?=e($detail['buyer_name'])?> · <?=e($detail['buyer_email'])?><?php if(!empty($detail['buyer_whatsapp'])):?> · <?=e($detail['buyer_whatsapp'])?><?php endif;?></p></div>
        <a class="adm-btn soft" href="<?=e($closeUrl)?>">Tutup Detail</a>
    </div>
    <div class="order-detail-grid">
        <div class="order-info-box"><span>Total</span><b><?=rupiah($detail['total'])?></b></div>
        <div class="order-info-box"><span>Status</span><b><?=admin_status_badge($detail['status'])?></b></div>
        <div class="order-info-box"><span>Tanggal</span><b><?=e(!empty($detail['created_at']) ? date('d/m/Y H:i', strtotime($detail['created_at'])).' WIB' : '-')?></b></div>
        <div class="order-info-box order-password-box">
            <span>Password Pesanan</span>
            <?php if($revealedOrderPassword!==''):?>
                <code><?=e($revealedOrderPassword)?></code>
            <?php else:?>
                <small>Password asli tidak tersedia untuk pesanan ini. Pesanan lama yang hanya menyimpan hash tidak dapat ditampilkan ulang.</small>
            <?php endif;?>
        </div>
    </div>
    <h3>Pengiriman Otomatis</h3>
    <div class="order-warranty-list">
    <?php if($contactDeliveries):foreach($contactDeliveries as $delivery):?>
        <div class="sale-item order-warranty-item">
            <div><b><?=e(strtoupper((string)$delivery['channel']))?></b><p><?=e($delivery['recipient'])?><?php if(!empty($delivery['last_error'])):?> · <?=e($delivery['last_error'])?><?php endif;?></p></div>
            <span class="order-warranty-status"><?=admin_status_badge((string)$delivery['status'])?></span>
        </div>
    <?php endforeach;else:?><div class="adm-empty"><div><b>Belum ada antrean email / WhatsApp</b><p>Antrean dibuat setelah pesanan berhasil delivered dan kanal pengiriman diaktifkan.</p></div></div><?php endif;?>
    </div>
    <h3>Garansi Pesanan</h3>
    <div class="order-warranty-list">
    <?php if($warranties):foreach($warranties as $w):?>
        <div class="sale-item order-warranty-item">
            <div><b><?=e($w['product_name'])?> <small>x <?=intval($w['qty'])?></small></b><p><?=e(trim((string)$w['warranty_label']) ?: ((int)$w['warranty_days']>0 ? ((int)$w['warranty_days'].' Hari') : 'Tanpa garansi'))?></p></div>
            <span class="order-warranty-status"><?=e(warranty_status_text($w))?></span>
        </div>
    <?php endforeach;else:?><div class="adm-empty"><div><b>Belum ada data garansi</b></div></div><?php endif;?>
    </div>
    <h3>Item & Stok Digital</h3>
    <?php if($stocks):foreach($stocks as $stock):$stockContent=(string)($stock['content'] ?? 'Belum terbuka / belum delivered');?>
        <div class="sale-item order-stock-item">
            <div class="order-stock-head"><b><?=e($stock['product_name'])?> <small>x <?=e($stock['qty'])?></small></b><button class="order-copy-btn" type="button" data-copy-order-stock="<?=e($stockContent)?>" title="Salin data stok"><span><?=admin_icon('copy')?></span><b>Salin</b></button></div>
            <pre><?=e($stockContent)?></pre>
        </div>
    <?php endforeach;else:?>
        <div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('lock')?></div><b>Stok belum terbuka</b><p>Produk akan diambil dari stok digital setelah pembayaran berhasil.</p></div></div>
    <?php endif;?>
</section>
<?php endif;?>

<form id="ordersBulkForm" method="post" class="bulk-form">
    <?=csrf_field()?>
    <div class="bulk-toolbar">
        <label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="ordersBulkForm"> Pilih semua</label>
        <div class="bulk-actions">
            <button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" onclick="return confirm('Hapus pesanan terpilih? Tindakan ini tidak dapat dibatalkan.')">Hapus Terpilih</button>
            <button class="adm-btn danger" type="submit" name="action" value="delete_all" onclick="return confirm('Hapus semua pesanan? Tindakan ini tidak dapat dibatalkan.')">Hapus Semua</button>
        </div>
    </div>
</form>

<div class="table-wrap orders-table responsive-list">
<table>
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Invoice</th><th>Pembeli</th><th>Total</th><th>Status</th><th>Tanggal</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if($rows):foreach($rows as $order):
        $detailParams=['detail'=>$order['invoice']];
        if($q!=='') $detailParams['q']=$q;
        if($status!=='') $detailParams['status']=$status;
    ?>
        <tr>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><input form="ordersBulkForm" class="bulk-row-check" type="checkbox" name="invoices[]" value="<?=e($order['invoice'])?>"></div></td>
            <td data-label="Invoice"><div class="cell-value"><b><?=e($order['invoice'])?></b><small><?=($order['sales_channel'] ?? 'website')==='telegram'?'Telegram':'Website'?></small></div></td>
            <td data-label="Pembeli"><div class="cell-value"><?=e($order['buyer_name'])?><small><?=e($order['buyer_email'])?></small><small class="order-product-summary"><?=e($order['product_names'] ?: '-')?></small></div></td>
            <td data-label="Total"><div class="cell-value"><?=rupiah($order['total'])?></div></td>
            <td data-label="Status"><div class="cell-value"><?=admin_status_badge($order['status'])?></div></td>
            <td data-label="Tanggal"><div class="cell-value"><?=e(!empty($order['created_at']) ? date('d/m/Y H:i', strtotime($order['created_at'])).' WIB' : '-')?></div></td>
            <td data-label="Aksi">
                <div class="cell-value"><div class="table-actions orders-actions">
                    <a class="order-action-btn detail" href="?<?=e(http_build_query($detailParams))?>">Detail</a>
                    <?php if($order['status']==='pending'):?>
                        <form method="post" onsubmit="return confirm('Set paid dan kirim produk digital sekarang?')"><?=csrf_field()?><input type="hidden" name="action" value="paid"><input type="hidden" name="invoice" value="<?=e($order['invoice'])?>"><button class="order-action-btn paid" type="submit">Set Paid</button></form>
                        <form method="post" onsubmit="return confirm('Batalkan pesanan ini?')"><?=csrf_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="invoice" value="<?=e($order['invoice'])?>"><button class="order-action-btn cancel" type="submit">Cancel</button></form>
                    <?php endif;?>
                    <form method="post" onsubmit="return confirm('Hapus pesanan ini? Tindakan ini tidak dapat dibatalkan.')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="invoice" value="<?=e($order['invoice'])?>"><button class="order-action-btn delete" type="submit">Hapus</button></form>
                </div></div>
            </td>
        </tr>
    <?php endforeach;else:?>
        <tr><td colspan="7"><div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('shopping-cart')?></div><b>Pesanan tidak ditemukan</b><p>Ubah kata kunci atau filter untuk melihat hasil lainnya.</p></div></div></td></tr>
    <?php endif;?>
    </tbody>
</table>
</div>
<?php admin_page_end(); ?>
