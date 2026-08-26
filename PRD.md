# PRD — Backend (API & Business Logic)
**Turunan dari `PRD.md` v0.2 — Sistem Manajemen Sekolah**

Versi: 0.1 (turunan pertama)
Status: Draft — acuan implementasi backend (Laravel API) oleh AI coding agent

---

## 0. Hubungan dengan PRD Umum (Prinsip Sinkronisasi)

- **`PRD.md` (umum) adalah single source of truth.** Dokumen ini adalah **turunan satu arah** darinya — berisi breakdown teknis backend dari tiap Functional Requirement (FR) di `PRD.md` §5.
- Setiap requirement di sini diberi kode **FR-BE-x.x** dan mencantumkan tag `↳ Turunan FR-x.x` yang menunjuk ke FR asal di `PRD.md`.
- **Dokumen ini tidak boleh menambah/mengubah scope** yang tidak ada di `PRD.md`. Jika saat breakdown backend ditemukan kebutuhan scope baru atau perubahan aturan bisnis, **`PRD.md` harus direvisi dulu**, baru dokumen ini di-regenerate menyesuaikan. Tidak ada auto-sync terbalik dari dokumen ini ke `PRD.md`.
- Referensi data model detail: `PRD.md` §6 (entitas inti) — dokumen ini mengasumsikan entitas tersebut sebagai basis skema.

---

## 1. Tech Stack & Arsitektur Backend

- **Framework**: Laravel (REST API, JSON), MySQL.
- **Auth**: Laravel Sanctum (token/session-based, cocok untuk SPA React di domain/subdomain sendiri).
- **Otorisasi**: Laravel Policies/Gates per role (`admin`, `guru`, `orang_tua`, `staff`), dicek di setiap endpoint sesuai matriks akses `PRD.md` §3.
- **Queue/Job**: Laravel Queue + Scheduler (`schedule:run`) untuk proses terjadwal (generate invoice, cutoff check, retensi foto, reminder jatuh tempo).
- **Push notification**: library Web Push berbasis VAPID (mis. `minishlink/web-push` via wrapper Laravel) — **bukan Firebase**, sesuai `PRD.md` FR-5.2.
- **Storage**: disk terenkripsi at-rest untuk foto (selfie absensi staff, foto penjemput) — lihat §8.
- **Export**: `barryvdh/laravel-dompdf` (PDF) + `maatwebsite/excel` (xlsx) untuk seluruh fitur ekspor.
- **Payment gateway**: integrasi Midtrans (Snap/Core API) — endpoint create-transaction + webhook callback.
- **Multi-tenancy readiness**: kolom `school_id` disiapkan nullable/default di entitas inti (`PRD.md` §2.1), namun MVP tidak menerapkan filter multi-tenant di query (single value).
- **Timezone**: seluruh timestamp disimpan/ditampilkan mengikuti `Asia/Jakarta` (`config('app.timezone')`).

---

## 2. Autentikasi & Otorisasi

**↳ Turunan FR-5.1, §3.1 (Provisioning Akun)**

- **FR-BE-5.1** `POST /api/auth/login` — email/username + password → token Sanctum + payload `role` untuk redirect frontend.
- **FR-BE-5.1a** `POST /api/auth/logout`.
- **FR-BE-5.1b** `POST /api/auth/forgot-password` — generate signed token, kirim email berisi link reset (expiry 60 menit, konfigurabel).
- **FR-BE-5.1c** `POST /api/auth/reset-password` — validasi token & expiry, update password (hashed).
- **FR-BE-3.1** `POST /api/admin/users` (Admin-only) — invite user (guru/staff/orang tua): buat record `users` + kirim kredensial awal via email. Tidak ada endpoint self-register publik.
- **Middleware RBAC**: setiap route group (`admin/*`, `guru/*`, `ortu/*`, `staff/*`) dibungkus policy yang menolak akses lintas-role dan lintas-data (mis. guru hanya query siswa di rombel yang diampu; orang tua hanya query siswa yang terhubung via `parent_student`).

---

## 3. Modul Absensi Siswa

