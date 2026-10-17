# Fase 1 — Fondasi Proyek

**Catatan historis:** dokumen ini merekam kondisi setelah Fase 1. Fitur riset kini sudah diimplementasikan pada Fase 2–8, dan runtime kini memakai PostgreSQL dan Qdrant melalui Docker atau instalasi langsung sesuai README. Catatan teknologi dan angka di bawah adalah riwayat, bukan konfigurasi aktif. Seluruh AI kini melalui gateway Senopati; konfigurasi AI lokal yang disebut dalam catatan historis ini sudah dihapus dari aplikasi. Gunakan [README terkini](../README.md) untuk instalasi, konfigurasi, serta pengujian; angka test dan status menu di bawah merupakan hasil Fase 1.

Dokumen ini mendeskripsikan implementasi pada `C:\Users\marco\Documents\Github\Researchmate`. Kode lengkap tersimpan langsung di repositori; tautan di bawah membuka berkas yang digunakan aplikasi. [README](C:/Users/marco/Documents/Github/Researchmate/README.md) memuat panduan penggunaan dan diagram sistem.

## 1. Tujuan fase

Menyediakan Laravel 13 yang dapat dipasang dan dijalankan lokal dengan PostgreSQL, autentikasi resmi, serta dasbor ResearchMate AI berbahasa Indonesia. Fondasi meliputi navigasi responsif, profil, kata sandi, tema, komponen Blade bersama, dan test Fase 1.

Proyek riset, PDF, embedding, koneksi Ollama, agen, matriks literatur, celah riset, percakapan AI, dan laporan belum diimplementasikan. Menu masa depan nonaktif dan berlabel fase. Tidak ada hasil riset atau keberhasilan AI yang disimulasikan sebagai hasil nyata.

## 2. Keputusan arsitektur

| Area | Keputusan dan alasan |
| --- | --- |
| Fondasi | Starter resmi Laravel Livewire commit `0f62a26c4e4b401c1300930f47d72a497add8cce`, agar asal implementasi dapat ditelusuri. |
| Backend | PHP 8.3+, Laravel 13; pola starter dipertahankan untuk mengurangi kode auth khusus. |
| Antarmuka | Blade, Livewire 4, Tailwind 4, Flux Free; tanpa komponen berbayar atau font jarak jauh. Alpine disediakan Livewire. |
| Aset | Vite standar 8.3.4, dengan `vite build` dan `vite`; Vite Plus tidak diperlukan. |
| Autentikasi | Fortify dengan form POST, CSRF, hash kata sandi, limiter login, dan session guard. Pengaturan memakai Livewire. |
| Database | PostgreSQL lokal; session, cache, dan queue memakai driver database. |
| Test | SQLite dalam memori untuk suite terisolasi; PostgreSQL aplikasi diperiksa tersendiri. |
| Bahasa/tema | Locale `id`, timezone aplikasi `Asia/Jakarta`, terjemahan Indonesia, tema terang/gelap/sistem. |
| AI | `config/ai.php` membaca placeholder `.env`; layanan HTTP dan inferensi baru Fase 3. |
| Paket | Lock file disertakan; platform Composer `8.3.0`. Runtime PHP 8.4.26, Laravel 13.35, Livewire 4.4.7, Flux Free 2.20.1. |

Verifikasi email, 2FA, dan passkeys tidak diwajibkan atau diaktifkan pada Fase 1. Dasbor menggunakan middleware `auth`; halaman keamanan meminta konfirmasi kata sandi.

## 3. Perintah instalasi

Prasyarat dan PATH PowerShell runtime portabel tersedia pada [README](C:/Users/marco/Documents/Github/Researchmate/README.md). PHP memerlukan ekstensi Laravel, `pdo_pgsql`, serta `pdo_sqlite` untuk test. Node memenuhi Vite 8: 20.19+ atau 22.12+; komputer implementasi menggunakan Node 22.18.0/npm 10.9.3.

Untuk salinan baru dengan PostgreSQL berjalan pada `127.0.0.1:5432`:

```powershell
createuser -h 127.0.0.1 -p 5432 -U postgres -P -S -D -R researchmate
createdb -h 127.0.0.1 -p 5432 -U postgres -O researchmate researchmate
composer install
npm.cmd ci
npm.cmd run build
if (-not (Test-Path -LiteralPath '.env')) { Copy-Item -LiteralPath '.env.example' -Destination '.env' }
```

