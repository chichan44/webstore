<?php
require_once __DIR__.'/../includes/helpers.php';
ensure_order_security_columns();
ensure_product_extra_columns();

$q=trim((string)($_GET['q']??''));
if($q==='' || strlen($q)>120 || !preg_match('/^[A-Za-z0-9._-]+$/',$q)) json_out(['ok'=>false,'message'=>'Invoice tidak valid'],422);
if(rate_limit_hit('public-track',$q,30,300)) json_out(['ok'=>false,'message'=>'Terlalu banyak permintaan. Coba lagi beberapa saat.'],429);

$st=$pdo->prepare('SELECT invoice,total,payment_total,status,created_at,paid_at,delivered_at,payment_expires_at FROM orders WHERE invoice=? ORDER BY id DESC LIMIT 1');
$st->execute([$q]);
$o=$st->fetch();
if(!$o) json_out(['ok'=>false,'message'=>'Pesanan tidak ditemukan'],404);

json_out(['ok'=>true,'order'=>[
    'invoice'=>(string)$o['invoice'],
    'total'=>(float)($o['payment_total']?:$o['total']),
    'status'=>(string)$o['status'],
    'created_at'=>$o['created_at'],
    'paid_at'=>$o['paid_at'],
    'delivered_at'=>$o['delivered_at'],
    'payment_expires_at'=>$o['payment_expires_at'],
]]);
