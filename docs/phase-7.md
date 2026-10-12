# Fase 7 — Pengalaman Pengguna dan Kualitas

## 1. Tujuan fase

Menyediakan navigasi fungsi nyata, tampilan responsif, state kosong/proses/gagal, lokalisasi, monitor, validasi, dan cakupan keamanan data pengguna.

## 2. Keputusan arsitektur

Blade dan komponen bersama menjaga konsistensi. Livewire memuat status monitor dari database. Dasbor memakai hitungan sebenarnya, bukan angka contoh. Label Bahasa Indonesia meliputi autentikasi, validasi, status, dan notifikasi. Tema terang/gelap/sistem dipertahankan. Tabel dapat digulir pada layar kecil; form mempunyai label dan pesan error.

Sesi, CSRF, throttle, policy, serta scope nested resource melindungi endpoint. PDF/laporan diunduh melalui controller; status API tidak membocorkan path atau embedding. Konten model ditampilkan sebagai teks yang di-escape.

## 3. Perintah instalasi

```powershell
npm.cmd ci
npm.cmd run build
php artisan view:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Untuk mengembangkan CSS/Blade, jalankan `npm.cmd run dev` pada terminal lain. Tidak ada font CDN wajib.

## 4. Berkas yang dibuat atau diubah

- [Layout](../resources/views/layouts), [komponen](../resources/views/components), [CSS](../resources/css/app.css).
- [Dasbor](../resources/views/dashboard.blade.php), [DashboardController](../app/Http/Controllers/DashboardController.php).
- [Proyek](../resources/views/projects), [PDF](../resources/views/documents), [riset](../resources/views/research), [chat](../resources/views/chat).
- [AgentMonitor](../app/Livewire/AgentMonitor.php), [status API](../app/Http/Controllers/ResearchApiController.php), [route](../routes/web.php).
- [Terjemahan](../lang/id.json), [validasi](../lang/id/validation.php), [auth](../lang/id/auth.php), [password](../lang/id/passwords.php).
- [Test antarmuka](../tests/Feature/ResearchInterfaceTest.php), [dasbor](../tests/Feature/DashboardTest.php), [lokalisasi](../tests/Feature/LocalizationTest.php), [autentikasi](../tests/Feature/Auth), [pengaturan](../tests/Feature/Settings).

## 5. Kode sumber lengkap

Seluruh tampilan, komponen, controller, dan test pada bagian 4 merupakan sumber lengkap. Navigasi fitur mengarah ke workspace proyek; keadaan tanpa proyek memberikan tindakan membuat proyek. Monitor memakai status/log persisten, bukan animasi kemajuan sukses buatan.

## 6. Perubahan konfigurasi

Locale dan fallback `id`; sesi terenkripsi memakai database. Aset dibangun lokal. Host server development `127.0.0.1`. `APP_DEBUG` hanya aktif untuk pengembangan lokal; ubah ke false bila aplikasi diakses pengguna lain. PHP Windows tidak memerlukan Pail untuk menjalankan server dan worker terpisah.

## 7. Perubahan database

Tidak ada migrasi UI tambahan. Hitungan dasbor berasal dari proyek pengguna dan relasi nyata. Monitor membaca run/step, dan status PDF membaca `documents.extraction_status/error_message`. Cache kesehatan Senopati merupakan hasil pemeriksaan eksplisit; belum diperiksa berbeda dari terhubung.

## 8. Perintah pengujian

```powershell
php vendor/bin/phpunit tests/Feature/ResearchInterfaceTest.php tests/Feature/DashboardTest.php tests/Feature/LocalizationTest.php
composer lint:check
composer types:check
npm.cmd run build
```

Uji browser manual: desktop dan seluler, buka sidebar, tema, proyek, PDF, literatur, matriks, celah, monitor, chat, laporan, dan pengaturan. Uji dua akun: URL proyek/dokumen/chunk/artifact milik akun lain harus ditolak. Catatan aktual ada di [testing.md](testing.md), bukan dianggap lulus hanya karena template tersedia.

## 9. Hasil yang diharapkan

Semua menu memiliki tujuan aktual. State queued/processing/failed ditampilkan jujur. API status hanya dapat diakses pemilik dengan sesi; pengguna anonim memperoleh respons autentikasi. Error form tampil dalam Bahasa Indonesia. Kegagalan model tidak ditampilkan sebagai riset selesai.

## 10. Pemecahan masalah

Aset hilang: build atau jalankan Vite; periksa `public/hot` yang menunjuk server lama. `419`: muat ulang form dan periksa host/kunci/sesi. Monitor diam: periksa worker dan polling. Notifikasi berhasil upload berarti PDF tersimpan/queued, bukan sudah terindeks. Theme mengacu pada pengaturan browser/pengguna; periksa kedua mode saat review.

## 11. Checklist penyelesaian

- [x] Navigasi dan komponen terhubung ke fitur nyata.
- [x] Label/error Indonesia serta state kosong/proses/gagal tersedia.
- [x] API/status dan resource privat mempunyai pemeriksaan akses.
- [x] Test, lint, dan analisis statis tersedia.
- [x] Review browser desktop/seluler, pencarian/urutan matriks, sumber/unduhan, serta console akhir dicatat di [testing.md](testing.md).

Pada perangkat lain, ulangi review browser sesuai ukuran dan konfigurasi setempat.

Fase berikutnya: [penyerahan akhir](phase-8.md).
