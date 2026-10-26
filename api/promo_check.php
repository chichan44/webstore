<?php
require_once __DIR__.'/../includes/helpers.php';
try{
    ensure_order_security_columns(); ensure_product_extra_columns();
    $cart=json_decode($_POST['cart']??'[]',true);
    if(!$cart || !is_array($cart)) json_out(['ok'=>false,'message'=>'Keranjang kosong'],400);
    $subtotal=0;
    foreach($cart as $row){
        $id=(int)($row['id']??0); $qty=max(1,min(99,(int)($row['qty']??1)));
        $variantId=(int)($row['variant_id']??0);
        if($variantId<=0) json_out(['ok'=>false,'code'=>'','subtotal'=>0,'discount'=>0,'total'=>0,'message'=>'Silakan pilih varian untuk produk '.$id.'.'],422);
        $st=$pdo->prepare('SELECT p.* FROM products p WHERE p.id=? AND p.status=1');
        $st->execute([$id]); $p=$st->fetch();
        if(!$p) json_out(['ok'=>false,'message'=>'Produk tidak ditemukan atau sedang nonaktif'],404);
        $st=$pdo->prepare('SELECT * FROM product_variants WHERE id=? AND product_id=? AND status=1 LIMIT 1');
        $st->execute([$variantId,(int)$p['id']]); $variant=$st->fetch();
        if(!$variant) json_out(['ok'=>false,'code'=>'','subtotal'=>0,'discount'=>0,'total'=>0,'message'=>'Varian produk tidak ditemukan atau tidak aktif.'],422);
        $availableStock=product_variant_stock($p,$variant);
        if($availableStock <= 0) json_out(['ok'=>false,'message'=>'Stok '.$p['name'].' ('.$variant['name'].') habis. Produk tidak bisa di-checkout.'],409);
        if($availableStock < $qty) json_out(['ok'=>false,'message'=>'Stok '.$p['name'].' ('.$variant['name'].') tidak cukup. Sisa stok: '.$availableStock],409);
        $price=product_variant_price($p,$variant);
        $subtotal += $price*$qty;
    }
    $code=strtoupper(trim((string)($_POST['promo_code']??'')));
    if($code==='') json_out(['ok'=>true,'code'=>'','subtotal'=>$subtotal,'discount'=>0,'total'=>$subtotal,'message'=>'']);
    $st=$pdo->prepare('SELECT * FROM promos WHERE UPPER(TRIM(code))=? LIMIT 1');
    $st->execute([$code]); $pr=$st->fetch();
    if(!$pr) json_out(['ok'=>false,'code'=>$code,'subtotal'=>$subtotal,'discount'=>0,'total'=>$subtotal,'message'=>'Kode promo tidak ditemukan.'],422);
    [$valid,$discount,$message]=promo_validate_and_discount($pr,(float)$subtotal);
    if(!$valid) json_out(['ok'=>false,'code'=>$code,'subtotal'=>$subtotal,'discount'=>0,'total'=>$subtotal,'message'=>$message],422);
    json_out(['ok'=>true,'code'=>$code,'subtotal'=>$subtotal,'discount'=>$discount,'total'=>$subtotal-$discount,'message'=>$message]);
}catch(Throwable $e){ json_out(['ok'=>false,'message'=>$e->getMessage()],500); }
?>
