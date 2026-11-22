<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/koneksi.php";

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rupiah($n) { return "Rp " . number_format((float)$n, 0, ',', '.'); }
function flash($type, $msg) { $_SESSION['flash'] = ['type' => $type, 'message' => $msg]; }

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        die("Sesi tidak valid. Silakan kembali dan coba lagi.");
    }
}

function cart_count() { return array_sum($_SESSION['cart'] ?? []); }

// Halaman saat ini (untuk tombol "kembali" setelah tambah ke keranjang)
function back_url() {
    $u = basename($_SERVER['PHP_SELF']);
    if (!empty($_SERVER['QUERY_STRING'])) $u .= '?' . $_SERVER['QUERY_STRING'];
    return $u;
}
function safe_back($b, $default = 'keranjang.php') {
    return preg_match('/^[a-z_]+\.php(\?[\w=&%.\-+]*)?$/i', (string)$b) ? $b : $default;
}

// ---- Login pelanggan ----
function user_login_check(): bool { return !empty($_SESSION['user_id']); }
function wajib_login() {
    if (!user_login_check()) {
        flash('warning', 'Silakan masuk atau daftar dulu untuk melanjutkan.');
        header("Location: masuk.php?next=" . urlencode(back_url()));
        exit;
    }
}

// Isi keranjang + data produk dari database -> [items, total]
function cart_items(mysqli $conn): array {
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) return [[], 0.0];
    $ids = implode(',', array_map('intval', array_keys($cart)));
    $res = $conn->query("SELECT p.*, k.nama_kategori FROM tbl_produk p
                         LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
                         WHERE p.id_produk IN ($ids)");
    $items = []; $total = 0.0;
    while ($r = $res->fetch_assoc()) {
        $qty = (int)$cart[$r['id_produk']];
        $r['qty'] = $qty;
        $r['subtotal'] = $r['harga'] * $qty;
        $r['kurang'] = $qty > (int)$r['stok'];
        $total += $r['subtotal'];
        $items[] = $r;
    }
    return [$items, $total];
}

function ikon_kategori($id) {
    return [1 => 'bi-basket2', 2 => 'bi-cup-straw', 3 => 'bi-house-gear'][(int)$id] ?? 'bi-box-seam';
}

// Kartu produk (dipakai di beranda & daftar produk)
function kartu_produk(array $p): string {
    $habis = (int)$p['stok'] <= 0;
    ob_start(); ?>
<div class="col-6 col-md-4 col-lg-3">
  <div class="card h-100 produk-card">
    <div class="produk-img"><i class="bi <?= ikon_kategori($p['id_kategori']) ?>"></i></div>
    <div class="card-body d-flex flex-column">
      <span class="badge text-bg-light align-self-start mb-2"><?= e($p['nama_kategori'] ?? 'Lainnya') ?></span>
      <h6 class="card-title mb-1"><?= e($p['nama_produk']) ?></h6>
      <div class="fw-bold text-primary mb-1"><?= rupiah($p['harga']) ?></div>
      <div class="small mb-3 <?= $habis ? 'text-danger' : 'text-muted' ?>">
        <?= $habis ? 'Stok habis' : ((int)$p['stok'] <= 10 ? 'Sisa ' . (int)$p['stok'] : 'Stok tersedia') ?>
      </div>
      <form method="post" action="keranjang.php" class="mt-auto">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="tambah">
        <input type="hidden" name="id" value="<?= (int)$p['id_produk'] ?>">
        <input type="hidden" name="back" value="<?= e(back_url()) ?>">
        <button class="btn btn-primary btn-sm w-100" <?= $habis ? 'disabled' : '' ?>>
          <i class="bi bi-cart-plus"></i> Tambah ke Keranjang
        </button>
      </form>
    </div>
  </div>
</div>
<?php
    return ob_get_clean();
}


// ---- Opsi proses transaksi (ubah di sini untuk menambah/mengganti pilihan) ----
function opsi_pengiriman(): array {
    return [
        'ambil'   => ['nama' => 'Ambil di toko',      'ongkir' => 0,     'alamat' => false],
        'kurir'   => ['nama' => 'Kurir toko',         'ongkir' => 10000, 'alamat' => true],
        'ekspres' => ['nama' => 'Ekspres (same-day)', 'ongkir' => 20000, 'alamat' => true],
    ];
}
function label_pengiriman(array $o): string {
    return $o['nama'] . ($o['ongkir'] > 0 ? ' (' . rupiah($o['ongkir']) . ')' : ' (gratis)');
}
function opsi_pembayaran(): array {
    return [
        'transfer' => 'Transfer bank',
        'cod'      => 'Bayar di tempat (COD)',
        'ewallet'  => 'E-wallet / QRIS',
    ];
}
// persen = potongan %, min = minimal belanja, maks = batas potongan (0 = tanpa batas)
function daftar_voucher(): array {
    return ['HEMAT10' => ['persen' => 10, 'min' => 0, 'maks' => 0]];
}
function hitung_voucher(string $kode, float $subtotal): array {
    $kode = strtoupper(trim($kode));
    $v = daftar_voucher()[$kode] ?? null;
    if (!$v) return ['ok' => false, 'diskon' => 0.0, 'pesan' => 'Kode voucher tidak valid.'];
    if ($subtotal < $v['min'])
        return ['ok' => false, 'diskon' => 0.0, 'pesan' => 'Minimal belanja ' . rupiah($v['min']) . ' untuk voucher ini.'];
    $d = $subtotal * $v['persen'] / 100;
    if ($v['maks'] > 0) $d = min($d, $v['maks']);
    $d = floor($d);
    return ['ok' => true, 'diskon' => (float)$d, 'pesan' => "Voucher $kode dipakai: potongan " . rupiah($d) . "."];
}
