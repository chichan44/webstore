<?php
require_once __DIR__.'/../includes/telegram_bot.php';
telegram_ensure_schema();

$botId=(int)($_GET['bot'] ?? 0);
$key=(string)($_GET['key'] ?? '');
$bot=$botId?telegram_get_bot($botId):null;
if(!$bot || $key==='' || !hash_equals((string)$bot['webhook_key'],$key)){
    json_out(['ok'=>false,'message'=>'Webhook tidak dikenali.'],404);
}
$secret=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if($secret==='' && function_exists('getallheaders')){
    foreach((array)getallheaders() as $headerName=>$headerValue){
        if(strcasecmp((string)$headerName,'X-Telegram-Bot-Api-Secret-Token')===0){$secret=(string)$headerValue;break;}
    }
}
if($secret==='' && function_exists('apache_request_headers')){
    foreach((array)apache_request_headers() as $headerName=>$headerValue){
        if(strcasecmp((string)$headerName,'X-Telegram-Bot-Api-Secret-Token')===0){$secret=(string)$headerValue;break;}
    }
}
if($secret==='' || !hash_equals((string)$bot['webhook_secret'],$secret)){
    telegram_log($botId,'in','webhook',null,null,'rejected',null,'Secret webhook tidak valid.');
    json_out(['ok'=>false,'message'=>'Unauthorized'],403);
}
$raw=(string)file_get_contents('php://input');
$update=json_decode($raw,true);
if(!is_array($update))json_out(['ok'=>false,'message'=>'Payload tidak valid.'],400);
try{
    telegram_handle_update($bot,$update);
    try{$pdo->prepare('UPDATE telegram_bots SET last_error=NULL WHERE id=?')->execute([$botId]);}catch(Throwable $x){}
    json_out(['ok'=>true]);
}catch(Throwable $e){
    global $pdo;
    if(!empty($update['update_id'])){
        try{$pdo->prepare('DELETE FROM telegram_updates WHERE bot_id=? AND update_id=?')->execute([$botId,(int)$update['update_id']]);}catch(Throwable $x){}
    }
    telegram_log($botId,'system','webhook_error',null,null,'failed',$update,$e->getMessage());
    try{$pdo->prepare('UPDATE telegram_bots SET last_error=?,updated_at=NOW() WHERE id=?')->execute([substr($e->getMessage(),0,1000),$botId]);}catch(Throwable $x){}
    json_out(['ok'=>false,'message'=>'Temporary processing error'],500);
}
