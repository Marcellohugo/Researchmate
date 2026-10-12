# Fase 3 — Gateway Senopati

## 1. Tujuan fase

Menghubungkan chat, agen, dan embedding ke gateway Senopati ITS tanpa model AI lokal.

## 2. Keputusan arsitektur

Satu SenopatiService memakai Laravel HTTP Client, endpoint HTTPS tetap, Bearer token, TLS aktif, dan redirect nonaktif. Keluaran JSON schema divalidasi di PHP; perbaikan dibatasi. Tool calling mempertahankan ID respons dan argumen tervalidasi.

## 3. Perintah instalasi

```powershell
composer install
php artisan config:clear
php artisan ai:check
```

Isi token, model chat qwen3:14b, dan model embedding embeddinggemma:latest di .env. Tidak perlu memasang runtime AI atau mengunduh model.

## 4. Berkas yang dibuat atau diubah

- [SenopatiService](../app/Services/AI/SenopatiService.php), [JsonSchemaValidator](../app/Services/AI/JsonSchemaValidator.php).
- [Config](../config/ai.php), [contoh env](../.env.example), [pengaturan](../resources/views/research/settings.blade.php).
- [Health command](../app/Console/Commands/AiHealthCommand.php), [test layanan](../tests/Feature/SenopatiServiceTest.php), [test nyata](../tests/Feature/SenopatiIntegrationTest.php).

## 5. Kode sumber lengkap

Berkas pada bagian 4 memuat implementasi lengkap: chat, structuredChat, generateEmbedding(s), listModels, checkConnection, validasi respons, dan penanganan HTTP/timeout tanpa membocorkan token.

## 6. Perubahan konfigurasi

SENOPATI_API_KEY, SENOPATI_CHAT_MODEL, SENOPATI_EMBED_MODEL, dan SENOPATI_TIMEOUT. AI_MAX_RETRIES membatasi perbaikan JSON. Model dan timeout dapat diubah tanpa source; restart worker setelah perubahan. [Panduan gateway](senopati.md) menjelaskan konfigurasi.

## 7. Perubahan database

Tidak ada tabel konfigurasi token. Model serta dimensi dicatat pada chunk; analisis mencatat model chat. Token hanya di environment privat. Model embedding yang diuji menghasilkan 768 dimensi; kode memvalidasi panjang sebenarnya.

## 8. Perintah pengujian

```powershell
php vendor/bin/phpunit tests/Feature/SenopatiServiceTest.php
$env:RUN_SENOPATI_TESTS = '1'
php vendor/bin/phpunit --group senopati
Remove-Item Env:RUN_SENOPATI_TESTS
```

Grup senopati memakai gateway nyata dan kuota; jalankan hanya dengan token yang tersedia. Test standar memakai HTTP mock.

## 9. Hasil yang diharapkan

Pengaturan menampilkan model chat/embedding gateway dan kesehatan koneksi. ai:check memeriksa embedding sebenarnya serta kolom vector. Kegagalan menghasilkan pesan/status gagal, tanpa fallback ke AI lokal.

## 10. Pemecahan masalah

401/403: token/izin kelompok. 429: batas penggunaan gateway; tunggu sebelum melanjutkan. Timeout: internet atau layanan gateway. JSON gagal: lihat batas percobaan dan feedback. Pergantian embedding: indeks ulang sebelum retrieval.

## 11. Checklist penyelesaian

- [x] Chat, embedding, daftar model, schema, dan tool calling terhubung ke gateway.
- [x] Token tidak ditampilkan dan endpoint tidak dapat diarahkan model ke host lain.
- [x] Tidak ada runtime AI lokal pada aplikasi atau startup.
- [x] Test mock dan test gateway nyata opsional tersedia.

Fase berikutnya: [PDF dan RAG](phase-4.md).