**↳ Turunan FR-1.1 – FR-1.6**

- **FR-BE-1.1** `GET /api/guru/classrooms/{id}/attendance?date=` — daftar siswa + status hari itu. `POST /api/guru/classrooms/{id}/attendance` — bulk simpan status (`Hadir/Izin/Sakit/Alpa`) per siswa dengan `recorded_by` + `timestamp`.
  - Validasi: jika `date` ada di `academic_calendar_holidays`, endpoint menolak simpan (400) dengan pesan "hari libur".
- **FR-BE-1.2** Field `note` opsional per record, disimpan di `student_attendances.note`.
- **FR-BE-1.3** `PATCH /api/guru/classrooms/{id}/attendance` — validasi window edit: hanya izinkan jika `today - recorded_date <= tolerance_days` (default 1, konfigurasi di `dismissal_settings`/`school_settings`). Di luar window → 403 + tercatat ke `audit_logs`.
- **FR-BE-1.4** `GET /api/ortu/children/{studentId}/attendance?month=` — kalender bulanan, scoped ke siswa yang terhubung ke akun ortu login.
- **FR-BE-1.5** Job/event listener: setelah simpan status `Izin/Sakit/Alpa` (atau `Hadir` jika `notify_on_present=true` di setting Admin), dispatch push notification ke seluruh `parent_student` terkait, target SLA < 1 menit (queue job, bukan sync call).
- **FR-BE-1.6** `GET /api/admin/reports/attendance?from=&to=&classroom_id=&format=pdf|xlsx` — generate & stream file export.

---

## 4. Modul Jemput Anak (Digital Dismissal)

**↳ Turunan FR-2.1 – FR-2.10**

- **FR-BE-2.1** `POST /api/ortu/children/{studentId}/pickups` — tambah penjemput (nama, foto, relationship). Saat create, generate `qr_code_token` unik (mis. UUID/HMAC-signed) khusus kombinasi (pickup × student).
- **FR-BE-2.2** Token QR **selalu 1:1 per (penjemput, siswa)** — jika satu orang jadi penjemput sah untuk >1 anak, backend membuat row `authorized_pickups` terpisah (bukan reuse token) per siswa.
- **FR-BE-2.3** `POST /api/verify/pickup/scan` — decode QR token → lookup `authorized_pickups` aktif (belum `revoked_at`) → return data penjemput+siswa untuk konfirmasi UI. `POST /api/verify/pickup/{id}/confirm` — set `pickup_logs` (method=`qr`) + update status siswa hari itu jadi `Sudah Dijemput` + timestamp.
- **FR-BE-2.4** `POST /api/verify/pickup/manual` — payload: student_id, authorized_pickup_id, catatan (wajib). Sama efeknya seperti FR-BE-2.3 tapi `method=manual`.
- **FR-BE-2.5** Setiap confirm (QR/manual) menulis 1 row `pickup_logs` (student_id, authorized_pickup_id, verified_by, method, timestamp, note).
- **FR-BE-2.6** Setelah `pickup_logs` insert sukses → dispatch push notification job ke seluruh ortu terhubung siswa (isi: nama penjemput, jam), target SLA < 1 menit.
- **FR-BE-2.7** Validasi di FR-BE-2.4: jika `authorized_pickup_id` tidak match siswa atau berstatus revoked → 422 "Penjemput tidak terdaftar", tidak membuat log.
- **FR-BE-2.8** `GET /api/admin/pickup-logs?date=&student_id=&pickup_id=` — filterable list untuk audit.
- **FR-BE-2.9** Scheduled job (jalan setiap menit atau at cutoff time via `dismissal_settings.cutoff_time`): query siswa aktif hari itu tanpa `pickup_logs` hari ini → tandai status derived `Belum Dijemput` (bisa berupa computed status, tidak perlu kolom fisik jika dihitung on-read) → muncul di endpoint dashboard admin alert.
- **FR-BE-2.10** Saat `DELETE /api/ortu/pickups/{id}` (soft delete → set `revoked_at`), token terkait langsung invalid untuk FR-BE-2.3/2.4 (lookup harus filter `revoked_at IS NULL`).

