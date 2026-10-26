<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/_layout.php';

$range=(int)($_GET['range'] ?? 7);
if(!in_array($range,[7,14,30],true)) $range=7;

$productCount=(int)$pdo->query('SELECT COUNT(*) c FROM products WHERE status=1')->fetch()['c'];
$orderCount=(int)$pdo->query('SELECT COUNT(*) c FROM orders')->fetch()['c'];
$paidCount=(int)$pdo->query('SELECT COUNT(*) c FROM orders WHERE status IN ("paid","delivered")')->fetch()['c'];
$omzet=(float)$pdo->query('SELECT COALESCE(SUM(total),0) c FROM orders WHERE status IN ("paid","delivered")')->fetch()['c'];
$recentOrders=$pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 5')->fetchAll();

$trendMap=[];
for($i=$range-1;$i>=0;$i--){
    $key=date('Y-m-d',strtotime('-'.$i.' days'));
    $trendMap[$key]=0;
}
try{
    $sql='SELECT DATE(COALESCE(delivered_at,paid_at,created_at)) d, COALESCE(SUM(total),0) total
          FROM orders
          WHERE status IN ("paid","delivered")
          AND COALESCE(delivered_at,paid_at,created_at) >= DATE_SUB(CURDATE(), INTERVAL '.(int)($range-1).' DAY)
          GROUP BY DATE(COALESCE(delivered_at,paid_at,created_at))';
    foreach($pdo->query($sql)->fetchAll() as $row){
        if(isset($trendMap[$row['d']])) $trendMap[$row['d']]=(float)$row['total'];
    }
}catch(Throwable $e){
    try{
        $sql='SELECT DATE(created_at) d, COALESCE(SUM(total),0) total FROM orders WHERE status IN ("paid","delivered") AND created_at >= DATE_SUB(CURDATE(), INTERVAL '.(int)($range-1).' DAY) GROUP BY DATE(created_at)';
        foreach($pdo->query($sql)->fetchAll() as $row){
            if(isset($trendMap[$row['d']])) $trendMap[$row['d']]=(float)$row['total'];
        }
    }catch(Throwable $ignored){}
}

$trendValues=array_values($trendMap);
$allTrendLabels=array_map(static fn($date)=>date('j M',strtotime($date)),array_keys($trendMap));
$labelStep=$range===30?5:($range===14?2:1);
$trendLabels=[];
foreach($allTrendLabels as $idx=>$label) $trendLabels[]=$idx%$labelStep===0 || $idx===count($allTrendLabels)-1 ? $label : '';
$trendMax=max(100000,(float)max($trendValues ?: [0]));
$chartPoints=[];
$pointCount=max(1,count($trendValues)-1);
foreach($trendValues as $idx=>$value){
    $x=24+(($idx/$pointCount)*552);
    $y=218-(($value/$trendMax)*190);
    $chartPoints[]=round($x,1).','.round($y,1);
}
$trendTotal=array_sum($trendValues);
$trendAvg=$range>0?$trendTotal/$range:0;
$me=admin_user();

admin_page_start('Dashboard');
?>

<section class="adm-stats dashboard-stats dashboard-kpi-grid">
    <a class="adm-stat dashboard-kpi" href="/admin/products.php" style="--tint:#eaf3ff;--color:#2678ff">
        <div class="adm-stat-icon"><?=admin_icon('package')?></div>
        <div class="dashboard-kpi-copy"><span>Produk Aktif</span><strong><?=e($productCount)?></strong><small>Produk yang tampil di toko</small></div>
        <i class="dashboard-kpi-arrow">›</i>
    </a>
    <a class="adm-stat dashboard-kpi" href="/admin/orders.php" style="--tint:#f0edff;--color:#6457ff">
        <div class="adm-stat-icon"><?=admin_icon('shopping-cart')?></div>
        <div class="dashboard-kpi-copy"><span>Total Pesanan</span><strong><?=e($orderCount)?></strong><small>Seluruh pesanan masuk</small></div>
        <i class="dashboard-kpi-arrow">›</i>
    </a>
    <a class="adm-stat dashboard-kpi" href="/admin/orders.php?status=success" style="--tint:#e8f8ef;--color:#13a46f">
        <div class="adm-stat-icon"><?=admin_icon('check-card')?></div>
        <div class="dashboard-kpi-copy"><span>Terbayar</span><strong><?=e($paidCount)?></strong><small>Sudah dibayar atau terkirim</small></div>
        <i class="dashboard-kpi-arrow">›</i>
    </a>
    <a class="adm-stat dashboard-kpi" href="/admin/orders.php?status=success" style="--tint:#fff1df;--color:#f28b2d">
        <div class="adm-stat-icon"><?=admin_icon('wallet')?></div>
        <div class="dashboard-kpi-copy"><span>Omzet</span><strong><?=rupiah($omzet)?></strong><small>Total transaksi sukses</small></div>
        <i class="dashboard-kpi-arrow">›</i>
    </a>
