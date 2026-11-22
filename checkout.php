<?php
require_once "fungsi.php";

class PesananException extends Exception {}

wajib_login();
$idPelanggan = (int)$_SESSION['user_pelanggan'];

[$items, $subtotalTampil] = cart_items($conn);
if (empty($_SESSION['cart']) || !$items) {
    flash('warning', 'Keranjang masih kosong. Pilih produk di daftar, lalu tekan Tambah ke Keranjang.');
    header("Location: keranjang.php");
    exit;
}

$opsiKirim  = opsi_pengiriman();
$opsiBayar  = opsi_pembayaran();
$errors = [];
$pesanVoucher = null;

$st = $conn->prepare("SELECT nama_pelanggan FROM tbl_pelanggan WHERE id_pelanggan = ?");
$st->bind_param("i", $idPelanggan);
$st->execute();
$pel = $st->get_result()->fetch_assoc();

$f = [
    'nama'    => $pel['nama_pelanggan'] ?? '',
    'hp'      => '',
    'alamat'  => '',
    'kirim'   => 'ambil',
    'bayar'   => 'transfer',
    'voucher' => $_SESSION['voucher'] ?? '',
];
$aksi = 'pesan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = (($_POST['aksi'] ?? '') === 'voucher') ? 'voucher' : 'pesan';
    $f['nama']    = trim($_POST['nama'] ?? '');
    $f['hp']      = trim($_POST['hp'] ?? '');
    $f['alamat']  = trim($_POST['alamat'] ?? '');
    $f['kirim']   = array_key_exists($_POST['kirim'] ?? '', $opsiKirim) ? $_POST['kirim'] : 'ambil';
    $f['bayar']   = array_key_exists($_POST['bayar'] ?? '', $opsiBayar) ? $_POST['bayar'] : 'transfer';
    $f['voucher'] = strtoupper(trim($_POST['voucher'] ?? ''));
}