---

## 5. Modul HR (Manajemen Staff)

**↳ Turunan FR-3.1 – FR-3.7**

- **FR-BE-3.1** CRUD `GET/POST/PUT /api/admin/staff` — data master staff (relasi opsional ke `users`).
- **FR-BE-3.2** `POST /api/staff/attendance/check-in` (multipart: foto) dan `POST /api/staff/attendance/check-out` (multipart: foto) — validasi: tolak check-in kedua di hari sama tanpa check-out sebelumnya (query `staff_attendances` hari berjalan, status open).
- **FR-BE-3.3** `PATCH /api/admin/staff/{id}/attendance/{attendanceId}` — override manual oleh Admin (mis. lupa check-out), wajib tercatat di `audit_logs` sebagai override.
- **FR-BE-3.4** `POST /api/staff/leave-requests` — jenis cuti, tanggal mulai/selesai, alasan, lampiran opsional (upload).
- **FR-BE-3.5** `PATCH /api/admin/leave-requests/{id}` — Approve/Reject + catatan (wajib jika Reject). Setelah update, dispatch push notification ke staff pemohon.
- **FR-BE-3.6** `GET /api/staff/leave-requests` — riwayat + sisa kuota (jika `leave_quota` dikonfigurasi per staff/jenis cuti).
- **FR-BE-3.7** `GET /api/admin/reports/hr?from=&to=&format=pdf|xlsx` — rekap kehadiran & cuti staff.

---

## 6. Modul Keuangan (SPP & Billing)

**↳ Turunan FR-4.1 – FR-4.10**

- **FR-BE-4.1** `POST/PUT /api/admin/fee-structures` — nominal SPP per `grade_level`/`classroom`, plus biaya tambahan one-time.
- **FR-BE-4.2** Scheduled job (cron tanggal 1 tiap bulan, konfigurabel): iterasi seluruh `students` berstatus `active` → generate 1 `invoices` row per siswa dengan nomor format `INV/{YYYY}/{MM}/{urutan 4 digit}` (sequence per bulan, unique constraint di DB), status awal `belum_bayar`.
- **FR-BE-4.3** `GET /api/ortu/children/{studentId}/invoices?status=` — scoped ke anak yang terhubung akun.
- **FR-BE-4.4** `POST /api/ortu/invoices/{id}/pay` — buat transaksi ke Midtrans (Snap token/Core API) untuk **nominal penuh invoice** (server-side fixed amount, tidak menerima nominal dari client) → return payment URL/token ke frontend.
- **FR-BE-4.5** `POST /api/webhooks/midtrans` — endpoint publik (signature-verified) menerima callback status transaksi → update `payments` + `invoices.status = lunas` jika settlement sukses. Idempotent terhadap retry webhook.
- **FR-BE-4.6** Job-job push notification: (a) saat invoice baru terbit (dipicu dari FR-BE-4.2), (b) reminder H-3/H-1 jatuh tempo (scheduled job harian query invoice `belum_bayar` mendekati `due_date`), (c) saat `payments` sukses (dipicu dari webhook FR-BE-4.5).
- **FR-BE-4.7** `GET /api/ortu/invoices/{id}/receipt.pdf` — generate kwitansi PDF (format sederhana sesuai catatan terbuka `PRD.md` §5.4/FR-4.7 — **perlu update generator jika format final berbeda**).
- **FR-BE-4.8** `GET /api/admin/finance/dashboard` — agregasi total tagihan, total terbayar, tunggakan per kelas/siswa. `GET /api/admin/reports/finance?format=pdf|xlsx`.
- **FR-BE-4.9** `PATCH /api/admin/invoices/{id}/mark-paid` — tandai lunas manual (catatan/bukti wajib), tercatat `audit_logs`.
- **FR-BE-4.10** Event listener pada `PATCH /api/admin/students/{id}` (status → `inactive`): auto-update seluruh `invoices` bulan berjalan milik siswa itu yang masih `belum_bayar` → `dibatalkan`; job generate bulanan (FR-BE-4.2) otomatis skip siswa nonaktif ke depannya (query filter `status=active`).

