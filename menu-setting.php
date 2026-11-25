<?php
if (!defined('ABSPATH')) exit;

$modal_notification = '';
$setting_field_limits = array(
    'payment_category' => 255, // wp_goweb_invoices.category; sama dengan wp_goweb_payments.payment_type
    'order_status' => 50, // wp_goweb_orders.order_status
    'warehouse_status' => 50, // wp_goweb_warehouse.status
    'box_status' => 50, // wp_goweb_boxes.status
    'payment_destination' => 255, // wp_goweb_payments.payment_destination
);
$setting_text_length = static function ( $value ) {
    $value = (string) $value;
    if ( function_exists('mb_strlen') ) return mb_strlen($value, 'UTF-8');
    if ( preg_match_all('/./us', $value, $characters) !== false ) return count($characters[0]);
    return strlen($value);
};

// --- 1. HANDLE BACKEND / DATABASE SAVING (PHP POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ( ! isset($_POST['goweb_setting_nonce']) || ! wp_verify_nonce($_POST['goweb_setting_nonce'], 'goweb_setting_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }
    // Server-side gating fitur — kondisi sama dengan kondisi render kartu
    // pengaturan masing-masing di bawah. Tanpa ini, tenant bisa kirim POST
    // langsung ke /dashboard/setting/ walau fitur terkait gak dia punya.
    // Simpan Kurs (Ditambah THB)
    if (isset($_POST['action_save_rates'])) {
        if ( ! $has_calc && ! $has_public_calc ) {
            wp_die('Akses ditolak. Fitur Kalkulator tidak aktif untuk toko ini.');
        }
        $new_rates = array(
            'CNY' => floatval($_POST['rate_cny']),
            'JPY' => floatval($_POST['rate_jpy']),
            'THB' => floatval($_POST['rate_thb']),
            'KRW' => floatval($_POST['rate_krw']),
            'USD' => floatval($_POST['rate_usd']),
            'TWD' => floatval(isset($_POST['rate_twd']) ? $_POST['rate_twd'] : 510),
            'PHP' => floatval(isset($_POST['rate_php']) ? $_POST['rate_php'] : 285)
        );
        // Validasi server-side (#29): kurs harus > 0. Dulu cuma floatval
        // mentah, jadi kurs 0/negatif bisa tersimpan dan kalkulator PUBLIK
        // (yang dilihat buyer) jadi menampilkan harga Rp0 / negatif.
        foreach ( $new_rates as $rate_code => $rate_val ) {
            if ( $rate_val <= 0 ) {
                wp_die('Gagal simpan: kurs ' . esc_html( $rate_code ) . ' harus lebih besar dari 0.');
            }
        }
        goweb_set_tenant_setting( $store_id, 'rates', $new_rates );
        $modal_notification = array(
            'title' => 'Kurs Berhasil Disimpan',
            'desc' => 'Nilai tukar mata uang terbaru (termasuk THB) telah berhasil disimpan permanen ke database dan siap digunakan pada kalkulator.'
        );
    }

    // Simpan Status Order Kustom
    if (isset($_POST['action_save_statuses'])) {
        if ( ! $has_po ) {
            wp_die('Akses ditolak. Fitur Rekapan PO tidak aktif untuk toko ini.');
        }
        $raw_statuses = isset($_POST['custom_statuses']) ? $_POST['custom_statuses'] : array();
        // array_values WAJIB (#27): array_filter mempertahankan key asli, jadi
        // kalau baris pertama dihapus daftar tersimpan jadi "berlubang"
        // (key 1,2,3) — dan kode lain yang baca $list[0] dapat status kosong.
        $cleaned_statuses = array_values( array_filter( array_map('sanitize_text_field', (array) $raw_statuses) ) );
        // Daftar kosong = fitur mati total (gak bisa tambah order / ubah
        // status sama sekali) (#28) — pulihkan ke daftar default.
        $statuses_restored = false;
        if ( empty( $cleaned_statuses ) ) {
            $cleaned_statuses = array('Antrean', 'On Process', 'Arrived WH', 'Shipped Indo', 'Done');
            $statuses_restored = true;
        }
        foreach ( $cleaned_statuses as $status ) {
            if ( $setting_text_length($status) > $setting_field_limits['order_status'] ) {
                wp_die('Gagal simpan: setiap status order maksimal 50 karakter agar sesuai dengan batas penyimpanan Rekapan PO.');
            }
        }

        goweb_set_tenant_setting( $store_id, 'custom_statuses', $cleaned_statuses );
        $modal_notification = array(
            'title' => 'Status Order Diperbarui',
            'desc' => $statuses_restored
                ? 'Daftar status tidak boleh kosong, jadi dikembalikan ke status bawaan (Antrean, On Process, dst).'
                : 'Tahapan status pelacakan order berhasil disimpan permanen ke database dan disinkronkan ke menu rekapan.'
        );
    }

    // Simpan Kargo & Pajak per Mata Uang + Packing Fee
    if (isset($_POST['action_save_cargo'])) {
        if ( ! $has_calc && ! $has_public_calc ) {
            wp_die('Akses ditolak. Fitur Kalkulator tidak aktif untuk toko ini.');
        }
        $currency_codes = array('CNY', 'JPY', 'THB', 'KRW', 'USD', 'TWD', 'PHP');
        $currency_cargo = array();
        foreach ( $currency_codes as $code ) {
            $currency_cargo[$code] = array(
                'air'         => isset($_POST['cc_air_' . $code]) ? floatval($_POST['cc_air_' . $code]) : 0,
                'sea'         => isset($_POST['cc_sea_' . $code]) ? floatval($_POST['cc_sea_' . $code]) : 0,
                'tax_percent' => isset($_POST['cc_tax_' . $code]) ? floatval($_POST['cc_tax_' . $code]) : 0,
            );
        }
        goweb_set_tenant_setting( $store_id, 'currency_cargo', $currency_cargo );
        // Validasi server-side (#29): tarif & pajak tidak boleh negatif, pajak
        // wajar <= 100%, packing fee tidak negatif — semua ini nongol ke
        // kalkulator publik sebagai harga yang dilihat buyer.
        foreach ( $currency_cargo as $cc_code => $cc_vals ) {
            if ( $cc_vals['air'] < 0 || $cc_vals['sea'] < 0 ) {
                wp_die('Gagal simpan: tarif kargo ' . esc_html( $cc_code ) . ' tidak boleh negatif.');
            }
            if ( $cc_vals['tax_percent'] < 0 || $cc_vals['tax_percent'] > 100 ) {
                wp_die('Gagal simpan: persentase pajak ' . esc_html( $cc_code ) . ' harus antara 0 sampai 100.');
            }
        }
        $packing_fee_input = floatval($_POST['cargo_packing_fee'] ?? 5000);
        if ( $packing_fee_input < 0 ) {
            wp_die('Gagal simpan: packing fee tidak boleh negatif.');
        }
        goweb_set_tenant_setting( $store_id, 'packing_fee', $packing_fee_input );

        $modal_notification = array(
            'title' => 'Kargo & Pajak Disimpan',
            'desc' => 'Tarif kargo & pajak per mata uang, serta packing fee, berhasil diperbarui dan disinkronkan ke kalkulator publik.'
        );
    }

    // Simpan Daftar Jenis Pembayaran/Tagihan — dipakai bareng di form
    // Konfirmasi Pembayaran publik (Jenis Pembayaran) DAN Rekapan Tagihan
    // (Jenis Tagihan), biar 1 sumber yang sama gak perlu diubah 2 tempat.
    if (isset($_POST['action_save_payment_categories'])) {
        if ( ! $has_payment && ! $has_tagihan ) {
            wp_die('Akses ditolak. Fitur Konfirmasi Bayar/Rekapan Tagihan tidak aktif untuk toko ini.');
        }
        $raw_cats = isset($_POST['payment_categories']) ? $_POST['payment_categories'] : array();
        // array_values (#27) + jangan biarkan kosong (#28) — kalau kosong,
        // form Konfirmasi Pembayaran publik gak punya pilihan sama sekali
        // dan form Tagihan gak bisa dipakai.
        $cleaned_cats = array_values( array_filter( array_map('sanitize_text_field', (array) $raw_cats) ) );
        $cats_restored = false;
        if ( empty( $cleaned_cats ) ) {
            $cleaned_cats = goweb_get_default_payment_categories();
            $cats_restored = true;
        }
        foreach ( $cleaned_cats as $category ) {
            if ( $setting_text_length($category) > $setting_field_limits['payment_category'] ) {
                wp_die('Gagal simpan: setiap jenis pembayaran/tagihan maksimal 255 karakter agar sesuai dengan batas pembayaran dan tagihan.');
            }
        }

        goweb_set_tenant_setting( $store_id, 'payment_categories', $cleaned_cats );
        $modal_notification = array(
            'title' => 'Jenis Pembayaran/Tagihan Disimpan',
            'desc' => $cats_restored
                ? 'Daftar jenis pembayaran tidak boleh kosong, jadi dikembalikan ke daftar bawaan.'
                : 'Daftar ini dipakai bersama oleh Konfirmasi Pembayaran dan Rekapan Tagihan.'
        );
    }

    // Simpan daftar metode pembayaran publik secara terpisah dari jenis tagihan.
    if (isset($_POST['action_save_payment_destinations'])) {
        if ( ! $has_payment ) {
            wp_die('Akses ditolak. Fitur Konfirmasi Pembayaran tidak aktif untuk toko ini.');
        }
        $raw_destinations = isset($_POST['payment_destinations']) ? (array) $_POST['payment_destinations'] : array();
        $cleaned_destinations = array_values( array_filter( array_map('sanitize_text_field', $raw_destinations) ) );
        foreach ( $cleaned_destinations as $destination ) {
            if ( $setting_text_length($destination) > $setting_field_limits['payment_destination'] ) {
                wp_die('Gagal simpan: setiap metode pembayaran maksimal 255 karakter.');
            }
        }
        goweb_set_tenant_setting( $store_id, 'payment_destinations', $cleaned_destinations );
        $modal_notification = array(
            'title' => 'Metode Pembayaran Disimpan',
            'desc' => empty( $cleaned_destinations )
                ? 'Belum ada metode pembayaran yang ditampilkan ke buyer.'
                : 'Daftar metode pembayaran untuk buyer berhasil diperbarui.'
        );
    }

    // Simpan Pengaturan Warehouse (mode link ke PO + daftar status)
    if (isset($_POST['action_save_wh_settings'])) {
        if ( ! $has_wh ) {
            wp_die('Akses ditolak. Fitur Database Warehouse tidak aktif untuk toko ini.');
        }
        $wh_link_mode = ( isset($_POST['wh_link_mode']) && $_POST['wh_link_mode'] === 'linked' ) ? 'linked' : 'free';

        $raw_wh_statuses = isset($_POST['wh_statuses']) ? $_POST['wh_statuses'] : array();
        // array_values (#27) supaya [0] selalu ada; kosong -> default (#28)
        $cleaned_wh_statuses = array_values( array_filter( array_map('sanitize_text_field', (array) $raw_wh_statuses) ) );
        if ( empty( $cleaned_wh_statuses ) ) {
            $cleaned_wh_statuses = goweb_get_default_warehouse_statuses();
        }
        foreach ( $cleaned_wh_statuses as $status ) {
            if ( $setting_text_length($status) > $setting_field_limits['warehouse_status'] ) {
                wp_die('Gagal simpan: setiap status warehouse maksimal 50 karakter agar sesuai dengan batas penyimpanan Warehouse.');
            }
        }

        // Status Box (lapisan konsolidasi di atas item Warehouse individual)
        $raw_box_statuses = isset($_POST['box_statuses']) ? $_POST['box_statuses'] : array();
        $cleaned_box_statuses = array_values( array_filter( array_map('sanitize_text_field', (array) $raw_box_statuses) ) );
        if ( empty( $cleaned_box_statuses ) ) {
            $cleaned_box_statuses = goweb_get_default_box_statuses();
        }
        foreach ( $cleaned_box_statuses as $status ) {
            if ( $setting_text_length($status) > $setting_field_limits['box_status'] ) {
                wp_die('Gagal simpan: setiap status box maksimal 50 karakter agar sesuai dengan batas penyimpanan Box.');
            }
        }
        goweb_set_tenant_setting( $store_id, 'wh_link_mode', $wh_link_mode );
        goweb_set_tenant_setting( $store_id, 'wh_statuses', $cleaned_wh_statuses );
        goweb_set_tenant_setting( $store_id, 'box_statuses', $cleaned_box_statuses );

        $modal_notification = array(
            'title' => 'Pengaturan Warehouse Disimpan',
            'desc' => 'Mode keterkaitan PO, daftar status warehouse, dan status Box berhasil diperbarui.'
        );
    }
}

