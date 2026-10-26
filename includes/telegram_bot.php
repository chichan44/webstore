<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/casaku.php';

function telegram_ensure_schema(): void {
    global $pdo;
    static $done=false;
    if($done) return;
    $done=true;
    ensure_order_security_columns();
    ensure_product_extra_columns();

    $queries=[
        "CREATE TABLE IF NOT EXISTS telegram_bots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bot_name VARCHAR(120) NOT NULL,
            bot_username VARCHAR(120) NULL,
            bot_user_id BIGINT NULL,
            token_enc TEXT NOT NULL,
            token_hint VARCHAR(24) NULL,
            webhook_secret VARCHAR(180) NOT NULL,
            webhook_key VARCHAR(80) NOT NULL,
            status ENUM('active','inactive','disabled') NOT NULL DEFAULT 'inactive',
            last_test_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_telegram_bot_username (bot_username),
            KEY idx_telegram_bot_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            telegram_user_id BIGINT NOT NULL,
            first_name VARCHAR(160) NULL,
            last_name VARCHAR(160) NULL,
            username VARCHAR(120) NULL,
            language_code VARCHAR(16) NULL,
            buyer_email VARCHAR(190) NULL,
            buyer_whatsapp VARCHAR(80) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_telegram_user_id (telegram_user_id),
            KEY idx_telegram_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_bot_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bot_id BIGINT UNSIGNED NOT NULL,
            telegram_user_id BIGINT NOT NULL,
            chat_id BIGINT NOT NULL,
            state VARCHAR(80) NULL,
            state_data LONGTEXT NULL,
            last_ui_message_ids LONGTEXT NULL,
            keyboard_message_id BIGINT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            started_at DATETIME NULL,
            last_interaction_at DATETIME NULL,
            UNIQUE KEY uq_telegram_bot_user (bot_id,telegram_user_id),
            KEY idx_telegram_bot_chat (bot_id,chat_id),
            KEY idx_telegram_bot_active (bot_id,is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_updates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bot_id BIGINT UNSIGNED NOT NULL,
            update_id BIGINT NOT NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uq_telegram_update (bot_id,update_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bot_id BIGINT UNSIGNED NULL,
            direction ENUM('in','out','system') NOT NULL DEFAULT 'system',
            event_type VARCHAR(80) NULL,
            telegram_user_id BIGINT NULL,
            chat_id BIGINT NULL,
            status VARCHAR(30) NULL,
            payload LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NULL,
            KEY idx_telegram_log_bot (bot_id,created_at),
            KEY idx_telegram_log_user (telegram_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_delivery_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            bot_id BIGINT UNSIGNED NULL,
            telegram_user_id BIGINT NULL,
            chat_id BIGINT NULL,
            status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            sent_at DATETIME NULL,
            UNIQUE KEY uq_telegram_delivery_order (order_id),
            KEY idx_telegram_delivery_work (status,next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_broadcasts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bot_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(180) NULL,
            message TEXT NOT NULL,
            image_url TEXT NULL,
            button_text VARCHAR(80) NULL,
            button_url TEXT NULL,
            status ENUM('draft','queued','sending','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
            total_count INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            scheduled_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NULL,
            KEY idx_telegram_broadcast_status (status,scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_broadcast_recipients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            broadcast_id BIGINT UNSIGNED NOT NULL,
            telegram_user_id BIGINT NOT NULL,
            chat_id BIGINT NOT NULL,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            last_error TEXT NULL,
            sent_at DATETIME NULL,
            UNIQUE KEY uq_telegram_broadcast_recipient (broadcast_id,telegram_user_id),
            KEY idx_telegram_broadcast_recipient_work (status,next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS telegram_migration_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            old_bot_id BIGINT UNSIGNED NOT NULL,
            new_bot_id BIGINT UNSIGNED NOT NULL,
            telegram_user_id BIGINT NOT NULL,
            chat_id BIGINT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_enc TEXT NOT NULL,
            status ENUM('pending','sent','failed','used') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            used_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uq_telegram_migration_user (old_bot_id,new_bot_id,telegram_user_id),
            UNIQUE KEY uq_telegram_migration_token (token_hash),
            KEY idx_telegram_migration_work (status,next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach($queries as $sql){ try{$pdo->exec($sql);}catch(Throwable $e){} }
    try{$pdo->exec("ALTER TABLE telegram_bots ADD UNIQUE KEY uq_telegram_webhook_key (webhook_key)");}catch(Throwable $e){}
    try{$pdo->exec("ALTER TABLE telegram_bot_users ADD COLUMN last_ui_message_ids LONGTEXT NULL AFTER state_data");}catch(Throwable $e){}
    try{$pdo->exec("ALTER TABLE telegram_bot_users ADD COLUMN keyboard_message_id BIGINT NULL AFTER last_ui_message_ids");}catch(Throwable $e){}
    try{
        $expiryNoticeColumnAdded=false;
        if(!db_column_exists('orders','telegram_expired_notified_at')){
            $pdo->exec("ALTER TABLE orders ADD telegram_expired_notified_at DATETIME NULL");
            $expiryNoticeColumnAdded=true;
        }
        // Hindari mengirim notifikasi massal untuk transaksi lama saat fitur pertama kali dipasang.
        if($expiryNoticeColumnAdded){
            $pdo->exec("UPDATE orders SET telegram_expired_notified_at=NOW() WHERE sales_channel='telegram' AND status='failed' AND payment_expires_at IS NOT NULL AND payment_expires_at<NOW()");
        }
    }catch(Throwable $e){}
    try{$pdo->exec("ALTER TABLE orders ADD KEY idx_telegram_expired_notice (sales_channel,status,payment_expires_at,telegram_expired_notified_at)");}catch(Throwable $e){}
    try{
        if(!db_column_exists('orders','telegram_unique_code')){
            $pdo->exec("ALTER TABLE orders ADD telegram_unique_code INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount");
        }
    }catch(Throwable $e){}
}

function telegram_secret_encrypt(string $value): string { return encrypt_admin_order_password($value); }
function telegram_secret_decrypt(?string $value): string { return decrypt_admin_order_password($value); }
function telegram_random_token(int $bytes=24): string { return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'='); }
function telegram_html(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function telegram_multiline_setting(string $value): string {
    // Mendukung baris baru asli dari textarea dan teks lama yang tersimpan sebagai \n / \r\n.
    $value=str_replace(['\\r\\n','\\n','\\r'],"\n",$value);
    $value=str_replace(["\r\n","\r"],"\n",$value);
    return trim($value);
}
function telegram_short(string $value,int $limit=220): string {
    $value=trim(preg_replace('/\s+/u',' ',strip_tags($value)) ?? '');
    return mb_strlen($value)>$limit ? mb_substr($value,0,$limit-1).'…' : $value;
}
function telegram_bot_token_hint(string $token): string {
    if(strlen($token)<10) return '••••';
    return substr($token,0,5).'…'.substr($token,-4);
}
function telegram_get_bot(int $id,bool $allowDisabled=false): ?array {
    global $pdo;
    telegram_ensure_schema();
    $st=$pdo->prepare('SELECT * FROM telegram_bots WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $bot=$st->fetch();
    if(!$bot || (!$allowDisabled && $bot['status']==='disabled')) return null;
    $bot['_token']=telegram_secret_decrypt($bot['token_enc'] ?? '');
    return $bot;
}
function telegram_active_bot(): ?array {
    global $pdo;
    telegram_ensure_schema();
    $id=(int)($pdo->query("SELECT id FROM telegram_bots WHERE status='active' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
    return $id ? telegram_get_bot($id) : null;
}
function telegram_log(?int $botId,string $direction,string $eventType,?int $telegramUserId,?int $chatId,string $status,$payload=null,string $error=''): void {
    global $pdo;
    telegram_ensure_schema();
    try{
        $json=is_string($payload)?$payload:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare('INSERT INTO telegram_logs(bot_id,direction,event_type,telegram_user_id,chat_id,status,payload,error_message,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())')
            ->execute([$botId,$direction,$eventType,$telegramUserId,$chatId,$status,$json,$error ?: null]);
    }catch(Throwable $e){}
}

function telegram_api_request(array $bot,string $method,array $params=[]): array {
    $token=(string)($bot['_token'] ?? '');
    if($token==='') throw new RuntimeException('Token bot tidak tersedia.');
    foreach($params as $key=>$value){ if(is_array($value)) $params[$key]=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    $url='https://api.telegram.org/bot'.$token.'/'.$method;
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$params,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10]);
        $raw=curl_exec($ch);
        $err=curl_error($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($raw===false) throw new RuntimeException('Koneksi Telegram gagal: '.$err);
    }else{
        $body=http_build_query($params);
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>25,'ignore_errors'=>true]]);
        $raw=@file_get_contents($url,false,$ctx);
        $code=0;
        if($raw===false) throw new RuntimeException('Koneksi Telegram gagal. Aktifkan ekstensi cURL.');
    }
    $json=json_decode((string)$raw,true);
    if(!is_array($json) || empty($json['ok'])){
        $desc=is_array($json)?(string)($json['description'] ?? 'Respons Telegram tidak valid'):'Respons Telegram tidak valid';
        throw new RuntimeException($desc.' (HTTP '.$code.')');
    }
    return is_array($json['result'] ?? null)?$json['result']:['value'=>$json['result'] ?? true];
}
function telegram_send_message(array $bot,int $chatId,string $text,array $replyMarkup=[],bool $disablePreview=true): array {
    $params=['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML'];
    // Telegram Bot API terbaru memakai link_preview_options; disable_web_page_preview sudah tidak ada di dokumentasi saat ini.
    if($disablePreview) $params['link_preview_options']=['is_disabled'=>true];
    if($replyMarkup) $params['reply_markup']=$replyMarkup;
    $result=telegram_api_request($bot,'sendMessage',$params);
    telegram_log((int)$bot['id'],'out','sendMessage',null,$chatId,'success',['message_id'=>$result['message_id'] ?? null]);
    return $result;
}
function telegram_edit_message_content(array $bot,int $chatId,int $messageId,string $text,array $replyMarkup=[],bool $isCaption=false): bool {
    if($chatId<=0 || $messageId<=0)return false;
    $method=$isCaption?'editMessageCaption':'editMessageText';
    $params=['chat_id'=>$chatId,'message_id'=>$messageId,'parse_mode'=>'HTML'];
    if($isCaption)$params['caption']=$text;
    else{
        $params['text']=$text;
        $params['link_preview_options']=['is_disabled'=>true];
    }
    if($replyMarkup)$params['reply_markup']=$replyMarkup;
    try{
        telegram_api_request($bot,$method,$params);
        telegram_log((int)$bot['id'],'out',$method,null,$chatId,'success',['message_id'=>$messageId]);
        return true;
    }catch(Throwable $e){
        $notModified=str_contains(strtolower($e->getMessage()),'message is not modified');
        telegram_log((int)$bot['id'],'out',$method,null,$chatId,$notModified?'success':'failed',['message_id'=>$messageId],$e->getMessage());
        return $notModified;
    }
}
// Ubah payload QRIS mentah menjadi sumber gambar yang dapat diambil Telegram.
function telegram_qris_photo_sources(string $qris): array {
    $qris=trim($qris);
    if($qris==='')return [];
    if(str_starts_with($qris,'data:image/'))return [$qris];
    if(filter_var($qris,FILTER_VALIDATE_URL) && preg_match('~^https?://~i',$qris))return [$qris];

    if(strlen($qris)>4096)return [];
    $encoded=rawurlencode($qris);
    return [
        'https://quickchart.io/qr?size=520&margin=3&ecLevel=M&format=png&text='.$encoded,
        'https://api.qrserver.com/v1/create-qr-code/?size=520x520&margin=18&format=png&data='.$encoded,
    ];
}

function telegram_send_photo(array $bot,int $chatId,string $photo,string $caption='',array $replyMarkup=[]): array {
    $params=['chat_id'=>$chatId,'caption'=>$caption,'parse_mode'=>'HTML'];
    $tmp=null;
    if(str_starts_with($photo,'data:image/') && !function_exists('curl_init'))throw new RuntimeException('Pengiriman QR data URI membutuhkan ekstensi cURL.');
    if(str_starts_with($photo,'data:image/') && preg_match('~^data:image/(png|jpeg|jpg|webp);base64,(.+)$~s',$photo,$m)){
        $tmp=tempnam(sys_get_temp_dir(),'tgqr_').'.'.$m[1];
        file_put_contents($tmp,base64_decode($m[2],true) ?: '');
        $params['photo']=new CURLFile($tmp);
    }else{
        $params['photo']=$photo;
    }
    if($replyMarkup) $params['reply_markup']=$replyMarkup;
    try{
        $result=telegram_api_request($bot,'sendPhoto',$params);
        telegram_log((int)$bot['id'],'out','sendPhoto',null,$chatId,'success',['message_id'=>$result['message_id'] ?? null]);
        return $result;
    }finally{ if($tmp && is_file($tmp)) @unlink($tmp); }
}
function telegram_delete_message(array $bot,int $chatId,int $messageId): bool {
    if($chatId<=0 || $messageId<=0)return false;
    try{
        telegram_api_request($bot,'deleteMessage',['chat_id'=>$chatId,'message_id'=>$messageId]);
        telegram_log((int)$bot['id'],'out','deleteMessage',null,$chatId,'success',['message_id'=>$messageId]);
        return true;
    }catch(Throwable $e){
        $alreadyGone=str_contains(strtolower($e->getMessage()),'message to delete not found');
        telegram_log((int)$bot['id'],'out','deleteMessage',null,$chatId,$alreadyGone?'success':'failed',['message_id'=>$messageId],$e->getMessage());
        return $alreadyGone;
    }
}
function telegram_get_last_ui_message_ids(int $botId,int $chatId): array {
    global $pdo;
    try{
        $st=$pdo->prepare('SELECT last_ui_message_ids FROM telegram_bot_users WHERE bot_id=? AND chat_id=? LIMIT 1');
        $st->execute([$botId,$chatId]);
        $raw=trim((string)($st->fetchColumn() ?: ''));
        if($raw==='')return [];
        $decoded=json_decode($raw,true);
        if(!is_array($decoded))$decoded=ctype_digit($raw)?[(int)$raw]:[];
        return array_values(array_unique(array_filter(array_map('intval',$decoded),fn($id)=>$id>0)));
    }catch(Throwable $e){return [];}
}
function telegram_set_last_ui_message_ids(int $botId,int $chatId,array $messageIds): void {
    global $pdo;
    $ids=array_values(array_unique(array_filter(array_map('intval',$messageIds),fn($id)=>$id>0)));
    try{
        $pdo->prepare('UPDATE telegram_bot_users SET last_ui_message_ids=?,last_interaction_at=NOW() WHERE bot_id=? AND chat_id=?')
            ->execute([$ids?json_encode($ids):null,$botId,$chatId]);
    }catch(Throwable $e){}
}
function telegram_get_keyboard_message_id(int $botId,int $chatId): int {
    global $pdo;
    try{
        $st=$pdo->prepare('SELECT keyboard_message_id FROM telegram_bot_users WHERE bot_id=? AND chat_id=? LIMIT 1');
        $st->execute([$botId,$chatId]);
        return max(0,(int)($st->fetchColumn() ?: 0));
    }catch(Throwable $e){return 0;}
}
function telegram_set_keyboard_message_id(int $botId,int $chatId,int $messageId): void {
    global $pdo;
    try{
        $pdo->prepare('UPDATE telegram_bot_users SET keyboard_message_id=?,last_interaction_at=NOW() WHERE bot_id=? AND chat_id=?')
            ->execute([$messageId>0?$messageId:null,$botId,$chatId]);
    }catch(Throwable $e){}
}
function telegram_replace_keyboard_message(array $bot,int $chatId,callable $sender): array {
    $botId=(int)$bot['id'];
    $oldMessageId=telegram_get_keyboard_message_id($botId,$chatId);
    $result=$sender();
    $newMessageId=max(0,(int)($result['message_id'] ?? 0));
    if($newMessageId>0){
        // Simpan anchor baru sebelum menghapus anchor lama agar Telegram mobile
        // tidak sempat kehilangan reply keyboard di kolom input.
        telegram_set_keyboard_message_id($botId,$chatId,$newMessageId);
        if($oldMessageId>0 && $oldMessageId!==$newMessageId){
            telegram_delete_message($bot,$chatId,$oldMessageId);
        }
    }
    return $result;
}
function telegram_clear_ui_messages(array $bot,int $chatId): void {
    $oldIds=telegram_get_last_ui_message_ids((int)$bot['id'],$chatId);$failed=[];
    foreach($oldIds as $messageId)if(!telegram_delete_message($bot,$chatId,$messageId))$failed[]=$messageId;
    telegram_set_last_ui_message_ids((int)$bot['id'],$chatId,$failed);
}
function telegram_replace_ui_messages(array $bot,int $chatId,callable $sender): array {
    $oldIds=telegram_get_last_ui_message_ids((int)$bot['id'],$chatId);
    $result=$sender();
    $messages=isset($result['message_id'])?[$result]:$result;
    $newIds=[];
    if(is_array($messages)){
        foreach($messages as $message){
            if(is_array($message) && !empty($message['message_id']))$newIds[]=(int)$message['message_id'];
        }
    }
    if($newIds){
        $trackedIds=$newIds;
        foreach($oldIds as $messageId){
            if(!in_array($messageId,$newIds,true) && !telegram_delete_message($bot,$chatId,$messageId))$trackedIds[]=$messageId;
        }
        telegram_set_last_ui_message_ids((int)$bot['id'],$chatId,$trackedIds);
    }
    return $result;
}
function telegram_send_ui_message(array $bot,int $chatId,string $text,array $replyMarkup=[],bool $disablePreview=true): array {
    return telegram_replace_ui_messages($bot,$chatId,static fn()=>telegram_send_message($bot,$chatId,$text,$replyMarkup,$disablePreview));
}
function telegram_send_ui_photo(array $bot,int $chatId,string $photo,string $caption='',array $replyMarkup=[]): array {
    return telegram_replace_ui_messages($bot,$chatId,static fn()=>telegram_send_photo($bot,$chatId,$photo,$caption,$replyMarkup));
}
function telegram_answer_callback(array $bot,string $callbackId,string $text=''): void {
    try{ telegram_api_request($bot,'answerCallbackQuery',['callback_query_id'=>$callbackId,'text'=>$text,'show_alert'=>'false']); }catch(Throwable $e){}
}
function telegram_test_connection(array $bot): array {
    $me=telegram_api_request($bot,'getMe');
    global $pdo;
    $pdo->prepare('UPDATE telegram_bots SET bot_name=?,bot_username=?,bot_user_id=?,last_test_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?')
        ->execute([(string)($me['first_name'] ?? $bot['bot_name']),(string)($me['username'] ?? ''),(int)($me['id'] ?? 0),(int)$bot['id']]);
    return $me;
}
function telegram_webhook_url(array $bot): string {
    $site=rtrim(seo_site_url(),'/');
    return $site.'/api/telegram_webhook.php?bot='.(int)$bot['id'].'&key='.rawurlencode((string)$bot['webhook_key']);
}
function telegram_get_webhook_info(array $bot): array {
    return telegram_api_request($bot,'getWebhookInfo');
}
function telegram_set_webhook(array $bot): array {
    $result=telegram_api_request($bot,'setWebhook',[
        'url'=>telegram_webhook_url($bot),
        'secret_token'=>(string)$bot['webhook_secret'],
        'allowed_updates'=>['message','callback_query'],
        'drop_pending_updates'=>'false',
        // Nilai lebih konservatif untuk shared hosting.
        'max_connections'=>10,
    ]);
    telegram_sync_commands($bot);
    try{
        $info=telegram_get_webhook_info($bot);
        $lastError=trim((string)($info['last_error_message'] ?? ''));
        global $pdo;
        $pdo->prepare('UPDATE telegram_bots SET last_error=?,updated_at=NOW() WHERE id=?')->execute([$lastError ?: null,(int)$bot['id']]);
    }catch(Throwable $e){}
    return $result;
}
function telegram_repair_webhook(array $bot): array {
    telegram_test_connection($bot);
    $expected=telegram_webhook_url($bot);
    $before=[];
    try{$before=telegram_get_webhook_info($bot);}catch(Throwable $e){}
    $current=trim((string)($before['url'] ?? ''));
    $hasError=trim((string)($before['last_error_message'] ?? ''))!=='';
    if($current!==$expected || $hasError || $current===''){
        telegram_set_webhook($bot);
    }else{
        telegram_sync_commands($bot);
    }
    $after=telegram_get_webhook_info($bot);
    global $pdo;
    $lastError=trim((string)($after['last_error_message'] ?? ''));
    $pdo->prepare('UPDATE telegram_bots SET last_error=?,last_test_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$lastError ?: null,(int)$bot['id']]);
    return $after;
}
function telegram_delete_webhook(array $bot): array { return telegram_api_request($bot,'deleteWebhook',['drop_pending_updates'=>'false']); }

function telegram_delete_bot_permanently(int $botId): void {
    global $pdo;
    telegram_ensure_schema();

    $bot=telegram_get_bot($botId,true);
    if(!$bot) throw new RuntimeException('Bot tidak ditemukan.');
    if((string)$bot['status']==='active'){
        throw new RuntimeException('Bot aktif tidak dapat dihapus. Aktifkan bot pengganti atau nonaktifkan bot ini terlebih dahulu.');
    }

    $st=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE sales_channel='telegram' AND telegram_bot_id=? AND (status IN ('pending','paid') OR (status='delivered' AND COALESCE(telegram_delivery_status,'pending')<>'sent'))");
    $st->execute([$botId]);
    $unfinishedOrders=(int)$st->fetchColumn();
    if($unfinishedOrders>0){
        throw new RuntimeException('Bot belum dapat dihapus karena masih ada '.$unfinishedOrders.' transaksi Telegram yang belum selesai/dikirim. Selesaikan atau batalkan transaksi tersebut terlebih dahulu.');
    }

    try{ telegram_delete_webhook($bot); }catch(Throwable $e){}

    $pdo->beginTransaction();
    try{
        // Hentikan pekerjaan yang tidak boleh lagi memakai bot yang dihapus.
        $pdo->prepare("UPDATE telegram_broadcasts SET status='cancelled',completed_at=COALESCE(completed_at,NOW()) WHERE bot_id=? AND status IN ('queued','sending')")->execute([$botId]);
        $pdo->prepare("UPDATE telegram_logs SET bot_id=NULL WHERE bot_id=?")->execute([$botId]);
        $pdo->prepare("UPDATE telegram_delivery_queue SET bot_id=NULL WHERE bot_id=?")->execute([$botId]);
        $pdo->prepare("UPDATE orders SET telegram_bot_id=NULL WHERE telegram_bot_id=?")->execute([$botId]);

        // Data yang hanya berguna untuk koneksi bot lama boleh dibersihkan.
        $pdo->prepare('DELETE FROM telegram_updates WHERE bot_id=?')->execute([$botId]);
        $pdo->prepare('DELETE FROM telegram_bot_users WHERE bot_id=?')->execute([$botId]);
        $pdo->prepare('DELETE FROM telegram_migration_queue WHERE old_bot_id=? OR new_bot_id=?')->execute([$botId,$botId]);
        $pdo->prepare('DELETE FROM telegram_bots WHERE id=?')->execute([$botId]);

        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function telegram_sync_commands(array $bot): array {
    $commands=[
        ['command'=>'start','description'=>'Mulai dan buka menu utama'],
        ['command'=>'products','description'=>'Lihat daftar produk'],
        ['command'=>'vouchers','description'=>'Lihat voucher promo'],
        ['command'=>'orders','description'=>'Riwayat pesanan'],
        ['command'=>'menu','description'=>'Tampilkan menu utama'],
        ['command'=>'help','description'=>'Cara order dan bantuan'],
    ];
    $result=telegram_api_request($bot,'setMyCommands',['commands'=>$commands]);
    try{
        telegram_api_request($bot,'setChatMenuButton',[
            'menu_button'=>['type'=>'commands'],
        ]);
    }catch(Throwable $e){
        telegram_log((int)$bot['id'],'system','setChatMenuButton',null,null,'failed',null,$e->getMessage());
    }
    return $result;
}
function telegram_register_update(int $botId,int $updateId): bool {
    global $pdo;
    try{$pdo->prepare('INSERT INTO telegram_updates(bot_id,update_id,created_at) VALUES(?,?,NOW())')->execute([$botId,$updateId]);return true;}
    catch(Throwable $e){return false;}
}
function telegram_upsert_user(int $botId,array $from,int $chatId): array {
    global $pdo;
    $tgId=(int)($from['id'] ?? 0);
    if(!$tgId) throw new RuntimeException('ID pengguna Telegram tidak valid.');
    $pdo->prepare('INSERT INTO telegram_users(telegram_user_id,first_name,last_name,username,language_code,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),username=VALUES(username),language_code=VALUES(language_code),updated_at=NOW()')
        ->execute([$tgId,(string)($from['first_name'] ?? ''),(string)($from['last_name'] ?? ''),(string)($from['username'] ?? ''),(string)($from['language_code'] ?? '')]);
    $pdo->prepare('INSERT INTO telegram_bot_users(bot_id,telegram_user_id,chat_id,is_active,started_at,last_interaction_at) VALUES(?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),is_active=1,last_interaction_at=NOW()')
        ->execute([$botId,$tgId,$chatId]);
    $st=$pdo->prepare('SELECT * FROM telegram_users WHERE telegram_user_id=?');$st->execute([$tgId]);
    return $st->fetch() ?: ['telegram_user_id'=>$tgId];
}
function telegram_set_state(int $botId,int $telegramUserId,string $state='',array $data=[]): void {
    global $pdo;
    $pdo->prepare('UPDATE telegram_bot_users SET state=?,state_data=?,last_interaction_at=NOW() WHERE bot_id=? AND telegram_user_id=?')
        ->execute([$state ?: null,$data?json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$botId,$telegramUserId]);
}
function telegram_get_state(int $botId,int $telegramUserId): array {
    global $pdo;
    $st=$pdo->prepare('SELECT state,state_data FROM telegram_bot_users WHERE bot_id=? AND telegram_user_id=? LIMIT 1');
    $st->execute([$botId,$telegramUserId]);
    $row=$st->fetch() ?: [];
    $data=[];
    if(!empty($row['state_data'])){
        $decoded=json_decode((string)$row['state_data'],true);
        if(is_array($decoded))$data=$decoded;
    }
    return ['state'=>(string)($row['state'] ?? ''),'data'=>$data];
}
function telegram_set_chat_state(int $botId,int $chatId,string $state='',array $data=[]): void {
    global $pdo;
    $pdo->prepare('UPDATE telegram_bot_users SET state=?,state_data=?,last_interaction_at=NOW() WHERE bot_id=? AND chat_id=?')
        ->execute([$state ?: null,$data?json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$botId,$chatId]);
}
function telegram_clear_chat_state(int $botId,int $chatId): void {
    telegram_set_chat_state($botId,$chatId);
}
function telegram_button_labels(): array {
    return [
        'products'=>trim((string)setting('telegram_button_products','📦 List Produk')) ?: '📦 List Produk',
        'orders'=>trim((string)setting('telegram_button_orders','📜 Riwayat')) ?: '📜 Riwayat',
        'vouchers'=>trim((string)setting('telegram_button_vouchers','🎟 Voucher')) ?: '🎟 Voucher',
        'help'=>trim((string)setting('telegram_button_help','❓ Cara Order')) ?: '❓ Cara Order',
        'info'=>trim((string)setting('telegram_button_info','ℹ️ Informasi')) ?: 'ℹ️ Informasi',
        'website'=>trim((string)setting('telegram_button_website','🌐 Website')) ?: '🌐 Website',
    ];
}
function telegram_main_keyboard(): array {
    $b = telegram_button_labels();

    return [
        'keyboard' => [
            [$b['products'], $b['orders']],
            [$b['vouchers'], $b['help']],
            [$b['info'], $b['website']],
        ],
        'resize_keyboard' => true,
        // Menu tetap tersedia setelah tombol dipilih, tetapi tidak dipaksa
        // selalu terbuka. Dengan begitu tombol Back Android dapat menutup
        // reply keyboard dan ikon keyboard tetap bisa membukanya kembali.
        'one_time_keyboard' => false,
        'input_field_placeholder' => 'Pilih menu…',
    ];
}
function telegram_send_main_menu(
    array $bot,
    int $chatId,
    array $user = []
): array {
    telegram_clear_chat_state((int)$bot['id'],$chatId);

    // Pesan konten boleh diganti/dihapus, tetapi pesan yang membawa
    // ReplyKeyboardMarkup disimpan sebagai anchor terpisah. Pada Telegram
    // mobile, menghapus pesan anchor dapat ikut menghilangkan tombol keyboard.
    telegram_clear_ui_messages($bot,$chatId);

    $name = trim(
        (string)($user['first_name'] ?? '')
    ) ?: 'Kak';

    $welcome = trim(
        (string)setting(
            'telegram_welcome_message',
            "Selamat datang di {store}, {name}!\n\n"
            . "Pilih menu di bawah untuk melihat produk "
            . "dan melakukan pembelian otomatis."
        )
    );

    $text = str_replace(
        ['{store}', '{name}'],
        [
            trim((string)setting('site_name', 'Digital Store')),
            $name,
        ],
        $welcome
    );

    $image = trim(
        (string)setting('telegram_welcome_image', '')
    );

    $keyboard = telegram_main_keyboard();
    $safeText = telegram_html($text);

    if (
        $image !== ''
        && filter_var($image, FILTER_VALIDATE_URL)
    ) {
        try {
            // Caption Telegram maksimal 1.024 karakter.
            if (mb_strlen($text) <= 1000) {
                return telegram_replace_keyboard_message(
                    $bot,
                    $chatId,
                    static fn()=>telegram_send_photo(
                        $bot,
                        $chatId,
                        $image,
                        $safeText,
                        $keyboard
                    )
                );
            }

            // Untuk teks panjang, gambar menjadi konten biasa sedangkan
            // pesan teks menjadi anchor reply keyboard yang tidak ikut
            // dihapus saat pengguna membuka produk/menu lainnya.
            try {
                $photoMessage=telegram_send_photo($bot,$chatId,$image);
                if(!empty($photoMessage['message_id'])){
                    telegram_set_last_ui_message_ids(
                        (int)$bot['id'],
                        $chatId,
                        [(int)$photoMessage['message_id']]
                    );
                }
            } catch (Throwable $e) {
                telegram_log(
                    (int)$bot['id'],
                    'system',
                    'welcome_photo',
                    null,
                    $chatId,
                    'failed',
                    ['image' => $image],
                    $e->getMessage()
                );
            }

            return telegram_replace_keyboard_message(
                $bot,
                $chatId,
                static fn()=>telegram_send_message(
                    $bot,
                    $chatId,
                    $safeText,
                    $keyboard
                )
            );
        } catch (Throwable $e) {
            telegram_log(
                (int)$bot['id'],
                'system',
                'welcome_photo',
                null,
                $chatId,
                'failed',
                ['image' => $image],
                $e->getMessage()
            );
        }
    }

    // Fallback apabila gambar kosong atau gagal dikirim.
    return telegram_replace_keyboard_message(
        $bot,
        $chatId,
        static fn()=>telegram_send_message(
            $bot,
            $chatId,
            $safeText,
            $keyboard
        )
    );
}
function telegram_product_first_variant(array $p): array {
    $variants=product_active_variants((int)($p['id'] ?? 0));
    if(!$variants) throw new RuntimeException('Varian produk tidak ditemukan.');
    return $variants[0];
}
function telegram_product_price(array $p): float {
    return product_variant_price($p, telegram_product_first_variant($p));
}
function telegram_product_stock(array $p): int {
    return product_variant_stock($p, telegram_product_first_variant($p));
}
function telegram_db_timezone_offset_seconds(): int {
    global $pdo;
    static $offset=null;
    if($offset!==null)return $offset;
    try{$offset=(int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())')->fetchColumn();}
    catch(Throwable $e){$offset=0;}
    return max(-50400,min(50400,$offset));
}
function telegram_db_datetime_timestamp(string $datetime): int {
    $offset=telegram_db_timezone_offset_seconds();
    $sign=$offset<0?'-':'+';$abs=abs($offset);
    $timezone=sprintf('%s%02d:%02d',$sign,intdiv($abs,3600),intdiv($abs%3600,60));
    try{
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$datetime,new DateTimeZone($timezone));
        if($date instanceof DateTimeImmutable)return $date->getTimestamp();
    }catch(Throwable $e){}
    return strtotime($datetime) ?: 0;
}
function telegram_display_datetime_wib(?string $datetime): string {
    if(!$datetime)return '-';
    $timestamp=telegram_db_datetime_timestamp($datetime);
    if($timestamp<=0)return (string)$datetime;
    try{return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i').' WIB';}
    catch(Throwable $e){return (string)$datetime;}
}
function telegram_db_expiry_datetime(int $minutes): string {
    global $pdo;
    $minutes=max(1,min(1440,$minutes));
    try{return (string)$pdo->query('SELECT DATE_FORMAT(DATE_ADD(NOW(),INTERVAL '.$minutes." MINUTE),'%Y-%m-%d %H:%i:%s')")->fetchColumn();}
    catch(Throwable $e){return date('Y-m-d H:i:s',time()+($minutes*60));}
}
function telegram_send_categories(array $bot,int $chatId): void {
    global $pdo;
    telegram_clear_chat_state((int)$bot['id'],$chatId);
    $rows=$pdo->query("SELECT c.id,c.name FROM categories c WHERE c.status=1 ORDER BY c.sort_order,c.name")->fetchAll();
    $keys=[[['text'=>'🔥 Semua Produk','callback_data'=>'cat:0:1']]];
    $pair=[];
    foreach($rows as $row){
        $pair[]=['text'=>category_icon_symbol((string)$row['name']).' '.telegram_short((string)$row['name'],28),'callback_data'=>'cat:'.(int)$row['id'].':1'];
        if(count($pair)===2){$keys[]=$pair;$pair=[];}
    }
    if($pair)$keys[]=$pair;
    telegram_send_ui_message($bot,$chatId,"<b>📦 Katalog Produk</b>\n\nPilih kategori yang ingin dilihat:",['inline_keyboard'=>$keys]);
}
function telegram_send_product_text_list(
    array $bot,
    int $chatId,
    int $page = 1,
    string $notice = ''
): void {
    global $pdo;
    telegram_clear_chat_state((int)$bot['id'],$chatId);

    $perPage = 10;

    $total = (int)$pdo->query("
        SELECT COUNT(*)
        FROM products
        WHERE status = 1
          AND telegram_status = 1
    ")->fetchColumn();

    if ($total < 1) {
        telegram_send_ui_message(
            $bot,
            $chatId,
            'Produk belum tersedia.'
        );
        return;
    }

    $totalPages = max(
        1,
        (int)ceil($total / $perPage)
    );

    $page = max(
        1,
        min($page, $totalPages)
    );

    $offset = ($page - 1) * $perPage;

    $rows = $pdo->query("
        SELECT p.*
        FROM products p
        WHERE p.status = 1
          AND p.telegram_status = 1
        ORDER BY
            p.telegram_sort_order ASC,
            p.sold_count DESC,
            p.id DESC
        LIMIT {$perPage}
        OFFSET {$offset}
    ")->fetchAll();

    $text = "<b>📦 LIST PRODUCT</b>\n\n";
    if ($notice !== '') {
        $text .= '⚠️ ' . telegram_html($notice) . "\n\n";
    }
    $productButtons = [];
    $numberMap = [];

    foreach ($rows as $index => $product) {
        $number = $offset + $index + 1;
        $stock = telegram_product_stock($product);

        $productName = telegram_short(
            (string)$product['name'],
            45
        );

        $text .= '[' . $number . ']. '
            . telegram_html($productName)
            . ' ( ' . $stock . " )\n";

        $productId = (int)$product['id'];
        $numberMap[$number] = $productId;

        $productButtons[] = [
            'text' => (string)$number,
            'callback_data' => 'prodlist:' . $productId . ':' . $page,
        ];
    }

    $text .= "\n📄 Halaman {$page} / {$totalPages}";
    $text .= "\n📦 Total Produk: {$total}";
    $text .= "\n\nKetik nomor produk atau tekan tombol angka di bawah.";

    $keyboard = [];

    foreach (
        array_chunk($productButtons, 5)
        as $buttonRow
    ) {
        $keyboard[] = $buttonRow;
    }

    $navigation = [];

    if ($page > 1) {
        $navigation[] = [
            'text' => '⬅️ Sebelumnya',
            'callback_data' => 'product_list:' . ($page - 1),
        ];
    }

    if ($page < $totalPages) {
        $navigation[] = [
            'text' => 'Selanjutnya ➡️',
            'callback_data' => 'product_list:' . ($page + 1),
        ];
    }

    if ($navigation) {
        $keyboard[] = $navigation;
    }

    $keyboard[] = [
        [
            'text' => '🗂 Lihat Kategori',
            'callback_data' => 'categories',
        ],
        [
            'text' => '🏠 Menu Utama',
            'callback_data' => 'menu',
        ],
    ];

    telegram_send_ui_message(
        $bot,
        $chatId,
        $text,
        ['inline_keyboard' => $keyboard]
    );

    telegram_set_chat_state(
        (int)$bot['id'],
        $chatId,
        'product_text_list',
        [
            'page' => $page,
            'total_pages' => $totalPages,
            'numbers' => $numberMap,
        ]
    );
}
function telegram_send_products(array $bot,int $chatId,int $categoryId=0,int $page=1,string $notice=''): void {
    global $pdo;
    telegram_clear_chat_state((int)$bot['id'],$chatId);

    $perPage=10;
    $where='p.status=1 AND p.telegram_status=1';
    $params=[];
    $categoryName='';

    if($categoryId>0){
        $where.=' AND p.category_id=?';
        $params[]=$categoryId;

        $categoryStatement=$pdo->prepare('SELECT name FROM categories WHERE id=? AND status=1 LIMIT 1');
        $categoryStatement->execute([$categoryId]);
        $categoryName=trim((string)($categoryStatement->fetchColumn() ?: ''));
    }

    $count=$pdo->prepare("SELECT COUNT(*) FROM products p WHERE $where");
    $count->execute($params);
    $total=(int)$count->fetchColumn();

    if($total<1){
        telegram_send_ui_message(
            $bot,
            $chatId,
            'Produk belum tersedia pada kategori ini.',
            ['inline_keyboard'=>[[['text'=>'◀️ Pilih Kategori','callback_data'=>'categories']]]]
        );
        return;
    }

    $totalPages=max(1,(int)ceil($total/$perPage));
    $page=max(1,min($page,$totalPages));
    $offset=($page-1)*$perPage;

    $sql="SELECT p.*,c.name category
          FROM products p
          LEFT JOIN categories c ON c.id=p.category_id
          WHERE $where
          ORDER BY p.telegram_sort_order ASC,p.sold_count DESC,p.id DESC
          LIMIT {$perPage} OFFSET {$offset}";
    $statement=$pdo->prepare($sql);
    $statement->execute($params);
    $rows=$statement->fetchAll();

    $text="<b>📦 LIST PRODUCT</b>\n\n";
    if($categoryName!==''){
        $text.='<b>Kategori:</b> '.telegram_html($categoryName)."\n\n";
    }
    if($notice!==''){
        $text.='⚠️ '.telegram_html($notice)."\n\n";
    }

    $numberMap=[];
    $productButtons=[];

    foreach($rows as $index=>$product){
        $number=$offset+$index+1;
        $stock=telegram_product_stock($product);
        $productId=(int)$product['id'];
        $productName=telegram_short((string)$product['name'],45);

        $text.='['.$number.']. '.telegram_html($productName).' ( '.$stock." )\n";
        $numberMap[$number]=$productId;
        $productButtons[]=[
            'text'=>(string)$number,
            'callback_data'=>'catprod:'.$productId.':'.$categoryId.':'.$page,
        ];
    }

    $text.="\n📄 Halaman {$page} / {$totalPages}";
    $text.="\n📦 Total Produk: {$total}";
    $text.="\n\nKetik nomor produk atau tekan tombol angka di bawah.";

    $keyboard=[];
    foreach(array_chunk($productButtons,5) as $buttonRow)$keyboard[]=$buttonRow;

    $navigation=[];
    if($page>1){
        $navigation[]=[
            'text'=>'⬅️ Sebelumnya',
            'callback_data'=>'cat:'.$categoryId.':'.($page-1),
        ];
    }
    if($page<$totalPages){
        $navigation[]=[
            'text'=>'Selanjutnya ➡️',
            'callback_data'=>'cat:'.$categoryId.':'.($page+1),
        ];
    }
    if($navigation)$keyboard[]=$navigation;

    $keyboard[]=[
        ['text'=>'🗂 Lihat Kategori','callback_data'=>'categories'],
        ['text'=>'🏠 Menu Utama','callback_data'=>'menu'],
    ];

    telegram_send_ui_message(
        $bot,
        $chatId,
        $text,
        ['inline_keyboard'=>$keyboard]
    );

    telegram_set_chat_state(
        (int)$bot['id'],
        $chatId,
        'product_text_list',
        [
            'origin'=>'category',
            'category_id'=>$categoryId,
            'page'=>$page,
            'total_pages'=>$totalPages,
            'numbers'=>$numberMap,
        ]
    );
}
function telegram_promo_value_label(array $promo): string {
    $value=parse_local_number($promo['value'] ?? 0);
    return strtolower((string)($promo['type'] ?? 'fixed'))==='percent'
        ? plain_number($value).'%'
        : rupiah($value);
}
function telegram_active_vouchers(int $limit=20): array {
    global $pdo;
    $limit=max(1,min(50,$limit));
    try{$rows=$pdo->query("SELECT * FROM promos WHERE status=1 ORDER BY id DESC LIMIT 100")->fetchAll();}
    catch(Throwable $e){return [];}
    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->getTimestamp();
    $active=array_values(array_filter($rows,static function(array $promo) use($now): bool {
        $starts=promo_datetime_ts($promo['starts_at'] ?? '');
        $ends=promo_datetime_ts($promo['ends_at'] ?? '');
        $usageLimit=(int)($promo['usage_limit'] ?? 0);
        $used=(int)($promo['used_count'] ?? 0);
        if((int)($promo['status'] ?? 0)!==1)return false;
        if($starts!==null && $starts>$now)return false;
        if($ends!==null && $ends<$now)return false;
        if($usageLimit>0 && $used>=$usageLimit)return false;
        return true;
    }));
    return array_slice($active,0,$limit);
}
function telegram_send_vouchers(array $bot,int $chatId): void {
    telegram_clear_chat_state((int)$bot['id'],$chatId);
    $rows=telegram_active_vouchers();
    if(!$rows){
        telegram_send_ui_message(
            $bot,
            $chatId,
            "<b>🎟 Voucher Promo</b>\n\nBelum ada voucher promo yang aktif saat ini.",
            ['inline_keyboard'=>[[['text'=>'📦 Lihat Produk','callback_data'=>'product_list:1'],['text'=>'🏠 Menu Utama','callback_data'=>'menu']]]]
        );
        return;
    }
    $text="<b>🎟 VOUCHER PROMO</b>\n\n";
    foreach($rows as $index=>$promo){
        $code=strtoupper(trim((string)($promo['code'] ?? '')));
        $text.='<b>'.($index+1).'. '.telegram_html($code)."</b>\n";
        $text.='Diskon: <b>'.telegram_html(telegram_promo_value_label($promo))."</b>\n";
        $minimum=parse_local_number($promo['min_purchase'] ?? 0);
        if($minimum>0)$text.='Minimum belanja: '.telegram_html(rupiah($minimum))."\n";
        $maximum=parse_local_number($promo['max_discount'] ?? 0);
        if($maximum>0)$text.='Maksimum diskon: '.telegram_html(rupiah($maximum))."\n";
        $ends=promo_datetime_ts($promo['ends_at'] ?? '');
        $endsLabel='Tidak dibatasi';
        if($ends!==null){
            $endsLabel=(new DateTimeImmutable('@'.$ends))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i').' WIB';
        }
        $text.='Berlaku hingga: '.telegram_html($endsLabel)."\n";
        $text.="\n";
    }
    $text.='Masukkan kode voucher saat konfirmasi pesanan sebelum menampilkan QRIS.';
    telegram_send_ui_message(
        $bot,
        $chatId,
        trim($text),
        ['inline_keyboard'=>[[['text'=>'📦 Lihat Produk','callback_data'=>'product_list:1'],['text'=>'🏠 Menu Utama','callback_data'=>'menu']]]]
    );
}
function telegram_get_product(int $id): ?array {
    global $pdo;
    $st=$pdo->prepare("SELECT p.*,c.name category,
        COALESCE((SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ('paid','delivered')),p.sold_count,0) sold_success_count
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        WHERE p.id=? AND p.status=1 AND p.telegram_status=1
        LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function telegram_product_detail_back_callback(int $botId,int $telegramUserId,int $productId,string $fallback=''): string {
    $state=telegram_get_state($botId,$telegramUserId);
    $data=is_array($state['data'] ?? null)?$state['data']:[];
    if((int)($data['product_id'] ?? 0)===$productId){
        $back=trim((string)($data['back_callback'] ?? ''));
        if($back!=='')return $back;
    }
    return $fallback!==''?$fallback:'prod:'.$productId;
}
function telegram_product_detail_content(array $p,int $productId,string $backCallback,int $qty=1): array {
    $variant=telegram_product_first_variant($p);
    $stock=product_variant_stock($p,$variant);$price=product_variant_price($p,$variant);
    $maxQty=max(1,min(5,$stock));
    $qty=max(1,min($qty,$maxQty));
    $desc=telegram_short((string)($p['description'] ?? ''),420);
    $sold=max(0,(int)($p['sold_success_count'] ?? $p['sold_count'] ?? 0));
    $text='<b>🛍 '.telegram_html((string)$p['name'])."</b>\n\n";
    if($desc!=='')$text.=telegram_html($desc)."\n\n";
    $text.='<b>Harga:</b> '.telegram_html(rupiah($price))."\n";
    $text.='<b>Stok:</b> '.$stock."\n";
    $text.='<b>Terjual:</b> '.$sold."\n\n";
    $text.='<b>Garansi:</b> '.telegram_html((string)(product_variant_warranty_label($variant,$p) ?: '-'))."\n";
    $text.='<b>Pengiriman:</b> '.(strtolower((string)$p['delivery_type'])==='manual'?'Manual oleh admin':'Otomatis setelah pembayaran')."\n\n";
    if($stock>0){
        $text.='Pilih jumlah produk, lalu tekan <b>Bayar via QRIS</b>.'."\n\n";
        $text.='<b>Jumlah Pesanan:</b> +'.$qty;
    }else $text.='Maaf, stok produk sedang habis.';
    if($backCallback==='')$backCallback='cat:'.(int)($p['category_id'] ?? 0).':1';

    $keys=[];
    if($stock>0){
        $keys[]=[
            ['text'=>'➖','callback_data'=>'qtydec:'.$productId.':'.$qty],
            ['text'=>(string)$qty,'callback_data'=>'noop'],
            ['text'=>'➕','callback_data'=>'qtyinc:'.$productId.':'.$qty],
        ];
        $keys[]=[['text'=>'💳 Bayar via QRIS','callback_data'=>'confirm:'.$productId.':'.$qty]];
    }
    $keys[]=[['text'=>'◀️ Kembali ke Produk','callback_data'=>$backCallback]];
    return ['text'=>$text,'keys'=>$keys,'qty'=>$qty,'back_callback'=>$backCallback];
}
function telegram_send_product_detail(array $bot,int $chatId,int $productId,string $backCallback='',int $qty=1): void {
    telegram_clear_chat_state((int)$bot['id'],$chatId);
    $p=telegram_get_product($productId);
    if(!$p){telegram_send_ui_message($bot,$chatId,'Produk tidak ditemukan atau sedang nonaktif.');return;}
    $content=telegram_product_detail_content($p,$productId,$backCallback,$qty);
    telegram_set_chat_state((int)$bot['id'],$chatId,'product_detail',[
        'product_id'=>$productId,
        'qty'=>$content['qty'],
        'back_callback'=>$content['back_callback'],
    ]);
    if(!empty($p['thumbnail']) && filter_var(seo_url((string)$p['thumbnail']),FILTER_VALIDATE_URL)){
        try{telegram_send_ui_photo($bot,$chatId,seo_url((string)$p['thumbnail']),$content['text'],['inline_keyboard'=>$content['keys']]);return;}catch(Throwable $e){}
    }
    telegram_send_ui_message($bot,$chatId,$content['text'],['inline_keyboard'=>$content['keys']]);
}
function telegram_edit_product_detail(array $bot,int $chatId,int $messageId,bool $isCaption,int $productId,string $backCallback='',int $qty=1): void {
    $p=telegram_get_product($productId);
    if(!$p)throw new RuntimeException('Produk tidak ditemukan atau sedang nonaktif.');
    $content=telegram_product_detail_content($p,$productId,$backCallback,$qty);
    telegram_set_chat_state((int)$bot['id'],$chatId,'product_detail',[
        'product_id'=>$productId,
        'qty'=>$content['qty'],
        'back_callback'=>$content['back_callback'],
    ]);
    if(!telegram_edit_message_content($bot,$chatId,$messageId,$content['text'],['inline_keyboard'=>$content['keys']],$isCaption)){
        telegram_send_product_detail($bot,$chatId,$productId,$content['back_callback'],$content['qty']);
    }
}
function telegram_send_quantity(array $bot,int $chatId,int $productId,string $detailCallback=''): void {
    telegram_send_product_detail($bot,$chatId,$productId,$detailCallback,1);
}
function telegram_invoice_no(): string { return 'TG-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8)); }
function telegram_unique_code(): int { return random_int(100,500); }
function telegram_payment_unique_code(string $gateway,int $current=0): int {
    if($gateway==='casaku'){
        if(!casaku_use_unique_code())return 0;
        return $current>0?max(1,min(999,$current)):random_int(1,999);
    }
    return $current>0?max(100,min(500,$current)):telegram_unique_code();
}
function telegram_payment_gateway(): string {
    $gateway=strtolower(trim((string)setting('payment_gateway','qris_static')));
    return in_array($gateway,['tokopay','casaku','qris_static'],true)?$gateway:'qris_static';
}
function telegram_assert_auto_gateway_ready(string $gateway): void {
    if($gateway==='tokopay'){
        if(trim((string)setting('tokopay_merchant_id',''))==='' || trim((string)setting('tokopay_secret_key',''))===''){
            throw new RuntimeException('Tokopay aktif tetapi Merchant ID / Secret Key belum lengkap.');
        }
        return;
    }
    if($gateway==='casaku'){
        if(trim((string)setting('casaku_license_key',''))==='')throw new RuntimeException('Casaku aktif tetapi License Key belum diatur.');
        if(trim((string)setting('casaku_qr_id',''))==='')throw new RuntimeException('Casaku aktif tetapi QR ID belum diatur.');
        if(!casaku_package_ids())throw new RuntimeException('Casaku aktif tetapi Package ID belum diatur.');
        return;
    }
    throw new RuntimeException('Bot auto-order membutuhkan payment gateway otomatis Tokopay atau Casaku. QRIS manual tidak dapat mengonfirmasi pembayaran otomatis.');
}
function telegram_resolve_promo(string $promoCode,float $subtotal): array {
    global $pdo;
    $promoCode=strtoupper(trim($promoCode));
    if($promoCode==='')return [null,0.0,''];
    $st=$pdo->prepare('SELECT * FROM promos WHERE UPPER(TRIM(code))=? LIMIT 1');
    $st->execute([$promoCode]);
    $promo=$st->fetch();
    if(!$promo)throw new RuntimeException('Kode promo tidak ditemukan.');
    [$valid,$discount,$message]=promo_validate_and_discount($promo,$subtotal);
    if(!$valid)throw new RuntimeException($message);
    return [$promo,(float)$discount,$message];
}
function telegram_send_checkout_confirmation(array $bot,int $chatId,int $productId,int $qty,string $promoCode='',string $backCallback='',int $uniqueCode=0): void {
    $p=telegram_get_product($productId);
    if(!$p)throw new RuntimeException('Produk tidak ditemukan.');
    $stock=telegram_product_stock($p);
    $qty=max(1,min(5,$qty));
    if($stock<$qty)throw new RuntimeException('Stok tidak cukup. Sisa stok: '.$stock);
    $gateway=telegram_payment_gateway();
    telegram_assert_auto_gateway_ready($gateway);
    $price=telegram_product_price($p);
    $subtotal=$price*$qty;
    [$promo,$discount]=telegram_resolve_promo($promoCode,$subtotal);
    $promoCode=$promo?strtoupper(trim((string)$promo['code'])):'';
    $baseTotal=max(0,$subtotal-$discount);
    $uniqueCode=telegram_payment_unique_code($gateway,$uniqueCode);
    $total=$baseTotal+$uniqueCode;
    if($backCallback===''){
        $backCallback=telegram_product_detail_back_callback((int)$bot['id'],$chatId,$productId,'prod:'.$productId);
    }

    $text="<b>🧾 Konfirmasi Pesanan</b>

";
    $text.='<b>Produk:</b> '.telegram_html((string)$p['name'])."
";
    $text.='<b>Qty:</b> '.$qty."
";
    $text.='<b>Harga:</b> '.telegram_html(rupiah($price))."
";
    $text.='<b>Subtotal:</b> '.telegram_html(rupiah($subtotal))."
";
    if($promo){
        $text.='<b>Voucher:</b> '.telegram_html($promoCode).' (-'.telegram_html(rupiah($discount)).")
";
    }
    if($uniqueCode>0)$text.='<b>Kode Unik:</b> +'.telegram_html(rupiah($uniqueCode))."
";
    $text.='<b>Total:</b> '.telegram_html(rupiah($total))."
";
    $text.='<b>Gateway:</b> '.telegram_html($gateway==='casaku'?'Casaku':'Tokopay')."

";
    $text.='Periksa kembali pesanan sebelum melanjutkan ke pembayaran QRIS.';

    $keys=[];
    $keys[]=[[ 'text'=>$promo?'🎟 Ganti Kode Voucher':'🎟 Input Kode Voucher','callback_data'=>'voucher:'.$productId.':'.$qty ]];
    if($promo)$keys[]=[[ 'text'=>'🗑 Hapus Voucher','callback_data'=>'removevoucher:'.$productId.':'.$qty ]];
    $keys[]=[[ 'text'=>'✅ Lanjut Pembayaran','callback_data'=>'pay:'.$productId.':'.$qty.':'.$uniqueCode ]];
    $keys[]=[[ 'text'=>'◀️ Kembali ke Produk','callback_data'=>'backdetail:'.$productId.':'.$qty ]];

    telegram_set_chat_state((int)$bot['id'],$chatId,'checkout_confirm',[
        'product_id'=>$productId,
        'qty'=>$qty,
        'promo_code'=>$promoCode,
        'unique_code'=>$uniqueCode,
        'payment_gateway'=>$gateway,
        'back_callback'=>$backCallback,
    ]);
    telegram_send_ui_message($bot,$chatId,$text,['inline_keyboard'=>$keys]);
}
function telegram_send_voucher_prompt(array $bot,int $chatId,int $productId,int $qty,string $backCallback='',string $currentPromoCode='',int $uniqueCode=0): void {
    if($backCallback===''){
        $backCallback=telegram_product_detail_back_callback((int)$bot['id'],$chatId,$productId,'prod:'.$productId);
    }
    $uniqueCode=telegram_payment_unique_code(telegram_payment_gateway(),$uniqueCode);
    telegram_set_chat_state((int)$bot['id'],$chatId,'awaiting_voucher',[
        'product_id'=>$productId,
        'qty'=>$qty,
        'back_callback'=>$backCallback,
        'promo_code'=>strtoupper(trim($currentPromoCode)),
        'unique_code'=>$uniqueCode,
    ]);
    telegram_send_ui_message(
        $bot,
        $chatId,
        "<b>🎟 Input Kode Voucher</b>\n\nKirim kode voucher melalui kolom pesan. Kode akan diperiksa menggunakan promo yang diatur di website.",
        ['inline_keyboard'=>[[['text'=>'Tanpa Voucher','callback_data'=>'novoucher:'.$productId.':'.$qty]],[['text'=>'◀️ Kembali ke Konfirmasi','callback_data'=>'reconfirm:'.$productId.':'.$qty]]]]
    );
}
function telegram_find_active_pending_order(int $telegramUserId,int $productId,int $qty,int $promoId=0,int $uniqueCode=0,string $provider=''): ?array {
    global $pdo;
    $provider=strtolower(trim($provider));
    if($provider==='casaku'){
        $st=$pdo->prepare("SELECT o.* FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.telegram_user_id=? AND oi.product_id=? AND oi.qty=? AND COALESCE(o.promo_id,0)=? AND COALESCE(o.telegram_unique_code,0)=? AND o.payment_provider='casaku' AND o.status='pending' AND (o.payment_expires_at IS NULL OR o.payment_expires_at>DATE_SUB(NOW(),INTERVAL 3 MINUTE)) ORDER BY o.id DESC LIMIT 1");
        $st->execute([$telegramUserId,$productId,$qty,$promoId,$uniqueCode]);
    }else{
        $st=$pdo->prepare("SELECT o.* FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.telegram_user_id=? AND oi.product_id=? AND oi.qty=? AND COALESCE(o.promo_id,0)=? AND COALESCE(o.telegram_unique_code,0)=? AND o.status='pending' AND (o.payment_expires_at IS NULL OR o.payment_expires_at>NOW()) ORDER BY o.id DESC LIMIT 1");
        $st->execute([$telegramUserId,$productId,$qty,$promoId,$uniqueCode]);
    }
    return $st->fetch() ?: null;
}
function telegram_create_order(array $bot,array $user,int $chatId,int $productId,int $qty,string $promoCode='',int $uniqueCode=0): array {
    global $pdo;
    telegram_ensure_schema();
    $p=telegram_get_product($productId);if(!$p)throw new RuntimeException('Produk tidak ditemukan.');
    $qty=max(1,min(5,$qty));
    $gatewayMode=telegram_payment_gateway();
    telegram_assert_auto_gateway_ready($gatewayMode);
    $price=telegram_product_price($p);$subtotal=$price*$qty;
    [$promo,$discount]=telegram_resolve_promo($promoCode,$subtotal);
    $promoId=(int)($promo['id'] ?? 0);
    $baseTotal=max(0,$subtotal-$discount);
    $uniqueCode=telegram_payment_unique_code($gatewayMode,$uniqueCode);
    $existing=telegram_find_active_pending_order((int)$user['telegram_user_id'],$productId,$qty,$promoId,$uniqueCode,$gatewayMode);
    if($existing){
        if($gatewayMode==='casaku' && $uniqueCode===0){
            $amount=(int)round((float)($existing['payment_total'] ?: $existing['total']));
            $lockKey=casaku_acquire_exact_amount_lock($amount);
            try{casaku_prepare_exact_amount($amount,(string)$existing['invoice']);}
            finally{casaku_release_exact_amount_lock($lockKey);}
        }
        return $existing;
    }
    if(maintenance_mode_enabled()) throw new RuntimeException(maintenance_mode_message());
    $stock=telegram_product_stock($p);if($stock<$qty)throw new RuntimeException('Stok tidak cukup. Sisa stok: '.$stock);
    $total=$baseTotal+$uniqueCode;$invoice=telegram_invoice_no();
    $expiredMinutes=$gatewayMode==='casaku'?casaku_expired_minutes():tokopay_expired_minutes();
    $expiresAt=telegram_db_expiry_datetime($expiredMinutes);
    $name=trim((string)($user['first_name'] ?? '').' '.(string)($user['last_name'] ?? '')) ?: ((string)($user['username'] ?? '') ?: 'Telegram User');
    $email=trim((string)($user['buyer_email'] ?? '')) ?: 'tg'.(int)$user['telegram_user_id'].'@telegram.invalid';
    $password=random_access_password();$accessHash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO orders(invoice,sales_channel,telegram_bot_id,telegram_user_id,telegram_chat_id,buyer_name,buyer_email,buyer_whatsapp,subtotal,discount,telegram_unique_code,promo_id,total,payment_total,status,payment_provider,qris_payload,access_password_hash,access_password_admin_enc,payment_expires_at,telegram_delivery_status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,"pending",?,?,?,?,?,"pending",NOW())')
            ->execute([$invoice,'telegram',(int)$bot['id'],(int)$user['telegram_user_id'],$chatId,$name,$email,(string)($user['buyer_whatsapp'] ?? ''),$subtotal,$discount,$uniqueCode,$promoId?:null,$total,$total,$gatewayMode,'',$accessHash,encrypt_admin_order_password($password),$expiresAt]);
        $orderId=(int)$pdo->lastInsertId();
        $deliveryType=strtolower((string)($p['delivery_type'] ?? 'instant'))==='manual'?'manual':'instant';
        $variant=telegram_product_first_variant($p);
        $variantId=(int)$variant['id'];
        $variantName=(string)$variant['name'];
        if($deliveryType==='manual') assert_manual_stock_reservable_variant($productId,$variantId,$qty);
        $warrantyLabel=product_variant_warranty_label($variant,$p);
        $warrantyDays=product_variant_warranty_days($variant,$p);
        $pdo->prepare('INSERT INTO order_items(order_id,product_id,variant_id,variant_name,product_name,warranty_label,warranty_days,qty,price,total) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$orderId,$productId,$variantId,$variantName,$p['name'].' — '.$variantName,$warrantyLabel,$warrantyDays,$qty,$price,$subtotal]);
        $itemId=(int)$pdo->lastInsertId();
        if($deliveryType!=='manual') reserve_order_item_stocks($orderId,$itemId,$productId,$qty,$expiresAt,$variantId);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    try{
        if($gatewayMode==='casaku'){
            $lockKey='';
            try{
                if(!casaku_use_unique_code())$lockKey=casaku_acquire_exact_amount_lock((int)round($baseTotal));
                $gateway=create_casaku_order(['invoice'=>$invoice,'total'=>$baseTotal,'preferred_unique_code'=>$uniqueCode]);
                $paymentTotal=(float)$gateway['payment_total'];
                $actualUnique=(int)($gateway['unique_code'] ?? 0);
                $ref=(string)$gateway['transaction_id'];
                $qris=(string)$gateway['qris'];
                $gatewayExpiresAt=(string)($gateway['expires_at'] ?? '');
                if($gatewayExpiresAt!=='')$expiresAt=$gatewayExpiresAt;
                $storedTotal=$baseTotal+$actualUnique;
                $localPayUrl=seo_url('/checkout.php?invoice='.rawurlencode($invoice));
                if(!filter_var($localPayUrl,FILTER_VALIDATE_URL) || !preg_match('~^https?://~i',$localPayUrl))$localPayUrl='';
                assert_payment_reference_available('casaku',$ref,$orderId);
                $pdo->prepare('UPDATE orders SET payment_url=?,qris_payload=?,payment_reference=?,payment_fee=0,payment_total=?,telegram_unique_code=?,total=?,payment_expires_at=? WHERE id=?')
                    ->execute([$localPayUrl,$qris,$ref,$paymentTotal,$actualUnique,$storedTotal,$expiresAt,$orderId]);
            }finally{
                if($lockKey!=='')casaku_release_exact_amount_lock($lockKey);
            }
        }else{
            $gateway=create_tokopay_order(['invoice'=>$invoice,'buyer_name'=>$name,'buyer_email'=>$email,'buyer_whatsapp'=>(string)($user['buyer_whatsapp'] ?? ''),'total'=>$total],[['product_id'=>$productId,'product_name'=>$p['name'],'price'=>$price,'qty'=>$qty]]);
            $gd=is_array($gateway['data'] ?? null)?$gateway['data']:[];
            $payUrl=(string)($gd['pay_url'] ?? $gd['checkout_url'] ?? $gd['payment_url'] ?? $gd['url'] ?? '');
            $qris=(string)($gd['qr_link'] ?? $gd['qris_url'] ?? $gd['qris_link'] ?? $gd['qris_image'] ?? $gd['qr_url'] ?? $gd['qr'] ?? $gd['qr_string'] ?? setting('qris_payload',''));
            $ref=(string)($gd['trx_id'] ?? $gd['reference'] ?? $gd['ref_id'] ?? ($gateway['reference'] ?? ''));
            $fee=(float)($gd['fee'] ?? $gd['biaya'] ?? $gd['total_fee'] ?? 0);
            $paymentTotal=(float)($gd['total_dibayar'] ?? $gd['total_bayar'] ?? $gd['total_amount'] ?? ($total+$fee));
            if($paymentTotal<$total)$paymentTotal=$total;
            assert_payment_reference_available('tokopay',$ref,$orderId);
            $pdo->prepare('UPDATE orders SET payment_url=?,qris_payload=?,payment_reference=?,payment_fee=?,payment_total=? WHERE id=?')->execute([$payUrl,$qris,$ref,$fee,$paymentTotal,$orderId]);
        }
    }catch(Throwable $e){
        $pdo->beginTransaction();
        try{$pdo->prepare("UPDATE orders SET status='failed',telegram_last_error=? WHERE id=?")->execute([$e->getMessage(),$orderId]);release_order_stock_reservations($orderId);$pdo->commit();}catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();}
        throw $e;
    }
    store_order_access_session($invoice,$password);
    $st=$pdo->prepare('SELECT * FROM orders WHERE id=?');$st->execute([$orderId]);return $st->fetch();
}
function telegram_invoice_caption(array $order): string {
    $items=order_items((int)$order['id']);$products=[];foreach($items as $it)$products[]=$it['product_name'].' x'.$it['qty'];
    $expires=!empty($order['payment_expires_at'])?telegram_display_datetime_wib((string)$order['payment_expires_at']):'-';
    $paymentTotal=(float)($order['payment_total'] ?: $order['total']);
    $text="<b>⌛ TRANSAKSI PENDING</b>\n\n";
    $text.='<b>ID Pembayaran:</b> '.telegram_html((string)$order['invoice'])."\n";
    $text.='<b>Produk:</b> '.telegram_html(implode(', ',$products))."\n";
    $text.='<b>Harga:</b> '.telegram_html(rupiah($order['subtotal']))."\n";
    if((float)($order['discount'] ?? 0)>0)$text.='<b>Diskon Voucher:</b> -'.telegram_html(rupiah($order['discount']))."\n";
    if((int)($order['telegram_unique_code'] ?? 0)>0)$text.='<b>Kode Unik:</b> +'.telegram_html(rupiah((int)$order['telegram_unique_code']))."\n";
    if((float)$order['payment_fee']>0)$text.='<b>Biaya:</b> '.telegram_html(rupiah($order['payment_fee']))."\n";
    $text.='<b>Total Dibayar:</b> '.telegram_html(rupiah($paymentTotal))."\n";
    $text.='<b>Metode:</b> QRIS'."\n";
    $text.='<b>Batas Bayar:</b> '.telegram_html($expires)."\n\n";
    $text.='Pindai QRIS di atas. Produk dikirim otomatis setelah pembayaran terkonfirmasi.';
    return $text;
}
function telegram_send_invoice(array $bot,int $chatId,array $order): void {
    global $pdo;
    telegram_clear_chat_state((int)$bot['id'],$chatId);
    $keys=[[['text'=>'🔄 Cek Pembayaran','callback_data'=>'check:'.$order['invoice']],['text'=>'❌ Batalkan','callback_data'=>'cancel:'.$order['invoice']]]];
    if(!empty($order['payment_url']))$keys[]=[['text'=>'💳 Buka Pembayaran','url'=>(string)$order['payment_url']]];
    $keys[]=[['text'=>'🏠 Menu Utama','callback_data'=>'menu']];
    $caption=telegram_invoice_caption($order);$qris=trim((string)($order['qris_payload'] ?? ''));
    $result=null;
    $photoSources=telegram_qris_photo_sources($qris);
    foreach($photoSources as $photoSource){
        try{
            $result=telegram_send_photo($bot,$chatId,$photoSource,$caption,['inline_keyboard'=>$keys]);
            if($result)break;
        }catch(Throwable $e){
            telegram_log((int)$bot['id'],'system','invoice_photo',null,$chatId,'failed',[
                'invoice'=>(string)($order['invoice'] ?? ''),
                'source'=>str_starts_with($photoSource,'data:image/')?'data-uri':parse_url($photoSource,PHP_URL_HOST),
            ],$e->getMessage());
        }
    }
    if(!$result){
        $extra=$qris!=='' ? "\n\n⚠️ QRIS gagal ditampilkan sebagai gambar. Gunakan tombol <b>Buka Pembayaran</b> untuk memindai QRIS." : "\n\n⚠️ Payload QRIS transaksi kosong.";
        $result=telegram_send_message($bot,$chatId,$caption.$extra,['inline_keyboard'=>$keys]);
    }
    if(!empty($result['message_id'])){
        $newMessageId=(int)$result['message_id'];
        telegram_clear_ui_messages($bot,$chatId);
        $oldMessageId=(int)($order['telegram_invoice_message_id'] ?? 0);
        if($oldMessageId>0 && $oldMessageId!==$newMessageId)telegram_delete_message($bot,$chatId,$oldMessageId);
        $pdo->prepare('UPDATE orders SET telegram_invoice_message_id=? WHERE id=?')->execute([$newMessageId,(int)$order['id']]);
    }
}
function telegram_delete_invoice_message(array $order): bool {
    global $pdo;
    $messageId=(int)($order['telegram_invoice_message_id'] ?? 0);
    $chatId=(int)($order['telegram_chat_id'] ?? 0);
    $botId=(int)($order['telegram_bot_id'] ?? 0);
    if($messageId<=0)return true;
    $deleted=false;
    if($chatId>0 && $botId>0){
        $invoiceBot=telegram_get_bot($botId,true);
        if($invoiceBot)$deleted=telegram_delete_message($invoiceBot,$chatId,$messageId);
    }
    if($deleted && !empty($order['id'])){
        try{$pdo->prepare('UPDATE orders SET telegram_invoice_message_id=NULL WHERE id=?')->execute([(int)$order['id']]);}catch(Throwable $e){}
    }
    return $deleted;
}
function telegram_cleanup_closed_invoice_messages(int $limit=50): int {
    global $pdo;
    $limit=max(1,min(200,$limit));$count=0;
    try{$rows=$pdo->query("SELECT * FROM orders WHERE sales_channel='telegram' AND telegram_invoice_message_id IS NOT NULL AND status IN ('delivered','cancelled','failed') ORDER BY id ASC LIMIT $limit")->fetchAll();}
    catch(Throwable $e){return 0;}
    foreach($rows as $order)if(telegram_delete_invoice_message($order))$count++;
    return $count;
}
function telegram_notify_expired_order(array $order,?array $bot=null): bool {
    global $pdo;
    if((string)($order['sales_channel'] ?? '')!=='telegram')return false;
    if(!empty($order['telegram_expired_notified_at']))return true;
    $chatId=(int)($order['telegram_chat_id'] ?? 0);
    $botId=(int)($order['telegram_bot_id'] ?? 0);
    if($chatId<=0 || $botId<=0)return false;
    $bot=$bot ?: telegram_get_bot($botId,true);
    if(!$bot)return false;
    // Klaim notifikasi terlebih dahulu agar worker dan tombol cek tidak mengirim pesan ganda.
    $claim=$pdo->prepare('UPDATE orders SET telegram_expired_notified_at=NOW() WHERE id=? AND telegram_expired_notified_at IS NULL');
    $claim->execute([(int)$order['id']]);
    if(!$claim->rowCount())return true;
    telegram_delete_invoice_message($order);
    $items=order_items((int)$order['id']);
    $productId=(int)($items[0]['product_id'] ?? 0);
    $text="❌ <b>Pembayaran Expired, Silahkan Coba Ulang!</b>";
    if(!empty($order['invoice']))$text.="\n\nID Pembayaran: <b>".telegram_html((string)$order['invoice']).'</b>';
    $keys=[];
    if($productId>0)$keys[]=[[ 'text'=>'🔄 Coba Beli Ulang','callback_data'=>'prod:'.$productId ]];
    $keys[]=[[ 'text'=>'📦 Lihat Produk','callback_data'=>'product_list:1' ],[ 'text'=>'🏠 Menu Utama','callback_data'=>'menu' ]];
    try{
        telegram_send_message($bot,$chatId,$text,['inline_keyboard'=>$keys]);
        return true;
    }catch(Throwable $e){
        try{$pdo->prepare('UPDATE orders SET telegram_expired_notified_at=NULL WHERE id=?')->execute([(int)$order['id']]);}catch(Throwable $x){}
        telegram_log($botId,'system','expired_notice',(int)($order['telegram_user_id'] ?? 0),$chatId,'failed',['invoice'=>$order['invoice'] ?? ''],$e->getMessage());
        return false;
    }
}
function telegram_expire_pending_orders_with_notifications(int $limit=100): int {
    global $pdo;
    telegram_ensure_schema();
    $limit=max(1,min(500,$limit));
    try{
        $rows=$pdo->query("SELECT * FROM orders WHERE sales_channel='telegram' AND payment_expires_at IS NOT NULL AND ((LOWER(COALESCE(payment_provider,''))='casaku' AND payment_expires_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (LOWER(COALESCE(payment_provider,''))<>'casaku' AND payment_expires_at<NOW())) AND status IN ('pending','failed') AND telegram_expired_notified_at IS NULL ORDER BY id ASC LIMIT ".$limit)->fetchAll();
    }catch(Throwable $e){return 0;}
    $count=0;
    foreach($rows as $order){
        if((string)$order['status']==='pending'){
            $pdo->beginTransaction();
            try{
                $up=$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");
                $up->execute([(int)$order['id']]);
                if($up->rowCount())release_order_stock_reservations((int)$order['id']);
                $pdo->commit();
                $order['status']='failed';
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();continue;}
        }
        if(telegram_notify_expired_order($order))$count++;
    }
    return $count;
}
function telegram_order_status_label(string $status): string {
    return ['pending'=>'Menunggu pembayaran','paid'=>'Pembayaran diterima','delivered'=>'Terkirim','cancelled'=>'Dibatalkan','failed'=>'Kedaluwarsa / gagal'][$status] ?? ucfirst($status);
}
function telegram_sync_casaku_payment(array $order,bool $force=false): array {
    global $pdo;
    if(strtolower(trim((string)($order['payment_provider'] ?? '')))!=='casaku' || empty($order['payment_reference']))return $order;
    if(!in_array((string)($order['status'] ?? ''),['pending','failed','paid'],true))return $order;
    $invoice=(string)$order['invoice'];
    if(!$force && rate_limit_hit('telegram-casaku-status',$invoice,20,60))return $order;
    $syncError='';
    try{
        $required=(float)($order['payment_total'] ?: $order['total']);
        $deepFallback=$force || !rate_limit_hit('telegram-casaku-deep',$invoice,1,30);
        $remote=casaku_remote_snapshot((string)$order['payment_reference'],$required,$deepFallback);
        $remoteStatus=(string)($remote['status'] ?? '');
        $remoteAmount=$remote['amount'] ?? null;
        $remoteId=trim((string)($remote['transaction_id'] ?? ''));
        if($remoteId!=='' && !hash_equals((string)$order['payment_reference'],$remoteId))throw new RuntimeException('Transaction ID status Casaku tidak cocok.');
        if($remoteStatus==='paid'){
            if($remoteAmount!==null && abs((float)$remoteAmount-$required)>0.0001)throw new RuntimeException('Nominal status Casaku tidak cocok dengan order.');
            try{
                $paidOrder=mark_order_paid($invoice,(string)$order['payment_reference'],true);
                if(!empty($paidOrder['_fulfillment_new']))telegram_after_order_paid($paidOrder);
            }catch(Throwable $fulfillError){
                telegram_log((int)($order['telegram_bot_id'] ?? 0),'system','casaku_fulfillment',(int)($order['telegram_user_id'] ?? 0),(int)($order['telegram_chat_id'] ?? 0),'failed',['invoice'=>$invoice],$fulfillError->getMessage());
            }
        }elseif(in_array($remoteStatus,['cancel','expired'],true) && (string)$order['status']==='pending'){
            $expiryTs=!empty($order['payment_expires_at'])?telegram_db_datetime_timestamp((string)$order['payment_expires_at']):0;
            if($expiryTs>0 && $expiryTs+180<=time()){
                $pdo->beginTransaction();
                try{
                    $up=$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");$up->execute([(int)$order['id']]);
                    if($up->rowCount())release_order_stock_reservations((int)$order['id']);
                    $pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            }
        }elseif((string)$order['status']==='paid'){
            try{
                $paidOrder=mark_order_paid($invoice,(string)$order['payment_reference'],true);
                if(!empty($paidOrder['_fulfillment_new']))telegram_after_order_paid($paidOrder);
            }catch(Throwable $e){}
        }
    }catch(Throwable $e){
        $syncError=$e->getMessage();
        telegram_log((int)($order['telegram_bot_id'] ?? 0),'system','casaku_status_check',(int)($order['telegram_user_id'] ?? 0),(int)($order['telegram_chat_id'] ?? 0),'failed',['invoice'=>$invoice],$syncError);
    }
    $lastRemoteStatus=$remoteStatus ?? '';
    $lastRemoteAmount=$remoteAmount ?? null;
    $st=$pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');$st->execute([(int)$order['id']]);
    $fresh=$st->fetch() ?: $order;
    $fresh['_casaku_remote_status']=$lastRemoteStatus;
    $fresh['_casaku_remote_amount']=$lastRemoteAmount;
    $fresh['_casaku_sync_error']=$syncError;
    return $fresh;
}
function telegram_refresh_order(string $invoice,int $telegramUserId,bool $forcePaymentCheck=false): ?array {
    global $pdo;
    $st=$pdo->prepare('SELECT * FROM orders WHERE invoice=? AND telegram_user_id=? LIMIT 1');$st->execute([$invoice,$telegramUserId]);$order=$st->fetch();
    if(!$order)return null;
    if(strtolower(trim((string)($order['payment_provider'] ?? '')))==='casaku')$order=telegram_sync_casaku_payment($order,$forcePaymentCheck);
    if($order && $order['status']==='pending' && !empty($order['payment_expires_at'])){
        $expireTs=telegram_db_datetime_timestamp((string)$order['payment_expires_at']);
        if(strtolower(trim((string)($order['payment_provider'] ?? '')))==='casaku')$expireTs+=180;
        if($expireTs<=time()){
            $pdo->beginTransaction();
            try{
                $up=$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");
                $up->execute([(int)$order['id']]);
                if($up->rowCount()){
                    release_order_stock_reservations((int)$order['id']);
                    $order['status']='failed';
                }
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}
            if($order['status']==='failed')telegram_delete_invoice_message($order);
        }
    }
    if(
        $order
        && (string)($order['status'] ?? '')==='failed'
        && (string)($order['sales_channel'] ?? '')==='telegram'
        && !empty($order['payment_expires_at'])
    ){
        $notifyTs=telegram_db_datetime_timestamp((string)$order['payment_expires_at']);
        if(strtolower(trim((string)($order['payment_provider'] ?? '')))==='casaku')$notifyTs+=180;
        if($notifyTs<=time())telegram_notify_expired_order($order);
    }
    return $order ?: null;
}
function telegram_show_history(array $bot,int $chatId,int $telegramUserId): void {
    global $pdo;
    telegram_clear_chat_state((int)$bot['id'],$chatId);
    $st=$pdo->prepare('SELECT * FROM orders WHERE telegram_user_id=? ORDER BY id DESC LIMIT 10');$st->execute([$telegramUserId]);$rows=$st->fetchAll();
    if(!$rows){telegram_send_ui_message($bot,$chatId,'Belum ada riwayat pesanan.',['inline_keyboard'=>[[['text'=>'📦 Lihat Produk','callback_data'=>'categories']]]]);return;}
    $keys=[];foreach($rows as $o)$keys[]=[[ 'text'=>telegram_order_status_label((string)$o['status']).' · '.$o['invoice'].' · '.rupiah($o['payment_total'] ?: $o['total']), 'callback_data'=>'order:'.$o['invoice'] ]];
    telegram_send_ui_message($bot,$chatId,"<b>📜 Riwayat Pesanan</b>\nPilih invoice untuk melihat detail:",['inline_keyboard'=>$keys]);
}
function telegram_show_order(array $bot,int $chatId,int $telegramUserId,string $invoice): void {
    telegram_clear_chat_state((int)$bot['id'],$chatId);
    $o=telegram_refresh_order($invoice,$telegramUserId);if(!$o){telegram_send_ui_message($bot,$chatId,'Pesanan tidak ditemukan.');return;}
    $text='<b>🧾 '.telegram_html((string)$o['invoice'])."</b>\n\n";
    $text.='<b>Status:</b> '.telegram_html(telegram_order_status_label((string)$o['status']))."\n";
    if((int)($o['telegram_unique_code'] ?? 0)>0)$text.='<b>Kode Unik:</b> +'.telegram_html(rupiah((int)$o['telegram_unique_code']))."\n";
    $text.='<b>Total:</b> '.telegram_html(rupiah($o['payment_total'] ?: $o['total']))."\n";
    $text.='<b>Tanggal:</b> '.telegram_html(display_datetime_gmt7((string)$o['created_at']))."\n";
    $keys=[];
    if($o['status']==='pending')$keys[]=[['text'=>'🔄 Cek Pembayaran','callback_data'=>'check:'.$invoice],['text'=>'❌ Batalkan','callback_data'=>'cancel:'.$invoice]];
    if($o['status']==='delivered')$keys[]=[['text'=>'📦 Kirim Ulang Produk','callback_data'=>'resend:'.$invoice]];
    $keys[]=[['text'=>'◀️ Riwayat','callback_data'=>'history']];
    telegram_send_ui_message($bot,$chatId,$text,['inline_keyboard'=>$keys]);
}
function telegram_cancel_order(int $telegramUserId,string $invoice): bool {
    global $pdo;
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT id FROM orders WHERE invoice=? AND telegram_user_id=? AND status='pending' FOR UPDATE");$st->execute([$invoice,$telegramUserId]);$id=(int)($st->fetchColumn() ?: 0);
        if(!$id){$pdo->rollBack();return false;}
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$id]);release_order_stock_reservations($id);$pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function telegram_queue_order_delivery(int $orderId): void {
    global $pdo;
    telegram_ensure_schema();
    $st=$pdo->prepare('SELECT telegram_bot_id,telegram_user_id,telegram_chat_id FROM orders WHERE id=?');$st->execute([$orderId]);$o=$st->fetch();if(!$o)return;
    $pdo->prepare("INSERT INTO telegram_delivery_queue(order_id,bot_id,telegram_user_id,chat_id,status,attempts,next_attempt_at,created_at,updated_at) VALUES(?,?,?,?, 'pending',0,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE bot_id=VALUES(bot_id),telegram_user_id=VALUES(telegram_user_id),chat_id=VALUES(chat_id),status=IF(status IN ('sent','processing'),status,'pending'),next_attempt_at=IF(status IN ('sent','processing'),next_attempt_at,NOW()),updated_at=NOW()")
        ->execute([$orderId,$o['telegram_bot_id'],$o['telegram_user_id'],$o['telegram_chat_id']]);
}
function telegram_delivery_bot_for_order(array $order): ?array {
    global $pdo;
    $active=telegram_active_bot();
    if($active){
        $st=$pdo->prepare('SELECT COUNT(*) FROM telegram_bot_users WHERE bot_id=? AND telegram_user_id=? AND is_active=1');
        $st->execute([(int)$active['id'],(int)$order['telegram_user_id']]);
        if((int)$st->fetchColumn()>0)return $active;
    }
    $origin=!empty($order['telegram_bot_id'])?telegram_get_bot((int)$order['telegram_bot_id']):null;
    if($origin && $origin['status']!=='disabled')return $origin;
    return null;
}
function telegram_try_deliver_order(int $orderId,bool $force=false): bool {
    global $pdo;
    telegram_ensure_schema();
    $st=$pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');$st->execute([$orderId]);$order=$st->fetch();if(!$order)return false;
    if((string)$order['sales_channel']!=='telegram' || empty($order['telegram_chat_id']))return false;
    if($order['status']!=='delivered')return false;
    telegram_delete_invoice_message($order);
    if(!$force && ($order['telegram_delivery_status'] ?? '')==='sent')return true;
    if(!$force){
        telegram_queue_order_delivery($orderId);
        $claim=$pdo->prepare("UPDATE telegram_delivery_queue SET status='processing',attempts=attempts+1,updated_at=NOW(),last_error=NULL WHERE order_id=? AND ((status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())) OR (status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)))");
        $claim->execute([$orderId]);
        if(!$claim->rowCount()){
            $q=$pdo->prepare('SELECT status FROM telegram_delivery_queue WHERE order_id=? LIMIT 1');$q->execute([$orderId]);
            return (string)$q->fetchColumn()==='sent';
        }
    }
    $bot=telegram_delivery_bot_for_order($order);if(!$bot)throw new RuntimeException('Bot pengiriman tidak tersedia atau pengguna belum memulai bot pengganti.');
    $chatId=(int)$order['telegram_chat_id'];
    if((int)$bot['id']!==(int)$order['telegram_bot_id']){
        $r=$pdo->prepare('SELECT chat_id FROM telegram_bot_users WHERE bot_id=? AND telegram_user_id=? AND is_active=1 LIMIT 1');$r->execute([(int)$bot['id'],(int)$order['telegram_user_id']]);$chatId=(int)($r->fetchColumn() ?: 0);
        if(!$chatId)throw new RuntimeException('Pengguna belum menekan Start pada bot aktif.');
    }
    telegram_clear_ui_messages($bot,$chatId);
    $pdo->prepare("UPDATE orders SET telegram_delivery_status='pending',telegram_delivery_attempts=telegram_delivery_attempts+1,telegram_last_error=NULL WHERE id=?")->execute([$orderId]);
    telegram_send_message($bot,$chatId,"<b>✅ PEMBAYARAN BERHASIL</b>\n\nInvoice: <code>".telegram_html((string)$order['invoice'])."</code>\nProduk digital Anda sedang dikirim di bawah ini.");
    $items=$pdo->prepare('SELECT oi.id,oi.product_name,oi.qty,oi.warranty_label,oi.warranty_days,oi.warranty_started_at,oi.warranty_expires_at,p.delivery_type,p.product_terms FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');$items->execute([$orderId]);
    foreach($items as $item){
        if(strtolower((string)($item['delivery_type'] ?? 'instant'))==='manual'){
            telegram_send_message($bot,$chatId,'<b>📦 '.telegram_html((string)$item['product_name'])."</b>\nPesanan ini diproses manual oleh admin. Anda akan menerima informasi lanjutan setelah selesai.\n\n<b>🛡 Garansi:</b> ".telegram_html(warranty_status_text($item)));
            continue;
        }
        $stocks=$pdo->prepare('SELECT content FROM delivered_stocks WHERE order_item_id=? ORDER BY id');$stocks->execute([(int)$item['id']]);$rows=$stocks->fetchAll();
        foreach($rows as $idx=>$row){
            $content=(string)$row['content'];
            if(mb_strlen($content)>3000)$content=mb_substr($content,0,2999).'…';
            $msg='<b>📦 '.telegram_html((string)$item['product_name']).'</b>';
            if(count($rows)>1)$msg.=' #'.($idx+1);
            $msg.="\n\n<pre>".telegram_html($content).'</pre>';
            $msg.="\n\n<b>🛡 Garansi:</b> ".telegram_html(warranty_status_text($item));
            if(!empty($item['product_terms']))$msg.="\n\n<b>Ketentuan:</b>\n".telegram_html(telegram_short((string)$item['product_terms'],700));
            telegram_send_message($bot,$chatId,$msg);
        }
    }
    telegram_send_message($bot,$chatId,'Simpan data produk dengan aman. Riwayat pesanan dapat dibuka kembali melalui menu <b>📜 Riwayat</b>.',['inline_keyboard'=>[[['text'=>'📜 Lihat Riwayat','callback_data'=>'history'],['text'=>'🛍 Belanja Lagi','callback_data'=>'categories']]]]);
    $pdo->prepare("UPDATE orders SET telegram_bot_id=?,telegram_chat_id=?,telegram_delivery_status='sent',telegram_delivered_at=NOW(),telegram_last_error=NULL WHERE id=?")->execute([(int)$bot['id'],$chatId,$orderId]);
    $pdo->prepare("UPDATE telegram_delivery_queue SET bot_id=?,chat_id=?,status='sent',sent_at=NOW(),updated_at=NOW(),last_error=NULL WHERE order_id=?")->execute([(int)$bot['id'],$chatId,$orderId]);
    return true;
}
function telegram_after_order_paid($orderOrInvoice): void {
    global $pdo;
    telegram_ensure_schema();
    if(is_array($orderOrInvoice))$order=$orderOrInvoice;
    else{$st=$pdo->prepare('SELECT * FROM orders WHERE invoice=? LIMIT 1');$st->execute([(string)$orderOrInvoice]);$order=$st->fetch();}
    if(!$order || (string)($order['sales_channel'] ?? '')!=='telegram')return;
    telegram_delete_invoice_message($order);
    telegram_queue_order_delivery((int)$order['id']);
    try{telegram_try_deliver_order((int)$order['id']);}
    catch(Throwable $e){
        $attempts=(int)($order['telegram_delivery_attempts'] ?? 0)+1;$delay=min(3600,max(60,$attempts*120));
        $pdo->prepare("UPDATE orders SET telegram_delivery_status='failed',telegram_last_error=? WHERE id=?")->execute([$e->getMessage(),$order['id']]);
        $pdo->prepare("UPDATE telegram_delivery_queue SET status='failed',last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE order_id=? AND status<>'sent'")->execute([$e->getMessage(),$delay,$order['id']]);
        telegram_log((int)($order['telegram_bot_id'] ?? 0),'system','delivery',(int)($order['telegram_user_id'] ?? 0),(int)($order['telegram_chat_id'] ?? 0),'failed',['order_id'=>$order['id']],$e->getMessage());
    }
}
function telegram_create_broadcast(int $botId,string $title,string $message,string $imageUrl='',string $buttonText='',string $buttonUrl=''): int {
    global $pdo;
    telegram_ensure_schema();
    if(trim($message)==='')throw new RuntimeException('Isi broadcast wajib diisi.');
    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO telegram_broadcasts(bot_id,title,message,image_url,button_text,button_url,status,scheduled_at,created_at) VALUES(?,?,?,?,?,?,'queued',NOW(),NOW())")
            ->execute([$botId,$title,$message,$imageUrl ?: null,$buttonText ?: null,$buttonUrl ?: null]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO telegram_broadcast_recipients(broadcast_id,telegram_user_id,chat_id,status,attempts,next_attempt_at) SELECT ?,telegram_user_id,chat_id,'pending',0,NOW() FROM telegram_bot_users WHERE bot_id=? AND is_active=1")
            ->execute([$id,$botId]);
        $count=(int)$pdo->query('SELECT COUNT(*) FROM telegram_broadcast_recipients WHERE broadcast_id='.$id)->fetchColumn();
        $pdo->prepare('UPDATE telegram_broadcasts SET total_count=? WHERE id=?')->execute([$count,$id]);
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function telegram_prepare_migration(int $oldBotId,int $newBotId,int $days=7): int {
    global $pdo;
    telegram_ensure_schema();
    if($oldBotId===$newBotId)throw new RuntimeException('Bot lama dan bot baru harus berbeda.');
    $old=telegram_get_bot($oldBotId,true);$new=telegram_get_bot($newBotId,true);if(!$old||!$new)throw new RuntimeException('Data bot tidak ditemukan.');
    if(empty($new['bot_username']))throw new RuntimeException('Username bot baru belum terbaca. Jalankan uji koneksi terlebih dahulu.');
    $users=$pdo->prepare('SELECT telegram_user_id,chat_id FROM telegram_bot_users WHERE bot_id=? AND is_active=1');$users->execute([$oldBotId]);
    $insert=$pdo->prepare("INSERT INTO telegram_migration_queue(old_bot_id,new_bot_id,telegram_user_id,chat_id,token_hash,token_enc,status,attempts,next_attempt_at,expires_at,created_at) VALUES(?,?,?,?,?,?,'pending',0,NOW(),DATE_ADD(NOW(),INTERVAL ? DAY),NOW()) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),token_hash=VALUES(token_hash),token_enc=VALUES(token_enc),status='pending',attempts=0,next_attempt_at=NOW(),expires_at=VALUES(expires_at),last_error=NULL");
    $count=0;foreach($users as $u){$token=telegram_random_token(18);$insert->execute([$oldBotId,$newBotId,$u['telegram_user_id'],$u['chat_id'],hash('sha256',$token),telegram_secret_encrypt($token),max(1,min(30,$days))]);$count++;}
    return $count;
}
function telegram_accept_migration(int $newBotId,int $telegramUserId,string $token): bool {
    global $pdo;
    $hash=hash('sha256',$token);
    $st=$pdo->prepare("SELECT id FROM telegram_migration_queue WHERE new_bot_id=? AND telegram_user_id=? AND token_hash=? AND status IN ('pending','sent','failed') AND expires_at>NOW() LIMIT 1");$st->execute([$newBotId,$telegramUserId,$hash]);$id=(int)($st->fetchColumn() ?: 0);if(!$id)return false;
    $pdo->prepare("UPDATE telegram_migration_queue SET status='used',used_at=NOW(),last_error=NULL WHERE id=?")->execute([$id]);
    try{$pdo->prepare("UPDATE telegram_delivery_queue q JOIN orders o ON o.id=q.order_id SET q.status='pending',q.attempts=0,q.next_attempt_at=NOW(),q.last_error=NULL,q.updated_at=NOW() WHERE o.telegram_user_id=? AND o.status='delivered' AND q.status<>'sent'")->execute([$telegramUserId]);}catch(Throwable $e){}
    return true;
}
function telegram_process_worker(int $limit=20): array {
    global $pdo;
    telegram_ensure_schema();
    $limit=max(1,min(100,$limit));
    $paymentChecks=0;
    try{
        $casakuRows=$pdo->query("SELECT * FROM orders WHERE sales_channel='telegram' AND payment_provider='casaku' AND status IN ('pending','failed','paid') AND payment_reference IS NOT NULL AND payment_reference<>'' AND (payment_expires_at IS NULL OR payment_expires_at>DATE_SUB(NOW(),INTERVAL 3 MINUTE)) ORDER BY id ASC LIMIT ".min(20,$limit))->fetchAll();
        foreach($casakuRows as $casakuOrder){telegram_sync_casaku_payment($casakuOrder);$paymentChecks++;}
    }catch(Throwable $e){}
    $expiredNotifications=telegram_expire_pending_orders_with_notifications(100);
    expire_pending_orders(100);
    telegram_cleanup_closed_invoice_messages($limit);
    $result=['deliveries'=>0,'broadcasts'=>0,'migrations'=>0,'payment_checks'=>$paymentChecks,'expired_notifications'=>$expiredNotifications,'failed'=>0];
    try{$pdo->exec("DELETE FROM telegram_updates WHERE created_at<DATE_SUB(NOW(),INTERVAL 7 DAY)");$pdo->exec("DELETE FROM telegram_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)");}catch(Throwable $e){}
    $rows=$pdo->query("SELECT order_id FROM telegram_delivery_queue WHERE attempts<10 AND ((status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())) OR (status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))) ORDER BY id ASC LIMIT $limit")->fetchAll();
    foreach($rows as $r){try{if(telegram_try_deliver_order((int)$r['order_id']))$result['deliveries']++;}catch(Throwable $e){$result['failed']++;$pdo->prepare("UPDATE telegram_delivery_queue SET status='failed',last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),updated_at=NOW() WHERE order_id=? AND status<>'sent'")->execute([$e->getMessage(),$r['order_id']]);}}
    $recips=$pdo->query("SELECT r.*,b.bot_id,b.message,b.image_url,b.button_text,b.button_url FROM telegram_broadcast_recipients r JOIN telegram_broadcasts b ON b.id=r.broadcast_id WHERE r.status IN ('pending','failed') AND r.attempts<5 AND b.status IN ('queued','sending') AND (r.next_attempt_at IS NULL OR r.next_attempt_at<=NOW()) ORDER BY r.id ASC LIMIT $limit")->fetchAll();
    foreach($recips as $r){
        try{$bot=telegram_get_bot((int)$r['bot_id']);if(!$bot)throw new RuntimeException('Bot broadcast tidak aktif.');$markup = [];

if (!empty($r['button_text']) && !empty($r['button_url'])) {
    $buttonUrl  = trim((string)$r['button_url']);
    $botUsername = ltrim((string)($bot['bot_username'] ?? ''), '@');

    $urlParts = parse_url($buttonUrl);
    $urlQuery = [];

    if (!empty($urlParts['query'])) {
        parse_str($urlParts['query'], $urlQuery);
    }

    $urlHost = strtolower((string)($urlParts['host'] ?? ''));
    $urlPath = trim((string)($urlParts['path'] ?? ''), '/');

    $isProductsDeepLink =
        in_array($urlHost, ['t.me', 'telegram.me'], true)
        && strcasecmp($urlPath, $botUsername) === 0
        && (($urlQuery['start'] ?? '') === 'products');

    if ($isProductsDeepLink) {
        // Tombol internal bot: langsung buka katalog tanpa /start.
        $markup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => (string)$r['button_text'],
                        'callback_data' => 'product_list:1',
                    ],
                ],
            ],
        ];
    } else {
        // URL biasa tetap dibuka sebagai tautan.
        $markup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => (string)$r['button_text'],
                        'url' => $buttonUrl,
                    ],
                ],
            ],
        ];
    }
}
            if(!empty($r['image_url']))telegram_send_photo($bot,(int)$r['chat_id'],(string)$r['image_url'],telegram_html((string)$r['message']),$markup);else telegram_send_message($bot,(int)$r['chat_id'],telegram_html((string)$r['message']),$markup);
            $pdo->prepare("UPDATE telegram_broadcast_recipients SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$r['id']]);$pdo->prepare("UPDATE telegram_broadcasts SET status='sending',sent_count=sent_count+1 WHERE id=?")->execute([$r['broadcast_id']]);$result['broadcasts']++;
        } catch (Throwable $e) {
    $pdo->prepare("
        UPDATE telegram_broadcast_recipients
        SET
            status = 'failed',
            attempts = attempts + 1,
            last_error = ?,
            next_attempt_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
        WHERE id = ?
    ")->execute([
        $e->getMessage(),
        $r['id']
    ]);

    $result['failed']++;
}
    }
    $pdo->exec("
    UPDATE telegram_broadcasts b
    SET
        sent_count = (
            SELECT COUNT(*)
            FROM telegram_broadcast_recipients r
            WHERE r.broadcast_id = b.id
              AND r.status = 'sent'
        ),

        failed_count = (
            SELECT COUNT(*)
            FROM telegram_broadcast_recipients r
            WHERE r.broadcast_id = b.id
              AND r.status = 'failed'
        ),

        status = CASE
            WHEN EXISTS (
                SELECT 1
                FROM telegram_broadcast_recipients r
                WHERE r.broadcast_id = b.id
                  AND (
                      r.status = 'pending'
                      OR (
                          r.status = 'failed'
                          AND r.attempts < 5
                      )
                  )
            ) THEN 'sending'

            WHEN EXISTS (
                SELECT 1
                FROM telegram_broadcast_recipients r
                WHERE r.broadcast_id = b.id
                  AND r.status = 'failed'
            ) THEN 'failed'

            ELSE 'completed'
        END,

        completed_at = CASE
            WHEN EXISTS (
                SELECT 1
                FROM telegram_broadcast_recipients r
                WHERE r.broadcast_id = b.id
                  AND (
                      r.status = 'pending'
                      OR (
                          r.status = 'failed'
                          AND r.attempts < 5
                      )
                  )
            ) THEN NULL
            ELSE COALESCE(b.completed_at, NOW())
        END

    WHERE b.status IN ('queued', 'sending')
");
    $migs=$pdo->query("SELECT q.*,b.bot_username new_username FROM telegram_migration_queue q JOIN telegram_bots b ON b.id=q.new_bot_id WHERE q.status IN ('pending','failed') AND q.attempts<5 AND q.expires_at>NOW() AND (q.next_attempt_at IS NULL OR q.next_attempt_at<=NOW()) ORDER BY q.id ASC LIMIT $limit")->fetchAll();
    foreach($migs as $m){
        try{$old=telegram_get_bot((int)$m['old_bot_id']);if(!$old)throw new RuntimeException('Bot lama tidak tersedia.');$token=telegram_secret_decrypt((string)$m['token_enc']);$url='https://t.me/'.ltrim((string)$m['new_username'],'@').'?start=migrate_'.$token;
            $text="<b>⚠️ BOT RESMI DIPINDAHKAN</b>\n\nAktifkan bot baru agar riwayat pesanan dan layanan otomatis tetap terhubung.";
            telegram_send_message($old,(int)$m['chat_id'],$text,['inline_keyboard'=>[[['text'=>'🔄 Pindah ke Bot Baru','url'=>$url]]]]);
            $pdo->prepare("UPDATE telegram_migration_queue SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$m['id']]);$result['migrations']++;
        }catch(Throwable $e){$pdo->prepare("UPDATE telegram_migration_queue SET status='failed',attempts=attempts+1,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?")->execute([$e->getMessage(),$m['id']]);$result['failed']++;}
    }
    return $result;
}
function telegram_handle_message(array $bot,array $message): void {
    $chat=$message['chat'] ?? [];$from=$message['from'] ?? [];$chatId=(int)($chat['id'] ?? 0);if(!$chatId || ($chat['type'] ?? 'private')!=='private')return;
    $user=telegram_upsert_user((int)$bot['id'],$from,$chatId);$text=trim((string)($message['text'] ?? ''));$labels=telegram_button_labels();
    if(preg_match('~^/start(?:@\w+)?(?:\s+(.+))?$~i',$text,$m)){
        $param=trim((string)($m[1] ?? ''));$migrated=false;
        if(str_starts_with($param,'migrate_')){
            $migrated=telegram_accept_migration((int)$bot['id'],(int)$user['telegram_user_id'],substr($param,8));
        }
        if($migrated)telegram_send_message($bot,$chatId,'✅ Akun berhasil dihubungkan ke bot baru. Riwayat dan pesanan Anda tetap tersimpan.');
        if(preg_match('~^product_(\d+)$~',$param,$productMatch)){
            telegram_send_product_detail($bot,$chatId,(int)$productMatch[1]);return;
        }
        if($param==='products'){
            telegram_send_product_text_list($bot,$chatId,1);return;
        }
        telegram_send_main_menu($bot,$chatId,$user);return;
    }
    if($text==='/menu' || $text===$labels['website']){
        if($text===$labels['website']){
            telegram_clear_chat_state((int)$bot['id'],$chatId);
            telegram_send_ui_message($bot,$chatId,'Buka website resmi melalui tombol berikut:',['inline_keyboard'=>[[['text'=>'🌐 Buka '.setting('site_name','Website'),'url'=>seo_site_url()]]]]);return;
        }
        telegram_send_main_menu($bot,$chatId,$user);return;
    }
    if(preg_match('~^/products(?:@\w+)?$~i',$text) || $text===$labels['products']){
        telegram_send_product_text_list($bot,$chatId,1);return;
    }
    if(preg_match('~^/vouchers?(?:@\w+)?$~i',$text) || $text===$labels['vouchers']){
        telegram_send_vouchers($bot,$chatId);return;
    }
    if($text==='/orders' || $text===$labels['orders']){telegram_show_history($bot,$chatId,(int)$user['telegram_user_id']);return;}
    if($text==='/help' || $text===$labels['help']){
        telegram_clear_chat_state((int)$bot['id'],$chatId);
        $help=telegram_multiline_setting((string)setting('telegram_help_message',"1. Pilih List Produk.\n2. Pilih produk dan jumlah.\n3. Masukkan voucher jika ada.\n4. Lanjutkan pembayaran dan pindai QRIS.\n5. Produk dikirim otomatis setelah pembayaran berhasil."));
        telegram_send_ui_message($bot,$chatId,"<b>❓ Cara Order</b>\n\n".telegram_html($help));return;
    }
    if($text===$labels['info']){
        telegram_clear_chat_state((int)$bot['id'],$chatId);
        $info=telegram_multiline_setting((string)setting('telegram_info_message','Bot auto-order resmi. Pastikan username bot sesuai informasi pada website resmi.'));
        telegram_send_ui_message($bot,$chatId,"<b>ℹ️ Informasi</b>\n\n".telegram_html($info));return;
    }

    $state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);
    if(($state['state'] ?? '')==='awaiting_voucher'){
        $stateData=is_array($state['data'] ?? null)?$state['data']:[];
        $productId=(int)($stateData['product_id'] ?? 0);$qty=max(1,(int)($stateData['qty'] ?? 1));
        $backCallback=trim((string)($stateData['back_callback'] ?? ''));
        $uniqueCode=(int)($stateData['unique_code'] ?? 0);
        try{
            if($text==='')throw new RuntimeException('Kirim kode voucher dalam bentuk teks.');
            telegram_send_checkout_confirmation($bot,$chatId,$productId,$qty,$text,$backCallback,$uniqueCode);
        }catch(Throwable $e){
            telegram_set_chat_state((int)$bot['id'],$chatId,'awaiting_voucher',$stateData);
            telegram_send_ui_message(
                $bot,
                $chatId,
                '❌ '.telegram_html($e->getMessage())."\n\nSilakan kirim kode voucher lain.",
                ['inline_keyboard'=>[[['text'=>'Tanpa Voucher','callback_data'=>'novoucher:'.$productId.':'.$qty]],[['text'=>'◀️ Kembali ke Konfirmasi','callback_data'=>'reconfirm:'.$productId.':'.$qty]]]]
            );
        }
        return;
    }

    if(preg_match('~^\d+$~',$text)){
        $selectionNumber=(int)$text;
        if(($state['state'] ?? '')==='product_text_list'){
            $stateData=is_array($state['data'] ?? null)?$state['data']:[];
            $numbers=is_array($stateData['numbers'] ?? null)?$stateData['numbers']:[];
            $productId=(int)($numbers[$selectionNumber] ?? $numbers[(string)$selectionNumber] ?? 0);
            $statePage=max(1,(int)($stateData['page'] ?? 1));
            $fromCategory=(string)($stateData['origin'] ?? '')==='category';
            $stateCategoryId=max(0,(int)($stateData['category_id'] ?? 0));
            if($productId>0){
                telegram_send_product_detail($bot,$chatId,$productId,$fromCategory?'cat:'.$stateCategoryId.':'.$statePage:'product_list:'.$statePage);return;
            }
            $notice='Nomor produk '.$selectionNumber.' tidak tersedia pada halaman ini.';
            if($fromCategory)telegram_send_products($bot,$chatId,$stateCategoryId,$statePage,$notice);
            else telegram_send_product_text_list($bot,$chatId,$statePage,$notice);
            return;
        }
    }
    telegram_send_main_menu($bot,$chatId,$user);
}
function telegram_handle_callback(array $bot,array $callback): void {
    $id=(string)($callback['id'] ?? '');$data=(string)($callback['data'] ?? '');$from=$callback['from'] ?? [];$message=$callback['message'] ?? [];$chatId=(int)($message['chat']['id'] ?? 0);$messageId=(int)($message['message_id'] ?? 0);$isCaption=!empty($message['photo']) || array_key_exists('caption',$message);if(!$chatId)return;
    $user=telegram_upsert_user((int)$bot['id'],$from,$chatId);$deferAnswer=str_starts_with($data,'check:');if(!$deferAnswer)telegram_answer_callback($bot,$id);
    try{
        if($data==='noop')return;
        if($data==='menu'){telegram_send_main_menu($bot,$chatId,$user);return;}
        if($data==='categories'){telegram_send_categories($bot,$chatId);return;}
        if($data==='vouchers'){telegram_send_vouchers($bot,$chatId);return;}
        if(preg_match('~^product_list:(\d+)$~',$data,$m)){telegram_send_product_text_list($bot,$chatId,(int)$m[1]);return;}
        if($data==='history'){telegram_show_history($bot,$chatId,(int)$user['telegram_user_id']);return;}
        if(preg_match('~^cat:(\d+):(\d+)$~',$data,$m)){telegram_send_products($bot,$chatId,(int)$m[1],(int)$m[2]);return;}
        if(preg_match('~^prodlist:(\d+):(\d+)$~',$data,$m)){telegram_send_product_detail($bot,$chatId,(int)$m[1],'product_list:'.max(1,(int)$m[2]));return;}
        if(preg_match('~^catprod:(\d+):(\d+):(\d+)$~',$data,$m)){
            telegram_send_product_detail($bot,$chatId,(int)$m[1],'cat:'.max(0,(int)$m[2]).':'.max(1,(int)$m[3]));return;
        }
        if(preg_match('~^prod:(\d+)$~',$data,$m)){telegram_send_product_detail($bot,$chatId,(int)$m[1]);return;}

        if(preg_match('~^qtydec:(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$qty=max(1,(int)$m[2]-1);
            $back=telegram_product_detail_back_callback((int)$bot['id'],(int)$user['telegram_user_id'],$productId);
            telegram_edit_product_detail($bot,$chatId,$messageId,$isCaption,$productId,$back,$qty);return;
        }
        if(preg_match('~^qtyinc:(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$p=telegram_get_product($productId);if(!$p)throw new RuntimeException('Produk tidak ditemukan.');
            $max=max(1,min(5,telegram_product_stock($p)));$qty=min($max,(int)$m[2]+1);
            $back=telegram_product_detail_back_callback((int)$bot['id'],(int)$user['telegram_user_id'],$productId);
            telegram_edit_product_detail($bot,$chatId,$messageId,$isCaption,$productId,$back,$qty);return;
        }
        if(preg_match('~^confirm:(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$qty=(int)$m[2];
            $state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);$sd=is_array($state['data'] ?? null)?$state['data']:[];
            $back=telegram_product_detail_back_callback((int)$bot['id'],(int)$user['telegram_user_id'],$productId);
            $uniqueCode=((int)($sd['product_id'] ?? 0)===$productId)?(int)($sd['unique_code'] ?? 0):0;
            telegram_send_checkout_confirmation($bot,$chatId,$productId,$qty,'',$back,$uniqueCode);return;
        }
        if(preg_match('~^voucher:(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$qty=(int)$m[2];
            $state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);$sd=is_array($state['data'] ?? null)?$state['data']:[];
            $back=telegram_product_detail_back_callback((int)$bot['id'],(int)$user['telegram_user_id'],$productId);
            $currentPromo=((int)($sd['product_id'] ?? 0)===$productId)?(string)($sd['promo_code'] ?? ''):'';
            $uniqueCode=((int)($sd['product_id'] ?? 0)===$productId)?(int)($sd['unique_code'] ?? 0):0;
            telegram_send_voucher_prompt($bot,$chatId,$productId,$qty,$back,$currentPromo,$uniqueCode);return;
        }
        if(preg_match('~^(?:novoucher|removevoucher):(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$qty=(int)$m[2];
            $state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);$sd=is_array($state['data'] ?? null)?$state['data']:[];
            $back=telegram_product_detail_back_callback((int)$bot['id'],(int)$user['telegram_user_id'],$productId);
            $uniqueCode=((int)($sd['product_id'] ?? 0)===$productId)?(int)($sd['unique_code'] ?? 0):0;
            telegram_send_checkout_confirmation($bot,$chatId,$productId,$qty,'',$back,$uniqueCode);return;
        }
        if(preg_match('~^reconfirm:(\d+):(\d+)$~',$data,$m)){
            $state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);$sd=is_array($state['data'] ?? null)?$state['data']:[];
            $back=trim((string)($sd['back_callback'] ?? ''));
            $promoCode=(string)($sd['promo_code'] ?? '');
            $uniqueCode=(int)($sd['unique_code'] ?? 0);
            telegram_send_checkout_confirmation($bot,$chatId,(int)$m[1],(int)$m[2],$promoCode,$back,$uniqueCode);return;
        }
        if(preg_match('~^backdetail:(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);$sd=is_array($state['data'] ?? null)?$state['data']:[];
            $back=trim((string)($sd['back_callback'] ?? ''));
            telegram_send_product_detail($bot,$chatId,$productId,$back,(int)$m[2]);return;
        }
        if(preg_match('~^pay:(\d+):(\d+)(?::(\d+))?$~',$data,$m)){
            $productId=(int)$m[1];$qty=(int)$m[2];$uniqueCode=(int)($m[3] ?? 0);
            $state=telegram_get_state((int)$bot['id'],(int)$user['telegram_user_id']);$sd=is_array($state['data'] ?? null)?$state['data']:[];
            $promoCode=((int)($sd['product_id'] ?? 0)===$productId && (int)($sd['qty'] ?? 0)===$qty)?(string)($sd['promo_code'] ?? ''):'';
            if($uniqueCode<=0 && (int)($sd['product_id'] ?? 0)===$productId && (int)($sd['qty'] ?? 0)===$qty)$uniqueCode=(int)($sd['unique_code'] ?? 0);
            $order=telegram_create_order($bot,$user,$chatId,$productId,$qty,$promoCode,$uniqueCode);
            telegram_send_invoice($bot,$chatId,$order);return;
        }

        // Kompatibilitas tombol lama: tetap diarahkan ke alur konfirmasi baru.
        if(preg_match('~^qtylist:(\d+):(\d+)$~',$data,$m)){telegram_send_product_detail($bot,$chatId,(int)$m[1],'product_list:'.max(1,(int)$m[2]),1);return;}
        if(preg_match('~^qtycat:(\d+):(\d+):(\d+)$~',$data,$m)){telegram_send_product_detail($bot,$chatId,(int)$m[1],'cat:'.max(0,(int)$m[2]).':'.max(1,(int)$m[3]),1);return;}
        if(preg_match('~^qty:(\d+)$~',$data,$m)){telegram_send_product_detail($bot,$chatId,(int)$m[1],'',1);return;}
        if(preg_match('~^buy:(\d+):(\d+)$~',$data,$m)){
            $productId=(int)$m[1];$back=telegram_product_detail_back_callback((int)$bot['id'],(int)$user['telegram_user_id'],$productId);
            telegram_send_checkout_confirmation($bot,$chatId,$productId,(int)$m[2],'',$back);return;
        }

        if(preg_match('~^order:(.+)$~',$data,$m)){telegram_show_order($bot,$chatId,(int)$user['telegram_user_id'],$m[1]);return;}
        if(preg_match('~^check:(.+)$~',$data,$m)){
            // Tombol manual selalu memaksa pengecekan baru.
            $o=telegram_refresh_order($m[1],(int)$user['telegram_user_id'],true);
            if(!$o)throw new RuntimeException('Pesanan tidak ditemukan.');
            if($o['status']==='delivered')telegram_after_order_paid($o);
            elseif(in_array((string)$o['status'],['failed','cancelled'],true))telegram_delete_invoice_message($o);
            $label=telegram_order_status_label((string)$o['status']);
            $remote=strtolower(trim((string)($o['_casaku_remote_status'] ?? '')));
            $syncError=trim((string)($o['_casaku_sync_error'] ?? ''));
            if((string)$o['status']==='pending' && $remote!=='')$label.=' · Casaku: '.$remote;
            elseif((string)$o['status']==='pending' && $syncError!=='')$label.=' · API Casaku gagal dicek';
            telegram_answer_callback($bot,$id,'Status: '.telegram_short($label,180));return;
        }
        if(preg_match('~^cancel:(.+)$~',$data,$m)){
            $o=telegram_refresh_order($m[1],(int)$user['telegram_user_id']);$cancelled=telegram_cancel_order((int)$user['telegram_user_id'],$m[1]);
            if($cancelled && $o)telegram_delete_invoice_message($o);
            telegram_send_ui_message($bot,$chatId,$cancelled?'✅ Pesanan dibatalkan dan stok dilepas kembali.':'Pesanan tidak dapat dibatalkan.');return;
        }
        if(preg_match('~^resend:(.+)$~',$data,$m)){$o=telegram_refresh_order($m[1],(int)$user['telegram_user_id']);if(!$o||$o['status']!=='delivered')throw new RuntimeException('Produk belum tersedia untuk dikirim ulang.');telegram_try_deliver_order((int)$o['id'],true);return;}
    }catch(Throwable $e){
        if($deferAnswer)telegram_answer_callback($bot,$id,'Gagal: '.telegram_short($e->getMessage(),120));
        else telegram_send_ui_message($bot,$chatId,'⚠️ '.telegram_html($e->getMessage()));
        telegram_log((int)$bot['id'],'system','callback',(int)$user['telegram_user_id'],$chatId,'failed',$data,$e->getMessage());
    }
}
function telegram_handle_update(array $bot,array $update): void {
    $updateId=(int)($update['update_id'] ?? 0);if($updateId && !telegram_register_update((int)$bot['id'],$updateId))return;
    telegram_log((int)$bot['id'],'in','update',isset($update['message']['from']['id'])?(int)$update['message']['from']['id']:(isset($update['callback_query']['from']['id'])?(int)$update['callback_query']['from']['id']:null),isset($update['message']['chat']['id'])?(int)$update['message']['chat']['id']:(isset($update['callback_query']['message']['chat']['id'])?(int)$update['callback_query']['message']['chat']['id']:null),'received',$update);
    if(!empty($update['message']))telegram_handle_message($bot,$update['message']);
    elseif(!empty($update['callback_query']))telegram_handle_callback($bot,$update['callback_query']);
}
