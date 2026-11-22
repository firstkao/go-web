<?php
/*
Template Name: Tenant Public Portal
*/

// WAJIB: jangan di-cache. Halaman ini nampilin data yang bisa berubah
// kapan aja dari sisi admin (kurs, tarif kargo, status order, saldo slot
// WH) — kalau ke-cache, perubahan settingan gak bakal kelihatan sampai
// cache-nya di-purge manual, padahal harusnya langsung update. Ini WAJIB
// urgent — kalau customer lihat harga basi, mereka bisa rugi beneran.
if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true);
nocache_headers();
if ( function_exists('do_action') ) {
    do_action('litespeed_control_set_nocache', 'goweb tenant public portal - dynamic settings & data');
}
header('X-LiteSpeed-Cache-Control: no-cache');
// Surrogate-Control lebih spesifik buat CDN/edge cache (hCDN dkk) —
// sebagian CDN lebih nurut header ini dibanding Cache-Control biasa.
header('Surrogate-Control: no-store');
header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Selalu paksa tampilkan WordPress Admin Bar di bagian atas
show_admin_bar( true );

// 1. Tangkap slug tenant dari URL
$tenant_slug = get_query_var( 'tenant_slug' );
$current_tenant = goweb_find_tenant_by_slug( $tenant_slug );

if ( ! $current_tenant ) {
    wp_die('Maaf, halaman atau toko GO-WEB tidak ditemukan dalam sistem.');
}

$store_id   = $current_tenant['id'];
$store_name = $current_tenant['store_name'];
$admin_name = $current_tenant['admin_name'];
$is_active  = (isset($current_tenant['status']) && $current_tenant['status'] == 'Active');
$features   = isset($current_tenant['features']) ? $current_tenant['features'] : array();

$has_public_calc = ($is_active && in_array('public_calc', $features));
$has_payment= ($is_active && in_array('customer_payment', $features));
$has_testi = ($is_active && in_array('testimonials', $features));
$has_po     = ($is_active && in_array('po_recap', $features));
$has_tagihan= ($is_active && in_array('tagihan_recap', $features));
$has_wh     = ($is_active && in_array('warehouse', $features));

if ( ! $is_active ) {
    wp_die('Toko ' . esc_html($store_name) . ' sedang dalam status offline atau tutup.');
}

// Tentukan tab aktif berdasarkan URL (?tab=...) atau default ke fitur pertama yang aktif
$default_tab = 'calculator';
if (!$has_public_calc && $has_po) $default_tab = 'po';
else if (!$has_public_calc && !$has_po && $has_wh) $default_tab = 'resi';
else if (!$has_public_calc && !$has_po && !$has_wh && $has_payment) $default_tab = 'payment';
else if (!$has_public_calc && !$has_po && !$has_wh && !$has_payment && $has_testi) $default_tab = 'testimoni';

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : $default_tab;

// Gating tab publik (#24) — dulu ?tab=... cuma mengatur TOMBOL yang tampil;
// akses langsung via URL ke tab fitur yang dimatikan tetap me-render data
// (PO/tagihan/resi/pembayaran/kalkulator) & tetap menerima submission.
// Sekarang tab yang tidak aktif di-fallback ke tab default yang aktif.
$tab_allowed = array();
if ($has_public_calc) $tab_allowed[] = 'calculator';
if ($has_po) $tab_allowed[] = 'po';
if ($has_tagihan) $tab_allowed[] = 'tagihan';
if ($has_wh) $tab_allowed[] = 'resi';
if ($has_payment) $tab_allowed[] = 'payment';
if ($has_testi) $tab_allowed[] = 'testimoni';
if ( ! in_array( $current_tab, $tab_allowed, true ) ) {
    $current_tab = $default_tab;
}
$base_url = remove_query_arg('tab');

// Handler Simpan Konfirmasi Pembayaran ke Database Tenant
$submission_success = false;
global $wpdb;
$payments_table = goweb_get_table_names()['payments'];

