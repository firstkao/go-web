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

// Tab awal mengikuti fitur yang benar-benar aktif, termasuk konfigurasi
// tenant yang hanya menyediakan Tagihan atau fitur lain.
$tab_allowed = array();
if ($has_public_calc) $tab_allowed[] = 'calculator';
if ($has_po) $tab_allowed[] = 'po';
if ($has_tagihan) $tab_allowed[] = 'tagihan';
if ($has_wh) $tab_allowed[] = 'resi';
if ($has_payment) $tab_allowed[] = 'payment';
if ($has_testi) $tab_allowed[] = 'testimoni';

$portal_actions = array();
if ($has_public_calc) $portal_actions[] = 'menghitung estimasi biaya';
if ($has_po) $portal_actions[] = 'melihat daftar pesanan (PO)';
if ($has_tagihan) $portal_actions[] = 'memeriksa tagihan';
if ($has_wh) $portal_actions[] = 'melihat status paket di warehouse';
if ($has_payment) $portal_actions[] = 'mengirim konfirmasi pembayaran';
if ($has_testi) $portal_actions[] = 'menulis testimoni';
$portal_action_summary = '';
if ( count( $portal_actions ) > 1 ) {
    $last_portal_action = array_pop( $portal_actions );
    $portal_action_summary = implode( ', ', $portal_actions ) . ' serta ' . $last_portal_action;
} elseif ( ! empty( $portal_actions ) ) {
    $portal_action_summary = reset( $portal_actions );
}

$default_tab = ! empty( $tab_allowed ) ? reset( $tab_allowed ) : '';
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : $default_tab;

// Gating tab publik (#24): URL yang tidak aktif selalu kembali ke fitur
// pertama yang tersedia, dan konfigurasi tanpa fitur tidak memilih panel palsu.
if ( ! in_array( $current_tab, $tab_allowed, true ) ) {
    $current_tab = $default_tab;
}
$base_url = remove_query_arg('tab');

// Handler form publik. Kesalahan yang bisa diperbaiki pelanggan ditampilkan
// kembali di dalam portal supaya konteks halaman dan teks yang sudah diisi aman.
$submission_success = false;
$testi_success = false;
$payment_submission_error = '';
$testi_submission_error = '';
$payment_proof_url = '';
$payment_form_values = array(
    'payment_type' => isset($_POST['payment_type']) && is_scalar($_POST['payment_type']) ? sanitize_text_field(wp_unslash($_POST['payment_type'])) : '',
    'payment_destination' => isset($_POST['payment_destination']) && is_scalar($_POST['payment_destination']) ? sanitize_text_field(wp_unslash($_POST['payment_destination'])) : '',
    'po_name' => isset($_POST['po_name']) && is_scalar($_POST['po_name']) ? sanitize_text_field(wp_unslash($_POST['po_name'])) : '',
    'buyer_name' => isset($_POST['buyer_name']) && is_scalar($_POST['buyer_name']) ? sanitize_text_field(wp_unslash($_POST['buyer_name'])) : '',
    'social_id' => isset($_POST['social_id']) && is_scalar($_POST['social_id']) ? sanitize_text_field(wp_unslash($_POST['social_id'])) : '',
    'nominal' => isset($_POST['nominal']) && is_scalar($_POST['nominal']) ? sanitize_text_field(wp_unslash($_POST['nominal'])) : '',
);
$testi_form_values = array(
    'name' => isset($_POST['testi_name']) && is_scalar($_POST['testi_name']) ? sanitize_text_field(wp_unslash($_POST['testi_name'])) : '',
    'rating' => isset($_POST['testi_rating']) && is_scalar($_POST['testi_rating']) ? intval($_POST['testi_rating']) : (isset($_POST['submit_public_testimonial']) ? 0 : 5),
    'message' => isset($_POST['testi_message']) && is_scalar($_POST['testi_message']) ? sanitize_textarea_field(wp_unslash($_POST['testi_message'])) : '',
);
global $wpdb;
$payments_table = goweb_get_table_names()['payments'];

