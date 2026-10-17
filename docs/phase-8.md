# Operasi dan penyerahan ResearchMate

Runtime yang direkomendasikan memakai Docker Compose: aplikasi Laravel, worker antrean, PostgreSQL, dan Qdrant. Chat dan embedding tetap melalui Senopati ITS. Instalasi langsung tetap tersedia untuk pengembangan. [README](../README.md) memuat langkah instalasi dan diagram terkini.

## Data dan kredensial

Source dan lockfile berada di Git. Kredensial berada di `.env` yang diabaikan Git. PDF, laporan, database, serta volume Qdrant merupakan data privat. Jangan mengganti APP_KEY pada instalasi berisi data.

Compose memisahkan volume PostgreSQL, Qdrant, serta storage aplikasi yang dipakai bersama oleh web dan worker. Hanya port web loopback yang dipublikasikan secara default. PostgreSQL dan Qdrant berada di jaringan internal Compose.

## Pembaruan instalasi

Cadangkan PostgreSQL dan storage privat sebelum migrasi. Pengindeksan lama tetap tersedia sebagai sumber rebuild dalam kolom embedding JSON. Migrasi tidak menghapus dokumen atau hasil riset. `ai:setup-vector` menyalin embedding tersimpan ke Qdrant tanpa melakukan embedding ulang seluruh PDF.

Setelah memperbarui source/image, jalankan migrasi dan sinkronisasi indeks sesuai README, kemudian restart worker. Jangan memakai `migrate:fresh`, `docker compose down -v`, atau database pengguna untuk pengujian.

## Backup dan pemulihan

Simpan dump PostgreSQL dan salinan `storage/app/private` dari waktu yang sama. Pada Docker, lokasi ini berada dalam volume app_storage. Qdrant dapat dibangun ulang dari data embedding di PostgreSQL melalui `ai:setup-vector`; snapshot Qdrant dapat mempercepat pemulihan.

Uji restore pada database dan volume baru sebelum memakai backup untuk pemulihan. Restore file saja tidak memulihkan metadata, akun, atau kutipan. Restore database saja tidak memulihkan PDF dan laporan.

## Antrean dan kesehatan

```powershell
php artisan ai:check
php artisan queue:failed
php artisan queue:retry ID_JOB
```

Worker memproses PDF, chat, agen, dan pembersihan titik vektor. Pekerjaan pembersihan dapat dicoba ulang setelah Qdrant pulih. Pencarian selalu memeriksa sumber aktif PostgreSQL sehingga titik sisa tidak membuka akses ke sumber yang sudah dihapus.

## Pemeriksaan sebelum digunakan

Jalankan pemeriksaan Composer, analisis statis, suite PostgreSQL, serta build frontend sesuai [testing](testing.md). Integrasi nyata Senopati memakai token dan kuota. Hasil mock tidak membuktikan kualitas kesimpulan ilmiah.

Runtime Windows portabel yang sudah ada tetap memakai cluster dan port pada `.env`. Skrip `scripts/start-local.ps1` tidak membuat ulang cluster. Instalasi Docker baru memakai volume terpisah dan tidak otomatis memindahkan database atau PDF dari instalasi langsung.

## Batas aplikasi

OCR belum tersedia. Analisis membaca seluruh teks yang terindeks dalam batas konfigurasi, tetapi detail tetap dapat terlewat saat peringkasan. Metadata dan APA/IEEE memerlukan tinjauan. Kandidat celah hanya berlaku pada koleksi proyek. Web server dan jaringan produksi memerlukan konfigurasi deployment sesuai lingkungan.

[Demo historis](demo.md) menyimpan hasil run terdahulu. Hasil tersebut bukan pengukuran Qdrant atau Docker terbaru.