Isi konfigurasi database `.env`, kemudian **hanya untuk instalasi baru** buat kunci:

```powershell
php artisan key:generate
php artisan config:clear
php artisan migrate
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8000
```

Di terminal lain, `npm.cmd run dev` menyediakan perubahan frontend. `php artisan queue:work --tries=2 --timeout=120` menjalankan worker database; Fase 1 belum mengirim job riset. `composer run dev` juga tersedia, tetapi Windows native sebaiknya memakai terminal terpisah karena Pail memerlukan `pcntl`.

Komputer implementasi memakai PHP/Composer portabel di `%LOCALAPPDATA%\ResearchMate\runtime`, PostgreSQL EDB di `%LOCALAPPDATA%\ResearchMate\runtime\postgresql\pgsql\bin`, data di `%LOCALAPPDATA%\ResearchMate\postgres-data`, dan port `55432`. Kredensial acak hanya di `.env`. Database `researchmate` dan `researchmate_testing` telah disediakan; migrasi Fase 1 pada database aplikasi telah dijalankan. Server memakai `pg_ctl`, bukan Windows service.

## 4. Berkas yang dibuat atau diperbarui

| Kelompok | Berkas sumber |
| --- | --- |
| Paket | [composer.json](C:/Users/marco/Documents/Github/Researchmate/composer.json), [composer.lock](C:/Users/marco/Documents/Github/Researchmate/composer.lock), [package.json](C:/Users/marco/Documents/Github/Researchmate/package.json), [package-lock.json](C:/Users/marco/Documents/Github/Researchmate/package-lock.json) |
| Bootstrap | [bootstrap/app.php](C:/Users/marco/Documents/Github/Researchmate/bootstrap/app.php), [bootstrap/providers.php](C:/Users/marco/Documents/Github/Researchmate/bootstrap/providers.php) |
| Konfigurasi | [.env.example](C:/Users/marco/Documents/Github/Researchmate/.env.example), [config/ai.php](C:/Users/marco/Documents/Github/Researchmate/config/ai.php), [config/app.php](C:/Users/marco/Documents/Github/Researchmate/config/app.php), [config/database.php](C:/Users/marco/Documents/Github/Researchmate/config/database.php), [config/fortify.php](C:/Users/marco/Documents/Github/Researchmate/config/fortify.php), [config/session.php](C:/Users/marco/Documents/Github/Researchmate/config/session.php) |
| Routing | [routes/web.php](C:/Users/marco/Documents/Github/Researchmate/routes/web.php), [routes/settings.php](C:/Users/marco/Documents/Github/Researchmate/routes/settings.php) |
| Akun/auth | [User.php](C:/Users/marco/Documents/Github/Researchmate/app/Models/User.php), [FortifyServiceProvider.php](C:/Users/marco/Documents/Github/Researchmate/app/Providers/FortifyServiceProvider.php), [CreateNewUser.php](C:/Users/marco/Documents/Github/Researchmate/app/Actions/Fortify/CreateNewUser.php), [ResetUserPassword.php](C:/Users/marco/Documents/Github/Researchmate/app/Actions/Fortify/ResetUserPassword.php), [Logout.php](C:/Users/marco/Documents/Github/Researchmate/app/Livewire/Actions/Logout.php) |
| Validasi | [ProfileValidationRules.php](C:/Users/marco/Documents/Github/Researchmate/app/Concerns/ProfileValidationRules.php), [PasswordValidationRules.php](C:/Users/marco/Documents/Github/Researchmate/app/Concerns/PasswordValidationRules.php) |
| Default keamanan | [AppServiceProvider.php](C:/Users/marco/Documents/Github/Researchmate/app/Providers/AppServiceProvider.php): aturan kata sandi produksi dan larangan perintah database destruktif pada produksi |
| Halaman | [welcome.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/welcome.blade.php), [dashboard.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/dashboard.blade.php) |
| Layout aplikasi | [app.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/app.blade.php), [sidebar.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/app/sidebar.blade.php), [header.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/app/header.blade.php), [head.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/partials/head.blade.php) |
| Layout auth | [auth.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/auth.blade.php), [simple.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/auth/simple.blade.php), [card.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/auth/card.blade.php), [split.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/layouts/auth/split.blade.php) |
| Form auth | [login.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/auth/login.blade.php), [register.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/auth/register.blade.php), [forgot-password.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/auth/forgot-password.blade.php), [reset-password.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/auth/reset-password.blade.php), [confirm-password.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/auth/confirm-password.blade.php) |
| Pengaturan Livewire | [⚡profile.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/settings/⚡profile.blade.php), [⚡security.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/settings/⚡security.blade.php), [⚡appearance.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/settings/⚡appearance.blade.php), [⚡delete-user-form.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/settings/⚡delete-user-form.blade.php), [⚡delete-user-modal.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/settings/⚡delete-user-modal.blade.php) |
| Komponen bersama | [navigation.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/research/navigation.blade.php), [panel.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/research/panel.blade.php), [status.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/research/status.blade.php), [stat-card.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/research/stat-card.blade.php), [desktop-user-menu.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/desktop-user-menu.blade.php), [app-logo.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/app-logo.blade.php), [app-logo-icon.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/app-logo-icon.blade.php) |
| Komponen auth/pengaturan | [auth-header.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/auth-header.blade.php), [auth-session-status.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/components/auth-session-status.blade.php), [layout pengaturan](C:/Users/marco/Documents/Github/Researchmate/resources/views/pages/settings/layout.blade.php), [settings-heading.blade.php](C:/Users/marco/Documents/Github/Researchmate/resources/views/partials/settings-heading.blade.php) |
| Aset | [app.css](C:/Users/marco/Documents/Github/Researchmate/resources/css/app.css), [app.js](C:/Users/marco/Documents/Github/Researchmate/resources/js/app.js), [vite.config.js](C:/Users/marco/Documents/Github/Researchmate/vite.config.js) |
| Bahasa | [lang/id.json](C:/Users/marco/Documents/Github/Researchmate/lang/id.json), [auth.php](C:/Users/marco/Documents/Github/Researchmate/lang/id/auth.php), [passwords.php](C:/Users/marco/Documents/Github/Researchmate/lang/id/passwords.php), [validation.php](C:/Users/marco/Documents/Github/Researchmate/lang/id/validation.php) |
| Test | [phpunit.xml](C:/Users/marco/Documents/Github/Researchmate/phpunit.xml), [tests/TestCase.php](C:/Users/marco/Documents/Github/Researchmate/tests/TestCase.php), [DashboardTest.php](C:/Users/marco/Documents/Github/Researchmate/tests/Feature/DashboardTest.php), [LocalizationTest.php](C:/Users/marco/Documents/Github/Researchmate/tests/Feature/LocalizationTest.php), [OfflineAuthenticationTest.php](C:/Users/marco/Documents/Github/Researchmate/tests/Feature/OfflineAuthenticationTest.php), direktori `tests/Feature/Auth` dan `tests/Feature/Settings` |
| CI | [tests.yml](C:/Users/marco/Documents/Github/Researchmate/.github/workflows/tests.yml): layanan PostgreSQL dan lingkungan test terpisah |

