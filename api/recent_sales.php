<?php
require_once __DIR__.'/../includes/helpers.php';
$rows=$pdo->query('SELECT oi.product_name, oi.product_id, o.buyer_name, COALESCE(o.paid_at,o.delivered_at,o.created_at) created_at, p.thumbnail FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.id=oi.product_id WHERE o.status IN ("paid","delivered") ORDER BY COALESCE(o.paid_at,o.delivered_at,o.created_at) DESC, oi.id DESC LIMIT 5')->fetchAll();
$items=[];
foreach($rows as $r){
    $ts=strtotime($r['created_at']);
    $mins=max(1,round((time()-$ts)/60));
    $items[]=[
        'product_name'=>(string)$r['product_name'],
        'product_id'=>(int)$r['product_id'],
        'buyer_name'=>mb_substr((string)$r['buyer_name'],0,4).'***',
        'time_text'=>$mins<60?$mins.' menit lalu':date('d M H:i',$ts),
        'created_at'=>(string)$r['created_at'],
        'thumbnail'=>$r['thumbnail'] ?: ''
    ];
}
json_out(['ok'=>true,'items'=>$items]);
