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
            // Captcha WAJIB diganti setelah percobaan gagal (#31). Sebelumnya
            // angka soal hanya di-refresh kalau JAWABANNYA salah — jadi kalau
            // password-nya yang salah, soal yang sama terus dipakai ulang dan
            // bot cukup menghitung sekali untuk mencoba password tak terbatas
            // (captcha jadi formalitas).
            $_SESSION['math_num1'] = rand(1, 10);
            $_SESSION['math_num2'] = rand(1, 10);
            $num1 = $_SESSION['math_num1'];
            $num2 = $_SESSION['math_num2'];
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
<?php ob_start(); ?>
<style>
        /* Token warna & font TIDAK didefinisikan di sini lagi — sumber
           tunggal ada di :root style.css (Plus Jakarta Sans + palet pastel),
           jadi halaman login & portal publik tampil dengan identitas sama. */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        .login-container { width: 100%; max-width: 480px; margin: auto; padding: 52px 20px 64px; }
        .login-card { padding: 36px; border: 1px solid #e5dfef; border-radius: 20px; background: #fff; box-shadow: 0 18px 48px rgba(59,48,95,.09); }
        .login-heading { margin-bottom: 28px; text-align: center; }
        .login-kicker { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border: 1px solid #e0d7ed; border-radius: 999px; background: #f0ebf7; color: #594a75; font-size: .75rem; font-weight: 800; }
        .login-heading h1 { margin: 12px 0 5px; color: #302b46; font-size: 1.55rem; font-weight: 800; letter-spacing: -.035em; }
        .login-heading p { color: #777188; font-size: .88rem; line-height: 1.6; }
        .login-error { margin-bottom: 20px; padding: 12px 15px; border: 1px solid #efd6d1; border-radius: 12px; background: #fbf1ef; color: #8b4f46; font-size: .84rem; font-weight: 700; text-align: center; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 7px; color: #403a53; font-size: .84rem; font-weight: 750; }
        .form-control { width: 100%; min-height: 48px; padding: 12px 14px; border: 1px solid #ded8e8; border-radius: 11px; background: #fff; color: #302b46; font: inherit; font-size: .9rem; outline: none; transition: border-color .18s ease, box-shadow .18s ease; }
        .form-control::placeholder { color: #a09aac; }
        .form-control:focus { border-color: #8a76ae; box-shadow: 0 0 0 3px rgba(109,90,145,.13); }
        .login-captcha { margin-bottom: 22px; padding: 16px; border: 1px dashed #cfc3df; border-radius: 13px; background: #f5f2f8; }
        .login-captcha label { margin-bottom: 7px; color: #594a75; font-size: .78rem; }
        .login-captcha-question { color: #403a53; font-size: .88rem; font-weight: 750; }
        .login-captcha .form-control { margin-top: 9px; }
        .btn-submit { width: 100%; min-height: 48px; padding: 12px 16px; border: 0; border-radius: 11px; background: linear-gradient(135deg, #6d28d9, #4338ca); color: #fff; font: inherit; font-size: .9rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 9px; text-decoration: none; transition: transform .18s ease, box-shadow .18s ease; box-shadow: 0 8px 20px rgba(79,70,149,.18); }
        .btn-submit:hover { transform: translateY(-1px); color: #fff; box-shadow: 0 11px 24px rgba(79,70,149,.25); }
        .login-register-link { margin: 20px 0 0; color: #777188; font-size: .84rem; text-align: center; }
        .login-register-link a { color: #594a75; font-weight: 800; text-decoration: none; }
        .login-register-link a:hover { color: #43345f; text-decoration: underline; }
        @media (max-width: 640px) {
            .login-container { max-width: 460px; padding: 32px 16px 44px; }
            .login-card { padding: 26px 22px; border-radius: 17px; }
        }
</style>
<?php
$login_inline_styles = ob_get_clean();
add_action('wp_head', static function () use ($login_inline_styles) { echo $login_inline_styles; }, 99);
get_header();
?>

<main class="login-container">
    <div class="login-card">
        
        <div class="login-heading">
            <span class="login-kicker"><i class="fa-solid fa-lock"></i> Area Tenant</span>
            <h1>Masuk ke akun</h1>
            <p>Masuk ke dashboard toko GO-WEB kamu.</p>
        </div>

        <?php if (!empty($login_error)): ?>
            <div class="login-error">
                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc_html($login_error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php wp_nonce_field('tenant_login_action', 'tenant_login_nonce'); ?>

            <div class="form-group">
                <label for="login-email">Email</label>
                <input type="email" id="login-email" name="log" class="form-control" required autocomplete="username" placeholder="Masukkan email toko kamu">
            </div>

            <div class="form-group">
                <label for="login-password">Password</label>
                <input type="password" id="login-password" name="pwd" class="form-control" required autocomplete="current-password" placeholder="Masukkan password">
            </div>

            <!-- HONEYPOT TRAP -->
            <div style="display:none !important;" aria-hidden="true">
                <label>Jangan isi kolom ini jika Anda manusia:</label>
                <input type="text" name="website_url_trap" value="" tabindex="-1" autocomplete="off">
            </div>

            <!-- MATH CAPTCHA -->
            <div class="form-group login-captcha">
                <label for="login-math-answer">Verifikasi keamanan</label>
                <div class="login-captcha-question">Berapa hasil dari <?php echo $num1; ?> + <?php echo $num2; ?>?</div>
                <input type="number" id="login-math-answer" name="math_answer" class="form-control" required inputmode="numeric" placeholder="Masukkan jawaban">
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-right-to-bracket"></i> Masuk ke Dashboard
            </button>
        </form>
        <p class="login-register-link">Belum punya toko? <a href="<?php echo esc_url(home_url('/register')); ?>">Daftar sekarang</a></p>

    </div>
</main>

<?php get_footer(); ?>