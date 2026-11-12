<?php
require_once __DIR__.'/../includes/helpers.php';
ensure_product_extra_columns();
$ids=$_GET['ids']??''; $arr=array_filter(array_map('intval',explode(',',$ids)));
$vids=array_filter(array_map('intval',explode(',',(string)($_GET['variant_ids']??''))));
$variants=[];
if($vids){
    ensure_product_variant_columns();
    $vin=implode(',',array_fill(0,count($vids),'?'));
    $st=$pdo->prepare("SELECT v.*,p.is_flash_sale,p.flash_ends_at FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.status=1 AND p.status=1 AND p.is_archived=0 AND v.id IN ($vin)");
    $st->execute($vids);
    foreach($st->fetchAll() as $row){
        $price=product_variant_price($row,$row);
        if($price<=0) continue;
        $variants[]=[
            'id'=>(int)$row['id'],
            'product_id'=>(int)$row['product_id'],
            'price'=>$price,
            'original_price'=>product_variant_compare_price($row,$row),
            'discount_pct'=>product_variant_discount_pct($row,$row),
        ];
    }
}
if(!$arr) json_out(['ok'=>true,'products'=>[],'variants'=>$variants]);
$in=implode(',',array_fill(0,count($arr),'?'));
$st=$pdo->prepare("SELECT id,name,price,thumbnail,delivery_type,product_features,warranty_label,warranty_days,CASE WHEN COALESCE(delivery_type,'instant')='manual' THEN GREATEST(COALESCE((SELECT SUM(v.manual_stock) FROM product_variants v WHERE v.product_id=products.id AND v.status=1),0)-COALESCE((SELECT SUM(oir.qty) FROM order_items oir JOIN orders orr ON orr.id=oir.order_id JOIN product_variants v2 ON v2.id=oir.variant_id WHERE v2.product_id=products.id AND orr.status='pending' AND (orr.payment_expires_at IS NULL OR orr.payment_expires_at>NOW())),0),0) ELSE (SELECT COUNT(*) FROM product_stocks s JOIN product_variants v ON v.id=s.variant_id WHERE s.product_id=products.id AND s.status='available' AND v.status=1) END AS stock_count FROM products WHERE status=1 AND is_archived=0 AND id IN ($in) HAVING stock_count > 0");
$st->execute($arr); json_out(['ok'=>true,'products'=>$st->fetchAll(),'variants'=>$variants]);
?>
