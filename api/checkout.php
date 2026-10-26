<?php
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/casaku.php';
require_once __DIR__.'/../includes/nowpayments.php';
require_once __DIR__.'/../includes/order_delivery.php';
require_once __DIR__.'/../includes/payments.php';
try{
    ensure_order_security_columns();
    ensure_product_extra_columns();
    if(maintenance_mode_enabled()) json_out(['ok'=>false,'maintenance'=>true,'message'=>maintenance_mode_message()],503);
    if(rate_limit_hit('checkout','checkout',10,600))json_out(['ok'=>false,'message'=>'Terlalu banyak percobaan checkout. Coba lagi beberapa menit lagi.'],429);
    $cart=json_decode($_POST['cart']??'[]',true);
    if(!$cart || !is_array($cart))json_out(['ok'=>false,'message'=>'Keranjang kosong'],400);
    if((string)($_POST['terms_accepted']??'')!=='1')json_out(['ok'=>false,'message'=>'Anda harus menyetujui syarat & ketentuan produk.'],422);
    $name=trim((string)($_POST['buyer_name']??''));
    $email=trim((string)($_POST['buyer_email']??''));
    $wa=trim((string)($_POST['buyer_whatsapp']??''));
    // Contact rule: name required; email XOR/WA — at least one valid contact.
    if($name==='')json_out(['ok'=>false,'message'=>'Nama wajib diisi.'],422);
    if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))json_out(['ok'=>false,'message'=>'Email tidak valid.'],422);
    if($wa!==''){
        $wa=order_delivery_normalize_whatsapp($wa);
        if($wa==='')json_out(['ok'=>false,'message'=>'Nomor WhatsApp tidak valid. Gunakan nomor aktif, misalnya 08123456789.'],422);
    }
    if($email==='' && $wa==='')json_out(['ok'=>false,'message'=>'Isi email atau nomor WhatsApp (minimal salah satu) untuk pengiriman produk.'],422);

    // Payment method: wizard posts payment_method; default = admin global gateway.
    $paymentMethod=trim((string)($_POST['payment_method']??''));
    if($paymentMethod===''){
        $paymentMethod=(string)setting('payment_gateway','qris_static');
    }
    $paymentMethod=strtolower($paymentMethod);
    if(!in_array($paymentMethod,['demo','nowpayments','tokopay','casaku','qris_static'],true)){
        json_out(['ok'=>false,'message'=>'Metode pembayaran tidak valid.'],422);
    }
    if($paymentMethod==='demo' && !checkout_demo_payment_enabled()){
        json_out(['ok'=>false,'message'=>'Pembayaran demo sedang dinonaktifkan.'],422);
    }

    $subtotal=0;$items=[];
    foreach($cart as $row){
        $id=(int)($row['id']??0);$qty=max(1,min(99,(int)($row['qty']??1)));
        $variantId=(int)($row['variant_id']??0);
        if($variantId<=0)json_out(['ok'=>false,'message'=>'Silakan pilih varian untuk produk '.$id.'.'],422);
        $st=$pdo->prepare('SELECT p.* FROM products p WHERE p.id=? AND p.status=1');
        $st->execute([$id]);$p=$st->fetch();
        if(!$p)json_out(['ok'=>false,'message'=>'Produk tidak ditemukan atau sedang nonaktif'],404);
        $st=$pdo->prepare('SELECT * FROM product_variants WHERE id=? AND product_id=? AND status=1 LIMIT 1');
        $st->execute([$variantId,(int)$p['id']]);$variant=$st->fetch();
        if(!$variant)json_out(['ok'=>false,'message'=>'Varian produk tidak ditemukan atau tidak aktif.'],422);
        $deliveryType=strtolower((string)($p['delivery_type']??'instant'))==='manual'?'manual':'instant';
        $availableStock=product_variant_stock($p,$variant);
        if($availableStock<=0)json_out(['ok'=>false,'message'=>'Stok '.$p['name'].' ('.$variant['name'].') habis. Produk tidak bisa di-checkout.'],409);
        if($availableStock<$qty)json_out(['ok'=>false,'message'=>'Stok '.$p['name'].' ('.$variant['name'].') tidak cukup. Sisa stok: '.$availableStock],409);
        $price=product_variant_price($p,$variant);
        $items[]=['p'=>$p,'qty'=>$qty,'price'=>$price,'total'=>$price*$qty,'delivery_type'=>$deliveryType,'variant'=>$variant];
        $subtotal+=$price*$qty;
    }

    $discount=0;$promoId=null;$promo=strtoupper(trim((string)($_POST['promo_code']??'')));
    if($promo!==''){
        $st=$pdo->prepare('SELECT * FROM promos WHERE UPPER(TRIM(code))=? LIMIT 1');$st->execute([$promo]);$pr=$st->fetch();
        if(!$pr)json_out(['ok'=>false,'message'=>'Kode promo tidak ditemukan.'],422);
        [$valid,$discount,$message]=promo_validate_and_discount($pr,(float)$subtotal);
        if(!$valid)json_out(['ok'=>false,'message'=>$message],422);
        $promoId=(int)$pr['id'];
    }

    $total=$subtotal-$discount;$invoice=invoice_no();$accessPassword=random_access_password();$accessHash=password_hash($accessPassword,PASSWORD_DEFAULT);
    $gatewayMode=$paymentMethod;
    if(!in_array($gatewayMode,['demo','qris_static','tokopay','casaku','nowpayments'],true))$gatewayMode='qris_static';
    if($gatewayMode==='demo'){
        // Demo orders expire far out — paid immediately after insert.
        $expiredMinutes=60;
    }elseif($gatewayMode==='casaku'){
        $expiredMinutes=casaku_expired_minutes();
    }elseif($gatewayMode==='nowpayments'){
        $expiredMinutes=nowpayments_expired_minutes();
    }else{
        $expiredMinutes=tokopay_expired_minutes();
    }
    $expiresAt=date('Y-m-d H:i:s',time()+($expiredMinutes*60));
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO orders(invoice,sales_channel,buyer_name,buyer_email,buyer_whatsapp,subtotal,discount,promo_id,total,payment_total,status,payment_provider,qris_payload,access_password_hash,access_password_admin_enc,payment_expires_at,telegram_delivery_status,created_at) VALUES(?,"website",?,?,?,?,?,?,?, ?,"pending",?,?,?,?,?,"not_applicable",NOW())')
            ->execute([$invoice,$name,$email,$wa,$subtotal,$discount,$promoId,$total,$total,$gatewayMode,($gatewayMode==='qris_static')?setting('qris_payload',''):'',$accessHash,encrypt_admin_order_password($accessPassword),$expiresAt]);
        $oid=(int)$pdo->lastInsertId();
        foreach($items as $it){
            $variant=$it['variant'];
            $variantName=(string)$variant['name'];
            $displayName=$it['p']['name'].' — '.$variantName;
            if($it['delivery_type']==='manual') assert_manual_stock_reservable_variant((int)$it['p']['id'],(int)$variant['id'],(int)$it['qty']);
            $warrantyLabel=product_variant_warranty_label($variant,$it['p']);
            $warrantyDays=product_variant_warranty_days($variant,$it['p']);
            $pdo->prepare('INSERT INTO order_items(order_id,product_id,variant_id,variant_name,product_name,warranty_label,warranty_days,qty,price,total) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$oid,$it['p']['id'],(int)$variant['id'],$variantName,$displayName,$warrantyLabel,$warrantyDays,$it['qty'],$it['price'],$it['total']]);
            $itemId=(int)$pdo->lastInsertId();
            if($it['delivery_type']!=='manual') reserve_order_item_stocks($oid,$itemId,(int)$it['p']['id'],(int)$it['qty'],$expiresAt,(int)$variant['id']);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    $localPaymentUrl='/checkout.php?invoice='.rawurlencode($invoice);
    $orderStatus='pending';
    if($gatewayMode==='demo'){
        // Auto-complete demo payment via the same paid/fulfill path as admin mark-paid.
        $pdo->prepare("UPDATE orders SET payment_reference=?, payment_total=?, payment_expires_at=? WHERE id=?")
            ->execute(['demo_'.$invoice,$total,$expiresAt,$oid]);
        $paidOrder=mark_order_paid($invoice,'demo_auto');
        $orderStatus='paid';
        store_order_access_session($invoice,$accessPassword);
        json_out([
            'ok'=>true,
            'invoice'=>$invoice,
            'total'=>$total,
            'payment_url'=>$localPaymentUrl,
            'payment_method'=>'demo',
            'status'=>$orderStatus,
        ]);
    }elseif($gatewayMode==='tokopay'){
        try{
            $orderForGateway=['invoice'=>$invoice,'buyer_name'=>$name,'buyer_email'=>$email,'buyer_whatsapp'=>$wa,'total'=>$total];
            $itemForGateway=array_map(fn($it)=>['product_id'=>$it['p']['id'],'product_name'=>$it['p']['name'].' — '.(string)$it['variant']['name'],'price'=>$it['price'],'qty'=>$it['qty']],$items);
            $gateway=create_tokopay_order($orderForGateway,$itemForGateway);
            $gd=is_array($gateway['data']??null)?$gateway['data']:[];
            $payUrl=(string)($gd['pay_url']??$gd['checkout_url']??$gd['payment_url']??$gd['url']??'');
            $qris=(string)($gd['qr_link']??$gd['qris_url']??$gd['qris_link']??$gd['qris_image']??$gd['qr_url']??$gd['qr']??$gd['qr_string']??setting('qris_payload',''));
            $ref=(string)($gd['trx_id']??$gd['reference']??$gd['ref_id']??($gateway['reference']??''));
            $fee=(float)($gd['fee']??$gd['biaya']??$gd['total_fee']??0);
            $paymentTotal=(float)($gd['total_dibayar']??$gd['total_bayar']??$gd['total_amount']??($total+$fee));
            if($paymentTotal<$total)$paymentTotal=$total;
            assert_payment_reference_available('tokopay',$ref,$oid);
            $pdo->prepare('UPDATE orders SET payment_url=?,qris_payload=?,payment_reference=?,payment_fee=?,payment_total=? WHERE id=?')->execute([$payUrl,$qris,$ref,$fee,$paymentTotal,$oid]);
        }catch(Throwable $gatewayError){
            $pdo->beginTransaction();
            try{$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'")->execute([$oid]);release_order_stock_reservations($oid);$pdo->commit();}catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();}
            throw $gatewayError;
        }
    }elseif($gatewayMode==='nowpayments'){
        try{
            $npSiteUrl=rtrim(seo_site_url(),'/');
            $callbackUrl=$npSiteUrl.'/api/nowpayments_webhook.php';
            $gateway=nowpayments_create_invoice([
                'amount'=>$total,
                'currency'=>'idr',
                'pay_currency'=>nowpayments_default_currency(),
                'callback_url'=>$callbackUrl,
                'order_id'=>$invoice,
                'description'=>'Digital Store Order '.$invoice,
                'success_url'=>$npSiteUrl.'/checkout.php?invoice='.rawurlencode($invoice),
                'cancel_url'=>$npSiteUrl.'/checkout.php',
            ]);
            if(!$gateway['ok']) throw new RuntimeException($gateway['error'] ?? 'NowPayments gagal membuat invoice.');
            $payUrl=(string)($gateway['invoice_url']??'');
            $ref=(string)($gateway['invoice_id']??'');
            assert_payment_reference_available('nowpayments',$ref,$oid);
            $pdo->prepare('UPDATE orders SET payment_url=?,payment_reference=?,payment_total=? WHERE id=?')->execute([$payUrl,$ref,$total,$oid]);
        }catch(Throwable $gatewayError){
            $pdo->beginTransaction();
            try{$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'")->execute([$oid]);release_order_stock_reservations($oid);$pdo->commit();}catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();}
            throw $gatewayError;
        }
    }elseif($gatewayMode==='casaku'){
        $lockKey='';
        try{
            if(!casaku_use_unique_code())$lockKey=casaku_acquire_exact_amount_lock((int)round($total));
            $gateway=create_casaku_order(['invoice'=>$invoice,'total'=>$total]);
            $paymentTotal=(float)$gateway['payment_total'];
            $fee=(float)$gateway['payment_fee'];
            $ref=(string)$gateway['transaction_id'];
            $qris=(string)$gateway['qris'];
            $gatewayExpiresAt=(string)($gateway['expires_at']??'');
            if($gatewayExpiresAt!=='')$expiresAt=$gatewayExpiresAt;
            assert_payment_reference_available('casaku',$ref,$oid);
            $pdo->prepare('UPDATE orders SET payment_url=NULL,qris_payload=?,payment_reference=?,payment_fee=?,payment_total=?,payment_expires_at=? WHERE id=?')->execute([$qris,$ref,$fee,$paymentTotal,$expiresAt,$oid]);
        }catch(Throwable $gatewayError){
            $pdo->beginTransaction();
            try{$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'")->execute([$oid]);release_order_stock_reservations($oid);$pdo->commit();}catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();}
            throw $gatewayError;
        }finally{
            if($lockKey!=='')casaku_release_exact_amount_lock($lockKey);
        }
    }
    store_order_access_session($invoice,$accessPassword);
    json_out([
        'ok'=>true,
        'invoice'=>$invoice,
        'total'=>$total,
        'payment_url'=>$localPaymentUrl,
        'payment_method'=>$gatewayMode,
        'status'=>$orderStatus,
    ]);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();json_out(['ok'=>false,'message'=>$e->getMessage()],500);}
