# Pengaturan pendapatan kasir

Pada tambah/edit user, role staff/kasir/cashier mempunyai dua pengaturan independen:

| Tanpa rincian pecahan | Lock penjualan | Input sebelum logout |
| --- | --- | --- |
| Aktif (default) | Nonaktif | Total pendapatan saja |
| Aktif | Aktif | Total pendapatan wajib sama dengan penjualan |
| Nonaktif | Nonaktif | Pecahan uang, QR, dan pengeluaran; total dihitung dari rincian |
| Nonaktif | Aktif | Rincian wajib valid; jumlah pecahan + QR + pengeluaran wajib sama dengan total input dan penjualan |

Mode ditentukan pengaturan akun pada server. Pengiriman flag mode dari kasir tidak dapat mengubahnya. Pada mode sederhana, rincian tidak disimpan; field rincian dari tab lama diabaikan. Pada mode rincian, total saja tanpa pecahan ditolak. Pecahan yang tidak digunakan diisi nol. Perhitungan penjualan harian dan komponen QR/pengeluaran mengikuti aturan aplikasi yang sudah ada.

Migrasi `2026_10_06_000003_add_revenue_simple_mode_to_users` menambahkan boolean dengan default aktif untuk akun lama dan baru. Migrasi sudah diterapkan pada database aplikasi lokal. Terapkan migrasi ini juga saat deploy ke lingkungan lain.

Pengujian mencakup empat kombinasi, simpan pengaturan user, nominal kosong/tidak valid, penolakan data yang dimanipulasi, logout langsung saat rincian wajib, dan respons validasi browser yang terlambat. Form admin diverifikasi di browser; pengujian logout menggunakan database SQLite terpisah, tanpa mencatat pendapatan akun toko sebenarnya.
