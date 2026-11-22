SISTEM INFORMASI PENJUALAN TOKO - PHP NATIVE

Kebutuhan:
- XAMPP (Apache + MySQL)
- PHP 8+
- Database db_tokoku
- Tabel BAB sebelumnya: tbl_pelanggan dan tbl_transaksi

INSTALASI:
1. Ekstrak folder sistem-penjualan-toko ke D:\xampp\htdocs\
2. Jalankan Apache dan MySQL dari XAMPP Control Panel.
3. Buka phpMyAdmin: http://localhost/phpmyadmin
4. Pilih database db_tokoku. Pastikan tbl_pelanggan dan tbl_transaksi BAB 1 tersedia.
5. Jalankan setup.sql pada database db_tokoku (SQL > paste/import).
6. Buka http://localhost/sistem-penjualan-toko/

Koneksi default config.php:
Host localhost, user root, password kosong, database db_tokoku.
Ubah config.php bila setting MySQL Anda berbeda.

FITUR:
Dashboard, pelanggan, kategori, produk, transaksi, detail, laporan View/JOIN,
subquery analisis, Stored Procedure input transaksi, Trigger log, TCL, EXPLAIN.

Catatan:
- Tambah transaksi memakai CALL TambahTransaksi(...), jadi Stored Procedure pada setup.sql harus sudah dibuat.
- Website ini proyek pembelajaran lokal; sebelum deploy publik tambahkan login, CSRF protection,
  validasi lebih kuat, dan aturan keamanan produksi.