Awalan Unicode `⚡` mengikuti format komponen Livewire 4 starter; pertahankan saat menyalin berkas.

## 5. Kode sumber lengkap

Berkas pada bagian 4 berisi implementasi lengkap yang digunakan aplikasi, bukan pseudocode. Kode tidak disalin ulang agar hanya ada satu versi yang perlu dipelihara. Keterhubungan:

1. Bootstrap memuat provider dan routing; Fortify mendaftarkan endpoint auth.
2. Registrasi memanggil `CreateNewUser` dengan validasi bersama dan `User` yang memakai cast `hashed`.
3. Form masuk/keluar memakai endpoint Fortify standar; driver database menyimpan sesi.
4. `routes/web.php` menyajikan dasbor setelah autentikasi.
5. Layout sidebar memuat navigasi bersama, header, menu akun, dan `dashboard.blade.php`.
6. `routes/settings.php` menghubungkan profil, keamanan, dan tampilan dengan Livewire.
7. `config/ai.php` menyediakan konfigurasi yang ditampilkan dasbor; tidak mengirim request Ollama.

[README terkini](../README.md) memuat diagram sistem, ERD, workflow agen yang sudah terhubung, serta urutan percakapan berbasis sumber.

## 6. Perubahan konfigurasi

| Konfigurasi | Nilai/kegunaan |
| --- | --- |
| `APP_NAME`, `APP_URL` | ResearchMate AI dan URL lokal konsisten untuk cookie/tautan reset. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | `id`, `id`, `id_ID`. |
| `APP_TIMEZONE` | `Asia/Jakarta`, dapat diubah tanpa source. |
| `DB_CONNECTION`, `DB_HOST` | `pgsql`, `127.0.0.1`; database/role `researchmate`. |
| `DB_PORT` | `5432` untuk instalasi umum; `55432` pada runtime lokal ini. |
| `DB_PASSWORD`, `APP_KEY` | Rahasia di `.env`, diabaikan Git. |
| `SESSION_DRIVER`, `SESSION_ENCRYPT` | `database`, `true`; sesi persist dengan payload terenkripsi. |
| `SESSION_SECURE_COOKIE` | `false` pada HTTP loopback; `true` bila HTTPS. |
| `CACHE_STORE`, `QUEUE_CONNECTION` | `database`. |
| `FILESYSTEM_DISK` | `local`; unggahan privat baru Fase 4. |
| `MAIL_MAILER` | `log`; reset dicatat lokal, bukan dikirim SMTP. |
| `OLLAMA_*`, `AI_*`, `PDF_MAX_UPLOAD_MB` | Placeholder `.env.example`; penggunaan aktif Fase 3–5. |

