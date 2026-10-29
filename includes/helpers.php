<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lang.php';
if (!function_exists('security_headers')) {
    function security_headers(): void {
        if (headers_sent()) return;
        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()");
        $csp = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; img-src 'self' data: https:; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; connect-src 'self'; frame-src 'self'";
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $csp .= '; upgrade-insecure-requests';
        header('Content-Security-Policy: '.$csp);
    }
}
security_headers();

/**
 * Start storefront session before any HTML output.
 * csrf_token()/flash()/order-access helpers must not call session_start() after headers.
 */
function ensure_storefront_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    @session_start();
}
ensure_storefront_session();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rupiah($n){ return 'Rp ' . number_format((float)$n,0,',','.'); }

function telegram_product_button_template(?string $template=null): string {
    $template=trim($template ?? (string)setting('telegram_product_button_url',''));
    if($template==='') return '';

    // Otomatis naikkan format lama ?start=products agar langsung membuka produk aktif.
    if(
        !str_contains($template,'{product_id}')
        && preg_match('~^(https://(?:www\.)?(?:t\.me|telegram\.me)/[A-Za-z0-9_]+)\?start=products$~i',$template,$m)
    ){
        return $m[1].'?start=product_{product_id}';
    }

    return $template;
}

function parse_local_number($value): float {
    $s = trim((string)$value);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^0-9,\.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === ',' || $s === '.') return 0.0;
    $hasComma = str_contains($s, ',');
    $hasDot = str_contains($s, '.');
    if ($hasComma && $hasDot) {
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma > $lastDot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($hasComma) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($hasDot && preg_match('/^\-?\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}
function plain_number($value): string {
    $n = parse_local_number($value);
    if (abs($n - round($n)) < 0.00001) return (string)(int)round($n);
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
function promo_datetime_ts($value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;
    $tz = new DateTimeZone('Asia/Jakarta');
    foreach (['Y-m-d H:i:s','Y-m-d H:i','Y-m-d\\TH:i:s','Y-m-d\\TH:i'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $value, $tz);
        if ($dt instanceof DateTimeImmutable) return $dt->getTimestamp();
    }
    $ts = strtotime($value);
    return $ts === false ? null : $ts;
}
function promo_validate_and_discount(array $promo, float $subtotal): array {
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->getTimestamp();
    $starts = promo_datetime_ts($promo['starts_at'] ?? '');
    $ends = promo_datetime_ts($promo['ends_at'] ?? '');
    if (empty($promo) || (int)($promo['status'] ?? 0) !== 1) return [false, 0.0, 'Kode promo tidak aktif.'];
    if ($starts !== null && $starts > $now) return [false, 0.0, 'Kode promo belum memasuki masa aktif.'];
    if ($ends !== null && $ends < $now) return [false, 0.0, 'Kode promo sudah berakhir.'];
    $limit = (int)($promo['usage_limit'] ?? 0);
    $used = (int)($promo['used_count'] ?? 0);
    if ($limit > 0 && $used >= $limit) return [false, 0.0, 'Batas pemakaian kode promo sudah habis.'];
    $min = parse_local_number($promo['min_purchase'] ?? 0);
    if ($subtotal < $min) return [false, 0.0, 'Minimal pembelian untuk kode promo '.strtoupper((string)($promo['code'] ?? '')).' adalah '.rupiah($min).'.'];
    $value = parse_local_number($promo['value'] ?? 0);
    $max = parse_local_number($promo['max_discount'] ?? 0);
    $type = strtolower((string)($promo['type'] ?? 'fixed'));
    $discount = $type === 'percent' ? floor($subtotal * $value / 100) : $value;
    if ($max > 0) $discount = min($discount, $max);
    $discount = max(0, min($discount, $subtotal));
    if ($discount <= 0) return [false, 0.0, 'Kode promo valid, tetapi nilai diskonnya 0. Periksa Value promo di admin.'];
    return [true, $discount, 'Kode promo '.strtoupper((string)($promo['code'] ?? '')).' berhasil diterapkan.'];
}


function seo_site_url(): string {
    $configured = trim((string)setting('site_url',''));
    if ($configured !== '') return rtrim($configured, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https://' : 'http://').$host;
}
function seo_url(string $path='/'): string {
    if (preg_match('~^https?://~i', $path)) return $path;
    $base = seo_site_url();
    $path = '/'.ltrim($path ?: '/', '/');
    return $base.$path;
}
function seo_clean_text(string $text, int $limit=160): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($text === '') return '';
    return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
}
function render_seo_meta(array $data): void {
    $site = (string)($data['site'] ?? setting('site_name','Digital Store'));
    $title = seo_clean_text((string)($data['title'] ?? $site), 70);
    $description = seo_clean_text((string)($data['description'] ?? setting('site_description','Toko produk digital terpercaya.')), 160);
    $path = (string)($data['path'] ?? '/');
    $canonical = (string)($data['canonical'] ?? seo_url($path));
    $type = (string)($data['type'] ?? 'website');
    $image = trim((string)($data['image'] ?? ''));
    if ($image !== '') $image = seo_url($image);
    $robots = (string)($data['robots'] ?? 'index, follow');
    echo '<title>'.e($title).'</title>' . "\n";
    echo '<meta name="description" content="'.e($description).'">' . "\n";
    $favicon = trim((string)setting('site_favicon',''));
    if ($favicon !== '') echo '<link rel="icon" href="'.e($favicon).'">' . "\n";
    $googleVerify = trim((string)setting('google_site_verification_meta',''));
    if ($googleVerify !== '') {
        if (preg_match('~content\s*=\s*(["\'])(.*?)\1~i', $googleVerify, $m)) $googleVerify = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        $googleVerify = trim(strip_tags($googleVerify));
        if ($googleVerify !== '') echo '<meta name="google-site-verification" content="'.e($googleVerify).'">' . "\n";
    }
    echo '<meta name="robots" content="'.e($robots).'">' . "\n";
    echo '<link rel="canonical" href="'.e($canonical).'">' . "\n";
    echo '<link rel="alternate" hreflang="id-ID" href="'.e($canonical).'">' . "\n";
    echo '<meta property="og:locale" content="id_ID">' . "\n";
    echo '<meta property="og:type" content="'.e($type).'">' . "\n";
    echo '<meta property="og:site_name" content="'.e($site).'">' . "\n";
    echo '<meta property="og:title" content="'.e($title).'">' . "\n";
    echo '<meta property="og:description" content="'.e($description).'">' . "\n";
    echo '<meta property="og:url" content="'.e($canonical).'">' . "\n";
    if ($image !== '') echo '<meta property="og:image" content="'.e($image).'">' . "\n";
    echo '<meta name="twitter:card" content="'.($image !== '' ? 'summary_large_image' : 'summary').'">' . "\n";
    echo '<meta name="twitter:title" content="'.e($title).'">' . "\n";
    echo '<meta name="twitter:description" content="'.e($description).'">' . "\n";
    if ($image !== '') echo '<meta name="twitter:image" content="'.e($image).'">' . "\n";
    $json = $data['json_ld'] ?? [];
    if ($json) {
        $jsonItems = array_is_list($json) ? $json : [$json];
        foreach ($jsonItems as $item) {
            if (!is_array($item)) continue;
            echo '<script type="application/ld+json">'.json_encode($item, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).'</script>' . "\n";
        }
    }
}
function store_structured_data(string $site, string $desc): array {
    $url = seo_url('/');
    return [
        '@context' => 'https://schema.org',
        '@type' => 'OnlineStore',
        'name' => $site,
        'url' => $url,
        'description' => seo_clean_text($desc, 180),
        'inLanguage' => 'id-ID',
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => $url.'?q={search_term_string}',
            'query-input' => 'required name=search_term_string'
        ]
    ];
}

function slugify($text){ $text=strtolower(trim($text)); $text=preg_replace('/[^a-z0-9]+/','-',$text); return trim($text,'-') ?: 'item'; }
function json_out($data, int $code=200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }
function csrf_token(){ ensure_storefront_session(); if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_check(){ ensure_storefront_session(); $sent=(string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')); $real=(string)($_SESSION['csrf'] ?? ''); if($sent==='' || $real==='' || !hash_equals($real,$sent)) { http_response_code(419); exit('CSRF token invalid'); } }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function invoice_no(){ return 'INV-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)),0,8)); }
function upload_image($field){
    if(empty($_FILES[$field]['name']) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) return null;
    if($_FILES[$field]['size'] > 2*1024*1024) throw new Exception('Ukuran gambar maksimal 2MB.');

    $tmp = $_FILES[$field]['tmp_name'];
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : mime_content_type($tmp);
    if(!isset($allowed[$mime])) throw new Exception('File gambar tidak valid. Gunakan JPG/PNG/WEBP.');

    $info = @getimagesize($tmp);
    if(!$info || empty($info[0]) || empty($info[1])) throw new Exception('File gambar rusak atau bukan gambar asli.');
    if($info[0] > 5000 || $info[1] > 5000) throw new Exception('Dimensi gambar terlalu besar. Maksimal 5000x5000 piksel.');

    $ext = $allowed[$mime];
    $name='prod-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$ext;
    $dest=__DIR__.'/../uploads/products/'.$name;

    $reencoded = false;
    if(function_exists('imagecreatetruecolor')){
        $img = null;
        if($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($tmp);
        if($mime === 'image/png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($tmp);
        if($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($tmp);
        if($img){
            if($mime === 'image/jpeg' && function_exists('imagejpeg')) $reencoded = @imagejpeg($img, $dest, 86);
            if($mime === 'image/png' && function_exists('imagepng')) { imagesavealpha($img, true); $reencoded = @imagepng($img, $dest, 6); }
            if($mime === 'image/webp' && function_exists('imagewebp')) $reencoded = @imagewebp($img, $dest, 86);
            imagedestroy($img);
        }
    }
    if(!$reencoded && !move_uploaded_file($tmp,$dest)) throw new Exception('Upload gagal.');
    @chmod($dest, 0644);
    return '/uploads/products/'.$name;
}

function upload_site_asset(string $field, string $kind='logo'): ?string {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new Exception('Upload file gagal.');
    if (($_FILES[$field]['size'] ?? 0) > 2*1024*1024) throw new Exception('Ukuran file maksimal 2MB.');

    $tmp = $_FILES[$field]['tmp_name'];
    $name = (string)$_FILES[$field]['name'];
    $extOriginal = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : mime_content_type($tmp);

    $allowedLogo = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $allowedFav = $allowedLogo + ['image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'];
    if ($kind === 'favicon' && $extOriginal === 'ico') $mime = $mime ?: 'image/x-icon';
    $allowed = $kind === 'favicon' ? $allowedFav : $allowedLogo;
    if (!isset($allowed[$mime])) throw new Exception($kind === 'favicon' ? 'Favicon harus JPG, PNG, WEBP, atau ICO.' : 'Logo harus JPG, PNG, atau WEBP.');

    if ($allowed[$mime] !== 'ico') {
        $info = @getimagesize($tmp);
        if (!$info || empty($info[0]) || empty($info[1])) throw new Exception('File gambar rusak atau bukan gambar asli.');
        if ($info[0] > 5000 || $info[1] > 5000) throw new Exception('Dimensi gambar terlalu besar. Maksimal 5000x5000 piksel.');
    }

    $dir = __DIR__.'/../uploads/site';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ext = $allowed[$mime];
    $filename = $kind.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$ext;
    $dest = $dir.'/'.$filename;
    if (!move_uploaded_file($tmp, $dest)) throw new Exception('Upload gagal disimpan.');
    @chmod($dest, 0644);
    return '/uploads/site/'.$filename;
}
function delete_public_upload(?string $path): void {
    $path = trim((string)$path);
    if ($path === '' || !str_starts_with($path, '/uploads/')) return;
    $full = realpath(__DIR__.'/..'.$path);
    $root = realpath(__DIR__.'/../uploads');
    if ($full && $root && str_starts_with($full, $root) && is_file($full)) @unlink($full);
}
function render_brand_mark(string $site='', string $desc='', string $smallOverride=''): string {
    $logo = trim((string)setting('site_logo',''));
    $small = $smallOverride !== '' ? $smallOverride : mb_strimwidth($desc, 0, 24, '...', 'UTF-8');
    $mark = $logo !== ''
        ? '<span class="brand-mark has-logo"><img class="brand-logo-img" src="'.e($logo).'" alt="Logo '.e($site ?: 'Website').'" loading="lazy"></span>'
        : '<span class="brand-mark">DS</span>';
    return $mark.'<span><b>'.e($site).'</b><small>'.e($small).'</small></span>';
}

/**
 * Storefront primary navigation (pages may land later).
 * Home · Track order · Warranty term · FAQ · Blog
 */
function storefront_nav_links(): array {
    return [
        ['label' => t('Beranda'), 'href' => '/'],
        ['label' => t('Track Order'), 'href' => '/track-order.php'],
        ['label' => t('Ketentuan Garansi'), 'href' => '/page.php?slug=ketentuan-garansi'],
        ['label' => t('FAQ'), 'href' => '/page.php?slug=faq'],
        ['label' => t('Blog'), 'href' => '/blog.php'],
    ];
}

/**
 * Shared UI icon set (single source of truth for storefront + admin panel).
 * Same component as admin_icon(): 24px stroke SVGs, currentColor.
 */
function ui_icon(string $name, string $class = ''): string {
    $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M9.5 20v-6h5v6"/>',
        'package' => '<path d="m3.5 7 8.5-4 8.5 4-8.5 4-8.5-4Z"/><path d="M3.5 7v10l8.5 4 8.5-4V7"/><path d="M12 11v10"/>',
        'layers' => '<path d="m12 2.8 9 4.7-9 4.7-9-4.7 9-4.7Z"/><path d="m3 12 9 4.7 9-4.7"/><path d="m3 16.5 9 4.7 9-4.7"/>',
        'shopping-cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.3l2.1 10.1a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 1.9-1.4L21 8H6"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'file-text' => '<path d="M6 2.8h8l4 4V21H6z"/><path d="M14 2.8V7h4"/><path d="M9 12h6M9 16h6"/>',
        'tag' => '<path d="M20.5 13.5 13 21l-10-10V3h8l9.5 9.5a1.4 1.4 0 0 1 0 2Z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'credit-card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6.5 15h4"/>',
        'telegram' => '<path d="m21 3-7.7 18-4.4-6.4L3 11.2 21 3Z"/><path d="m8.9 14.6 4.1-3.8"/>',
        'refresh' => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.7-2.6L20 11M4 13l2.2 4.6A7 7 0 0 0 17.9 15"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>',
        'external-link' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M19 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'log-out' => '<path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5"/><path d="m16 16 4-4-4-4M20 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.5 2.5 0 1 1 4.3 1.7c-1.1 1-2.1 1.5-2.1 3.3M12 18h.01"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5Z"/>',
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'chevron-down' => '<path d="m7 9.5 5 5 5-5"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20V7"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'box' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 8h8v8H8z"/>',
        'store' => '<path d="m5 4-2 6a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2l-2-6Z"/><path d="M5 12v8h14v-8"/><path d="M9 20v-5h6v5"/>',
        'check-card' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m8 12 2.2 2.2L16 9"/>',
        'wallet' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H18v16H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M4 7h14M14 12h7v5h-7a2.5 2.5 0 0 1 0-5Z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
        'save' => '<path d="M5 3h12l3 3v15H4V4a1 1 0 0 1 1-1Z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'filter' => '<path d="M4 5h16M7 12h10M10 19h4"/>',
        'eye' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>',
        'shield' => '<path d="M12 3 20 6v5c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
    ];
    $path = $paths[$name] ?? $paths['box'];
    $classAttr = $class !== '' ? ' class="'.e($class).'"' : '';
    return '<svg'.$classAttr.' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
}

/**
 * Shared storefront header.
 * PC: brand · centered text nav · right = search + wishlist + dark mode
 * Mobile: brand · dark mode · hamburger; cart is a floating button bottom-right.
 */
function render_storefront_header(string $site='', string $desc='', array $cats=[], array $opts=[]): void {
    $q = (string)($opts['q'] ?? ($_GET['q'] ?? ''));
    $searchAction = (string)($opts['search_action'] ?? '/');
    if ($site === '') $site = (string)setting('site_name', 'Digital Store');
    if ($desc === '') $desc = (string)setting('site_description', 'Toko Produk Digital Terlengkap');
    $menuLabel = t('Buka menu');
    $wishLabel = t('Wishlist');
    $cartLabel = t('Keranjang');
    $searchLabel = t('Cari');
    $searchTitle = t('Cari produk');
    $searchPlaceholder = t('Cari produk digital...');
    $navLinks = storefront_nav_links();
    ?>
<header class="ds-header">
  <div class="ds-shell header-inner">
    <a href="/" class="brand"><?=render_brand_mark($site,$desc)?></a>
    <nav class="head-nav" aria-label="<?=t('Navigasi utama')?>">
      <?php foreach ($navLinks as $nav): ?>
        <a href="<?=e($nav['href'])?>"><?=e($nav['label'])?></a>
      <?php endforeach; ?>
    </nav>
    <div class="head-actions">
      <form class="head-search" action="<?=e($searchAction)?>" method="get" data-head-search hidden>
        <input name="q" value="<?=e($q)?>" placeholder="<?=e($searchPlaceholder)?>" aria-label="<?=e($searchTitle)?>">
        <button type="submit"><?=e($searchLabel)?></button>
      </form>
      <button type="button" class="head-chip head-search-toggle" data-toggle-head-search aria-label="<?=e($searchTitle)?>" aria-expanded="false">
        <span aria-hidden="true">⌕</span>
      </button>
      <a href="/wishlist.php" class="head-chip header-wishlist" aria-label="<?=e($wishLabel)?>">
        <?=ui_icon('heart')?>
      </a>
      <button type="button" data-theme-toggle class="moon head-chip" aria-label="Dark mode">
        <?=ui_icon('moon')?>
      </button>
      <button type="button" class="mobile-menu-btn" data-open-mobile-menu aria-label="<?=e($menuLabel)?>">
        <span class="burger-icon" aria-hidden="true"><i></i><i></i><i></i></span>
      </button>
    </div>
  </div>
</header>
<button type="button" class="cart-fab" data-open-cart aria-label="<?=e($cartLabel)?>">
  <span aria-hidden="true">🛒</span>
  <i data-cart-count>0</i>
</button>
<?php
}

/**
 * Shared mobile drawer — full-width panel below header, centered links (no brand/login/wishlist).
 */
function render_storefront_mobile_menu(string $site='', string $desc='', array $cats=[], array $opts=[]): void {
    $q = (string)($opts['q'] ?? ($_GET['q'] ?? ''));
    if ($site === '') $site = (string)setting('site_name', 'Digital Store');
    if ($desc === '') $desc = (string)setting('site_description', 'Toko Produk Digital Terlengkap');
    $searchLabel = t('Cari');
    $searchPlaceholder = t('Cari produk digital...');
    $navLinks = storefront_nav_links();
    ?>
<div class="mobile-menu" id="mobileMenu" aria-hidden="true">
  <div class="mobile-menu-backdrop" data-close-mobile-menu></div>
  <aside class="mobile-menu-panel">
    <form class="mobile-menu-search" action="/" method="get">
      <input name="q" value="<?=e($q)?>" placeholder="<?=e($searchPlaceholder)?>">
      <button type="submit"><?=e($searchLabel)?></button>
    </form>
    <nav class="mobile-menu-nav" aria-label="<?=t('Navigasi utama')?>">
      <?php foreach ($navLinks as $nav): ?>
        <a href="<?=e($nav['href'])?>"><?=e($nav['label'])?></a>
      <?php endforeach; ?>
    </nav>
  </aside>
</div>
<?php
}
function safe_product_html(string $html): string {
    $allowed = '<a><b><strong><i><em><br><p><small><span><ul><ol><li><h1><h2><h3><h4><blockquote><code>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/iu', '', $html);
    $html = preg_replace('/\s(href|src)\s*=\s*(["\'])\s*javascript:.*?\2/iu', ' $1="#"', $html);
    if (!preg_match('/<[^>]+>/', $html)) $html = nl2br(e($html));
    return $html;
}

function flash($key,$val=null){ ensure_storefront_session(); if($val===null){$v=$_SESSION['flash'][$key]??null; unset($_SESSION['flash'][$key]); return $v;} $_SESSION['flash'][$key]=$val; }

function db_column_exists(string $table, string $column): bool {
    global $pdo;
    static $cache = [];
    $key = $table.'.'.$column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
        $st->execute([$table, $column]);
        return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $cache[$key] = false; }
}
function ensure_order_security_columns(): void {
    global $pdo;
    static $done=false;
    if ($done) return;
    $done=true;
    try { if (!db_column_exists('orders','access_password_hash')) $pdo->exec("ALTER TABLE orders ADD access_password_hash VARCHAR(255) NULL AFTER qris_payload"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','access_password_admin_enc')) $pdo->exec("ALTER TABLE orders ADD access_password_admin_enc TEXT NULL AFTER access_password_hash"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','payment_reference')) $pdo->exec("ALTER TABLE orders ADD payment_reference VARCHAR(180) NULL AFTER status"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','payment_provider')) $pdo->exec("ALTER TABLE orders ADD payment_provider VARCHAR(40) NULL AFTER status"); } catch (Throwable $e) {}
    try {
        $st=$pdo->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_order_payment_provider' LIMIT 1");
        $st->execute();
        if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE orders ADD KEY idx_order_payment_provider (payment_provider,payment_reference)");
    } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','payment_expires_at')) $pdo->exec("ALTER TABLE orders ADD payment_expires_at DATETIME NULL AFTER access_password_admin_enc"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','promo_id')) $pdo->exec("ALTER TABLE orders ADD promo_id INT NULL AFTER discount"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','promo_counted_at')) $pdo->exec("ALTER TABLE orders ADD promo_counted_at DATETIME NULL AFTER promo_id"); } catch (Throwable $e) {}
}



function product_default_thumbnail_url(): string {
    return '';
}

function ensure_pages_table(): void {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            slug VARCHAR(190) NOT NULL UNIQUE,
            content MEDIUMTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    
    try { if (!db_column_exists('pages','content')) $pdo->exec("ALTER TABLE pages ADD content MEDIUMTEXT NULL AFTER slug"); } catch (Throwable $e) {}
    try { if (!db_column_exists('pages','sort_order')) $pdo->exec("ALTER TABLE pages ADD sort_order INT NOT NULL DEFAULT 0 AFTER content"); } catch (Throwable $e) {}
    try { if (!db_column_exists('pages','status')) $pdo->exec("ALTER TABLE pages ADD status TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order"); } catch (Throwable $e) {}
    try { if (!db_column_exists('pages','created_at')) $pdo->exec("ALTER TABLE pages ADD created_at DATETIME NULL AFTER status"); } catch (Throwable $e) {}
    try { if (!db_column_exists('pages','updated_at')) $pdo->exec("ALTER TABLE pages ADD updated_at DATETIME NULL AFTER created_at"); } catch (Throwable $e) {}
}

function ensure_category_icon_column(): void {
    global $pdo;
    try {
        if (!db_column_exists('categories','icon')) {
            $pdo->exec("ALTER TABLE categories ADD icon VARCHAR(64) NOT NULL DEFAULT '' AFTER slug");
        }
    } catch (Throwable $e) {}
}

function category_icon_symbol(string $name, ?string $custom = ''): string {
    $custom = trim((string)$custom);
    if ($custom !== '') return $custom;
    $name = mb_strtolower($name);
    if (str_contains($name,'akun') || str_contains($name,'premium')) return '♛';
    if (str_contains($name,'book') || str_contains($name,'ebook')) return '▭';
    if (str_contains($name,'template')) return '▤';
    if (str_contains($name,'tool')) return '⌘';
    if (str_contains($name,'voucher')) return '⌁';
    return '▦';
}

function warranty_days_from_label(?string $label): int {
    $label=mb_strtolower(trim((string)$label));
    if($label==='') return 0;
    if(str_contains($label,'tanpa garansi') || str_contains($label,'no warranty')) return 0;
    if(preg_match('/(\d+)\s*(hari|day)/u',$label,$m)) return min(3650,max(0,(int)$m[1]));
    if(preg_match('/(\d+)\s*(minggu|week)/u',$label,$m)) return min(3650,max(0,(int)$m[1]*7));
    if(preg_match('/(\d+)\s*(bulan|month)/u',$label,$m)) return min(3650,max(0,(int)$m[1]*30));
    if(preg_match('/(\d+)\s*(tahun|year)/u',$label,$m)) return min(3650,max(0,(int)$m[1]*365));
    return 0;
}
function ensure_product_warranty_column(): void {
    global $pdo;
    $orderWarrantyAdded=false;
    try {
        if (!db_column_exists('products','warranty_label')) {
            $pdo->exec("ALTER TABLE products ADD warranty_label VARCHAR(100) NOT NULL DEFAULT '7 Hari' AFTER description");
        }
    } catch (Throwable $e) {}
    try {
        if (!db_column_exists('products','warranty_days')) {
            $pdo->exec("ALTER TABLE products ADD warranty_days INT NOT NULL DEFAULT 7 AFTER warranty_label");
            $rows=$pdo->query("SELECT id,warranty_label FROM products")->fetchAll();
            $up=$pdo->prepare("UPDATE products SET warranty_days=? WHERE id=?");
            foreach($rows as $row){
                $label=trim((string)($row['warranty_label'] ?? ''));
                $days=warranty_days_from_label($label);
                if($days===0 && $label!=='' && !str_contains(mb_strtolower($label),'tanpa garansi') && !str_contains(mb_strtolower($label),'no warranty')) $days=7;
                $up->execute([$days,(int)$row['id']]);
            }
        }
    } catch (Throwable $e) {}
    try { if (!db_column_exists('order_items','warranty_label')) { $pdo->exec("ALTER TABLE order_items ADD warranty_label VARCHAR(100) NULL AFTER product_name"); $orderWarrantyAdded=true; } } catch (Throwable $e) {}
    try { if (!db_column_exists('order_items','warranty_days')) { $pdo->exec("ALTER TABLE order_items ADD warranty_days INT NOT NULL DEFAULT 0 AFTER warranty_label"); $orderWarrantyAdded=true; } } catch (Throwable $e) {}
    try { if (!db_column_exists('order_items','warranty_started_at')) { $pdo->exec("ALTER TABLE order_items ADD warranty_started_at DATETIME NULL AFTER warranty_days"); $orderWarrantyAdded=true; } } catch (Throwable $e) {}
    try { if (!db_column_exists('order_items','warranty_expires_at')) { $pdo->exec("ALTER TABLE order_items ADD warranty_expires_at DATETIME NULL AFTER warranty_started_at"); $orderWarrantyAdded=true; } } catch (Throwable $e) {}
    if($orderWarrantyAdded){
        try {
            $pdo->exec("UPDATE order_items oi JOIN products p ON p.id=oi.product_id SET oi.warranty_label=COALESCE(NULLIF(oi.warranty_label,''),p.warranty_label), oi.warranty_days=CASE WHEN oi.warranty_days>0 THEN oi.warranty_days ELSE COALESCE(p.warranty_days,0) END WHERE oi.warranty_label IS NULL OR oi.warranty_label='' OR oi.warranty_days=0");
            $pdo->exec("UPDATE order_items oi JOIN orders o ON o.id=oi.order_id SET oi.warranty_started_at=COALESCE(oi.warranty_started_at,o.delivered_at,o.paid_at,o.created_at), oi.warranty_expires_at=CASE WHEN oi.warranty_days>0 THEN COALESCE(oi.warranty_expires_at,DATE_ADD(COALESCE(o.delivered_at,o.paid_at,o.created_at),INTERVAL oi.warranty_days DAY)) ELSE NULL END WHERE o.status IN ('paid','delivered') AND oi.warranty_started_at IS NULL");
        } catch (Throwable $e) {}
    }
}

function product_warranty_days(array $product): int {
    if(array_key_exists('warranty_days',$product)) return min(3650,max(0,(int)$product['warranty_days']));
    return warranty_days_from_label($product['warranty_label'] ?? '');
}
function activate_order_warranties(int $orderId): void {
    global $pdo;
    ensure_product_warranty_column();
    $pdo->prepare("UPDATE order_items SET warranty_started_at=COALESCE(warranty_started_at,NOW()), warranty_expires_at=CASE WHEN warranty_days>0 THEN COALESCE(warranty_expires_at,DATE_ADD(NOW(),INTERVAL warranty_days DAY)) ELSE NULL END WHERE order_id=?")->execute([$orderId]);
}
function warranty_status_text(array $item): string {
    $days=max(0,(int)($item['warranty_days'] ?? 0));
    if($days<=0) return 'Tanpa garansi';
    $expires=trim((string)($item['warranty_expires_at'] ?? ''));
    if($expires==='') return 'Garansi '.$days.' hari';
    return strtotime($expires)>=time() ? 'Aktif sampai '.display_datetime_gmt7($expires) : 'Berakhir '.display_datetime_gmt7($expires);
}

function ensure_product_delivery_column(): void {
    global $pdo;
    try {
        if (!db_column_exists('products','delivery_type')) {
            $pdo->exec("ALTER TABLE products ADD delivery_type ENUM('instant','manual') NOT NULL DEFAULT 'instant' AFTER warranty_label");
        }
    } catch (Throwable $e) {
        try {
            if (!db_column_exists('products','delivery_type')) {
                $pdo->exec("ALTER TABLE products ADD delivery_type VARCHAR(20) NOT NULL DEFAULT 'instant' AFTER warranty_label");
            }
        } catch (Throwable $e2) {}
    }
}

function ensure_product_manual_stock_column(): void {
    global $pdo;
    try {
        if (!db_column_exists('products','manual_stock')) {
            $pdo->exec("ALTER TABLE products ADD manual_stock INT NOT NULL DEFAULT 0 AFTER delivery_type");
        }
    } catch (Throwable $e) {}
}
function ensure_product_variant_columns(): void {
    global $pdo;
    static $done=false;
    if ($done) return;
    $done=true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_variants (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            price DECIMAL(14,2) NOT NULL DEFAULT 0,
            discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            flash_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            warranty_label VARCHAR(100) NOT NULL DEFAULT '',
            warranty_days INT NOT NULL DEFAULT 0,
            price_delta DECIMAL(14,2) NOT NULL DEFAULT 0,
            original_price DECIMAL(14,2) NOT NULL DEFAULT 0,
            manual_stock INT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            KEY idx_variant_product (product_id,status,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    foreach ([
        'price' => "ALTER TABLE product_variants ADD price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER name",
        'discount_pct' => "ALTER TABLE product_variants ADD discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER price",
        'flash_discount_pct' => "ALTER TABLE product_variants ADD flash_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_pct",
        'warranty_label' => "ALTER TABLE product_variants ADD warranty_label VARCHAR(100) NOT NULL DEFAULT '' AFTER flash_discount_pct",
        'warranty_days' => "ALTER TABLE product_variants ADD warranty_days INT NOT NULL DEFAULT 0 AFTER warranty_label",
        'price_delta' => "ALTER TABLE product_variants ADD price_delta DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER warranty_days",
        'original_price' => "ALTER TABLE product_variants ADD original_price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER price_delta",
    ] as $col => $ddl) {
        try { if (!db_column_exists('product_variants', $col)) $pdo->exec($ddl); } catch (Throwable $e) {}
    }
    try { if (!db_column_exists('product_stocks','variant_id')) $pdo->exec("ALTER TABLE product_stocks ADD variant_id INT UNSIGNED NULL AFTER product_id"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE product_stocks ADD KEY idx_stock_variant (variant_id,status)"); } catch (Throwable $e) {}
    try { if (!db_column_exists('order_items','variant_id')) $pdo->exec("ALTER TABLE order_items ADD variant_id INT UNSIGNED NULL AFTER product_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('order_items','variant_name')) $pdo->exec("ALTER TABLE order_items ADD variant_name VARCHAR(120) NULL AFTER variant_id"); } catch (Throwable $e) {}
    try { ensure_variant_level_pricing_backfill(); } catch (Throwable $e) {}
}

/**
 * One-time backfill of the variant-level pricing model.
 *
 * Legacy (product-level):  products.price + product_variants.price_delta = sale,
 *                          products.original_price / product_variants.original_price = coret,
 *                          products.flash_price = flash sale price.
 * New (variant-level):     product_variants.price = harga reguler (== coret),
 *                          product_variants.discount_pct / flash_discount_pct = % off.
 *
 * Runs only for variants that still have price=0 while the legacy columns carry data,
 * so it is idempotent and will not clobber values an admin has already saved.
 */
function ensure_variant_level_pricing_backfill(): void {
    global $pdo;
    $st=$pdo->query("SELECT v.id, v.price_delta, v.original_price, v.manual_stock,
            p.id product_id, p.price product_price, p.original_price product_original,
            p.is_flash_sale, p.flash_price, p.flash_ends_at,
            p.warranty_label product_warranty_label, p.warranty_days product_warranty_days
        FROM product_variants v JOIN products p ON p.id=v.product_id
        WHERE v.price=0 AND (v.price_delta<>0 OR p.price>0 OR p.original_price>0 OR p.flash_price>0
              OR p.warranty_label<>'' OR p.warranty_days>0)");
    $rows=$st->fetchAll();
    if(!$rows) return;

    $flashNow=static function(array $p): bool {
        return !empty($p['is_flash_sale'])
            && (float)$p['flash_price'] > 0
            && (empty($p['flash_ends_at']) || strtotime((string)$p['flash_ends_at']) > time());
    };
    $legacySale=static function(array $p, array $v) use ($flashNow): float {
        $base=$flashNow($p) ? (float)$p['flash_price'] : (float)$p['price'];
        return max(0.0, $base + (float)$v['price_delta']);
    };
    $legacyCompare=static function(array $p, array $v) use ($flashNow): float {
        $sale=$legacySale($p,$v);
        if ((float)$v['original_price'] > 0) return (float)$v['original_price'] > $sale ? (float)$v['original_price'] : 0.0;
        $delta=(float)$v['price_delta'];
        $base=$flashNow($p) ? (float)$p['flash_price'] : (float)$p['price'];
        $regular=(float)$p['price'];
        $orig=(float)$p['original_price'];
        if ($orig > 0 && $orig > $base) $compare = $orig + $delta;
        elseif ($regular > $base) $compare = $regular + $delta;
        else $compare = 0.0;
        return $compare > $sale ? $compare : 0.0;
    };

    $upd=$pdo->prepare('UPDATE product_variants SET price=?, discount_pct=?, flash_discount_pct=?, warranty_label=?, warranty_days=? WHERE id=?');
    foreach ($rows as $row) {
        $v=['id'=>(int)$row['id'],'price_delta'=>(float)$row['price_delta'],'original_price'=>(float)$row['original_price']];
        $p=[
            'price'=>(float)$row['product_price'],'original_price'=>(float)$row['product_original'],
            'is_flash_sale'=>(int)$row['is_flash_sale'],'flash_price'=>(float)$row['flash_price'],
            'flash_ends_at'=>$row['flash_ends_at'],
        ];
        $regular=(float)$row['product_price'] + (float)$row['price_delta'];
        $legacyOriginal=(float)$row['original_price'] > 0 ? (float)$row['original_price']
            : ((float)$row['product_original'] > 0 ? (float)$row['product_original'] + (float)$row['price_delta'] : 0.0);
        $newPrice = $legacyOriginal > 0 ? $legacyOriginal : max(0.0, $regular);

        $saleNoFlash = max(0.0, (float)$row['product_price'] + (float)$row['price_delta']);
        if ($newPrice > 0 && $saleNoFlash < $newPrice) {
            $discountPct = round((1 - $saleNoFlash / $newPrice) * 100, 2);
        } elseif ($newPrice > 0 && $saleNoFlash > $newPrice) {
            // legacy coret below sale → treat sale as the regular price, no discount
            $newPrice = $saleNoFlash;
            $discountPct = 0.0;
        } else {
            $discountPct = 0.0;
        }

        $flashPct = 0.0;
        if ((int)$row['is_flash_sale'] === 1 && (float)$row['flash_price'] > 0) {
            $flashSale = max(0.0, (float)$row['flash_price'] + (float)$row['price_delta']);
            if ($newPrice > 0 && $flashSale < $newPrice) $flashPct = round((1 - $flashSale / $newPrice) * 100, 2);
        }

        $warrantyLabel = trim((string)$row['product_warranty_label']);
        $warrantyDays = max(0, (int)$row['product_warranty_days']);
        if ($warrantyDays <= 0) $warrantyDays = warranty_days_from_label($warrantyLabel);
        if ($warrantyLabel === '') $warrantyLabel = $warrantyDays > 0 ? ($warrantyDays.' Hari') : '';

        $upd->execute([
            round($newPrice, 2),
            max(0.0, min(100.0, $discountPct)),
            max(0.0, min(100.0, $flashPct)),
            mb_substr($warrantyLabel, 0, 100),
            $warrantyDays,
            (int)$row['id'],
        ]);
    }
}
function ensure_product_reviews_table(): void {
    global $pdo;
    static $done=false;
    if ($done) return;
    $done=true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
            comment TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            ip VARCHAR(80) NOT NULL DEFAULT '',
            created_at DATETIME NULL, moderated_at DATETIME NULL,
            order_id INT UNSIGNED NULL,
            order_item_id INT UNSIGNED NULL,
            KEY idx_review_product_status (product_id,status,created_at),
            KEY idx_review_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    try { if (!db_column_exists('product_reviews','order_id')) $pdo->exec("ALTER TABLE product_reviews ADD order_id INT UNSIGNED NULL AFTER moderated_at"); } catch (Throwable $e) {}
    try { if (!db_column_exists('product_reviews','order_item_id')) $pdo->exec("ALTER TABLE product_reviews ADD order_item_id INT UNSIGNED NULL AFTER order_id"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE product_reviews ADD UNIQUE KEY uq_review_order_item (order_item_id)"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE product_reviews ADD KEY idx_review_order (order_id)"); } catch (Throwable $e) {}
}

/**
 * Buyer-only review helpers — prove purchase with invoice + access password
 * (same secret as track-order unlock). No storefront accounts.
 */
function product_review_session_has_order_access(int $orderId, string $invoice): bool {
    ensure_storefront_session();
    $invoice = trim($invoice);
    if ($invoice === '') return false;
    $st = $GLOBALS['pdo']->prepare('SELECT id, invoice, status FROM orders WHERE id=? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order || (string)$order['invoice'] !== $invoice) return false;
    $status = strtolower((string)($order['status'] ?? ''));
    if (!in_array($status, ['paid', 'delivered'], true)) return false;
    if (isset($_SESSION['order_access'][$invoice])) return true;
    foreach (($_SESSION['review_access'] ?? []) as $row) {
        if (is_array($row) && (int)($row['order_id'] ?? 0) === $orderId && (string)($row['invoice'] ?? '') === $invoice) {
            return true;
        }
    }
    return false;
}

function product_review_resolve_order_item(int $productId, string $invoice, string $password): array {
    global $pdo;
    $invoice = trim($invoice);
    $password = trim($password);
    if ($invoice === '' || $password === '') {
        return ['ok' => false, 'message' => 'Invoice dan kata sandi produk wajib diisi.'];
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $invoice) || strlen($invoice) > 80) {
        return ['ok' => false, 'message' => 'Invoice tidak valid.'];
    }
    if (order_access_blocked($invoice, 'password')) {
        return ['ok' => false, 'message' => 'Terlalu banyak percobaan kata sandi. Coba lagi beberapa menit lagi.'];
    }
    $st = $pdo->prepare('SELECT * FROM orders WHERE invoice=? ORDER BY id DESC LIMIT 1');
    $st->execute([$invoice]);
    $order = $st->fetch();
    if (!$order) {
        record_order_access_fail($invoice, 'password', 5, 900);
        return ['ok' => false, 'message' => 'Pesanan tidak ditemukan.'];
    }
    $status = strtolower((string)($order['status'] ?? ''));
    if (!in_array($status, ['paid', 'delivered'], true)) {
        return ['ok' => false, 'message' => 'Ulasan hanya dapat diberikan setelah pembayaran lunas.'];
    }
    if (!verify_order_access($order, $password)) {
        record_order_access_fail($invoice, 'password', 5, 900);
        return ['ok' => false, 'message' => 'Kata sandi produk salah.'];
    }
    clear_order_access_fail($invoice, 'password');
    $st = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? AND product_id=? ORDER BY id LIMIT 1');
    $st->execute([(int)$order['id'], $productId]);
    $item = $st->fetch();
    if (!$item) {
        return ['ok' => false, 'message' => 'Produk ini tidak ada di pesanan Anda.'];
    }
    return [
        'ok' => true,
        'order' => $order,
        'item' => $item,
        'buyer_name' => trim((string)($order['buyer_name'] ?? '')),
    ];
}

function product_review_already_submitted(int $orderId): bool {
    global $pdo;
    $st = $pdo->prepare("SELECT COUNT(*) FROM product_reviews WHERE order_id=? AND status IN ('pending','approved')");
    $st->execute([$orderId]);
    return (int)$st->fetchColumn() > 0;
}

function product_review_unlock_session(int $orderId, string $invoice, int $productId, string $password=''): void {
    ensure_storefront_session();
    $invoice = trim($invoice);
    if ($invoice === '') return;
    // Keep track-order secret when known so lacak pesanan + ulasan share one unlock.
    if ($password !== '' && !isset($_SESSION['order_access'][$invoice])) {
        $_SESSION['order_access'][$invoice] = $password;
    }
    $_SESSION['review_access'][$productId] = [
        'order_id' => $orderId,
        'invoice' => $invoice,
    ];
}

function product_review_session_unlocked(int $productId): ?array {
    ensure_storefront_session();
    $row = $_SESSION['review_access'][$productId] ?? null;
    if (is_array($row) && !empty($row['invoice']) && !empty($row['order_id'])) {
        $orderId = (int)$row['order_id'];
        $invoice = (string)$row['invoice'];
        if (product_review_session_has_order_access($orderId, $invoice)) {
            $st = $GLOBALS['pdo']->prepare('SELECT id FROM order_items WHERE order_id=? AND product_id=? LIMIT 1');
            $st->execute([$orderId, $productId]);
            if ($st->fetch()) {
                return ['order_id' => $orderId, 'invoice' => $invoice];
            }
        }
    }
    // Fallback: track-order session unlock that covers this product.
    $sessionInvoices = array_keys($_SESSION['order_access'] ?? []);
    foreach ($sessionInvoices as $inv) {
        $st = $GLOBALS['pdo']->prepare('SELECT o.id, o.status FROM orders o WHERE o.invoice=? LIMIT 1');
        $st->execute([(string)$inv]);
        $order = $st->fetch();
        if (!$order) continue;
        $status = strtolower((string)($order['status'] ?? ''));
        if (!in_array($status, ['paid', 'delivered'], true)) continue;
        $st2 = $GLOBALS['pdo']->prepare('SELECT id FROM order_items WHERE order_id=? AND product_id=? LIMIT 1');
        $st2->execute([(int)$order['id'], $productId]);
        if ($st2->fetch()) {
            return ['order_id' => (int)$order['id'], 'invoice' => (string)$inv];
        }
    }
    return null;
}

/**
 * Little-censored buyer contact for public review lists.
 * Prefers WhatsApp digits (0812****7890); falls back to Telegram @us****so.
 */
function product_review_censor_contact(?string $whatsapp, ?string $telegramUsername): string {
    $wa = preg_replace('/\D+/', '', (string)$whatsapp);
    if (is_string($wa) && $wa !== '') {
        $len = strlen($wa);
        if ($len <= 6) return $wa;
        $keepStart = 5;
        $keepEnd = 4;
        if ($len - $keepStart - $keepEnd < 2) {
            $keepStart = max(2, $len - $keepEnd - 2);
        }
        $stars = str_repeat('*', max(2, $len - $keepStart - $keepEnd));
        return substr($wa, 0, $keepStart) . $stars . substr($wa, -$keepEnd);
    }
    $tg = ltrim(trim((string)$telegramUsername), '@');
    if ($tg !== '') {
        $len = mb_strlen($tg);
        if ($len <= 5) return '@' . $tg;
        $keepStart = 3;
        $keepEnd = 2;
        $stars = str_repeat('*', max(2, $len - $keepStart - $keepEnd));
        return '@' . mb_substr($tg, 0, $keepStart) . $stars . mb_substr($tg, -$keepEnd);
    }
    return '';
}

function checkout_demo_payment_enabled_setting(): bool {
    return (string)setting('checkout_demo_payment_enabled', '0') === '1';
}

function ensure_product_default_variants(): void {
    global $pdo;
    ensure_product_variant_columns();
    static $done=false;
    if ($done) return;
    $done=true;
    try {
        // 1) Products with zero variants → INSERT "Default" variant (price taken from the legacy product row)
        $pdo->exec("INSERT INTO product_variants(product_id,name,price,price_delta,manual_stock,sort_order,status,created_at,updated_at)
            SELECT p.id,'Default',p.price,0,0,0,1,NOW(),NOW() FROM products p
            WHERE NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id=p.id)");
        // 2) Manual products → Default variant manual_stock = products.manual_stock
        $pdo->exec("UPDATE product_variants v JOIN products p ON p.id=v.product_id
            SET v.manual_stock=p.manual_stock
            WHERE v.name='Default' AND p.delivery_type='manual' AND v.manual_stock=0 AND p.manual_stock>0");
        // 3) Instant products → tag untagged stock rows to Default variant
        $pdo->exec("UPDATE product_stocks s JOIN products p ON p.id=s.product_id
            JOIN product_variants v ON v.product_id=p.id AND v.name='Default'
            SET s.variant_id=v.id WHERE s.variant_id IS NULL AND p.delivery_type='instant'");
        // 4) Legacy manual order_items → tag to Default variant (needed so mark_order_paid
        //    can decrement variant stock for still-pending orders)
        $pdo->exec("UPDATE order_items oi JOIN products p ON p.id=oi.product_id
            JOIN product_variants v ON v.product_id=p.id AND v.name='Default'
            SET oi.variant_id=v.id, oi.variant_name=COALESCE(NULLIF(oi.variant_name,''),'Default')
            WHERE oi.variant_id IS NULL AND p.delivery_type='manual'");
    } catch (Throwable $e) {}
}
function product_active_variants(int $productId): array {
    global $pdo;
    ensure_product_variant_columns();
    $st=$pdo->prepare('SELECT * FROM product_variants WHERE product_id=? AND status=1 ORDER BY sort_order,id');
    $st->execute([$productId]);
    return $st->fetchAll();
}
/**
 * Variant-level pricing (plan v4).
 *
 * The product is only a parent — it carries no price, stock or warranty.
 * Everything below lives on the variant:
 *   product_variants.price             harga reguler (== harga coret)
 *   product_variants.discount_pct      diskon rutin, persen
 *   product_variants.flash_discount_pct diskon flash, persen (saat jendela flash produk aktif)
 *
 * Sale price = price * (1 - pct/100).  The <del> slash is simply `price`
 * whenever the effective discount is greater than zero.
 */

/** Flash WINDOW lives on the product (one sale event); the amount is per variant. */
function product_variant_flash_active(array $p): bool {
    return !empty($p['is_flash_sale'])
        && (empty($p['flash_ends_at']) || strtotime((string)$p['flash_ends_at']) > time());
}

/** Harga reguler varian — angka yang dicoret saat diskon > 0. */
function product_variant_regular_price(array $variant): float {
    return max(0.0, (float)($variant['price'] ?? 0));
}

/** Diskon efektif (persen) untuk varian, memperhitungkan jendela flash produk. */
function product_variant_discount_pct(array $p, array $variant): float {
    $pct = 0.0;
    if (product_variant_flash_active($p)) $pct = (float)($variant['flash_discount_pct'] ?? 0);
    if ($pct <= 0) $pct = (float)($variant['discount_pct'] ?? 0);
    return max(0.0, min(100.0, $pct));
}

/** Harga jual varian setelah diskon. */
function product_variant_price(array $p, array $variant): float {
    $regular = product_variant_regular_price($variant);
    $pct = product_variant_discount_pct($p, $variant);
    if ($pct <= 0 || $regular <= 0) return $regular;
    return max(0.0, round($regular * (1 - $pct / 100), 2));
}

/**
 * Harga coret varian. Mengembalikan 0 bila varian itu tidak sedang didiskon,
 * supaya card/PDP bisa menyembunyikan <del> dengan bersih.
 */
function product_variant_compare_price(array $p, array $variant): float {
    $sale = product_variant_price($p, $variant);
    $regular = product_variant_regular_price($variant);
    return $regular > $sale ? $regular : 0.0;
}

/** Label garansi varian (fallback ke kolom produk lama bila varian belum terisi). */
function product_variant_warranty_label(array $variant, array $p = []): string {
    $label = trim((string)($variant['warranty_label'] ?? ''));
    if ($label === '') $label = trim((string)($p['warranty_label'] ?? ''));
    return $label;
}

/** Lama garansi varian dalam hari (fallback ke label/kolom produk lama). */
function product_variant_warranty_days(array $variant, array $p = []): int {
    if (isset($variant['warranty_days']) && (int)$variant['warranty_days'] > 0) {
        return min(3650, max(0, (int)$variant['warranty_days']));
    }
    $label = trim((string)($variant['warranty_label'] ?? ''));
    if ($label !== '') {
        $fromLabel = warranty_days_from_label($label);
        if ($fromLabel > 0) return $fromLabel;
    }
    if ($p) return product_warranty_days($p);
    return 0;
}

/**
 * Catalog-card pricing: price of the cheapest active variant plus that variant's
 * slashed price, the biggest per-variant discount percent (for the card badge),
 * and whether any variant still has stock (cards mark the buy button when
 * everything is gone).
 */
function storefront_product_card_pricing(array $p): array {
    $variants = product_active_variants((int)($p['id'] ?? 0));
    $cheapestInStock = null;
    $cheapestAny = null;
    $hasStock = false;
    $maxDiscountPct = 0;
    foreach ($variants as $v) {
        $vp = product_variant_price($p, $v);
        $vc = product_variant_compare_price($p, $v);
        $pct = $vc > $vp && $vp > 0 ? (int)round((1 - $vp / $vc) * 100) : 0;
        if ($pct > $maxDiscountPct) $maxDiscountPct = $pct;
        if ($cheapestAny === null || $vp < $cheapestAny[0]) $cheapestAny = [$vp, $vc, $v];
        if (product_variant_stock($p, $v) > 0) {
            $hasStock = true;
            if ($cheapestInStock === null || $vp < $cheapestInStock[0]) $cheapestInStock = [$vp, $vc, $v];
        }
    }
    // Show the cheapest buyable variant; if everything is sold out, still show the cheapest variant price.
    if ($cheapestInStock !== null) {
        [$price, $compare, $shown] = $cheapestInStock;
    } elseif ($cheapestAny !== null) {
        [$price, $compare, $shown] = $cheapestAny;
    } else {
        $price = 0.0;
        $compare = 0.0;
        $shown = [];
    }
    return [
        'price' => (float)$price,
        'hasStock' => $hasStock,
        'originalPrice' => $compare > $price ? (float)$compare : 0.0,
        'maxDiscountPct' => $maxDiscountPct,
        'discountPct' => ($compare > $price && $price > 0) ? (int)round((1 - $price / $compare) * 100) : 0,
        'variantId' => (int)($shown['id'] ?? 0),
        'variantName' => (string)($shown['name'] ?? ''),
        'warrantyLabel' => product_variant_warranty_label($shown ?: [], $p),
        'warrantyDays' => product_variant_warranty_days($shown ?: [], $p),
    ];
}

/**
 * Refresh the denormalized products.rating cache from approved reviews.
 * Returns the cached value. Called after moderation and lazily on PDP view.
 */
function product_refresh_rating(int $productId): float {
    global $pdo;
    $productId = (int)$productId;
    if ($productId <= 0) return 0.0;
    $st = $pdo->prepare("SELECT COUNT(*) c, AVG(rating) a FROM product_reviews WHERE product_id=? AND status='approved'");
    $st->execute([$productId]);
    $row = $st->fetch() ?: ['c' => 0, 'a' => null];
    $count = (int)$row['c'];
    $avg = $row['a'] !== null ? (float)$row['a'] : 0.0;
    if ($count <= 0) {
        // No approved reviews → leave whatever is cached alone (legacy seeded ratings).
        $st = $pdo->prepare('SELECT rating FROM products WHERE id=?');
        $st->execute([$productId]);
        return max(0.0, min(5.0, (float)($st->fetchColumn() ?: 0)));
    }
    $synced = round(max(0.0, min(5.0, $avg)), 1);
    $pdo->prepare('UPDATE products SET rating=? WHERE id=?')->execute([$synced, $productId]);
    return $synced;
}
function manual_reserved_stock_variant(int $productId, int $variantId): int {
    global $pdo;
    $st=$pdo->prepare("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=? AND oi.variant_id=? AND o.status='pending' AND (o.payment_expires_at IS NULL OR o.payment_expires_at>NOW())");
    $st->execute([$productId,$variantId]);
    return max(0,(int)$st->fetchColumn());
}
function product_variant_stock(array $p, array $variant): int {
    global $pdo;
    ensure_product_variant_columns();
    $productId=(int)($p['id'] ?? 0);
    $variantId=(int)($variant['id'] ?? 0);
    if ($variantId <= 0) return 0;
    if (strtolower((string)($p['delivery_type'] ?? 'instant')) === 'manual') {
        return max(0, (int)($variant['manual_stock'] ?? 0) - manual_reserved_stock_variant($productId, $variantId));
    }
    $st=$pdo->prepare('SELECT COUNT(*) FROM product_stocks WHERE product_id=? AND variant_id=? AND status="available"');
    $st->execute([$productId,$variantId]);
    return max(0,(int)$st->fetchColumn());
}
function assert_manual_stock_reservable_variant(int $productId, int $variantId, int $qty): void {
    global $pdo;
    $qty=max(1,$qty);
    $st=$pdo->prepare('SELECT v.manual_stock,p.name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=? AND v.product_id=? FOR UPDATE');
    $st->execute([$variantId,$productId]);
    $variant=$st->fetch();
    if(!$variant) throw new RuntimeException('Varian produk tidak ditemukan.');
    $reserved=manual_reserved_stock_variant($productId,$variantId);
    $available=max(0,(int)$variant['manual_stock']-$reserved);
    if($available<$qty) throw new RuntimeException('Stok '.$variant['name'].' tidak cukup. Sisa stok: '.$available);
}

function ensure_product_terms_column(): void {
    global $pdo;
    try {
        if (!db_column_exists('products','product_terms')) {
            $pdo->exec("ALTER TABLE products ADD product_terms MEDIUMTEXT NULL AFTER description");
        }
    } catch (Throwable $e) {}
}

function ensure_product_rating_column(): void {
    global $pdo;
    try {
        if (!db_column_exists('products','rating')) {
            $pdo->exec("ALTER TABLE products ADD rating DECIMAL(2,1) NOT NULL DEFAULT 5.0 AFTER original_price");
        }
    } catch (Throwable $e) {}
}

function ensure_product_telegram_columns(): void {
    global $pdo;
    try { if (!db_column_exists('products','telegram_status')) $pdo->exec("ALTER TABLE products ADD telegram_status TINYINT(1) NOT NULL DEFAULT 1 AFTER status"); } catch (Throwable $e) {}
    try { if (!db_column_exists('products','telegram_sort_order')) $pdo->exec("ALTER TABLE products ADD telegram_sort_order INT NOT NULL DEFAULT 0 AFTER telegram_status"); } catch (Throwable $e) {}
}

function ensure_order_channel_columns(): void {
    global $pdo;
    try { if (!db_column_exists('orders','sales_channel')) $pdo->exec("ALTER TABLE orders ADD sales_channel VARCHAR(24) NOT NULL DEFAULT 'website' AFTER invoice"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','payment_fee')) $pdo->exec("ALTER TABLE orders ADD payment_fee DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','payment_total')) $pdo->exec("ALTER TABLE orders ADD payment_total DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER payment_fee"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_bot_id')) $pdo->exec("ALTER TABLE orders ADD telegram_bot_id BIGINT UNSIGNED NULL AFTER sales_channel"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_user_id')) $pdo->exec("ALTER TABLE orders ADD telegram_user_id BIGINT NULL AFTER telegram_bot_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_chat_id')) $pdo->exec("ALTER TABLE orders ADD telegram_chat_id BIGINT NULL AFTER telegram_user_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_invoice_message_id')) $pdo->exec("ALTER TABLE orders ADD telegram_invoice_message_id BIGINT NULL AFTER telegram_chat_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_delivery_status')) $pdo->exec("ALTER TABLE orders ADD telegram_delivery_status VARCHAR(24) NOT NULL DEFAULT 'not_applicable' AFTER telegram_invoice_message_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_delivery_attempts')) $pdo->exec("ALTER TABLE orders ADD telegram_delivery_attempts INT NOT NULL DEFAULT 0 AFTER telegram_delivery_status"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_last_error')) $pdo->exec("ALTER TABLE orders ADD telegram_last_error TEXT NULL AFTER telegram_delivery_attempts"); } catch (Throwable $e) {}
    try { if (!db_column_exists('orders','telegram_delivered_at')) $pdo->exec("ALTER TABLE orders ADD telegram_delivered_at DATETIME NULL AFTER telegram_last_error"); } catch (Throwable $e) {}
}

function ensure_stock_reservation_columns(): void {
    global $pdo;
    static $done=false;
    if ($done) return;
    $done=true;
    try {
        $st=$pdo->query("SHOW COLUMNS FROM product_stocks LIKE 'status'");
        $col=$st ? $st->fetch() : null;
        if ($col && stripos((string)($col['Type'] ?? ''), "'reserved'")===false) {
            $pdo->exec("ALTER TABLE product_stocks MODIFY status ENUM('available','reserved','sold','void') NOT NULL DEFAULT 'available'");
        }
    } catch (Throwable $e) {}
    try { if (!db_column_exists('product_stocks','reserved_order_id')) $pdo->exec("ALTER TABLE product_stocks ADD reserved_order_id INT NULL AFTER status"); } catch (Throwable $e) {}
    try { if (!db_column_exists('product_stocks','reserved_order_item_id')) $pdo->exec("ALTER TABLE product_stocks ADD reserved_order_item_id INT NULL AFTER reserved_order_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('product_stocks','reserved_at')) $pdo->exec("ALTER TABLE product_stocks ADD reserved_at DATETIME NULL AFTER reserved_order_item_id"); } catch (Throwable $e) {}
    try { if (!db_column_exists('product_stocks','reserved_until')) $pdo->exec("ALTER TABLE product_stocks ADD reserved_until DATETIME NULL AFTER reserved_at"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE product_stocks ADD KEY idx_stock_reservation (reserved_order_id,reserved_order_item_id,status)"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE product_stocks ADD KEY idx_stock_reserved_until (status,reserved_until)"); } catch (Throwable $e) {}
    try {
        $pdo->exec("UPDATE product_stocks ps JOIN orders o ON o.id=ps.reserved_order_id SET ps.status='available',ps.reserved_order_id=NULL,ps.reserved_order_item_id=NULL,ps.reserved_at=NULL,ps.reserved_until=NULL WHERE ps.status='reserved' AND ps.reserved_until IS NOT NULL AND (o.status IN ('failed','cancelled') OR (o.status='pending' AND ((LOWER(COALESCE(o.payment_provider,''))='casaku' AND ps.reserved_until<DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (LOWER(COALESCE(o.payment_provider,''))<>'casaku' AND ps.reserved_until<NOW()))))");
        $pdo->exec("UPDATE orders SET status='failed' WHERE status='pending' AND payment_expires_at IS NOT NULL AND ((LOWER(COALESCE(payment_provider,''))='casaku' AND payment_expires_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (LOWER(COALESCE(payment_provider,''))<>'casaku' AND payment_expires_at<NOW()))");
    } catch (Throwable $e) {}
}

function reserve_order_item_stocks(int $orderId, int $orderItemId, int $productId, int $qty, ?string $expiresAt=null, int $variantId=0): array {
    global $pdo;
    ensure_stock_reservation_columns();
    if ($qty < 1) return [];
    if ($variantId <= 0) throw new RuntimeException('Varian produk wajib dipilih untuk reservasi stok.');
    $st=$pdo->prepare('SELECT id FROM product_stocks WHERE product_id=? AND status="available" AND variant_id=? ORDER BY id ASC LIMIT '.(int)$qty.' FOR UPDATE');
    $st->execute([$productId,$variantId]);
    $ids=array_map('intval',array_column($st->fetchAll(),'id'));
    if (count($ids) < $qty) throw new RuntimeException('Stok digital tidak cukup untuk menyelesaikan pesanan.');
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $params=[$orderId,$orderItemId,$expiresAt ?: date('Y-m-d H:i:s', time()+900),...$ids];
    $up=$pdo->prepare("UPDATE product_stocks SET status='reserved',reserved_order_id=?,reserved_order_item_id=?,reserved_at=NOW(),reserved_until=? WHERE status='available' AND id IN ($ph)");
    $up->execute($params);
    if ($up->rowCount() !== count($ids)) throw new RuntimeException('Sebagian stok baru saja digunakan pembeli lain. Silakan ulangi checkout.');
    return $ids;
}

function release_order_stock_reservations(int $orderId): int {
    global $pdo;
    ensure_stock_reservation_columns();
    $st=$pdo->prepare("UPDATE product_stocks SET status='available',reserved_order_id=NULL,reserved_order_item_id=NULL,reserved_at=NULL,reserved_until=NULL WHERE reserved_order_id=? AND status='reserved'");
    $st->execute([$orderId]);
    return $st->rowCount();
}

function expire_pending_orders(int $limit=100): int {
    global $pdo;
    ensure_stock_reservation_columns();
    $limit=max(1,min(500,$limit));
    $ids=$pdo->query("SELECT id FROM orders WHERE status='pending' AND payment_expires_at IS NOT NULL AND ((LOWER(COALESCE(payment_provider,''))='casaku' AND payment_expires_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (LOWER(COALESCE(payment_provider,''))<>'casaku' AND payment_expires_at<NOW())) ORDER BY id ASC LIMIT $limit")->fetchAll();
    $count=0;
    foreach($ids as $row){
        $oid=(int)$row['id'];
        $pdo->beginTransaction();
        try{
            $st=$pdo->prepare("UPDATE orders SET status='failed' WHERE id=? AND status='pending'");
            $st->execute([$oid]);
            if($st->rowCount()){
                release_order_stock_reservations($oid);
                $count++;
            }
            $pdo->commit();
        }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); }
    }
    return $count;
}

function ensure_product_extra_columns(): void {
    ensure_order_security_columns();
    ensure_product_warranty_column();
    ensure_product_delivery_column();
    ensure_product_manual_stock_column();
    ensure_product_terms_column();
    ensure_product_rating_column();
    ensure_product_telegram_columns();
    ensure_order_channel_columns();
    ensure_stock_reservation_columns();
    ensure_product_variant_columns();
    ensure_product_reviews_table();
    ensure_product_default_variants();
}

function admin_password_crypto_key(): string {
    global $config;
    $appKey = trim((string)($config['app_key'] ?? ''));
    if ($appKey !== '') return hash('sha256', 'digital-store-app-key|'.$appKey, true);
    // Kompatibilitas untuk instalasi lama yang belum memiliki app_key.
    $seed = 'digital-store-admin-password|'.($config['db_name'] ?? '').'|'.($config['db_user'] ?? '').'|'.($config['db_pass'] ?? '');
    return hash('sha256', $seed, true);
}
function encrypt_admin_order_password(string $plain): string {
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) return 'plain:'.base64_encode($plain);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', admin_password_crypto_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return 'plain:'.base64_encode($plain);
    return 'v1:'.base64_encode($iv.$cipher);
}
function decrypt_admin_order_password(?string $payload): string {
    $payload = (string)$payload;
    if ($payload === '') return '';
    if (str_starts_with($payload, 'plain:')) return (string)base64_decode(substr($payload, 6), true);
    if (!str_starts_with($payload, 'v1:') || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($payload, 3), true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', admin_password_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
function cleanup_legacy_order_admin_pin_setting(): void {
    global $pdo;
    try {
        $st = $pdo->prepare('DELETE FROM settings WHERE `key`=?');
        $st->execute(['admin_order_password_pin_hash']);
    } catch (Throwable $e) {}
}
function store_admin_order_password(int $orderId, string $plain): void {
    global $pdo;
    ensure_order_security_columns();
    $pdo->prepare('UPDATE orders SET access_password_admin_enc=? WHERE id=?')->execute([encrypt_admin_order_password($plain), $orderId]);
}
function get_admin_order_password(array $order): string {
    return decrypt_admin_order_password($order['access_password_admin_enc'] ?? '');
}
function ensure_order_access_attempts_table(): void {
    global $pdo;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS order_access_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(80) NOT NULL, lookup_key VARCHAR(190) NOT NULL, action VARCHAR(40) NOT NULL DEFAULT 'password', attempts INT DEFAULT 0, locked_until DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uniq_access_attempt (ip,lookup_key,action)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}
function order_access_key(string $raw): string { return hash('sha256', strtolower(trim($raw))); }
function order_access_blocked(string $raw, string $action='password'): bool {
    global $pdo; ensure_order_access_attempts_table();
    $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); $key=order_access_key($raw);
    $st=$pdo->prepare('SELECT locked_until FROM order_access_attempts WHERE ip=? AND lookup_key=? AND action=? LIMIT 1'); $st->execute([$ip,$key,$action]); $r=$st->fetch();
    return $r && !empty($r['locked_until']) && strtotime($r['locked_until'])>time();
}
function record_order_access_fail(string $raw, string $action='password', int $limit=5, int $lockSeconds=900): void {
    global $pdo; ensure_order_access_attempts_table();
    $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); $key=order_access_key($raw);
    $st=$pdo->prepare('SELECT attempts FROM order_access_attempts WHERE ip=? AND lookup_key=? AND action=? LIMIT 1'); $st->execute([$ip,$key,$action]); $r=$st->fetch();
    $attempts=((int)($r['attempts'] ?? 0))+1; $locked=$attempts >= $limit ? date('Y-m-d H:i:s', time()+$lockSeconds) : null;
    $pdo->prepare('INSERT INTO order_access_attempts(ip,lookup_key,action,attempts,locked_until,updated_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts), locked_until=VALUES(locked_until), updated_at=NOW()')->execute([$ip,$key,$action,$attempts,$locked]);
}
function clear_order_access_fail(string $raw, string $action='password'): void {
    global $pdo; ensure_order_access_attempts_table();
    $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); $key=order_access_key($raw);
    $pdo->prepare('DELETE FROM order_access_attempts WHERE ip=? AND lookup_key=? AND action=?')->execute([$ip,$key,$action]);
}

function ensure_rate_limits_table(): void {
    global $pdo;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS security_rate_limits (id INT AUTO_INCREMENT PRIMARY KEY, bucket VARCHAR(80) NOT NULL, ip VARCHAR(80) NOT NULL, identity_hash VARCHAR(190) NOT NULL, attempts INT DEFAULT 0, reset_at DATETIME NOT NULL, updated_at DATETIME NULL, UNIQUE KEY uniq_rate_limit (bucket,ip,identity_hash), KEY idx_reset_at (reset_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}
function rate_limit_hit(string $bucket, string $identity='', int $limit=10, int $windowSeconds=600): bool {
    global $pdo; ensure_rate_limits_table();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $hash = order_access_key($identity !== '' ? $identity : $ip);
    $now = time();
    $resetAt = date('Y-m-d H:i:s', $now + $windowSeconds);
    try { $pdo->prepare('DELETE FROM security_rate_limits WHERE reset_at < NOW()')->execute(); } catch (Throwable $e) {}
    $st = $pdo->prepare('SELECT attempts, reset_at FROM security_rate_limits WHERE bucket=? AND ip=? AND identity_hash=? LIMIT 1');
    $st->execute([$bucket,$ip,$hash]);
    $row = $st->fetch();
    if(!$row || strtotime((string)$row['reset_at']) < $now){
        $pdo->prepare('INSERT INTO security_rate_limits(bucket,ip,identity_hash,attempts,reset_at,updated_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE attempts=1, reset_at=VALUES(reset_at), updated_at=NOW()')->execute([$bucket,$ip,$hash,1,$resetAt]);
        return false;
    }
    $attempts = (int)$row['attempts'] + 1;
    $pdo->prepare('UPDATE security_rate_limits SET attempts=?, updated_at=NOW() WHERE bucket=? AND ip=? AND identity_hash=?')->execute([$attempts,$bucket,$ip,$hash]);
    return $attempts > $limit;
}
function ensure_payment_audit_table(): void {
    global $pdo;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS payment_audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, invoice VARCHAR(80) NULL, reference VARCHAR(190) NULL, provider VARCHAR(40) NOT NULL DEFAULT 'tokopay', event_status VARCHAR(80) NULL, amount DECIMAL(12,2) NULL, ip VARCHAR(80) NULL, valid_signature TINYINT(1) DEFAULT 0, message VARCHAR(255) NULL, payload MEDIUMTEXT NULL, created_at DATETIME NOT NULL, KEY idx_invoice (invoice), KEY idx_reference (reference)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}
function log_payment_audit(?string $invoice, ?string $reference, ?string $status, $amount, bool $valid, string $message, array $payload=[], string $provider='tokopay'): void {
    global $pdo; ensure_payment_audit_table();
    $provider=preg_replace('/[^a-z0-9_-]/i','',strtolower(trim($provider))) ?: 'unknown';
    try { $ip=(string)($_SERVER['REMOTE_ADDR'] ?? ''); $json=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $pdo->prepare('INSERT INTO payment_audit_logs(invoice,reference,provider,event_status,amount,ip,valid_signature,message,payload,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')->execute([$invoice,$reference,$provider,$status,$amount,$ip,$valid?1:0,mb_substr($message,0,255),$json]); } catch (Throwable $e) {}
}
function ensure_payment_webhook_events_table(): void {
    global $pdo;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS payment_webhook_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(40) NOT NULL DEFAULT 'tokopay', event_key CHAR(64) NOT NULL, invoice VARCHAR(80) NULL, reference VARCHAR(190) NULL, event_status VARCHAR(80) NULL, amount DECIMAL(14,2) NULL, payload_hash CHAR(64) NULL, process_status ENUM('processing','processed','failed') NOT NULL DEFAULT 'processing', attempts INT NOT NULL DEFAULT 1, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, processed_at DATETIME NULL, last_error VARCHAR(500) NULL, UNIQUE KEY uq_webhook_event (provider,event_key), KEY idx_webhook_invoice (invoice), KEY idx_webhook_reference (reference), KEY idx_webhook_process (process_status,last_seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}
function payment_webhook_event_key(array $verified, array $rawData=[], string $provider='tokopay'): string {
    $provider=preg_replace('/[^a-z0-9_-]/i','',strtolower(trim($provider))) ?: 'unknown';
    $amount=$verified['amount'] ?? '';
    if(is_numeric($amount)) $amount=number_format((float)$amount,2,'.','');
    $base=$provider.'|'.trim((string)($verified['invoice'] ?? '')).'|'.trim((string)($verified['reference'] ?? '')).'|'.strtolower(trim((string)($verified['status'] ?? ''))).'|'.$amount;
    if(trim((string)($verified['reference'] ?? ''))===''){
        $base.='|'.hash('sha256',json_encode($rawData,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    return hash('sha256',$base);
}
function claim_payment_webhook_event(array $verified, array $rawData=[], string $provider='tokopay'): array {
    global $pdo;
    ensure_payment_webhook_events_table();
    $provider=preg_replace('/[^a-z0-9_-]/i','',strtolower(trim($provider))) ?: 'unknown';
    $key=payment_webhook_event_key($verified,$rawData,$provider);
    $payloadHash=hash('sha256',json_encode($rawData,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $amount=$verified['amount'] ?? null;
    if($amount!==null && $amount!=='' && !is_numeric($amount)) $amount=null;
    try{
        $pdo->prepare("INSERT INTO payment_webhook_events(provider,event_key,invoice,reference,event_status,amount,payload_hash,process_status,attempts,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,'processing',1,NOW(),NOW())")
            ->execute([$provider,$key,(string)($verified['invoice'] ?? ''),(string)($verified['reference'] ?? ''),(string)($verified['status'] ?? ''),$amount,$payloadHash]);
        return ['event_key'=>$key,'claimed'=>true,'duplicate'=>false,'status'=>'processing'];
    }catch(Throwable $e){
        $st=$pdo->prepare("SELECT process_status,last_seen_at FROM payment_webhook_events WHERE provider=? AND event_key=? LIMIT 1");$st->execute([$provider,$key]);$row=$st->fetch();
        if(!$row) throw $e;
        if((string)$row['process_status']==='failed' || ((string)$row['process_status']==='processing' && !empty($row['last_seen_at']) && strtotime((string)$row['last_seen_at'])<time()-120)){
            $up=$pdo->prepare("UPDATE payment_webhook_events SET process_status='processing',attempts=attempts+1,last_seen_at=NOW(),last_error=NULL WHERE provider=? AND event_key=? AND (process_status='failed' OR (process_status='processing' AND last_seen_at<DATE_SUB(NOW(),INTERVAL 120 SECOND)))");
            $up->execute([$provider,$key]);
            if($up->rowCount()) return ['event_key'=>$key,'claimed'=>true,'duplicate'=>false,'status'=>'processing'];
        }
        $pdo->prepare("UPDATE payment_webhook_events SET last_seen_at=NOW(),attempts=attempts+1 WHERE provider=? AND event_key=?")->execute([$provider,$key]);
        return ['event_key'=>$key,'claimed'=>false,'duplicate'=>true,'status'=>(string)$row['process_status']];
    }
}
function complete_payment_webhook_event(string $eventKey, string $provider='tokopay'): void {
    global $pdo; if($eventKey==='')return; ensure_payment_webhook_events_table();
    $provider=preg_replace('/[^a-z0-9_-]/i','',strtolower(trim($provider))) ?: 'unknown';
    $pdo->prepare("UPDATE payment_webhook_events SET process_status='processed',processed_at=COALESCE(processed_at,NOW()),last_seen_at=NOW(),last_error=NULL WHERE provider=? AND event_key=?")->execute([$provider,$eventKey]);
}
function fail_payment_webhook_event(string $eventKey, string $error, string $provider='tokopay'): void {
    global $pdo; if($eventKey==='')return; ensure_payment_webhook_events_table();
    $provider=preg_replace('/[^a-z0-9_-]/i','',strtolower(trim($provider))) ?: 'unknown';
    $pdo->prepare("UPDATE payment_webhook_events SET process_status='failed',last_seen_at=NOW(),last_error=? WHERE provider=? AND event_key=?")->execute([mb_substr($error,0,500),$provider,$eventKey]);
}
function tokopay_callback_ip_allowed(): bool {
    $allow = trim(setting('tokopay_callback_ip_whitelist','0'));
    if ($allow === '0') return true;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $list = trim(setting('tokopay_callback_ips',''));
    $allowed = array_filter(array_map('trim', preg_split('/[,\s]+/', $list)));
    return $ip !== '' && in_array($ip, $allowed, true);
}
function tokopay_expired_minutes(): int {
    $v = (int)setting('tokopay_expired_minutes','5');
    if ($v < 1) $v = 5;
    if ($v > 1440) $v = 1440;
    return $v;
}
function maintenance_mode_enabled(): bool {
    return trim((string)setting('maintenance_mode','0')) === '1';
}
function maintenance_mode_message(): string {
    $message=trim((string)setting('maintenance_message','Checkout sedang dalam pemeliharaan. Silakan coba kembali beberapa saat lagi.'));
    return $message!==''?$message:'Checkout sedang dalam pemeliharaan. Silakan coba kembali beberapa saat lagi.';
}

function tokopay_callback_payload(array $data): array {
    $bodyData = is_array($data['data'] ?? null) ? $data['data'] : [];
    $get = function(array $keys, string $default='') use ($data, $bodyData): string {
        foreach($keys as $k){
            if(isset($data[$k]) && $data[$k] !== '') return (string)$data[$k];
            if(isset($bodyData[$k]) && $bodyData[$k] !== '') return (string)$bodyData[$k];
        }
        return $default;
    };
    $amount = null;
    foreach(['total_dibayar','amount','nominal','gross_amount','total'] as $k){
        if(isset($data[$k]) && $data[$k] !== '') { $amount = $data[$k]; break; }
        if(isset($bodyData[$k]) && $bodyData[$k] !== '') { $amount = $bodyData[$k]; break; }
    }
    return [
        'invoice' => $get(['reff_id','ref_id','merchant_ref','merchant_order_id','invoice','order_id']),
        'reference' => $get(['reference','trx_id','pay_ref','payment_reference','id'], 'tokopay_webhook'),
        'status' => strtolower(trim($get(['status','payment_status','transaction_status','status_text']))),
        'merchant_id' => $get(['merchant_id','merchant']),
        'signature' => $get(['signature','sign','signature_key','sig']),
        'amount' => $amount,
        'data' => $bodyData,
    ];
}
function assert_payment_reference_available(string $provider, string $reference, int $orderId): void {
    global $pdo;
    $provider=strtolower(trim($provider));
    $reference=trim($reference);
    if($provider==='' || $reference==='') throw new RuntimeException('Reference payment gateway kosong.');
    $st=$pdo->prepare('SELECT id FROM orders WHERE payment_provider=? AND payment_reference=? AND id<>? LIMIT 1');
    $st->execute([$provider,$reference,$orderId]);
    if($st->fetchColumn()) throw new RuntimeException('Reference payment gateway sudah digunakan oleh order lain.');
}

function tokopay_status_is_success(string $status): bool {
    $status = strtolower(trim($status));
    if(in_array($status, ['success','completed','paid','settlement','berhasil','sukses','settled','capture'], true)) return true;
    return $status !== '' && (str_contains($status, 'success') || str_contains($status, 'sukses') || str_contains($status, 'berhasil') || str_contains($status, 'paid'));
}
function display_datetime_gmt7(?string $dt): string {
    if(!$dt) return '-';
    try{
        // Koneksi database aplikasi menggunakan session timezone +07:00, jadi jangan menambah 7 jam lagi.
        $d = new DateTime($dt, new DateTimeZone('Asia/Jakarta'));
        return $d->format('d/m/Y H:i').' WIB';
    }catch(Throwable $e){ return (string)$dt; }
}

function random_access_password(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghijkmnpqrstuvwxyz';
    $out = '';
    for ($i=0;$i<14;$i++) $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
    return substr($out,0,4).'-'.substr($out,4,5).'-'.substr($out,9,5);
}
function store_order_access_session(string $invoice, string $password): void {
    ensure_storefront_session();
    $_SESSION['order_access'][$invoice] = $password;
}
function session_order_access(string $invoice): string {
    ensure_storefront_session();
    return (string)($_SESSION['order_access'][$invoice] ?? '');
}
function verify_order_access(array $order, string $password): bool {
    $hash = (string)($order['access_password_hash'] ?? '');
    if ($hash === '') return true;
    return $password !== '' && password_verify($password, $hash);
}

function product_rating_state(float $rating): string {
    $rating = max(0, min(5, $rating));
    if ($rating < 2.0) return 'empty';
    if ($rating < 4.7) return 'half';
    return 'full';
}
function render_product_rating(float $rating, int $sold = 0, int $reviews = 0): string {
    static $iconIndex = 0;
    $iconIndex++;

    $rating = max(0, min(5, $rating));
    $label = number_format($rating, 1);
    $state = product_rating_state($rating);
    $path = 'M12 2.7l2.82 5.72 6.31.92-4.56 4.44 1.08 6.28L12 17.1l-5.65 2.96 1.08-6.28-4.56-4.44 6.31-.92L12 2.7z';

    if ($state === 'full') {
        $star = '<svg class="rating-star is-full" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'.$path.'" fill="currentColor" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round"/></svg>';
    } elseif ($state === 'half') {
        $clipId = 'rating-half-'.$iconIndex;
        $star = '<svg class="rating-star is-half" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
              . '<defs><clipPath id="'.$clipId.'"><rect x="0" y="0" width="12" height="24"/></clipPath></defs>'
              . '<path d="'.$path.'" class="rating-star-base" stroke-width="1.1" stroke-linejoin="round"/>'
              . '<path d="'.$path.'" class="rating-star-fill" clip-path="url(#'.$clipId.')" stroke-width="1.1" stroke-linejoin="round"/>'
              . '</svg>';
    } else {
        $star = '<svg class="rating-star is-empty" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'.$path.'" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    }

    /* ⭐ 5.0 • 0 Sold — one nowrap row; review count lives in the aria-label only */
    $soldLabel = t('{n} Terjual', ['n' => number_format($sold)]);

    return '<div class="product-rating rating-'.$state.'" aria-label="Rating '.$label.' dari 5, '.$soldLabel.', '.number_format($reviews).' ulasan">'
        .'<span class="product-rating-score">'.$star.'<b>'.$label.'</b></span>'
        .'<span class="product-rating-dot" aria-hidden="true">•</span>'
        .'<span class="product-sold-inline">'.$soldLabel.'</span>'
        .'</div>';
}

/**
 * Card badge copy: biggest per-variant discount percent, e.g. "🏷️ 50%".
 */
function storefront_discount_badge(array $item): string {
    $pct = 0;
    if (isset($item['maxDiscountPct'])) $pct = (int)$item['maxDiscountPct'];
    elseif (isset($item['discountPct'])) $pct = (int)$item['discountPct'];
    if ($pct <= 0) {
        $raw = $item['discount'] ?? '';
        if (is_numeric($raw)) {
            $pct = (int)round((float)$raw);
        } else {
            $s = trim((string)$raw);
            if ($s !== '' && preg_match('/(\d+)\s*%/u', $s, $m)) $pct = (int)$m[1];
        }
    }
    if ($pct <= 0) {
        $price = (float)($item['price'] ?? 0);
        $original = (float)($item['originalPrice'] ?? 0);
        if ($original > $price && $price > 0) $pct = (int)round((1 - $price / $original) * 100);
    }
    return $pct > 0 ? '🏷️ '.$pct.'%' : '';
}
/**
 * Shared storefront product card — homepage look, usable on any page.
 * $item: id, name, slug, price, originalPrice, rating, sold, thumbnail, category?, discount?, maxDiscountPct?
 * $opts: show_wishlist (default true), show_category (default false), grid_class
 */
function storefront_product_card(array $item, array $opts = []): string {
    $id = (int)($item['id'] ?? 0);
    $name = trim((string)($item['name'] ?? ''));
    $slug = trim((string)($item['slug'] ?? ''));
    $href = $slug !== '' ? '/'.rawurlencode($slug) : '#';
    $price = (float)($item['price'] ?? 0);
    $original = (float)($item['originalPrice'] ?? 0);
    if ($original <= 0 || $original < $price) {
        $original = max($price, (float)($item['regularPrice'] ?? 0));
    }
    $rating = max(0, min(5, (float)($item['rating'] ?? 0)));
    $sold = (int)($item['sold'] ?? 0);
    $reviews = (int)($item['reviews'] ?? 0);
    $thumb = trim((string)($item['thumbnail'] ?? ''));
    $category = trim((string)($item['category'] ?? ''));
    /* Badge = biggest discounted-variant amount, even when the cheapest variant itself has no slash */
    $badge = storefront_discount_badge($item);
    $showWishlist = (bool)($opts['show_wishlist'] ?? true);
    $showCategory = (bool)($opts['show_category'] ?? false);

    $thumbHtml = storefront_product_thumb($thumb, $name);
    $wishlist = '';
    if ($showWishlist && $id > 0) {
        $wishlist = '<button class="product-wishlist-btn" type="button" data-wishlist-toggle="'.$id.'" aria-label="'.e(t('Tambahkan {name} ke wishlist', ['name'=>$name])).'" aria-pressed="false"><span>♡</span></button>';
    }
    $catHtml = ($showCategory && $category !== '') ? '<span class="grid-category-label">'.e($category).'</span>' : '';
    $del = ($original > $price && $original > 0) ? '<del>'.rupiah($original).'</del>' : '';
    /* hasStock=false → all variants sold out; button already marks it, so no stock pill on the rating row */
    $isOut = array_key_exists('hasStock', $item) && !$item['hasStock'];
    $btnClass = $isOut ? 'grid-cart-btn is-out' : 'grid-cart-btn';
    $btnLabel = $isOut ? t('Habis') : t('Beli');
    $btnExtra = $isOut ? ' aria-disabled="true"' : '';

    return '<article class="product-card znl-product-card" data-product-card data-id="'.$id.'">'
        .'<div class="thumb znl-card-thumb">'
            .($badge !== '' ? '<em>'.e($badge).'</em>' : '')
            .$thumbHtml
            .$wishlist
        .'</div>'
        .'<div class="product-main znl-card-body">'
            .$catHtml
            .'<a class="product-title" href="'.$href.'">'.e($name).'</a>'
            .render_product_rating($rating, $sold, $reviews)
            .'<div class="product-price-action-row">'
                .'<div class="product-price-row"><strong>'.rupiah($price).'</strong>'.$del.'</div>'
                .'<a class="'.$btnClass.'" href="'.$href.'"'.$btnExtra.' aria-label="'.e(t('Lihat Produk & Pilih Varian')).': '.e($name).'">'.e($btnLabel).'</a>'
            .'</div>'
        .'</div>'
    .'</article>';
}

/** Wrap multiple storefront_product_card() outputs in a portable grid. */
function storefront_product_grid(array $items, array $opts = []): string {
    $gridClass = trim((string)($opts['grid_class'] ?? ''));
    $class = trim('sf-card-grid '.$gridClass);
    $html = '';
    foreach ($items as $item) {
        $html .= storefront_product_card($item, $opts);
    }
    if ($html === '') return '';
    return '<div class="'.$class.'">'.$html.'</div>';
}

function storefront_product_thumb(string $src, string $label = 'Produk Digital'): string {
    $src = trim($src);
    $fallback = trim($label);
    if ($fallback === '') $fallback = 'Produk Digital';
    if ($src === '') {
        return '<b class="ds-default-thumb" aria-label="Produk tanpa thumbnail: '.e($fallback).'"><span>'.e($fallback).'</span></b>';
    }
    return '<img class="ds-product-thumb-img" src="'.e($src).'" alt="Thumbnail produk '.e($fallback).'" loading="lazy" width="600" height="600">';
}

function render_storefront_styles(string $page='index'): void {
    $v = '20261029-ui-v1';
    echo '<link rel="stylesheet" href="/assets/css/front-mobile-v7.css?v='.$v.'">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/product-grid-v20.css?v='.$v.'">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/product-list-fresh-v1.css?v='.$v.'">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/category-label-v1.css?v='.$v.'">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/product-grid-design-v3.css?v='.$v.'">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/product-grid-redesign-v2.css?v='.$v.'">' . "\n";
    if ($page === 'checkout') {
        echo '<link rel="stylesheet" href="/assets/css/checkout-v24.css?v='.$v.'">' . "\n";
    }
    if ($page === 'product') {
        echo '<link rel="stylesheet" href="/assets/css/product-page-v1.css?v='.$v.'">' . "\n";
    }
    // Theme CSS — always default for now; new themes can be added later
    echo '<link rel="stylesheet" href="/assets/css/themes/default-indigo.css?v='.$v.'">' . "\n";
}

function safe_footer_html(string $html): string {
    $allowed = '<a><b><strong><i><em><br><p><small><span><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre><div>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/iu', '', $html);
    $html = preg_replace('/\s(href|src)\s*=\s*(["\'])\s*javascript:.*?\2/iu', ' $1="#"', $html);
    return $html;
}
function footer_clean_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '#';
    if ($url[0] === '/' || $url[0] === '#') return $url;
    if (preg_match('~^https?://~i', $url)) return $url;
    if (filter_var($url, FILTER_VALIDATE_EMAIL)) return 'mailto:'.$url;
    return '#';
}
function footer_link_lines(string $raw): array {
    $links = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 2));
        $label = $parts[0] ?? '';
        $url = $parts[1] ?? '#';
        if ($label === '') continue;
        $links[] = ['label' => $label, 'url' => footer_clean_url($url)];
    }
    return $links;
}
function render_footer_columns(string $site='', string $desc=''): void {
    $siteSafe = e($site ?: 'Digital Store');
    $year = date('Y');

    $brandTitle = trim((string)setting('footer_brand_title', $site ?: 'Digital Store'));
    $brandDesc = trim((string)setting('footer_brand_desc', $desc ?: 'Toko online produk digital dengan pengiriman cepat dan aman.'));
    $brandTitleSafe = e($brandTitle !== '' ? $brandTitle : ($site ?: 'Digital Store'));
    $brandDescSafe = e($brandDesc !== '' ? $brandDesc : ($desc ?: 'Toko online produk digital dengan pengiriman cepat dan aman.'));

    $facebook = footer_clean_url((string)setting('footer_facebook_url', '#'));
    $telegram = footer_clean_url((string)setting('footer_telegram_url', '#'));
    $whatsapp = footer_clean_url((string)setting('footer_whatsapp_url', '#'));

    $helpTitle = trim((string)setting('footer_help_title', 'Menu Bantuan')) ?: 'Menu Bantuan';
    $helpRaw = (string)setting('footer_help_links', "Syarat Layanan|/page.php?slug=syarat-layanan\nKebijakan Pengembalian|/page.php?slug=kebijakan-pengembalian\nHubungi Kami|/page.php?slug=hubungi-kami");
    $helpLinks = footer_link_lines($helpRaw);
    if (!$helpLinks) {
        $helpLinks = [
            ['label' => 'Syarat Layanan', 'url' => '/page.php?slug=syarat-layanan'],
            ['label' => 'Kebijakan Pengembalian', 'url' => '/page.php?slug=kebijakan-pengembalian'],
            ['label' => 'Hubungi Kami', 'url' => '/page.php?slug=hubungi-kami'],
        ];
    }

    $contactTitle = trim((string)setting('footer_contact_title', 'Kontak Kami')) ?: 'Kontak Kami';
    $contactEmail = trim((string)setting('footer_contact_email', ''));
    $contactHours = trim((string)setting('footer_contact_hours', 'Setiap hari 08:00 - 22:00 WIB'));
    $contactLocation = trim((string)setting('footer_contact_location', 'Indonesia'));

    echo '<footer class="znl-footer" aria-label="Footer website"><div class="ds-shell"><div class="znl-footer-card">';
    echo '<div class="znl-footer-grid">';

    echo '<section class="znl-footer-brand" aria-label="Informasi brand">';
    echo '<div class="znl-footer-brand-head"><span class="znl-footer-mark">DS</span><div><h3>'.$brandTitleSafe.'</h3><p>'.$brandDescSafe.'</p></div></div>';
    echo '<div class="znl-footer-social" aria-label="Media sosial">';
    echo '<a href="'.e($facebook).'" target="_blank" rel="noopener"><span>f</span><b>Facebook</b></a>';
    echo '<a href="'.e($telegram).'" target="_blank" rel="noopener"><span>➤</span><b>Telegram</b></a>';
    echo '<a href="'.e($whatsapp).'" target="_blank" rel="noopener"><span>☘</span><b>WhatsApp</b></a>';
    echo '</div></section>';

    echo '<section class="znl-footer-col" aria-label="Menu bantuan"><h3>'.e($helpTitle).'</h3><div class="znl-footer-links">';
    $icons = ['◷','↻','◌','•'];
    foreach ($helpLinks as $idx => $link) {
        echo '<a href="'.e($link['url']).'"><span>'.e($icons[$idx] ?? '•').'</span><b>'.e($link['label']).'</b></a>';
    }
    echo '</div></section>';

    echo '<section class="znl-footer-col" aria-label="Kontak kami"><h3>'.e($contactTitle).'</h3><div class="znl-footer-contact">';
    if ($contactEmail !== '') echo '<a href="mailto:'.e($contactEmail).'"><span>✉</span><b>'.e($contactEmail).'</b></a>';
    if ($contactHours !== '') echo '<p><span>◷</span><b>'.e($contactHours).'</b></p>';
    if ($contactLocation !== '') echo '<p><span>⌖</span><b>'.e($contactLocation).'</b></p>';
    echo '</div></section>';

    echo '</div>';
    echo '<div class="znl-footer-bottom"><span>© '.$year.' '.$siteSafe.'. All rights reserved.</span><span>Digital commerce platform</span></div>';
    echo '</div></div></footer>';
}

function order_items($order_id){ global $pdo; $st=$pdo->prepare('SELECT * FROM order_items WHERE order_id=?'); $st->execute([$order_id]); return $st->fetchAll(); }
function trigger_order_contact_delivery(array $order): void {
    require_once __DIR__.'/order_delivery.php';
    if (defined('DEFER_EXTERNAL_DELIVERY') && DEFER_EXTERNAL_DELIVERY) {
        if (!empty($order['id']) && (string)($order['status'] ?? '') === 'delivered') order_delivery_queue_order((int)$order['id']);
        return;
    }
    order_delivery_after_fulfilled($order);
}

function process_paid_order_notifications(array $order): void {
    if (empty($order['id']) || (string)($order['status'] ?? '') !== 'delivered') return;
    try {
        require_once __DIR__.'/order_delivery.php';
        order_delivery_process_order((int)$order['id']);
    } catch (Throwable $e) {
        // Queue state already records delivery failures for retry.
    }
    try {
        if ((string)($order['sales_channel'] ?? '') === 'telegram' && function_exists('telegram_after_order_paid')) {
            telegram_after_order_paid($order);
        }
    } catch (Throwable $e) {
        // Telegram delivery has its own retry queue.
    }
}

function payment_webhook_success(array $response, array $order=[]): void {
    // On PHP-FPM, acknowledge the payment provider first, then finish slower
    // email/WhatsApp/Telegram delivery after the HTTP response is closed.
    if (function_exists('fastcgi_finish_request')) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fastcgi_finish_request();
        if ($order) process_paid_order_notifications($order);
        exit;
    }

    // Shared hosts without FastCGI keep the previous immediate-delivery behavior.
    if ($order) process_paid_order_notifications($order);
    json_out($response, 200);
}
function mark_order_paid($invoice, $reference='manual', bool $allowFailedRecovery=false){
    global $pdo;
    ensure_order_security_columns();
    ensure_product_extra_columns();

    // Stage 1: persist the verified payment first. Fulfillment happens in a
    // separate transaction so a temporary stock/delivery error can never roll
    // a genuinely paid order back to "pending/unpaid".
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT * FROM orders WHERE invoice=? FOR UPDATE');
        $st->execute([$invoice]);
        $order=$st->fetch();
        if(!$order) throw new Exception('Order tidak ditemukan');
        if($order['status']==='cancelled') throw new Exception('Order sudah dibatalkan.');
        if($order['status']==='failed' && !$allowFailedRecovery) throw new Exception('Order sudah dibatalkan atau kedaluwarsa.');
        if($order['status']==='delivered'){
            $pdo->commit();
            $order['_fulfillment_new']=false;
            trigger_order_contact_delivery($order);
            return $order;
        }
        if($order['status']!=='paid'){
            $pdo->prepare('UPDATE orders SET status="paid", payment_reference=COALESCE(NULLIF(payment_reference,""),?), paid_at=COALESCE(paid_at,NOW()) WHERE id=?')->execute([$reference,$order['id']]);
        }
        $orderId=(int)$order['id'];
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Stage 2: fulfill atomically. If anything fails here, the order intentionally
    // remains PAID and can be retried by webhook/polling/admin after the issue is fixed.
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$orderId]);
        $order=$st->fetch();
        if(!$order) throw new Exception('Order tidak ditemukan');
        if($order['status']==='delivered'){
            $pdo->commit();
            $order['_fulfillment_new']=false;
            trigger_order_contact_delivery($order);
            return $order;
        }
        if($order['status']!=='paid') throw new Exception('Status order berubah sebelum fulfillment.');

        $items=order_items($order['id']);
        foreach($items as $it){
            $pst=$pdo->prepare('SELECT delivery_type FROM products WHERE id=? FOR UPDATE');
            $pst->execute([$it['product_id']]);
            $productRow=$pst->fetch() ?: [];
            $deliveryType=strtolower((string)($productRow['delivery_type'] ?? 'instant'));
            $qty=(int)$it['qty'];
            if($deliveryType === 'manual'){
                $variantId=(int)($it['variant_id'] ?? 0);
                if($variantId<=0) throw new Exception('Varian produk tidak ditemukan untuk '.$it['product_name']);
                $up=$pdo->prepare('UPDATE product_variants SET manual_stock=manual_stock-? WHERE id=? AND manual_stock>=?');
                $up->execute([$qty,$variantId,$qty]);
                if(!$up->rowCount()) throw new Exception('Stok varian tidak cukup untuk '.$it['product_name']);
                $pdo->prepare('UPDATE products SET sold_count=sold_count+? WHERE id=?')->execute([$qty,$it['product_id']]);
                continue;
            }

            $variantId=(int)($it['variant_id'] ?? 0);
            if($variantId<=0) throw new Exception('Varian produk tidak ditemukan untuk '.$it['product_name']);
            $reserved=$pdo->prepare('SELECT * FROM product_stocks WHERE product_id=? AND reserved_order_item_id=? AND status="reserved" ORDER BY id ASC FOR UPDATE');
            $reserved->execute([$it['product_id'],$it['id']]);
            $stocks=$reserved->fetchAll();
            $need=$qty-count($stocks);
            if($need>0){
                /* Variant-exclusive: only this order item's variant may be consumed */
                $free=$pdo->prepare('SELECT * FROM product_stocks WHERE product_id=? AND variant_id=? AND status="available" ORDER BY id ASC LIMIT '.(int)$need.' FOR UPDATE');
                $free->execute([$it['product_id'],$variantId]);
                $stocks=array_merge($stocks,$free->fetchAll());
            }
            if(count($stocks) < $qty) throw new Exception('Stok digital tidak cukup untuk '.$it['product_name']);
            $stocks=array_slice($stocks,0,$qty);
            foreach($stocks as $stock){
                $up=$pdo->prepare('UPDATE product_stocks SET status="sold", sold_order_id=?, sold_at=NOW(), reserved_order_id=NULL, reserved_order_item_id=NULL, reserved_at=NULL, reserved_until=NULL WHERE id=? AND status IN ("available","reserved")');
                $up->execute([$order['id'],$stock['id']]);
                if(!$up->rowCount()) throw new Exception('Stok digital baru saja berubah. Silakan proses ulang pesanan.');
                $pdo->prepare('INSERT INTO delivered_stocks(order_item_id, stock_id, content) VALUES(?,?,?)')->execute([$it['id'],$stock['id'],$stock['content']]);
            }
            $pdo->prepare('UPDATE products SET sold_count=sold_count+? WHERE id=?')->execute([$qty,$it['product_id']]);
        }

        release_order_stock_reservations((int)$order['id']);
        activate_order_warranties((int)$order['id']);
        if(!empty($order['promo_id']) && empty($order['promo_counted_at'])){
            $pdo->prepare('UPDATE promos SET used_count=used_count+1 WHERE id=?')->execute([(int)$order['promo_id']]);
        }
        $pdo->prepare('UPDATE orders SET status="delivered", delivered_at=COALESCE(delivered_at,NOW()), promo_counted_at=CASE WHEN promo_id IS NOT NULL AND promo_counted_at IS NULL THEN NOW() ELSE promo_counted_at END WHERE id=?')->execute([$order['id']]);
        $pdo->commit();
        $st=$pdo->prepare('SELECT * FROM orders WHERE id=?');
        $st->execute([$order['id']]);
        $result=$st->fetch() ?: $order;
        $result['_fulfillment_new']=true;
        trigger_order_contact_delivery($result);
        return $result;
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        // Payment state from stage 1 is intentionally preserved.
        throw $e;
    }
}

function http_post_json($url, array $payload, int $timeout=20): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) throw new Exception('Koneksi payment gateway gagal: '.$err);
    } else {
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'=>$body,
            'timeout'=>$timeout,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code=(int)$m[1];
        if ($res === false) throw new Exception('Koneksi payment gateway gagal. Aktifkan cURL atau allow_url_fopen.');
    }
    $json = json_decode((string)$res, true);
    if (!is_array($json)) throw new Exception('Response payment gateway tidak valid: HTTP '.$code);
    $json['_http_code'] = $code;
    return $json;
}
function http_get_json(string $url, array $query=[], int $timeout=12): array {
    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $target = $url.($qs !== '' ? (str_contains($url, '?') ? '&' : '?').$qs : '');
    if (function_exists('curl_init')) {
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) throw new Exception('Koneksi payment gateway gagal: '.$err);
    } else {
        $ctx = stream_context_create(['http'=>[
            'method'=>'GET',
            'header'=>"Accept: application/json
",
            'timeout'=>$timeout,
            'ignore_errors'=>true,
        ]]);
        $res = @file_get_contents($target, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code=(int)$m[1];
        if ($res === false) throw new Exception('Koneksi payment gateway gagal. Aktifkan cURL atau allow_url_fopen.');
    }
    $json = json_decode((string)$res, true);
    if (!is_array($json)) throw new Exception('Response payment gateway tidak valid: HTTP '.$code);
    $json['_http_code'] = $code;
    return $json;
}

function create_tokopay_order(array $order, array $items=[]): array {
    $merchant = trim(setting('tokopay_merchant_id',''));
    $secret = trim(setting('tokopay_secret_key',''));
    $channel = trim(setting('tokopay_channel','QRIS')) ?: 'QRIS';
    $api = trim(setting('tokopay_api_url','https://api.tokopay.id/v1/order')) ?: 'https://api.tokopay.id/v1/order';
    if (!$merchant || !$secret) throw new Exception('Merchant ID dan Secret Key Tokopay belum diatur.');
    $siteUrl = rtrim(setting('site_url',''), '/');
    $redirect = $siteUrl ? $siteUrl.'/track-order.php?invoice='.rawurlencode($order['invoice']) : '';
    $expiredMinutes = tokopay_expired_minutes();
    $expiredTs = time() + ($expiredMinutes * 60);
    $payload = [
        'merchant_id' => $merchant,
        'kode_channel' => $channel,
        'reff_id' => $order['invoice'],
        'amount' => (int)round((float)$order['total']),
        'customer_name' => $order['buyer_name'],
        'customer_email' => $order['buyer_email'],
        'customer_phone' => $order['buyer_whatsapp'] ?: '080000000000',
        'redirect_url' => $redirect,
        'expired_ts' => $expiredTs,
        'signature' => md5($merchant.':'.$secret.':'.$order['invoice']),
    ];
    if ($items) {
        $payload['items'] = array_map(function($it){
            return [
                'product_code' => (string)($it['product_id'] ?? 'DIGITAL'),
                'name' => (string)($it['product_name'] ?? 'Produk Digital'),
                'price' => (int)round((float)($it['price'] ?? 0)),
            ];
        }, $items);
    }
    $res = http_post_json($api, $payload);
    $status = strtolower((string)($res['status'] ?? $res['message'] ?? ''));
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    if (!in_array($status, ['success','1','ok'], true) && empty($data['pay_url']) && empty($data['checkout_url']) && empty($data['qr_link']) && empty($data['qr_string'])) {
        throw new Exception('Tokopay gagal membuat transaksi: '.(($res['message'] ?? '') ?: json_encode($res)));
    }
    return $res;
}
function tokopay_check_order_verified(string $invoice, string $reference='', ?float $expectedAmount=null): array {
    $merchant = trim((string)setting('tokopay_merchant_id',''));
    $secret = trim((string)setting('tokopay_secret_key',''));
    if ($merchant === '' || $secret === '') throw new Exception('Kredensial Tokopay belum lengkap.');
    $invoice = trim($invoice);
    $reference = trim($reference);
    if ($invoice === '') throw new Exception('Invoice Tokopay kosong.');

    $query = ['merchant_id'=>$merchant, 'secret'=>$secret, 'ref_id'=>$invoice];
    if ($reference !== '' && $reference !== 'tokopay_webhook') $query['trx_id'] = $reference;
    $res = http_get_json('https://api.tokopay.id/v1/check-order', $query, 8);
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    if (!$data) throw new Exception('Status order Tokopay tidak ditemukan.');

    $remoteMerchant = trim((string)($data['merchant_id'] ?? ''));
    $remoteInvoice = trim((string)($data['reff_id'] ?? $data['ref_id'] ?? ''));
    $remoteReference = trim((string)($data['trx_id'] ?? $data['reference'] ?? ''));
    $remoteStatus = strtolower(trim((string)($data['status'] ?? '')));
    $remoteAmount = $data['jumlah_bayar'] ?? $data['total_dibayar'] ?? $data['nominal'] ?? null;

    if ($remoteMerchant !== '' && !hash_equals($merchant, $remoteMerchant)) throw new Exception('Merchant Tokopay hasil verifikasi tidak cocok.');
    if ($remoteInvoice === '' || !hash_equals($invoice, $remoteInvoice)) throw new Exception('Invoice Tokopay hasil verifikasi tidak cocok.');
    if ($reference !== '' && $reference !== 'tokopay_webhook') {
        if ($remoteReference === '' || !hash_equals($reference, $remoteReference)) throw new Exception('Reference Tokopay hasil verifikasi tidak cocok.');
    }
    if ($expectedAmount !== null) {
        if (!is_numeric($remoteAmount) || abs((float)$remoteAmount - $expectedAmount) > 0.0001) throw new Exception('Nominal Tokopay hasil verifikasi tidak cocok.');
    }

    return [
        'status'=>$remoteStatus,
        'success'=>in_array($remoteStatus, ['success','completed'], true),
        'invoice'=>$remoteInvoice,
        'reference'=>$remoteReference,
        'amount'=>is_numeric($remoteAmount) ? (float)$remoteAmount : null,
        'raw'=>$res,
    ];
}

function verify_tokopay_webhook(array $data): array {
    $merchant = trim(setting('tokopay_merchant_id',''));
    $secret = trim(setting('tokopay_secret_key',''));
    if (!$merchant || !$secret) throw new Exception('Kredensial Tokopay belum lengkap.');
    $payload = tokopay_callback_payload($data);
    $invoice = (string)$payload['invoice'];
    $signature = (string)$payload['signature'];
    $payloadMerchant = (string)$payload['merchant_id'];
    if (!$invoice) throw new Exception('Invoice kosong.');
    if ($payloadMerchant && !hash_equals($merchant, $payloadMerchant)) throw new Exception('Merchant ID webhook tidak cocok.');
    $expected = md5($merchant.':'.$secret.':'.$invoice);
    if (!$signature || !hash_equals($expected, $signature)) throw new Exception('Signature Tokopay tidak valid.');
    return ['invoice'=>$invoice,'reference'=>$payload['reference'],'status'=>$payload['status'],'data'=>$payload['data'],'amount'=>$payload['amount'],'signature_valid'=>true];
}

function ensure_admin_notification_table(): void {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notification_reads (
            admin_id INT NOT NULL PRIMARY KEY,
            last_read_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

function admin_notification_last_read(): string {
    global $pdo;
    try {
        ensure_admin_notification_table();
        $admin = admin_user();
        $adminId = (int)($admin['id'] ?? 0);
        if ($adminId <= 0) return '1970-01-01 00:00:00';
        $st = $pdo->prepare('SELECT last_read_at FROM admin_notification_reads WHERE admin_id=? LIMIT 1');
        $st->execute([$adminId]);
        $row = $st->fetch();
        return (string)($row['last_read_at'] ?? '1970-01-01 00:00:00');
    } catch (Throwable $e) { return '1970-01-01 00:00:00'; }
}

function admin_notifications_mark_read(): void {
    global $pdo;
    try {
        ensure_admin_notification_table();
        $admin = admin_user();
        $adminId = (int)($admin['id'] ?? 0);
        if ($adminId <= 0) return;
        $st = $pdo->prepare('INSERT INTO admin_notification_reads(admin_id,last_read_at,updated_at) VALUES(?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_read_at=VALUES(last_read_at), updated_at=NOW()');
        $st->execute([$adminId]);
    } catch (Throwable $e) {}
}

function admin_notification_summary(): array {
    global $pdo;
    $data = ['unread'=>0, 'items'=>[]];
    try {
        ensure_order_security_columns();
        ensure_admin_notification_table();
        $lastRead = admin_notification_last_read();
        $stUnread = $pdo->prepare("SELECT COUNT(*) c FROM orders WHERE COALESCE(paid_at,created_at) > ? AND status IN ('pending','paid','delivered')");
        $stUnread->execute([$lastRead]);
        $data['unread'] = (int)($stUnread->fetch()['c'] ?? 0);
        $st = $pdo->query("SELECT invoice,buyer_name,total,status,created_at,paid_at FROM orders WHERE status IN ('pending','paid','delivered','failed','cancelled') ORDER BY COALESCE(paid_at,created_at) DESC, id DESC LIMIT 5");
        foreach ($st->fetchAll() as $o) {
            $eventTime = (string)($o['paid_at'] ?: $o['created_at']);
            $isUnread = $eventTime > $lastRead && in_array((string)$o['status'], ['pending','paid','delivered'], true);
            $label = $o['status'] === 'pending' ? 'Menunggu pembayaran' : (($o['status'] === 'delivered' || $o['status'] === 'paid') ? 'Pembayaran sukses' : ucfirst((string)$o['status']));
            $data['items'][] = [
                'invoice' => $o['invoice'],
                'buyer' => $o['buyer_name'],
                'total' => $o['total'],
                'status' => $o['status'],
                'label' => $label,
                'time' => $eventTime,
                'unread' => $isUnread,
            ];
        }
    } catch (Throwable $e) {}
    return $data;
}

function build_public_url($path){ $base=rtrim(setting('site_url',''),'/'); return $base . $path; }
?>
