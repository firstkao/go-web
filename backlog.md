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

## ✅ SELESAI — "Alur Aneh" / Isu Desain (#6–#15) + AUDIT KEDUA (#16–#19)
Semua item sudah diperbaiki atau sudah diputuskan pemiliknya. Rincian per item di bawah.

### AUDIT KEDUA — temuan baru (putaran ulang setelah #6–#15 selesai)
Audit ulang menyeluruh (cron retensi, auth session, upload/kuota, CSV, proration,
routing, super-admin edit, semua alur submit) menemukan 4 lubang yang lolos
putaran pertama. Area yang sudah diverifikasi AMAN: lockout/captcha login,
session token (hash_equals + expiry), ownership check ?store=, gate
subscription, proration upgrade, nominal durasi server-side, CSV export
(formula injection terproteksi), XSS escape, routing & tenant picker, kuota
storage live, pembersihan file saat delete/reject.

### #16 — register.php: validasi server-side KOSONG — ✅ FIXED
Semua cek cuma di JS/HTML (`required` + wizard step). POST langsung bisa:
password kosong ikut ke-hash; email ngawur -> `sanitize_email()` jadi '' ->
tenant terdaftar dengan email kosong; nama toko kosong -> slug kosong ->
portal publik rusak; `reg_features` bebas string/TypeError array_map.

**Fix:** validasi server-side di awal handler: nama toko/admin/slug wajib,
email wajib valid (`is_email`), password min 8 karakter; `reg_features`
di-whitelist ke 6 fitur dikenal (field hilang/bukan array -> default semua
fitur, sesuai UI); pesan error digabung + tampil apa adanya ke pendaftar.

