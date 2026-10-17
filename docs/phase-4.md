# PDF privat dan pencarian dengan Qdrant

PDF disimpan privat. Worker mengekstrak halaman, membentuk chunk, lalu meminta embedding melalui gateway Senopati. Aplikasi tidak menjalankan model AI lokal.

PostgreSQL menyimpan metadata, teks, nomor halaman, serta salinan embedding JSON untuk membangun ulang indeks. Qdrant menyimpan vektor dan ID sumber. Teks PDF tidak disalin ke payload Qdrant. Collection dipisahkan menurut model dan dimensi.

Pencarian memfilter proyek, model, dimensi, serta ID chunk aktif. Server mengambil ulang teks dari PostgreSQL dan memeriksa dokumen berstatus indexed. Model memilih ID sumber, lalu server menyusun kutipan dari teks asli. Kebocoran sumber antar proyek dan kutipan buatan model ditolak.

## Instalasi dan migrasi

Ikuti [README](../README.md) untuk Docker atau instalasi langsung. PostgreSQL tidak memerlukan extension vektor.

```powershell
php artisan migrate
php artisan ai:setup-vector
php artisan ai:check
php artisan queue:work --tries=3 --timeout=1800
```

Cadangkan database dan storage privat sebelum memperbarui instalasi. Migrasi mengubah kolom embedding lama menjadi text dengan mempertahankan isinya. `ai:setup-vector` menyinkronkan embedding yang sudah tersimpan ke Qdrant, sehingga PDF lama tidak harus dikirim ulang ke gateway.

Jika model berubah, jalankan `php artisan ai:reindex ID_PROYEK --force`. Indeks pengganti disimpan per PDF setelah embedding dan upsert Qdrant berhasil. Transaksi PostgreSQL mempertahankan chunk dan bukti lama bila penggantian gagal. Pembersihan titik lama berjalan melalui antrean yang dapat dicoba ulang.

## Penghapusan dan pemulihan

Penghapusan dokumen/proyek menghapus data relasional dan berkas privat. Pembersihan Qdrant masuk antrean. Titik yang belum dibersihkan tidak dapat dipakai pencarian karena ID sumber aktif diperiksa kembali. Pantau failed jobs dan ulangi pekerjaan gagal setelah layanan pulih.

```powershell
php artisan queue:failed
php artisan queue:retry ID_JOB
php artisan ai:setup-vector
```

Qdrant dapat dibangun ulang dari PostgreSQL. Backup tetap mencakup PostgreSQL dan `storage/app/private`; snapshot Qdrant mempercepat pemulihan.

## Batas pemrosesan

PDF maksimal 20 MB, chunk 1200 karakter dengan overlap 200, retrieval enam sumber, dan maksimal 1000 chunk per PDF secara default. Konfigurasi tersedia di `.env.example`. Worker mempunyai timeout 1800 detik dan retry_after antrean 1860 detik.

PDF pindai memerlukan OCR di luar aplikasi. Kutipan yang cocok secara tekstual tetap membutuhkan penilaian manusia terhadap maknanya. Kegagalan gateway atau Qdrant ditampilkan sebagai kegagalan, tanpa pengganti hasil rekaan.

## Pemeriksaan

```powershell
php vendor/bin/phpunit tests/Feature/DocumentRagTest.php tests/Feature/QdrantServiceTest.php tests/Feature/VectorSetupTest.php
```

Lihat [panduan pengujian](testing.md) untuk database PostgreSQL terpisah dan perbedaan mock dengan integrasi nyata. Kontrak REST mengikuti [dokumentasi Qdrant](https://qdrant.tech/documentation/quickstart/).