<?php
/*
Template Name: Tenant Login Page
*/

// WAJIB: jangan di-cache. Halaman ini punya nonce + captcha matematika yang
// unik per-sesi; kalau ke-cache, semua pengunjung dapat nonce/captcha yang
// sama & basi, akibatnya submit login akan selalu gagal ("sesi kedaluwarsa").
if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true);
nocache_headers();
if ( function_exists('do_action') ) {
    do_action('litespeed_control_set_nocache', 'goweb login page - dynamic nonce & captcha');
}
header('X-LiteSpeed-Cache-Control: no-cache');

$login_error = '';

// Inisialisasi session aman untuk captcha matematika
if (!session_id()) {
    session_start();
}

if (!isset($_SESSION['math_num1']) || !isset($_SESSION['math_num2'])) {
    $_SESSION['math_num1'] = rand(1, 10);
    $_SESSION['math_num2'] = rand(1, 10);
}

$num1 = $_SESSION['math_num1'];
$num2 = $_SESSION['math_num2'];

// Proteksi brute-force: kunci sementara per KOMBINASI IP + EMAIL (pola standar
// keamanan web). Dulu kuncinya cuma per-email — padahal email tenant itu
// publik (kelihatan di portal), jadi siapa pun bisa ngetik password salah 5x
// dan MENJAIL akun tenant 15 menit (DoS akun, pemilik gak bisa login walau
// password-nya benar). Dengan kombinasi IP+email: yang ngespam cuma mengunci
// dirinya sendiri, sedangkan pemilik akun yang login dari perangkat/IP dia
// sendiri tetap bisa masuk. Tenant satu kantor dengan IP sama tapi email
// berbeda tetap punya kunci masing-masing.
// Pakai transient WP — otomatis expire sendiri, gak perlu tabel/cron
// pembersih tambahan.
define( 'GOWEB_LOGIN_MAX_ATTEMPTS', 5 );
define( 'GOWEB_LOGIN_LOCKOUT_MINUTES', 15 );
$attempted_email = isset($_POST['log']) ? sanitize_text_field($_POST['log']) : '';
$lockout_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
$lockout_key = 'goweb_login_lock_' . md5( strtolower( $attempted_email ) . '|' . $lockout_ip );
$is_locked_out = false;
if ( $attempted_email !== '' ) {
    $attempt_count = (int) get_transient( $lockout_key );
    if ( $attempt_count >= GOWEB_LOGIN_MAX_ATTEMPTS ) {
        $is_locked_out = true;
        $login_error = 'Terlalu banyak percobaan login gagal dari koneksi ini untuk akun ini. Coba lagi dalam ' . GOWEB_LOGIN_LOCKOUT_MINUTES . ' menit.';
    }
}

