
<?php
/* ==========================================
   DASHBOARD KASIR - TOKO KITA
========================================== */

// Cek session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek hak akses kasir
if (
    !isset($_SESSION['kasir_id']) ||
    ($_SESSION['role'] ?? '') !== 'kasir'
) {
    header("Location: ../login.php");
    exit;
}

// Pengaturan halaman
$pageTitle = "Dashboard Kasir";

// Memanggil header
require_once "header.php";

// Fungsi keamanan
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

// Fungsi format rupiah
if (!function_exists('rupiah')) {
    function rupiah($angka) {
        return "Rp " . number_format(
            (float)$angka,
            0,
            ',',
            '.'
        );
    }
}

/* ==========================================
   FUNGSI QUERY
========================================== */

function ambilData($conn, $sql) {
    $result = $conn->query($sql);

    if (!$result) {
        error_log("Query error: " . $conn->error);
        return [];
    }

    return $result->fetch_assoc() ?: [];
}

function angka($data, $kolom) {
    return $data[$kolom] ?? 0;
}

/* ==========================================
   DATA DASHBOARD
========================================== */

// Total pelanggan
$data = ambilData(
    $conn,
    "SELECT COUNT(*) AS total FROM tbl_pelanggan"
);

$customers = (int) angka($data, 'total');

// Total produk
$data = ambilData(
    $conn,
    "SELECT COUNT(*) AS total FROM tbl_produk"
);

$products = (int) angka($data, 'total');

// Total transaksi
$data = ambilData(
    $conn,
    "SELECT COUNT(*) AS total FROM tbl_transaksi"
);

$transactions = (int) angka($data, 'total');

// Total pendapatan
$data = ambilData(
    $conn,
    "SELECT COALESCE(SUM(total_belanja),0) AS total
     FROM tbl_transaksi"
);

$revenue = (float) angka($data, 'total');

// Rata-rata transaksi
$avgOrder = $transactions > 0
    ? $revenue / $transactions
    : 0;

/* ==========================================
   PENJUALAN HARI INI
========================================== */

$data = ambilData(
    $conn,
    "SELECT
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(total_belanja),0) AS total_penjualan
     FROM tbl_transaksi
     WHERE DATE(tanggal) = CURDATE()"
);

$todayCount = (int) angka($data, 'total_transaksi');

$todayRevenue = (float) angka(
    $data,
    'total_penjualan'
);

/* ==========================================
   PENJUALAN BULAN INI
========================================== */

$thisM = ambilData(
    $conn,
    "SELECT
        COALESCE(SUM(total_belanja),0) AS rev,
        COUNT(*) AS cnt
     FROM tbl_transaksi
     WHERE YEAR(tanggal) = YEAR(CURDATE())
     AND MONTH(tanggal) = MONTH(CURDATE())"
);

$lastM = ambilData(
    $conn,
    "SELECT
        COALESCE(SUM(total_belanja),0) AS rev,
        COUNT(*) AS cnt
     FROM tbl_transaksi
     WHERE YEAR(tanggal) = YEAR(CURDATE() - INTERVAL 1 MONTH)
     AND MONTH(tanggal) = MONTH(CURDATE() - INTERVAL 1 MONTH)"
);

// Persentase perubahan
$pct = function($now, $before) {
    if ($before == 0) {
        return $now > 0 ? 100 : 0;
    }

    return round(
        (($now - $before) / $before) * 100
    );
};

$revPct = $pct(
    $thisM['rev'] ?? 0,
    $lastM['rev'] ?? 0
);

$cntPct = $pct(
    $thisM['cnt'] ?? 0,
    $lastM['cnt'] ?? 0
);

/* ==========================================
   PELANGGAN BARU DAN KEMBALI
========================================== */

$data = ambilData(
    $conn,
    "SELECT COUNT(*) AS total
     FROM (
        SELECT id_pelanggan
        FROM tbl_transaksi
        WHERE id_pelanggan IS NOT NULL
        GROUP BY id_pelanggan
        HAVING COUNT(*) > 1
     ) AS pelanggan_kembali"
);

$returning = (int) angka($data, 'total');

$data = ambilData(
    $conn,
    "SELECT COUNT(DISTINCT id_pelanggan) AS total
     FROM tbl_transaksi
     WHERE id_pelanggan IS NOT NULL"
);

$buyers = (int) angka($data, 'total');

$firstTime = max($buyers - $returning, 0);

$retPct = $buyers > 0
    ? round($returning / $buyers * 100)
    : 0;

$firstPct = $buyers > 0
    ? 100 - $retPct
    : 0;

