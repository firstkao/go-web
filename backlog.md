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

## 🆕 FITUR TESTIMONI (dikerjakan agent peer, diselesaikan + diaudit + dites di sesi ini)

### Ringkasan fitur
- Tabel baru `wp_goweb_testimonials` (store_id, buyer_name, rating 1-5,
  message, status Pending/Approved/Rejected, created_at) — dbDelta +
  GOWEB_DB_VERSION 1.8.0 → 1.9.0.
- **Sisi tenant (menu)**: `/dashboard/testimonials/` — kartu moderasi dengan
  tab Pending/Approved/Rejected/Semua + counter; tombol Tampilkan/Tolak/
  Hapus; handler di admin.php (nonce `goweb_testi_action_<store_id>`,
  server-side gating `has_testi`, semua query diikat store_id).
- **Sisi buyer (page)**: tab `Testimoni` di portal publik
  (`page-testimoni.php`): daftar testimoni Approved + form kirim
  (nama maks 100 char, rating 1-5, isi maks 1000 char) — masuk sebagai
  Pending, tampil setelah di-approve admin tenant.
- Pembersihan data: testimoni ikut dihapus saat tenant dihapus
  (goweb_delete_tenant_completely) dan ikut dihitung dalam kuota storage.
- Fitur `testimonials` ditambah ke: checkbox registrasi + whitelist,
  modal edit tenant Super Admin + whitelist `#19`, sidebar & overview
  dashboard, gating server-side (menu + handler).

### Audit & perbaikan atas kerja agent
- **BUG CSS (kritis, visual)**: `style.css` punya `--accent-gradient:
  var(--accent-gradient)` (referensi diri / circular) → properti invalid →
  SEMUA tombol gradient (tab aktif, submit, pill aktif) kehilangan
  warnanya. Difix: nilai gradient asli ditulis langsung di :root.
- **Fitur setengah jadi**: sisi buyer belum ada sama sekali saat agent
  berhenti (tidak ada page, tidak ada tab, tidak ada handler submit).
  Dibangun lengkap: handler di tenant-web.php (nonce
  `goweb_public_testi_<store_id>`, validasi server-side pola #15e/#18,
  anti-spam ringan maks 3 Pending per nama), page-testimoni.php (list +
  form), integrasi tab & default_tab fallback di tenant-web.php.
- XSS: nama & pesan buyer di-escape (esc_html), rating di-clamp 1-5.
- Status non-Approved tidak pernah tampil publik.

**Test baru (6):** `testi-submit-happy` (Pending + data benar),
`testi-submit-invalid` (rating 9 ditolak), `testi-spam-limit` (maks 3
Pending/nama), `testi-approve` (moderasi → Approved), `testi-blocked`
(tanpa fitur → wp_die), `testi-display` (Approved tampil, Pending tidak,
XSS ter-escape). Total: 50 skenario PHP + 4 standalone ALL-PASS.
Harness: stub + FakeWPDB ditambah key `testimonials`, `mysql2date`,
GROUP BY COUNT, dan perbaikan strip klausa ORDER BY (bug lama harness:
multi-kondisi + ORDER BY selalu 0 baris).

### AUDIT KEEMPAT (pasca fitur testimoni) — #24 — ✅ FIXED

**#24 — Gating tab portal publik cuma mengatur tombol, bukan akses (SERIUS):**
Dulu `?tab=po|tagihan|resi|payment|calculator` via URL langsung tetap
me-render data & tetap menerima submission walau tenant MEMATIKAN fitur
tersebut — tombolnya hilang dari navigasi, tapi datanya masih bisa dilihat
siapa pun yang tahu URL-nya (mis. tenant matikan Rekapan PO agar data tidak
publik, nyatanya tetap tampil). Lolos di semua audit sebelumnya karena
semua test selalu memakai tab yang aktif.

**Fix (tenant-web.php, satu titik):**
- Daftar tab yang AKTIF (whitelist) + fallback ke `$default_tab` kalau
  `?tab=` minta tab yang dimatikan — UX tetap halus (bukan error).
- Handler POST pembayaran publik kini juga di-gate `$has_payment`
  (dulu cuma dicek nonce; tab fallback saja tidak menutup POST langsung).
- Handler testimoni sudah ter-gate sejak dibangun; moderasi dashboard
  sudah ter-gate `has_testi`.

