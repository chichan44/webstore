DIGITAL STORE - PANDUAN INSTALASI
================================

Cocok untuk:
- Shared Hosting / cPanel / DirectAdmin / LiteSpeed
- VPS / aaPanel
- Apache, LiteSpeed, atau Nginx yang menjalankan PHP

PERSYARATAN WAJIB
- PHP 8.1 minimum (PHP 8.3 / 8.4 direkomendasikan)
- MySQL 8.0+ atau MariaDB 10.6+
- Ekstensi PHP: PDO, pdo_mysql, mbstring, OpenSSL, Fileinfo, cURL, JSON, Session
- HTTPS/SSL sangat direkomendasikan dan diperlukan untuk webhook Telegram produksi
- Database kosong
- Script harus dipasang pada document root/domain, bukan subfolder

DIREKOMENDASIKAN
- PHP memory_limit 256M atau lebih
- max_execution_time 60 detik atau lebih
- GD Extension untuk pemrosesan gambar
- Cron Job untuk fitur Telegram worker/broadcast

CARA INSTALL
1. Buat database MySQL/MariaDB beserta user database dari panel hosting.
2. Upload seluruh isi ZIP ke document root domain (contoh public_html).
3. Buka https://domainanda.com/install/install.php
4. Isi Database Host, Port, Nama Database, Username, dan Password Database.
5. Isi nama website dan Site URL.
6. Buat akun administrator dengan password minimal 10 karakter.
7. Klik Install Sekarang.
8. Setelah berhasil, login melalui /admin/login.php
9. Atur Payment Gateway, Footer, Produk, Stok, dan Telegram melalui panel admin.

CATATAN KEAMANAN
- Installer membuat includes/config.php secara otomatis dan mengunci installer setelah sukses.
- APP_KEY acak dibuat pada saat install untuk mengenkripsi data sensitif internal.
- Paket distribusi tidak menyertakan database pemilik, token bot, kredensial payment gateway, data pesanan, data pelanggan, atau gambar produk lama.
- Jangan membagikan includes/config.php kepada pihak lain.

TELEGRAM
- Telegram menggunakan webhook PHP, tidak membutuhkan Node.js.
- Untuk antrean pengiriman, broadcast, retry, dan tugas berkala, buat Cron Job menuju URL yang ditampilkan di /admin/telegram.php.
- Panduan tambahan tersedia pada TELEGRAM-SETUP.txt.

PAYMENT
- Payment gateway dapat diatur dari /admin/tokopay.php: QRIS Manual, Tokopay, atau Casaku.
- Untuk Casaku: isi License Key, QR ID Merchant, Package IDs, Webhook Secret, lalu pasang URL /api/casaku_webhook.php di menu Webhook Developer Casaku.
- Callback Casaku wajib lolos HMAC-SHA256 dari raw body (X-Casaku-Signature), transactionId dan nominal harus cocok dengan order, lalu status paid diverifikasi ulang server-to-server sebelum produk dipenuhi.
- Untuk Tokopay otomatis, isi Merchant ID, Secret Key, endpoint, dan konfigurasi callback sesuai akun/provider pembeli.
- Auto-order Telegram mengikuti gateway otomatis yang aktif. Jika gateway Casaku dipilih, bot juga membuat QRIS Casaku dan tidak membutuhkan kredensial Tokopay.
- Whitelist IP callback tidak diisi secara paksa pada fresh install agar tidak membawa konfigurasi milik website penjual. Aktifkan setelah pembeli mengetahui IP callback resmi provider yang digunakan.

PENGIRIMAN PRODUK OTOMATIS
- Atur dari /admin/settings.php pada bagian Pengiriman Produk Otomatis.
- Email: aktifkan Email Otomatis. SMTP direkomendasikan; jika SMTP kosong sistem mencoba mail() PHP dari hosting.
- WhatsApp: aktifkan WhatsApp Otomatis dan isi Token Fonnte. Perangkat WhatsApp pada akun Fonnte harus sudah terhubung.
- Nomor WhatsApp checkout otomatis dinormalisasi ke format kode negara Indonesia 62 secara default.
- Setelah order berstatus delivered, produk dikirim ke email dan WhatsApp pembeli. Status tiap kanal dicatat agar callback pembayaran berulang tidak mengirim produk dua kali.
- Jika pengiriman gagal sementara, antrean dicoba ulang oleh Cron Job Telegram worker yang sama.