/* ==========================================
   GRAFIK PENJUALAN 12 BULAN
========================================== */

$thisYear = array_fill(1, 12, 0);
$lastYear = array_fill(1, 12, 0);

$rs = $conn->query(
    "SELECT
        YEAR(tanggal) AS y,
        MONTH(tanggal) AS m,
        COALESCE(SUM(total_belanja),0) AS total
     FROM tbl_transaksi
     WHERE YEAR(tanggal) IN (
        YEAR(CURDATE()),
        YEAR(CURDATE()) - 1
     )
     GROUP BY YEAR(tanggal), MONTH(tanggal)"
);

if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        if ((int)$r['y'] === (int)date('Y')) {
            $thisYear[(int)$r['m']] = (float)$r['total'];
        } else {
            $lastYear[(int)$r['m']] = (float)$r['total'];
        }
    }
}

/* ==========================================
   TRANSAKSI TERBARU
========================================== */

$recent = $conn->query(
    "SELECT
        t.id_transaksi,
        t.tanggal,
        t.total_belanja,
        p.nama_pelanggan
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p
        ON p.id_pelanggan = t.id_pelanggan
     ORDER BY t.id_transaksi DESC
     LIMIT 6"
);

/* ==========================================
   FORMAT PERSENTASE
========================================== */

$badge = function($v) {
    $class = $v >= 0 ? 'up' : 'down';
    $icon = $v >= 0 ? 'up-right' : 'down-right';
    $sign = $v >= 0 ? '+' : '';

    return '<span class="rt-badge '.$class.'">
        <i class="bi bi-arrow-'.$icon.'"></i>
        '.$sign.(int)$v.'%
    </span>';
};

$trend = function($v) {
    $class = $v >= 0 ? 'text-up' : 'text-down';
    $sign = $v >= 0 ? '+' : '';

    return '<span class="'.$class.'">'
        .$sign.(int)$v.'%</span>';
};

?>

<style>

/* ==========================================
   TEMA BIRU MUDA
========================================== */

:root {
    --rt-blue: #2998df;
    --rt-blue-dark: #1769aa;
    --rt-blue-light: #e8f5ff;
    --rt-cyan: #61c4ee;
    --rt-navy: #17446b;
    --rt-border: #e3edf5;
    --rt-muted: #71869a;
}

.rt-wrap {
    font-family: "Nunito", "Inter", system-ui, sans-serif;
    color: #263b50;
}

.rt-title {
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0;
    color: var(--rt-navy);
}

.rt-sub {
    font-size: .85rem;
    color: var(--rt-muted);
}

.rt-sub b {
    color: var(--rt-blue);
}

.rt-date {
    border: 1px solid var(--rt-border);
    background: #fff;
    border-radius: 9px;
    padding: 9px 14px;
    font-size: .82rem;
    color: var(--rt-navy);
}

