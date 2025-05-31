<?php
session_start();
include '../db_connection.php';
include "../view/header.php";

if (!isset($_SESSION['id'])) {
  die("Silakan login terlebih dahulu.");
}
$pengguna_id = intval($_SESSION['id']);

// Ambil data cart user beserta foto produk dan stock produk
$stmt = $conn->prepare("
    SELECT c.*, p.foto_url, p.stock, p.nama_produk, p.harga 
    FROM cart c
    JOIN produk p ON c.produk_id = p.produk_id
    WHERE c.pengguna_id = ?
    ORDER BY c.created_at DESC
");
$stmt->bind_param("i", $pengguna_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Keranjang Belanja</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
</head>

<body class="bg-gray-100 font-sans text-gray-900">

  <section class="max-w-7xl mx-auto bg-white mt-4 divide-y divide-gray-200 border border-gray-200 rounded-md shadow-lg">
    <h1 class="text-2xl font-semibold p-6">Keranjang Belanja Kamu</h1>

    <?php if ($result->num_rows > 0): ?>
      <?php $grandTotal = 0; ?>
      <?php while ($row = $result->fetch_assoc()):
        $total = $row['harga'] * $row['quantity'];
        $grandTotal += $total;
      ?>
        <article class="flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition duration-200">
          <div class="flex items-center space-x-4">
            <img alt="<?= htmlspecialchars($row['nama_produk']) ?>" class="w-16 h-16 object-cover rounded flex-shrink-0" src="../uploads/<?= htmlspecialchars($row['foto_url']) ?>" />
            <div>
              <h3 class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($row['nama_produk']) ?></h3>
              <p class="text-xs text-gray-500">
                Warna: <?= htmlspecialchars($row['color']) ?> | Ukuran: <?= htmlspecialchars($row['size']) ?>
              </p>
              <label for="qty-<?= $row['cart_id'] ?>" class="text-xs text-gray-500">Jumlah:</label>
              <input
                type="number"
                id="qty-<?= $row['cart_id'] ?>"
                class="w-16 border rounded px-2 py-1 text-sm"
                value="<?= $row['quantity'] ?>"
                min="1"
                max="<?= $row['stock'] ?>"
                data-cart-id="<?= $row['cart_id'] ?>"
                data-harga="<?= $row['harga'] ?>" />
            </div>
          </div>
          <div class="flex items-center space-x-6">
            <div class="font-bold text-gray-900 text-sm subtotal" id="subtotal-<?= $row['cart_id'] ?>">
              Rp <?= number_format($total, 0, ',', '.') ?>
            </div>
            <form method="POST" action="hapus_cart.php" onsubmit="return confirm('Yakin ingin hapus produk ini?');" style="display:inline;">
              <input type="hidden" name="cart_id" value="<?= $row['cart_id'] ?>">
              <button type="submit" class="text-red-600 hover:text-red-800">
                <i class="fas fa-trash-alt"></i>
              </button>
            </form>
          </div>
        </article>
      <?php endwhile; ?>

      <!-- Total belanja keseluruhan di luar loop produk -->
      <div class="flex items-center justify-between px-6 py-3 border-t border-gray-200">
        <div class="text-gray-900 font-semibold text-lg">Total Belanja:</div>
        <div class="text-gray-900 font-bold text-lg" id="grandTotal">
          Rp <?= number_format($grandTotal, 0, ',', '.') ?>
        </div>
      </div>

      <div class="flex justify-end p-6">
        <form action="checkout.php" method="post" class="w-full max-w-xs">
          <button type="submit" class="w-full bg-gray-900 text-white font-semibold text-sm px-6 py-3 rounded hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-600 transition">
            Checkout Sekarang
          </button>
        </form>
      </div>

    <?php else: ?>
      <p class="text-gray-600 text-center p-6">Keranjang kamu kosong.</p>
    <?php endif; ?>
  </section>

  <script>
    // Fungsi format angka ke Rupiah
    function formatRupiah(number) {
      return 'Rp ' + number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // Update subtotal dan grand total di frontend
    function updateTotals() {
      let grandTotal = 0;
      document.querySelectorAll('input[type=number][data-cart-id]').forEach(input => {
        const qty = parseInt(input.value);
        const harga = parseInt(input.dataset.harga);
        const subtotalElem = document.getElementById('subtotal-' + input.dataset.cartId);
        const subtotal = qty * harga;
        subtotalElem.textContent = formatRupiah(subtotal);
        grandTotal += subtotal;
      });
      document.getElementById('grandTotal').textContent = formatRupiah(grandTotal);
    }

    // Kirim perubahan quantity ke server via fetch AJAX
    function updateQuantity(cartId, quantity) {
      fetch('update_quantity.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: `cart_id=${cartId}&quantity=${quantity}`
        })
        .then(res => res.json())
        .then(data => {
          if (!data.success) {
            alert('Gagal update quantity: ' + data.message);
          }
        })
        .catch((error) => {
          // Hanya log error ke console tanpa alert ke user
          console.error('Fetch error:', error);
        });
    }


    // Pasang event listener pada input quantity
    // Debounce function untuk membatasi frekuensi update ke server
    function debounce(func, delay) {
      let timeout;
      return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
      };
    }

    // Pasang event listener dengan debounce
    document.querySelectorAll('input[type=number][data-cart-id]').forEach(input => {
      input.addEventListener('input', debounce(e => {
        let qty = parseInt(e.target.value);
        const max = parseInt(e.target.max);
        const min = parseInt(e.target.min);

        if (qty > max) {
          qty = max;
          e.target.value = max;
        } else if (qty < min || isNaN(qty)) {
          qty = min;
          e.target.value = min;
        }

        updateQuantity(e.target.dataset.cartId, qty);
        updateTotals();
      }, 300)); // 300ms delay
    });


    // Hitung ulang total saat halaman load
    updateTotals();
  </script>

</body>

</html>