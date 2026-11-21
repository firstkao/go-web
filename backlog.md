# GO-WEB — Backlog Perbaikan

Catatan bersama (assistant + pemilik project) soal apa saja yang sudah dan belum
dikerjakan di tema GO-WEB. Dibuat setelah audit menyeluruh seluruh alur
(functions.php, admin.php + 8 modul menu, login/register, tenant-web + 5 halaman
publik, super-admin, index, footer).

---

## ✅ SUDAH DIPERBAIKI — 5 Bug Utama (sesi ini)

### Bug #1 — Dropdown tahun hardcode 2026–2028 (data tampil kosong di tahun lain)
**Status:** FIXED
**Gejala:** Tabel Rekapan PO, Rekapan Tagihan, statistik diagnosa, dan CSV-nya
selalu kosong apapun isinya, karena filter siklusnya tidak bisa dipilih di tahun
selain 2026–2028 (nilai select jadi kosong → filter `getFullYear() === ''` gagal
untuk semua baris). Di menu-finance filter-nya kebetulan masih jalan via variabel
PHP, tapi UI dropdown-nya sama blank-nya.
**Fix:**
- `menu-po.php` — option tahun digenerate dinamis: tahun sekarang ±2, default
  terpilih = tahun berjalan.
- `menu-tagihan.php` — idem.
- `menu-finance.php` — idem (default terpilih tetap `$current_real_year`).

### Bug #2 — Tombol "Hapus Tenant" di Super Admin tidak punya handler
**Status:** FIXED
**Gejala:** Tombol trash di daftar tenant `super-admin.php` submit → reload →
tidak terjadi apa-apa (konfirmasi JS muncul, tapi tidak ada `isset($_POST['delete_tenant'])`
di handler POST).
**Fix:** `super-admin.php` — tambah handler `delete_tenant`:
- Ambil data tenant dulu, catat ke `goweb_deleted_tenants_log` (format sama
  dengan cron retensi otomatis, reason: "Dihapus manual oleh Super Admin"),
- lalu panggil `goweb_delete_tenant_completely()` (hapus semua tabel + file bukti).
- Pesan error jika tenant tidak ditemukan.

### Bug #3 — `$approved_package` dipakai sebelum didefinisikan
**Status:** FIXED
**Gejala:** Di handler approve `super-admin.php`, kolom `cycle_daily_rate`
insert tenants memakai `$approved_package['price']`, padahal variabel itu baru
didefinisikan SETELAH insert (di blok pembayaran). Akibatnya setiap tenant baru
di-approve dengan `cycle_daily_rate = 0` (PHP 8: deprecation warning + null→0).
Ke-masking oleh fallback `> 0` di menu-subscription/admin.php, tapi nilai harian
awal memang tidak pernah tersimpan.
**Fix:** `super-admin.php` — pindahkan
`$approved_package = goweb_get_package(...)` ke SEBELUM insert tenants.

### Bug #4 — Tidak ada validasi duplikat email/slug
**Status:** FIXED
**Gejala:** Email/slug sama bisa didaftarkan berkali-kali (register & approve
sama-sama tidak mengecek). Akibatnya `goweb_find_tenant_by_slug()` (get_row tanpa
ORDER) dan login by email jadi tidak deterministik.
**Fix:**
- `register.php` — sebelum insert pending: cek email & slug terhadap tabel
  `goweb_tenants` **dan** `goweb_pending_registrations`. Kalau duplikat →
  exception → pesan error ramah (tidak expose detail DB).
- `super-admin.php` — cek ulang email & slug saat klik Approve (antrean bisa
  nunggu berhari-hari; slug/email bisa keambil tenant lain di selang waktu).
  Kalau duplikat → approve ditolak dengan pesan, data pending tidak diinsert.

### Bug #5 — Stored XSS di Rekapan PO (menyasar Super Admin)
**Status:** FIXED
**Gejala:** `menu-po.php` merender `${o.batch}`, `${o.name}`, `${o.item}`, dan
`${st}` (status) mentah ke `innerHTML` template string. Tenant bisa mengisi
nama/item dengan markup berbahaya; dieksekusi di browser siapa pun yang buka
halaman itu — termasuk Super Admin yang mengintip via `?store=slug`.
**Fix:** `menu-po.php`:
- Tambah helper `escHtml()` di JS; semua interpolasi data order & status di
  innerHTML kini di-escape (termasuk atribut `title` dan option status).
- `'uid'` di-cast `intval()` di sisi PHP (dipakai di atribut onclick).
- Perbandingan filter/statistik tetap pakai nilai mentah (tidak berubah perilaku).

