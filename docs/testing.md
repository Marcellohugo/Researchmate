# Pengujian ResearchMate

## Lingkungan

Suite standar memakai SQLite :memory:, HTTP mock, token fixture, cache/session array, dan queue sync. Tidak memakai AI lokal atau menghubungi gateway nyata. Suite PostgreSQL memakai database khusus **researchmate_testing** karena RefreshDatabase mengubah tabel. Jangan memakai database researchmate milik pengguna sebagai target test.

Mock menguji kontrak API, validasi, kepemilikan, transisi, bounded retry, kegagalan, dan sumber. PostgreSQL menguji FK/JSONB/vector cosine sebenarnya. Test gateway nyata opsional menguji layanan Senopati, model chat/embedding, vektor finite, dan JSON schema. Hasil akademik tetap perlu tinjauan manusia.

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
```

## PostgreSQL khusus

Administrator membuat database khusus milik role aplikasi dan mengaktifkan extension vector. Contoh runtime portabel, sesuaikan port/admin:

```powershell
createdb -h 127.0.0.1 -p 55433 -U postgres -O researchmate researchmate_testing
psql -h 127.0.0.1 -p 55433 -U postgres -d researchmate_testing -c 'CREATE EXTENSION IF NOT EXISTS vector;'
```

Gunakan terminal test terpisah agar override tidak memengaruhi aplikasi:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '55433'
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

Extension aktif sebelum migrasi. Retrieval memfilter proyek, model, dimensi, dan status dokumen; cosine PHP hanya dipakai untuk SQLite saat testing.

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
| Akademik | OpenAlex/Crossref, cache/timeout/dedup, mode dokumen lokal tidak mengakses API akademik |
| UI/API | Halaman terhubung, hitungan nyata, policy, laporan privat, JSON status tanpa path privat |

## Hasil terakhir

Pada 14 Oktober 2026, suite SQLite lulus **128 test: 126 lulus, 2 dilewati, 648 assertion**; dua skip merupakan gateway nyata opsional dan pemeriksaan khusus PostgreSQL. Suite PostgreSQL lulus **128 test: 127 lulus, 1 dilewati, 653 assertion**. Pint dan PHPStan lulus. CI menjalankan build, pemeriksaan gaya, analisis statis, dan suite PostgreSQL pada setiap push ke main; hasil tiap commit tersedia di GitHub Actions.

Regresi baru mencakup hasil terpisah per run, riwayat analisis artikel yang sama, pemulihan checkpoint historis tanpa menebak run, rollback yang menjaga riwayat, pembatalan saat inferensi, sumber chat yang berubah, larangan perubahan saat workflow aktif, batas koleksi OpenAlex, pemilihan cuplikan dari bagian akhir, dan retry embedding yang memakai ulang batch berhasil. Checkpoint embedding memakai cache aplikasi selama 24 jam, kemudian dibersihkan setelah indeks berhasil diganti; gunakan cache database/file yang persisten untuk mempertahankannya antar-worker.

Bukti demo akademik 9 Oktober ada di [demo](demo.md) dan merupakan run historis sebelum seluruh AI dipindahkan ke gateway. Mutu atribusi temuan tidak dapat dinyatakan lulus hanya berdasarkan test perangkat lunak. OCR belum tersedia; AnalysisAgent membaca maksimal 12 chunk terpilih dari bagian utama dan sampel merata per artikel. Chat memakai retrieval seluruh indeks yang cocok model/dimensi.
