<?php
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/casaku.php';
require_once __DIR__.'/../includes/nowpayments.php';
require_once __DIR__.'/../includes/telegram_bot.php';
ensure_order_security_columns();
ensure_product_extra_columns();
telegram_ensure_schema();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!defined('DEFER_EXTERNAL_DELIVERY')) define('DEFER_EXTERNAL_DELIVERY', true);

    $raw = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    $payload = tokopay_callback_payload($data);
    $auditInvoice = (string)$payload['invoice'];
    $auditRef = (string)$payload['reference'];
    $auditStatus = (string)$payload['status'];
    $auditAmount = $payload['amount'];
    $eventKey = '';

    try {
        try {
            $verified = verify_tokopay_webhook($data);
        } catch (Throwable $e) {
            log_payment_audit($auditInvoice,$auditRef,$auditStatus,$auditAmount,false,'Callback Tokopay ditolak: '.$e->getMessage(),$data);
            json_out(['status'=>false,'message'=>'Callback tidak valid'],403);
        }

        if ((string)setting('tokopay_callback_ip_whitelist','0') === '1' && !tokopay_callback_ip_allowed()) {
            log_payment_audit($auditInvoice,$auditRef,$auditStatus,$auditAmount,true,'Callback Tokopay ditolak: IP tidak ada di whitelist',$data);
            json_out(['status'=>false,'message'=>'IP callback tidak diizinkan'],403);
        }

        $invoice = (string)$verified['invoice'];
        $status = (string)$verified['status'];
        if (!tokopay_status_is_success($status)) {
            log_payment_audit($invoice,(string)$verified['reference'],$status,$verified['amount'],true,'Webhook Tokopay valid tetapi bukan status sukses',$data);
            json_out(['status'=>true,'ignored'=>true]);
        }

        $st = $pdo->prepare('SELECT * FROM orders WHERE invoice=? LIMIT 1');
        $st->execute([$invoice]);
        $order = $st->fetch();
        if (!$order) json_out(['status'=>false,'message'=>'Order tidak ditemukan'],404);
        if (strtolower(trim((string)($order['payment_provider'] ?? ''))) !== 'tokopay') {
            log_payment_audit($invoice,(string)$verified['reference'],$status,$verified['amount'],true,'Callback Tokopay ditolak: provider order bukan Tokopay',$data);
            json_out(['status'=>false,'message'=>'Provider pembayaran tidak cocok'],403);
        }

        $storedRef = trim((string)($order['payment_reference'] ?? ''));
        $callbackRef = trim((string)($verified['reference'] ?? ''));
        if ($callbackRef === '' || $callbackRef === 'tokopay_webhook') {
            log_payment_audit($invoice,$callbackRef,$status,$verified['amount'],true,'Callback Tokopay ditolak: reference kosong',$data);
            json_out(['status'=>false,'message'=>'Reference pembayaran tidak valid'],403);
        }
        if ($storedRef !== '' && !hash_equals($storedRef, $callbackRef)) {
            log_payment_audit($invoice,$callbackRef,$status,$verified['amount'],true,'Callback Tokopay ditolak: reference berbeda dengan transaksi checkout',$data);
            json_out(['status'=>false,'message'=>'Reference pembayaran tidak cocok'],403);
        }

        $paidAmount = $verified['amount'];
        if ($paidAmount === null || $paidAmount === '') $paidAmount = $verified['data']['total_dibayar'] ?? $verified['data']['jumlah_bayar'] ?? null;
        $requiredAmount = (float)($order['payment_total'] ?: $order['total']);
        if (!is_numeric($paidAmount) || abs((float)$paidAmount - $requiredAmount) > 0.0001) {
            log_payment_audit($invoice,$callbackRef,$status,is_numeric($paidAmount)?(float)$paidAmount:null,true,'Callback Tokopay ditolak: nominal tidak sama dengan payment_total',$data);
            json_out(['status'=>false,'message'=>'Nominal pembayaran tidak sesuai'],403);
        }

        try {
            $remote = tokopay_check_order_verified($invoice, $callbackRef, $requiredAmount);
        } catch (Throwable $e) {
            log_payment_audit($invoice,$callbackRef,$status,(float)$paidAmount,true,'Verifikasi server-to-server Tokopay gagal: '.$e->getMessage(),$data);
            json_out(['status'=>false,'message'=>'Status pembayaran belum dapat diverifikasi'],503);
        }
        if (empty($remote['success'])) {
            log_payment_audit($invoice,$callbackRef,$status,(float)$paidAmount,true,'Tokopay belum mengonfirmasi status Success/Completed',$remote['raw'] ?? []);
            json_out(['status'=>false,'message'=>'Pembayaran belum terkonfirmasi'],503);
        }

        $verified['amount'] = (float)$paidAmount;
        $claim = claim_payment_webhook_event($verified,$data,'tokopay');
        $eventKey = (string)$claim['event_key'];
        if (!$claim['claimed']) {
            $latest=$pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
            $latest->execute([(int)$order['id']]);
            payment_webhook_success(['status'=>true,'duplicate'=>true], $latest->fetch() ?: []);
        }

        try {
            $paidOrder = mark_order_paid($invoice,$callbackRef,true);
            if (!empty($paidOrder['_fulfillment_new']) && (string)($paidOrder['sales_channel'] ?? '') === 'telegram') {
                telegram_queue_order_delivery((int)$paidOrder['id']);
            }
            complete_payment_webhook_event($eventKey,'tokopay');
            log_payment_audit($invoice,$callbackRef,$status,(float)$paidAmount,true,'Webhook Tokopay tervalidasi signature, reference, nominal, dan check-order',$data);
            payment_webhook_success(['status'=>true,'duplicate'=>empty($paidOrder['_fulfillment_new'])], $paidOrder);
        } catch (Throwable $e) {
            fail_payment_webhook_event($eventKey,$e->getMessage(),'tokopay');
            log_payment_audit($invoice,$callbackRef,$status,(float)$paidAmount,true,'Pembayaran valid tetapi fulfillment perlu retry: '.$e->getMessage(),$data);
            json_out(['status'=>false,'message'=>'Pembayaran terverifikasi, proses pesanan akan dicoba ulang'],503);
        }
    } catch (Throwable $e) {
        if ($eventKey !== '') fail_payment_webhook_event($eventKey,$e->getMessage(),'tokopay');
        log_payment_audit($auditInvoice,$auditRef,$auditStatus,$auditAmount,false,$e->getMessage(),is_array($data)?$data:[]);
        json_out(['status'=>false,'message'=>'Callback tidak dapat diproses'],500);
    }
}

