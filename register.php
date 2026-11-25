<?php
/*
Template Name: Tenant Registration Page
*/

// WAJIB: jangan di-cache. Form ini punya nonce dinamis; kalau ke-cache,
// semua pengunjung dapat nonce yang sama & basi, submit registrasi akan
// selalu gagal ("sesi form kedaluwarsa").
if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true);
nocache_headers();
if ( function_exists('do_action') ) {
    do_action('litespeed_control_set_nocache', 'goweb register page - dynamic nonce');
}
header('X-LiteSpeed-Cache-Control: no-cache');

// Exception khusus untuk error VALIDASI yang pesannya memang ditujukan ke
// pendaftar (duplikat email/slug, dll). Beda dari error teknis tak terduga:
// pesan exception ini ditampilkan APA ADANYA ke user, bukan diganti pesan
// generik "terjadi kendala teknis" oleh catch \Throwable di bawah.
class GoWebUserFacingError extends \Exception {}

$submitted_success = false;
$submission_error = '';

// Graceful fallback bila functions.php yang terpasang belum memiliki helper
// QRIS — tujuannya cuma supaya template tetap selesai dirender (JS wizard
// tetap termuat), bukan mengulang kebijakan.
// PENTING: JANGAN menyalin angka threshold/persentase atau URL gambar QRIS di
// sini. Duplikasi seperti itu persis bug #11 (sumber harga/fee terpecah): kalau
// policy di functions.php berubah, halaman ini diam-diam memakai angka lama.
// Kalau helper tidak tersedia, halaman dirender TANPA baris biaya layanan &
// tanpa gambar QRIS, jadi tidak pernah menampilkan angka yang salah.
$reg_qris_ready      = function_exists('goweb_qris_fee_config') && function_exists('goweb_calculate_qris_fee');
$reg_qris_fee_config = $reg_qris_ready ? goweb_qris_fee_config() : null;
$reg_starter_price   = goweb_get_package('starter')['price'];
$reg_starter_fee     = $reg_qris_ready
    ? goweb_calculate_qris_fee( $reg_starter_price )
    : array( 'base' => $reg_starter_price, 'fee' => 0, 'total' => $reg_starter_price );
$reg_qris_image_url  = function_exists('goweb_get_qris_image_url') ? goweb_get_qris_image_url() : '';

