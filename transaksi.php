
<?php
$pageTitle = "Transaksi Penjualan";
require "header.php";

// PROSES SIMPAN TRANSAKSI
if (isset($_POST["save"])) {

    $pel  = (int)($_POST["id_pelanggan"] ?? 0);
    $prod = (int)($_POST["id_produk"] ?? 0);
    $qty  = (int)($_POST["jumlah"] ?? 0);

    if ($pel > 0 && $prod > 0 && $qty > 0) {

        $s = $conn->prepare("CALL TambahTransaksi(?,?,?)");

        $s->bind_param("iii", $pel, $prod, $qty);

        if ($s->execute()) {
            flash("Transaksi berhasil disimpan. Silakan cetak struk pada riwayat transaksi.");
        } else {
            flash("Transaksi gagal: " . $s->error, "danger");
        }

        $s->close();

        // Bersihkan hasil Stored Procedure
        while ($conn->more_results()) {
            $conn->next_result();

            if ($res = $conn->store_result()) {
                $res->free();
            }
        }

    } else {
        flash("Pilih pelanggan, produk, dan jumlah yang valid.", "warning");
    }

    redirect("transaksi.php");
}

// DATA PELANGGAN
$customers = $conn->query("
    SELECT * FROM tbl_pelanggan
    ORDER BY nama_pelanggan
");

// DATA PRODUK
$products = $conn->query("
    SELECT * FROM tbl_produk
    ORDER BY nama_produk
");

// RIWAYAT TRANSAKSI
$list = $conn->query("
    SELECT
        t.*,
        p.nama_pelanggan
    FROM tbl_transaksi t
    LEFT JOIN tbl_pelanggan p
        ON p.id_pelanggan = t.id_pelanggan
    ORDER BY t.id_transaksi DESC
    LIMIT 100
");
?>

<style>
.transaksi-title {
    color: #173b63;
    font-weight: 800;
}

.card {
    border: 1px solid #dbeafe;
    border-radius: 12px;
    overflow: hidden;
}

.card-header {
    background: #eaf6ff;
    color: #173b63;
    font-weight: 700;
    padding: 15px;
}

.btn-primary {
    background: #268bd2;
    border-color: #268bd2;
}

.btn-primary:hover {
    background: #173b63;
}

.btn-struk {
    background: #268bd2;
    color: white;
    border-radius: 6px;
    padding: 6px 10px;
    text-decoration: none;
    font-size: 12px;
    display: inline-block;
}

.btn-struk:hover {
    background: #173b63;
    color: white;
}

.table thead th {
    background: #f0f8ff;
    color: #173b63;
    font-size: 13px;
}

.table td {
    vertical-align: middle;
    font-size: 13px;
}
</style>

<div class="container-fluid">

    <div class="mb-4">
        <h4 class="transaksi-title">
            <i class="bi bi-cart-check me-2"></i>
            Transaksi Penjualan
        </h4>

        <p class="text-muted small">
            Kelola transaksi dan cetak struk pembelian pelanggan.
        </p>
    </div>

    <div class="row g-3">

        <!-- FORM TRANSAKSI -->
        <div class="col-lg-4">

            <div class="card">

                <div class="card-header">
                    <i class="bi bi-plus-circle me-2"></i>
                    Input Transaksi
                </div>

                <div class="card-body">

                    <form method="POST">

                        <div class="mb-3">

                            <label class="form-label">
                                Pelanggan
                            </label>

                            <select name="id_pelanggan"
                                    class="form-select"
                                    required>

                                <option value="">
                                    -- Pilih pelanggan --
                                </option>

                                <?php if ($customers): ?>

                                    <?php while ($c = $customers->fetch_assoc()): ?>

                                        <option value="<?= (int)$c["id_pelanggan"] ?>">
                                            <?= e($c["nama_pelanggan"]) ?>
                                        </option>

                                    <?php endwhile; ?>

                                <?php endif; ?>

                            </select>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Produk
                            </label>

                            <select name="id_produk"
                                    class="form-select"
                                    required>

                                <option value="">
                                    -- Pilih produk --
                                </option>

                                <?php if ($products): ?>

                                    <?php while ($p = $products->fetch_assoc()): ?>

                                        <option value="<?= (int)$p["id_produk"] ?>">

                                            <?= e($p["nama_produk"]) ?>
                                            —
                                            <?= rupiah($p["harga"]) ?>
                                            (Stok <?= (int)$p["stok"] ?>)

                                        </option>

                                    <?php endwhile; ?>

                                <?php endif; ?>

                            </select>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Jumlah
                            </label>

                            <input type="number"
                                   name="jumlah"
                                   class="form-control"
                                   min="1"
                                   placeholder="Masukkan jumlah"
                                   required>

                        </div>

                        <button type="submit"
                                name="save"
                                class="btn btn-primary w-100">

                            <i class="bi bi-cart-check me-2"></i>
                            Simpan Transaksi

                        </button>

                        <div class="small text-muted mt-3">
                            Transaksi diproses menggunakan Stored Procedure
                            TambahTransaksi.
                        </div>

                    </form>

                </div>

            </div>

        </div>

        <!-- RIWAYAT TRANSAKSI -->
        <div class="col-lg-8">

            <div class="card">

                <div class="card-header d-flex justify-content-between align-items-center">

                    <span>
                        <i class="bi bi-receipt me-2"></i>
                        Riwayat Transaksi
                    </span>

                    <span class="badge bg-primary">
                        <?= $list ? $list->num_rows : 0 ?> Data
                    </span>

                </div>

                <div class="table-responsive">

                    <table class="table table-hover mb-0">

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Tanggal</th>
                                <th>Pelanggan</th>
                                <th>Total</th>
                                <th>Aksi</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if ($list && $list->num_rows > 0): ?>

                            <?php while ($r = $list->fetch_assoc()): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            #<?= (int)$r["id_transaksi"] ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= e($r["tanggal"]) ?>
                                    </td>

                                    <td>
                                        <?= e($r["nama_pelanggan"] ?? "-") ?>
                                    </td>

                                    <td class="fw-semibold">
                                        <?= rupiah($r["total_belanja"]) ?>
                                    </td>

                                    <td>

                                        <div class="d-flex gap-1">

                                            <a href="struk.php?id=<?= (int)$r["id_transaksi"] ?>"
                                               target="_blank"
                                               class="btn-struk">

                                                <i class="bi bi-printer"></i>
                                                Cetak

                                            </a>

                                            <a href="detail.php?id=<?= (int)$r["id_transaksi"] ?>"
                                               class="btn btn-sm btn-outline-primary">

                                                <i class="bi bi-eye"></i>

                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="5"
                                    class="text-center text-muted py-4">

                                    <i class="bi bi-receipt fs-2 d-block mb-2"></i>

                                    Belum ada transaksi.

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>

<?php require "footer.php"; ?>
