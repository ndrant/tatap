# Panduan Deployment Tatap Web PHP di Shared Hosting (cPanel / DirectAdmin / Apache)

Panduan ini menjelaskan cara memasang dan meng-host **Tatap Web PHP** di layanan *shared hosting* standar (seperti Hostinger, Niagahoster, DomaiNesia, cPanel, DirectAdmin, atau server Apache/LiteSpeed) tanpa memerlukan VPS, Docker, Python, atau Composer di server hosting Anda.

---

## 1. Kebutuhan Sistem Server (Server Requirements)

Sebagian besar layanan shared hosting modern sudah memenuhi seluruh kebutuhan ini secara default:
- **PHP**: Versi 7.4, 8.0, 8.1, 8.2, atau 8.3+
- **Ekstensi PHP Wajib** (biasanya sudah aktif di cPanel):
  - `ext-curl` (untuk mengambil stream dan katalog anime)
  - `ext-pdo_sqlite` (untuk database riwayat tontonan dan bookmark mandiri)
  - `ext-openssl` (untuk dekripsi stream AES MegaCloud & Putarin)
  - `ext-json` & `ext-mbstring`
- **Web Server**: Apache atau LiteSpeed dengan `mod_rewrite` aktif (didukung otomatis via file `.htaccess`).
- **Zero Composer / Zero Vendor**: Tidak memerlukan perintah `composer install`. Seluruh dependensi 100% mandiri dalam PHP bawaan.

---

## 2. Pengujian Lokal Terlebih Dahulu (Run in Local First)

Sebelum mengunggah ke hosting, Anda dapat menguji langsung versi PHP ini di komputer lokal Anda:

### Cara Praktis (Satu Perintah Otomatis):
```bash
python3 tools/build_php_web.py --serve
```
*Perintah ini otomatis membangun paket dan langsung menjalankan server PHP lokal di `http://127.0.0.1:8768`.*

### Cara Manual via Built-in PHP Server:
```bash
# 1. Bangun paket staging
python3 tools/build_php_web.py

# 2. Jalankan server lokal dengan router pengarah
php -S 127.0.0.1:8768 -t dist/tatap-web-php dist/tatap-web-php/router.php
```
Buka browser dan akses **`http://127.0.0.1:8768`**. Anda dapat langsung mencoba pencarian anime, membuka detail AniList, memutar episode HiAnime/Otakudesu, dan menguji subtitle terjemahan.

---

## 3. Cara 1: Otomatis Mengunggah via Tool CLI (`build_php_web.py`)

Jika Anda memiliki akses FTP/FTPS ke akun shared hosting Anda:

1. **Jalankan build dan upload otomatis:**
   ```bash
   python3 tools/build_php_web.py \
     --deploy-ftp \
     --ftp-host ftp.domainanda.com \
     --ftp-user username_cpanel \
     --ftp-pass password_cpanel \
     --ftp-dir /public_html
   ```

   *Opsi Tambahan:*
   - `--ftp-ssl`: Gunakan enkripsi FTPS/TLS jika hosting Anda mewajibkannya.
   - `--ftp-port 21`: Ubah port FTP jika berbeda dari port standar 21.
   - `--upload-zip-only`: Unggah berkas ZIP saja ke server untuk diekstrak manual melalui cPanel File Manager.

---

## 3. Cara 2: Manual via cPanel File Manager

1. **Build Berkas Rilis:**
   ```bash
   python3 tools/build_php_web.py
   ```
   Perintah ini akan menghasilkan berkas: `dist/tatap-web-php.zip` (~1 MB).

2. **Unggah ke Hosting:**
   - Buka **cPanel** > masuk ke **File Manager**.
   - Masuk ke direktori web target:
     - Untuk domain utama: direktori `public_html/`
     - Untuk subdomain atau subfolder: misalnya `public_html/tatap/` atau direktori root subdomain Anda.
   - Klik tombol **Upload**, pilih berkas `dist/tatap-web-php.zip`.

3. **Ekstrak Berkas:**
   - Klik kanan pada `tatap-web-php.zip` di File Manager > pilih **Extract**.
   - Pastikan berkas `.htaccess` ikut terekstrak (aktifkan opsi *Show Hidden Files / Dotfiles* di pengaturan cPanel File Manager).
   - Hapus berkas zip setelah diekstrak.

4. **Selesai!**
   Buka alamat website Anda di browser (misalnya `https://domainanda.com/` atau `https://domainanda.com/tatap/`).

---

## 4. Keamanan & Isolasi Data Multi-Visitor

- **Proteksi Database SQLite (`data/.htaccess`)**:
  Folder `data/` dilindungi oleh aturan Apache `Require all denied` sehingga berkas database `tatap.sqlite` dan berkas cache tidak dapat diunduh atau diakses secara langsung oleh pengunjung website.
- **Isolasi Riwayat & Bookmark Pengunjung**:
  Setiap pengunjung website secara otomatis diberikan token pengunjung unik yang disimpan dalam cookie dan header `X-Visitor-Token`. Riwayat tontonan (*watch history*), *bookmarks*, dan pengaturan (*settings*) setiap pengunjung terisolasi secara privat di database.

---

## 5. Dukungan Subfolder & Root Domain

Tatap Web PHP dirancang untuk mendeteksi lokasi pemasangannya secara otomatis:
- Jika dipasang di root domain (`https://nonton.com/`), API otomatis berjalan di `/api/...`.
- Jika dipasang di subfolder (`https://domainanda.com/tatap/`), frontend secara otomatis mendeteksi base path dan memanggil `/tatap/api/...` tanpa memerlukan konfigurasi manual.
