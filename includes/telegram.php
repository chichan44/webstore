<?php
function send_order_telegram($order_id){
    global $pdo;
    $token=setting('telegram_bot_token',''); $chat=setting('telegram_chat_id','');
    if(!$token || !$chat) return false;
    $st=$pdo->prepare('SELECT * FROM orders WHERE id=?'); $st->execute([$order_id]); $o=$st->fetch(); if(!$o) return false;
    $text="✅ Pesanan paid/delivered\nInvoice: {$o['invoice']}\nNama: {$o['buyer_name']}\nEmail: {$o['buyer_email']}\nTotal: ".rupiah($o['total']);
    $url="https://api.telegram.org/bot{$token}/sendMessage";
    $payload=http_build_query(['chat_id'=>$chat,'text'=>$text]);
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$payload,'timeout'=>5]]);
    return @file_get_contents($url,false,$ctx)!==false;
}
?>
