# Pengujian ResearchMate

## Lingkungan

Suite standar memakai PostgreSQL pada database khusus **researchmate_testing**, HTTP mock, token fixture, cache/session array, dan queue sync. Test menolak database yang tidak berakhiran `_test` atau `_testing` serta DB_URL yang terisi sebelum menjalankan migrasi test. Jangan memakai database pengguna sebagai target pengujian.

Mock menguji kontrak REST Qdrant dan Senopati, validasi vektor, filter proyek/model/dimensi, rollback indeks, serta retry pembersihan. PostgreSQL menguji FK, JSONB, dan transaksi sebenarnya. Mock tidak membuktikan server Qdrant atau gateway nyata tersedia. Integrasi nyata dijalankan terpisah.

Ekspor Word/PDF memakai PHPWord dan Dompdf. Test renderer memeriksa isi DOCX/XML dan teks PDF. Pemeriksaan struktur bukan pengganti pemeriksaan visual.

## Perintah standar

```powershell
composer validate --strict
composer check-platform-reqs
composer test
npm.cmd run build
```

composer test menjalankan Pint, PHPStan level 7, serta PHPUnit. Test terarah:

```powershell
php vendor/bin/phpunit tests/Feature/SenopatiServiceTest.php tests/Feature/AiServiceTest.php
php vendor/bin/phpunit tests/Feature/DocumentRagTest.php tests/Feature/ResearchWorkflowTest.php
php vendor/bin/phpunit tests/Feature/AnalysisSourceTest.php tests/Feature/DeepAnalysisTest.php
php vendor/bin/phpunit tests/Feature/PaperMetadataTest.php tests/Feature/CitationFormatterTest.php
php vendor/bin/phpunit tests/Feature/ReportDocumentRendererTest.php tests/Feature/ReportExportTest.php
```

## PostgreSQL khusus

Administrator membuat database khusus milik role aplikasi. Extension vektor tidak diperlukan. Contoh runtime portabel, sesuaikan port/admin:

```powershell
createdb -h 127.0.0.1 -p 54329 -U postgres -O researchmate researchmate_testing
```