if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_tenant_registration']) ) {
    if ( ! isset($_POST['goweb_register_nonce']) || ! wp_verify_nonce($_POST['goweb_register_nonce'], 'goweb_register_action') ) {
        wp_die('Sesi form kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }

    // ANTI-SPAM (#34) — halaman ini publik dan tiap pendaftaran menyimpan
    // 1 file bukti ke disk, jadi tanpa proteksi siapa pun bisa membanjiri
    // antrean Super Admin + menghabiskan disk. Login & form pembayaran
    // publik sudah punya proteksi (captcha/honeypot/validasi), registrasi
    // justru paling berisiko karena belum pernah dijaga.
    //   (a) honeypot: field tersembunyi, hanya bot yang mengisinya
    if ( ! empty( $_POST['reg_website_trap'] ) ) {
        wp_die( 'Spam terdeteksi!' );
    }
    //   (b) throttle sederhana per IP: maks 5 percobaan pendaftaran / jam
    // IP untuk throttle lewat helper (#40) — default REMOTE_ADDR, dan bisa
    // memakai IP asli pengunjung kalau CIDR CDN didaftarkan (lihat functions.php).
    $reg_ip = function_exists('goweb_get_client_ip') ? goweb_get_client_ip() : ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown' );
    $reg_throttle_key = 'goweb_reg_throttle_' . md5( $reg_ip );
    $reg_attempts = (int) get_transient( $reg_throttle_key );
    if ( $reg_attempts >= 5 ) {
        wp_die( 'Terlalu banyak percobaan pendaftaran dari koneksi ini. Silakan coba lagi dalam 1 jam.' );
    }
    set_transient( $reg_throttle_key, $reg_attempts + 1, HOUR_IN_SECONDS );

    try {
        // Validasi SERVER-SIDE — dulu semua cek cuma ada di JS/HTML (required,
        // step wizard), gampang dilewati via POST langsung (#16):
        // - password kosong ikut ke-hash -> hash password kosong masuk DB
        // - email ngawur -> sanitize_email() jadi '' -> tenant terdaftar
        //   dengan email kosong
        // - nama toko kosong -> slug kosong -> portal publik rusak
        // - reg_features bebas dikirim string apa pun (malah bisa TypeError
        //   kalau bukan array) -> masuk DB tanpa cek
        $validation_errors = array();
        $store_name  = sanitize_text_field($_POST['reg_store_name'] ?? '');
        $admin_name  = sanitize_text_field($_POST['reg_admin_name'] ?? '');
        $admin_email = sanitize_email($_POST['reg_email'] ?? '');
        $store_slug  = sanitize_title($_POST['reg_store_slug'] ?? '');
        $raw_pass    = isset($_POST['reg_password']) ? (string) $_POST['reg_password'] : '';

        if ( $store_name === '' ) $validation_errors[] = 'Nama toko wajib diisi.';
        if ( $admin_name === '' ) $validation_errors[] = 'Nama admin wajib diisi.';
        if ( $admin_email === '' || ! is_email( $admin_email ) ) $validation_errors[] = 'Format email tidak valid.';
        if ( $store_slug === '' ) $validation_errors[] = 'URL toko (slug) wajib diisi.';
        if ( mb_strlen( $raw_pass ) < 8 ) $validation_errors[] = 'Password minimal 8 karakter.';

        // Fitur harus dari daftar yang dikenal (whitelist) — string ngawur
        // dari client gak boleh masuk DB. Kalau field-nya gak ada / bukan
        // array, perlakukan seperti pilihan default UI: SEMUA fitur aktif
        // (semua checkbox memang tercentang by design).
        $known_features = array('calculator', 'public_calc', 'customer_payment', 'po_recap', 'tagihan_recap', 'warehouse', 'testimonials');
        if ( isset($_POST['reg_features']) && is_array($_POST['reg_features']) ) {
            $features = array_values( array_intersect( array_map('sanitize_text_field', $_POST['reg_features']), $known_features ) );
        } else {
            $features = $known_features;
        }

        if ( ! empty( $validation_errors ) ) {
            throw new GoWebUserFacingError( implode( ' ', $validation_errors ) );
        }

        // Password di-hash langsung di sini (wp_hash_password) dan disimpan sebagai
        // hash di tabel wp_goweb_pending_registrations -> wp_goweb_tenants.
        // Verifikasi saat login pakai wp_check_password() lewat
        // goweb_tenant_login() di functions.php. Tenant TIDAK dibuatkan akun
        // wp_users, jadi tidak perlu plaintext di sini.
        $secure_pass = wp_hash_password( $raw_pass );

        // Tangkap pilihan paket — sekarang lookup dari kamus paket terpusat
        // (goweb_get_package_definitions() di functions.php), bukan hardcode
        // manual di sini, biar konsisten sama menu-subscription.php nanti.
        $package_choice = isset($_POST['reg_package']) ? sanitize_text_field($_POST['reg_package']) : 'starter';
        $selected_package = goweb_get_package( $package_choice );
        $package_key    = $selected_package['key'];
        $package_name   = $selected_package['name'];
        $storage_limit  = $selected_package['storage_limit'];
        
        $features    = $features; // sudah divalidasi + whitelisted di atas (#16)
        $proof_url   = '';

        // Batas ukuran bukti transfer (#34) — dulu tanpa batas sama sekali,
        // file sangat besar tetap diterima apa adanya sebelum dikompres.
        $reg_proof_max_bytes = 5 * 1048576; // 5 MB
        if ( ! empty($_FILES['reg_payment_proof']['name']) && intval($_FILES['reg_payment_proof']['size'] ?? 0) > $reg_proof_max_bytes ) {
            throw new GoWebUserFacingError( 'Ukuran bukti transfer maksimal 5 MB. Silakan kompres/perkecil gambarnya lalu kirim ulang.' );
        }

        // Proses upload dan KOMPRESI GAMBAR mandiri
        if ( ! empty($_FILES['reg_payment_proof']['name']) ) {
            if ( ! function_exists('wp_handle_upload') ) {
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
            }
            $upload_overrides = array( 'test_form' => false, 'mimes' => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ) );
            $uploaded = wp_handle_upload($_FILES['reg_payment_proof'], $upload_overrides);
            
            if ( isset($uploaded['url']) && isset($uploaded['file']) ) { 
                // Kompres gambar dengan editor bawaan WP
                $image_editor = wp_get_image_editor( $uploaded['file'] );
                if ( ! is_wp_error( $image_editor ) ) {
                    $image_editor->resize( 800, 1200, false ); // Maksimal lebar 800px
                    $image_editor->set_quality( 70 ); // Turunkan kualitas ke 70%
                    $image_editor->save( $uploaded['file'] ); // Timpa file asli
                }
                $proof_url = esc_url($uploaded['url']); 
            }
        }

        global $wpdb;
        $goweb_table_names = goweb_get_table_names();
        $pending_table = $goweb_table_names['pending'];
        $tenants_table = $goweb_table_names['tenants'];

        // VALIDASI DUPLIKAT — sebelumnya gak ada cek sama sekali, jadi email
        // atau slug yang sama bisa didaftarkan berkali-kali, dan pas login
        // / lookup slug hasilnya jadi tidak deterministik (baris pertama
        // yang ketemu).
        $dup_tenant_email = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tenants_table} WHERE LOWER(email) = LOWER(%s)", $admin_email
        ) );
        $dup_pending_email = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pending_table} WHERE LOWER(email) = LOWER(%s)", $admin_email
        ) );
        if ( intval( $dup_tenant_email ) > 0 ) {
            throw new GoWebUserFacingError( 'Email ini sudah terdaftar sebagai tenant aktif. Gunakan email lain atau login langsung.' );
        }
        if ( intval( $dup_pending_email ) > 0 ) {
            throw new GoWebUserFacingError( 'Email ini sudah ada di antrean pendaftaran dan sedang menunggu verifikasi. Mohon tunggu proses selesai.' );
        }

        $dup_tenant_slug = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tenants_table} WHERE slug = %s", $store_slug
        ) );
        $dup_pending_slug = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pending_table} WHERE slug = %s", $store_slug
        ) );
        if ( intval( $dup_tenant_slug ) > 0 || intval( $dup_pending_slug ) > 0 ) {
            throw new GoWebUserFacingError( 'URL web "' . $store_slug . '" sudah dipakai toko lain. Silakan pilih nama URL yang berbeda.' );
        }

        // ID request harus UNIK — dulu 'req_' . time() bisa BENTROK (PK
        // duplicate) kalau 2 orang daftar dalam detik yang sama (#17); yang
        // kedua insert gagal dan harus isi ulang form dari awal. Sekarang
        // pakai suffix acak + loop pengecekan eksistensi.
        $reg_id = 'req_' . time() . '_' . bin2hex( random_bytes( 4 ) );
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pending_table} WHERE request_id = %s", $reg_id ) ) > 0 ) {
            $reg_id = 'req_' . time() . '_' . bin2hex( random_bytes( 4 ) );
        }

        $insert_result = $wpdb->insert( $pending_table, array(
            'request_id'    => $reg_id,
            'store_name'    => $store_name,
            'admin_name'    => $admin_name,
            'email'         => $admin_email,
            'slug'          => $store_slug,
            'password'      => $secure_pass,
            'package_key'   => $package_key,
            'package_name'  => $package_name,
            'storage_limit' => $storage_limit,
            'proof_url'     => $proof_url,
            'features'      => wp_json_encode( $features ),
            'created_at'    => current_time('mysql'),
        ) );

        // Kalau insert gagal, tandai gagal (jangan tampilkan 'Berhasil')
        // tapi jangan expose detail error database mentah ke pengunjung.
        if ( $insert_result === false ) {
            $submission_error = 'Terjadi kendala teknis saat menyimpan pendaftaran. Silakan coba lagi beberapa saat, atau hubungi @first_kao di Twitter kalau masalah berlanjut.';
        } else {
            $submitted_success = true;
        }
    } catch ( \Throwable $e ) {
        // Error validasi (duplikat email/slug, dll) — pesannya memang ditulis
        // buat pendaftar, tampilkan APA ADANYA. Error teknis tak terduga —
        // catat ke error log server (buat developer) dan tampilkan pesan
        // generik, bukan detail teknis/stack trace.
        error_log( 'GO-WEB registration error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        $submission_error = ( $e instanceof GoWebUserFacingError )
            ? $e->getMessage()
            : 'Terjadi kendala teknis saat memproses pendaftaran. Silakan coba lagi beberapa saat, atau hubungi @first_kao di Twitter kalau masalah berlanjut.';
    }
}
?>
<?php ob_start(); ?>
<style>
        /* Token warna & font TIDAK didefinisikan di sini lagi — sumber
           tunggal ada di :root style.css (Plus Jakarta Sans + palet pastel),
           jadi halaman register & portal publik tampil dengan identitas sama. */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        .reg-container { --pastel-purple: #6d5a91; --pastel-purple-bg: #f0ebf7; --pastel-purple-border: #ded7e9; --pastel-mint: #6f9278; --pastel-mint-bg: #edf4ee; --bg-page: #faf9fc; --text-dark: #302b46; --text-muted: #625d70; --border-soft: #e5e0f2; --accent-gradient: linear-gradient(135deg, #6d5a91, #51416f); width: 100%; max-width: 780px; margin: 42px auto; padding: 0 20px 64px; flex: 1 0 auto; }
        .reg-intro { max-width: 640px; margin: 0 auto 30px; text-align: center; }
        .reg-intro-kicker { display: inline-flex; padding: 5px 12px; border: 1px solid #e0d7ed; border-radius: 999px; background: #f0ebf7; color: #594a75; font-size: .74rem; font-weight: 800; letter-spacing: .04em; }
        .reg-intro h1 { margin: 12px 0 7px; color: #302b46; font-size: clamp(1.55rem, 3vw, 1.9rem); font-weight: 800; line-height: 1.2; letter-spacing: -.04em; }
        .reg-intro p { color: var(--text-muted); font-size: .9rem; line-height: 1.65; }
        .reg-card { padding: 32px; border: 1px solid #e5e0f2; border-radius: 20px; background: #fff; box-shadow: 0 18px 48px rgba(59,48,95,.08); }
        .reg-login-link { margin: 18px 0 0; color: var(--text-muted); font-size: .84rem; text-align: center; }
        .reg-login-link a { color: #594a75; font-weight: 800; text-decoration: none; }
        .reg-login-link a:hover { color: #43345f; text-decoration: underline; }
        .reg-login-link a:focus-visible { outline: 3px solid #8069a3; outline-offset: 3px; border-radius: 3px; }
        
        /* Step Indicator Header */
        .step-indicators { display: flex; justify-content: space-between; margin-bottom: 32px; position: relative; }
        .step-indicators::before { content: ''; position: absolute; top: 18px; left: 10%; right: 10%; height: 2px; background: var(--border-soft); z-index: 1; }
        .step-item { position: relative; z-index: 2; text-align: center; background: #fff; padding: 0 10px; }
        .step-circle { width: 36px; height: 36px; border-radius: 50%; background: #fff; border: 2px solid var(--border-soft); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; margin: 0 auto 6px; transition: all 0.3s; }
        .step-item.active .step-circle { background: var(--pastel-purple); border-color: var(--pastel-purple); color: #fff; box-shadow: 0 4px 12px rgba(89,74,117,.2); }
        .step-item.completed .step-circle { background: var(--pastel-mint); border-color: var(--pastel-mint); color: #fff; }
        .step-label { font-size: 0.78rem; font-weight: 700; color: var(--text-muted); }
        .step-item.active .step-label { color: var(--pastel-purple); }

        .form-step-pane { display: none; }
        .form-step-pane.active { display: block; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.83rem; font-weight: 750; color: #403a53; margin-bottom: 7px; }
        .form-control { width: 100%; min-height: 46px; padding: 11px 14px; border: 1px solid #e5e0f2; border-radius: 11px; color: #302b46; font: inherit; font-size: 0.9rem; background: #fff; outline: none; transition: border-color .18s ease, box-shadow .18s ease; }
        .form-control:focus { border-color: #9c8ab8; box-shadow: 0 0 0 3px rgba(109,90,145,.12); }
        .form-control::placeholder { color: #716b80; }
        
        /* Grid Paket Langganan */
        .pkg-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 24px; }
        .pkg-card { border: 1px solid #e3ddec; border-radius: 13px; padding: 17px 14px; cursor: pointer; transition: border-color .18s ease, background .18s ease, box-shadow .18s ease, transform .18s ease; text-align: center; background: #fbfaff; }
        .pkg-card:hover { border-color: #b7a8cf; transform: translateY(-1px); }
        .pkg-card.selected { border-color: #9c8ab8; background: #f0ebf7; box-shadow: 0 5px 15px rgba(89,74,117,.11); }
        .pkg-title { font-size: 0.9rem; font-weight: 800; color: var(--text-dark); margin-bottom: 4px; }
        .pkg-price { font-size: 1.1rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 8px; }
        .pkg-storage { font-size: 0.75rem; font-weight: 700; color: #fff; background: var(--text-muted); padding: 2px 8px; border-radius: 999px; display: inline-block; }
        .pkg-card.selected .pkg-storage { background: var(--pastel-purple); }
        .pkg-card input[type="radio"] { display: none; }

        .btn-action-group { display: flex; gap: 12px; margin-top: 28px; }
        .btn-next, .btn-submit { flex: 1; min-height: 48px; padding: 12px 16px; border-radius: 12px; background: linear-gradient(135deg, #6d5a91, #51416f); color: #fff; border: none; font-weight: 800; font-size: .92rem; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: transform .18s ease, box-shadow .18s ease; box-shadow: 0 8px 20px rgba(81,65,111,.18); }
        .btn-next:hover, .btn-submit:hover { transform: translateY(-1px); color: #fff; box-shadow: 0 11px 24px rgba(81,65,111,.25); }
        .btn-next:focus-visible, .btn-prev:focus-visible, .btn-submit:focus-visible { outline: 3px solid #8069a3; outline-offset: 3px; }
        .btn-prev { min-height: 48px; padding: 11px 20px; border-radius: 12px; background: #fff; color: #594781; border: 1px solid #dcd4ed; font-weight: 750; font-size: .92rem; cursor: pointer; transition: background .18s ease, border-color .18s ease, color .18s ease; }
        .btn-prev:hover { background: #faf8ff; border-color: #c9bddb; color: #433267; }
        
        .tnc-box { background: var(--bg-page); border: 1.5px solid var(--border-soft); padding: 20px; border-radius: var(--radius-md); font-size: 0.85rem; color: var(--text-muted); line-height: 1.6; max-height: 220px; overflow-y: auto; margin-bottom: 16px; }
        .tnc-box ul { padding-left: 20px; margin-top: 6px; }

        .reg-2col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        @media (max-width: 760px) {
            .pkg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .reg-container { margin: 30px auto; padding: 0 16px 44px; }
            .reg-intro { margin-bottom: 24px; }
            .reg-card { padding: 23px 18px; border-radius: 17px; }
            .reg-2col-grid { grid-template-columns: 1fr; gap: 0; }
            .step-indicators { margin-bottom: 25px; }
            .step-item { padding: 0 5px; }
            .step-label { max-width: 98px; font-size: 0.66rem; line-height: 1.3; }
            .step-circle { width: 32px; height: 32px; font-size: 0.8rem; }
            .pkg-grid { gap: 9px; }
            .pkg-card { padding: 14px 9px; }
            .pkg-price { font-size: 1rem; }
            .tnc-box { padding: 16px; font-size: .82rem; }
        }
        @media (max-width: 380px) {
            .reg-card { padding: 20px 15px; }
            .btn-action-group { gap: 8px; }
            .btn-prev { min-height: 48px; padding: 11px 13px; }
            .btn-next, .btn-submit { padding: 11px 10px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .form-step-pane.active, .step-circle, .btn-next, .btn-prev, .btn-submit, .form-control { animation: none; transition: none; }
        }
</style>
<?php
$register_inline_styles = ob_get_clean();
add_action('wp_head', static function () use ($register_inline_styles) { echo $register_inline_styles; }, 99);
get_header();
?>

<div class="reg-container">
    <div class="reg-intro">
        <span class="reg-intro-kicker">GO-WEB PLATFORM</span>
        <h1>Pendaftaran tenant</h1>
        <p>Ikuti 3 langkah mudah untuk mendaftarkan proxy, jastip, atau WH kamu di GO-WEB.</p>
    </div>

    <?php if ($submitted_success): ?>
        <div style="background: var(--pastel-mint-bg); border: 1.5px solid #a7f3d0; color: #065f46; padding: 32px; border-radius: var(--radius-lg); text-align: center; box-shadow: 0 4px 20px rgba(16,185,129,0.05);">
            <div style="font-size: 2.5rem; color: #10b981; margin-bottom: 12px;"><i class="fa-solid fa-circle-check"></i></div>
            <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 8px;">Pendaftaran Berhasil Dikirim!</h3>
            <p style="font-size: 0.92rem; line-height: 1.6; margin-bottom: 20px;">
                Terima kasih sudah mendaftar. Data beserta bukti pembayaran kamu telah masuk antrean. Mohon tunggu maksimal <strong>1x24 jam</strong> untuk proses verifikasi dan aktivasi sistem oleh Owner.
                <br><br>
                Pastikan kamu sudah memiliki akun Discord, karena briefing akan dilakukan di sana.
            </p>
            <div style="background: #fff; border: 1px solid #a7f3d0; padding: 16px; border-radius: var(--radius-md); font-size: 0.88rem; color: var(--text-dark);">
                <i class="fa-brands fa-twitter" style="color: #1da1f2; font-size: 1.1rem; margin-right: 4px;"></i> 
                Butuh proses lebih cepat atau mengalami kendala? Silakan mention atau DM ke <strong>@first_kao</strong> di Twitter.
            </div>
            <div style="margin-top: 24px;">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-submit" style="display: inline-flex; max-width: 220px; margin: 0 auto; text-decoration: none;">Kembali ke Beranda</a>
            </div>
        </div>
    <?php else: ?>
        <?php if ( ! empty($submission_error) ): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 600; font-size: 0.9rem; text-align: center;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html($submission_error); ?>
            </div>
        <?php endif; ?>
        <div class="reg-card">
            
            <div class="step-indicators">
                <div class="step-item active" id="indicator-step-1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Syarat & Ketentuan</div>
                </div>
                <div class="step-item" id="indicator-step-2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Informasi & Fitur</div>
                </div>
                <div class="step-item" id="indicator-step-3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Bayar & Selesai</div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="multi-step-reg-form">
                <?php wp_nonce_field('goweb_register_action', 'goweb_register_nonce'); ?>
                
                <!-- STEP 1: SYARAT & KETENTUAN (TNC) -->
                <div class="form-step-pane active" id="pane-step-1">
                    <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 14px;"><i class="fa-solid fa-file-contract"></i> Langkah 1: Syarat & Ketentuan Web Sharing</h4>
                    
                    <div class="tnc-box">
                        <p>Baca dan pahami semua ketentuan berikut. Centang persetujuan di bawah untuk melanjutkan.</p>
                        <ul>
                            <li><strong>Sistem Web Sharing:</strong> GO-WEB menggunakan infrastruktur bersama. Tenant <strong>tidak dapat mengunggah logo sendiri, memakai domain khusus, atau meminta fitur di luar modul standar.</strong></li>
                            <li><strong>Perpanjangan & Risiko Data:</strong> Masa sewa berlaku bulanan. Dashboard mengingatkan saat sisa masa aktif <strong>7 hari atau kurang</strong>; tenant tetap bertanggung jawab memantau masa sewanya. Jika pembayaran belum diverifikasi dalam <strong>1 hari setelah jatuh tempo (H+1)</strong>, toko offline sampai verifikasi selesai. Jika belum diverifikasi dalam <strong>2 hari setelah jatuh tempo (H+2)</strong>, seluruh data toko akan <strong>dihapus permanen</strong>, termasuk PO, tagihan, data warehouse dan foto bukti paket, data keuangan, serta file bukti transfer. Penghapusan ini di luar tanggung jawab pemberi sewa.</li>
                            <li><strong>Verifikasi Perpanjangan Sewa Bersifat Manual:</strong> Pembayaran sewa atau upgrade <strong>tidak langsung mengaktifkan paket</strong> — akan diperiksa manual oleh Super Admin setelah bukti transfer dikirim. Masa aktif bertambah setelah pembayaran disetujui.</li>
                            <li><strong>Retensi Data Maksimal (1 Tahun):</strong> Terlepas dari status sewa aktif atau rutin diperpanjang, <strong>seluruh data akun tetap dihapus otomatis dan permanen 1 tahun setelah tanggal pendaftaran</strong>. Unduh atau cadangkan data penting sebelum masa tersebut berakhir.</li>
                            <li><strong>Kebijakan Kapasitas (Storage):</strong> Biaya sewa mengikuti paket kapasitas <em>storage</em> yang dipilih (Starter/Lite/Standard/Advanced/Pro/Ultimate). Kuota menghitung <strong>semua data toko di sistem</strong> — riwayat pembayaran, rekapan order, data keuangan, pengaturan, dan file bukti transfer — bukan hanya foto. Jika kuota penuh, tenant perlu <em>upgrade</em> paket. Bukti transfer otomatis dikompres saat diunggah.</li>
                            <li><strong>Upgrade Paket (Tidak Ada Downgrade):</strong> Tenant dapat <em>upgrade</em> ke paket berkapasitas lebih besar kapan saja melalui menu Status Langganan. <strong>Downgrade ke paket lebih kecil tidak tersedia</strong> — setelah upgrade, biaya bulanan mengikuti paket baru seterusnya.</li>
                            <li><strong>Perhitungan Masa Aktif saat Upgrade Mendadak:</strong> Jika tenant upgrade sebelum masa sewa habis, sisa masa aktif <strong>tetap dihitung, lalu ditambah 30 hari penuh dari paket baru</strong> — bukan menggantikannya.</li>
                        </ul>
                    </div>

                    <div class="form-group" style="margin-top: 16px;">
                        <label style="display: flex; align-items: center; gap: 10px; font-weight: 600; cursor: pointer; color: var(--text-dark);">
                            <input type="checkbox" id="tnc-checkbox" style="width: 18px; height: 18px; accent-color: var(--pastel-purple);">
                            <span>Saya telah membaca, memahami, dan menyetujui seluruh Syarat & Ketentuan di atas.</span>
                        </label>
                    </div>

                    <div id="tnc-error-msg" style="color: #ef4444; font-size: 0.82rem; font-weight: 700; margin-top: 8px; display: none;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Anda harus mencentang kotak persetujuan terlebih dahulu.
                    </div>

                    <div class="btn-action-group">
                        <button type="button" class="btn-next" id="btn-to-step-2">Selanjutnya <i class="fa-solid fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- STEP 2: PILIH PAKET, INFO & FITUR -->
                <div class="form-step-pane" id="pane-step-2">
                    <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 14px;"><i class="fa-solid fa-store"></i> Langkah 2: Pilih Paket & Informasi Toko</h4>
                    
                    <!-- PILIHAN PAKET STORAGE -->
                    <!-- PILIHAN PAKET STORAGE — digenerate dari kamus terpusat
                         (goweb_get_package_definitions), bukan hardcode lagi (#11) -->
                    <div class="form-group">
                        <label>PILIH PAKET LANGGANAN BULANAN</label>
                        <div class="pkg-grid">
                            <?php foreach ( goweb_get_package_definitions() as $reg_pkg ): ?>
                            <label class="pkg-card<?php echo $reg_pkg['key'] === 'starter' ? ' selected' : ''; ?>" id="card-<?php echo esc_attr($reg_pkg['key']); ?>">
                                <input type="radio" name="reg_package" value="<?php echo esc_attr($reg_pkg['key']); ?>"<?php echo $reg_pkg['key'] === 'starter' ? ' checked' : ''; ?> onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket <?php echo esc_html($reg_pkg['name']); ?></div>
                                <div class="pkg-price">Rp <?php echo number_format($reg_pkg['price'], 0, ',', '.'); ?></div>
                                <div class="pkg-storage">Kapasitas <?php echo esc_html( goweb_format_storage_size( $reg_pkg['storage_limit'] ) ); ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>NAMA PROXY / JASTIP / WH</label>
                        <input type="text" name="reg_store_name" id="reg-store-name" class="form-control" placeholder="cth: Sakura Jastip Store">
                    </div>

                    <div class="form-group">
                        <label>URL WEB</label>
                        <div style="display: flex; align-items: stretch; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: var(--bg-page); overflow: hidden;">
                            <span style="display: flex; align-items: center; padding: 0 16px; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.88rem; white-space: nowrap; background: #f3f0ff; flex-shrink: 0;">go-web.cloud/</span>
                            <input type="text" name="reg_store_slug" id="reg-store-slug" class="form-control" placeholder="cth: sakura-jastip" style="border: none; background: #fff; border-radius: 0; width: 100%;" required>
                        </div>
                        <small style="color: var(--text-muted); font-size: 0.78rem; margin-top: 4px; display: block;">*Gunakan huruf kecil, tanpa spasi.</small>
                    </div>

                    <div class="reg-2col-grid">
                        <div class="form-group">
                            <label>NAMA PEMILIK / ADMIN</label>
                            <input type="text" name="reg_admin_name" id="reg-admin-name" class="form-control" placeholder="cth: Dinda Pratiwi">
                        </div>
                        <div class="form-group">
                            <label>EMAIL KONTAK</label>
                            <input type="email" name="reg_email" id="reg-email" class="form-control" placeholder="cth: admin@store.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>PASSWORD DASHBOARD ADMIN</label>
                        <input type="password" name="reg_password" id="reg-password" class="form-control" placeholder="Buat password untuk login ke dashboard" required>
                    </div>

                    <div class="form-group" style="background: var(--bg-page); padding: 16px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft);">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 10px;"><i class="fa-solid fa-puzzle-piece" style="color:var(--pastel-purple);"></i> FITUR YANG DIAKTIFKAN</label>
                        <div class="reg-2col-grid" style="gap: 10px;">
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="calculator" checked style="accent-color: var(--pastel-purple);"> Kalkulator Harga Jual</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="public_calc" checked style="accent-color: var(--pastel-purple);"> Kalkulator Publik</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="customer_payment" checked style="accent-color: var(--pastel-purple);"> Konfirmasi Bayar</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="po_recap" checked style="accent-color: var(--pastel-purple);"> Kelola Rekapan PO</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="tagihan_recap" checked style="accent-color: var(--pastel-purple);"> Kelola Rekapan Tagihan</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="warehouse" checked style="accent-color: var(--pastel-purple);"> Database Warehouse</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="testimonials" checked style="accent-color: var(--pastel-purple);"> Kelola Testimoni</label>
                        </div>
                    </div>

                    <div id="step2-error-msg" style="color: #ef4444; font-size: 0.82rem; font-weight: 700; margin-top: 8px; display: none;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Harap isi seluruh kolom informasi dan password dengan benar.
                    </div>

                    <div class="btn-action-group">
                        <button type="button" class="btn-prev" id="btn-back-to-step-1"><i class="fa-solid fa-arrow-left"></i> Kembali</button>
                        <button type="button" class="btn-next" id="btn-to-step-3">Selanjutnya <i class="fa-solid fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- STEP 3: PEMBAYARAN & UPLOAD BUKTI -->
                <div class="form-step-pane" id="pane-step-3">
                    <h4 style="font-size: 1.05rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 14px;"><i class="fa-solid fa-receipt"></i> Langkah 3: Pembayaran Sewa Awal</h4>
                    
                    <div style="background: var(--pastel-purple-bg); border: 1.5px dashed var(--pastel-purple-border); padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 0.88rem;">
                        <strong>Informasi Transfer:</strong><br>
                        Silakan lakukan pembayaran biaya sewa awal dengan cara scan QRIS di bawah ini lalu unggah buktinya.
                        
                        <!-- DETAIL HARGA DAN BIAYA QRIS — default (Starter) dihitung
                             dari kamus paket + kamus fee terpusat, bukan hardcode -->
                        <div style="background: #fff; padding: 12px; margin-top: 10px; border-radius: 8px; border: 1px solid var(--border-soft);">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span>Harga Paket:</span>
                                <strong id="display-base-price">Rp <?php echo number_format( goweb_get_package('starter')['price'], 0, ',', '.'); ?></strong>
                            </div>
                            <?php if ( $reg_qris_ready ): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: var(--text-muted); font-size: 0.8rem;">
                                    <span id="display-fee-label">Biaya Layanan QRIS (<?php echo esc_html( $reg_qris_fee_config['low_label'] ); ?>):</span>
                                    <strong id="display-fee-price">Rp <?php echo number_format( $reg_starter_fee['fee'], 0, ',', '.'); ?></strong>
                                </div>
                                <hr style="border: 0; border-top: 1px solid var(--border-soft); margin: 8px 0;">
                            <?php endif; ?>
                            <div style="display: flex; justify-content: space-between; font-size: 1.1rem;">
                                <strong>Total Transfer:</strong>
                                <strong id="display-total-price" style="color: var(--pastel-purple);">Rp <?php echo number_format( $reg_starter_fee['total'], 0, ',', '.'); ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if ( $reg_qris_image_url !== '' ): ?>
                        <div style="text-align: center; margin-bottom: 20px; background: var(--bg-page); padding: 14px; border: 1.5px dashed var(--pastel-purple-border); border-radius: var(--radius-md);">
                            <p style="font-size: 0.78rem; font-weight: 700; color: var(--pastel-purple); margin-bottom: 8px;">SCAN QRIS DIBAWAH INI</p>
                            <img src="<?php echo esc_url( $reg_qris_image_url ); ?>" alt="QRIS Barcode" style="width: 150px; height: 150px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border-soft);">
                        </div>
                    <?php endif; ?>

                    <div class="form-group" style="margin-bottom: 24px;">
                        <label>Unggah Bukti Transfer</label>
                        <input type="file" name="reg_payment_proof" id="reg-proof" class="form-control" accept="image/*" style="background: var(--bg-page);">
                    </div>

                    <div id="step3-error-msg" style="color: #ef4444; font-size: 0.82rem; font-weight: 700; margin-top: 8px; display: none; margin-bottom: 12px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Harap unggah bukti transfer pembayaran terlebih dahulu.
                    </div>

                    <div class="btn-action-group">
                        <button type="button" class="btn-prev" id="btn-back-to-step-2"><i class="fa-solid fa-arrow-left"></i> Kembali</button>
                        <!-- HONEYPOT anti-bot (#34): disembunyikan dari manusia, -->
                        <!-- bot pengisi-semua-field akan mengisinya dan ditolak. -->
                        <div style="position: absolute; left: -9999px; top: auto; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">
                            <label for="reg-website-trap">Website</label>
                            <input type="text" name="reg_website_trap" id="reg-website-trap" tabindex="-1" autocomplete="off">
                        </div>
                        <button type="submit" name="submit_tenant_registration" class="btn-submit">
                            <i class="fa-solid fa-paper-plane"></i> Kirim Konfirmasi
                        </button>
                    </div>
                </div>

            </form>
        </div>
    <?php endif; ?>
    <p class="reg-login-link">Sudah punya akun? <a href="<?php echo esc_url(home_url('/login')); ?>">Masuk ke dashboard</a></p>

</div>

<script>
// Logic Update Harga di Step 3 Berdasarkan Paket.
// SEBELUMNYA harga paket + persentase/threshold fee QRIS hardcode duplikat di
// sini — sekarang diambil dari kamus terpusat (goweb_get_package_definitions
// & goweb_qris_fee_config di functions.php), jadi kalau harga/policy berubah
// cukup ubah satu tempat.
const priceMap = <?php echo wp_json_encode( array_combine( array_keys( goweb_get_package_definitions() ), array_column( goweb_get_package_definitions(), 'price' ) ) ); ?>;
const QRIS_FEE = <?php echo wp_json_encode( $reg_qris_fee_config ); ?>;

// Fungsi format angka ke format Rupiah
function formatRupiah(angka) {
    return 'Rp ' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function updatePackageSelection(radio) {
    document.querySelectorAll('.pkg-card').forEach(card => card.classList.remove('selected'));
    radio.closest('.pkg-card').classList.add('selected');
    
    // Ambil harga paket sebagai integer
    const basePrice = priceMap[radio.value];
    
    if (basePrice) {
        let feePercentage = 0;
        let feeLabel = "";

        // Logika Biaya Tambahan QRIS — dari kamus terpusat QRIS_FEE.
        // Kalau helper pusat belum tersedia, QRIS_FEE = null: hitung tanpa fee
        // (baris biaya juga tidak dirender), jangan tebak angka sendiri.
        if (QRIS_FEE && basePrice < QRIS_FEE.threshold) {
            feePercentage = QRIS_FEE.low_pct;
            feeLabel = QRIS_FEE.low_label;
        } else if (QRIS_FEE) {
            feePercentage = QRIS_FEE.high_pct;
            feeLabel = QRIS_FEE.high_label;
        }

        // Hitung biaya dan total
        const feeAmount = Math.ceil(basePrice * feePercentage);
        const totalPrice = basePrice + feeAmount;

        // Update nominal di langkah 3
        const displayBase = document.getElementById('display-base-price');
        const displayFeeLabel = document.getElementById('display-fee-label');
        const displayFeePrice = document.getElementById('display-fee-price');
        const displayTotal = document.getElementById('display-total-price');

        if (displayBase && displayFeeLabel && displayFeePrice && displayTotal) {
            displayBase.innerText = formatRupiah(basePrice);
            displayFeeLabel.innerText = `Biaya Layanan QRIS (${feeLabel}):`;
            displayFeePrice.innerText = formatRupiah(feeAmount);
            displayTotal.innerText = formatRupiah(totalPrice);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const pane1 = document.getElementById('pane-step-1');
    const pane2 = document.getElementById('pane-step-2');
    const pane3 = document.getElementById('pane-step-3');

    const ind1 = document.getElementById('indicator-step-1');
    const ind2 = document.getElementById('indicator-step-2');
    const ind3 = document.getElementById('indicator-step-3');

    const btnToStep2 = document.getElementById('btn-to-step-2');
    const btnBackTo1 = document.getElementById('btn-back-to-step-1');
    const btnToStep3 = document.getElementById('btn-to-step-3');
    const btnBackTo2 = document.getElementById('btn-back-to-step-2');

    const tncCheckbox = document.getElementById('tnc-checkbox');
    const tncError = document.getElementById('tnc-error-msg');
    
    const storeInput = document.getElementById('reg-store-name');
    const slugInput = document.getElementById('reg-store-slug');
    const adminInput = document.getElementById('reg-admin-name');
    const emailInput = document.getElementById('reg-email');
    const passInput = document.getElementById('reg-password');
    const step2Error = document.getElementById('step2-error-msg');

    const proofInput = document.getElementById('reg-proof');
    const step3Error = document.getElementById('step3-error-msg');

    if(storeInput && slugInput) {
        storeInput.addEventListener('input', () => {
            if(!slugInput.value || slugInput.dataset.auto === 'true') {
                slugInput.value = storeInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
                slugInput.dataset.auto = 'true';
            }
        });
        slugInput.addEventListener('input', () => {
            slugInput.dataset.auto = 'false';
        });
    }

    if(btnToStep2) {
        btnToStep2.addEventListener('click', () => {
            if(!tncCheckbox.checked) {
                tncError.style.display = 'block';
                return;
            }
            tncError.style.display = 'none';
            pane1.classList.remove('active');
            pane2.classList.add('active');
            ind1.classList.remove('active');
            ind1.classList.add('completed');
            ind2.classList.add('active');
        });
    }

    if(btnBackTo1) {
        btnBackTo1.addEventListener('click', () => {
            pane2.classList.remove('active');
            pane1.classList.add('active');
            ind2.classList.remove('active');
            ind1.classList.remove('completed');
            ind1.classList.add('active');
        });
    }

    if(btnToStep3) {
        btnToStep3.addEventListener('click', () => {
            if(!storeInput.value.trim() || !slugInput.value.trim() || !adminInput.value.trim() || !emailInput.value.trim() || !passInput.value.trim()) {
                step2Error.style.display = 'block';
                return;
            }
            step2Error.style.display = 'none';
            pane2.classList.remove('active');
            pane3.classList.add('active');
            ind2.classList.remove('active');
            ind2.classList.add('completed');
            ind3.classList.add('active');
        });
    }

    if(btnBackTo2) {
        btnBackTo2.addEventListener('click', () => {
            pane3.classList.remove('active');
            pane2.classList.add('active');
            ind3.classList.remove('active');
            ind2.classList.remove('completed');
            ind2.classList.add('active');
        });
    }

    const mainForm = document.getElementById('multi-step-reg-form');
    if(mainForm) {
        mainForm.addEventListener('submit', (e) => {
            if(!proofInput.files || proofInput.files.length === 0) {
                e.preventDefault();
                step3Error.style.display = 'block';
            } else {
                step3Error.style.display = 'none';
            }
        });
    }
});
</script>

<?php get_footer(); ?>