Semua file yang diubah sudah lolos `php -l`.

**Hasil test (harness WordPress-stub + $wpdb fake in-memory, 9 skenario):**
- `approve-fresh` — approve tenant baru: tenant terinsert, `cycle_daily_rate` =
  harga/30 (BUKAN 0 — regresi Bug#3), payments Verified, pending dibersihkan,
  tidak ada warning undefined variable. ✅
- `approve-duplicate` — approve dengan slug/email yang sudah dipakai tenant
  aktif: insert DITOLAK, pending tidak ikut terhapus, pesan jelas. ✅ (Bug#4)
- `delete-tenant` — tombol hapus kini benar-benar menghapus: flag
  `goweb_delete_tenant_completely` terpanggil, tenant+payments terhapus,
  tercatat di log dengan reason "Dihapus manual oleh Super Admin". ✅ (Bug#2)
- `reg-dup-email` / `reg-dup-slug` / `reg-fresh` — validasi duplikat
  case-insensitive & lintas tabel (tenants + pending), password tetap ter-hash,
  fitur tersimpan JSON, pendaftaran normal tidak terganggu. ✅ (Bug#4)
- `year-po` / `year-tagihan` / `year-finance` — dropdown tahun kini digenerate
  dinamis (Y-2 s/d Y+2), tahun berjalan otomatis ter-SELECT. ✅ (Bug#1)

**Perbaikan susulan dari hasil test (masih bagian Bug#4):**
- `register.php` — catch `\Throwable` sebelumnya MENIMPA semua pesan error
  jadi "Terjadi kendala teknis..." generik, sehingga pesan validasi duplikat
  tidak pernah sampai ke pendaftar. Sekarang ada exception khusus
  `GoWebUserFacingError`: pesan validasi tampil apa adanya, error teknis tak
  terduga tetap digenerikkan + di-log.

Harness test tersimpan di `_tests_tmp/` (stub + runner). Folder ini artefak
verifikasi, TIDAK di-commit — boleh dihapus kapan saja, atau dipakai lagi
buat regresi kalau suatu saat menyentuh file-file yang sama.

### Temuan susulan audit Bug#5 (sudah diperbaiki & dites)
Saat audit ulang, pola XSS yang sama ternyata juga ada di autocomplete
"Pilih PO/Order" pada 2 modul yang TIDAK masuk laporan awal:
- `menu-tagihan.php` — `${o.label}` (batch + nama customer) dirender mentah
  ke `innerHTML` di daftar saran.
- `menu-warehouse.php` — idem.
**Fix:** tambah helper `escHtml()` + escape body & atribut `data-label`,
`id` di-cast `intval` (sama persis dengan pola fix di menu-po.php).
**Test:** `node _tests_tmp/test-xss-suggest.js` — ekstrak fungsi escHtml +
template render LANGSUNG dari file asli, jalankan dengan payload
`<img onerror=...>`, verifikasi output ter-escape penuh (12 assertion, ALL-PASS).
Sisanya sudah diaudit: interpolasi `${...}` lain di seluruh tema hanya angka/enum
(pagination, kalkulasi JS) — aman.

---

## ⏳ PENDING — "Alur Aneh" / Isu Desain (menunggu keputusan pemilik)

### #6 — Feature gating tidak konsisten antar modul
`menu-po.php` TIDAK punya guard `if (!$has_po)` (sibling-nya payments/tagihan/
warehouse/calculator punya). Lebih penting: guard itu cuma melindungi *view* —
handler POST di `admin.php` (PO, invoice, warehouse, settings) tidak pernah
mengecek `$has_*` maupun `$is_store_online`, jadi tenant dengan fitur dimatikan
(atau sudah Offline) tetap bisa insert/update/delete via POST langsung (nonce
tetap valid). Sidebar hanya men-disable menu via CSS.
**Perlu keputusan:** apakah handler POST juga harus di-gate per fitur & status?

### #7 — Kurs kalkulator internal tidak bisa diatur tanpa fitur `public_calc`
Kartu "Pengaturan Kurs" & "Kargo per Mata Uang" di `menu-setting.php` hanya
dirender `if ($has_public_calc)`, padahal setting `rates` & `currency_cargo`
juga dipakai kalkulator internal (`$has_calc`). Tenant dengan kalkulator
internal saja tidak bisa mengubah kursnya.
**Perlu keputusan:** render kartu kurs jika `$has_calc || $has_public_calc`?

### #8 — Kolom mati: `total_value`, `qty`, `resi_keluar`, `tanggal_keluar`
Handler tambah PO selalu insert `qty=1`, `total_value=0` (form tidak punya field
nominal). `resi_keluar` & `tanggal_keluar` di tabel warehouse tidak pernah
diisi form manapun (placeholder pencarian publik bahkan menyebut "Resi
Masuk/Keluar" padahal keluar tidak pernah ada).
**Perlu keputusan:** hapus kolomnya, atau isi fungsinya (form resi keluar)?

### #9 — Masking resi publik kosmetik saja
`page-resi.php` menampilkan resi dimask (`SF674XXX`) tapi atribut `data-search`
di HTML yang sama memuat resi lengkap — tinggal view-source.
**Perlu keputusan:** pindahkan pencarian ke server-side / hapus resi penuh dari
HTML, atau terima risikonya?

### #10 — Nama customer tampil publik
Portal publik (Rekapan PO & Tagihan) menampilkan nama pemesan ke siapa pun tanpa
login. Kemungkinan by design, tapi data PII customer tenant terbuka untuk
di-scrape.
**Perlu keputusan produk.**

### #11 — Biaya QRIS & sumber harga tidak terpusat
- Fee QRIS: 0,3% untuk < Rp500rb, 0,7% untuk ≥ Rp500rb (`menu-subscription.php`,
  `register.php`) — persentasenya "terbalik" dari biasanya; konfirmasi bukan typo.
- QRIS image hardcode `https://mercatoria.id/wp-content/uploads/2026/02/qris-barcode.jpg`
  (domain eksternal, path tanggal masa depan).
- Harga paket diduplikasi lagi di JS `priceMap` di `register.php` (anti-pola
  "satu sumber kebenaran" — seharusnya dari `goweb_get_package_definitions()`).

### #12 — Login tenant: sesi tunggal + lockout per-email
- Login sukses menimpa `session_token_hash` → login di perangkat baru mengusir
  sesi di perangkat lama (satu token per tenant, bukan per-device).
- Lockout brute-force 5x/15 menit **per-email** (disengaja) → siapa pun bisa
  mengunci akun tenant manapun dengan 5 percobaan salah sengaja (DoS akun).
**Perlu keputusan:** multi-device session? Lockout per-IP kombinasi?

### #13 — Retensi otomatis sangat agresif
H+1 offline, **H+2 data dihapus permanen**, plus hapus total di hari ke-365
meski tenant aktif bayar. Cron WP berbasis kunjungan — di situs sepi cron bisa
telat lalu memproses backlog sekaligus. Sudah tertulis di T&C, tapi jendela
2 hari untuk "hapus permanen seluruh data bisnis" tipis.
**Perlu keputusan:** perpanjang grace period? reminder e-mail?

### #14 — Approve/reject pendaftaran: file bukti & insert silent-fail
- Reject: bukti transfer tidak dihapus dari disk (file yatim).
- Approve: hasil `$wpdb->insert` tenants tidak dicek (gagal tetap nampil
  "berhasil disetujui"); `'tenant_' . time()` bisa bentrok PK kalau 2 approval
  dalam detik yang sama.

### #15 — Lain-lain kecil
- `admin.php` ~line 596: komentar orphan ("// ditulis oleh halaman Pengaturan…")
  — sisa edit yang kepotong.
- `menu-calculator.php`: rate THB hardcode `data-rate="460"` (tidak ikut setting
  tenant, beda dari 4 pill lain); display "Packing/Handling" di kartu hasil
  hardcode "Rp 5.000" padahal kalkulasi pakai `packingFee` dinamis.
- Logout via GET `?goweb_logout=1` tanpa nonce (logout-CSRF, dampak rendah).
- Form pembayaran publik: server tidak men-enforce `required` bukti transfer
  (JS dimatikan → bisa submit tanpa bukti), dan tidak ada rate-limit/captcha →
  tenant bisa dibanjiri entri pending palsu.

---

## Catatan riset alur (ringkasan arsitektur, untuk referensi)
- Routing manual via `goweb_force_custom_templates()` (template_redirect prio 1).
- Auth tenant custom (bukan wp_users): cookie `goweb_tenant_auth` +
  `session_token_hash` di tabel tenants.
- 10 tabel custom `wp_goweb_*`, dbDelta + versioning `GOWEB_DB_VERSION` (1.8.0),
  migrasi one-time dari wp_options.
- Alur bisnis: register → pending → approve Super Admin (aktif 30 hari) →
  tenant kelola toko di /dashboard → customer akses /{slug} → perpanjangan
  diajukan tenant, diverifikasi Super Admin → H+1 offline, H+2 hapus permanen,
  retensi maksimal 365 hari.
