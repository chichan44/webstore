<?php
if (!function_exists('casaku_api_base')) {
    function casaku_api_base(): string {
        return 'https://api.casaku.id';
    }

    function casaku_expired_minutes(): int {
        $v = (int)setting('casaku_expired_minutes', '15');
        if ($v < 1) $v = 15;
        if ($v > 1440) $v = 1440;
        return $v;
    }

    function casaku_package_ids(): array {
        $raw = trim((string)setting('casaku_package_ids', ''));
        if ($raw === '') return [];
        $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []))));

        // DANA Bisnis dapat terbaca sebagai id.dana atau id.dana.kasir.
        if (in_array('id.dana', $items, true) || in_array('id.dana.kasir', $items, true)) {
            if (!in_array('id.dana', $items, true)) $items[] = 'id.dana';
            if (!in_array('id.dana.kasir', $items, true)) $items[] = 'id.dana.kasir';
        }
        return array_values(array_unique($items));
    }

    function casaku_use_unique_code(): bool {
        return trim((string)setting('casaku_use_unique_code', '1')) !== '0';
    }

    function casaku_prefix(): string {
        $prefix = strtoupper(trim((string)setting('casaku_prefix', 'CSK')));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?? '';
        return substr($prefix !== '' ? $prefix : 'CSK', 0, 8);
    }

    function casaku_request_signature_header(): string {
        foreach (['HTTP_X_CASAKU_SIGNATURE', 'REDIRECT_HTTP_X_CASAKU_SIGNATURE'] as $key) {
            if (!empty($_SERVER[$key])) return trim((string)$_SERVER[$key]);
        }
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                if (strcasecmp((string)$name, 'X-Casaku-Signature') === 0) return trim((string)$value);
            }
        }
        return '';
    }

    function casaku_http_request(string $method, string $path, array $payload = [], int $timeout = 15): array {
        $license = trim((string)setting('casaku_license_key', ''));
        if ($license === '') throw new RuntimeException('License Key Casaku belum diatur.');
        if (!str_starts_with($path, '/')) $path = '/'.$path;
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET','POST'], true)) throw new RuntimeException('Metode request Casaku tidak didukung.');

        $url = casaku_api_base().$path;
        $body = '';
        if ($method === 'GET') {
            if ($payload) $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) throw new RuntimeException('Payload Casaku tidak dapat dibuat.');
        }

        $headers = ['Accept: application/json', 'x-license-key: '.$license];
        if ($method === 'POST') $headers[] = 'Content-Type: application/json';
        $code = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => min(5, max(2, $timeout - 1)),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $body;
            } else {
                $opts[CURLOPT_HTTPGET] = true;
            }
            curl_setopt_array($ch, $opts);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res === false) throw new RuntimeException('Koneksi Casaku gagal: '.$err);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers)."\r\n",
                'content' => $method === 'POST' ? $body : '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]]);
            $res = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
            if ($res === false) throw new RuntimeException('Koneksi Casaku gagal. Aktifkan ekstensi cURL pada hosting.');
        }

        $json = json_decode((string)$res, true);
        if (!is_array($json)) throw new RuntimeException('Response Casaku tidak valid (HTTP '.$code.').');
        $json['_http_code'] = $code;
        if ($code < 200 || $code >= 300) {
            $message = trim((string)($json['message'] ?? $json['error'] ?? ''));
            throw new RuntimeException('Casaku menolak request (HTTP '.$code.').'.($message !== '' ? ' '.$message : ''));
        }
        return $json;
    }

    function casaku_http_post(string $path, array $payload, int $timeout = 15): array {
        return casaku_http_request('POST', $path, $payload, $timeout);
    }

    function casaku_http_get(string $path, array $query = [], int $timeout = 10): array {
        return casaku_http_request('GET', $path, $query, $timeout);
    }

    // Parser respons dibuat toleran terhadap wrapper data/result/transaction.
    function casaku_response_objects(array $response): array {
        $objects = [];
        $seen = [];
        $queue = [[$response, 0]];
        $wrapperKeys = ['data','result','transaction','payment','detail','details','item','trx','qris'];
        while ($queue) {
            [$obj, $depth] = array_shift($queue);
            if (!is_array($obj)) continue;
            $fingerprint = md5(serialize($obj));
            if (isset($seen[$fingerprint])) continue;
            $seen[$fingerprint] = true;
            $objects[] = $obj;
            if ($depth >= 4) continue;
            foreach ($wrapperKeys as $key) {
                if (isset($obj[$key]) && is_array($obj[$key])) $queue[] = [$obj[$key], $depth + 1];
            }
        }
        return $objects;
    }

    function casaku_response_transaction_id(array $response): string {
        $objects = casaku_response_objects($response);
        foreach (['transactionId','transaction_id','trxId','trx_id','reference','referenceId','reference_id','id'] as $key) {
            foreach ($objects as $obj) {
                if (isset($obj[$key]) && !is_array($obj[$key]) && trim((string)$obj[$key]) !== '') return trim((string)$obj[$key]);
            }
        }
        return '';
    }

    function casaku_response_status(array $response): string {
        $objects = casaku_response_objects($response);
        foreach (['paymentStatus','payment_status','transactionStatus','transaction_status','status'] as $key) {
            foreach ($objects as $obj) {
                if (!isset($obj[$key]) || is_array($obj[$key])) continue;
                $value = strtolower(trim((string)$obj[$key]));
                if (in_array($value, ['pending','paid','cancel','cancelled','canceled','expired'], true)) {
                    if (in_array($value, ['cancelled','canceled'], true)) return 'cancel';
                    return $value;
                }
            }
        }
        return '';
    }

    function casaku_response_amount(array $response): ?float {
        $objects = casaku_response_objects($response);
        $finalFields = [
            'totalAmount','total_amount','finalAmount','final_amount','paymentAmount','payment_amount',
            'payAmount','pay_amount','amountToPay','amount_to_pay','totalPayment','total_payment',
            'grossAmount','gross_amount','totalBayar','total_bayar','totalDibayar','total_dibayar'
        ];
        foreach ($finalFields as $key) {
            foreach ($objects as $obj) {
                if (isset($obj[$key]) && $obj[$key] !== '' && is_numeric($obj[$key])) return (float)$obj[$key];
            }
        }
        foreach (['amount','nominal','total'] as $key) {
            foreach ($objects as $obj) {
                if (isset($obj[$key]) && $obj[$key] !== '' && is_numeric($obj[$key])) return (float)$obj[$key];
            }
        }
        return null;
    }

    function casaku_response_qris(array $response): string {
        $objects = casaku_response_objects($response);
        foreach (['qr_string','qrString','qrisString','qris_string','qris','qr'] as $key) {
            foreach ($objects as $obj) {
                if (isset($obj[$key]) && is_string($obj[$key]) && trim($obj[$key]) !== '') return trim($obj[$key]);
            }
        }
        return '';
    }

    function casaku_response_expired_at(array $response): ?string {
        $objects = casaku_response_objects($response);
        foreach (['expiredAt','expiresAt','expired_at','expires_at'] as $key) {
            foreach ($objects as $obj) {
                if (empty($obj[$key]) || is_array($obj[$key])) continue;
                try {
                    $dt = new DateTime((string)$obj[$key]);
                    $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                    return $dt->format('Y-m-d H:i:s');
                } catch (Throwable $e) {}
            }
        }
        return null;
    }

    // Tag 54 QRIS/EMV berisi nominal yang dibayar.
    function casaku_qris_amount(string $qris): ?float {
        $qris = trim($qris);
        $len = strlen($qris);
        if ($len < 8) return null;
        $offset = 0;
        while ($offset + 4 <= $len) {
            $tag = substr($qris, $offset, 2);
            $lengthText = substr($qris, $offset + 2, 2);
            if (!ctype_digit($tag) || !ctype_digit($lengthText)) return null;
            $valueLen = (int)$lengthText;
            $valueStart = $offset + 4;
            if ($valueStart + $valueLen > $len) return null;
            $value = substr($qris, $valueStart, $valueLen);
            if ($tag === '54' && is_numeric($value)) return (float)$value;
            $offset = $valueStart + $valueLen;
        }
        return null;
    }

    function casaku_generated_transaction_details(array $response, int $baseAmount): array {
        $transactionId = casaku_response_transaction_id($response);
        $qris = casaku_response_qris($response);
        $responseAmount = casaku_response_amount($response);
        $qrisAmount = $qris !== '' ? casaku_qris_amount($qris) : null;
        if ($transactionId === '') throw new RuntimeException('Casaku tidak mengembalikan transactionId.');
        if ($qris === '') throw new RuntimeException('Casaku tidak mengembalikan QRIS string.');

        $paymentTotal = null;
        if ($qrisAmount !== null && $qrisAmount >= $baseAmount) $paymentTotal = $qrisAmount;
        elseif ($responseAmount !== null && $responseAmount >= $baseAmount) $paymentTotal = $responseAmount;
        if ($paymentTotal === null) throw new RuntimeException('Nominal pembayaran Casaku tidak valid.');

        return [
            'raw'=>$response,
            'transaction_id'=>$transactionId,
            'qris'=>$qris,
            'payment_total'=>(float)$paymentTotal,
            'expires_at'=>casaku_response_expired_at($response),
        ];
    }

    function casaku_cancel_transaction(string $transactionId): bool {
        $transactionId = trim($transactionId);
        if ($transactionId === '') return false;
        try {
            $res = casaku_http_post('/api/generate/cancel-status', ['transactionId'=>$transactionId], 6);
            $status = casaku_response_status($res);
            return $status === 'cancel' || (int)($res['status'] ?? 0) === 200;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Kode unik dibuat lokal agar nominal final selalu sama dengan yang tersimpan.
    function casaku_unique_payment_total_available(int $paymentTotal, string $invoice = ''): bool {
        global $pdo;
        if ($paymentTotal <= 0) return false;
        $sql = "SELECT 1 FROM orders WHERE payment_provider='casaku' AND status='pending' AND payment_reference IS NOT NULL AND payment_reference<>'' AND ROUND(payment_total)=? AND (payment_expires_at IS NULL OR payment_expires_at>NOW())";
        $params = [$paymentTotal];
        if ($invoice !== '') { $sql .= ' AND invoice<>?'; $params[] = $invoice; }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return !$st->fetchColumn();
    }

    function casaku_local_unique_code(int $baseAmount, string $invoice = ''): int {
        global $pdo;
        if ($baseAmount <= 0) throw new RuntimeException('Nominal dasar kode unik Casaku tidak valid.');

        $usedTotals = [];
        try {
            $sql = "SELECT payment_total FROM orders WHERE payment_provider='casaku' AND status='pending' AND payment_reference IS NOT NULL AND payment_reference<>'' AND (payment_expires_at IS NULL OR payment_expires_at>NOW())";
            $params = [];
            if ($invoice !== '') {
                $sql .= ' AND invoice<>?';
                $params[] = $invoice;
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $row) {
                $v = (int)round((float)($row['payment_total'] ?? 0));
                if ($v > 0) $usedTotals[$v] = true;
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Tidak dapat memeriksa kode unik Casaku yang aktif.');
        }

        for ($i = 0; $i < 80; $i++) {
            $code = random_int(1, 999);
            if (!isset($usedTotals[$baseAmount + $code])) return $code;
        }
        $start = random_int(1, 999);
        for ($i = 0; $i < 999; $i++) {
            $code = (($start - 1 + $i) % 999) + 1;
            if (!isset($usedTotals[$baseAmount + $code])) return $code;
        }
        throw new RuntimeException('Semua kode unik Casaku sedang terpakai. Coba kembali setelah beberapa transaksi kedaluwarsa.');
    }

    function casaku_acquire_exact_amount_lock(int $amount): string {
        global $pdo;
        if ($amount <= 0) throw new RuntimeException('Nominal transaksi Casaku tidak valid.');
        $key = 'casaku_exact_'.$amount;
        $st = $pdo->prepare('SELECT GET_LOCK(?,8)');
        $st->execute([$key]);
        if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Pembayaran dengan nominal yang sama sedang diproses. Coba lagi beberapa detik.');
        return $key;
    }

    function casaku_release_exact_amount_lock(string $key): void {
        global $pdo;
        if ($key === '') return;
        try {
            $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute([$key]);
        } catch (Throwable $e) {}
    }

    function casaku_remote_pending_by_amount(int $amount): array {
        $matches = [];
        for ($page = 1; $page <= 5; $page++) {
            $response = casaku_http_get('/api/generate/list', [
                'status'=>'pending',
                'search'=>(string)$amount,
                'page'=>$page,
                'limit'=>50,
                'sort'=>'newest',
            ], 8);
            $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $value = casaku_response_amount($item);
                if ($value !== null && (int)round($value) === $amount) $matches[] = $item;
            }
            $pages = max(1, (int)($data['pages'] ?? 1));
            if ($page >= $pages || count($items) < 50) break;
        }
        return $matches;
    }

    function casaku_prepare_exact_amount(int $amount, string $invoice = ''): void {
        global $pdo;
        $sql = "SELECT id,invoice,payment_reference,payment_expires_at FROM orders WHERE payment_provider='casaku' AND status='pending' AND payment_reference IS NOT NULL AND payment_reference<>'' AND ROUND(payment_total)=?";
        $params = [$amount];
        if ($invoice !== '') {
            $sql .= ' AND invoice<>?';
            $params[] = $invoice;
        }
        $sql .= ' ORDER BY id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $local) {
            $expired = !empty($local['payment_expires_at']) && strtotime((string)$local['payment_expires_at']) + 180 <= time();
            if ($expired) {
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");
                    $up->execute([(int)$local['id']]);
                    if ($up->rowCount()) release_order_stock_reservations((int)$local['id']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
                casaku_cancel_transaction((string)$local['payment_reference']);
                continue;
            }

            try {
                $snapshot = casaku_remote_snapshot((string)$local['payment_reference'], (float)$amount);
                $status = (string)($snapshot['status'] ?? '');
                if (in_array($status, ['cancel','expired'], true)) {
                    $pdo->beginTransaction();
                    try {
                        $up = $pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");
                        $up->execute([(int)$local['id']]);
                        if ($up->rowCount()) release_order_stock_reservations((int)$local['id']);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                    }
                    continue;
                }
                if ($status === 'paid') {
                    throw new RuntimeException('Ada pembayaran '.rupiah($amount).' yang sudah terdeteksi pada transaksi sebelumnya. Cek status transaksi tersebut sebelum membuat transaksi baru.');
                }
            } catch (Throwable $e) {
                if (str_starts_with($e->getMessage(), 'Ada pembayaran ')) throw $e;
            }

            throw new RuntimeException('Nominal '.rupiah($amount).' sedang dipakai transaksi lain. Tunggu transaksi tersebut selesai/kedaluwarsa atau aktifkan Kode Unik.');
        }

        foreach (casaku_remote_pending_by_amount($amount) as $item) {
            $transactionId = casaku_response_transaction_id($item);
            if ($transactionId === '') continue;
            $st = $pdo->prepare("SELECT id,invoice,status,payment_expires_at FROM orders WHERE payment_provider='casaku' AND payment_reference=? LIMIT 1");
            $st->execute([$transactionId]);
            $local = $st->fetch();
            if (!$local) {
                throw new RuntimeException('Ada transaksi Casaku lain dengan nominal '.rupiah($amount).' yang masih pending. Tunggu hingga selesai/kedaluwarsa atau aktifkan Kode Unik.');
            }

            $active = (string)$local['status'] === 'pending' && (empty($local['payment_expires_at']) || strtotime((string)$local['payment_expires_at']) + 180 > time());
            if ($active && ($invoice === '' || !hash_equals($invoice, (string)$local['invoice']))) {
                throw new RuntimeException('Nominal '.rupiah($amount).' sedang dipakai transaksi lain. Tunggu transaksi tersebut selesai/kedaluwarsa atau aktifkan Kode Unik.');
            }
            if ($active) continue;

            if ((string)$local['status'] === 'pending') {
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");
                    $up->execute([(int)$local['id']]);
                    if ($up->rowCount()) release_order_stock_reservations((int)$local['id']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
            }
            if (!casaku_cancel_transaction($transactionId)) {
                throw new RuntimeException('Transaksi Casaku lama dengan nominal '.rupiah($amount).' belum dapat dibersihkan. Coba lagi beberapa saat.');
            }
        }
    }

    function create_casaku_order(array $order): array {
        $qrId = trim((string)setting('casaku_qr_id', ''));
        $packages = casaku_package_ids();
        if ($qrId === '') throw new RuntimeException('QR ID Casaku belum diatur.');
        if (!$packages) throw new RuntimeException('Package ID Casaku belum diatur.');

        $baseAmount = (int)round((float)($order['total'] ?? 0));
        if ($baseAmount <= 0) throw new RuntimeException('Nominal transaksi Casaku tidak valid.');
        $invoice = trim((string)($order['invoice'] ?? ''));
        $uniqueRequested = casaku_use_unique_code();
        $preferredUnique = isset($order['preferred_unique_code']) ? (int)$order['preferred_unique_code'] : 0;
        $uniqueCode = 0;
        if ($uniqueRequested) {
            if ($preferredUnique >= 1 && $preferredUnique <= 999 && casaku_unique_payment_total_available($baseAmount + $preferredUnique, $invoice)) {
                $uniqueCode = $preferredUnique;
            } else {
                $uniqueCode = casaku_local_unique_code($baseAmount, $invoice);
            }
        }
        $requestedAmount = $baseAmount + $uniqueCode;
        if (!$uniqueRequested) casaku_prepare_exact_amount($requestedAmount, $invoice);

        $payload = [
            'qr_id' => $qrId,
            'amount' => $requestedAmount,
            'useUniqueCode' => false,
            'packageIds' => $packages,
            'expiredInMinutes' => casaku_expired_minutes(),
            'qrType' => 'dynamic',
            'paymentMethod' => 'qris',
            'useQris' => true,
            'prefix' => casaku_prefix(),
        ];

        $response = casaku_http_post('/api/generate/v2/qris', $payload, 20);
        $details = casaku_generated_transaction_details($response, $requestedAmount);
        $paymentTotal = (int)round((float)$details['payment_total']);

        if ($paymentTotal !== $requestedAmount) {
            casaku_cancel_transaction((string)$details['transaction_id']);
            throw new RuntimeException('Nominal QRIS Casaku berubah dari nominal yang diminta. Transaksi dibatalkan agar pembayaran tidak salah nominal.');
        }

        return [
            'raw' => $details['raw'],
            'transaction_id' => $details['transaction_id'],
            'qris' => $details['qris'],
            'payment_total' => (float)$paymentTotal,
            'payment_fee' => (float)$uniqueCode,
            'unique_code' => $uniqueCode,
            'expires_at' => $details['expires_at'],
        ];
    }

    function casaku_check_status(string $transactionId): array {
        $transactionId = trim($transactionId);
        if ($transactionId === '') throw new RuntimeException('Transaction ID Casaku kosong.');
        return casaku_http_post('/api/generate/check-status', ['transactionId' => $transactionId], 6);
    }

    function casaku_list_find_transaction(string $transactionId, ?string $statusFilter = null): ?array {
        $query = ['search'=>$transactionId, 'page'=>1, 'limit'=>10, 'sort'=>'newest'];
        if ($statusFilter !== null && $statusFilter !== '') $query['status'] = $statusFilter;
        $response = casaku_http_get('/api/generate/list', $query, 6);
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id = casaku_response_transaction_id($item);
            if ($id !== '' && hash_equals($transactionId, $id)) return $item;
        }
        return null;
    }

    function casaku_remote_snapshot(string $transactionId, ?float $expectedAmount = null, bool $deepFallback = false): array {
        $transactionId = trim($transactionId);
        if ($transactionId === '') throw new RuntimeException('Transaction ID Casaku kosong.');

        $check = [];
        $checkError = '';
        $status = '';
        $remoteId = '';
        $amount = null;
        $listItem = null;
        $listErrors = [];

        try {
            $check = casaku_check_status($transactionId);
            $status = casaku_response_status($check);
            $remoteId = casaku_response_transaction_id($check);
            $amount = casaku_response_amount($check);
        } catch (Throwable $e) {
            $checkError = $e->getMessage();
        }

        $idMismatch = $remoteId !== '' && !hash_equals($transactionId, $remoteId);
        $amountMismatch = $expectedAmount !== null && $amount !== null && abs((float)$amount - $expectedAmount) > 0.0001;
        $needsFallback = $checkError !== '' || $status === '' || ($deepFallback && $status === 'pending') || $idMismatch || $amountMismatch || ($status === 'paid' && $amount === null);

        if ($needsFallback) {
            try {
                $listItem = casaku_list_find_transaction($transactionId, 'paid');
            } catch (Throwable $e) {
                $listErrors[] = $e->getMessage();
            }
            if (!$listItem) {
                try {
                    $listItem = casaku_list_find_transaction($transactionId, null);
                } catch (Throwable $e) {
                    $listErrors[] = $e->getMessage();
                }
            }
            if ($listItem) {
                $listStatus = casaku_response_status($listItem);
                $listId = casaku_response_transaction_id($listItem);
                $listAmount = casaku_response_amount($listItem);
                if ($listStatus !== '') $status = $listStatus;
                if ($listId !== '') $remoteId = $listId;
                if ($listAmount !== null) $amount = $listAmount;
            }
        }

        if ($status === '' && !$listItem && $checkError !== '') {
            $extra = $listErrors ? ' Fallback list: '.implode(' | ', array_unique($listErrors)) : '';
            throw new RuntimeException('Cek status Casaku gagal: '.$checkError.$extra);
        }

        return [
            'status'=>$status,
            'transaction_id'=>$remoteId,
            'amount'=>$amount,
            'check'=>$check,
            'check_error'=>$checkError,
            'list_item'=>$listItem,
            'list_errors'=>$listErrors,
        ];
    }

    function verify_casaku_webhook_raw(string $rawBody, string $signature): array {
        $secret = trim((string)setting('casaku_webhook_secret', ''));
        if ($secret === '') throw new RuntimeException('Webhook Secret Casaku belum diatur.');
        $signature = strtolower(trim($signature));
        if (str_starts_with($signature, 'sha256=')) $signature = substr($signature, 7);
        if ($signature === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) throw new RuntimeException('Signature Casaku tidak valid.');
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signature)) throw new RuntimeException('Signature Casaku tidak cocok.');
        $data = json_decode($rawBody, true);
        if (!is_array($data)) throw new RuntimeException('Payload webhook Casaku bukan JSON yang valid.');
        return $data;
    }

    function casaku_callback_ip_allowed(): bool {
        if (trim((string)setting('casaku_callback_ip_whitelist', '0')) !== '1') return true;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $list = preg_split('/[,\s]+/', trim((string)setting('casaku_callback_ips', ''))) ?: [];
        $allowed = array_values(array_filter(array_map('trim', $list)));
        return $ip !== '' && in_array($ip, $allowed, true);
    }
}
