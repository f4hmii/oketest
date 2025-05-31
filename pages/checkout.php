<?php
session_start();
include '../db_connection.php';
include "../view/header.php";

if (!isset($_SESSION['id'])) {
    die("Silakan login terlebih dahulu.");
}
$pengguna_id = intval($_SESSION['id']);

// Ambil data cart user
$stmt = $conn->prepare("
    SELECT c.*, p.nama_produk, p.foto_url 
    FROM cart c 
    JOIN produk p ON c.produk_id = p.produk_id 
    WHERE c.pengguna_id = ?
");
$stmt->bind_param("i", $pengguna_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$totalHarga = 0;

while ($row = $result->fetch_assoc()) {
    $items[] = $row;
    $totalHarga += $row['harga'] * $row['quantity'];
}

$checkoutSukses = false;
$transaksi_id = 0;

// Proses jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($items) > 0) {
    $alamat_pengiriman = $_POST['alamat_pengiriman'] ?? '';
    $metode_pembayaran = $_POST['metode_pembayaran'] ?? '';

    if (empty($alamat_pengiriman) || empty($metode_pembayaran)) {
        echo "<script>alert('Silakan isi alamat pengiriman dan metode pembayaran.');</script>";
    } else {
        // 1. Insert ke transaksi
        $stmtTransaksi = $conn->prepare("INSERT INTO transaksi (pengguna_id, total_harga, alamat_pengiriman, metode_pembayaran) VALUES (?, ?, ?, ?)");
        $stmtTransaksi->bind_param("iiss", $pengguna_id, $totalHarga, $alamat_pengiriman, $metode_pembayaran);
        $stmtTransaksi->execute();
        $transaksi_id = $stmtTransaksi->insert_id;

        // 2. Insert tiap item ke transaksi_detail
        $stmtDetail = $conn->prepare("INSERT INTO transaksi_detail (transaksi_id, produk_id, quantity, harga, ukuran, warna) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $stmtDetail->bind_param(
                "iiisss",
                $transaksi_id,
                $item['produk_id'],
                $item['quantity'],
                $item['harga'],
                $item['size'],
                $item['color']
            );
            $stmtDetail->execute();
        }

        // 3. Kosongkan cart
        $stmt = $conn->prepare("DELETE FROM cart WHERE pengguna_id = ?");
        $stmt->bind_param("i", $pengguna_id);
        $stmt->execute();

        // 4. Tandai checkout sukses
        $checkoutSukses = true;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Checkout</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">
    <div class="max-w-4xl mx-auto mt-10 bg-white p-6 rounded shadow">
        <h2 class="text-2xl font-semibold mb-4">Checkout</h2>

        <?php if ($checkoutSukses): ?>
            <!-- Pesan Checkout Berhasil -->
            <div class="text-center text-black">
                <h3 class="text-2xl font-semibold mb-4">Checkout Berhasil!</h3>
                <p>Terima kasih telah melakukan pembelian.</p>
                <p class="mt-2">Nomor Transaksi: <strong>#<?= htmlspecialchars($transaksi_id) ?></strong></p>
                <a href="../index.php" class="inline-block mt-6 px-4 py-2 bg-gray-900 text-white rounded hover:bg-gray-700">Kembali ke Beranda</a>
            </div>
        <?php elseif (count($items) > 0): ?>
            <!-- Form Checkout -->
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Alamat Pengiriman</label>
                    <textarea name="alamat_pengiriman" required class="w-full border rounded p-2" placeholder="Masukkan alamat lengkap..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Metode Pembayaran</label>
                    <select name="metode_pembayaran" id="metode_pembayaran" required class="w-full border rounded p-2" onchange="tampilkanOpsi()">
                        <option value="">Pilih Metode</option>
                        <option value="Transfer Bank">Transfer Bank</option>
                        <option value="E-Wallet">E-Wallet</option>
                        <option value="COD">COD (Bayar di Tempat)</option>
                    </select>
                </div>

                <div id="opsi_transfer" class="mb-4 hidden">
                    <label class="block text-sm font-medium mb-2">Pilih Bank</label>
                    <select name="opsi_transfer" class="w-full border rounded p-2">
                        <option value="BCA">BCA</option>
                        <option value="MANDIRI">MANDIRI</option>
                        <option value="BNI">BNI</option>
                    </select>
                </div>

                <div id="opsi_ewallet" class="mb-4 hidden">
                    <label class="block text-sm font-medium mb-2">Pilih E-Wallet</label>
                    <select name="opsi_ewallet" class="w-full border rounded p-2">
                        <option value="DANA">DANA</option>
                        <option value="GOPAY">GOPAY</option>
                        <option value="SHOPEEPAY">SHOPEEPAY</option>
                    </select>
                </div>

                <?php foreach ($items as $item): ?>
                    <div class="flex justify-between items-center py-3 border-b">
                        <div class="flex items-center gap-4">
                            <img src="../uploads/<?= htmlspecialchars($item['foto_url']) ?>" class="w-16 h-16 object-cover rounded" alt="<?= htmlspecialchars($item['nama_produk']) ?>">
                            <div>
                                <p class="font-semibold"><?= htmlspecialchars($item['nama_produk']) ?></p>
                                <p class="text-sm text-gray-600">Ukuran: <?= htmlspecialchars($item['size']) ?> | Warna: <?= htmlspecialchars($item['color']) ?></p>
                                <p class="text-sm text-gray-600">Jumlah: <?= $item['quantity'] ?></p>
                            </div>
                        </div>
                        <div class="font-semibold text-gray-800">Rp <?= number_format($item['harga'] * $item['quantity'], 0, ',', '.') ?></div>
                    </div>
                <?php endforeach; ?>

                <div class="flex justify-between mt-6 text-lg font-semibold">
                    <span>Total:</span>
                    <span>Rp <?= number_format($totalHarga, 0, ',', '.') ?></span>
                </div>

                <button type="submit" class="mt-6 w-full bg-red-500 text-white py-3 rounded hover:bg-black">Bayar Sekarang</button>
            </form>
        <?php else: ?>
            <p class="text-gray-600">Tidak ada produk untuk checkout.</p>
        <?php endif; ?>
    </div>

    <script>
        function tampilkanOpsi() {
            const metode = document.getElementById("metode_pembayaran").value;
            const opsiTransfer = document.getElementById("opsi_transfer");
            const opsiEwallet = document.getElementById("opsi_ewallet");

            opsiTransfer.classList.add("hidden");
            opsiEwallet.classList.add("hidden");

            if (metode === "Transfer Bank") {
                opsiTransfer.classList.remove("hidden");
            } else if (metode === "E-Wallet") {
                opsiEwallet.classList.remove("hidden");
            }
        }
    </script>

</body>

</html>