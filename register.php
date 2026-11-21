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

if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_tenant_registration']) ) {
    if ( ! isset($_POST['goweb_register_nonce']) || ! wp_verify_nonce($_POST['goweb_register_nonce'], 'goweb_register_action') ) {
        wp_die('Sesi form kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }
    try {
        $store_name  = sanitize_text_field($_POST['reg_store_name']);
        $admin_name  = sanitize_text_field($_POST['reg_admin_name']);
        $admin_email = sanitize_email($_POST['reg_email']);
        $store_slug  = sanitize_title($_POST['reg_store_slug']);
        $raw_pass    = $_POST['reg_password'];
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
        
        $features    = isset($_POST['reg_features']) ? array_map('sanitize_text_field', $_POST['reg_features']) : array();
        $proof_url   = '';

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

        $reg_id = 'req_' . time();

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
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Tenant Baru | GO-WEB</title>
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
        body { background: #faf8ff; color: var(--text-dark); }
        
        .site-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 36px; background: #fff; border-bottom: 1px solid var(--border-soft); box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .site-logo { font-size: 1.25rem; font-weight: 800; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .btn-home { display: inline-flex; align-items: center; gap: 8px; background: var(--pastel-purple-bg); color: var(--pastel-purple); border: 1.5px solid var(--pastel-purple-border); padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; text-decoration: none; transition: 0.2s; }
        .btn-home:hover { background: var(--pastel-purple); color: #fff; }

        .reg-container { max-width: 700px; margin: 40px auto; padding: 0 20px 80px; }
        .reg-card { background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 36px; box-shadow: 0 4px 20px rgba(139,92,246,0.04); }
        
        /* Step Indicator Header */
        .step-indicators { display: flex; justify-content: space-between; margin-bottom: 32px; position: relative; }
        .step-indicators::before { content: ''; position: absolute; top: 18px; left: 10%; right: 10%; height: 2px; background: var(--border-soft); z-index: 1; }
        .step-item { position: relative; z-index: 2; text-align: center; background: #fff; padding: 0 10px; }
        .step-circle { width: 36px; height: 36px; border-radius: 50%; background: #fff; border: 2px solid var(--border-soft); color: var(--text-muted); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; margin: 0 auto 6px; transition: all 0.3s; }
        .step-item.active .step-circle { background: var(--pastel-purple); border-color: var(--pastel-purple); color: #fff; box-shadow: 0 4px 12px rgba(139,92,246,0.3); }
        .step-item.completed .step-circle { background: var(--pastel-mint); border-color: var(--pastel-mint); color: #fff; }
        .step-label { font-size: 0.78rem; font-weight: 700; color: var(--text-muted); }
        .step-item.active .step-label { color: var(--pastel-purple); }

        .form-step-pane { display: none; }
        .form-step-pane.active { display: block; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.92rem; background: #fff; outline: none; }
        .form-control:focus { border-color: var(--pastel-purple); box-shadow: 0 0 0 3px rgba(139,92,246,0.15); }
        
        /* Grid Paket Langganan */
        .pkg-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .pkg-card { border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); padding: 16px; cursor: pointer; transition: 0.2s; text-align: center; background: #faf8ff; }
        .pkg-card:hover { border-color: var(--pastel-purple); }
        .pkg-card.selected { border-color: var(--pastel-purple); background: var(--pastel-purple-bg); box-shadow: 0 4px 12px rgba(139,92,246,0.15); }
        .pkg-title { font-size: 0.9rem; font-weight: 800; color: var(--text-dark); margin-bottom: 4px; }
        .pkg-price { font-size: 1.1rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 8px; }
        .pkg-storage { font-size: 0.75rem; font-weight: 700; color: #fff; background: var(--text-muted); padding: 2px 8px; border-radius: 999px; display: inline-block; }
        .pkg-card.selected .pkg-storage { background: var(--pastel-purple); }
        .pkg-card input[type="radio"] { display: none; }

        .btn-action-group { display: flex; gap: 12px; margin-top: 28px; }
        .btn-next, .btn-submit { flex: 1; padding: 14px; border-radius: var(--radius-md); background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; border: none; font-weight: 700; font-size: 0.95rem; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-prev { padding: 14px 24px; border-radius: var(--radius-md); background: #fff; color: var(--text-muted); border: 1.5px solid var(--border-soft); font-weight: 700; font-size: 0.95rem; cursor: pointer; }
        
        .tnc-box { background: #faf8ff; border: 1.5px solid var(--border-soft); padding: 20px; border-radius: var(--radius-md); font-size: 0.85rem; color: var(--text-muted); line-height: 1.6; max-height: 220px; overflow-y: auto; margin-bottom: 16px; }
        .tnc-box ul { padding-left: 20px; margin-top: 6px; }

        .reg-2col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        @media (max-width: 640px) {
            .site-header { padding: 14px 20px; }
            .reg-container { margin: 24px auto; padding: 0 14px 60px; }
            .reg-card { padding: 22px; }
            .reg-2col-grid { grid-template-columns: 1fr; gap: 0; }
            .pkg-grid { grid-template-columns: 1fr; }
            .step-label { font-size: 0.68rem; }
            .step-circle { width: 30px; height: 30px; font-size: 0.8rem; }
        }
    </style>
</head>
<body <?php body_class(); ?>>

<header class="site-header">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="site-logo">
        GO- <span style="color: var(--pastel-purple);">WEB</span> <span style="font-size: 0.65rem; background: var(--pastel-purple-bg); color: var(--pastel-purple); padding: 2px 6px; border-radius: 6px; border: 1px solid var(--pastel-purple-border);">CORE</span>
    </a>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-home">
        <i class="fa-solid fa-house"></i> Home
    </a>
</header>

<div class="reg-container">
    <div style="text-align: center; margin-bottom: 32px;">
        <span style="background: var(--pastel-purple-bg); color: var(--pastel-purple); border: 1px solid var(--pastel-purple-border); padding: 4px 14px; border-radius: 999px; font-size: 0.78rem; font-weight: 700;">GO-WEB PLATFORM</span>
        <h1 style="font-size: 1.8rem; font-weight: 800; margin-top: 10px; color: var(--text-dark);">Pendaftaran Tenant & Sewa Web</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">Ikuti 3 langkah mudah untuk mendaftarkan proxy, jastip, atau WH kamu di GO-WEB.</p>
    </div>

    <?php if ($submitted_success): ?>
        <div style="background: #ecfdf5; border: 1.5px solid #a7f3d0; color: #065f46; padding: 32px; border-radius: var(--radius-lg); text-align: center; box-shadow: 0 4px 20px rgba(16,185,129,0.05);">
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
                        <p>Harap baca dan pahami ketentuan layanan platform GO-WEB sebelum melanjutkan:</p>
                        <ul>
                            <li><strong>Sistem Web Sharing:</strong> Platform ini menggunakan infrastruktur bersama. Tenant <strong>tidak dapat mengunggah logo sendiri</strong>, <strong>tidak memiliki custom domain</strong>, dan <strong>tidak dapat merequest custom fitur</strong> di luar modul standar.</li>
                            <li><strong>Perpanjangan & Risiko Data:</strong> Masa sewa bersifat bulanan. Tenant akan melihat <strong>peringatan otomatis di dashboard</strong> ketika masa aktif tersisa 7 hari atau kurang — sebagai kemudahan, bukan kewajiban platform, sehingga tenant tetap bertanggung jawab memantau masa aktifnya sendiri. Apabila melewati tanggal jatuh tempo (H) dan <strong>tidak diperpanjang dalam 1 (satu) hari</strong> (H+1), toko akan <strong>otomatis dinonaktifkan (offline)</strong> — halaman publik dan dashboard tidak bisa diakses sampai perpanjangan diverifikasi. Apabila dalam <strong>2 (dua) hari</strong> sejak jatuh tempo (H+2) tenant <strong>tetap tidak melakukan perpanjangan</strong> (pembayaran terverifikasi), maka seluruh data toko — termasuk rekapan PO, rekapan tagihan, data warehouse (termasuk foto bukti paket), data keuangan, dan file bukti transfer — <strong>akan terhapus otomatis dan hilang permanen</strong> di luar tanggung jawab pemberi sewa.</li>
                            <li><strong>Verifikasi Perpanjangan Sewa Bersifat Manual:</strong> Konfirmasi pembayaran sewa/upgrade paket <strong>tidak langsung aktif otomatis</strong> — perlu diverifikasi manual oleh Super Admin platform setelah bukti transfer dikirim. Masa aktif baru diperpanjang setelah verifikasi disetujui.</li>
                            <li><strong>Retensi Data Maksimal (1 Tahun):</strong> Terlepas dari status sewa aktif atau rutin diperpanjang, <strong>seluruh data akun akan tetap terhapus otomatis dan permanen setelah genap 1 (satu) tahun</strong> sejak tanggal pendaftaran. Mohon unduh/backup data penting secara berkala sebelum periode ini berakhir.</li>
                            <li><strong>Kebijakan Kapasitas (Storage):</strong> Biaya sewa platform bervariasi mengikuti paket kapasitas <em>storage</em> (Starter/Lite/Standard/Advanced/Pro/Ultimate) yang Anda pilih. Kapasitas yang dihitung mencakup <strong>seluruh data toko Anda di sistem kami</strong> — riwayat pembayaran, rekapan order, data keuangan, pengaturan, dan file bukti transfer — bukan cuma foto yang diunggah. Kapasitas yang penuh mengharuskan Anda melakukan <em>upgrade</em> paket. Seluruh gambar bukti transfer otomatis dikompres saat diunggah untuk efisiensi ruang.</li>
                            <li><strong>Upgrade Paket (Tidak Ada Downgrade):</strong> Anda dapat <em>upgrade</em> ke paket dengan kapasitas lebih besar kapan saja lewat menu Status Langganan. <strong>Downgrade ke paket lebih kecil tidak didukung</strong> — sekali naik paket, biaya sewa bulanan mengikuti paket baru tersebut seterusnya.</li>
                            <li><strong>Perhitungan Masa Aktif saat Upgrade Mendadak:</strong> Kalau Anda upgrade paket di tengah masa sewa yang masih berjalan (bukan pas mau habis), sisa hari yang masih ada <strong>akan tetap dihitung dan ditambah 30 hari penuh dari paket baru</strong> — bukan menggantikan sisa masa aktif yang sudah ada.</li>
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
                    <div class="form-group">
                        <label>PILIH PAKET LANGGANAN BULANAN</label>
                        <div class="pkg-grid">
                            <label class="pkg-card selected" id="card-starter">
                                <input type="radio" name="reg_package" value="starter" checked onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket Starter</div>
                                <div class="pkg-price">Rp 79.000</div>
                                <div class="pkg-storage">Kapasitas 250 MB</div>
                            </label>
                            <label class="pkg-card" id="card-lite">
                                <input type="radio" name="reg_package" value="lite" onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket Lite</div>
                                <div class="pkg-price">Rp 99.000</div>
                                <div class="pkg-storage">Kapasitas 500 MB</div>
                            </label>
                            <label class="pkg-card" id="card-standard">
                                <input type="radio" name="reg_package" value="standard" onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket Standard</div>
                                <div class="pkg-price">Rp 149.000</div>
                                <div class="pkg-storage">Kapasitas 1 GB</div>
                            </label>
                            <label class="pkg-card" id="card-advanced">
                                <input type="radio" name="reg_package" value="advanced" onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket Advanced</div>
                                <div class="pkg-price">Rp 199.000</div>
                                <div class="pkg-storage">Kapasitas 2 GB</div>
                            </label>
                            <label class="pkg-card" id="card-pro">
                                <input type="radio" name="reg_package" value="pro" onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket Pro</div>
                                <div class="pkg-price">Rp 299.000</div>
                                <div class="pkg-storage">Kapasitas 5 GB</div>
                            </label>
                            <label class="pkg-card" id="card-ultimate">
                                <input type="radio" name="reg_package" value="ultimate" onchange="updatePackageSelection(this)">
                                <div class="pkg-title">Paket Ultimate</div>
                                <div class="pkg-price">Rp 449.000</div>
                                <div class="pkg-storage">Kapasitas 10 GB</div>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>NAMA PROXY / JASTIP / WH</label>
                        <input type="text" name="reg_store_name" id="reg-store-name" class="form-control" placeholder="cth: Sakura Jastip Store">
                    </div>

                    <div class="form-group">
                        <label>URL WEB</label>
                        <div style="display: flex; align-items: stretch; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #faf8ff; overflow: hidden;">
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
                            <label>EMAIL KONTAK / AKUN</label>
                            <input type="email" name="reg_email" id="reg-email" class="form-control" placeholder="cth: admin@store.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>PASSWORD DASHBOARD ADMIN</label>
                        <input type="password" name="reg_password" id="reg-password" class="form-control" placeholder="Buat password untuk login ke dashboard" required>
                    </div>

                    <div class="form-group" style="background: #faf8ff; padding: 16px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft);">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 10px;"><i class="fa-solid fa-puzzle-piece" style="color:var(--pastel-purple);"></i> FITUR YANG DIAKTIFKAN</label>
                        <div class="reg-2col-grid" style="gap: 10px;">
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="calculator" checked style="accent-color: var(--pastel-purple);"> Kalkulator Harga Jual</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="public_calc" checked style="accent-color: var(--pastel-purple);"> Kalkulator Publik</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="customer_payment" checked style="accent-color: var(--pastel-purple);"> Konfirmasi Bayar</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="po_recap" checked style="accent-color: var(--pastel-purple);"> Kelola Rekapan PO</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="tagihan_recap" checked style="accent-color: var(--pastel-purple);"> Kelola Rekapan Tagihan</label>
                            <label style="font-size: 0.82rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="reg_features[]" value="warehouse" checked style="accent-color: var(--pastel-purple);"> Database Warehouse</label>
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
                        Silakan lakukan pembayaran biaya sewa awal ke rekening pusat atau scan QRIS di bawah ini, lalu unggah buktinya.
                        
                        <!-- DETAIL HARGA DAN BIAYA QRIS -->
                        <div style="background: #fff; padding: 12px; margin-top: 10px; border-radius: 8px; border: 1px solid var(--border-soft);">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span>Harga Paket:</span>
                                <strong id="display-base-price">Rp 79.000</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: var(--text-muted); font-size: 0.8rem;">
                                <span id="display-fee-label">Biaya Layanan QRIS (0.3%):</span>
                                <strong id="display-fee-price">Rp 237</strong>
                            </div>
                            <hr style="border: 0; border-top: 1px solid var(--border-soft); margin: 8px 0;">
                            <div style="display: flex; justify-content: space-between; font-size: 1.1rem;">
                                <strong>Total Transfer:</strong>
                                <strong id="display-total-price" style="color: var(--pastel-purple);">Rp 79.237</strong>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: center; margin-bottom: 20px; background: #faf8ff; padding: 14px; border: 1.5px dashed var(--pastel-purple-border); border-radius: var(--radius-md);">
                        <p style="font-size: 0.78rem; font-weight: 700; color: var(--pastel-purple); margin-bottom: 8px;">SCAN QRIS DIBAWAH INI</p>
                        <img src="https://mercatoria.id/wp-content/uploads/2026/02/qris-barcode.jpg" alt="QRIS Barcode" style="width: 150px; height: 150px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border-soft);">
                    </div>

                    <div class="form-group" style="margin-bottom: 24px;">
                        <label>Unggah Bukti Transfer (Otomatis Terkompresi)</label>
                        <input type="file" name="reg_payment_proof" id="reg-proof" class="form-control" accept="image/*" style="background: #faf8ff;">
                    </div>

                    <div id="step3-error-msg" style="color: #ef4444; font-size: 0.82rem; font-weight: 700; margin-top: 8px; display: none; margin-bottom: 12px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Harap unggah bukti transfer pembayaran terlebih dahulu.
                    </div>

                    <div class="btn-action-group">
                        <button type="button" class="btn-prev" id="btn-back-to-step-2"><i class="fa-solid fa-arrow-left"></i> Kembali</button>
                        <button type="submit" name="submit_tenant_registration" class="btn-submit">
                            <i class="fa-solid fa-paper-plane"></i> Kirim Konfirmasi
                        </button>
                    </div>
                </div>

            </form>
        </div>
    <?php endif; ?>

</div>

<script>
// Logic Update Harga di Step 3 Berdasarkan Paket (Diubah jadi angka / integer)
const priceMap = {
    'starter': 79000,
    'lite': 99000,
    'standard': 149000,
    'advanced': 199000,
    'pro': 299000,
    'ultimate': 449000
};

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

        // Logika Biaya Tambahan QRIS
        if (basePrice < 500000) {
            feePercentage = 0.003; // 0,3%
            feeLabel = "0,3%";
        } else {
            feePercentage = 0.007; // 0,7%
            feeLabel = "0,7%";
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

<?php wp_footer(); ?>
</body>
</html>