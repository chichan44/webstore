<?php
require_once __DIR__.'/../includes/telegram_bot.php';
require_once __DIR__.'/../includes/order_delivery.php';
telegram_ensure_schema();
if(PHP_SAPI!=='cli'){
    $expected=(string)setting('telegram_cron_secret','');
    $provided=(string)($_GET['key'] ?? '');
    if($expected==='' || $provided==='' || !hash_equals($expected,$provided))json_out(['ok'=>false,'message'=>'Unauthorized'],403);
}
try{
    $result=telegram_process_worker(25);
    $result=array_merge($result,order_delivery_process_queue(50));
    if(PHP_SAPI==='cli')echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    else json_out(['ok'=>true]+$result);
}catch(Throwable $e){
    if(PHP_SAPI==='cli'){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);} 
    json_out(['ok'=>false,'message'=>$e->getMessage()],500);
}