.rt-card {
    background: #fff;
    border: 1px solid var(--rt-border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(40, 110, 160, .04);
}

.rt-top {
    border-radius: 12px;
    padding: 18px;
    color: white;
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 100px;
    height: 100%;
}

.rt-top .ic {
    width: 46px;
    height: 46px;
    min-width: 46px;
    border-radius: 10px;
    background: rgba(255,255,255,.22);
    display: grid;
    place-items: center;
    font-size: 1.35rem;
}

.rt-top small {
    opacity: .95;
    font-size: .8rem;
}

.rt-top .val {
    font-size: 1.12rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 4px;
}

.rt-top.o {
    background: linear-gradient(135deg,#45b6ed,#238bd2);
}

.rt-top.n {
    background: linear-gradient(135deg,#287fbd,#15558c);
}

.rt-top.t {
    background: linear-gradient(135deg,#48c0d9,#2296bd);
}

.rt-top.b {
    background: linear-gradient(135deg,#63b9ed,#3486d0);
}

.rt-badge {
    font-size: .67rem;
    font-weight: 700;
    padding: 3px 7px;
    border-radius: 5px;
    background: #fff;
}

.rt-badge.up {
    color: #16a34a;
}

.rt-badge.down {
    color: #dc2626;
}

.text-up {
    color: #16a34a;
    font-weight: 700;
}

.text-down {
    color: #dc2626;
    font-weight: 700;
}

/* Kartu statistik */

.rt-mini {
    padding: 17px;
}

.rt-mini .v {
    font-size: 1.12rem;
    font-weight: 800;
    color: var(--rt-navy);
}

.rt-mini .l {
    font-size: .8rem;
    color: var(--rt-muted);
}

.rt-mini .ico {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    font-size: 1.2rem;
}

.rt-mini hr {
    margin: 13px 0 10px;
    border-color: var(--rt-border);
    opacity: 1;
}

.rt-mini .foot {
    font-size: .77rem;
    color: var(--rt-muted);
    display: flex;
    justify-content: space-between;
    gap: 5px;
}

.rt-mini .foot a {
    color: var(--rt-blue);
    font-weight: 700;
    text-decoration: none;
}

.rt-mini .foot a:hover {
    text-decoration: underline;
}

/* Header card */

.rt-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--rt-border);
}

.rt-head h6 {
    margin: 0;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--rt-navy);
}

.rt-head .hi {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-size: .95rem;
}

/* Informasi */

.rt-info {
    border: 1px solid var(--rt-border);
    border-radius: 9px;
    text-align: center;
    padding: 14px 5px;
    font-size: .76rem;
    color: var(--rt-muted);
}

.rt-info i {
    font-size: 1.2rem;
    display: block;
    margin-bottom: 6px;
}

.rt-info b {
    display: block;
    color: var(--rt-navy);
    font-size: 1rem;
    margin-top: 3px;
}

.rt-donut {
    position: relative;
    width: 105px;
    height: 105px;
    flex-shrink: 0;
}

/* Tabel */

.rt-table th {
    font-size: .78rem;
    color: var(--rt-muted);
    font-weight: 700;
    background: #f3f9fe;
    border: 0;
    padding: 13px;
}

.rt-table td {
    font-size: .83rem;
    vertical-align: middle;
    padding: 13px;
}

.rt-table tbody tr:hover {
    background: #f7fbff;
}

.rt-btn {
    background: var(--rt-blue);
    color: #fff;
    border: 0;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: .8rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-block;
}

.rt-btn:hover {
    background: var(--rt-blue-dark);
    color: white;
}

.rt-empty {
    text-align: center;
    padding: 30px 10px;
    color: var(--rt-muted);
}

.rt-empty i {
    font-size: 2rem;
    display: block;
    margin-bottom: 8px;
}

@media(max-width:576px) {
    .rt-title {
        font-size: 1.3rem;
    }

    .rt-top .val {
        font-size: .95rem;
    }

    .rt-head {
        padding: 13px;
    }

    .rt-mini .v {
        font-size: 1rem;
    }
}

</style>


<div class="rt-wrap">

    <!-- ==================================
         SELAMAT DATANG
    =================================== -->

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">

        <div>
            <h1 class="rt-title">
                Selamat Datang,
                <?= e($_SESSION['kasir_nama'] ?? 'Kasir') ?>
            </h1>

            <div class="rt-sub mt-1">
                Selamat bekerja! Hari ini ada
                <b><?= $todayCount ?></b>
                transaksi.
            </div>
        </div>

        <div class="rt-date">
            <i class="bi bi-calendar3 me-2"></i>
            <?= date("d M Y") ?>
        </div>

    </div>


    <!-- ==================================
         KARTU UTAMA
    =================================== -->

    <div class="row g-3 mb-3">

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="rt-top o">

                <div class="ic">
                    <i class="bi bi-cash-stack"></i>
                </div>

                <div>
                    <small>Total Penjualan</small>

                    <div class="val">
                        <?= e(rupiah($revenue)) ?>
                        <?= $badge($revPct) ?>
                    </div>
                </div>

            </div>
        </div>


        <div class="col-12 col-sm-6 col-xl-3">
            <div class="rt-top n">

                <div class="ic">
                    <i class="bi bi-calendar-check"></i>
                </div>

                <div>
                    <small>Penjualan Hari Ini</small>

                    <div class="val">
                        <?= e(rupiah($todayRevenue)) ?>
                    </div>
                </div>

            </div>
        </div>


        <div class="col-12 col-sm-6 col-xl-3">
            <div class="rt-top t">

                <div class="ic">
                    <i class="bi bi-receipt"></i>
                </div>

                <div>
                    <small>Total Transaksi</small>

                    <div class="val">
                        <?= number_format($transactions,0,",",".") ?>
                        <?= $badge($cntPct) ?>
                    </div>
                </div>

            </div>
        </div>


        <div class="col-12 col-sm-6 col-xl-3">
            <div class="rt-top b">

                <div class="ic">
                    <i class="bi bi-wallet2"></i>
                </div>

                <div>
                    <small>Rata-rata Transaksi</small>

                    <div class="val">
                        <?= e(rupiah($avgOrder)) ?>
                    </div>
                </div>

            </div>
        </div>

    </div>


    <!-- ==================================
         KARTU STATISTIK KECIL
    =================================== -->

    <div class="row g-3 mb-3">

        <?php
        $minis = [

            [
                "Penjualan Bulan Ini",
                rupiah($thisM["rev"] ?? 0),
                "bi-graph-up-arrow",
                "#e0f2fe",
                "#0284c7",
                $trend($revPct)
            ],

            [
                "Transaksi Bulan Ini",
                number_format($thisM["cnt"] ?? 0,0,",","."),
                "bi-clock-history",
                "#e0f7fa",
                "#0891b2",
                $trend($cntPct)
            ],

            [
                "Total Produk",
                number_format($products,0,",","."),
                "bi-box-seam",
                "#e8f5ff",
                "#268bd2",
                ""
            ],

            [
                "Total Pelanggan",
                number_format($customers,0,",","."),
                "bi-people",
                "#eff6ff",
                "#2563eb",
                ""
            ]

        ];

        foreach ($minis as $m):
        ?>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="rt-card rt-mini">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="v">
                            <?= e($m[1]) ?>
                        </div>

                        <div class="l">
                            <?= e($m[0]) ?>
                        </div>
                    </div>

                    <div class="ico"
                         style="background:<?= e($m[3]) ?>;color:<?= e($m[4]) ?>">

                        <i class="bi <?= e($m[2]) ?>"></i>

                    </div>

                </div>

                <hr>

                <div class="foot">

                    <span>
                        <?= $m[5] ? $m[5]." vs bulan lalu" : "&nbsp;" ?>
                    </span>

                    <a href="transaksi.php">
                        Lihat Semua
                    </a>

                </div>

            </div>

        </div>

        <?php endforeach; ?>

    </div>


    <!-- ==================================
         GRAFIK DAN INFORMASI
    =================================== -->

    <div class="row g-3 mb-3">

        <!-- GRAFIK PENJUALAN -->

        <div class="col-12 col-xl-8">

            <div class="rt-card h-100">

                <div class="rt-head">

                    <h6>
                        <span class="hi"
                              style="background:#e0f2fe;color:#0284c7">

                            <i class="bi bi-bar-chart"></i>

                        </span>

                        Penjualan Bulanan
                    </h6>

                    <span class="rt-date">
                        <i class="bi bi-calendar3 me-1"></i>
                        Tahun <?= date("Y") ?>
                    </span>

                </div>

                <div class="p-3">

                    <div class="d-flex gap-2 mb-3 flex-wrap">

                        <div class="border rounded p-2 small">

                            <span style="color:#a5d9f5">
                                ●
                            </span>

                            Tahun Lalu

                            <b class="d-block">
                                <?= e(rupiah(array_sum($lastYear))) ?>
                            </b>

                        </div>

                        <div class="border rounded p-2 small">

                            <span style="color:#2998df">
                                ●
                            </span>

                            Tahun Ini

                            <b class="d-block">
                                <?= e(rupiah(array_sum($thisYear))) ?>
                            </b>

                        </div>

                    </div>

                    <div style="height:290px">
                        <canvas id="salesChart"></canvas>
                    </div>

                </div>

            </div>

        </div>


        <!-- INFORMASI UMUM -->

        <div class="col-12 col-xl-4">

            <div class="rt-card h-100">

                <div class="rt-head">

                    <h6>
                        <span class="hi"
                              style="background:#e0f2fe;color:#0284c7">

                            <i class="bi bi-info-circle"></i>

                        </span>

                        Informasi Umum
                    </h6>

                </div>

                <div class="p-3">

                    <div class="row g-2">

                        <div class="col-4">

                            <div class="rt-info">

                                <i class="bi bi-box-seam text-primary"></i>

                                Produk

                                <b><?= $products ?></b>

                            </div>

                        </div>


                        <div class="col-4">

                            <div class="rt-info">

                                <i class="bi bi-people text-info"></i>

                                Pelanggan

                                <b><?= $customers ?></b>

                            </div>

                        </div>


                        <div class="col-4">

                            <div class="rt-info">

                                <i class="bi bi-cart3 text-success"></i>

                                Transaksi

                                <b><?= $transactions ?></b>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- RINGKASAN PELANGGAN -->

                <div class="border-top p-3">

                    <div class="fw-bold mb-3"
                         style="font-size:.85rem;color:#17446b">

                        Ringkasan Pelanggan

                    </div>

                    <div class="d-flex align-items-center justify-content-around flex-wrap gap-2">

                        <div class="rt-donut">
                            <canvas id="custChart"></canvas>
                        </div>

                        <div class="text-center">

                            <div class="fw-bold fs-5">
                                <?= $firstTime ?>
                            </div>

                            <div style="font-size:.75rem;color:#2998df">
                                Pembeli Baru
                            </div>

                            <span class="rt-badge up">
                                <?= $firstPct ?>%
                            </span>

                        </div>

                        <div class="text-center">

                            <div class="fw-bold fs-5">
                                <?= $returning ?>
                            </div>

                            <div style="font-size:.75rem;color:#0891b2">
                                Pelanggan Kembali
                            </div>

                            <span class="rt-badge up">
                                <?= $retPct ?>%
                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ==================================
         TRANSAKSI TERBARU
    =================================== -->

    <div class="rt-card mb-4">

        <div class="rt-head">

            <h6>

                <span class="hi"
                      style="background:#e0f2fe;color:#0284c7">

                    <i class="bi bi-receipt"></i>

                </span>

                Transaksi Terbaru

            </h6>

            <a class="rt-btn" href="transaksi.php">

                <i class="bi bi-plus-circle me-1"></i>

                Transaksi Baru

            </a>

        </div>


        <div class="table-responsive">

            <table class="table rt-table mb-0">

                <thead>

                    <tr>

                        <th class="ps-4">ID Transaksi</th>

                        <th>Tanggal</th>

                        <th>Pelanggan</th>

                        <th class="text-end pe-4">
                            Total Belanja
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if ($recent && $recent->num_rows > 0): ?>

                    <?php while ($r = $recent->fetch_assoc()): ?>

                        <tr>

                            <td class="ps-4">

                                <span class="fw-bold"
                                      style="color:#2998df">

                                    #<?= e($r['id_transaksi']) ?>

                                </span>

                            </td>


                            <td>
                                <?= e($r['tanggal']) ?>
                            </td>


                            <td>

                                <i class="bi bi-person-circle me-1 text-info"></i>

                                <?= e($r['nama_pelanggan'] ?? 'Umum') ?>

                            </td>


                            <td class="fw-bold text-end pe-4">

                                <?= e(rupiah($r['total_belanja'])) ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="4">

                            <div class="rt-empty">

                                <i class="bi bi-receipt"></i>

                                Belum ada transaksi.

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- ==========================================
     CHART JS
========================================== -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>

// Nama bulan
const bulan = [
    "Jan", "Feb", "Mar", "Apr",
    "Mei", "Jun", "Jul", "Agu",
    "Sep", "Okt", "Nov", "Des"
];

// Data penjualan dari PHP
const thisYear = <?= json_encode(array_values($thisYear)) ?>;

const lastYear = <?= json_encode(array_values($lastYear)) ?>;

// Format angka grafik
const fmt = value => {

    if (value >= 1000000) {
        return (value / 1000000).toFixed(1) + " jt";
    }

    if (value >= 1000) {
        return (value / 1000).toFixed(0) + " rb";
    }

    return value;

};


// Grafik penjualan
new Chart(
    document.getElementById("salesChart"),
    {
        type: "bar",

        data: {

            labels: bulan,

            datasets: [

                {
                    label: "Tahun Lalu",

                    data: lastYear,

                    backgroundColor: "#b8e3fa",

                    borderRadius: 6
                },

                {
                    label: "Tahun Ini",

                    data: thisYear,

                    backgroundColor: "#2998df",

                    borderRadius: 6
                }

            ]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                },

                tooltip: {

                    callbacks: {

                        label: function(context) {

                            return context.dataset.label +
                                ": Rp " +
                                context.parsed.y.toLocaleString("id-ID");

                        }

                    }

                }

            },

            scales: {

                x: {
                    grid: {
                        display: false
                    }
                },

                y: {

                    beginAtZero: true,

                    ticks: {
                        callback: fmt
                    },

                    grid: {
                        color: "#edf4f9"
                    }

                }

            }

        }

    }
);


// Grafik pelanggan
new Chart(
    document.getElementById("custChart"),
    {
        type: "doughnut",

        data: {

            datasets: [

                {

                    data: [

                        <?= max($firstTime, 0) ?>,

                        <?= max($returning, 0) ?>,

                        <?= ($firstTime + $returning) ? 0 : 1 ?>

                    ],

                    backgroundColor: [

                        "#2998df",

                        "#55c2df",

                        "#e5edf4"

                    ],

                    borderWidth: 2

                }

            ]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            cutout: "68%",

            plugins: {

                legend: {
                    display: false
                },

                tooltip: {
                    enabled: false
                }

            }

        }

    }
);

</script>


<?php
// Memanggil footer
require_once "footer.php";
?>