**Minor (dicatat, belum diperbaiki — dampak kecil):**
- Anti-spam testimoni per-NAMA: ganti nama = bisa kirim terus (per-IP
  throttle butuh keputusan infra). Batas 3/nama + moderasi manual tetap
  jaring pertama.
- `strlen` hitung byte (nama emoji terhitung lebih cepat mencapai batas).
- Daftar testimoni Approved belum ada pagination (performa kalau ribuan).

**Test baru (3):** `g-tab-po-hidden` (data PO gak tampil + fallback ke
kalkulator), `g-tab-pay-hidden` (POST pembayaran ditolak, nol insert),
`g-tab-po-allowed` (fitur aktif tetap tampil). Total: 53 skenario PHP +
4 standalone ALL-PASS.

## 🔍 AUDIT KELIMA (pasca #24) — #25, #26 — ✅ FIXED

**#25 — File bukti jadi yatim saat delete baris (kembaran #14):**
`action_delete_warehouse` (foto bukti) & delete payment di dashboard
(`proof_url`) menghapus baris DB tanpa menghapus file dari disk — file
yatim menumpuk selamanya dan tetap dihitung di kuota (karena kuota dibaca
dari URL di DB, yang sudah hilang... jadi justru kuota "nyasar" ke bawah
padahal file masih di disk).
**Fix:** ambil URL bukti/foto SEBELUM delete →
`goweb_delete_uploaded_file_by_url()`.

**#26 — Status warehouse & box free-text (kembaran #20):**
`action_update_wh_status` & `action_update_box_status` menerima status
apa pun dari POST — status ngawur merusak badge/filter/portal (sama
seperti status order dulu).
**Fix:** validasi terhadap daftar `wh_statuses` / `box_statuses` toko.

**Audit bersih:** escaping menyeluruh (grep semua file: nol `echo $`
tanpa esc_*), ownership semua handler, index.php landing (esc + kamus
paket terpusat), handler box (ownership check + pelepasan item saat
delete box).

**Test baru (4):** `wh-delete-file` (foto ikut dibersihkan),
`pay-delete-file` (bukti ikut dibersihkan), `wh-bad-status` &
`box-bad-status` (status ngawur ditolak, nilai tidak berubah). Total:
57 skenario PHP + 4 standalone ALL-PASS. Harness: FakeWPDB kini
memproyeksikan daftar kolom SELECT sederhana (dulu get_var
"SELECT kolom" balikin kolom pertama baris — menyembunyikan asumsi
query nyata).

## 🔍 AUDIT KEENAM (pasca #25–#26) — #27, #28, #29 — ✅ FIXED

**#27 — Daftar pengaturan tersimpan "berlubang" → status item baru kosong:**
`array_filter()` mempertahankan KEY asli. Kalau tenant menghapus baris
PERTAMA di daftar status (Pengaturan), daftar tersimpan punya key 1,2,3
(tanpa 0) — lalu kode yang membaca `$wh_statuses[0]` /
`$box_statuses[0]` (item Warehouse & Box baru) dapat null, sehingga item
baru tersimpan dengan **status kosong** (badge kosong, filter & portal
rusak).
**Fix:** `array_values()` di semua handler simpan daftar + pemakaian
`array_values((array) ...)` di admin.php saat ambil status awal.

