# Groceries

Aplikasi manajemen groceries berbasis Laravel untuk mengelola master data, stok, pembelian, penjualan, laporan, dan sinkronisasi data.

## Kebutuhan

- PHP 8.0+
- Composer
- Node.js dan npm
- MySQL atau database lain yang didukung Laravel

## Setup Lokal

1. Install dependency PHP dan JavaScript.

```bash
composer install
npm install
```

2. Buat file environment lokal.

```bash
cp .env.example .env
php artisan key:generate
```

3. Sesuaikan koneksi database di `.env`.

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=groceries
DB_USERNAME=root
DB_PASSWORD=
```

4. Jalankan migrasi dan seeder.

```bash
php artisan migrate --seed
```

5. Jalankan aplikasi.

```bash
php artisan serve
npm run dev
```

Untuk build asset production:

```bash
npm run build
```

## Konfigurasi Sinkronisasi

Jika fitur sinkronisasi digunakan, sesuaikan variable berikut di `.env`.

```env
SYNC_ENABLED=false
SYNC_BASE_URL=
SYNC_DEVICE_ID=offline-001
SYNC_PULL_LIMIT=10000
SYNC_TABLES=
SYNC_API_KEY=
```

## Remote Origin Baru

Setelah repository kosong dibuat di GitHub/GitLab/Bitbucket, ganti origin lokal:

```bash
git remote set-url origin <URL_REPO_BARU>
git push -u origin main
```

Jika remote lama masih ingin disimpan sebagai referensi:

```bash
git remote rename origin upstream
git remote add origin <URL_REPO_BARU>
git push -u origin main
```