// Ambil data tersimpan dari database (Default THB diset 460 jika belum ada)
$saved_rates = goweb_get_tenant_setting( $store_id, 'rates', array('CNY' => 2450, 'JPY' => 110, 'THB' => 460, 'KRW' => 12, 'USD' => 16200, 'TWD' => 510, 'PHP' => 285) );
$saved_statuses = goweb_get_tenant_setting( $store_id, 'custom_statuses', array("Antrean", "On Process", "Arrived WH", "Shipped Indo", "Done") );
$default_currency_cargo = goweb_get_default_currency_cargo();
$saved_currency_cargo = goweb_get_tenant_setting( $store_id, 'currency_cargo', $default_currency_cargo );
$saved_packing_fee = goweb_get_tenant_setting( $store_id, 'packing_fee', 5000 );
$currency_labels = array( 'CNY' => '🇨🇳 Yuan (CNY)', 'JPY' => '🇯🇵 Yen (JPY)', 'THB' => '🇹🇭 Baht (THB)', 'KRW' => '🇰🇷 Won (KRW)', 'USD' => '🇺🇸 USD ($)', 'TWD' => '🇹🇼 Taiwan Dollar (TWD)', 'PHP' => '🇵🇭 Philippine Peso (PHP)' );
// Default 7 kategori pembayaran/tagihan standar — tenant boleh ubah/hapus/tambah
$saved_payment_categories = goweb_get_tenant_setting( $store_id, 'payment_categories', array(
    '💰 DP / Uang Muka Barang',
    '✅ Pelunasan / Sisa Bayar',
    '🚚 Ongkir Lokal (ke WH)',
    "✈️ Ongkir Kargo Int'l",
    '🧾 Pajak / Bea Masuk',
    '📦 Packing / Handling Fee',
    '🔖 Lainnya',
) );
$saved_payment_destinations = (array) goweb_get_tenant_setting( $store_id, 'payment_destinations', array() );
$saved_wh_link_mode = goweb_get_tenant_setting( $store_id, 'wh_link_mode', 'free' );
$saved_wh_statuses = goweb_get_tenant_setting( $store_id, 'wh_statuses', goweb_get_default_warehouse_statuses() );
$saved_box_statuses = goweb_get_tenant_setting( $store_id, 'box_statuses', goweb_get_default_box_statuses() );
?>

