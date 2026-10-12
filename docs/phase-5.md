# Fase 5 — Orkestrasi Multi-Agen PHP

## 1. Tujuan fase

Menjalankan enam agen sungguhan dengan alat terbatas, state persisten, antrean, log tahap, titik keputusan manusia, dan pemulihan kegagalan.

## 2. Keputusan arsitektur

Orkestrator merupakan state machine deterministik. Agen menghasilkan JSON melalui model lokal, sedangkan PHP menentukan transisi dan menjalankan alat berizin. Satu tahap diproses per job. Lock run mencegah worker menjalankan tahap yang sama bersamaan; PostgreSQL memakai advisory lock. Hasil `AgentStep` selesai dipakai kembali setelah gangguan, dengan identitas tahap/revisi.

Titik tunggu `awaiting_paper_selection` dan `awaiting_approval` tidak dilewati otomatis. Revisi verifikasi kembali ke sintesis, celah, lalu verifikasi, maksimal dua siklus. Setelah batas tercapai, masalah tetap ditandai dan hasil menunggu tinjauan manusia. Agen tidak mengeksekusi shell, SQL, atau kode dari keluaran model.

## 3. Perintah instalasi

Setelah Fase 2–4 siap:

```powershell
php artisan migrate
php artisan ai:check
php artisan queue:work --tries=3 --timeout=1800
```

Mulai workflow dari workspace proyek. Setelah perubahan kode/konfigurasi, `php artisan queue:restart` meminta worker membaca versi baru.

## 4. Berkas yang dibuat atau diubah

- [Enam agen](../app/Agents): `PlannerAgent`, `DiscoveryAgent`, `AnalysisAgent`, `SynthesisAgent`, `ResearchGapAgent`, `VerificationAgent`.
- [AgentInterface](../app/Services/AI/AgentInterface.php), [AbstractAgent](../app/Services/AI/AbstractAgent.php), [AgentContext](../app/Services/AI/AgentContext.php), [AgentResult](../app/Services/AI/AgentResult.php), [AgentRegistry](../app/Services/AI/AgentRegistry.php).
- [Template prompt agen](../resources/prompts/agents).
- [AgentOrchestrator](../app/Services/AI/AgentOrchestrator.php), [ToolRegistry](../app/Services/AI/ToolRegistry.php), [WorkflowStateManager](../app/Services/AI/WorkflowStateManager.php).
- [Job workflow](../app/Jobs/RunResearchWorkflow.php), [controller workflow](../app/Http/Controllers/ResearchWorkflowController.php).
- [AgentMonitor](../app/Livewire/AgentMonitor.php), [halaman monitor](../resources/views/research/agents.blade.php), [test workflow](../tests/Feature/ResearchWorkflowTest.php).

## 5. Kode sumber lengkap

Schema berada di kelas agen; prompt Markdown berada di `resources/prompts/agents` dan dimuat oleh `AbstractAgent`. `ToolRegistry` memuat implementasi alat PHP beserta pemeriksaan argumen, mode offline, dan kepemilikan sumber. Tautan bagian 4 memuat kode lengkap, termasuk transaksi penyimpanan output, recovery, dan loop revisi.

## 6. Perubahan konfigurasi

`AI_MAX_RETRIES=2` membatasi regenerasi dan revisi. `AI_MAX_TOOL_CALLS=40` membatasi alat, bukan memberi akses alat tak dikenal. Antrean database memakai `after_commit` dan `DB_QUEUE_RETRY_AFTER=1860`. Worker timeout 1800 detik harus lebih kecil daripada retry-after. Retry job transport berbeda dari revisi ilmiah workflow.

Pada PHP Windows native tanpa `pcntl`, timeout worker tidak menghentikan job sebagai batas total proses; batas HTTP tetap aktif. Demo memakai satu worker. Hasil analisis tiap artikel yang selesai disimpan untuk pemulihan, sedangkan advisory lock PostgreSQL mencegah tahap run yang sama dikerjakan bersamaan dan dilepas saat koneksi worker berakhir.

## 7. Perubahan database

`research_runs` menyimpan status, tahap aktif, rencana, workflow JSONB, retry, dan waktu. `agent_steps` menyimpan agen, tahap/revisi, input/output, durasi, dan error. Tahap utama: `planning`, `discovering`, `analyzing`, `synthesizing`, `identifying_gaps`, `verifying`. Status lain: `pending`, `awaiting_paper_selection`, `awaiting_approval`, `completed`, `failed`, `cancelled`. Hasil analisis, celah, klaim, dan bukti tersimpan pada tabel masing-masing.

## 8. Perintah pengujian

```powershell
php vendor/bin/phpunit tests/Feature/ResearchWorkflowTest.php
composer types:check
```

HTTP mock menguji tool calls nyata dari response model, seluruh transisi, checkpoint, revisi terbatas, pemulihan, reuse tahap selesai, penolakan alat asing, mode offline, sumber proyek asing, cancel, dan approval. Mock tidak membuktikan kualitas model nyata; [demo](demo.md) merupakan pemeriksaan terpisah.

## 9. Hasil yang diharapkan

Run dimulai `pending`, maju per tahap, berhenti untuk pemilihan artikel, kemudian kembali berjalan. Monitor menampilkan data run dan log sesungguhnya. Failure mempertahankan hasil selesai; tombol lanjutkan memulihkan dari tahap tersimpan. Cancel menyimpan pekerjaan yang sudah selesai dan mengembalikan proyek ke `draft`. Approval diterima hanya dengan checkbox persetujuan pada tahap yang benar.

## 10. Pemecahan masalah

Run yang menunggu pilihan/persetujuan memerlukan tindakan pengguna, bukan retry worker. Run gagal memerlukan perbaikan penyebab sebelum dilanjutkan. Jika worker mati, jalankan kembali; jangan membuat run baru untuk mengganti run belum selesai. Log tahap menunjukkan schema, tool, atau koneksi yang gagal. Gangguan setelah inferensi tetapi sebelum commit dapat memerlukan inferensi ulang; tahap yang sudah commit tidak dibuang.

## 11. Checklist penyelesaian

- [x] Enam agen menggunakan model, prompt, schema, dan izin alat.
- [x] State, hasil, dan jejak eksekusi persisten.
- [x] Pemilihan sumber, persetujuan, cancel, dan resume terhubung.
- [x] Batas revisi, recovery, idempotensi tahap selesai, serta offline diuji.
- [x] Workflow model nyata menyelesaikan enam agen dan satu siklus revisi pada perangkat demo; lihat [catatan run](demo.md).

Durasi pada perangkat lain dan mutu penafsiran setiap hasil tetap perlu ditinjau sebelum pemakaian akademik.

Fase berikutnya: [fitur riset](phase-6.md).