Gunakan terminal test terpisah agar override tidak memengaruhi aplikasi:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '54329'
$env:DB_DATABASE = 'researchmate_testing'
$env:DB_USERNAME = 'researchmate'
$env:DB_URL = ''
$rmTestPassword = Read-Host 'Kata sandi database test' -AsSecureString
$env:DB_PASSWORD = [System.Net.NetworkCredential]::new('', $rmTestPassword).Password
php artisan config:clear
php vendor/bin/phpunit
Remove-Item Env:DB_PASSWORD
# Tutup terminal test setelah selesai.
```

Retrieval Qdrant memfilter proyek, model, dimensi, dan ID chunk aktif. PostgreSQL memeriksa kembali sumber dan status dokumen. Tidak ada backend pencarian khusus untuk test.

## Gateway nyata

Token Senopati harus tersedia di .env. Test ini memakai internet dan kuota:

```powershell
$env:RUN_SENOPATI_TESTS = '1'
php vendor/bin/phpunit --group senopati
Remove-Item Env:RUN_SENOPATI_TESTS
php artisan ai:check
```

Pada 11 Oktober 2026, test gateway nyata lulus **1 test / 7 assertion**. Model embedding gateway embeddinggemma:latest menghasilkan **768 dimensi**. Chat qwen3:14b, JSON schema, dan siklus alat dengan hasil diteruskan ke model telah diuji nyata. Retrieval nyata berhasil mengembalikan enam sumber milik proyek. Data indeks demo dimigrasikan ke 338 vector gateway tanpa mengganti ID/teks chunk; status run historis tetap completed. Gateway sempat timeout/429; checkpoint dan transaksi mencegah indeks campuran.

## Cakupan

| Area | Pemeriksaan |
| --- | --- |
| Auth/proyek | Registrasi/login/logout/password, input, policy, isolasi pengguna, CRUD, duplikasi/riwayat/cascade |
| Gateway | Bearer, endpoint tetap, error tanpa token, timeout/HTTP/redirect, schema/perbaikan, argumen dan ID alat |
| Embedding | Respons sesuai input/index, finite/nonzero, dimensi konsisten, urutan respons |
| PDF/RAG | MIME/header/ukuran/hash, parsing/halaman, chunk, indeks atomik, cosine/scoping, sumber asli, citation palsu |
| Workflow | Enam agen, allowlist/budget, checkpoint, bounded revision, retry/recovery/cancel/approval |
| Analisis artikel | Seluruh chunk terindeks, batas batch/teks, penggabungan hierarkis, satu generasi per eksekusi, checkpoint/retry, pembatalan, kutipan sesuai lingkup sumber |
| Akademik | OpenAlex/Crossref, cache/timeout/dedup, mode dokumen lokal tidak mengakses API akademik |
| Metadata/sitasi | Override per proyek, validasi DOI/URL/penulis, penguncian saat workflow aktif, APA/IEEE, metadata tidak lengkap, isolasi artikel bersama |
| Ekspor | Markdown terbaca, DOCX/XML, teks PDF, run terbaru, status draf, metadata proyek, jenis berkas/MIME, unduhan privat dan policy |
| UI/API | Halaman terhubung, hitungan nyata, policy, laporan privat, JSON status tanpa path privat |

## Hasil terakhir

Pada 17 Oktober 2026, suite PostgreSQL lulus **176 test**, dengan **1 test gateway opsional dilewati**, dari **177 test** dan **1.035 assertions**. Composer validate --strict, Pint, PHPStan level 7, dan build Vite berhasil. Cakupan tambahan meliputi kontrak Qdrant, penulisan indeks gagal, input topik yang berubah, pengurutan artikel sesuai hasil retrieval, serta antrean pembersihan dokumen/proyek/akun.

Suite yang sama juga lulus dalam image Docker PHP 8.3.35 dengan PostgreSQL terpisah: 176 lulus, 1 dilewati, 1.035 assertions, tanpa warning. Pint dan PHPStan lulus dalam image pengujian. Image produksi tidak memuat `.env` atau `.env.docker`, dan tidak menerima kata sandi administrator PostgreSQL.

Pemeriksaan layanan nyata pada Docker berhasil: aplikasi dan PostgreSQL/Qdrant sehat, worker berjalan, `ai:setup-vector` menyinkronkan 348 embedding tersimpan, dan `ai:check` mendeteksi embedding Senopati 768 dimensi. Pencarian vektor yang sama mengembalikan sumber terdekat dengan skor sekitar 1. Pencarian dengan topik serta pertanyaan riset mengembalikan enam sumber yang tetap berada dalam proyeknya.

Pemindahan data memverifikasi jumlah tetap 2 akun, 3 proyek, 15 dokumen, 348 chunk, 2 run, dan 1 laporan. Seluruh 15 PDF cocok dengan hash aslinya dan laporan tersedia dalam storage Docker. Backup sebelum dan setelah perubahan tipe embedding tersimpan privat di direktori backup runtime. Pemeriksaan ini membuktikan integrasi dan pelestarian data, bukan akurasi akademik atau mutu ranking untuk seluruh topik.

CI menjalankan build, pemeriksaan gaya, analisis statis, dan suite PostgreSQL pada setiap push ke main; hasil tiap commit tersedia di GitHub Actions. Suite standar melewati test gateway nyata opsional. Semua test database memakai PostgreSQL. Docker menyediakan profile test dengan database terpisah sesuai README.

Regresi mencakup hasil terpisah per run, riwayat analisis artikel yang sama, pemulihan checkpoint historis tanpa menebak run, rollback yang menjaga riwayat, pembatalan saat inferensi, sumber chat yang berubah, larangan perubahan saat workflow aktif, batas koleksi OpenAlex, dan retry embedding yang memakai ulang batch berhasil. Checkpoint embedding memakai cache aplikasi selama 24 jam, kemudian dibersihkan setelah indeks berhasil diganti; gunakan cache database/file yang persisten untuk mempertahankannya antar-worker.

Analisis bertahap menyimpan checkpoint batch dan penggabungan ringkasan pada `agent_steps`, dengan tanda tangan sumber untuk menghindari pemakaian hasil dari input berbeda. Test mencakup artikel melebihi 12 chunk, kelanjutan job yang tidak dihitung sebagai kegagalan/retry, penggunaan ulang batch berhasil, penolakan kutipan dari luar batch atau hasil penggabungan yang sah, dan batas sumber. Hasil final mencatat jumlah chunk sumber/teranalisis, jumlah batch, serta tingkat penggabungan. Metadata suntingan disimpan khusus pada `project_papers`; test memeriksa proyek lain dan artikel global tetap terisolasi. Ekspor diuji dalam ketiga format dengan metadata proyek dan sumber pada run terbaru.

Bukti demo akademik 9 Oktober ada di [demo](demo.md) dan merupakan run historis sebelum seluruh AI dipindahkan ke gateway. Bukti tersebut tidak menguji mutu peringkasan seluruh teks yang ditambahkan kemudian. Mutu atribusi temuan tidak dapat dinyatakan lulus hanya berdasarkan test perangkat lunak. Analisis baru membaca seluruh chunk terindeks dalam batas konfigurasi; run historis tetap memiliki batas maksimal 12 cuplikan. OCR belum tersedia, gambar/tabel yang gagal diekstrak tidak diperiksa, dan detail dapat terlewat saat penggabungan ringkasan. Chat memakai retrieval seluruh indeks yang cocok model/dimensi. APA/IEEE tetap memerlukan tinjauan metadata dan aturan penerbit; unduhan daftar pustaka teks tidak memiliki italic atau hanging indent.
