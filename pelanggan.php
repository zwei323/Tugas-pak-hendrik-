<?php
$pageTitle="Data Pelanggan"; require "header.php";
if(isset($_POST["save"])){
    $nama=trim($_POST["nama_pelanggan"]??""); $kota=trim($_POST["kota"]??"");
    if($nama!==""){
        $stmt=$conn->prepare("INSERT INTO tbl_pelanggan(nama_pelanggan,kota) VALUES(?,?)");
        $stmt->bind_param("ss",$nama,$kota);
        if($stmt->execute()){flash("Pelanggan berhasil ditambahkan.");}else{flash("Gagal: ".$stmt->error,"danger");}
    } else flash("Nama pelanggan wajib diisi.","warning");
    redirect("pelanggan.php");
}
if(isset($_GET["delete"])){
    $id=(int)$_GET["delete"];
    $stmt=$conn->prepare("DELETE FROM tbl_pelanggan WHERE id_pelanggan=?");$stmt->bind_param("i",$id);
    if($stmt->execute()) flash("Pelanggan dihapus."); else flash("Tidak dapat menghapus pelanggan yang memiliki transaksi.","danger");
    redirect("pelanggan.php");
}
$list=$conn->query("SELECT * FROM tbl_pelanggan ORDER BY id_pelanggan DESC");
?>
<div class="page-actions"><div><div class="text-muted">Kelola data pelanggan toko</div></div></div>
<div class="row g-3"><div class="col-lg-4"><div class="card"><div class="card-header">Tambah Pelanggan</div><div class="card-body"><form method="post"><div class="mb-3"><label class="form-label">Nama Pelanggan</label><input class="form-control" name="nama_pelanggan" required maxlength="100"></div><div class="mb-3"><label class="form-label">Kota</label><input class="form-control" name="kota" maxlength="50"></div><button class="btn btn-primary w-100" name="save">Simpan Pelanggan</button></form></div></div></div>
<div class="col-lg-8"><div class="card"><div class="card-header">Daftar Pelanggan</div><div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Nama</th><th>Kota</th><th>Aksi</th></tr></thead><tbody><?php if($list && $list->num_rows):while($r=$list->fetch_assoc()):?><tr><td><?=e($r["id_pelanggan"])?></td><td><?=e($r["nama_pelanggan"])?></td><td><?=e($r["kota"])?></td><td><a class="btn btn-sm btn-outline-danger" data-confirm="Hapus pelanggan ini?" href="?delete=<?=$r["id_pelanggan"]?>"><i class="bi bi-trash"></i></a></td></tr><?php endwhile;else:?><tr><td colspan="4" class="text-center text-muted p-4">Data belum tersedia.</td></tr><?php endif;?></tbody></table></div></div></div></div>
<?php require "footer.php"; ?>
