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

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : $default_tab;
$base_url = remove_query_arg('tab');

// Handler Simpan Konfirmasi Pembayaran ke Database Tenant
$submission_success = false;
global $wpdb;
$payments_table = goweb_get_table_names()['payments'];

if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_public_confirmation']) ) {
    if ( ! isset($_POST['goweb_public_confirm_nonce']) || ! wp_verify_nonce($_POST['goweb_public_confirm_nonce'], 'goweb_public_confirm_' . $store_id) ) {
        wp_die('Sesi form kedaluwarsa. Silakan muat ulang halaman dan kirim ulang konfirmasi.');
    }
    $payment_type = sanitize_text_field($_POST['payment_type']);
    $po_name    = sanitize_text_field($_POST['po_name']);
    $buyer_name = sanitize_text_field($_POST['buyer_name']);
    $social_id  = sanitize_text_field($_POST['social_id']);
    $nominal    = sanitize_text_field($_POST['nominal']);
    $proof_url  = '';

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

    $wpdb->insert( $payments_table, array(
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
    $submission_success = true;
    $current_tab = 'payment';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Pelanggan - <?php echo esc_html($store_name); ?> | GO-WEB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php wp_head(); ?>

    <style>
        :root {
            --pastel-purple: #8b5cf6;
            --pastel-purple-bg: #f5f3ff;
            --pastel-purple-border: #ddd6fe;
            --pastel-mint: #10b981;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --border-soft: #e5e7eb;
            --radius-md: 12px;
            --radius-lg: 16px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #faf8ff; color: var(--text-dark); display: flex; flex-direction: column; min-height: 100vh; }
        
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
        .feature-tab-btn.active { background: #7c3aed; color: #fff; box-shadow: 0 4px 14px rgba(124,58,237,0.3); }

        .feature-panel { background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 32px; box-shadow: 0 4px 20px rgba(139,92,246,0.03); }
        
        .panel-heading { margin-bottom: 24px; }
        .panel-heading h3 { font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 4px; }
        .panel-heading p { font-size: 0.85rem; color: var(--text-muted); }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; background: #fff; }
        
        .btn-submit { padding: 12px 24px; border-radius: var(--radius-md); background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; width: 100%; justify-content: center; box-shadow: 0 4px 14px rgba(124,58,237,0.25); }
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