---

## 7. Cross-Cutting Backend

**↳ Turunan FR-5.2, FR-5.3, FR-5.5**

- **FR-BE-5.2** Endpoint subscription push: `POST /api/push/subscribe` (simpan `endpoint`+keys VAPID per user/device), `DELETE /api/push/subscribe`. Semua job notifikasi (§3–6 di atas) memakai service push terpusat ini.
- **FR-BE-5.3** Response API tidak perlu translate teks (translasi ditangani frontend via i18n keys), namun backend menyediakan **kode/slug**, bukan teks hardcoded, untuk field seperti status, jenis cuti, dsb., agar frontend bisa mapping ke ID/EN.
- **FR-BE-5.5** `POST /api/ortu/consents` — simpan `consented_at` + `consent_version` per (parent, student). Middleware/gate: endpoint yang memproses data anak (absensi, jemput, invoice) untuk siswa tanpa consent aktif → 403 dengan kode error khusus agar frontend redirect ke form consent. `POST /api/ortu/consents/{id}/withdraw` — set `consent_withdrawn_at`, dispatch notifikasi ke Admin untuk tindak lanjut manual.

---

## 8. Scheduled Jobs / Background Tasks (Ringkasan)

| Job | Frekuensi | Terkait FR |
|---|---|---|
| Generate invoice SPP bulanan | 1x/bulan (tgl konfigurabel) | FR-BE-4.2, FR-BE-4.10 |
| Reminder jatuh tempo invoice | Harian | FR-BE-4.6 |
| Cutoff dismissal check ("Belum Dijemput") | Harian, on cutoff_time | FR-BE-2.9 |
| Retensi foto bukti (selfie/penjemput) | Tahunan (akhir tahun ajaran) | NFR §7.1 `PRD.md` (retensi 1 tahun ajaran) |
| Push notification dispatch queue | Real-time (queue worker) | FR-BE-1.5, 2.6, 3.5, 4.6, 5.5 |

---

## 9. Non-Functional Requirements (Backend-Relevant)

**↳ Turunan `PRD.md` §7**

- RBAC ketat di layer Policy/Gate (§2), bukan hanya filter di frontend.
- Audit log (`audit_logs`) wajib ditulis untuk: edit absensi lewat window (FR-BE-1.3), override absensi staff (FR-BE-3.3), approve/reject cuti (FR-BE-3.5), mark-paid manual (FR-BE-4.9), penarikan consent (FR-BE-5.5).
- Enkripsi at-rest untuk kolom/file sensitif (foto, data kontak, data keuangan); seluruh endpoint hanya via HTTPS/TLS.
- Job retensi foto (§8) menghapus/mengarsip file setelah 1 tahun ajaran berjalan berakhir.
- Semua timestamp disimpan dalam `Asia/Jakarta`.
- Endpoint export (FR-BE-1.6, 3.7, 4.8) menghasilkan PDF & Excel (.xlsx).

---

## 10. Tabel Traceability (FR Umum → FR Backend)

| FR Asal (`PRD.md`) | FR Backend |
|---|---|
| FR-1.1 – FR-1.6 | FR-BE-1.1 – FR-BE-1.6 |
| FR-2.1 – FR-2.10 | FR-BE-2.1 – FR-BE-2.10 |
| FR-3.1 – FR-3.7 | FR-BE-3.1 – FR-BE-3.7 |
| FR-4.1 – FR-4.10 | FR-BE-4.1 – FR-BE-4.10 |
| FR-5.1 – FR-5.5 | FR-BE-5.1, FR-BE-5.2, FR-BE-5.3, FR-BE-5.5 (FR-5.4 dashboard — murni agregasi, lihat endpoint per modul di atas) |

---

*Dokumen ini diturunkan dari `PRD.md` v0.2. Jika `PRD.md` direvisi (versi baru), bagian terkait di dokumen ini harus di-regenerate menyesuaikan. Dokumen ini tidak mengubah `PRD.md`.*
