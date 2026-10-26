<?php
function order_delivery_ensure_schema(): void {
    global $pdo;
    static $done=false;
    if($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_delivery_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        channel VARCHAR(20) NOT NULL,
        recipient VARCHAR(190) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NULL,
        last_error TEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        sent_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_order_delivery_channel (order_id,channel),
        KEY idx_order_delivery_work (status,next_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done=true;
}
function order_delivery_email_enabled(): bool {
    return (string)setting('delivery_email_enabled','1')==='1';
}
function order_delivery_whatsapp_enabled(): bool {
    return (string)setting('delivery_whatsapp_enabled','0')==='1';
}
function order_delivery_channel_enabled(string $channel): bool {
    return $channel==='email' ? order_delivery_email_enabled() : ($channel==='whatsapp' ? order_delivery_whatsapp_enabled() : false);
}
function order_delivery_normalize_whatsapp(string $value): string {
    $digits=preg_replace('/\D+/','',$value) ?? '';
    if($digits==='') return '';
    $country=preg_replace('/\D+/','',(string)setting('fonnte_country_code','62')) ?: '62';
    if(str_starts_with($digits,'0')) $digits=$country.substr($digits,1);
    elseif(str_starts_with($digits,'8')) $digits=$country.$digits;
    if(strlen($digits)<10 || strlen($digits)>16) return '';
    return $digits;
}
function order_delivery_default_whatsapp_template(): string {
    return "Halo {nama},\n\nPembayaran pesanan *{invoice}* sudah lunas. Berikut produk digital Anda:\n\n{detail_produk}\n\nSimpan data produk dengan aman.\n{info_password}\n{info_link}\n\nTerima kasih,\n{nama_toko}";
}
function order_delivery_render_template(string $template,array $values): string {
    $replace=[];
    foreach($values as $key=>$value) $replace['{'.$key.'}']=(string)$value;
    return trim(strtr($template,$replace));
}
function order_delivery_payload(int $orderId): array {
    global $pdo;
    $st=$pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $order=$st->fetch();
    if(!$order) throw new RuntimeException('Order pengiriman tidak ditemukan.');

    $items=$pdo->prepare('SELECT oi.*,p.delivery_type,p.product_terms FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');
    $items->execute([$orderId]);
    $rows=$items->fetchAll();
    $detailBlocks=[];
    $productLines=[];
    $stockBlocks=[];
    $warrantyLines=[];
    $termsBlocks=[];

    foreach($rows as $item){
        $name=trim((string)$item['product_name']) ?: 'Produk Digital';
        $label=$name.((int)$item['qty']>1?' x'.(int)$item['qty']:'');
        $productLines[]='- '.$label;
        $detail=[$label];
        $stockText=[];

        if(strtolower((string)($item['delivery_type'] ?? 'instant'))==='manual'){
            $manual='Pengiriman produk ini diproses manual oleh admin.';
            $detail[]=$manual;
            $stockText[]=$manual;
        }else{
            $stocks=$pdo->prepare('SELECT content FROM delivered_stocks WHERE order_item_id=? ORDER BY id');
            $stocks->execute([(int)$item['id']]);
            $stockRows=$stocks->fetchAll();
            foreach($stockRows as $index=>$stock){
                $content=trim((string)$stock['content']);
                if($content==='') continue;
                if(count($stockRows)>1){
                    $detail[]='Data #'.($index+1).':';
                    $stockText[]='Data #'.($index+1).':';
                }
                $detail[]=$content;
                $stockText[]=$content;
            }
            if(!$stockText){
                $missing='Data produk belum tersedia. Hubungi admin dengan menyertakan invoice.';
                $detail[]=$missing;
                $stockText[]=$missing;
            }
        }

        if(function_exists('warranty_status_text')){
            $warranty='Garansi: '.warranty_status_text($item);
            $detail[]=$warranty;
            $warrantyLines[]=$label.' — '.$warranty;
        }
        $terms=trim((string)($item['product_terms'] ?? ''));
        if($terms!==''){
            $detail[]="Ketentuan:\n".$terms;
            $termsBlocks[]=$label."\n".$terms;
        }

        $detailBlocks[]=implode("\n",$detail);
        $stockBlocks[]=$label."\n".implode("\n",$stockText);
    }

    $siteName=trim((string)setting('site_name','Digital Store')) ?: 'Digital Store';
    $buyerName=trim((string)$order['buyer_name']) ?: 'Kak';
    $password=function_exists('decrypt_admin_order_password')?trim((string)decrypt_admin_order_password($order['access_password_admin_enc'] ?? '')):'';
    $track=rtrim((string)setting('site_url',''),'/');
    if($track!=='') $track.='/track-order.php?invoice='.rawurlencode((string)$order['invoice']);

    $detailProduk=implode("\n\n",$detailBlocks);
    $intro='Halo '.$buyerName.",\n\nPembayaran pesanan ".(string)$order['invoice']." sudah lunas. Berikut produk digital Anda:\n\n";
    $footer="\n\nSimpan data produk dengan aman.";
    if($password!=='') $footer.="\nKata sandi akses pesanan: ".$password;
    if($track!=='') $footer.="\nLacak / buka pesanan: ".$track;
    $footer.="\nSetelah menerima produk, beri ulasan di halaman produk (gunakan invoice + kata sandi untuk verifikasi pembeli).";
    $footer.="\n\nTerima kasih,\n".$siteName;
    $emailBody=$intro.$detailProduk.$footer;

    $template=trim((string)setting('delivery_whatsapp_template',''));
    if($template==='') $template=order_delivery_default_whatsapp_template();
    $vars=[
        'nama'=>$buyerName,
        'invoice'=>(string)$order['invoice'],
        'nama_toko'=>$siteName,
        'produk'=>implode("\n",$productLines),
        'detail_produk'=>$detailProduk,
        'data_produk'=>implode("\n\n",$stockBlocks),
        'garansi'=>implode("\n",$warrantyLines),
        'ketentuan'=>implode("\n\n",$termsBlocks),
        'total'=>function_exists('rupiah')?rupiah((float)($order['payment_total'] ?? $order['total'] ?? 0)):'Rp '.number_format((float)($order['payment_total'] ?? $order['total'] ?? 0),0,',','.'),
        'password_pesanan'=>$password,
        'link_pesanan'=>$track,
        'info_password'=>$password!==''?'Kata sandi akses pesanan: '.$password:'',
        'info_link'=>$track!==''?'Lacak / buka pesanan: '.$track:'',
        'email'=>(string)($order['buyer_email'] ?? ''),
        'whatsapp'=>(string)($order['buyer_whatsapp'] ?? ''),
    ];
    $whatsappBody=order_delivery_render_template($template,$vars);
    if($whatsappBody==='') $whatsappBody=order_delivery_render_template(order_delivery_default_whatsapp_template(),$vars);

    return [
        'order'=>$order,
        'subject'=>'Produk Digital '.(string)$order['invoice'].' - '.$siteName,
        'body'=>$emailBody,
        'email_body'=>$emailBody,
        'whatsapp_body'=>$whatsappBody,
    ];
}
function order_delivery_queue_order(int $orderId): void {
    global $pdo;
    order_delivery_ensure_schema();
    $st=$pdo->prepare('SELECT id,status,buyer_email,buyer_whatsapp FROM orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $order=$st->fetch();
    if(!$order || (string)$order['status']!=='delivered') return;

    if(order_delivery_email_enabled() && filter_var((string)$order['buyer_email'],FILTER_VALIDATE_EMAIL) && !str_ends_with(strtolower((string)$order['buyer_email']),'@telegram.invalid')){
        $pdo->prepare("INSERT INTO order_delivery_queue(order_id,channel,recipient,status,attempts,next_attempt_at,created_at,updated_at) VALUES(?,'email',?,'pending',0,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE recipient=VALUES(recipient),status=IF(status='sent','sent',status),next_attempt_at=IF(status='sent',next_attempt_at,COALESCE(next_attempt_at,NOW())),updated_at=NOW()")
            ->execute([$orderId,(string)$order['buyer_email']]);
    }
    $wa=order_delivery_normalize_whatsapp((string)$order['buyer_whatsapp']);
    if(order_delivery_whatsapp_enabled() && $wa!==''){
        $pdo->prepare("INSERT INTO order_delivery_queue(order_id,channel,recipient,status,attempts,next_attempt_at,created_at,updated_at) VALUES(?,'whatsapp',?,'pending',0,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE recipient=VALUES(recipient),status=IF(status='sent','sent',status),next_attempt_at=IF(status='sent',next_attempt_at,COALESCE(next_attempt_at,NOW())),updated_at=NOW()")
            ->execute([$orderId,$wa]);
    }
}
function order_delivery_send_whatsapp(string $target,string $message): bool {
    $token=trim((string)setting('fonnte_token',''));
    if($token==='') throw new RuntimeException('Token Fonnte belum diatur.');
    $fields=http_build_query([
        'target'=>$target,
        'message'=>$message,
        'countryCode'=>preg_replace('/\D+/','',(string)setting('fonnte_country_code','62')) ?: '62',
    ]);
    $url='https://api.fonnte.com/send';
    $response='';$code=0;
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$fields,
            CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT=>6,
            CURLOPT_TIMEOUT=>10,
        ]);
        $response=(string)curl_exec($ch);
        $error=curl_error($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($response==='' && $error!=='') throw new RuntimeException('Fonnte tidak dapat dihubungi: '.$error);
    }else{
        $context=stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>"Authorization: {$token}\r\nContent-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content'=>$fields,
            'timeout'=>10,
            'ignore_errors'=>true,
        ]]);
        $raw=@file_get_contents($url,false,$context);
        if($raw===false) throw new RuntimeException('Fonnte tidak dapat dihubungi.');
        $response=(string)$raw;
        if(isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)) $code=(int)$m[1];
    }
    $json=json_decode($response,true);
    if($code>=400) throw new RuntimeException('Fonnte HTTP '.$code.'.');
    if(!is_array($json) || empty($json['status'])){
        $reason=is_array($json)?trim((string)($json['reason'] ?? $json['message'] ?? '')):'';
        throw new RuntimeException('Fonnte menolak pengiriman'.($reason!==''?': '.$reason:'.'));
    }
    return true;
}
function order_delivery_process_row(array $row): bool {
    global $pdo;
    $channel=(string)$row['channel'];
    if(!order_delivery_channel_enabled($channel)) return false;

    $claim=$pdo->prepare("UPDATE order_delivery_queue SET status='processing',attempts=attempts+1,updated_at=NOW(),last_error=NULL WHERE id=? AND ((status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())) OR (status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)))");
    $claim->execute([(int)$row['id']]);
    if(!$claim->rowCount()) return false;

    try{
        $payload=order_delivery_payload((int)$row['order_id']);
        if($channel==='email'){
            require_once __DIR__.'/mailer.php';
            if(!send_text_email((string)$row['recipient'],(string)$payload['subject'],(string)$payload['email_body'])) throw new RuntimeException('Email tidak berhasil dikirim oleh server mail.');
        }elseif($channel==='whatsapp'){
            order_delivery_send_whatsapp((string)$row['recipient'],(string)$payload['whatsapp_body']);
        }else{
            throw new RuntimeException('Kanal pengiriman tidak dikenal.');
        }
        $pdo->prepare("UPDATE order_delivery_queue SET status='sent',sent_at=NOW(),next_attempt_at=NULL,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        return true;
    }catch(Throwable $e){
        $attempts=(int)$row['attempts']+1;
        $delay=min(3600,max(60,$attempts*120));
        $pdo->prepare("UPDATE order_delivery_queue SET status='failed',last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,1500),$delay,(int)$row['id']]);
        return false;
    }
}
function order_delivery_process_order(int $orderId): array {
    global $pdo;
    order_delivery_queue_order($orderId);
    $st=$pdo->prepare("SELECT * FROM order_delivery_queue WHERE order_id=? AND ((status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())) OR (status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))) ORDER BY id");
    $st->execute([$orderId]);
    $sent=0;$failed=0;
    foreach($st->fetchAll() as $row){
        if(!order_delivery_channel_enabled((string)$row['channel'])) continue;
        if(order_delivery_process_row($row)) $sent++; else $failed++;
    }
    return ['sent'=>$sent,'failed'=>$failed];
}
function order_delivery_after_fulfilled(array $order): void {
    if(empty($order['id']) || (string)($order['status'] ?? '')!=='delivered') return;
    try{order_delivery_process_order((int)$order['id']);}catch(Throwable $e){}
}
function order_delivery_process_queue(int $limit=50): array {
    global $pdo;
    order_delivery_ensure_schema();
    $limit=max(1,min(200,$limit));
    $rows=$pdo->query("SELECT * FROM order_delivery_queue WHERE ((status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())) OR (status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))) ORDER BY id ASC LIMIT ".$limit)->fetchAll();
    $sent=0;$failed=0;
    foreach($rows as $row){
        if(!order_delivery_channel_enabled((string)$row['channel'])) continue;
        if(order_delivery_process_row($row)) $sent++; else $failed++;
    }
    return ['contact_delivery_sent'=>$sent,'contact_delivery_failed'=>$failed];
}
