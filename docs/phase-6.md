# Fase 6 — Literatur, Sintesis, Celah, dan Laporan

## 1. Tujuan fase

Menghubungkan penemuan literatur opsional, seleksi artikel, analisis sumber, matriks interaktif, kandidat celah, pemeriksaan bukti, percakapan, serta ekspor laporan.

## 2. Keputusan arsitektur

Mode offline membaca koleksi PDF proyek dan tidak meminta OpenAlex/Crossref. Mode online memanggil host API akademik tetap; metadata dinormalisasi, DOI dibersihkan, hasil dideduplikasi dan disimpan, serta pencarian dicache. Error tidak mengganti hasil tersimpan dengan data rekaan.

Analisis mengembalikan `null` bila informasi tidak ditemukan. Kutipan harus merujuk sumber proyek yang benar dan cocok dengan teks tersimpan. Dukungan lokal dan status bibliografi eksternal dibedakan. Celah merupakan kandidat terbatas koleksi, termasuk keterbatasannya. Approval manusia mencatat tinjauan, tanpa mengubah status klaim unsupported menjadi verified.

Pemeriksaan kutipan memastikan hubungan sumber dan kecocokan teks, tetapi belum memastikan kebenaran penafsiran atau atribusi setiap field. UI memakai label **“Kutipan cocok — tinjau makna”** untuk `supported`. Temuan studi terdahulu yang dikutip dalam pengantar harus dibedakan dari hasil penelitian artikel itu sendiri.

## 3. Perintah instalasi

```powershell
composer install
php artisan migrate
php artisan ai:check
php artisan queue:work --tries=3 --timeout=1800
```

Untuk online, pastikan PHP dapat memvalidasi sertifikat TLS. Jika runtime portabel belum mempunyai CA bundle, gunakan CA resmi dan atur `curl.cainfo`/`openssl.cafile`; jangan menonaktifkan TLS. Pada lingkungan implementasi, bundle CA resmi sudah dipasang pada runtime PHP.

## 4. Berkas yang dibuat atau diubah

- [OpenAlexService](../app/Services/Academic/OpenAlexService.php), [CrossrefService](../app/Services/Academic/CrossrefService.php).
- [DiscoveryAgent](../app/Agents/DiscoveryAgent.php), [AnalysisAgent](../app/Agents/AnalysisAgent.php), [SynthesisAgent](../app/Agents/SynthesisAgent.php), [ResearchGapAgent](../app/Agents/ResearchGapAgent.php), [VerificationAgent](../app/Agents/VerificationAgent.php).
- [LiteratureController](../app/Http/Controllers/LiteratureController.php), [ResearchResultsController](../app/Http/Controllers/ResearchResultsController.php), [ResearchChatController](../app/Http/Controllers/ResearchChatController.php).
- [UI literatur/matriks/celah/bukti/laporan](../resources/views/research), [UI chat](../resources/views/chat/index.blade.php).
- [Test akademik](../tests/Feature/AcademicServiceTest.php), [test workflow](../tests/Feature/ResearchWorkflowTest.php), [test antarmuka](../tests/Feature/ResearchInterfaceTest.php).

## 5. Kode sumber lengkap

Tautan bagian 4 memuat kode lengkap. Matriks dapat dicari dan diurutkan berdasarkan judul, tahun, atau metode. Halaman bukti memeriksa hubungan chunk ke proyek sebelum menampilkan teks. Laporan Markdown mencantumkan pertanyaan, mode/status run, sintesis, matriks, akses sumber, kandidat celah, keterbatasan, status klaim, dan kutipan.

## 6. Perubahan konfigurasi

`OPENALEX_API_KEY` opsional; bila terisi dikirim sebagai Bearer token. [OpenAlex](https://help.openalex.org/api/authentication/) mengizinkan kueri dasar tanpa key dan menyediakan key gratis; kapasitasnya mengikuti aturan layanan. `ACADEMIC_API_TIMEOUT` mengatur kedua layanan dengan default 15 detik dan batas 1–120 detik. Seluruh inferensi AI melalui Senopati dan memerlukan internet; mode dokumen lokal hanya menonaktifkan API akademik. Pada runtime implementasi, `php.ini` menunjuk CA bundle `%LOCALAPPDATA%\ResearchMate\runtime\php\cacert.pem`.

## 7. Perubahan database

Literatur memakai `papers` dan pivot pilihan/ranking/status analisis. `paper_analyses` menyimpan JSON dan akses sumber. `research_gaps` menyimpan kandidat dan referensi pendukung. `claims` serta `claim_evidence` mencatat verifikasi. `research_artifacts` menunjuk berkas Markdown privat; `research_messages` menyimpan percakapan dan status bukti. Semua pembacaan proyek melewati policy.

## 8. Perintah pengujian

```powershell
php vendor/bin/phpunit tests/Feature/AcademicServiceTest.php tests/Feature/ResearchWorkflowTest.php tests/Feature/ResearchInterfaceTest.php
```

Suite memakai HTTP mock untuk cache, error/rate-limit, normalisasi, deduplikasi, host tetap, dan larangan permintaan eksternal offline. Pemeriksaan CLI nyata pada lingkungan implementasi memperoleh tiga record OpenAlex tanpa key serta mencocokkan DOI `10.1038/nature14539` di Crossref dengan judul “Deep learning”; ini terpisah dari hasil demo offline.

## 9. Hasil yang diharapkan

Pengguna memilih sumber sebelum analisis. Matriks menampilkan informasi ditemukan/tidak ditemukan dan sumber. Kandidat celah memiliki referensi serta peringatan keterbatasan. Klaim tanpa kutipan sah ditandai. Laporan dapat diekspor setelah verifikasi pada `awaiting_approval` atau `completed`, dengan status run tercantum. Unduhan membutuhkan pemilik proyek dan artifact yang sesuai.

## 10. Pemecahan masalah

Online gagal TLS: periksa CA bundle PHP dan restart proses. `401`/`403`: periksa key lokal; `429`: tunggu sebelum mengulang. Metadata saja tidak membuat PDF otomatis tersedia. **AnalysisAgent memakai maksimal 12 chunk terpilih per artikel dari bagian utama dan sampel merata**; `full_text` berarti PDF terindeks tersedia, bukan seluruh teks dibaca model. Informasi di halaman akhir/tabel mungkin tidak tercakup: periksa PDF dan gunakan chat retrieval untuk tinjauan tambahan. Jangan menafsirkan kandidat celah sebagai survei seluruh literatur dunia.

## 11. Checklist penyelesaian

- [x] OpenAlex opsional, normalisasi, cache, dan error terhubung.
- [x] Seleksi, matriks, celah, bukti, chat, dan laporan tersedia.
- [x] Bukti lokal dan metadata eksternal memiliki status terpisah.
- [x] Konteks terbatas dan status draf dijelaskan.

Pengguna perlu meninjau kualitas setiap keluaran ilmiah terhadap sumber asli sebelum memakainya. Persetujuan pada demo merupakan uji fungsi, bukan pengesahan ilmiah; [hasil demo](demo.md) mencatat contoh atribusi yang perlu diperiksa.

Fase berikutnya: [pengalaman pengguna dan kualitas](phase-7.md).