$invoice = trim((string)($_GET['invoice'] ?? ''));
if ($invoice === '' || strlen($invoice) > 120 || !preg_match('/^[A-Za-z0-9._-]+$/', $invoice)) json_out(['ok'=>false,'status'=>'invalid_invoice'],422);
$st = $pdo->prepare('SELECT id,invoice,sales_channel,status,payment_provider,payment_reference,payment_total,total,payment_expires_at FROM orders WHERE invoice=? LIMIT 1');
$st->execute([$invoice]);
$o = $st->fetch();
if (!$o) json_out(['ok'=>false,'status'=>'not_found'],404);

$provider = strtolower(trim((string)($o['payment_provider'] ?? '')));
$required = (float)($o['payment_total'] ?: $o['total']);

if (in_array((string)$o['status'],['pending','failed'],true) && $provider === 'casaku' && !empty($o['payment_reference']) && !rate_limit_hit('casaku-status',$invoice,12,60)) {
    try {
        $deepFallback = !rate_limit_hit('casaku-deep-status',$invoice,1,30);
        $remote = casaku_remote_snapshot((string)$o['payment_reference'],$required,$deepFallback);
        $remoteStatus = (string)($remote['status'] ?? '');
        $remoteAmount = $remote['amount'] ?? null;
        $remoteId = trim((string)($remote['transaction_id'] ?? ''));
        if ($remoteId !== '' && !hash_equals((string)$o['payment_reference'],$remoteId)) throw new RuntimeException('Transaction ID status Casaku tidak cocok.');

        if ($remoteStatus === 'paid') {
            if ($remoteAmount !== null && abs((float)$remoteAmount-$required)>0.0001) {
                log_payment_audit($invoice,(string)$o['payment_reference'],'paid',$remoteAmount,true,'Polling Casaku menemukan paid tetapi nominal tidak cocok',$remote,'casaku');
            } else {
                $verified=['invoice'=>$invoice,'reference'=>(string)$o['payment_reference'],'status'=>'paid','amount'=>$required,'signature_valid'=>true];
                $claim=claim_payment_webhook_event($verified,$remote,'casaku');
                if ($claim['claimed']) {
                    try {
                        $paidOrder=mark_order_paid($invoice,(string)$o['payment_reference'],true);
                        if (!empty($paidOrder['_fulfillment_new'])) telegram_after_order_paid($paidOrder);
                        complete_payment_webhook_event((string)$claim['event_key'],'casaku');
                    } catch (Throwable $e) {
                        fail_payment_webhook_event((string)$claim['event_key'],$e->getMessage(),'casaku');
                        log_payment_audit($invoice,(string)$o['payment_reference'],'paid',$required,true,'Pembayaran Casaku paid, fulfillment perlu retry: '.$e->getMessage(),$remote,'casaku');
                    }
                }
                $check=$pdo->prepare('SELECT status FROM orders WHERE id=?');
                $check->execute([$o['id']]);
                $o['status']=(string)($check->fetchColumn() ?: $o['status']);
            }
        } elseif (in_array($remoteStatus,['cancel','expired'],true) && $o['status']==='pending') {
            $expiryTs=!empty($o['payment_expires_at'])?strtotime((string)$o['payment_expires_at']):false;
            if ($expiryTs !== false && $expiryTs + 180 <= time()) {
                $pdo->beginTransaction();
                try {
                    $up=$pdo->prepare('UPDATE orders SET status="failed" WHERE id=? AND status="pending"');
                    $up->execute([$o['id']]);
                    if ($up->rowCount()) release_order_stock_reservations((int)$o['id']);
                    $pdo->commit();
                    if ($up->rowCount()) $o['status']='failed';
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
        }
    } catch (Throwable $e) {
        log_payment_audit($invoice,(string)$o['payment_reference'],'status_check',null,false,'Cek status Casaku gagal: '.$e->getMessage(),[],'casaku');
    }
}

if (in_array((string)$o['status'],['pending','failed'],true) && $provider === 'tokopay' && !empty($o['payment_reference']) && !rate_limit_hit('tokopay-status',$invoice,8,60)) {
    try {
        $remote=tokopay_check_order_verified($invoice,(string)$o['payment_reference'],$required);
        if (!empty($remote['success'])) {
            $verified=['invoice'=>$invoice,'reference'=>(string)$o['payment_reference'],'status'=>(string)$remote['status'],'amount'=>$required,'signature_valid'=>true];
            $claim=claim_payment_webhook_event($verified,$remote['raw'] ?? [],'tokopay');
            if ($claim['claimed']) {
                try {
                    $paidOrder=mark_order_paid($invoice,(string)$o['payment_reference'],true);
                    if (!empty($paidOrder['_fulfillment_new'])) telegram_after_order_paid($paidOrder);
                    complete_payment_webhook_event((string)$claim['event_key'],'tokopay');
                } catch (Throwable $e) {
                    fail_payment_webhook_event((string)$claim['event_key'],$e->getMessage(),'tokopay');
                    log_payment_audit($invoice,(string)$o['payment_reference'],'paid',$required,true,'Tokopay terverifikasi melalui check-order, fulfillment perlu retry: '.$e->getMessage(),$remote['raw'] ?? []);
                }
            }
            $check=$pdo->prepare('SELECT status FROM orders WHERE id=?');
            $check->execute([$o['id']]);
            $o['status']=(string)($check->fetchColumn() ?: $o['status']);
        }
    } catch (Throwable $e) {
        log_payment_audit($invoice,(string)$o['payment_reference'],'status_check',null,false,'Cek status Tokopay gagal: '.$e->getMessage(),[],'tokopay');
    }
}

if (in_array((string)$o['status'],['pending','failed'],true) && $provider === 'nowpayments' && !empty($o['payment_reference']) && !rate_limit_hit('nowpayments-status',$invoice,8,60)) {
    try {
        $remote = nowpayments_check_status((string)$o['payment_reference']);
        if ($remote['ok']) {
            $npStatus = (string)($remote['payment_status'] ?? '');
            $mapped = nowpayments_map_status($npStatus);
            if ($mapped === 'paid') {
                $verified = ['invoice'=>$invoice,'reference'=>(string)$o['payment_reference'],'status'=>'paid','amount'=>$required,'signature_valid'=>true];
                $claim = claim_payment_webhook_event($verified, $remote, 'nowpayments');
                if ($claim['claimed']) {
                    try {
                        $paidOrder = mark_order_paid($invoice, (string)$o['payment_reference'], true);
                        if (!empty($paidOrder['_fulfillment_new'])) telegram_after_order_paid($paidOrder);
                        complete_payment_webhook_event((string)$claim['event_key'], 'nowpayments');
                    } catch (Throwable $e) {
                        fail_payment_webhook_event((string)$claim['event_key'], $e->getMessage(), 'nowpayments');
                        log_payment_audit($invoice, (string)$o['payment_reference'], 'paid', $required, true, 'NowPayments paid, fulfillment perlu retry: '.$e->getMessage(), $remote, 'nowpayments');
                    }
                }
                $check = $pdo->prepare('SELECT status FROM orders WHERE id=?');
                $check->execute([$o['id']]);
                $o['status'] = (string)($check->fetchColumn() ?: $o['status']);
            } elseif (in_array($mapped, ['failed'], true) && $o['status'] === 'pending') {
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare('UPDATE orders SET status="failed" WHERE id=? AND status="pending"');
                    $up->execute([$o['id']]);
                    if ($up->rowCount()) release_order_stock_reservations((int)$o['id']);
                    $pdo->commit();
                    if ($up->rowCount()) $o['status'] = 'failed';
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
            }
        }
    } catch (Throwable $e) {
        log_payment_audit($invoice, (string)$o['payment_reference'], 'status_check', null, false, 'Cek status NowPayments gagal: '.$e->getMessage(), [], 'nowpayments');
    }
}

if ($o['status']==='paid' && !rate_limit_hit('paid-fulfillment-retry',$invoice,8,60)) {
    try {
        $paidOrder=mark_order_paid($invoice,(string)($o['payment_reference'] ?? 'payment_confirmed'),in_array($provider,['casaku','tokopay','nowpayments'],true));
        if (!empty($paidOrder['_fulfillment_new'])) telegram_after_order_paid($paidOrder);
        $o['status']=(string)($paidOrder['status'] ?? $o['status']);
    } catch (Throwable $e) {
        log_payment_audit($invoice,(string)($o['payment_reference'] ?? ''),'paid',$required,true,'Retry fulfillment belum berhasil: '.$e->getMessage(),[],$provider ?: 'unknown');
    }
}

$localExpiryTs=!empty($o['payment_expires_at'])?strtotime((string)$o['payment_expires_at']):false;
if ($provider==='casaku' && $localExpiryTs!==false) $localExpiryTs+=180;
if ($o['status']==='pending' && $localExpiryTs!==false && $localExpiryTs<time()) {
    $pdo->beginTransaction();
    try {
        $up=$pdo->prepare('UPDATE orders SET status="failed" WHERE id=? AND status="pending"');
        $up->execute([$o['id']]);
        if ($up->rowCount()) release_order_stock_reservations((int)$o['id']);
        $pdo->commit();
        if ($up->rowCount()) $o['status']='failed';
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
}

$txt=['pending'=>'Menunggu pembayaran','paid'=>'Pembayaran diterima','delivered'=>'Produk siap dibuka','cancelled'=>'Dibatalkan','failed'=>'Pembayaran expired / gagal'][$o['status']] ?? $o['status'];
json_out(['ok'=>true,'status'=>$o['status'],'status_text'=>$txt]);