$vInfo = ['ok' => false, 'diskon' => 0.0, 'pesan' => ''];
if ($f['voucher'] !== '') $vInfo = hitung_voucher($f['voucher'], (float)$subtotalTampil);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi === 'voucher') {
    if ($f['voucher'] === '') {
        unset($_SESSION['voucher']);
        $pesanVoucher = ['secondary', 'Voucher dilepas.'];
    } elseif ($vInfo['ok']) {
        $_SESSION['voucher'] = $f['voucher'];
        $pesanVoucher = ['success', $vInfo['pesan']];
    } else {
        unset($_SESSION['voucher']);
        $pesanVoucher = ['danger', $vInfo['pesan']];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi === 'pesan') {
    $o = $opsiKirim[$f['kirim']];
    $hpBersih = preg_replace('/[\s-]/', '', $f['hp']);

    if ($f['nama'] === '' || mb_strlen($f['nama']) > 100) $errors[] = "Nama penerima wajib diisi (maksimal 100 karakter).";
    if (!preg_match('/^\+?[0-9]{9,15}$/', $hpBersih))     $errors[] = "No. HP tidak valid (9-15 angka).";
    if ($o['alamat'] && $f['alamat'] === '')              $errors[] = "Alamat wajib diisi untuk pengiriman " . $o['nama'] . ".";
    if (mb_strlen($f['alamat']) > 500)                    $errors[] = "Alamat maksimal 500 karakter.";
    if ($f['voucher'] !== '' && !$vInfo['ok'])            $errors[] = $vInfo['pesan'];
    if (!$pel)                                            $errors[] = "Data akun tidak ditemukan. Silakan masuk ulang.";

    if (!$errors) {
        $cart = $_SESSION['cart'];
        try {
            $conn->begin_transaction();

            // 1) Cek stok & hitung subtotal dari harga di database (bukan dari browser)
            $sel = $conn->prepare("SELECT nama_produk, harga, stok FROM tbl_produk WHERE id_produk = ? FOR UPDATE");
            $baris = []; $subtotal = 0.0;
            foreach ($cart as $id => $qty) {
                $id = (int)$id; $qty = (int)$qty;
                $sel->bind_param("i", $id);
                $sel->execute();
                $p = $sel->get_result()->fetch_assoc();
                if (!$p) throw new PesananException("Ada produk di keranjang yang sudah tidak tersedia.");
                if ((int)$p['stok'] < $qty)
                    throw new PesananException("Stok {$p['nama_produk']} tidak mencukupi (sisa {$p['stok']}).");
                $sub = $p['harga'] * $qty;
                $subtotal += $sub;
                $baris[] = [$id, $qty, (float)$p['harga'], (float)$sub];
            }

            // 2) Ongkir, voucher, total (dihitung ulang di server)
            $ongkir = (float)$o['ongkir'];
            $kode = null; $diskon = 0.0;
            if ($f['voucher'] !== '') {
                $v = hitung_voucher($f['voucher'], $subtotal);
                if (!$v['ok']) throw new PesananException($v['pesan']);
                $kode = $f['voucher']; $diskon = $v['diskon'];
            }
            $total   = $subtotal - $diskon + $ongkir;
            $alamat  = $o['alamat'] ? $f['alamat'] : null;
            $kirim   = $o['nama'];
            $bayar   = $opsiBayar[$f['bayar']];

            // 3) Transaksi
            $st = $conn->prepare("INSERT INTO tbl_transaksi
                (tanggal, id_pelanggan, total_belanja, status, nama_penerima, no_hp, alamat, pengiriman, ongkir, pembayaran, kode_voucher, diskon)
                VALUES (CURDATE(), ?, ?, 'Menunggu', ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("idssssdssd", $idPelanggan, $total, $f['nama'], $hpBersih, $alamat, $kirim, $ongkir, $bayar, $kode, $diskon);
            $st->execute();
            $idTransaksi = $conn->insert_id;

            // 4) Detail + kurangi stok
            $det = $conn->prepare("INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, jumlah, harga, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stk = $conn->prepare("UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ?");
            foreach ($baris as [$id, $qty, $harga, $sub]) {
                $det->bind_param("iiidd", $idTransaksi, $id, $qty, $harga, $sub);
                $det->execute();
                $stk->bind_param("ii", $qty, $id);
                $stk->execute();
            }

            $conn->commit();

            $_SESSION['cart'] = [];
            unset($_SESSION['voucher']);
            $_SESSION['pesanan_saya'][] = $idTransaksi;
            flash('success', "Pesanan berhasil dibuat! Simpan nomor pesanan Anda: #$idTransaksi");
            header("Location: lacak.php?id=" . $idTransaksi);
            exit;

        } catch (PesananException $ex) {
            $conn->rollback();
            flash('danger', $ex->getMessage());
            header("Location: keranjang.php");
            exit;
        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = "Terjadi kesalahan saat memproses pesanan. Pastikan tambah_checkout.sql sudah dijalankan.";
        }
    }
}

$pageTitle = "Proses Transaksi";
require "header.php";

$ongkirAwal = (float)$opsiKirim[$f['kirim']]['ongkir'];
$diskonAwal = $vInfo['ok'] ? (float)$vInfo['diskon'] : 0.0;
$totalAwal  = $subtotalTampil - $diskonAwal + $ongkirAwal;
?>
<style>
  .co-card{border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
  .co-card .form-label{font-weight:600;margin-bottom:.4rem}
  .co-card .form-control,.co-card .form-select{padding:.7rem .9rem;border-radius:10px}
  .co-title{font-weight:700;font-size:1.05rem;margin-bottom:.5rem}
  .co-section{font-weight:700;font-size:1.1rem;margin:1.25rem 0 .75rem}
  .btn-pakai{background:#fff;border:1px solid #dee2e6;color:#0f6b4f;font-weight:600;border-radius:10px;padding:.6rem 1.1rem}
  .btn-pakai:hover{background:#f1f8f5}
  .btn-pesan{width:100%;background:#1f8a68;color:#fff;border:0;border-radius:10px;padding:.85rem;font-weight:600}
  .btn-pesan:hover{background:#177055;color:#fff}
  .co-item{display:flex;justify-content:space-between;gap:12px;font-size:.92rem;padding:.2rem 0}
</style>

<div class="row justify-content-center"><div class="col-md-8 col-lg-5">
<form method="post" id="formCo" class="card co-card"><div class="card-body p-4">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" id="aksi" value="pesan">

  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>

  <div class="co-title">Keranjang saya</div>
  <?php foreach ($items as $i): ?>
    <div class="co-item">
      <span><?= e($i['nama_produk']) ?> &times; <?= (int)$i['qty'] ?></span>
      <span class="fw-semibold"><?= rupiah($i['subtotal']) ?></span>
    </div>
  <?php endforeach; ?>
  <a href="keranjang.php" class="small">Ubah isi keranjang</a>

  <div class="co-section">Data penerima</div>

  <div class="mb-3">
    <label class="form-label" for="nama">Nama</label>
    <input type="text" class="form-control" id="nama" name="nama" maxlength="100" value="<?= e($f['nama']) ?>">
  </div>
  <div class="mb-3">
    <label class="form-label" for="hp">No. HP</label>
    <input type="tel" class="form-control" id="hp" name="hp" maxlength="20" inputmode="tel" value="<?= e($f['hp']) ?>">
  </div>
  <div class="mb-3">
    <label class="form-label" for="alamat">Alamat (kosongkan jika ambil di toko)</label>
    <textarea class="form-control" id="alamat" name="alamat" rows="3" maxlength="500"><?= e($f['alamat']) ?></textarea>
  </div>
  <div class="mb-3">
    <label class="form-label" for="kirim">Pengiriman</label>
    <select class="form-select" id="kirim" name="kirim">
      <?php foreach ($opsiKirim as $k => $o): ?>
        <option value="<?= e($k) ?>" <?= $f['kirim'] === $k ? 'selected' : '' ?>><?= e(label_pengiriman($o)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label" for="bayar">Pembayaran</label>
    <select class="form-select" id="bayar" name="bayar">
      <?php foreach ($opsiBayar as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $f['bayar'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-2">
    <label class="form-label" for="voucher">Kode voucher</label>
    <div class="d-flex gap-2">
      <input type="text" class="form-control" id="voucher" name="voucher" maxlength="30" placeholder="HEMAT10"
             value="<?= e($f['voucher']) ?>" style="text-transform:uppercase" autocomplete="off">
      <button type="button" class="btn-pakai" id="btnPakai">Pakai</button>
    </div>
    <?php if ($pesanVoucher): ?>
      <div class="small mt-1 text-<?= e($pesanVoucher[0]) ?>"><?= e($pesanVoucher[1]) ?></div>
    <?php elseif ($vInfo['ok']): ?>
      <div class="small mt-1 text-success"><?= e($vInfo['pesan']) ?></div>
    <?php endif; ?>
  </div>

  <hr>
  <div class="d-flex justify-content-between small"><span>Subtotal</span><span><?= rupiah($subtotalTampil) ?></span></div>
  <div class="d-flex justify-content-between small"><span>Ongkir</span><span id="tOngkir"><?= rupiah($ongkirAwal) ?></span></div>
  <div class="d-flex justify-content-between small text-success"><span>Diskon</span><span>- <?= rupiah($diskonAwal) ?></span></div>
  <div class="d-flex justify-content-between fw-bold fs-5 mt-1 mb-3"><span>Total</span><span id="tTotal"><?= rupiah($totalAwal) ?></span></div>

  <button type="submit" class="btn-pesan">Pesan sekarang</button>
</div></form>
</div></div>

<script>
(function () {
  const ongkir = <?= json_encode(array_map(fn($o) => $o['ongkir'], $opsiKirim)) ?>;
  const butuhAlamat = <?= json_encode(array_map(fn($o) => $o['alamat'], $opsiKirim)) ?>;
  const subtotal = <?= (float)$subtotalTampil ?>, diskon = <?= $diskonAwal ?>;
  const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
  const kirim = document.getElementById('kirim');
  const form = document.getElementById('formCo');

  function hitung() {
    const o = ongkir[kirim.value] || 0;
    document.getElementById('tOngkir').textContent = rp(o);
    document.getElementById('tTotal').textContent = rp(subtotal - diskon + o);
    document.getElementById('alamat').required = !!butuhAlamat[kirim.value];
  }
  kirim.addEventListener('change', hitung);
  hitung();

  function pakai() { document.getElementById('aksi').value = 'voucher'; form.submit(); }
  document.getElementById('btnPakai').addEventListener('click', pakai);
  document.getElementById('voucher').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); pakai(); }
  });
})();
</script>
<?php require "footer.php"; ?>
