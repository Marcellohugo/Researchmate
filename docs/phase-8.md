# Fase 8 — Penyerahan dan Operasi Lokal

## 1. Tujuan fase

Menyerahkan kode runnable, migrasi, diagram, panduan instalasi, pengujian, operasi, batas implementasi, serta demo akademik yang memakai lima dokumen nyata.

## 2. Keputusan arsitektur

Repositori menyimpan source dan lockfile. Runtime, kredensial, model, database, PDF, laporan, dan backup berada di luar Git atau storage privat yang diabaikan Git. Jalankan lokal melalui PHP, PostgreSQL/pgvector, gateway Senopati, dan worker database. Diagram arsitektur, ERD, workflow, dan sequence terdapat pada [README](../README.md).

Untuk lingkungan bersama, gunakan web server PHP yang sesuai, HTTPS, `APP_DEBUG=false`, serta permission filesystem yang membatasi akun layanan. Server `artisan serve` digunakan untuk pengembangan/demo lokal. Tidak ada klaim deployment publik atau audit keamanan produksi yang belum dilakukan.

## 3. Perintah instalasi

Ikuti README untuk role/database/extension dan `.env`. Setelah prasyarat siap:

```powershell
composer install
npm.cmd ci
npm.cmd run build
php artisan migrate
php artisan ai:setup-vector
php artisan ai:check
composer test
```

Jalankan server/worker sesuai README atau gunakan skrip Windows pada runtime yang sudah disiapkan:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
```

Skrip memulai layanan lokal yang tersedia, menjalankan migrasi, dan menyimpan log proses. Dependensi, aset, token Senopati, dan extension harus sudah siap. Skrip tidak memulai AI lokal; contoh PATH runtime ada di [README](../README.md#menjalankan-aplikasi). Tidak perlu Docker untuk runtime portabel Windows ini.

## 4. Berkas yang dibuat atau diubah

- [README](../README.md), [dokumen fase](.), [demo](demo.md), [testing](testing.md).
- [Lock Composer](../composer.lock), [lock npm](../package-lock.json), [.env.example](../.env.example).
- [Migrasi](../database/migrations), [test](../tests), [workflow CI](../.github/workflows/tests.yml), [PHPStan](../phpstan.neon).
- [Perintah AI](../app/Console/Commands), [antrean](../config/queue.php), [storage](../config/filesystems.php).
- [Skrip startup Windows](../scripts/start-local.ps1).

## 5. Kode sumber lengkap

Source aplikasi tersedia dalam [app](../app), [resources](../resources), [routes](../routes), [config](../config), dan [database](../database). Dokumen tiap fase menautkan berkas utama tanpa menggandakan kode yang dapat menjadi usang. Kode test dapat diperiksa untuk kontrak kegagalan dan keamanan.

## 6. Perubahan konfigurasi

Lingkungan implementasi memakai PG16 port `55433`, cluster `%LOCALAPPDATA%\ResearchMate\postgres16-data`; PG18 port `55432` tetap terpisah. Lokasi executable PG16: `%LOCALAPPDATA%\ResearchMate\runtime\postgresql16\pgsql\bin`. Chat dan embedding melalui gateway Senopati HTTPS. Jangan membagikan `.env`, credential DPAPI, log reset password, data cluster, atau PDF/laporan privat.

Sebelum update, cadangkan database serta `storage/app/private`. Contoh backup memakai role aplikasi dan prompt password, tanpa password pada command:

```powershell
$rmBackup = Join-Path $env:LOCALAPPDATA 'ResearchMate\backups'
New-Item -ItemType Directory -Force -Path $rmBackup | Out-Null
$rmBackupFile = Join-Path $rmBackup ('researchmate-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.dump')
pg_dump -h 127.0.0.1 -p 55433 -U researchmate -d researchmate -W -Fc -f "$rmBackupFile"
```

Periksa exit code dan simpan backup dengan izin privat. Restore harus diuji pada database baru yang terpisah; jangan menimpa data pengguna untuk latihan.

## 7. Perubahan database

Tidak ada tabel penyerahan tambahan. Migrasi lengkap menyiapkan auth/cache/queue serta seluruh skema riset. Extension aktif per database. Perubahan tipe embedding memakai `ai:setup-vector` setelah backup; perubahan model memerlukan indeks ulang tiap proyek. `.env.example` memakai port umum `5432`, bukan lokasi khusus komputer implementasi.

## 8. Perintah pengujian

```powershell
composer validate --strict
composer check-platform-reqs
composer test
npm.cmd run build
php artisan ai:check
```

Jalankan suite PostgreSQL khusus dan grup Senopati mengikuti [testing.md](testing.md). Lakukan [demo](demo.md) sampai pemilihan sumber, tinjauan verifikasi, persetujuan manusia, dan unduhan laporan. CI dikonfigurasi, tetapi status eksekusi remote harus diperiksa terpisah.

## 9. Hasil yang diharapkan

Instalasi dapat menjalankan fitur auth sampai laporan lokal. Daftar verifikasi membedakan suite mock, operator vector nyata, chat/embedding nyata, serta demo penuh. Hasil bermasalah tetap mempunyai status yang jujur. Dokumen ini bukan bukti bahwa setiap run menghasilkan kesimpulan ilmiah benar.

Pada 9 Oktober 2026, proyek/run 1 selesai setelah enam agen dan satu revisi. Dua jawaban RAG selesai; laporan 32.570 byte diunduh melalui GUI dan SHA-256 cocok dengan artifact privat. Persetujuan pukul 22:02 pada akun penguji merupakan uji alur aplikasi, bukan pengesahan kesimpulan ilmiah. [Demo](demo.md) memuat bukti dan [testing](testing.md) mencatat suite akhir: 95 test SQLite/PostgreSQL, Pint/PHPStan, serta build berhasil.

## 10. Pemecahan masalah

Runtime setelah reboot: mulai PG16, kemudian server/worker. Konfigurasi berubah: clear config dan restart worker. Disk penuh/permission gagal: periksa storage sebelum mengulang. **OCR belum tersedia, analisis agen dibatasi 12 chunk awal, dan metadata offline belum diverifikasi eksternal**. Exact vector search belum memakai HNSW. Laporan saat menunggu approval merupakan draf dengan status tercantum.

## 11. Checklist penyelesaian

- [x] Source, migrasi, lockfile, dan panduan setiap fase tersedia.
- [x] Arsitektur, ERD, workflow, dan sequence didokumentasikan.
- [x] Setup, reindex aman, operasi lokal, backup, dan test dijelaskan.
- [x] PDF demo dan asal metadata nyata dicatat tanpa hasil rekaan.
- [x] Bukti demo penuh, persetujuan uji fungsional, dua jawaban RAG, unduhan dengan hash cocok, dan screenshot akhir dicatat.
- [x] Batas konteks, metadata offline, serta kebutuhan tinjauan makna kutipan dijelaskan.

Pengguna tetap perlu menilai atribusi dan kesimpulan terhadap PDF asli sebelum memakai hasil akademik. Ini batas penggunaan keluaran model, bukan fase implementasi yang belum dikerjakan.