Tabel nilai AI lengkap ada di README. Setelah `.env` berubah, jalankan `config:clear` dan mulai ulang server/worker. Jangan mengganti kunci instalasi aktif tanpa memahami dampaknya.

## 7. Perubahan database

Migrasi lengkap:

- [0001_01_01_000000_create_users_table.php](C:/Users/marco/Documents/Github/Researchmate/database/migrations/0001_01_01_000000_create_users_table.php): `users`, `password_reset_tokens`, `sessions`.
- [0001_01_01_000001_create_cache_table.php](C:/Users/marco/Documents/Github/Researchmate/database/migrations/0001_01_01_000001_create_cache_table.php): `cache`, `cache_locks`.
- [0001_01_01_000002_create_jobs_table.php](C:/Users/marco/Documents/Github/Researchmate/database/migrations/0001_01_01_000002_create_jobs_table.php): `jobs`, `job_batches`, `failed_jobs`.

Email unik. Sesi memiliki indeks `user_id` nullable dan `last_activity`, tanpa constraint FK pada starter. Tabel riset/pgvector belum dibuat. Role PostgreSQL aplikasi merupakan pemilik database tanpa superuser. Jalankan `migrate` sebelum login memakai session database.

Seeder bawaan bukan syarat instalasi; registrasi UI menyediakan akun demo. Tidak perlu migrasi destruktif untuk memulai aplikasi.

## 8. Perintah pengujian

```powershell
composer validate --strict
composer check-platform-reqs
npm.cmd run build
php artisan config:clear
php artisan test
composer lint:check
composer types:check
php artisan route:list --except-vendor
php artisan migrate:status
```

`composer test` menggabungkan lint, analisis tipe, dan suite PHP. Suite memakai SQLite dalam memori, mail array, queue sync, dan sesi array melalui `phpunit.xml`; PostgreSQL aplikasi tidak dipakai suite ini. Jangan override lingkungan test menuju database berisi data penting.

Cakupan: registrasi, login/kegagalan login, logout, reset/konfirmasi kata sandi, profil, penghapusan akun, perlindungan dasbor, lokalisasi, serta autentikasi produksi lokal tanpa verifikasi email wajib.

**Hasil verifikasi aktual:** `composer test` lulus: Pint, PHPStan tanpa error, dan 40 test/154 assertion pada SQLite. Suite yang sama lulus 40 test/154 assertion pada PostgreSQL `researchmate_testing`. Tiga migrasi PostgreSQL terpasang pada database aplikasi dan test. Build Vite 8.3.4 berhasil; npm audit melaporkan 0 kerentanan dan Composer 0 advisory saat diperiksa.