### #17 — `request_id = 'req_' . time()` bentrok PK — ✅ FIXED
Kembaran Bug#14 di sisi register: 2 pendaftaran dalam detik sama -> insert
kedua gagal -> pesan generik + isi ulang form dari awal.
**Fix:** `req_<timestamp>_<random8hex>` + loop cek eksistensi (pola sama
seperti fix #14).

### #18 — menu-subscription.php: bukti transfer & nama tidak wajib di server — ✅ FIXED
Sama persis pola #15e: upload `confirm_proof` cuma diproses KALAU ada;
tanpa bukti, insert perpanjangan jalan dengan `proof_url = ''`.
**Fix:** `sender_name` kosong -> diblok; bukti kosong -> diblok; insert
benar-benar hanya lewat semua validasi.

### #19 — Edit tenant Super Admin: email bisa DUPLIKAT, status/fitur tanpa whitelist — ✅ FIXED
`update_tenant` gak cek email vs tenant lain — login lookup ambil baris
PERTAMA by email, jadi 2 tenant email sama = login bisa nyasar ke akun
salah. Status free-text dari POST bisa bikin toko "brick" (cek
`=== 'Active'` gak kenal). Fitur edit bebas string.
**Fix:** cek email duplikat kecuali tenant sendiri (case-insensitive) ->
wp_die; status whitelist Active/Offline; fitur di-whitelist ke 6 nilai
kenal. Data test harness: FakeWPDB kini paham operator `!=`/`<>`.

**Test baru:** `reg-empty-pass`, `reg-bad-email`, `reg-nofeatures`
(whitelist + request_id unik), `sub-no-proof`, `sub-no-name`, `sub-happy`
(nominal 79000+237=79.237, kategori subscription), `sa-edit-dup-email`,
`sa-edit-bad-status`, `sa-edit-ok`. Semua 35 skenario PHP + 4 test
standalone: ALL-PASS. Catatan koordinasi: commit ini sengaja TIDAK
menyertakan style.css (sedang diedit agent peer untuk tugas UI yang beda).

**Keputusan pemilik terkait repo (bukan bagian item):**
- `.gitattributes` yang terhapus → DIBIARKAN terhapus (tidak dipakai).

### #6 — Feature gating tidak konsisten antar modul — ✅ FIXED
`menu-po.php` TIDAK punya guard `if (!$has_po)` (sibling-nya payments/tagihan/
warehouse/calculator punya). Lebih penting: guard itu cuma melindungi *view* —
handler POST di `admin.php` (PO, invoice, warehouse, settings) tidak pernah
mengecek `$has_*` maupun `$is_store_online`, jadi tenant dengan fitur dimatikan
(atau sudah Offline) tetap bisa insert/update/delete via POST langsung (nonce
tetap valid). Sidebar hanya men-disable menu via CSS.
**Perlu keputusan:** apakah handler POST juga harus di-gate per fitur & status?

**Fix (diputuskan & diterapkan):**
- `menu-po.php` — guard view `if (!$has_po)` ditambah (konsisten sibling).
- `admin.php` — server-side gating di 4 handler POST: PO (`$has_po`),
  Tagihan (`$has_tagihan`), Warehouse (`$has_wh`), Pembayaran Pelanggan
  (`$has_payment`). `wp_die` dengan pesan jelas bila fitur tidak aktif.
- `menu-setting.php` — gating per aksi sesuai kondisi render kartunya:
  kurs & kargo → `$has_public_calc`, status order → `$has_po`, jenis
  pembayaran → `$has_payment || $has_tagihan`, warehouse → `$has_wh`.
- **Keputusan desain:** gating dilakukan per FITUR saja, TIDAK per status
  online. Alasannya: portal publik sudah memblokir penuh toko non-Active
  (`wp_die` di tenant-web.php), sedangkan dashboard offline tetap harus bisa
  diakses tenant untuk memproses perpanjangan sewa (kalau dashboard ditutup
  penuh, tenant jadi gak bisa perpanjang). Finance/subscription/overview
  memang selalu aktif — tidak di-gate.

**Test (8 skenario baru, ALL-PASS; 9 skenario lama + XSS test tetap hijau):**
`g-po-blocked`, `g-po-allowed`, `g-tagihan-blocked`, `g-wh-blocked`,
`g-payments-blocked`, `g-menu-po-guard`, `g-setting-rates-blocked`,
`g-setting-rates-allowed` — verifikasi POST tanpa fitur ditolak server-side
(tidak ada insert), POST dengan fitur tetap jalan, guard view muncul.
Catatan: skenario test `year-po` sempat "gagal" karena guard baru bekerja —
test-nya yang dikoreksi (`$has_po = true`), bukan kodenya.

### #7 — Kurs kalkulator internal tidak bisa diatur tanpa fitur `public_calc` — ✅ FIXED
Kartu "Pengaturan Kurs" & "Kargo per Mata Uang" di `menu-setting.php` hanya
dirender `if ($has_public_calc)`, padahal setting `rates` & `currency_cargo`
juga dipakai kalkulator internal (`$has_calc`). Tenant dengan kalkulator
internal saja tidak bisa mengubah kursnya.
**Perlu keputusan:** render kartu kurs jika `$has_calc || $has_public_calc`?

**Fix (diputuskan & diterapkan):**
- `menu-setting.php` — Kartu 1 (Kurs) & Kartu 3 (Kargo/Pajak/Packing Fee)
  kini dirender `if ($has_calc || $has_public_calc)`.
- Handler `action_save_rates` & `action_save_cargo` di-gate dengan kondisi
  yang sama (`! $has_calc && ! $has_public_calc` → wp_die), konsisten dengan
  gating #6.
- Pesan wp_die diganti "Fitur Kalkulator tidak aktif" (tidak lagi spesifik
  "Publik", karena sekarang mencakup keduanya).

**Test:** skenario baru `g-setting-calc-only-allowed` (tenant hanya punya
`calculator`): kurs bisa disimpan + kartu dirender. Skenario lama
`g-setting-rates-blocked` (tanpa kalkulator sama sekali) tetap ditolak —
assertion-nya dikoreksi mengikuti pesan baru. 19 skenario + XSS: ALL-PASS.

### #8 — Kolom mati: `total_value`, `qty`, `resi_keluar`, `tanggal_keluar` — ✅ FIXED
Handler tambah PO selalu insert `qty=1`, `total_value=0` (form tidak punya field
nominal). `resi_keluar` & `tanggal_keluar` di tabel warehouse tidak pernah
diisi form manapun (placeholder pencarian publik bahkan menyebut "Resi
Masuk/Keluar" padahal keluar tidak pernah ada).
**Perlu keputusan:** hapus kolomnya, atau isi fungsinya (form resi keluar)?

**Fix (diputuskan: ISI FUNGSINYA, jangan hapus kolom tanpa strategi migrasi):**
- `admin.php` — handler baru `action_update_wh_outgoing`: isi `resi_keluar` +
  `tanggal_keluar` (tanggal wajib jika resi diisi; null jika resi dikosongkan),
  ownership tetap via `store_id`. Plus: form tambah PO kini menerima
  `po_total_value` (opsional) → kolom `total_value` terisi.
- `menu-warehouse.php` — kolom baru "Resi Keluar" di tabel: input inline per
  baris (resi + tanggal + tombol simpan). Kolom juga ikut ke ekspor CSV
  (header + index kolom DOM disesuaikan).
- `menu-po.php` — field opsional "Total Nilai PO (Rp)" di modal tambah order;
  nilai ikut ke ekspor CSV (kolom TOTAL NILAI (RP), dari data JS bukan DOM).
- `page-resi.php` — resi keluar tampil di portal publik sebagai baris kedua
  (masked, sama seperti resi masuk) + tanggal keluar.
- `qty` TETAP 1 (1 baris = 1 item/PO) — didokumentasikan by-design, bukan bug.

**Test:** skenario `g-wh-outgoing` (resi keluar + tanggal tersimpan) dan
`g-po-total` (`total_value` = 850000 dari form, qty tetap 1). Semua skenario
(21 PHP + XSS): ALL-PASS.

### #9 — Masking resi publik kosmetik saja — ✅ FIXED
`page-resi.php` menampilkan resi dimask (`SF674XXX`) tapi atribut `data-search`
di HTML yang sama memuat resi lengkap — tinggal view-source.
**Perlu keputusan:** pindahkan pencarian ke server-side / hapus resi penuh dari
HTML, atau terima risikonya?

**Fix (diputuskan: server-side):**
- `page-resi.php` — resi TIDAK lagi masuk atribut `data-search` (client search
  kini hanya nama/PO).
- Pencarian by resi penuh pindah ke form GET `?resi=` yang difilter
  SERVER-SIDE di PHP (mencakup resi_masuk & resi_keluar), lengkap dengan
  banner hasil + tombol reset.
- Placeholder client search dikoreksi (dulu menjanjikan "Resi Masuk/Keluar"
  yang tidak bisa dijalankan secara aman).
- Masking tampilan tetap ada (kedua resi tetap di-mask di tampilan publik).

### #10 — Nama customer tampil publik — ✅ DIPUTUSKAN: BIARKAN
Portal publik (Rekapan PO & Tagihan) menampilkan nama pemesan ke siapa pun tanpa
login. Kemungkinan by design, tapi data PII customer tenant terbuka untuk
di-scrape.
**Keputusan pemilik: TIDAK diubah — nama admin/customer memang harus terlihat
(by design portal rekap). Tidak ada perubahan kode.**

### #11 — Biaya QRIS & sumber harga tidak terpusat — ✅ FIXED
- Fee QRIS: 0,3% untuk < Rp500rb, 0,7% untuk ≥ Rp500rb (`menu-subscription.php`,
  `register.php`) — persentasenya "terbalik" dari biasanya; konfirmasi bukan typo.
- QRIS image hardcode `https://mercatoria.id/wp-content/uploads/2026/02/qris-barcode.jpg`
  (domain eksternal, path tanggal masa depan).
- Harga paket diduplikasi lagi di JS `priceMap` di `register.php` (anti-pola
  "satu sumber kebenaran" — seharusnya dari `goweb_get_package_definitions()`).

**Fix (diputuskan: SATUKAN SUMBER, policy persentase TIDAK diubah):**
- `functions.php` — 3 fungsi baru: `goweb_qris_fee_config()` (threshold 500rb,
  low 0,3% / high 0,7% + label), `goweb_calculate_qris_fee($base)`
  (base/pct/fee/total, ceil), `goweb_get_qris_image_url()`.
- `register.php` — `priceMap` JS digenerate dari kamus paket via wp_json_encode;
  fee JS pakai konstanta `QRIS_FEE` dari kamus; 6 kartu paket HTML yang
  hardcode diganti loop dari kamus (nama, harga, kapasitas MB/GB);
  angka default Step 3 (Starter) dihitung server-side dari kamus; QRIS image
  dari fungsi.
- `menu-subscription.php` — handler PHP pakai `goweb_calculate_qris_fee()`;
  JS pakai `QRIS_FEE`; QRIS image dari fungsi.
- Persentase "terbalik" (0,3% < 500rb, 0,7% ≥ 500rb) DIPERTAHANKAN persis —
  ini policy bisnis, hanya sumbernya yang disatukan. Konfirmasi arah fee
  tetap terbuka untuk pemilik.

**Test:** `test-qris.php` (eval implementasi asli dari functions.php:
79000→237/79.237, 499999→masih low 1500, 500000→high 3500/503.500) +
`test-qris-central.js` (verifikasi tidak ada lagi hardcode 0.003/0.007/500000/
mercatoria.id di kedua file, priceMap & kartu paket dari kamus) + regresi
register (dup-email/dup-slug/fresh) tetap hijau.

### #12 — Login tenant: sesi tunggal + lockout per-email — ✅ DIPUTUSKAN & DIPERBAIKI
- Login sukses menimpa `session_token_hash` → login di perangkat baru mengusir
  sesi di perangkat lama (satu token per tenant, bukan per-device).
- Lockout brute-force 5x/15 menit **per-email** (disengaja) → siapa pun bisa
  mengunci akun tenant manapun dengan 5 percobaan salah sengaja (DoS akun).

**Keputusan pemilik (opsi A): lockout per KOMBINASI IP + EMAIL;**
sesi tetap 1-per-tenant (perilaku lama dipertahankan).

**Fix (login.php):**
- Kunci lockout kini `md5(email|IP)` (dulu `md5(email)` saja).
- Skenario lama "email publik di portal → dijail 15 menit" hilang: penyerang
  dari IP sendiri cuma mengunci dirinya sendiri; pemilik akun login dari
  perangkat/IP-nya sendiri tetap bisa masuk.
- Tenant satu kantor (IP sama, email berbeda) tetap punya kunci masing-masing.
- Pesan lockout diperjelas: "...dari koneksi ini untuk akun ini...".
- IP dari `$_SERVER['REMOTE_ADDR']` (standard; X-Forwarded-For sengaja tidak
  dipakai karena mudah dipalsukan).

**Test:** skenario `lockout-combo`: 5x gagal dari IP 1.1.1.1 → counter=5;
percobaan ke-6 dari IP sama → TERKUNCI (pesank baru, counter tidak nambah);
IP berbeda + email sama → LOLOS lockout (counter IP kedua = 1, error password
biasa); email berbeda di IP sama → gak kena lockout akun lain. Catatan:
session_start() di CLI harus dibungkus output buffer (test-only). Semua 30
suite: ALL-PASS.

### #13 — Retensi otomatis sangat agresif — ✅ DIPUTUSKAN: BIARKAN
H+1 offline, **H+2 data dihapus permanen**, plus hapus total di hari ke-365
meski tenant aktif bayar. Cron WP berbasis kunjungan — di situs sepi cron bisa
telat lalu memproses backlog sekaligus.
**Keputusan pemilik: TETAP SEBAGAIMANA ADANYA — jendela ini sudah tertulis di
T&C saat registrasi, jadi mengubahnya berarti mengubah janji kontrak ke tenant.
Tidak ada perubahan kode.**

### #14 — Approve/reject pendaftaran: file bukti & insert silent-fail — ✅ FIXED
- Reject: bukti transfer tidak dihapus dari disk (file yatim).
- Approve: hasil `$wpdb->insert` tenants tidak dicek (gagal tetap nampil
  "berhasil disetujui"); `'tenant_' . time()` bisa bentrok PK kalau 2 approval
  dalam detik yang sama.

**Fix:**
- `super-admin.php` — ID tenant baru kini `tenant_<timestamp>_<random8hex>`
  dengan loop pengecekan eksistensi (tidak bisa bentrok PK).
- Hasil insert tenants dicek: gagal → pesan "GAGAL menyimpan tenant baru",
  pendaftaran TIDAK dihapus dari antrean (bisa di-approve ulang), payment
  awal & sukses message tidak dijalankan.
- `reject_registration` kini menghapus file bukti dari disk
  (`goweb_delete_uploaded_file_by_url`) sebelum baris pending dihapus.

**Test:** skenario `reject-cleanup` (pending terhapus + file bukti tercatat
ke dibersihkan + pesan tampil); skenario approve lama tetap hijau. Catatan:
skenario sempat salah kamar (di-include lewat admin.php) — diperbaiki ke
super-admin.php. Semua suite (24 PHP + 2 node): ALL-PASS.

### #15 — Lain-lain kecil — ✅ FIXED
- `admin.php` ~line 596: komentar orphan ("// ditulis oleh halaman Pengaturan…")
  — sisa edit yang kepotong.
- `menu-calculator.php`: rate THB hardcode `data-rate="460"` (tidak ikut setting
  tenant, beda dari 4 pill lain); display "Packing/Handling" di kartu hasil
  hardcode "Rp 5.000" padahal kalkulasi pakai `packingFee` dinamis.
- Logout via GET `?goweb_logout=1` tanpa nonce (logout-CSRF, dampak rendah).
- Form pembayaran publik: server tidak men-enforce `required` bukti transfer
  (JS dimatikan → bisa submit tanpa bukti), dan tidak ada rate-limit/captcha →
  tenant bisa dibanjiri entri pending palsu.

**Fix:**
- `admin.php` — komentar orphan diganti komentar utuh yang menjelaskan dari
  mana kurs dibaca.
- `menu-calculator.php` — THB pill kini dari `$active_rates['THB']` (fallback
  460); kartu hasil kini menampilkan `res-packing` dinamis dari `packingFee`.
- `functions.php` + `header.php` — logout kini WAJIB nonce
  (`?goweb_logout=1&_wpnonce=...` via `wp_nonce_url`); tanpa/benar salah nonce
  → `wp_die` "Link logout tidak valid", tidak logout.
- `tenant-web.php` — validasi server-side form konfirmasi pembayaran: nama
  pembeli/PO/ID sosial wajib, `payment_type` harus ada di daftar kategori
  tenant, nominal harus angka > 0 (pemisah ribuan "1.500.000" dinormalisasi —
  dulu `intval("1.500.000")` = 1 di database), bukti transfer WAJIB
  (enforce server-side). Render pesan wp_die rapi per pelanggaran.
- Catatan: rate-limit/captcha TIDAK ditambah (butuh keputusan & library
  tambahan); validasi server-side sudah menutup celah submit tanpa bukti.

**Test:** `test-logout.php` (nonce benar/salah/kosong, redirect & sesi
bersih, header pakai wp_nonce_url) + skenario `pay-no-proof`,
`pay-bad-nominal`, `pay-bad-type`, `pay-happy` (nominal "1.500.000" →
tersimpan 1500000, status Pending, kategori po). Semua 29 suite:
ALL-PASS. `php -l` bersih semua file.

**Klarifikasi pemilik soal kalkulator (catatan desain, gak ada kode berubah):**
- `menu-calculator.php` = kalkulator BEBAS untuk tenant: hitung apa saja,
  input bebas diedit, TIDAK ADA yang tersimpan (terbukti: nol interaksi wpdb,
  semua murni JS client-side). Pill mata uang/kargo hanya PRESET yang mengisi
  input — nilai input tetap bebas.
- `page-calculator.php` (portal publik) = kalkulator untuk BUYER, nyambung ke
  menu setting (kurs/tarif yang dikelola tenant). Ini yang ketat.
- Fix #15b THB: nilai preset THB disamakan SUMBERNYA dengan 4 pill lain
  (pola lama yang sudah ada sejak sebelum sesi ini — CNY/JPY/KRW/USD/cargo
  memang sudah membaca setting); perilaku kalkulator tidak berubah, tetap
  bebas. Bukan "mengikat kalkulator ke setting".

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
