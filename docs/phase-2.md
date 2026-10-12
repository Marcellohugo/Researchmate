# Fase 2 — Database dan Proyek Riset

## 1. Tujuan fase

Menyediakan proyek milik pengguna, validasi formulir, pencarian, workspace, riwayat, duplikasi pengaturan, dan skema untuk seluruh pipeline riset.

## 2. Keputusan arsitektur

`ResearchProjectPolicy` memeriksa kepemilikan pada setiap operasi. Query daftar berawal dari relasi pengguna, termasuk saat pencarian. `ProjectRequest` memvalidasi masukan; controller menyimpan hanya field tervalidasi. Perubahan proyek dan riwayatnya menggunakan transaksi. Duplikasi menyalin pengaturan, tanpa menyalin PDF, percakapan, hasil, atau run.

Metadata artikel bersifat global melalui `papers`; akses literatur pengguna melewati `project_papers`. Data proyek menggunakan cascade, sedangkan berkas privat dihapus setelah transaksi commit. Metadata global tetap ada.

## 3. Perintah instalasi

Ikuti [instalasi utama](../README.md#instalasi-baru), isi `.env`, lalu:

```powershell
composer install
php artisan config:clear
php artisan migrate
php artisan migrate:status
```

Aktifkan pgvector sebelum migrasi bila akan langsung memakai RAG. Tidak ada seeder hasil riset atau kebutuhan menjalankan `migrate:fresh`.

## 4. Berkas yang dibuat atau diubah

- [Migrasi riset](../database/migrations/2026_10_09_000003_create_research_tables.php).
- [Model](../app/Models), [User](../app/Models/User.php), [factory proyek](../database/factories/ResearchProjectFactory.php).
- [Policy](../app/Policies/ResearchProjectPolicy.php), [request](../app/Http/Requests/ProjectRequest.php), [controller](../app/Http/Controllers/ResearchProjectController.php).
- [Route](../routes/web.php), [tampilan proyek](../resources/views/projects), [navigasi workspace](../resources/views/components/research/project-nav.blade.php).
- [Test proyek](../tests/Feature/ResearchProjectTest.php).

## 5. Kode sumber lengkap

Tautan berkas dan direktori pada bagian 4 mengarah ke implementasi lengkap di repositori ini. Model riset: `ResearchProject`, `Paper`, `ProjectPaper`, `Document`, `PaperChunk`, `ResearchRun`, `AgentStep`, `PaperAnalysis`, `ResearchGap`, `Claim`, `ClaimEvidence`, `ResearchArtifact`, `ResearchMessage`, dan `ProjectHistory`. Model `User` mempunyai relasi `projects`.

## 6. Perubahan konfigurasi

PostgreSQL merupakan database runtime; sesi, cache, dan antrean memakai database. `AI_MAX_PAPERS=20` membatasi target. Tahun awal/akhir boleh kosong; bila keduanya diisi, tahun akhir harus sama atau sesudah tahun awal. Bahasa `id`/`en`, mode `offline`/`online`, serta target minimal satu artikel divalidasi pada server. Field `user_id` dan `status` dari pengguna tidak dipercaya.

## 7. Perubahan database

Skema menambahkan `research_projects`, `papers`, `project_papers`, `documents`, `paper_chunks`, `research_runs`, `agent_steps`, `paper_analyses`, `research_gaps`, `claims`, `claim_evidence`, `research_artifacts`, `research_messages`, dan `project_history`. Foreign key, constraint unik pivot, hash PDF per proyek, indeks, dan JSONB ada dalam migrasi. [ERD](../README.md#data-dan-status-bukti) memperlihatkan relasinya.

## 8. Perintah pengujian

```powershell
php vendor/bin/phpunit tests/Feature/ResearchProjectTest.php
composer types:check
composer lint:check
```

Test mencakup CRUD, scope pengguna, proyek asing, validasi, pencarian, duplikasi, riwayat, serta penghapusan data proyek tanpa menghapus metadata global. SQLite terpisah dari data aplikasi; pengujian PostgreSQL memakai database khusus sesuai [panduan test](testing.md).

## 9. Hasil yang diharapkan

Daftar kosong menampilkan keadaan kosong yang sebenarnya. Proyek baru mempunyai status `draft`, target dan pemilik tervalidasi, serta catatan riwayat. Mengubah ID URL tidak memberi akses ke proyek pengguna lain. Duplikat muncul sebagai proyek baru dengan pengaturan yang sama dan koleksi kosong.

## 10. Pemecahan masalah

Jika tabel belum ditemukan, periksa database aktif lalu jalankan migrasi. `403` pada proyek asing merupakan penolakan yang benar. Jika file belum bisa dihapus, periksa izin storage; penghapusan berkas terjadi sesudah commit, bukan di tengah transaksi. Cadangkan database dan storage sebelum operasi penghapusan data nyata.

## 11. Checklist penyelesaian

- [x] Migrasi, model, relasi, factory, dan policy tersedia.
- [x] CRUD, pencarian, workspace, duplikasi, dan riwayat terhubung.
- [x] Validasi dan kepemilikan diuji otomatis.
- [x] Data privat dan metadata global mempunyai siklus hidup berbeda.
- [ ] Ulangi pengujian pada komputer tujuan mengikuti `testing.md`.

Fase berikutnya: [integrasi Senopati](phase-3.md).