<div class="fin-wrap settings-page">
    <style>
        .settings-page { --settings-ink:#302b46; --settings-muted:#625d70; --settings-line:#e5e0f2; }
        .settings-page .ms-settings-tabs { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:6px; margin:0 0 16px; padding:6px; border:1px solid var(--settings-line); border-radius:13px; background:#f4f1f8; }
        .settings-page .ms-settings-tabs[hidden] { display:none; }
        .settings-page .ms-settings-tab { display:inline-flex; min-width:0; min-height:42px; align-items:center; justify-content:center; gap:8px; padding:8px 12px; border:1px solid transparent; border-radius:9px; background:transparent; color:#514b63; font-family:inherit; font-size:.82rem; font-weight:700; line-height:1.3; cursor:pointer; transition:background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease; }
        .settings-page .ms-settings-tab:hover { border-color:#ded7e9; background:#fff; color:#51416f; }
        .settings-page .ms-settings-tab[aria-selected="true"] { border-color:#ded7e9; background:#fff; color:#51416f; box-shadow:0 2px 7px rgba(59,48,95,.08); }
        @media (prefers-reduced-motion:reduce) { .settings-page .ms-settings-tab { transition:none; } }
        .settings-page .ms-settings-tab:focus-visible,
        .settings-page [role="tabpanel"]:focus-visible { outline:3px solid rgba(109,90,145,.32); outline-offset:2px; }
        .settings-page .ms-settings-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); align-items:start; gap:16px; margin:0 0 24px; }
        .settings-page .ms-settings-column { display:contents; }
        .settings-page .ms-settings-grid[data-single-group="true"] { grid-template-columns:minmax(0,760px); justify-content:center; }
        .settings-page .ms-settings-grid [data-setting-group][hidden] { display:none !important; }
        .settings-page .ms-setting-card { display:flex; min-width:0; flex-direction:column; padding:21px; border:1px solid var(--settings-line); border-radius:16px; background:#fff; box-shadow:0 5px 18px rgba(59,48,95,.045); }
        .settings-page .ms-setting-card-heading { display:flex; align-items:flex-start; gap:11px; margin-bottom:18px; }
        .settings-page .ms-setting-card-heading h3 { margin:1px 0 4px; color:var(--settings-ink); font-size:1rem; font-weight:800; line-height:1.35; }
        .settings-page .ms-setting-card-heading p { max-width:58ch; margin:0; color:var(--settings-muted); font-size:.8rem; line-height:1.55; }
        .settings-page .ms-setting-icon { display:grid; flex:0 0 38px; width:38px; height:38px; place-items:center; border:1px solid #ded7e9; border-radius:11px; background:linear-gradient(145deg,#f8f5fb,#f0ebf7); color:var(--primary); font-size:.92rem; }
        .settings-page .ms-setting-card form { display:flex; flex:1; flex-direction:column; }
        .settings-page .ms-setting-card button[type="submit"] { display:inline-flex; min-height:44px; width:100%; align-items:center; justify-content:center; gap:8px; margin-top:auto; padding:10px 14px !important; border:0 !important; border-radius:10px !important; background:var(--accent-gradient) !important; color:#fff !important; font-size:.82rem; font-weight:800; transition:filter .16s ease,transform .16s ease; }
        .settings-page .ms-setting-card button[type="submit"]:hover { filter:brightness(.94); transform:translateY(-1px); }
        .settings-page .ms-setting-card button[type="submit"]:focus-visible,
        .settings-page button[onclick]:focus-visible,
        .settings-page input:focus-visible,
        .settings-page #successModal button:focus-visible { outline:3px solid rgba(109,90,145,.32); outline-offset:2px; }
        .settings-page .ms-rate-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0 14px; }
        .settings-page .form-group-fin { min-width:0; }
        .settings-page .form-input-fin { min-height:40px; color:var(--settings-ink); }
        .settings-page input[type="number"] { font-variant-numeric:tabular-nums; }
        .settings-page .ms-setting-card [class$="-row-item"] { display:flex !important; min-width:0; align-items:center; gap:8px !important; }
        .settings-page .ms-setting-card [class$="-row-item"] > div:first-child { display:flex !important; flex:0 0 44px; flex-direction:column; gap:4px !important; }
        .settings-page .ms-setting-card [class$="-row-item"] > div:first-child button { display:grid; width:44px !important; height:44px !important; flex:0 0 44px; place-items:center; padding:0 !important; border:1px solid var(--settings-line) !important; border-radius:9px !important; background:#f7f4fa !important; color:#51416f !important; cursor:pointer; font-size:0 !important; }
        .settings-page .ms-setting-card [class$="-row-item"] > div:first-child button::before { content:""; width:7px; height:7px; border:solid currentColor; border-width:2px 2px 0 0; transform:rotate(-45deg); }
        .settings-page .ms-setting-card [class$="-row-item"] > div:first-child button[onclick*="1)"]:not([onclick*="-1)"])::before { transform:rotate(135deg); }
        .settings-page .ms-setting-card [class$="-row-item"] > input { flex:1; min-width:0; min-height:40px; color:var(--settings-ink); }
        .settings-page .ms-setting-card [class$="-row-item"] > button { display:grid; flex:0 0 44px; width:44px; height:44px; place-items:center; padding:0 !important; border:1px solid #efd0ce !important; border-radius:9px !important; background:#fbf1ef !important; color:#913c38 !important; cursor:pointer; font-size:0 !important; }
        .settings-page .ms-setting-card [class$="-row-item"] > button::before { content:""; width:12px; height:12px; background:linear-gradient(45deg,transparent 44%,currentColor 45%,currentColor 55%,transparent 56%),linear-gradient(-45deg,transparent 44%,currentColor 45%,currentColor 55%,transparent 56%); }
        .settings-page .ms-setting-card button[onclick^="add"] { display:inline-flex; min-height:44px; align-items:center; justify-content:center; gap:7px; padding:8px 12px !important; border:1px dashed #cfc4de !important; border-radius:9px !important; background:#f7f4fa !important; color:#51416f !important; cursor:pointer; font-size:.78rem !important; font-weight:750 !important; }
        .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] > div { padding:12px !important; border-color:var(--settings-line) !important; border-radius:12px !important; background:#fff; }
        .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] > div > div:first-child { margin-bottom:10px !important; color:#51416f !important; font-size:.82rem !important; }
        .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] > div > div:last-child { display:grid !important; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px !important; }
        .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] label { min-height:32px; color:#514b63 !important; font-size:.69rem !important; line-height:1.4; }
        .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] input { min-height:40px; padding:8px 9px !important; border:1px solid var(--settings-line) !important; border-radius:8px !important; color:var(--settings-ink); font-size:.78rem !important; font-variant-numeric:tabular-nums; }
        .settings-page .ms-method-note { max-width:62ch; color:var(--settings-muted) !important; line-height:1.55 !important; }
        .settings-page .ms-option-row { min-height:44px; border-color:var(--settings-line) !important; border-radius:10px !important; color:#514b63; line-height:1.45; }
        .settings-page input[type="radio"] { accent-color:#6d5a91; }
        .settings-page .ms-empty-state { padding:32px 20px !important; border-color:var(--settings-line) !important; border-radius:16px !important; color:var(--settings-muted) !important; box-shadow:0 5px 18px rgba(59,48,95,.045); }
        .settings-page .ms-empty-state i { display:grid; width:42px; height:42px; place-items:center; margin:0 auto 10px; border:1px solid #ded7e9; border-radius:12px; background:#f3eff8; color:var(--primary); font-size:1rem; }
        #successModal { background:rgba(31,27,41,.58) !important; }
        #successModal .modal-box { max-height:calc(100dvh - 32px); overflow:auto; border:1px solid #e5e0f2; border-radius:16px; box-shadow:0 18px 54px rgba(36,29,52,.24) !important; }
        @media (max-width:900px) {
            .settings-page .ms-settings-grid { grid-template-columns:1fr; gap:14px; }
            .settings-page .ms-setting-card { padding:19px; }
            .settings-page .ms-rate-fields { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media (max-width:520px) {
            .settings-page .ms-settings-tabs { grid-template-columns:repeat(2,minmax(0,1fr)); gap:5px; padding:5px; }
            .settings-page .ms-settings-tab { min-height:42px; padding:8px 6px; font-size:.76rem; }
            .settings-page .ms-settings-tab i { font-size:.78rem; }
            .settings-page .ms-setting-card { padding:17px 15px; border-radius:14px; }
            .settings-page .ms-setting-card-heading { gap:10px; margin-bottom:16px; }
            .settings-page .ms-setting-icon { flex-basis:36px; width:36px; height:36px; }
            .settings-page .ms-setting-card-heading h3 { font-size:.94rem; }
            .settings-page .ms-setting-card-heading p { font-size:.76rem; }
            .settings-page .ms-rate-fields { grid-template-columns:1fr; }
            .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] > div > div:last-child { grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px !important; }
            .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] > div > div:last-child > div:last-child { grid-column:1 / -1; }
            .settings-page .ms-setting-card-cargo form > div[style*="flex-direction: column"] label { min-height:0; margin-bottom:4px !important; font-size:.72rem !important; }
            .settings-page .ms-setting-card [class$="-row-item"] > div:first-child { flex-basis:44px; }
            .settings-page .ms-setting-card [class$="-row-item"] > div:first-child button { width:44px !important; }
            .settings-page .ms-empty-state { padding:26px 16px !important; }
        }
    </style>
    <div class="ms-setting-card-heading ms-setting-page-heading">
        <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-sliders"></i></span>
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin: 0 0 6px;">Pengaturan Sistem &amp; Status Order</h2>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0;">Atur nilai tukar mata uang, tarif kargo internasional, dan kustomisasi tahapan status order.</p>
        </div>
    </div>

    <?php if ( ! $has_calc && ! $has_public_calc && ! $has_po && ! $has_payment && ! $has_tagihan && ! $has_wh ): ?>
        <div class="ms-empty-state" style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 40px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
            <strong>Belum ada fitur aktif yang butuh pengaturan.</strong>
            <p style="font-size: 0.85rem; margin-top: 4px;">Hubungi Super Admin buat mengaktifkan fitur toko kamu.</p>
        </div>
    <?php endif; ?>

    <div class="ms-settings-tabs" role="tablist" aria-label="Kelompok pengaturan" hidden>
        <?php if ($has_calc || $has_public_calc): ?>
            <button type="button" class="ms-settings-tab" id="settings-tab-calculator" role="tab" aria-selected="false" aria-controls="settings-panel" tabindex="-1" data-setting-tab="calculator"><i class="fa-solid fa-calculator" aria-hidden="true"></i> Kalkulator</button>
        <?php endif; ?>
        <?php if ($has_po || $has_payment || $has_tagihan): ?>
            <button type="button" class="ms-settings-tab" id="settings-tab-orders" role="tab" aria-selected="false" aria-controls="settings-panel" tabindex="-1" data-setting-tab="orders"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Order &amp; Tagihan</button>
        <?php endif; ?>
        <?php if ($has_payment): ?>
            <button type="button" class="ms-settings-tab" id="settings-tab-payments" role="tab" aria-selected="false" aria-controls="settings-panel" tabindex="-1" data-setting-tab="payments"><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Pembayaran</button>
        <?php endif; ?>
        <?php if ($has_wh): ?>
            <button type="button" class="ms-settings-tab" id="settings-tab-warehouse" role="tab" aria-selected="false" aria-controls="settings-panel" tabindex="-1" data-setting-tab="warehouse"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Warehouse</button>
        <?php endif; ?>
    </div>

    <div class="ms-settings-grid" id="settings-panel" role="region" tabindex="0" aria-label="Pengaturan toko">
        <div class="ms-settings-column">
        <?php if ($has_calc || $has_public_calc): ?>
        <!-- KARTU 1: PENGATURAN KURS (TERMASUK THB) -->
        <div class="ms-setting-card ms-setting-card-rates" data-setting-group="calculator">
            <div class="ms-setting-card-heading">
                <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-money-bill-transfer"></i></span>
                <div>
                    <h3>Pengaturan Kurs</h3>
                    <p>Nilai tukar tersimpan dan dipakai otomatis pada kalkulator.</p>
                </div>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div class="ms-rate-fields">
                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇨🇳 KURS CNY / YUAN (IDR)</label>
                    <input type="number" name="rate_cny" class="form-input-fin" value="<?php echo esc_attr($saved_rates['CNY']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇯🇵 KURS JPY / YEN (IDR)</label>
                    <input type="number" name="rate_jpy" class="form-input-fin" value="<?php echo esc_attr($saved_rates['JPY']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇹🇭 KURS THB / BAHT (IDR)</label>
                    <input type="number" name="rate_thb" class="form-input-fin" value="<?php echo esc_attr(isset($saved_rates['THB']) ? $saved_rates['THB'] : 460); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇹🇼 KURS TWD / TAIWAN DOLLAR (IDR)</label>
                    <input type="number" name="rate_twd" class="form-input-fin" value="<?php echo esc_attr(isset($saved_rates['TWD']) ? $saved_rates['TWD'] : 510); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇵🇭 KURS PHP / PHILIPPINE PESO (IDR)</label>
                    <input type="number" name="rate_php" class="form-input-fin" value="<?php echo esc_attr(isset($saved_rates['PHP']) ? $saved_rates['PHP'] : 285); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇰🇷 KURS KRW / WON (IDR)</label>
                    <input type="number" name="rate_krw" class="form-input-fin" value="<?php echo esc_attr($saved_rates['KRW']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 24px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">🇺🇸 USD / DOLLAR (IDR)</label>
                    <input type="number" name="rate_usd" class="form-input-fin" value="<?php echo esc_attr($saved_rates['USD']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
                </div>
                </div>

                <button type="submit" name="action_save_rates" class="btn-submit-action"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Kurs</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($has_po): ?>
        <!-- KARTU PENGATURAN STATUS ORDER KUSTOM -->
        <div class="ms-setting-card ms-setting-card-status" data-setting-group="orders">
            <div class="ms-setting-card-heading">
                <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-list-check"></i></span>
                <div>
                    <h3>Pengaturan Status Order</h3>
                    <p id="order-status-limit">Tahapan status ini tersimpan di database dan langsung terhubung ke menu rekapan. Maksimal 50 karakter per status.</p>
                </div>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Daftar Status Aktif Saat Ini:</label>
                    <small class="ms-method-note" style="display:block; margin-bottom:8px;">Maksimal 50 karakter per status.</small>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="status-list-container">
                        <?php foreach($saved_statuses as $st): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="status-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="moveStatusRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="moveStatusRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="custom_statuses[]" class="form-input-fin" value="<?php echo esc_attr($st); ?>" maxlength="<?php echo esc_attr($setting_field_limits['order_status'] * 2); ?>" data-max-chars="<?php echo esc_attr($setting_field_limits['order_status']); ?>" aria-label="Status order" aria-describedby="order-status-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.status-row-item').remove()" aria-label="Hapus status order" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addDatabaseStatusRow()" style="background: var(--slate-100); color: var(--text-dark); border: 1px dashed var(--border-soft); width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Status Baru</button>
                </div>

                <button type="submit" name="action_save_statuses" class="btn-submit-action"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Status Order</button>
            </form>
        </div>
        <?php endif; ?>

    <?php if ($has_calc || $has_public_calc): ?>
        <!-- KARTU KARGO & PAJAK PER MATA UANG -->
        <div class="ms-setting-card ms-setting-card-cargo" data-setting-group="calculator">
            <div class="ms-setting-card-heading">
                <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-plane"></i></span>
                <div>
                    <h3>Kargo &amp; Pajak per Mata Uang</h3>
                    <p>Tarif kargo dan pajak mengikuti mata uang yang dipilih pada kalkulator publik.</p>
                </div>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>

                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px;">
                    <?php foreach ( $currency_labels as $code => $label ):
                        $cc = isset($saved_currency_cargo[$code]) ? $saved_currency_cargo[$code] : (isset($default_currency_cargo[$code]) ? $default_currency_cargo[$code] : array('air' => 0, 'sea' => 0, 'tax_percent' => 0));
                    ?>
                        <div class="ms-currency-row" style="border: 1.5px solid var(--border-soft); border-radius: 10px; padding: 10px;">
                            <div class="ms-currency-title" style="font-size: 13px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;"><?php echo esc_html($label); ?></div>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;">
                                <div>
                                    <label style="font-size: 10px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">✈️ Udara/Kg</label>
                                    <input type="number" name="cc_air_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($cc['air']); ?>" style="width: 100%; padding: 6px 8px; border: 1.5px solid var(--border-soft); border-radius: 6px; font-size: 12px; font-weight: 600;" required>
                                </div>
                                <div>
                                    <label style="font-size: 10px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">🚢 Laut/Kg</label>
                                    <input type="number" name="cc_sea_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($cc['sea']); ?>" style="width: 100%; padding: 6px 8px; border: 1.5px solid var(--border-soft); border-radius: 6px; font-size: 12px; font-weight: 600;" required>
                                </div>
                                <div>
                                    <label style="font-size: 10px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">🧾 Pajak %</label>
                                    <input type="number" name="cc_tax_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($cc['tax_percent']); ?>" step="0.1" style="width: 100%; padding: 6px 8px; border: 1.5px solid var(--border-soft); border-radius: 6px; font-size: 12px; font-weight: 600;" required>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px; padding-top: 12px; border-top: 1px dashed var(--border-soft);">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">📦 PACKING & HANDLING FEE (SAMA UNTUK SEMUA MATA UANG)</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: 10px; background: #fff; overflow: hidden;">
                        <span style="padding: 0 12px; background: var(--slate-50); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 13px;">Rp</span>
                        <input type="number" name="cargo_packing_fee" class="form-input-fin" value="<?php echo esc_attr($saved_packing_fee); ?>" style="width: 100%; padding: 10px 14px; border: none; font-weight: 600;" required>
                    </div>
                </div>

                <button type="submit" name="action_save_cargo" class="btn-submit-action"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Kargo &amp; Pajak</button>
            </form>
        </div>
        <?php endif; ?>

        </div>

        <div class="ms-settings-column">
        <?php if ($has_payment || $has_tagihan): ?>
        <!-- KARTU JENIS PEMBAYARAN/TAGIHAN -->
        <div class="ms-setting-card ms-setting-card-payments" data-setting-group="orders">
            <div class="ms-setting-card-heading">
                <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-money-check-dollar"></i></span>
                <div>
                    <h3>Jenis Pembayaran / Tagihan</h3>
                    <p id="payment-category-limit">Daftar ini digunakan bersama oleh Konfirmasi Pembayaran dan Rekapan Tagihan. Maksimal 255 karakter agar kategori tetap sama di pembayaran dan tagihan.</p>
                </div>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Daftar Jenis Aktif Saat Ini:</label>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="payment-cat-list-container">
                        <?php foreach($saved_payment_categories as $cat): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="payment-cat-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="movePaymentCatRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="movePaymentCatRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="payment_categories[]" class="form-input-fin" value="<?php echo esc_attr($cat); ?>" maxlength="<?php echo esc_attr($setting_field_limits['payment_category'] * 2); ?>" data-max-chars="<?php echo esc_attr($setting_field_limits['payment_category']); ?>" aria-label="Jenis pembayaran atau tagihan" aria-describedby="payment-category-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.payment-cat-row-item').remove()" aria-label="Hapus jenis pembayaran atau tagihan" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addPaymentCatRow()" style="background: var(--slate-100); color: var(--text-dark); border: 1px dashed var(--border-soft); width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Jenis Baru</button>
                </div>

                <button type="submit" name="action_save_payment_categories" class="btn-submit-action"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Jenis Pembayaran</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($has_payment): ?>
        <div class="ms-setting-card ms-setting-card-destinations" data-setting-group="payments">
            <div class="ms-setting-card-heading">
                <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span>
                <div>
                    <h3>Metode Pembayaran</h3>
                    <p>Atur pilihan yang bisa dipilih buyer, misalnya BCA, QRIS, GoPay, atau ShopeePay.</p>
                </div>
            </div>
            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px;">Metode Pembayaran untuk Buyer:</label>
                    <small id="payment-destination-limit" style="display: block; color: var(--text-muted); font-size: 12px; line-height: 1.5; margin-bottom: 10px;">Isi nama metode saja, satu per baris. Maksimal 255 karakter. Daftar ini hanya tampil di halaman konfirmasi pembayaran.</small>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px;" id="payment-destination-list-container">
                        <?php foreach($saved_payment_destinations as $destination): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="payment-destination-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="movePaymentDestinationRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="movePaymentDestinationRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="payment_destinations[]" class="form-input-fin" value="<?php echo esc_attr($destination); ?>" maxlength="<?php echo esc_attr($setting_field_limits['payment_destination'] * 2); ?>" data-max-chars="<?php echo esc_attr($setting_field_limits['payment_destination']); ?>" aria-label="Metode pembayaran" aria-describedby="payment-destination-limit" placeholder="cth: BCA, QRIS, GoPay, ShopeePay" style="flex: 1; min-width: 0; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;">
                                <button type="button" onclick="this.closest('.payment-destination-row-item').remove()" aria-label="Hapus metode pembayaran" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addPaymentDestinationRow()" style="background: var(--slate-100); color: var(--text-dark); border: 1px dashed var(--border-soft); width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Metode Pembayaran</button>
                </div>
                <button type="submit" name="action_save_payment_destinations" class="btn-submit-action"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Metode Pembayaran</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($has_wh): ?>
        <!-- KARTU 5: PENGATURAN WAREHOUSE (MODE LINK KE PO + DAFTAR STATUS) -->
        <div class="ms-setting-card ms-setting-card-warehouse" data-setting-group="warehouse">
            <div class="ms-setting-card-heading">
                <span class="ms-setting-icon" aria-hidden="true"><i class="fa-solid fa-boxes-stacked"></i></span>
                <div>
                    <h3>Pengaturan Warehouse</h3>
                    <p>Atur keterkaitan dengan PO dan tahapan status barang di gudang.</p>
                </div>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Keterkaitan dengan Rekapan PO:</label>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid <?php echo $saved_wh_link_mode === 'free' ? '#a78bfa' : 'var(--border-soft)'; ?>; border-radius: 10px; margin-bottom: 8px; cursor: pointer; font-size: 13px; font-weight: 600;">
                        <input type="radio" name="wh_link_mode" value="free" <?php checked($saved_wh_link_mode, 'free'); ?>>
                        Bebas berdiri sendiri (isi nama pemesan manual)
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid <?php echo $saved_wh_link_mode === 'linked' ? '#a78bfa' : 'var(--border-soft)'; ?>; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;">
                        <input type="radio" name="wh_link_mode" value="linked" <?php checked($saved_wh_link_mode, 'linked'); ?>>
                        Wajib pilih dari Rekapan PO yang sudah ada
                    </label>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Tahapan Status Warehouse:</label>
                    <small id="warehouse-status-limit" class="ms-method-note" style="display:block; margin-bottom:8px;">Maksimal 50 karakter per status.</small>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="wh-status-list-container">
                        <?php foreach($saved_wh_statuses as $st): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="wh-status-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="moveWhStatusRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="moveWhStatusRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="wh_statuses[]" class="form-input-fin" value="<?php echo esc_attr($st); ?>" maxlength="<?php echo esc_attr($setting_field_limits['warehouse_status'] * 2); ?>" data-max-chars="<?php echo esc_attr($setting_field_limits['warehouse_status']); ?>" aria-label="Status warehouse" aria-describedby="warehouse-status-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.wh-status-row-item').remove()" aria-label="Hapus status warehouse" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addWhStatusRow()" style="background: var(--slate-100); color: var(--text-dark); border: 1px dashed var(--border-soft); width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Status Baru</button>
                </div>

                <div style="margin-bottom: 16px; padding-top: 12px; border-top: 1px dashed var(--border-soft);">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">📦 Tahapan Status Box (Konsolidasi):</label>
                    <small id="box-status-limit" class="ms-method-note" style="display:block; margin-bottom:8px;">Maksimal 50 karakter per status.</small>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="box-status-list-container">
                        <?php foreach($saved_box_statuses as $st): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="box-status-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="moveBoxStatusRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="moveBoxStatusRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="box_statuses[]" class="form-input-fin" value="<?php echo esc_attr($st); ?>" maxlength="<?php echo esc_attr($setting_field_limits['box_status'] * 2); ?>" data-max-chars="<?php echo esc_attr($setting_field_limits['box_status']); ?>" aria-label="Status box" aria-describedby="box-status-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.box-status-row-item').remove()" aria-label="Hapus status box" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addBoxStatusRow()" style="background: var(--slate-100); color: var(--text-dark); border: 1px dashed var(--border-soft); width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Status Box Baru</button>
                </div>

                <button type="submit" name="action_save_wh_settings" class="btn-submit-action"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Pengaturan Warehouse</button>
            </form>
        </div>
        <?php endif; ?>
        </div>
    </div>

</div>

<!-- CUSTOM MODAL POPUP -->
<div class="modal-overlay" id="successModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999; <?php echo !empty($modal_notification) ? 'opacity: 1; pointer-events: auto;' : 'opacity: 0; pointer-events: none;'; ?> transition: opacity 0.25s ease; padding: 20px;">
    <div class="modal-box" style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 40px; margin-bottom: 12px;">✅</div>
        <h3 style="font-size: 18px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;"><?php echo isset($modal_notification['title']) ? $modal_notification['title'] : 'Berhasil'; ?></h3>
        <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5;"><?php echo isset($modal_notification['desc']) ? $modal_notification['desc'] : ''; ?></p>
        <button type="button" onclick="closeSuccessModal()" style="background: var(--primary); color: #fff; font-weight: 800; padding: 11px 24px; border-radius: 10px; border: none; cursor: pointer; width: 100%;">OK, Mengerti</button>
    </div>
</div>

<script>
(function initSettingsTabs() {
    const tablist = document.querySelector('.settings-page .ms-settings-tabs');
    const panel = document.getElementById('settings-panel');
    if (!tablist || !panel) return;

    const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
    const groups = Array.from(panel.querySelectorAll('[data-setting-group]'));
    if (tabs.length < 2 || !groups.length) return;

    const storageKey = 'goweb.settings.active-tab:' + window.location.pathname + window.location.search;
    let savedGroup = '';
    try { savedGroup = window.sessionStorage.getItem(storageKey) || ''; } catch (error) {}

    function activateTab(tab, moveFocus) {
        const group = tab.dataset.settingTab;
        tabs.forEach(function (item) {
            const active = item === tab;
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.tabIndex = active ? 0 : -1;
        });
        const visibleGroups = groups.filter(function (item) { return item.dataset.settingGroup === group; });
        groups.forEach(function (item) {
            item.hidden = item.dataset.settingGroup !== group;
        });
        panel.dataset.activeGroup = group;
        panel.dataset.singleGroup = visibleGroups.length < 2 ? 'true' : 'false';
        panel.setAttribute('role', 'tabpanel');
        panel.removeAttribute('aria-label');
        panel.setAttribute('aria-labelledby', tab.id);
        try { window.sessionStorage.setItem(storageKey, group); } catch (error) {}
        if (moveFocus) tab.focus();
    }

    tablist.hidden = false;
    const initialTab = tabs.find(function (tab) { return tab.dataset.settingTab === savedGroup; }) || tabs[0];
    activateTab(initialTab, false);

    tablist.addEventListener('click', function (event) {
        const tab = event.target.closest('[role="tab"]');
        if (tab && tabs.includes(tab)) activateTab(tab, false);
    });

    tablist.addEventListener('keydown', function (event) {
        const currentIndex = tabs.indexOf(event.target.closest('[role="tab"]'));
        if (currentIndex < 0) return;

        let nextIndex = currentIndex;
        if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        else if (event.key === 'Home') nextIndex = 0;
        else if (event.key === 'End') nextIndex = tabs.length - 1;
        else return;

        event.preventDefault();
        activateTab(tabs[nextIndex], true);
    });
})();

(function improveSettingRowControls() {
    const root = document.querySelector('.settings-page');
    if (!root) return;
    const groupLabels = {
        'status-row-item': 'status order',
        'payment-cat-row-item': 'jenis pembayaran atau tagihan',
        'payment-destination-row-item': 'metode pembayaran',
        'wh-status-row-item': 'status warehouse',
        'box-status-row-item': 'status box'
    };
    function labelControls(container) {
        const rows = [];
        if (container.matches && container.matches('.status-row-item, .payment-cat-row-item, .payment-destination-row-item, .wh-status-row-item, .box-status-row-item')) rows.push(container);
        if (container.querySelectorAll) rows.push.apply(rows, container.querySelectorAll('.status-row-item, .payment-cat-row-item, .payment-destination-row-item, .wh-status-row-item, .box-status-row-item'));
        rows.forEach(function (row) {
            const rowType = Object.keys(groupLabels).find(function (className) { return row.classList.contains(className); });
            if (!rowType) return;
            const controls = row.querySelectorAll('button[onclick*="Row(this"]');
            controls.forEach(function (button) {
                const direction = button.getAttribute('onclick').includes(', -1)') ? 'Naikkan' : 'Turunkan';
                button.setAttribute('aria-label', direction + ' urutan ' + groupLabels[rowType]);
            });
        });
    }
    labelControls(root);
    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) labelControls(node);
                });
            });
        }).observe(root, { childList: true, subtree: true });
    }
})();

(function enforceSettingCharacterLimits() {
    const root = document.querySelector('.settings-page');
    if (!root) return;
    root.addEventListener('input', function (event) {
        const field = event.target;
        if (!field.matches('input[data-max-chars]')) return;
        const limit = Number(field.dataset.maxChars);
        const characters = Array.from(field.value);
        if (characters.length <= limit) return;
        const cursor = field.selectionStart;
        field.value = characters.slice(0, limit).join('');
        if (typeof cursor === 'number') {
            const nextCursor = Math.min(cursor, field.value.length);
            field.setSelectionRange(nextCursor, nextCursor);
        }
    });
})();

function addDatabaseStatusRow() {
    const container = document.getElementById('status-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "status-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="moveStatusRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="moveStatusRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="custom_statuses[]" class="form-input-fin" value="Status Baru" maxlength="<?php echo (int) ($setting_field_limits['order_status'] * 2); ?>" data-max-chars="<?php echo (int) $setting_field_limits['order_status']; ?>" aria-label="Status order" aria-describedby="order-status-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.status-row-item').remove()" aria-label="Hapus status order" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

// Naik/turunin urutan 1 baris status. Urutan DOM ini yang nentuin urutan
// array custom_statuses[] pas form disubmit — gak perlu ubah apapun di
// backend, cukup pindahin posisi elemennya di halaman.
function moveStatusRow(btn, direction) {
    const row = btn.closest('.status-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addPaymentCatRow() {
    const container = document.getElementById('payment-cat-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "payment-cat-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="movePaymentCatRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="movePaymentCatRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="payment_categories[]" class="form-input-fin" value="Jenis Baru" maxlength="<?php echo (int) ($setting_field_limits['payment_category'] * 2); ?>" data-max-chars="<?php echo (int) $setting_field_limits['payment_category']; ?>" aria-label="Jenis pembayaran atau tagihan" aria-describedby="payment-category-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.payment-cat-row-item').remove()" aria-label="Hapus jenis pembayaran atau tagihan" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function movePaymentCatRow(btn, direction) {
    const row = btn.closest('.payment-cat-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addPaymentDestinationRow() {
    const container = document.getElementById('payment-destination-list-container');
    if (!container) return;
    const row = document.createElement('div');
    row.style.cssText = 'display: flex; gap: 6px; align-items: center;';
    row.className = 'payment-destination-row-item';
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="movePaymentDestinationRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="movePaymentDestinationRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="payment_destinations[]" class="form-input-fin" maxlength="<?php echo (int) ($setting_field_limits['payment_destination'] * 2); ?>" data-max-chars="<?php echo (int) $setting_field_limits['payment_destination']; ?>" aria-label="Metode pembayaran" aria-describedby="payment-destination-limit" placeholder="cth: BCA, QRIS, GoPay, ShopeePay" style="flex: 1; min-width: 0; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;">
        <button type="button" onclick="this.closest('.payment-destination-row-item').remove()" aria-label="Hapus metode pembayaran" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function movePaymentDestinationRow(btn, direction) {
    const row = btn.closest('.payment-destination-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addWhStatusRow() {
    const container = document.getElementById('wh-status-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "wh-status-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="moveWhStatusRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="moveWhStatusRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="wh_statuses[]" class="form-input-fin" value="Status Baru" maxlength="<?php echo (int) ($setting_field_limits['warehouse_status'] * 2); ?>" data-max-chars="<?php echo (int) $setting_field_limits['warehouse_status']; ?>" aria-label="Status warehouse" aria-describedby="warehouse-status-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.wh-status-row-item').remove()" aria-label="Hapus status warehouse" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function moveWhStatusRow(btn, direction) {
    const row = btn.closest('.wh-status-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addBoxStatusRow() {
    const container = document.getElementById('box-status-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "box-status-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="moveBoxStatusRow(this, -1)" title="Naikkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="moveBoxStatusRow(this, 1)" title="Turunkan urutan" style="background: var(--slate-100); color: var(--text-dark); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="box_statuses[]" class="form-input-fin" value="Status Baru" maxlength="<?php echo (int) ($setting_field_limits['box_status'] * 2); ?>" data-max-chars="<?php echo (int) $setting_field_limits['box_status']; ?>" aria-label="Status box" aria-describedby="box-status-limit" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.box-status-row-item').remove()" aria-label="Hapus status box" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function moveBoxStatusRow(btn, direction) {
    const row = btn.closest('.box-status-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    if(modal) {
        modal.style.opacity = '0';
        modal.style.pointerEvents = 'none';
    }
}
</script>