if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_public_confirmation']) ) {
    if ( ! $has_payment ) {
        wp_die('Fitur konfirmasi pembayaran tidak aktif untuk toko ini.');
    }
    $current_tab = 'payment';
    $payment_nonce = isset($_POST['goweb_public_confirm_nonce']) && is_scalar($_POST['goweb_public_confirm_nonce'])
        ? sanitize_text_field(wp_unslash($_POST['goweb_public_confirm_nonce']))
        : '';
    if ( ! wp_verify_nonce($payment_nonce, 'goweb_public_confirm_' . $store_id) ) {
        $payment_submission_error = 'Sesi form sudah diperbarui. Periksa kembali isian dan kirim ulang dengan memilih bukti transfer baru.';
        $current_tab = 'payment';
    } elseif ( ! empty( $_POST['website_url_trap'] ) ) {
        // Honeypot tetap menghentikan bot tanpa memproses data kiriman.
        wp_die( 'Spam terdeteksi!' );
    } else {
    try {
    $payment_type = $payment_form_values['payment_type'];
    $payment_destination = $payment_form_values['payment_destination'];
    $po_name    = $payment_form_values['po_name'];
    $buyer_name = $payment_form_values['buyer_name'];
    $social_id  = $payment_form_values['social_id'];
    $nominal    = $payment_form_values['nominal'];
    $proof_url  = '';

    // Validasi SERVER-SIDE — dulu validasi `required` cuma ada di HTML form
    // (mudah dilewati langsung POST), jadi row "nominal 0 / nama kosong" bisa
    // masuk database pembayaran (#15).
    $payment_categories = goweb_get_tenant_setting( $store_id, 'payment_categories', goweb_get_default_payment_categories() );
    $payment_destinations = array_values( array_filter( array_map( 'sanitize_text_field', (array) goweb_get_tenant_setting( $store_id, 'payment_destinations', array() ) ) ) );
    $validation_errors = array();
    if ( $buyer_name === '' ) $validation_errors[] = 'Nama pembeli wajib diisi.';
    if ( $social_id === '' ) $validation_errors[] = 'ID sosial media wajib diisi.';
    if ( $po_name === '' ) $validation_errors[] = 'Nama PO / referensi wajib diisi.';
    if ( $payment_type === '' || ! in_array( $payment_type, (array) $payment_categories, true ) ) {
        $validation_errors[] = 'Jenis pembayaran tidak valid.';
    }
    if ( empty( $payment_destinations ) ) {
        $validation_errors[] = 'Toko belum mengatur metode pembayaran. Silakan hubungi admin toko.';
    } elseif ( $payment_destination === '' || ! in_array( $payment_destination, $payment_destinations, true ) ) {
        $validation_errors[] = 'Metode pembayaran tidak valid.';
    }
    $nominal_normalized = str_replace( array('.', ','), '', $nominal ); // buang pemisah ribuan ("1.500.000" -> 1500000)
    if ( ! preg_match( '/^[0-9]{1,15}$/', $nominal_normalized ) || intval( $nominal_normalized ) <= 0 ) {
        $validation_errors[] = 'Nominal harus berupa angka lebih dari 0.';
    }
    if ( empty( $_FILES['payment_proof']['name'] ) ) {
        $validation_errors[] = 'Bukti transfer wajib dilampirkan.';
    }
    if ( ! empty( $validation_errors ) ) {
        throw new \DomainException( implode( ' ', $validation_errors ) . ' Periksa kembali isian. Jika perlu, pilih ulang bukti transfer.' );
    }
    $nominal = $nominal_normalized; // dipakai intval() saat insert — dulu "1.500.000" jadi 1 di database

    // Throttle (#40): maksimal 5 konfirmasi per jam untuk kombinasi
    // IP + nama pembeli + nama PO. Dihitung SETELAH validasi lolos supaya
    // orang yang memperbaiki typo tidak ikut kena limit, tapi spam valid
    // tetap terhenti. Kuncinya sengaja tidak murni IP: di belakang CDN
    // (LiteSpeed/QUIC.cloud) REMOTE_ADDR bisa sama untuk banyak pengunjung.
    $pay_client_ip  = goweb_get_client_ip();
    $pay_throttle_k = 'goweb_pay_throttle_' . md5( $pay_client_ip . '|' . strtolower( $buyer_name ) . '|' . strtolower( $po_name ) );
    $pay_attempts   = (int) get_transient( $pay_throttle_k );
    if ( $pay_attempts >= 5 ) {
        throw new \DomainException( 'Batas konfirmasi pembayaran per jam tercapai. Silakan tunggu sebelum mencoba lagi atau hubungi admin toko.' );
    }
    set_transient( $pay_throttle_k, $pay_attempts + 1, HOUR_IN_SECONDS );

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
            throw new \DomainException( 'Kapasitas penyimpanan toko sedang penuh. Hubungi admin ' . $store_name . ' untuk bantuan. Pilih ulang bukti transfer setelah form dapat dikirim.' );
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
            $proof_url = esc_url($uploaded['url']);
            $payment_proof_url = $proof_url;
            $image_editor = wp_get_image_editor( $uploaded['file'] );
            if ( ! is_wp_error( $image_editor ) ) {
                $image_editor->resize( 800, 1200, false );
                $image_editor->set_quality( 70 );
                $image_editor->save( $uploaded['file'] );
            }
        } else {
            throw new \DomainException( 'Bukti transfer gagal diunggah. Pastikan file berformat JPG, PNG, GIF, atau WebP, lalu coba pilih ulang.' );
        }
    }

    // Timestamp penerimaan konfirmasi dicatat sistem, bukan diisi buyer.
    $payment_received_at = current_time('mysql');

    // Cek hasil insert (#36) — dulu kegagalan DB diabaikan dan pembeli tetap
    // melihat pesan "berhasil terkirim" padahal datanya tidak tersimpan
    // (kelas bug yang sama dengan #14 di approve tenant).
    $payment_inserted = $wpdb->insert( $payments_table, array(
        'store_id'         => $store_id,
        'payment_category' => 'po',
        'period'           => $po_name,
        'payment_type'     => $payment_type,
        'payment_destination' => $payment_destination,
        'nominal'          => intval($nominal),
        'sender'           => $buyer_name . ' (' . $social_id . ')',
        'proof_url'        => $proof_url,
        'status'           => 'Pending',
        'created_at'       => $payment_received_at,
    ) );
    if ( $payment_inserted === false ) {
        // Simpan konteks teknis ke PHP error log tanpa mencatat nama, kontak,
        // nominal, isi pilihan, atau URL bukti milik pembeli.
        error_log( 'GO-WEB public payment insert failed: ' . ( isset($wpdb->last_error) && $wpdb->last_error !== '' ? $wpdb->last_error : 'wpdb->insert returned false without a database error' ) );
        // Jangan tinggalkan file bukti yatim kalau barisnya gagal dibuat.
        if ( ! empty( $proof_url ) ) {
            goweb_delete_uploaded_file_by_url( $proof_url );
            $payment_proof_url = '';
        }
        throw new \DomainException( 'Konfirmasi gagal disimpan karena kendala teknis. Data teks tetap terisi; pilih ulang bukti transfer lalu coba lagi atau hubungi admin toko.' );
    }
    $submission_success = true;
    $payment_form_values = array_fill_keys( array_keys( $payment_form_values ), '' );
    $current_tab = 'payment';
    } catch ( \DomainException $e ) {
        if ( ! empty( $payment_proof_url ) ) goweb_delete_uploaded_file_by_url( $payment_proof_url );
        $payment_submission_error = $e->getMessage();
        $current_tab = 'payment';
    } catch ( \Throwable $e ) {
        if ( ! empty( $payment_proof_url ) ) goweb_delete_uploaded_file_by_url( $payment_proof_url );
        error_log( 'GO-WEB public payment error: ' . $e->getMessage() );
        $payment_submission_error = 'Terjadi kendala teknis saat memproses konfirmasi. Data teks tetap terisi; pilih ulang bukti transfer sebelum mencoba lagi.';
        $current_tab = 'payment';
    }
    }
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
    $current_tab = 'testimoni';
    $testi_nonce = isset($_POST['goweb_public_testi_nonce']) && is_scalar($_POST['goweb_public_testi_nonce'])
        ? sanitize_text_field(wp_unslash($_POST['goweb_public_testi_nonce']))
        : '';
    if ( ! wp_verify_nonce($testi_nonce, 'goweb_public_testi_' . $store_id) ) {
        $testi_submission_error = 'Sesi form sudah diperbarui. Isian tetap tersedia; periksa kembali lalu kirim ulang testimoni.';
    } elseif ( ! empty( $_POST['website_url_trap'] ) ) {
        // Honeypot tetap menghentikan bot tanpa memproses data kiriman.
        wp_die( 'Spam terdeteksi!' );
    } else {
    $testi_photo_url = '';
    try {
    $testi_name    = $testi_form_values['name'];
    $testi_rating  = $testi_form_values['rating'];
    $testi_message = $testi_form_values['message'];
    $testi_errors  = array();
    // mb_strlen: batas dihitung KARAKTER, bukan byte (#42). Dulu nama 30 emoji
    // (120 byte) sudah ditolak padahal baru 30 karakter dari batas 100.
    if ( $testi_name === '' || mb_strlen( $testi_name ) > 100 ) $testi_errors[] = 'Nama wajib diisi (maksimal 100 karakter).';
    if ( $testi_rating < 1 || $testi_rating > 5 ) $testi_errors[] = 'Rating harus 1 sampai 5 bintang.';
    if ( $testi_message === '' || mb_strlen( $testi_message ) > 1000 ) $testi_errors[] = 'Testimoni wajib diisi (maksimal 1000 karakter).';
    $testi_pending_same = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$testimonials_table} WHERE store_id = %s AND status = 'Pending' AND buyer_name = %s", $store_id, $testi_name
    ) );
    if ( intval( $testi_pending_same ) >= 3 ) $testi_errors[] = 'Kamu sudah punya beberapa testimoni yang masih menunggu moderasi. Tunggu sampai diproses admin toko.';
    if ( ! empty( $testi_errors ) ) {
        throw new \DomainException( implode( ' ', $testi_errors ) );
    }
    // Throttle (#40): batas 3 per nama masih bisa diakali dengan ganti nama,
    // jadi ditambah batas per IP + nama (5 per jam). Dipakai kunci gabungan
    // supaya tetap berguna kalau REMOTE_ADDR sama untuk banyak orang (CDN).
    $testi_client_ip  = goweb_get_client_ip();
    $testi_throttle_k = 'goweb_testi_throttle_' . md5( $testi_client_ip . '|' . strtolower( $testi_name ) );
    $testi_attempts   = (int) get_transient( $testi_throttle_k );
    if ( $testi_attempts >= 5 ) {
        throw new \DomainException( 'Batas pengiriman testimoni per jam tercapai. Silakan coba lagi nanti.' );
    }
    // Batas lintas nama mencegah rotasi identitas melewati throttle per nama.
    // Kunci dipisah per toko agar aktivitas di toko berbeda tidak tercampur;
    // ambang lebih tinggi mengurangi risiko pengguna satu jaringan berbagi kuota.
    $testi_store_ip_k = 'goweb_testi_store_ip_throttle_' . md5( $testi_client_ip . '|' . $store_id );
    $testi_store_ip_attempts = (int) get_transient( $testi_store_ip_k );
    if ( $testi_store_ip_attempts >= 20 ) {
        throw new \DomainException( 'Terlalu banyak testimoni dikirim dari koneksi ini dalam satu jam. Silakan coba lagi nanti.' );
    }
    set_transient( $testi_throttle_k, $testi_attempts + 1, HOUR_IN_SECONDS );
    set_transient( $testi_store_ip_k, $testi_store_ip_attempts + 1, HOUR_IN_SECONDS );

    // Foto opsional: helper memvalidasi format raster, batas 2 MiB, kuota,
    // serta proses resize sebelum URL disimpan bersama testimoni.
    $testi_row_bytes = strlen( $testi_name ) + strlen( $testi_message ) + strlen( 'Pending' ) + 16;
    $testi_photo_url = goweb_upload_testimonial_photo(
        isset( $_FILES['testi_photo'] ) ? $_FILES['testi_photo'] : null,
        $store_id,
        isset( $current_tenant['storage_limit'] ) ? $current_tenant['storage_limit'] : 250,
        $testi_row_bytes
    );

    // Cek hasil insert (#36) — jangan bilang "terkirim" kalau gagal simpan.
    $testi_inserted = $wpdb->insert( $testimonials_table, array(
        'store_id'   => $store_id,
        'buyer_name' => $testi_name,
        'rating'     => $testi_rating,
        'message'    => $testi_message,
        'photo_url'  => $testi_photo_url !== '' ? $testi_photo_url : null,
        'status'     => 'Pending',
        'created_at' => current_time('mysql'),
    ) );
    if ( $testi_inserted === false ) {
        throw new \DomainException( 'Testimoni gagal disimpan karena kendala teknis. Isi form tetap terjaga; silakan coba lagi.' );
    }
    $testi_success = true;
    $testi_form_values = array('name' => '', 'rating' => 5, 'message' => '');
    } catch ( \DomainException $e ) {
        if ( ! empty( $testi_photo_url ) ) goweb_delete_uploaded_file_by_url( $testi_photo_url );
        $testi_submission_error = $e->getMessage();
    } catch ( \Throwable $e ) {
        if ( ! empty( $testi_photo_url ) ) goweb_delete_uploaded_file_by_url( $testi_photo_url );
        error_log( 'GO-WEB public testimonial error: ' . $e->getMessage() );
        $testi_submission_error = 'Terjadi kendala teknis saat memproses testimoni. Isi form tetap terjaga; silakan coba lagi nanti.';
    }
    }
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

        .home-header {
            position: relative;
            z-index: 10;
            width: 100%;
            border-bottom: 1px solid #e8e2f1;
            background: linear-gradient(105deg, #fff 0%, #fbfaff 58%, #f6f2fa 100%);
        }
        .home-header-inner {
            display: flex;
            width: min(100%, 1180px);
            min-height: 78px;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin: 0 auto;
            padding: 13px 24px;
        }
        .home-brand { display: flex; min-width: 0; align-items: center; gap: 12px; color: var(--text-dark); }
        .home-brand-mark {
            display: grid;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            place-items: center;
            color: var(--pastel-purple);
            font-size: 1.35rem;
        }
        .home-brand-copy { display: flex; min-width: 0; flex-direction: column; line-height: 1.25; }
        .home-brand-copy strong { max-width: min(58vw, 480px); overflow: hidden; color: var(--text-dark); font-size: 1rem; font-weight: 800; letter-spacing: -.025em; text-overflow: ellipsis; white-space: nowrap; }
        .home-brand-copy small { display: inline-flex; align-items: center; gap: 6px; margin-top: 4px; color: #766b85; font-size: .61rem; font-weight: 750; letter-spacing: .075em; }
        .home-brand-copy small i { color: #6f9278; font-size: .52rem; }
        .home-operator { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; color: #8b8597; font-size: .61rem; font-weight: 750; letter-spacing: .065em; text-transform: uppercase; }
        .home-operator strong { max-width: min(34vw, 280px); overflow: hidden; color: #514b63; font-size: .8rem; font-weight: 750; letter-spacing: 0; text-overflow: ellipsis; text-transform: none; white-space: nowrap; }

        .home-container { max-width: 1120px; margin: 38px auto 0; padding: 0 24px 64px; flex: 1 0 auto; width: 100%; box-sizing: border-box; }
        .home-hero { position: relative; max-width: 780px; margin: 0 auto 27px; padding: 24px 24px 21px; border: 1px solid #e5e0f2; border-radius: 22px; background: linear-gradient(145deg, #fff 10%, #f7f4fb 100%); text-align: center; box-shadow: 0 12px 32px rgba(59, 48, 95, .045); }
        .home-hero-tag { display: inline-flex; align-items: center; gap: 7px; margin-bottom: 10px; padding: 5px 11px; border: 1px solid #ded7e9; border-radius: 999px; background: #f0ebf7; color: #62517f; font-size: .67rem; font-weight: 800; letter-spacing: .045em; }
        .home-hero h1 { margin-bottom: 7px; color: var(--text-dark); font-size: clamp(1.6rem, 3vw, 2.05rem); font-weight: 800; line-height: 1.23; letter-spacing: -.04em; }
        .home-hero p { max-width: 650px; margin: 0 auto; color: var(--text-muted); font-size: .88rem; line-height: 1.65; }

        /* Navigasi modul publik: pill lembut, bisa digeser di layar kecil. */
        .feature-tabs { display: flex; width: fit-content; max-width: 100%; justify-content: center; gap: 6px; margin: 0 auto 20px; padding: 6px; overflow-x: auto; border: 1px solid var(--border-soft); border-radius: 15px; background: #fff; box-shadow: 0 5px 18px rgba(59, 48, 95, .04); scrollbar-width: thin; scrollbar-color: #d9d0e6 transparent; }
        .feature-tabs-hint { margin: -9px auto 8px; color: #716b80; font-size: .7rem; text-align: center; }
        .feature-tabs-hint[hidden] { display: none; }
        .feature-tabs-hint i { margin-right: 4px; }
        .feature-tab-btn { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 8px; padding: 10px 15px; border: 1px solid transparent; border-radius: 10px; background: transparent; color: var(--text-muted); font-size: .78rem; font-weight: 750; text-decoration: none; white-space: nowrap; transition: background .16s ease, border-color .16s ease, color .16s ease; }
        .feature-tab-btn:hover { border-color: #e6deef; background: #faf8fc; color: #51416f; }
        .feature-tab-btn.active, .feature-tab-btn[aria-current="page"] { border-color: #e2d9ec; background: #f0ebf7; color: #51416f; box-shadow: inset 0 -2px #6d5a91; }
        .feature-tab-btn.active i, .feature-tab-btn[aria-current="page"] i { color: #6d5a91; }
        .feature-tab-btn:focus-visible { outline: 3px solid rgba(109,90,145,.35); outline-offset: 2px; }

        .feature-panel { min-width: 0; padding: clamp(20px, 3vw, 34px); border: 1px solid var(--border-soft); border-radius: 20px; background: #fff; box-shadow: 0 8px 28px rgba(59, 48, 95, .045); }
        .feature-panel > .panel-heading { margin-bottom: 22px; }
        .panel-heading h3 { margin-bottom: 5px; color: var(--text-dark); font-size: clamp(1.2rem, 2vw, 1.45rem); font-weight: 800; line-height: 1.3; letter-spacing: -.025em; }
        .panel-heading p { color: var(--text-muted); font-size: .86rem; line-height: 1.6; }

        .form-group { min-width: 0; margin-bottom: 17px; }
        .form-group label { display: block; margin-bottom: 7px; color: #514b63; font-size: .75rem; font-weight: 750; letter-spacing: .015em; }
        .form-control { width: 100%; min-width: 0; min-height: 45px; padding: 10px 13px; border: 1px solid var(--border-soft); border-radius: 11px; background: #fff; color: var(--text-dark); font: inherit; font-size: .87rem; transition: border-color .16s ease, box-shadow .16s ease; }
        .form-control:focus { border-color: #9c8ab8; outline: 0; box-shadow: 0 0 0 3px rgba(109, 90, 145, .12); }
        .form-control::placeholder { color: #716b80; opacity: 1; }
        .feature-panel :is(input, select, textarea, button, a):focus-visible { outline: 3px solid rgba(109,90,145,.32); outline-offset: 2px; }
        .feature-panel :is(input, select, textarea):focus-visible { border-color: #806c9f; }
        .feature-panel .public-search-status:empty { display: none; }
        .btn-submit { min-height: 46px; padding: 12px 20px; border: 0; border-radius: 11px; background: linear-gradient(135deg, #6d5a91, #51416f); color: #fff; font: inherit; font-size: .86rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; width: 100%; justify-content: center; box-shadow: 0 7px 16px rgba(81, 65, 111, .18); transition: transform .16s ease, box-shadow .16s ease; }
        .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 10px 20px rgba(81, 65, 111, .22); }
        .disabled-notice { background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; padding: 20px; border-radius: var(--radius-md); font-weight: 700; text-align: center; font-size: 0.9rem; }
        @media (max-width: 700px) {
            .home-header-inner { min-height: 70px; padding: 11px 18px; }
            .home-container { margin: 24px auto 0; padding: 0 16px 44px; }
            .home-hero { margin-bottom: 18px; padding: 20px 16px 18px; border-radius: 18px; }
            .feature-tabs { width: 100%; justify-content: flex-start; margin-bottom: 14px; border-radius: 13px; }
            .feature-tab-btn { padding: 9px 12px; font-size: .74rem; }
            .feature-panel { padding: 19px 16px; border-radius: 16px; }
        }
        @media (max-width: 480px) {
            .feature-tabs-hint { text-align: left; }
            .home-header-inner { min-height: 66px; gap: 9px; padding: 10px 15px; }
            .home-brand { gap: 8px; }
            .home-brand-mark { flex-basis: 31px; width: 31px; font-size: 1.15rem; }
            .home-brand-copy strong { max-width: 54vw; font-size: .88rem; }
            .home-brand-copy small { font-size: .56rem; }
            .home-operator { font-size: .56rem; }
            .home-operator strong { max-width: 30vw; font-size: .7rem; }
        }
    </style>
</head>
<body <?php body_class(); ?>>

    <!-- HEADER -->
    <header class="home-header">
        <div class="home-header-inner">
            <div class="home-brand">
                <span class="home-brand-mark" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
                <span class="home-brand-copy"><strong><?php echo esc_html($store_name); ?></strong></span>
            </div>
            <div class="home-operator">
                <span>Dioperasikan oleh</span>
                <strong><?php echo esc_html($admin_name); ?></strong>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <div class="home-container">
        
        <div class="home-hero">
            <span class="home-hero-tag"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> PORTAL PELANGGAN</span>
            <h1>Selamat datang di <?php echo esc_html($store_name); ?></h1>
            <?php if ( $portal_action_summary !== '' ): ?>
                <p>Pilih fitur di bawah untuk <?php echo esc_html( $portal_action_summary ); ?>.</p>
            <?php endif; ?>
        </div>

        <!-- NAVIGASI TABS (PILL STYLE ESTETIK SESUAI GAMBAR) -->
        <?php if ( count( $tab_allowed ) > 1 ): ?>
            <p class="feature-tabs-hint" hidden><i class="fa-solid fa-arrows-left-right" aria-hidden="true"></i> Geser menu untuk melihat semua fitur.</p>
        <?php endif; ?>
        <?php if ( ! empty( $tab_allowed ) ): ?>
        <nav class="feature-tabs" aria-label="Fitur portal pelanggan">
            <?php if ($has_public_calc): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'calculator', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'calculator' ? 'active' : ''; ?>"<?php echo $current_tab === 'calculator' ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-calculator"></i> Kalkulator Biaya
                </a>
            <?php endif; ?>

            <?php if ($has_po): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'po', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'po' ? 'active' : ''; ?>"<?php echo $current_tab === 'po' ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-clipboard-list"></i> Daftar PO
                </a>
            <?php endif; ?>

            <?php if ($has_tagihan): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'tagihan', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'tagihan' ? 'active' : ''; ?>"<?php echo $current_tab === 'tagihan' ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-file-invoice-dollar"></i> Tagihan
                </a>
            <?php endif; ?>

            <?php if ($has_wh): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'resi', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'resi' ? 'active' : ''; ?>"<?php echo $current_tab === 'resi' ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-boxes-stacked"></i> Warehouse
                </a>
            <?php endif; ?>

            <?php if ($has_payment): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'payment', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'payment' ? 'active' : ''; ?>"<?php echo $current_tab === 'payment' ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-receipt"></i> Konfirmasi Pembayaran
                </a>
            <?php endif; ?>

            <?php if ($has_testi): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'testimoni', $base_url)); ?>" class="feature-tab-btn <?php echo $current_tab === 'testimoni' ? 'active' : ''; ?>"<?php echo $current_tab === 'testimoni' ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-comments"></i> Testimoni
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
        <script>
        (() => {
            const tabs = document.querySelector('.feature-tabs');
            const hint = document.querySelector('.feature-tabs-hint');
            if (!tabs || !hint) return;
            const updateHint = () => { hint.hidden = tabs.scrollWidth <= tabs.clientWidth; };
            updateHint();
            window.addEventListener('resize', updateHint);
        })();
        </script>

        <!-- RENDER KONTEN PANEL — file ini ada di folder utama tema
             (sama kayak admin.php, menu-*.php, dll), BUKAN di subfolder
             partials/. Sebelumnya path ini nunjuk ke partials/, yang
             ternyata masih nyimpen versi file LAMA (dari sebelum nonce,
             perbaikan tabel database, dll) — jadi update apapun yang
             dilakukan ke file di folder utama gak pernah kepakai sama
             sekali, walau sudah di-upload berkali-kali. -->
        <div class="feature-panel">
            <?php
            if ( $current_tab === '' ) {
                echo '<div class="disabled-notice" role="status">Portal ini belum memiliki fitur publik yang aktif. Silakan hubungi admin toko.</div>';
                $file_part = '';
            } else {
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
                    $file_part = '';
                    break;
            }
            }

            if ( $file_part && file_exists($file_part) ) {
                include $file_part;
            } elseif ( $file_part ) {
                echo '<div style="padding: 40px; text-align: center; color: var(--text-muted);">File modul <strong>' . esc_html( basename($file_part) ) . '</strong> tidak ditemukan di folder tema.</div>';
            }
            ?>
        </div>

    </div>

    <?php
    $GLOBALS['goweb_tenant_portal_footer'] = array('store_name' => $store_name);
    get_footer();
    ?>