if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_public_confirmation']) ) {
    if ( ! $has_payment ) {
        wp_die('Fitur konfirmasi pembayaran tidak aktif untuk toko ini.');
    }
    if ( ! isset($_POST['goweb_public_confirm_nonce']) || ! wp_verify_nonce($_POST['goweb_public_confirm_nonce'], 'goweb_public_confirm_' . $store_id) ) {
        wp_die('Sesi form kedaluwarsa. Silakan muat ulang halaman dan kirim ulang konfirmasi.');
    }
    $payment_type = sanitize_text_field($_POST['payment_type']);
    $po_name    = sanitize_text_field($_POST['po_name']);
    $buyer_name = sanitize_text_field($_POST['buyer_name']);
    $social_id  = sanitize_text_field($_POST['social_id']);
    $nominal    = sanitize_text_field($_POST['nominal']);
    $proof_url  = '';

    // Validasi SERVER-SIDE — dulu validasi `required` cuma ada di HTML form
    // (mudah dilewati langsung POST), jadi row "nominal 0 / nama kosong" bisa
    // masuk database pembayaran (#15).
    $payment_categories = goweb_get_tenant_setting( $store_id, 'payment_categories', goweb_get_default_payment_categories() );
    $validation_errors = array();
    if ( $buyer_name === '' ) $validation_errors[] = 'Nama pembeli wajib diisi.';
    if ( $social_id === '' ) $validation_errors[] = 'ID sosial media wajib diisi.';
    if ( $po_name === '' ) $validation_errors[] = 'Nama PO / referensi wajib diisi.';
    if ( $payment_type === '' || ! in_array( $payment_type, (array) $payment_categories, true ) ) {
        $validation_errors[] = 'Jenis pembayaran tidak valid.';
    }
    $nominal_normalized = str_replace( array('.', ','), '', $nominal ); // buang pemisah ribuan ("1.500.000" -> 1500000)
    if ( ! preg_match( '/^[0-9]{1,15}$/', $nominal_normalized ) || intval( $nominal_normalized ) <= 0 ) {
        $validation_errors[] = 'Nominal harus berupa angka lebih dari 0.';
    }
    if ( empty( $_FILES['payment_proof']['name'] ) ) {
        $validation_errors[] = 'Bukti transfer wajib dilampirkan.';
    }
    if ( ! empty( $validation_errors ) ) {
        wp_die( 'Konfirmasi pembayaran ditolak: ' . esc_html( implode( ' ', $validation_errors ) ) );
    }
    $nominal = $nominal_normalized; // dipakai intval() saat insert — dulu "1.500.000" jadi 1 di database

    $storage_limit_b = (isset($current_tenant['storage_limit']) ? floatval($current_tenant['storage_limit']) : 250) * 1048576;
    // Dihitung LIVE dari semua data tenant di database (bukan cuma file
    // upload) — lihat goweb_calculate_tenant_storage_bytes() di
    // functions.php. Gak ada lagi counter manual yang di-increment tiap
    // upload, jadi gak akan pernah "ngambang" dari kondisi asli.
    $storage_used_b  = goweb_calculate_tenant_storage_bytes( $store_id );

    if ( ! empty($_FILES['payment_proof']['name']) ) {
        $file_size = $_FILES['payment_proof']['size'];
        
        // Cek apakah upload ini bikin kuota jebol
        if ( ($storage_used_b + $file_size) > $storage_limit_b ) {
            wp_die('Maaf, kapasitas penyimpanan toko sedang penuh. Harap hubungi admin ' . esc_html($store_name) . ' untuk mengosongkan ruang.');
        }
    
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        $upload_overrides = array( 'test_form' => false, 'mimes' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ) );
        $uploaded = wp_handle_upload($_FILES['payment_proof'], $upload_overrides);
        
        if ( isset($uploaded['url']) && isset($uploaded['file']) ) { 
            $image_editor = wp_get_image_editor( $uploaded['file'] );
            if ( ! is_wp_error( $image_editor ) ) {
                $image_editor->resize( 800, 1200, false );
                $image_editor->set_quality( 70 );
                $image_editor->save( $uploaded['file'] );
            }
            $proof_url = esc_url($uploaded['url']); 
        }
    }

    // Cek hasil insert (#36) — dulu kegagalan DB diabaikan dan pembeli tetap
    // melihat pesan "berhasil terkirim" padahal datanya tidak tersimpan
    // (kelas bug yang sama dengan #14 di approve tenant).
    $payment_inserted = $wpdb->insert( $payments_table, array(
        'store_id'         => $store_id,
        'payment_category' => 'po',
        'period'           => $po_name,
        'payment_type'     => $payment_type,
        'nominal'          => intval($nominal),
        'sender'           => $buyer_name . ' (' . $social_id . ')',
        'proof_url'        => $proof_url,
        'status'           => 'Pending',
        'created_at'       => current_time('mysql'),
    ) );
    if ( $payment_inserted === false ) {
        // Jangan tinggalkan file bukti yatim kalau barisnya gagal dibuat.
        if ( ! empty( $proof_url ) ) {
            goweb_delete_uploaded_file_by_url( $proof_url );
        }
        wp_die( 'Maaf, konfirmasi pembayaran gagal disimpan karena kendala teknis. Silakan coba lagi beberapa saat lagi atau hubungi admin toko.' );
    }
    $submission_success = true;
    $current_tab = 'payment';
}