Browser berhasil mendaftarkan akun nyata pada PostgreSQL. Desktop 1440 px dan seluler 390 px diperiksa tanpa overflow horizontal; sidebar/menu profil bekerja, serta tema terang/gelap diperiksa secara visual. [Tangkapan desktop](C:/Users/marco/Documents/Github/Researchmate/docs/screenshots/dashboard-light.jpg), [seluler](C:/Users/marco/Documents/Github/Researchmate/docs/screenshots/dashboard-mobile.jpg), dan [gelap](C:/Users/marco/Documents/Github/Researchmate/docs/screenshots/dashboard-dark.jpg) disimpan. Suite lokal memakai PHP 8.4.26; CI mendefinisikan PHP 8.3/PostgreSQL, tetapi eksekusi remote belum diperiksa. AI dan PDF tidak diuji karena belum ada.

## 9. Hasil yang diharapkan

| Tindakan | Hasil yang diperiksa |
| --- | --- |
| Membuka `/` | Halaman awal ResearchMate AI berbahasa Indonesia. |
| Registrasi valid | Hash kata sandi tersimpan; sesi aktif dan redirect dasbor. |
| Dasbor tanpa sesi | Redirect ke `/login`. |
| Kata sandi salah | Validasi Indonesia, tanpa sesi auth baru. |
| Profil/kata sandi | Perubahan tersimpan; keamanan meminta konfirmasi kata sandi. |
| Tema | Terang, gelap, dan sistem tersedia. |
| Layar seluler | Sidebar terbuka/tertutup; konten menyesuaikan lebar. |
| Menu riset | Nonaktif dan menampilkan fase. |
| Ollama | “Belum diperiksa”; nama model berasal dari konfigurasi. |
| Reset kata sandi | Email lokal di log; token valid dapat memulihkan akun. |
| Keluar | Sesi diinvalidasi; route terlindungi kembali meminta masuk. |

Demo memakai “Peneliti Demo”, email demo unik, serta kata sandi sendiri. Jangan tampilkan token reset atau kredensial database pada presentasi/rekaman.

## 10. Pemecahan masalah

- **Koneksi PostgreSQL:** periksa `pg_isready`, `pg_ctl`, port `.env`, kredensial, dan kepemilikan database. Runtime ini memakai `55432`, template memakai `5432`.
- **Driver hilang:** periksa `php --ini`, `php -m`, `check-platform-reqs`; aktifkan `pdo_pgsql`/`pdo_sqlite` pada PHP CLI yang benar.
- **Tabel belum ada:** pilih database yang benar lalu jalankan `migrate`; jangan langsung menghapus data.
- **Aset tidak tampil:** build atau jalankan Vite. Hapus hanya `public/hot` bila menunjuk server Vite yang sudah berhenti.
- **419:** konsistenkan host dengan `APP_URL`, periksa sesi/cookie/kunci, lalu muat ulang form.
- **Email reset tidak masuk inbox:** `MAIL_MAILER=log` mencatat tautan di `storage/logs/laravel.log`; token sensitif, jangan dipublikasikan.
- **Dev Windows gagal:** jalankan PHP dan Vite di terminal terpisah; Pail membutuhkan `pcntl`.
- **AI/PDF/OpenAlex belum tersedia:** batas Fase 1; placeholder bukan keberhasilan integrasi.

## 11. Checklist penyelesaian dan fase berikutnya

Nilai checklist memakai hasil aktual, bukan hanya keberadaan source:

- [x] PHP/Composer/Node dan ekstensi memenuhi paket terkunci.
- [x] Build berhasil tanpa font jarak jauh atau komponen berbayar.
- [x] PostgreSQL lokal terhubung dan seluruh migrasi Fase 1 terpasang.
- [x] Registrasi, masuk, keluar, reset, profil, dan perubahan kata sandi teruji.
- [x] Dasbor terlindungi dan tamu diarahkan ke login.
- [x] Sidebar/header diperiksa pada desktop/seluler dan tema terang/gelap.
- [x] UI, validasi, dan reset memakai Bahasa Indonesia.
- [x] Test, lint, serta analisis tipe selesai; hasil aktual dicatat.
- [x] Menu masa depan nonaktif dan status Ollama “Belum diperiksa”.
- [x] `.env` dan log reset diabaikan Git; runtime dan data PostgreSQL berada di luar repositori. Pemeriksaan berkas terlacak tidak menemukan `.env` atau log pengguna.

Berikutnya **Fase 2 — Database dan Proyek Riset**: tabel riset, model/relasi, policy kepemilikan, CRUD, dan workspace per pengguna. Ollama menunggu Fase 3; PDF/pgvector/RAG Fase 4; orkestrasi enam agen Fase 5.