</section>

<section class="dashboard-main-grid">
    <article class="adm-card dashboard-orders dashboard-panel dashboard-orders-panel">
        <div class="adm-card-head dashboard-panel-head">
            <div><h3>Pesanan Terbaru</h3><p>Pesanan terbaru yang masuk ke toko.</p></div>
            <a class="adm-btn soft" href="/admin/orders.php">Lihat semua pesanan →</a>
        </div>
        <div class="dashboard-table table-wrap dashboard-order-table">
            <table>
                <thead><tr><th>Invoice</th><th>Pembeli</th><th>Total</th><th>Status</th><th>Tanggal</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if($recentOrders):foreach($recentOrders as $order):$initial=strtoupper(substr((string)($order['buyer_name'] ?: 'P'),0,1));?>
                    <tr>
                        <td><a class="adm-link invoice-link" href="/admin/orders.php?detail=<?=e($order['invoice'])?>"><?=e($order['invoice'])?></a></td>
                        <td><div class="buyer-cell"><span><?=e($initial)?></span><div><b><?=e($order['buyer_name'])?></b><small><?=e($order['buyer_email'])?></small></div></div></td>
                        <td><?=rupiah($order['total'])?></td>
                        <td><?=admin_status_badge($order['status'])?></td>
                        <td><span class="dashboard-date-cell"><?=e(date('Y-m-d',strtotime($order['created_at'])))?></span><small><?=e(date('H:i:s',strtotime($order['created_at'])))?></small></td>
                        <td><a class="detail-pill" href="/admin/orders.php?detail=<?=e($order['invoice'])?>">Detail</a></td>
                    </tr>
                <?php endforeach;else:?>
                    <tr><td colspan="6"><div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('shopping-cart')?></div><b>Belum ada pesanan terbaru</b><p>Pesanan yang masuk akan muncul di sini.</p></div></div></td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
        <div class="dashboard-order-list">
            <?php if($recentOrders):foreach($recentOrders as $order):$initial=strtoupper(substr((string)($order['buyer_name'] ?: 'P'),0,1));?>
                <a class="dash-order-card" href="/admin/orders.php?detail=<?=e($order['invoice'])?>">
                    <span class="dash-avatar"><?=e($initial)?></span>
                    <div><b><?=e($order['invoice'])?></b><small><?=e($order['buyer_name'])?> · <?=e($order['buyer_email'])?></small></div>
                    <div class="dash-order-meta"><?=admin_status_badge($order['status'])?><strong><?=rupiah($order['total'])?></strong><small><?=e(display_datetime_gmt7($order['created_at'] ?? ''))?></small></div>
                    <i>›</i>
                </a>
            <?php endforeach;endif;?>
        </div>
    </article>

    <aside class="adm-card dashboard-panel dashboard-trend-panel">
        <div class="dashboard-chart-head">
            <div><h3>Tren Penjualan</h3><p><?=e($range)?> hari terakhir</p></div>
            <form method="get">
                <select name="range" onchange="this.form.submit()" aria-label="Rentang tren penjualan">
                    <option value="7" <?=$range===7?'selected':''?>>7 Hari</option>
                    <option value="14" <?=$range===14?'selected':''?>>14 Hari</option>
                    <option value="30" <?=$range===30?'selected':''?>>30 Hari</option>
                </select>
            </form>
        </div>
        <div class="dashboard-chart-wrap" aria-label="Grafik tren penjualan <?=e($range)?> hari terakhir">
            <svg viewBox="0 0 600 260" role="img" aria-hidden="true" preserveAspectRatio="none">
                <line x1="24" y1="28" x2="576" y2="28"></line>
                <line x1="24" y1="91" x2="576" y2="91"></line>
                <line x1="24" y1="154" x2="576" y2="154"></line>
                <line x1="24" y1="218" x2="576" y2="218"></line>
                <polyline points="<?=e(implode(' ',$chartPoints))?>"></polyline>
                <?php foreach($chartPoints as $point):[$cx,$cy]=explode(',',$point);?><circle cx="<?=e($cx)?>" cy="<?=e($cy)?>" r="4"></circle><?php endforeach;?>
            </svg>
            <div class="chart-y"><span><?=rupiah($trendMax)?></span><span><?=rupiah($trendMax*.75)?></span><span><?=rupiah($trendMax*.5)?></span><span>0</span></div>
            <div class="chart-x"><?php foreach($trendLabels as $label):?><span><?=e($label)?></span><?php endforeach;?></div>
        </div>
        <div class="dashboard-chart-summary">
            <div><span>Total Omzet</span><b><?=rupiah($trendTotal)?></b></div>
            <div><span>Rata-rata per hari</span><b><?=rupiah($trendAvg)?></b></div>
        </div>
    </aside>
</section>
<?php admin_page_end(); ?>