// Proses ketika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tenant_login_nonce']) && ! $is_locked_out) {

    // 0. Validasi nonce yang sesungguhnya (sebelumnya hanya dicek keberadaannya saja)
    if ( ! wp_verify_nonce( $_POST['tenant_login_nonce'], 'tenant_login_action' ) ) {
        $login_error = 'Sesi form kedaluwarsa. Silakan muat ulang halaman dan coba lagi.';
    }
    // 1. Cek Honeypot (Anti-Bot)
    elseif (!empty($_POST['website_url_trap'])) {
        $login_error = 'Spam terdeteksi!';
    } 
    // 2. Cek Validasi Matematika Captcha
    elseif (!isset($_POST['math_answer']) || intval($_POST['math_answer']) !== ($num1 + $num2)) {
        $login_error = 'Jawaban verifikasi matematika salah. Silakan coba lagi.';
        $_SESSION['math_num1'] = rand(1, 10);
        $_SESSION['math_num2'] = rand(1, 10);
    } 
    else {
        // 3. Proses Autentikasi Tenant Custom (BUKAN wp_signon — tenant tidak
        // punya akun di tabel wp_users, dicek langsung ke tabel wp_goweb_tenants)
        $login_result = goweb_tenant_login(
            sanitize_text_field($_POST['log']), // dipakai sbg email tenant
            $_POST['pwd']
        );

        if ( is_wp_error( $login_result ) ) {
            $login_error = $login_result->get_error_message();
            // Gagal — tambah hitungan percobaan (buat lockout brute-force)
            if ( $attempted_email !== '' ) {
                $current_attempts = (int) get_transient( $lockout_key );
                set_transient( $lockout_key, $current_attempts + 1, GOWEB_LOGIN_LOCKOUT_MINUTES * MINUTE_IN_SECONDS );
            }
        } else {
            // Sukses — reset hitungan percobaan buat akun ini
            if ( $attempted_email !== '' ) {
                delete_transient( $lockout_key );
            }
            // Arahkan sukses login langsung ke halaman dashboard tenant kita (/dashboard)
            wp_redirect(home_url('/dashboard'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Tenant | GO-WEB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php wp_head(); ?>
    <style>
        :root {
            --pastel-purple: #8b5cf6;
            --pastel-purple-bg: #f5f3ff;
            --pastel-purple-border: #ddd6fe;
            --pastel-peach-bg: #fff7ed;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --border-soft: #e5e7eb;
            --radius-md: 12px;
            --radius-lg: 16px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #faf8ff; color: var(--text-dark); }
        
        /* Header Sederhana */
        .site-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 36px; background: #fff; border-bottom: 1px solid var(--border-soft); }
        .site-logo { font-size: 1.25rem; font-weight: 800; color: var(--text-dark); text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .btn-home { display: inline-flex; align-items: center; gap: 8px; background: var(--pastel-purple-bg); color: var(--pastel-purple); border: 1.5px solid var(--pastel-purple-border); padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; text-decoration: none; transition: 0.2s; }
        .btn-home:hover { background: var(--pastel-purple); color: #fff; }

        .login-container { max-width: 440px; margin: 60px auto; padding: 0 20px 80px; }
        .login-card { background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 36px; box-shadow: 0 4px 20px rgba(139,92,246,0.04); }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.92rem; background: #fff; outline: none; }
        .form-control:focus { border-color: var(--pastel-purple); box-shadow: 0 0 0 3px rgba(139,92,246,0.15); }
        
        .btn-submit { width: 100%; padding: 14px; border-radius: var(--radius-md); background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; border: none; font-weight: 700; font-size: 0.95rem; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
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

<main class="login-container">
    <div class="login-card">
        
        <div style="text-align: center; margin-bottom: 28px;">
            <span style="background: var(--pastel-purple-bg); color: var(--pastel-purple); border: 1px solid var(--pastel-purple-border); padding: 4px 14px; border-radius: 999px; font-size: 0.78rem; font-weight: 700;"><i class="fa-solid fa-lock"></i> Area Tenant</span>
            <h2 style="font-size: 1.4rem; font-weight: 800; margin-top: 10px; color: var(--text-dark);">Login Admin GO</h2>
            <p style="color: var(--text-muted); font-size: 0.88rem; margin-top: 4px;">Masuk ke dashboard toko GO web sharing kamu.</p>
        </div>

        <?php if (!empty($login_error)): ?>
            <div style="background: var(--pastel-peach-bg); border: 1.5px solid #fed7aa; color: #ea580c; padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; font-weight: 700; margin-bottom: 20px; text-align: center;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html($login_error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php wp_nonce_field('tenant_login_action', 'tenant_login_nonce'); ?>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="log" class="form-control" required placeholder="Masukkan email toko kamu">
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="pwd" class="form-control" required placeholder="••••••••">
            </div>

            <!-- HONEYPOT TRAP -->
            <div style="display:none !important;" aria-hidden="true">
                <label>Jangan isi kolom ini jika Anda manusia:</label>
                <input type="text" name="website_url_trap" value="" tabindex="-1" autocomplete="off">
            </div>

            <!-- MATH CAPTCHA -->
            <div class="form-group" style="background: var(--pastel-purple-bg); border: 1.5px dashed var(--pastel-purple-border); padding: 16px; border-radius: var(--radius-md); margin-bottom: 24px;">
                <label style="margin-bottom: 6px; display: block; color: var(--pastel-purple); font-size: 0.8rem;">Verifikasi Keamanan (Anti-Bot)</label>
                <span style="font-size: 0.88rem; font-weight: 700; color: var(--text-dark);">Berapa hasil dari: <?php echo $num1; ?> + <?php echo $num2; ?> ?</span>
                <input type="number" name="math_answer" class="form-control" required placeholder="Masukkan jawaban" style="margin-top: 8px; background: #fff;">
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-right-to-bracket"></i> Masuk ke Dashboard
            </button>
        </form>

    </div>
</main>

<?php wp_footer(); ?>
</body>
</html>