**#28 — Daftar kosong bisa disimpan → fitur mati total tanpa pesan jelas:**
tenant bisa menyimpan daftar status/jenis bayar KOSONG; akibatnya tidak ada
satu pun nilai valid: tambah order/tagihan selalu ditolak (validasi #20/#21),
form Konfirmasi Pembayaran publik tidak punya pilihan, dan status WH/Box tak
bisa diubah (validasi #26). Fitur jadi "bricked" hanya karena pengaturan.
Bonus bug halus: `array_filter` dijalankan SEBELUM `sanitize_text_field`,
jadi entri berisi spasi lolos saringan lalu jadi string kosong.
**Fix:** urutan dibalik (`array_map` dulu, baru `array_filter`, lalu
`array_values`); kalau hasilnya kosong → dipulihkan ke daftar bawaan + pesan
notifikasi menjelaskan. Plus jaring pengaman di
`goweb_get_tenant_setting()`: daftar kosong yang sudah terlanjur tersimpan
(dari versi lama) diperlakukan sebagai "belum diatur" → default dipakai,
sehingga data produksi lama ikut pulih tanpa migrasi manual.

**#29 — Kurs & tarif kargo tanpa validasi server-side:**
`action_save_rates` / `action_save_cargo` cuma `floatval()` mentah (UI juga
tanpa `min`). Kurs 0 atau negatif tersimpan → kalkulator **PUBLIK** yang
dilihat buyer menampilkan harga Rp0 / negatif; pajak bisa >100%; packing
fee bisa negatif.
**Fix:** kurs wajib > 0; tarif kargo tidak negatif; pajak 0–100%; packing
fee tidak negatif — semua `wp_die` dengan pesan spesifik.

**Test baru (8):** `set-holey-statuses`, `set-empty-statuses`,
`set-empty-cats`, `wh-status-from-holey`, `set-zero-rate`,
`set-negative-cargo`, `set-tax-over-100`, `set-rates-ok`. Total: **65
skenario PHP + 4 standalone ALL-PASS**. Catatan harness: stub kini punya
penyimpanan setting in-memory agar alur simpan→baca ulang benar-benar teruji.

## 🔍 AUDIT KETUJUH — #30, #31 — ✅ FIXED
(catatan: area halaman home `index.php` & CSS SENGAJA di-skip — sedang
dikerjakan agent UI untuk bug tampilan beranda.)

**#30 — Hapus PO meninggalkan tagihan & item warehouse YATIM:**
`action_delete_order` cuma menghapus baris order. Akibatnya:
- Tagihan (`invoices.order_id`) menunjuk PO yang sudah tidak ada → di
  portal publik buyer `LEFT JOIN` gagal, kolom "Nama PO / Invoice" & "Nama
  Pemesan" tampil KOSONG; tagihan jadi tidak bisa dikenali siapa pun, dan
  tidak bisa dipakai lagi (form tagihan wajib pilih PO).
- Item warehouse (`warehouse.order_id`) juga yatim → batch hilang di portal.
**Fix:** cascade yang sadar-domain: (1) tagihan milik PO itu ikut dihapus
(tagihan selalu 1-PO di sistem ini), (2) item warehouse TIDAK dihapus
(catatan barang fisik) — hanya `order_id` di-null-kan, (3) baru PO dihapus.
Ownership check ditambah sebelum cascade. Teks modal konfirmasi di
`menu-po.php` diperbarui supaya jujur menjelaskan efek cascade ini.
Catatan implementasi: pakai `$goweb_tables['invoices']` langsung karena
`$invoices_table` baru didefinisikan SETELAH blok ini (ketahuan dari test).

**#31 — Captcha login statis setelah percobaan gagal:**
Angka soal matematika hanya di-refresh kalau JAWABANNYA salah. Kalau
password-nya yang salah, soal yang sama terus dipakai → bot cukup
menghitung SEKALI lalu memakai jawaban itu untuk percobaan password tanpa
batas (captcha jadi formalitas).
**Fix:** captcha diganti setiap percobaan login gagal (dan nilai `$num1/$num2`
untuk render ulang ikut diperbarui).

**Minor (dicatat, belum diubah — keputusan bisnis):**
- `cycle_daily_rate` hasil perpanjangan dihitung dari `nominal` yang SUDAH
  termasuk biaya layanan QRIS, jadi rate harian sedikit lebih tinggi dari
  harga paket murni. Dampak: prorata upgrade sedikit di atas seharusnya.
- Kapasitas box (`kapasitas_kg`) tidak menolak penambahan item yang membuat
  isi melebihi kapasitas (persentase di UI di-cap 100%).

**Test baru (3):** `del-po-cascade` (tagihan PO ikut hapus, tagihan PO lain
utuh, item WH tidak dihapus & `order_id` jadi null), `del-po-cascade-other-store`
(ownership: data toko lain tidak tersentuh), `login-captcha-refresh`
(captcha berubah setelah password salah, masih rentang 1-10). Skenario
`lockout-combo` disesuaikan (menjawab captcha yang baru tiap iterasi) karena
captcha kini berputar — perilaku kode yang benar, bukan regresi.
Total: **68 skenario PHP + 4 standalone ALL-PASS**.

## 🔍 AUDIT KEDELAPAN — #32, #33 — ✅ FIXED
(skip area home `index.php` & CSS — sedang dikerjakan agent UI.)

**#32 — Upload bukti perpanjangan sewa lolos tanpa cek kuota:**
`menu-subscription.php` mengunggah `confirm_proof` tanpa memeriksa kuota
storage — celah yang sama dengan upload foto warehouse (yang sudah difix di
#22). Tenant bisa terus menembus kuota paketnya lewat bukti perpanjangan.
**Fix:** cek `pemakaian + ukuran file > limit paket` sebelum upload →
blokir dengan pesan jelas, insert payment tidak dijalankan.

**#33 — Kolom `orders.payment_status` MATI (kelas bug #8):**
Kolom ada di skema (default 'Belum Lunas') dan cuma terisi dari migrasi data
lama — tidak pernah diisi, dibaca, atau ditampilkan aplikasi mana pun.
Akibatnya rekap PO tidak punya informasi status bayar sama sekali.
**Fix (diputuskan: difungsikan, bukan dihapus):**
- `functions.php`: helper `goweb_sync_order_payment_status($store_id, $order_id)`
  — status 'Lunas' kalau PO punya ≥1 tagihan dan SEMUA-nya Lunas, selain itu
  'Belum Lunas'.
- `admin.php`: dipanggil setiap tagihan berubah (tambah / toggle Lunas /
  hapus), termasuk mengambil `order_id` sebelum baris tagihan dihapus.
- `menu-po.php`: kolom baru **Status Bayar** di tabel rekap PO (badge hijau/
  merah), ikut di ekspor CSV, dan `payment_status` masuk data JSON tabel.

**Test baru (2):** `pay-status-sync` (tambah tagihan → Belum Lunas, toggle →
Lunas, hapus → kembali Belum Lunas), `sub-quota-block` (file 300MB > kuota
paket 250MB → ditolak, nol payment terinsert). Total: **70 skenario PHP + 4
standalone ALL-PASS**.
Catatan test: ID invoice diambil dari hasil insert (auto-increment harness
mulai dari 100), dan kuota mengikuti `storage_limit` PAKET — bukan kolom
tenant — sehingga test memakai file 300MB.

## 🔍 AUDIT KESEBELAS — #38, #39 — ✅ FIXED
(skip home `index.php` & CSS — agent UI.)

**#38 — Fitur testimoni tidak kelihatan di daftar tenant Super Admin:**
Permintaan pemilik: fitur testimoni harus muncul di pilihan register dan di
detail tenant pada daftar tenant. Dicek: register **sudah** ada checkbox
`testimonials` (`register.php:440`, ikut whitelist), dan modal Edit tenant
**sudah** ada `feat-testi` (`super-admin.php:679`). Yang belum: **daftar tenant
sama sekali tidak menampilkan badge fitur apa pun** — jadi Super Admin tak bisa
membandingkan paket antar tenant tanpa buka modal satu-satu (dan testimoni pun
"tak kelihatan" di sana).
**Fix:** tambah kolom **“Fitur Aktif”** di tabel tenant berisi badge tiap fitur
(termasuk **Testimoni**), plus `—` kalau tenant tidak punya fitur. Kolom kosong
*colspan* ikut disesuaikan 7 → 8.

**#39 — Pendaftaran terbengkalai tak pernah dibersihkan (baris + file):**
`wp_goweb_pending_registrations` hanya terhapus kalau di-approve/reject manual.
Pendaftaran yang tidak pernah diurus = baris + **file bukti transfer** numpuk
selamanya, antrean Super Admin makin panjang, disk terus terpakai. Sama kelasnya
dengan retensi tenant (#13) tapi ini murni sampah, bukan data pelanggan.
**Fix:** pembersihan ikut cron harian `goweb_run_tenant_cleanup()` — pendaftaran
lebih tua dari `GOWEB_PENDING_MAX_DAYS` (default **90 hari**, satu konstanta biar
mudah diubah) dihapus beserta file bukti di disk, lalu dicatat di
`goweb_deleted_pending_log` (maks 200 entri).
**Catatan bug proses:** patch pertama pakai `$t['pending']` padahal di fungsi itu
tidak ada variabel `$t` (cuma `$tenants_table`), dan pakai kolom `id` padahal
tabel pending primary key-nya `request_id` — keduanya ketahuan **karena test-nya
gagal**, bukan karena dibaca ulang. Setelah dikoreksi: ALL-PASS.

**Test baru (5):** `sa-feature-column`, `pending-stale-cleanup` (mengekstrak
fungsi cron **asli** dari `functions.php` lalu `eval`, jadi yang diuji kode
produksi — bukan tiruan harness). Total: **81 skenario PHP + 4 standalone
ALL-PASS**.

## 🔍 AUDIT KESEPULUH — #36, #37 — ✅ FIXED
(skip home `index.php` & CSS — agent UI sedang mengerjakan; area `style.css`,
markup & token desain milik agent UI.)

**#36 — Insert gagal tapi user tetap diberi tahu "berhasil" (kelas bug #14):**
Di tiga alur, nilai balik `$wpdb->insert` tidak pernah dicek:
- konfirmasi pembayaran publik (`tenant-web.php`) → pembeli lihat "terkirim"
  padahal baris tidak tersimpan (admin toko tidak akan pernah melihatnya);
- form testimoni publik (`tenant-web.php`) → sama, "terkirim" palsu;
- konfirmasi perpanjangan/upgrade (`menu-subscription.php`) → tenant merasa
  sudah bayar padahal sewa **tidak** akan diperpanjang.
**Fix:** cek `=== false` → pesan gagal yang jelas (bukan pesan sukses), dan
file bukti yang terlanjur diunggah dihapus lagi supaya tidak jadi file yatim.

**#37 — "Reset Data" keuangan permanen tanpa jejak audit:**
Tombol reset menghapus SELURUH `finance_ledger` + `wallets` tenant secara
permanen (tanpa backup/soft delete), bisa dipicu tenant sendiri maupun Super
Admin lewat `?store=`, dan tidak meninggalkan catatan apa pun — jadi tidak ada
cara membedakan "sengaja" vs "kepencet", dan reset oleh Super Admin tidak
terlihat oleh tenant (pola yang sama sudah dibereskan untuk hapus tenant di #2).
**Fix:** catat log `goweb_finance_reset_log` (maks 200 entri): store_id,
nama toko, pelaku (Tenant/Super Admin), jumlah baris ledger & dompet yang
terhapus, waktu. Fungsi reset sendiri TIDAK diubah/dibatasi — tetap fitur tenant.

**Test baru (4):** `pay-insert-fail`, `testi-insert-fail`, `sub-insert-fail`,
`fin-reset-log` (harness: `FakeWPDB::$fail_tables` untuk simulasi insert gagal).
Total: **79 skenario PHP + 4 standalone ALL-PASS**.

**Catatan koordinasi:** agent UI memindahkan token desain dari `:root` di
`style.css` ke `goweb_get_design_tokens()` + `wp_head` (commit 3f41e58). Sudah
diaudit: semua template yang mencetak HTML memanggil `wp_head()`
(`header.php`/`login.php`/`register.php`/`tenant-web.php`; `index.php` &
`super-admin.php` lewat `get_header()`), jadi token tetap tercetak di semua
halaman — tidak ada halaman yang kehilangan token.

## 🔍 AUDIT KESEMBILAN — #34, #35 — ✅ FIXED
(skip area home `index.php` & CSS — sedang dikerjakan agent UI.)

**#34 — Form registrasi publik tanpa proteksi spam & tanpa batas ukuran file:**
`/register` sama sekali tidak punya honeypot/captcha/throttle, padahal tiap
pendaftaran MENYIMPAN 1 file bukti ke disk. Artinya siapa pun bisa (a)
membanjiri antrean pending Super Admin, dan (b) menghabiskan disk dengan
file bukti (tidak ada batas ukuran sama sekali). Login & form pembayaran
publik justru sudah dijaga — registrasi yang paling berisiko malah tidak.
**Fix:**
- Honeypot tersembunyi `reg_website_trap` (bot pengisi-semua-field ditolak).
- Throttle per IP: maks 5 percobaan pendaftaran / jam (transient).
- Batas ukuran bukti transfer 5 MB (sebelum upload & kompresi).

**#35 — Tanggal PO/Warehouse tanpa validasi format:**
`po_date`, `wh_tanggal_masuk`, `wh_tanggal_keluar` cuma di-`sanitize_text_field`.
Tanggal ngawur membuat `created_at` jadi tidak valid → filter JS
(`new Date(...)`) gagal dan **baris PO HILANG dari daftar** walau datanya
ada di DB; di warehouse tanggal tampil `01/01/1970`.
**Fix:** validasi `YYYY-MM-DD`; kosong/ngawur → pakai tanggal hari ini
(perilaku yang sudah dipakai saat field kosong).

**Test baru (5):** `reg-honeypot`, `reg-throttle`, `reg-proof-too-big`,
`po-bad-date`, `wh-bad-date`. Total: **75 skenario PHP + 4 standalone
ALL-PASS**. Catatan harness: stub `current_time()` kini menghormati format
(dulu selalu balikin timestamp sehingga bug format tanggal tersembunyi).

## ✅ SELESAI — "Alur Aneh" / Isu Desain (#6–#15) + AUDIT KEDUA (#16–#19) + AUDIT KETIGA (#20–#23) + AUDIT KEEMPAT (#24) + AUDIT KELIMA (#25–#26) + AUDIT KEENAM (#27–#29) + AUDIT KETUJUH (#30–#31) + AUDIT KEDELAPAN (#32–#33) + AUDIT KESEMBILAN (#34–#35) + AUDIT KESEPULUH (#36–#37) + AUDIT KESEBELAS (#38–#39)
Semua item sudah diperbaiki atau sudah diputuskan pemiliknya. Rincian per item di bawah.

### AUDIT KEDUA — temuan baru (putaran ulang setelah #6–#15 selesai)
Audit ulang menyeluruh (cron retensi, auth session, upload/kuota, CSV, proration,
routing, super-admin edit, semua alur submit) menemukan 4 lubang yang lolos
putaran pertama. Area yang sudah diverifikasi AMAN: lockout/captcha login,
session token (hash_equals + expiry), ownership check ?store=, gate
subscription, proration upgrade, nominal durasi server-side, CSV export
(formula injection terproteksi), XSS escape, routing & tenant picker, kuota
storage live, pembersihan file saat delete/reject.

### AUDIT KETIGA — verifikasi fix + temuan kecil (#20–#23) — ✅ FIXED
Putaran verifikasi atas seluruh fix #6–#19: semuanya masih utuh, 39 test
ALL-PASS, commit CSS peer (bdeef7d, ad35755) dicek murni style/markup tanpa
ganggu logic. Sweep baru menemukan 4 validasi longgar di handler dashboard
(dampaknya "data sampah" tenant sendiri — bukan akses silang):

- **#20 `action_add_order` / `action_update_status` (admin.php)** — Total
  Nilai PO gak boleh negatif; status order HARUS dari daftar
  `custom_statuses` toko (add & update-status sama-sama divalidasi; dulu
  free-text -> status ngawur merusak badge/filter/portal).
- **#21 `action_add_invoice` (admin.php)** — kategori HARUS dari daftar
  `payment_categories` toko (sumber yang sama dengan select UI), nominal
  harus angka > 0 (dulu 0/negatif & kategori bebas bisa masuk).
- **#22 upload foto warehouse (admin.php)** — kuota storage kini dicek
  JUGA di sisi dashboard (dulu cuma di upload publik tenant-web.php, jadi
  tenant bisa menembus kuota paketnya lewat upload foto WH).
- **#23 `action_save_finance` (admin.php)** — nominal mutasi harus > 0
  (dulu top-up negatif bisa mengurangi saldo dompet), IDR gak boleh
  negatif, tanggal harus format YYYY-MM-DD.

**Test baru (9):** `po-neg-total`, `po-bad-status`, `inv-bad-nominal`,
`inv-bad-cat`, `inv-happy` (kategori dari daftar default + nominal
150000), `wh-quota` (foto 2MB > kuota 1MB ditolak, gak ada baris insert),
`fin-bad-amount`, `fin-bad-date`, `fin-happy` (ledger top-up 1jt + Wallet
A). Total: 44 skenario PHP + 4 test standalone ALL-PASS. Catatan test:
tabel ledger di stub = `wp_goweb_finance_ledger`.

**Catatan riwayat git:** perubahan kode #20–#23 di `admin.php` ternyata
ke-commit lebih dulu oleh agent peer di `ad35755` ("De-hardcode warna")
karena working tree bersama — commit itu sebenarnya berisi campuran CSS
peer + validasi logic ini. Commit `d8802a4` hanya membawa catatan backlog.
Isi perubahannya benar & teruji; hanya pesan commit ad35755 yang kurang
menggambarkan isi penuhnya. Tidak ada amend (aturan no-rewrite).

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
