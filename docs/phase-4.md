# Fase 4 — PDF Privat dan RAG

## 1. Tujuan fase

Menyimpan PDF lokal secara privat, mengekstrak teks dan nomor halaman, membuat chunk serta embedding melalui Senopati, dan mengambil bukti untuk percakapan proyek.

## 2. Keputusan arsitektur

Upload memeriksa MIME, ekstensi, header `%PDF-`, ukuran, dan nama berkas. Hash SHA-256 mencegah PDF identik masuk dua kali ke proyek. Job database memproses PDF dengan Smalot PdfParser, mempertahankan halaman asli, memecah teks berdasarkan halaman/paragraf, kemudian meminta embedding melalui Senopati dalam batch.

Chunk pengganti disimpan atomik setelah embedding berhasil. Retrieval PostgreSQL memakai cosine `<=>` dan filter proyek, status indeks, model, serta dimensi. Fallback cosine PHP hanya berlaku pada lingkungan test SQLite. Pada percakapan, model memilih `chunk_id`; PHP memeriksa bahwa ID terdapat dalam retrieval proyek dan mengambil kutipan langsung dari teks sumber asli. Schema menolak field kutipan buatan model, dan jawaban yang mengaku didukung tanpa referensi sah ditolak.

## 3. Perintah instalasi

Dependensi PDF sudah dikunci Composer. Pasang extension sesuai versi mayor PostgreSQL, lalu aktifkan sebagai administrator **di database aplikasi**:

```powershell
composer install
psql -h 127.0.0.1 -p 55433 -U postgres -d researchmate -c 'CREATE EXTENSION IF NOT EXISTS vector;'
php artisan migrate
php artisan ai:setup-vector
php artisan ai:check
php artisan queue:work --tries=3 --timeout=1800
```

Ganti port dan nama administrator pada instalasi berbeda. Role aplikasi tetap tanpa superuser. Extension juga diperlukan di database test PostgreSQL.

## 4. Berkas yang dibuat atau diubah

- [PdfParserService](../app/Services/Documents/PdfParserService.php), [DocumentChunkerService](../app/Services/Documents/DocumentChunkerService.php).
- [EmbeddingService](../app/Services/Documents/EmbeddingService.php), [VectorSearchService](../app/Services/Documents/VectorSearchService.php), [RetrievalService](../app/Services/Documents/RetrievalService.php).
- [EvidenceInvalidationService](../app/Services/Documents/EvidenceInvalidationService.php).
- [ProcessDocument](../app/Jobs/ProcessDocument.php), [AnswerResearchQuestion](../app/Jobs/AnswerResearchQuestion.php).
- [DocumentController](../app/Http/Controllers/DocumentController.php), [ResearchChatController](../app/Http/Controllers/ResearchChatController.php).
- [UI PDF](../resources/views/documents/index.blade.php), [UI chat](../resources/views/chat/index.blade.php).
- [SetupVectorCommand](../app/Console/Commands/SetupVectorCommand.php), [ReindexDocumentsCommand](../app/Console/Commands/ReindexDocumentsCommand.php), [test RAG](../tests/Feature/DocumentRagTest.php).

## 5. Kode sumber lengkap

Bagian 4 menautkan implementasi lengkap, termasuk penanganan PDF rusak/tanpa teks, transaksi embedding, seleksi sumber, validasi ID hasil retrieval, kutipan asli dari server, dan kegagalan job. [Model Document](../app/Models/Document.php) dan [PaperChunk](../app/Models/PaperChunk.php) menyimpan metadata ekstraksi dan indeks.

## 6. Perubahan konfigurasi

Batas awal PDF 20 MB, ukuran chunk 1200 karakter, overlap 200, retrieval enam sumber, dan maksimal 1000 chunk per PDF. PHP `upload_max_filesize` serta `post_max_size` harus cukup besar untuk batas aplikasi. PDF disimpan pada disk lokal privat, tanpa symlink publik. Antrean database memakai `retry_after=1860`; timeout worker 1800.

## 7. Perubahan database

`paper_chunks.embedding` memakai `vector` tanpa dimensi tetap. Setiap chunk mempunyai model dan dimensi. Instalasi nyata menghasilkan 768 dimensi melalui embeddinggemma:latest; kode tidak bergantung pada angka tersebut. Jika migrasi berjalan sebelum extension tersedia, kolom awal menjadi `text`. Setelah backup, `ai:setup-vector` mengonversinya melalui transaksi dengan SQL berikut:

```sql
ALTER TABLE paper_chunks ALTER COLUMN embedding TYPE vector USING embedding::vector;
```

Konversi gagal bila data tidak valid, tanpa menghapus chunk. Sesudah mengganti model/dimensi, jalankan `php artisan ai:reindex 123 --force` dengan ID proyek yang benar. Indeks lama dipertahankan sampai pengganti tiap PDF berhasil; referensi lama ditandai perlu tinjauan. Pencarian tepat digunakan; HNSW belum dipasang. Rujukan: [pgvector](https://github.com/pgvector/pgvector).

## 8. Perintah pengujian

```powershell
php vendor/bin/phpunit tests/Feature/DocumentRagTest.php tests/Feature/AiServiceTest.php
php artisan ai:check
```

Test memeriksa upload privat, duplikasi, izin, MIME/ukuran, ekstraksi, transaksi indeks, scope/model/dimensi, ID sumber sah/palsu, penolakan field kutipan dari model, pelestarian teks asli, indeks ulang, dan kegagalan. Pemeriksaan RAG terarah lulus 14 test/76 assertion. Suite PostgreSQL khusus menguji operator vector sungguhan; lihat [testing.md](testing.md).

## 9. Hasil yang diharapkan

PDF berubah dari `queued` ke `processing`, lalu `indexed` bila berhasil. Kegagalan memiliki `error_message`. Percakapan menampilkan sumber dan status bukti; tanpa sumber, hasil `insufficient_evidence` tidak diisi jawaban rekaan. PDF hanya bisa diunduh pemilik proyek.

## 10. Pemecahan masalah

PDF pindai tanpa teks memerlukan OCR eksternal sebelum upload; **OCR tidak diimplementasikan**. Queue tidak bergerak bila worker mati. Jika model berubah, filter dimensi/model dapat membuat sumber lama tidak cocok sampai reindex selesai. Gagal konversi vector: cadangkan dan periksa baris tidak valid, jangan mengosongkan tabel. Gagal inferensi: periksa layanan dan timeout, kemudian ulangi indeks.

## 11. Checklist penyelesaian

- [x] Upload, parsing, chunk, embedding, vector search, dan chat terhubung.
- [x] Nomor halaman berasal dari parser, bukan tebakan model.
- [x] Indeks ulang mempertahankan chunk lama saat proses pengganti gagal.
- [x] Bukti dan kepemilikan diperiksa pada server.
- [x] Lima PDF nyata terindeks dan dua jawaban RAG selesai pada perangkat demo; bukti ada di [demo.md](demo.md).

Saat pindah perangkat, ulangi pipeline dengan PDF pilihan dan tinjau makna jawaban terhadap sumber.

Fase berikutnya: [orkestrasi enam agen](phase-5.md).
