-- Digital Store - hapus pengaturan PIN admin password pesanan yang sudah tidak digunakan.
DELETE FROM settings WHERE `key` = 'admin_order_password_pin_hash';