// HANDLER KIRIM TESTIMONI PUBLIK — pembeli kirim dari portal, status awal
// Pending; admin tenant yang memutuskan tampil/tidak lewat menu Testimoni
// di dashboard. Validasi SERVER-SIDE lengkap (pola #15e/#18): nama maks
// 100 char, rating wajib 1-5, isi maks 1000 char, plus anti-spam ringan:
// maksimal 3 testimoni Pending per nama di toko ini.
$testimonials_table = goweb_get_table_names()['testimonials'];
$testi_success = false;
if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_public_testimonial']) ) {
    if ( ! $has_testi ) {
        wp_die('Fitur testimoni tidak aktif untuk toko ini.');
    }
    if ( ! isset($_POST['goweb_public_testi_nonce']) || ! wp_verify_nonce($_POST['goweb_public_testi_nonce'], 'goweb_public_testi_' . $store_id) ) {
        wp_die('Sesi form kedaluwarsa. Silakan muat ulang halaman dan kirim ulang testimoni.');
    }
    $testi_name    = sanitize_text_field($_POST['testi_name'] ?? '');
    $testi_rating  = intval($_POST['testi_rating'] ?? 0);
    $testi_message = sanitize_textarea_field($_POST['testi_message'] ?? '');
    $testi_errors  = array();
    if ( $testi_name === '' || strlen( $testi_name ) > 100 ) $testi_errors[] = 'Nama wajib diisi (maksimal 100 karakter).';
    if ( $testi_rating < 1 || $testi_rating > 5 ) $testi_errors[] = 'Rating harus 1 sampai 5 bintang.';
    if ( $testi_message === '' || strlen( $testi_message ) > 1000 ) $testi_errors[] = 'Testimoni wajib diisi (maksimal 1000 karakter).';
    $testi_pending_same = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$testimonials_table} WHERE store_id = %s AND status = 'Pending' AND buyer_name = %s", $store_id, $testi_name
    ) );
    if ( intval( $testi_pending_same ) >= 3 ) $testi_errors[] = 'Kamu sudah punya beberapa testimoni yang masih menunggu moderasi. Tunggu sampai diproses admin toko.';
    if ( ! empty( $testi_errors ) ) {
        wp_die( 'Testimoni ditolak: ' . esc_html( implode( ' ', $testi_errors ) ) );
    }
    // Cek hasil insert (#36) — jangan bilang "terkirim" kalau gagal simpan.
    $testi_inserted = $wpdb->insert( $testimonials_table, array(
        'store_id'   => $store_id,
        'buyer_name' => $testi_name,
        'rating'     => $testi_rating,
        'message'    => $testi_message,
        'status'     => 'Pending',
        'created_at' => current_time('mysql'),
    ) );
    if ( $testi_inserted === false ) {
        wp_die( 'Maaf, testimoni gagal disimpan karena kendala teknis. Silakan coba lagi beberapa saat lagi.' );
    }
    $testi_success = true;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Pelanggan - <?php echo esc_html($store_name); ?> | GO-WEB</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php wp_head(); ?>

    <style>
        /* Token warna & font TIDAK didefinisikan di sini lagi — sumber
           tunggal ada di :root style.css, biar semua halaman publik
           (portal, login, register, web tenant) tampil dengan identitas sama. */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        .home-header { background: #fff; border-bottom: 1px solid var(--border-soft); padding: 18px 32px; display: flex; justify-content: space-between; align-items: center; }
        .home-brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 1.1rem; color: var(--text-dark); }
        .home-brand span { background: var(--pastel-purple); color: #fff; padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; }
        
        .home-container { max-width: 900px; margin: 40px auto; padding: 0 20px; flex: 1 0 auto; width: 100%; box-sizing: border-box; }
        .home-hero { text-align: center; margin-bottom: 32px; }
        .home-hero h1 { font-size: 2.2rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px; }
        .home-hero p { font-size: 0.95rem; color: var(--text-muted); }

        /* NAVIGASI TAB PILL DENGAN EFEK SOFT & GLOW UNGU */
        .feature-tabs { display: flex; justify-content: center; gap: 8px; margin-bottom: 32px; flex-wrap: wrap; background: #ffffff; padding: 6px; border-radius: 999px; border: 1.5px solid var(--border-soft); width: fit-content; margin-left: auto; margin-right: auto; box-shadow: 0 4px 15px rgba(139,92,246,0.04); }
        .feature-tab-btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 20px; border-radius: 999px; border: none; background: transparent; font-weight: 700; font-size: 0.88rem; cursor: pointer; color: var(--text-muted); transition: all 0.2s; text-decoration: none; }
        .feature-tab-btn.active { background: var(--pastel-purple); color: #fff; box-shadow: 0 4px 14px rgba(124,58,237,0.3); }

        .feature-panel { background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 32px; box-shadow: 0 4px 20px rgba(139,92,246,0.03); }
        
        .panel-heading { margin-bottom: 24px; }
        .panel-heading h3 { font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 4px; }
        .panel-heading p { font-size: 0.85rem; color: var(--text-muted); }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; background: #fff; }
        
        .btn-submit { padding: 12px 24px; border-radius: var(--radius-md); background: var(--accent-gradient); color: #fff; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; width: 100%; justify-content: center; box-shadow: 0 4px 14px rgba(124,58,237,0.25); }
        .disabled-notice { background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; padding: 20px; border-radius: var(--radius-md); font-weight: 700; text-align: center; font-size: 0.9rem; }
        .pub-curr-pill.active { background: var(--pastel-purple) !important; color: #fff !important; border-color: var(--pastel-purple) !important; box-shadow: 0 4px 12px rgba(139,92,246,0.25); }
    </style>
</head>
<body <?php body_class(); ?>>

    <!-- HEADER -->
    <header class="home-header">
        <div class="home-brand">
            <?php echo esc_html($store_name); ?> <span>OFFICIAL WEB</span>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            Operated by <strong><?php echo esc_html($admin_name); ?></strong>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <div class="home-container">
        
        <div class="home-hero">
            <h1>Portal Pelanggan & Monitoring</h1>
            <p>Akses kalkulator estimasi biaya, cek status PO, hingga konfirmasi pembayaran langsung di <?php echo esc_html($store_name); ?>.</p>
        </div>

        <!-- NAVIGASI TABS (PILL STYLE ESTETIK SESUAI GAMBAR) -->
        <div class="feature-tabs">
            <?php if ($has_public_calc): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'calculator', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'calculator' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-calculator"></i> Kalkulator Biaya
                </a>
            <?php endif; ?>

            <?php if ($has_po): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'po', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'po' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clipboard-list"></i> Rekapan PO
                </a>
            <?php endif; ?>

            <?php if ($has_tagihan): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'tagihan', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'tagihan' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Tagihan
                </a>
            <?php endif; ?>

            <?php if ($has_wh): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'resi', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'resi' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-boxes-stacked"></i> Slot & Resi WH
                </a>
            <?php endif; ?>

            <?php if ($has_payment): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'payment', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'payment' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-receipt"></i> Konfirmasi Pembayaran
                </a>
            <?php endif; ?>

            <?php if ($has_testi): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'testimoni', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'testimoni' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-comments"></i> Testimoni
                </a>
            <?php endif; ?>
        </div>

        <!-- RENDER KONTEN PANEL — file ini ada di folder utama tema
             (sama kayak admin.php, menu-*.php, dll), BUKAN di subfolder
             partials/. Sebelumnya path ini nunjuk ke partials/, yang
             ternyata masih nyimpen versi file LAMA (dari sebelum nonce,
             perbaikan tabel database, dll) — jadi update apapun yang
             dilakukan ke file di folder utama gak pernah kepakai sama
             sekali, walau sudah di-upload berkali-kali. -->
        <div class="feature-panel">
            <?php
            switch ($current_tab) {
                case 'calculator':
                    $file_part = get_stylesheet_directory() . '/page-calculator.php';
                    break;
                case 'po':
                    $file_part = get_stylesheet_directory() . '/page-po.php';
                    break;
                case 'tagihan':
                    $file_part = get_stylesheet_directory() . '/page-tagihan.php';
                    break;
                case 'resi':
                    $file_part = get_stylesheet_directory() . '/page-resi.php';
                    break;
                case 'payment':
                    $file_part = get_stylesheet_directory() . '/page-payment.php';
                    break;
                case 'testimoni':
                    $file_part = get_stylesheet_directory() . '/page-testimoni.php';
                    break;
                default:
                    $file_part = get_stylesheet_directory() . '/page-payment.php';
                    break;
            }

            if (file_exists($file_part)) {
                include $file_part;
            } else {
                echo '<div style="padding: 40px; text-align: center; color: var(--text-muted);">File modul <strong>' . basename($file_part) . '</strong> tidak ditemukan di folder tema.</div>';
            }
            ?>
        </div>

    </div>

    <?php get_footer(); ?>