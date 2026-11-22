<?php
/*
Template Name: Super Admin Control Panel
*/

// WAJIB: jangan di-cache. Panel ini paling sensitif di seluruh tema — kalau
// ke-cache, siapapun bisa lihat data tenant lengkap tanpa login sama sekali.
if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true);
nocache_headers();
if ( function_exists('do_action') ) {
    do_action('litespeed_control_set_nocache', 'goweb super admin panel - most sensitive page');
}
header('X-LiteSpeed-Cache-Control: no-cache');

$current_user = wp_get_current_user();
$master_email = 'firstkao4@gmail.com'; 

if ( !current_user_can('administrator') && $current_user->user_email !== $master_email ) {
    wp_die('Akses ditolak! Halaman ini khusus untuk Super Admin GO-WEB.');
}

global $wpdb;
$goweb_tables  = goweb_get_table_names();
$tenants_table = $goweb_tables['tenants'];
$pending_table = $goweb_tables['pending'];

// Daftar fitur yang dikenal sistem (whitelist edit tenant #19). Sama dengan
// yang dirender di checkbox modal edit & yang dikirim form registrasi.
$goweb_known_features = array('calculator', 'public_calc', 'customer_payment', 'po_recap', 'tagihan_recap', 'warehouse');

$pending_requests = $wpdb->get_results( "SELECT * FROM {$pending_table} ORDER BY created_at DESC", ARRAY_A );

// Handler Aksi Super Admin
if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {

    // Verifikasi nonce untuk SEMUA aksi POST di halaman ini (proteksi CSRF)
    if ( ! isset($_POST['goweb_super_admin_nonce']) || ! wp_verify_nonce($_POST['goweb_super_admin_nonce'], 'goweb_super_admin_action') ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }

    // 1. Setujui Pendaftaran Tenant Baru dari Form Multi-Step
    if ( isset($_POST['approve_registration']) ) {
        $req_id = sanitize_text_field($_POST['request_id']);
        $req = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pending_table} WHERE request_id = %s", $req_id ), ARRAY_A );
        if ( $req ) {

            // CEK ULANG DUPLIKAT saat approve — antrean bisa nunggu berhari-hari,
            // dan di selang itu tenant lain bisa saja sudah merebut email/slug
            // yang sama. Tanpa cek ini, bisa tercipta 2 tenant dengan slug/email
            // identik (lookup jadi tidak deterministik).
            $dup_email_at_approve = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tenants_table} WHERE LOWER(email) = LOWER(%s)", $req['email']
            ) );
            $dup_slug_at_approve = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tenants_table} WHERE slug = %s", $req['slug']
            ) );
            if ( intval( $dup_email_at_approve ) > 0 || intval( $dup_slug_at_approve ) > 0 ) {
                echo '<div class="alert-box center alert-error">Gagal approve: email atau slug "' . esc_html( $req['slug'] ) . '" sudah dipakai tenant aktif lain. Tolak pendaftaran ini dan minta pendaftar mendaftar ulang dengan data yang berbeda.</div>';
            } else {

            // Tenant TIDAK dibuatkan akun WordPress (wp_users). Password sudah
            // di-hash di register.php (wp_hash_password) dan tinggal disalin ke
            // record tenant. Login diverifikasi lewat goweb_tenant_login() di
            // functions.php (wp_check_password), bukan wp_signon().
            // DEFINISIKAN paket dulu SEBELUM insert — sebelumnya variabel ini
            // dipakai di kolom cycle_daily_rate padahal baru didefinisikan
            // SETELAH insert tenants, akibatnya cycle_daily_rate selalu 0
            // buat setiap tenant baru.
            $approved_package = goweb_get_package( ! empty($req['package_key']) ? $req['package_key'] : 'starter' );
            // ID tenant harus UNIK — dulu 'tenant_' . time() bisa BENTROK (PK
            // duplicate) kalau 2 pendaftaran di-approve dalam detik yang sama,
            // dan hasilnya insert gagal diam-diam. Sekarang pakai suffix acak
            // + loop pengecekan eksistensi.
            $new_tenant_id = 'tenant_' . time() . '_' . bin2hex( random_bytes( 4 ) );
            while ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tenants_table} WHERE tenant_id = %s", $new_tenant_id ) ) > 0 ) {
                $new_tenant_id = 'tenant_' . time() . '_' . bin2hex( random_bytes( 4 ) );
            }
            $req_features = ! empty( $req['features'] ) ? json_decode( $req['features'], true ) : array('calculator', 'public_calc', 'customer_payment', 'po_recap', 'tagihan_recap', 'warehouse');

            // CEK HASIL INSERT — dulu kegagalan insert (mis. PK bentrok,
            // koneksi putus) diabaikan dan tetap nampil "berhasil disetujui"
            // padahal tenant gak jadi dibuat (#14).
            $inserted_tenant = $wpdb->insert( $tenants_table, array(
                'tenant_id'    => $new_tenant_id,
                'store_name'   => $req['store_name'],
                'admin_name'   => $req['admin_name'],
                'email'        => $req['email'],
                'slug'         => $req['slug'],
                'password'     => $req['password'], // sudah ter-hash sejak registrasi
                'package_key'  => ! empty($req['package_key']) ? $req['package_key'] : 'starter',
                'duration'     => 30,
                'active_until' => time() + (30 * DAY_IN_SECONDS), // masa aktif sewa awal, diperpanjang otomatis saat perpanjangan di-verify (lihat admin.php)
                'cycle_daily_rate' => round( $approved_package['price'] / 30, 2 ), // rate harian awal - dipakai buat proration kalau nanti upgrade
                'storage_limit'=> ! empty($req['storage_limit']) ? intval($req['storage_limit']) : 250, // sesuai paket yg dipilih saat registrasi — sebelumnya selalu di-hardcode 250MB apapun paketnya
                'storage_used' => 0,   // Kapasitas terpakai awal 0 Byte
                'features'     => wp_json_encode( $req_features ),
                'status'       => 'Active',
                'created_at'   => current_time('mysql'),
            ) );

            if ( $inserted_tenant === false ) {
                echo '<div class="alert-box center alert-error">GAGAL menyimpan tenant baru ke database. Pendaftaran MASIH di antrean — periksa error log lalu coba approve ulang.</div>';
            } else {

            // Simpan riwayat pembayaran awal sewa
            $payments_table = $goweb_tables['payments'];
            $wpdb->insert( $payments_table, array(
                'store_id'         => $new_tenant_id,
                'payment_category' => 'subscription',
                'period'           => 'Sewa Awal 30 Hari (Paket ' . $approved_package['name'] . ')',
                'payment_type'     => null,
                'nominal'          => $approved_package['price'],
                'sender'           => $req['admin_name'] . ' (' . $req['email'] . ')',
                'proof_url'        => $req['proof_url'],
                'status'           => 'Verified',
                'requested_package'=> null, // Bukan "ganti paket" — ini pembelian pertama, gak ada paket sebelumnya buat dibandingin
                'created_at'       => current_time('mysql'),
            ) );

            // Hapus dari antrean pending
            $wpdb->delete( $pending_table, array( 'request_id' => $req_id ) );

            echo '<div class="alert-box center alert-success">Tenant ' . esc_html($req['store_name']) . ' berhasil disetujui dan diaktifkan!</div>';
            } // akhir if insert tenant berhasil
            } // akhir else cek duplikat
        }
    }

    // 2. Tolak / Hapus Pendaftaran Pending
    if ( isset($_POST['reject_registration']) ) {
        $req_id = sanitize_text_field($_POST['request_id']);
        $req_reject = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pending_table} WHERE request_id = %s", $req_id ), ARRAY_A );
        // Bersihkan file bukti transfer dari disk — dulu dibiarkan jadi file
        // yatim selamanya walau pendaftarannya ditolak (#14).
        if ( $req_reject && ! empty( $req_reject['proof_url'] ) ) {
            goweb_delete_uploaded_file_by_url( $req_reject['proof_url'] );
        }
        $deleted = $wpdb->delete( $pending_table, array( 'request_id' => $req_id ) );
        if ( $deleted ) {
            echo '<div class="alert-box center alert-error">Pendaftaran tenant ditolak dan dihapus.</div>';
        }
    }

    // 3. Update / Edit Tenant Eksisting
    if ( isset($_POST['update_tenant']) ) {
        $t_id = sanitize_text_field($_POST['edit_tenant_id']);
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tenants_table} WHERE tenant_id = %s", $t_id ) );
        if ( $exists ) {
            $edit_email = sanitize_email($_POST['edit_email']);
            $edit_status = sanitize_text_field($_POST['edit_status']);
            $edit_features = isset($_POST['edit_features']) && is_array($_POST['edit_features'])
                ? array_values( array_intersect( array_map('sanitize_text_field', $_POST['edit_features']), $goweb_known_features ) )
                : array();

            // Email harus unik di antara tenant LAIN — dulu gak dicek, jadi 2
            // tenant bisa punya email sama. Login lookup pakai
            // "WHERE LOWER(email)=..." dan ambil baris PERTAMA, jadi hasilnya
            // bisa nyasar ke akun yang salah (#19).
            $dup_email_on_edit = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tenants_table} WHERE LOWER(email) = LOWER(%s) AND tenant_id != %s", $edit_email, $t_id
            ) );
            if ( intval( $dup_email_on_edit ) > 0 ) {
                wp_die('Gagal menyimpan: email "' . esc_html( $edit_email ) . '" sudah dipakai tenant lain. Login tenant dicari lewat email, jadi email tidak boleh duplikat.');
            }
            // Status harus dari daftar yang dikenal — dulu free-text dari POST,
            // cukup bikin status "ngawur" yang gak dikenali cek
            // "status === 'Active'" di mana-mana (toko bricked).
            if ( ! in_array( $edit_status, array('Active', 'Offline'), true ) ) {
                wp_die('Status toko tidak valid. Gunakan Active atau Offline.');
            }

            $wpdb->update( $tenants_table, array(
                'store_name' => sanitize_text_field($_POST['edit_store_name']),
                'admin_name' => sanitize_text_field($_POST['edit_admin_name']),
                'email'      => $edit_email,
                'status'     => $edit_status,
                'features'   => wp_json_encode( $edit_features ),
            ), array( 'tenant_id' => $t_id ) );
            echo '<div class="alert-box center alert-success">Data tenant berhasil diperbarui!</div>';
        }
    }

    // 2B. Hapus Tenant Manual beserta SELURUH datanya (tombol trash di daftar
    // tenant). SEBELUMNYA tombol ini gak punya handler sama sekali — submit
    // cuma reload halaman tanpa efek, padahal konfirmasi JS-nya muncul.
    if ( isset($_POST['delete_tenant']) ) {
        $t_id = sanitize_text_field($_POST['delete_tenant_id']);
        $tenant_to_delete = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tenants_table} WHERE tenant_id = %s", $t_id
        ), ARRAY_A );
        if ( $tenant_to_delete ) {
            // Catat dulu ke log penghapusan (format sama kayak cron retensi
            // otomatis di functions.php), baru hapus datanya.
            $manual_deleted_log = get_option( 'goweb_deleted_tenants_log', array() );
            $manual_deleted_log[] = array(
                'tenant_id'  => $t_id,
                'store_name' => isset($tenant_to_delete['store_name']) ? $tenant_to_delete['store_name'] : '',
                'email'      => isset($tenant_to_delete['email']) ? $tenant_to_delete['email'] : '',
                'reason'     => 'Dihapus manual oleh Super Admin',
                'deleted_at' => date( 'Y-m-d H:i:s' ),
            );
            if ( count( $manual_deleted_log ) > 500 ) {
                $manual_deleted_log = array_slice( $manual_deleted_log, -500 );
            }
            update_option( 'goweb_deleted_tenants_log', $manual_deleted_log );

            goweb_delete_tenant_completely( $t_id );
            echo '<div class="alert-box center alert-success">Tenant ' . esc_html( $tenant_to_delete['store_name'] ) . ' beserta seluruh datanya berhasil dihapus permanen.</div>';
        } else {
            echo '<div class="alert-box center alert-error">Tenant tidak ditemukan — mungkin sudah terhapus sebelumnya.</div>';
        }
    }

    // 4. Reset Password Tenant — tenant pakai auth custom sendiri (BUKAN
    // wp_users), jadi gak ada mekanisme "lupa password" bawaan WordPress.
    // Ini reset manual dibantu Super Admin: generate password baru,
    // simpan hash-nya, dan tampilkan plain text-nya SEKALI SAJA di sini
    // buat direlai ke tenant (gak pernah disimpan/ditampilkan lagi setelah ini).
    if ( isset($_POST['reset_tenant_password']) ) {
        $t_id = sanitize_text_field($_POST['reset_tenant_id']);
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tenants_table} WHERE tenant_id = %s", $t_id ) );
        if ( $exists ) {
            $new_plain_password = wp_generate_password( 10, false );
            $wpdb->update( $tenants_table,
                array(
                    'password'            => wp_hash_password( $new_plain_password ),
                    // Invalidate sesi login yang lagi aktif juga — kalau cuma
                    // ganti password doang, orang yang KEBETULAN lagi login
                    // (cookie sesinya masih valid) tetep bisa akses terus,
                    // soalnya validasi sesi itu cek session_token_hash, BUKAN
                    // password. Reset password harusnya beneran ngusir akses
                    // lama, apalagi kalau alasannya emang dugaan akses gak sah.
                    'session_token_hash'  => null,
                    'session_expires'     => null,
                ),
                array( 'tenant_id' => $t_id )
            );
            echo '<div style="background:#faf8ff; border: 1.5px solid #c4b5fd; color:#5b21b6; padding:16px 24px; text-align:center; font-weight:700; margin-bottom:20px; border-radius:10px;">
                🔑 Password baru buat tenant ini: <span style="font-family:monospace; font-size:1.1rem; background:#fff; padding:4px 10px; border-radius:6px; margin:0 4px;">' . esc_html($new_plain_password) . '</span>
                <br><span style="font-weight:400; font-size:0.8rem;">Salin sekarang &amp; kirim ke tenant secara pribadi — password ini TIDAK akan ditampilkan lagi setelah halaman ini ditutup/dimuat ulang. Sesi login yang lagi aktif juga otomatis keusir, tenant perlu login ulang pakai password baru.</span>
            </div>';
        }
    }

    // 5. Verifikasi / Tolak Perpanjangan Sewa Tenant (lintas semua tenant)
    // Ini SATU-SATUNYA tempat pembayaran kategori 'subscription' boleh
    // diverifikasi — tenant TIDAK BOLEH approve pembayaran sewanya sendiri
    // (itu duitnya masuk ke platform, bukan ke tenant). Verifikasi otomatis
    // memperpanjang active_until tenant terkait 30 hari.
    if ( isset($_POST['verify_subscription_payment']) || isset($_POST['reject_subscription_payment']) ) {
        $payments_table = $goweb_tables['payments'];
        $payment_id = intval($_POST['payment_id']);
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$payments_table} WHERE id = %d AND payment_category = 'subscription'", $payment_id
        ), ARRAY_A );

        if ( $row ) {
            if ( isset($_POST['verify_subscription_payment']) ) {
                $tenant_row_renew = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$tenants_table} WHERE tenant_id = %s", $row['store_id']
                ), ARRAY_A );

                if ( ! $tenant_row_renew ) {
                    echo '<div class="alert-box center alert-error">Tenant terkait sudah tidak ada di sistem.</div>';
                } else {
                    // Downgrade gak didukung sama sekali (keputusan produk) — UI
                    // tenant udah gak nampilin opsi downgrade, tapi ini proteksi
                    // server-side jaga-jaga kalau ada request manual di luar UI.
                    $current_tenant_package = goweb_get_package( isset($tenant_row_renew['package_key']) ? $tenant_row_renew['package_key'] : 'starter' );
                    $blocked_downgrade = false;
                    if ( ! empty( $row['requested_package'] ) ) {
                        $target_package = goweb_get_package( $row['requested_package'] );
                        if ( $target_package['storage_limit'] < $current_tenant_package['storage_limit'] ) {
                            $blocked_downgrade = true;
                        }
                    }

                    if ( $blocked_downgrade ) {
                        echo '<div class="alert-box center alert-error">Gagal diverifikasi: downgrade paket tidak didukung.</div>';
                    } else {
                        $wpdb->update( $payments_table, array( 'status' => 'Verified' ), array( 'id' => $payment_id ) );

                        $current_active_until = goweb_get_tenant_active_until( $tenant_row_renew );
                        $is_upgrade = ! empty( $row['requested_package'] );
                        $update_data = array( 'status' => 'Active' );

                        if ( $is_upgrade ) {
                            // UPGRADE: active_until GAK berubah (tenant udah bayar
                            // buat "nyampein" ke paket baru buat sisa hari yang
                            // ada, bukan beli hari tambahan) — cuma paket & rate
                            // harian yang di-update.
                            $update_data['package_key']      = $target_package['key'];
                            $update_data['storage_limit']    = $target_package['storage_limit'];
                            $update_data['cycle_daily_rate']  = round( $target_package['price'] / 30, 2 );
                            $success_msg = 'Upgrade paket berhasil diverifikasi — paket diperbarui ke ' . esc_html($target_package['name']) . ', masa aktif TETAP (gak berubah, cuma bayar selisih).';
                        } else {
                            // PERPANJANGAN: extend sesuai DURASI yang dipilih tenant
                            // (1/3/6/12 bulan) — bukan hardcode 30 hari lagi. Rate
                            // harian di-update jadi rata-rata tertimbang dari sisa
                            // nilai lama + pembayaran baru, biar upgrade selanjutnya
                            // (kalau ada) tetap dihitung adil.
                            $duration_months = ! empty( $row['requested_duration_months'] ) ? intval( $row['requested_duration_months'] ) : 1;
                            $duration_days = $duration_months * 30;
                            $base = max( time(), $current_active_until );
                            $update_data['active_until'] = $base + ( $duration_days * DAY_IN_SECONDS );

                            $remaining_days_before = max( 0, ceil( ( $current_active_until - time() ) / DAY_IN_SECONDS ) );
                            $old_rate = isset($tenant_row_renew['cycle_daily_rate']) && floatval($tenant_row_renew['cycle_daily_rate']) > 0
                                ? floatval($tenant_row_renew['cycle_daily_rate'])
                                : ( $current_tenant_package['price'] / 30 );
                            $value_remaining_before = $old_rate * $remaining_days_before;
                            $total_days_after = $remaining_days_before + $duration_days;
                            $update_data['cycle_daily_rate'] = $total_days_after > 0
                                ? round( ( $value_remaining_before + floatval($row['nominal']) ) / $total_days_after, 2 )
                                : round( floatval($row['nominal']) / max(1, $duration_days), 2 );

                            $success_msg = 'Perpanjangan sewa berhasil diverifikasi, masa aktif tenant diperpanjang ' . $duration_months . ' bulan.';
                        }

                        $wpdb->update( $tenants_table, $update_data, array( 'tenant_id' => $row['store_id'] ) );
                        echo '<div class="alert-box center alert-success">' . $success_msg . '</div>';
                    }
                }
            } else {
                $wpdb->delete( $payments_table, array( 'id' => $payment_id ) );
                echo '<div class="alert-box center alert-error">Pengajuan perpanjangan sewa ditolak dan dihapus.</div>';
            }
        }
    }
}

$all_tenants = $wpdb->get_results( "SELECT * FROM {$tenants_table} ORDER BY created_at DESC", ARRAY_A );
$pending_requests = $wpdb->get_results( "SELECT * FROM {$pending_table} ORDER BY created_at DESC", ARRAY_A );

// Ambil semua pengajuan perpanjangan sewa yang masih pending, LINTAS SEMUA
// TENANT — di-join manual ke nama toko biar gampang dibaca di tabel.
$pending_subs_payments_table = $goweb_tables['payments'];
$pending_subscriptions = $wpdb->get_results(
    "SELECT p.*, t.store_name FROM {$pending_subs_payments_table} p
     LEFT JOIN {$tenants_table} t ON p.store_id = t.tenant_id
     WHERE p.payment_category = 'subscription' AND p.status = 'Pending'
     ORDER BY p.created_at ASC", ARRAY_A
);

get_header(); 
?>

<main style="padding: 40px 32px 80px; width: 100%;">
    <div style="margin-bottom: 32px;">
        <span class="hero-tag"><i class="fa-solid fa-shield-halved"></i> Pusat Kontrol Utama</span>
        <h1>Super Admin Dashboard</h1>
        <p>Kelola pendaftaran tenant baru dari form registrasi, verifikasi pembayaran, edit toko, dan atur fitur GO-WEB.</p>
    </div>

    <!-- RINGKASAN CEPAT — biar gak perlu scroll ke tiap section satu-satu
         cuma buat tau ada berapa yang perlu ditindaklanjuti. -->
    <div style="display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 28px;">
        <a href="#section-pending-registrations" style="text-decoration: none; flex: 1; min-width: 220px; background: #fff; border: 1.5px solid <?php echo count($pending_requests) > 0 ? '#fde68a' : 'var(--border-soft, #e2e8f0)'; ?>; border-radius: 16px; padding: 18px 22px; display: flex; align-items: center; gap: 14px;">
            <div style="width: 42px; height: 42px; border-radius: 12px; background: <?php echo count($pending_requests) > 0 ? '#fef3c7' : '#f1f5f9'; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">🕐</div>
            <div>
                <div style="font-size: 1.4rem; font-weight: 800; color: <?php echo count($pending_requests) > 0 ? '#b45309' : 'var(--text-dark, #0f172a)'; ?>;"><?php echo count($pending_requests); ?></div>
                <div style="font-size: 12.5px; color: var(--text-muted, #64748b); font-weight: 600;">Pendaftaran Baru Nunggu</div>
            </div>
        </a>
        <a href="#section-pending-subscriptions" style="text-decoration: none; flex: 1; min-width: 220px; background: #fff; border: 1.5px solid <?php echo count($pending_subscriptions) > 0 ? '#fde68a' : 'var(--border-soft, #e2e8f0)'; ?>; border-radius: 16px; padding: 18px 22px; display: flex; align-items: center; gap: 14px;">
            <div style="width: 42px; height: 42px; border-radius: 12px; background: <?php echo count($pending_subscriptions) > 0 ? '#fef3c7' : '#f1f5f9'; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">💳</div>
            <div>
                <div style="font-size: 1.4rem; font-weight: 800; color: <?php echo count($pending_subscriptions) > 0 ? '#b45309' : 'var(--text-dark, #0f172a)'; ?>;"><?php echo count($pending_subscriptions); ?></div>
                <div style="font-size: 12.5px; color: var(--text-muted, #64748b); font-weight: 600;">Perpanjangan Sewa Nunggu Verifikasi</div>
            </div>
        </a>
    </div>

    <?php
    // KESEHATAN DATABASE — ukuran ASLI dari MySQL (bukan estimasi), buat
    // dibandingin sama batas hosting shared (biasanya 3GB per database).
    // Ubah GOWEB_DB_SIZE_LIMIT_MB kalau nanti pindah paket hosting yang
    // batasnya beda.
    define( 'GOWEB_DB_SIZE_LIMIT_MB', 3072 ); // 3 GB — batas umum shared hosting per database
    $db_size_bytes = $wpdb->get_var(
        "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
    $db_size_mb = round( floatval($db_size_bytes) / 1048576, 1 );
    $db_size_pct = min( 100, round( ($db_size_mb / GOWEB_DB_SIZE_LIMIT_MB) * 100, 1 ) );
    if ( $db_size_pct >= 85 ) { $db_health_color = '#ef4444'; $db_health_label = 'Mendekati Batas — Pertimbangkan Migrasi VPS'; }
    elseif ( $db_size_pct >= 60 ) { $db_health_color = '#d97706'; $db_health_label = 'Mulai Perlu Dipantau'; }
    else { $db_health_color = '#15803d'; $db_health_label = 'Aman'; }
    ?>
    <div class="tab-panel active" style="margin-bottom: 32px;">
        <h3 class="panel-title"><i class="fa-solid fa-database" style="color:var(--pastel-purple);"></i> Kesehatan Database (Hosting)</h3>
        <p class="panel-desc">Ukuran aktual database MySQL dibanding batas hosting shared kamu — dipakai buat nentuin kapan waktunya migrasi ke VPS.</p>
        <div style="background: #fff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 16px; padding: 20px 24px; margin-top: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <span style="font-size: 1.5rem; font-weight: 800; color: var(--text-dark, #0f172a);"><?php echo number_format($db_size_mb, 1); ?> MB</span>
                    <span style="color: var(--text-muted, #64748b); font-size: 0.9rem;"> / <?php echo number_format(GOWEB_DB_SIZE_LIMIT_MB, 0); ?> MB (<?php echo GOWEB_DB_SIZE_LIMIT_MB / 1024; ?> GB)</span>
                </div>
                <span style="padding: 4px 12px; border-radius: 999px; background: <?php echo esc_attr($db_health_color); ?>1a; color: <?php echo esc_attr($db_health_color); ?>; font-weight: 800; font-size: 0.8rem;"><?php echo $db_size_pct; ?>% — <?php echo esc_html($db_health_label); ?></span>
            </div>
            <div style="background: var(--border-soft, #e2e8f0); border-radius: 999px; height: 10px; width: 100%; overflow: hidden;">
                <div style="background: <?php echo esc_attr($db_health_color); ?>; height: 100%; width: <?php echo $db_size_pct; ?>%; transition: width 0.3s;"></div>
            </div>
            <p style="font-size: 0.78rem; color: var(--text-muted, #64748b); margin-top: 10px;">Dihitung langsung dari MySQL (<code>information_schema</code>), bukan estimasi — jadi angkanya akurat 100% sesuai kondisi database kamu saat ini.</p>
        </div>
    </div>

    <!-- SECTION 1: PERMINTAAN PENDAFTARAN TENANT BARU (PENDING REGISTRATIONS) -->
    <div class="tab-panel active" id="section-pending-registrations" style="margin-bottom: 32px;">
        <h3 class="panel-title"><i class="fa-solid fa-user-clock" style="color:var(--pastel-purple);"></i> Antrean Pendaftaran Tenant Baru</h3>
        <p class="panel-desc">Daftar proxy/jastip/WH yang mendaftar melalui halaman registrasi publik dan menunggu persetujuan.</p>
        
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Waktu Daftar</th>
                        <th>Nama Proxy / Jastip</th>
                        <th>URL Subfolder</th>
                        <th>Admin & Email</th>
                        <th>Bukti Transfer</th>
                        <th>Aksi Persetujuan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( !empty($pending_requests) && is_array($pending_requests) ): ?>
                        <?php foreach ( $pending_requests as $req ): $req_id = $req['request_id']; ?>
                            <tr>
                                <td><?php echo esc_html( date('d/m/Y H:i', strtotime($req['created_at'])) ); ?></td>
                                <td><strong><?php echo esc_html($req['store_name']); ?></strong></td>
                                <td><code>/<?php echo esc_html($req['slug']); ?></code></td>
                                <td><?php echo esc_html($req['admin_name']); ?><br><small style="color:var(--text-muted);"><?php echo esc_html($req['email']); ?></small></td>
                                <td>
                                    <?php if (!empty($req['proof_url'])): ?>
                                        <a href="<?php echo esc_url($req['proof_url']); ?>" target="_blank" style="color: var(--pastel-purple); font-weight: 700; text-decoration: underline;"><i class="fa-solid fa-image"></i> Lihat Bukti</a>
                                    <?php else: ?>
                                        <span>-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px;">
                                        <form method="POST">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="request_id" value="<?php echo esc_attr($req_id); ?>">
                                            <button type="submit" name="approve_registration" style="padding: 6px 14px; font-size: 0.75rem; background:#10b981; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:700;">
                                                <i class="fa-solid fa-check"></i> Setujui
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Tolak pendaftaran ini?');">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="request_id" value="<?php echo esc_attr($req_id); ?>">
                                            <button type="submit" name="reject_registration" style="padding: 6px 10px; font-size: 0.75rem; background:#fee2e2; color:#ef4444; border:none; border-radius:6px; cursor:pointer;">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">Tidak ada antrean pendaftaran tenant baru.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SECTION 1B: VERIFIKASI PERPANJANGAN SEWA (LINTAS SEMUA TENANT) -->
    <div class="tab-panel active" id="section-pending-subscriptions" style="margin-bottom: 32px;">
        <h3 class="panel-title"><i class="fa-solid fa-file-invoice-dollar" style="color:var(--pastel-purple);"></i> Verifikasi Perpanjangan Sewa Tenant</h3>
        <p class="panel-desc">Pembayaran sewa platform bulanan dari SEMUA tenant — cuma Super Admin yang boleh verifikasi ini (bukan tenant itu sendiri), karena ini masuk ke rekening platform.</p>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Waktu Kirim</th>
                        <th>Nama Toko</th>
                        <th>Paket Diajukan</th>
                        <th>Nominal</th>
                        <th>Pengirim</th>
                        <th>Bukti</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( !empty($pending_subscriptions) ): ?>
                        <?php foreach ( $pending_subscriptions as $sub ): ?>
                            <tr>
                                <td><?php echo esc_html( date('d/m/Y H:i', strtotime($sub['created_at'])) ); ?></td>
                                <td><strong><?php echo esc_html( $sub['store_name'] ?: '(tenant tidak ditemukan)' ); ?></strong></td>
                                <td>
                                    <?php if ( ! empty($sub['requested_package']) ): $req_pkg = goweb_get_package($sub['requested_package']); ?>
                                        <span class="status-badge badge-purple"><i class="fa-solid fa-arrow-up"></i> Upgrade ke <?php echo esc_html($req_pkg['name']); ?></span>
                                    <?php elseif ( ! empty($sub['requested_duration_months']) ): ?>
                                        <span class="status-badge badge-mint"><i class="fa-solid fa-rotate"></i> Perpanjang <?php echo esc_html($sub['requested_duration_months']); ?> Bulan</span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:0.8rem;">Tetap paket saat ini</span>
                                    <?php endif; ?>
                                </td>
                                <td>Rp <?php echo number_format( intval($sub['nominal']), 0, ',', '.' ); ?></td>
                                <td><?php echo esc_html($sub['sender']); ?></td>
                                <td>
                                    <?php if (!empty($sub['proof_url'])): ?>
                                        <a href="<?php echo esc_url($sub['proof_url']); ?>" target="_blank" style="color: var(--pastel-purple); font-weight: 700; text-decoration: underline;">Lihat</a>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display:flex; gap:6px; justify-content:center;">
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Verifikasi perpanjangan/upgrade ini? Masa aktif tenant akan diperpanjang sesuai durasi yang diajukan (atau paket diganti tanpa ubah masa aktif kalau ini upgrade).');" style="display:inline;">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo esc_attr($sub['id']); ?>">
                                            <button type="submit" name="verify_subscription_payment" style="padding: 6px 14px; font-size: 0.75rem; background:#10b981; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:700;">Verifikasi</button>
                                        </form>
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Tolak & hapus pengajuan ini?');" style="display:inline;">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo esc_attr($sub['id']); ?>">
                                            <button type="submit" name="reject_subscription_payment" style="padding: 6px 10px; font-size: 0.75rem; background:#fee2e2; color:#ef4444; border:none; border-radius:6px; cursor:pointer;">✕</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">Tidak ada pengajuan perpanjangan sewa yang pending.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SECTION 2: KELOLA TENANT AKTIF DATABASE -->
    <div class="tab-panel active" style="margin-bottom: 32px;">
        <h3 class="panel-title"><i class="fa-solid fa-store" style="color:var(--pastel-purple);"></i> Daftar Seluruh Tenant Aktif GO-WEB</h3>
        <p class="panel-desc">Kelola data toko, status aktif/offline, serta atur modul fitur yang diizinkan untuk tiap tenant.</p>
        
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Toko / Proxy</th>
                        <th>URL Path</th>
                        <th>Admin / Pemilik</th>
                        <th>Email Login</th>
                        <th>Tanggal Daftar</th>
                        <th>Status Akun</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( !empty($all_tenants) && is_array($all_tenants) ): ?>
                        <?php foreach ( $all_tenants as $tenant ): $t_id = $tenant['tenant_id']; $t_features = ! empty($tenant['features']) ? json_decode($tenant['features'], true) : array(); ?>
                            <tr>
                                <td><strong><?php echo esc_html($tenant['store_name']); ?></strong></td>
                                <td><code>/<?php echo esc_html(!empty($tenant['slug']) ? $tenant['slug'] : sanitize_title($tenant['store_name'])); ?></code></td>
                                <td><?php echo esc_html($tenant['admin_name']); ?></td>
                                <td><?php echo esc_html($tenant['email']); ?></td>
                                <td><?php echo esc_html( date('d/m/Y', strtotime($tenant['created_at'])) ); ?></td>
                                <td>
                                    <?php if($tenant['status'] == 'Active'): ?>
                                        <span class="status-badge badge-mint">Active</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-peach">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <button type="button" class="btn-edit-trigger" 
                                            data-id="<?php echo esc_attr($t_id); ?>" 
                                            data-store="<?php echo esc_attr($tenant['store_name']); ?>" 
                                            data-admin="<?php echo esc_attr($tenant['admin_name']); ?>" 
                                            data-email="<?php echo esc_attr($tenant['email']); ?>" 
                                            data-status="<?php echo esc_attr($tenant['status']); ?>" 
                                            data-features="<?php echo esc_attr(json_encode($t_features)); ?>"
                                            style="padding: 6px 12px; font-size: 0.75rem; background:#6366f1; color:#fff; border:none; border-radius:6px; cursor:pointer;">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </button>
                                        
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Yakin ingin menghapus tenant ini beserta seluruh datanya?');" style="display:inline;">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="delete_tenant_id" value="<?php echo esc_attr($t_id); ?>">
                                            <button type="submit" name="delete_tenant" style="padding: 6px 10px; font-size: 0.75rem; background:#fee2e2; color:#ef4444; border:none; border-radius:6px; cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 20px;">Belum ada data tenant aktif di database.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SECTION 3: LOG PENGHAPUSAN TENANT OTOMATIS (RETENSI DATA) -->
    <?php $goweb_deletion_log = get_option('goweb_deleted_tenants_log', array()); ?>
    <div class="tab-panel active">
        <h3 class="panel-title"><i class="fa-solid fa-trash-clock" style="color:var(--pastel-purple);"></i> Log Penghapusan Data Otomatis</h3>
        <p class="panel-desc">Tenant dihapus otomatis (data + file bukti transfer) kalau lewat jatuh tempo sewa + masa tenggang <?php echo GOWEB_LAPSE_GRACE_DAYS; ?> hari tanpa perpanjangan, ATAU sudah genap <?php echo GOWEB_MAX_RETENTION_DAYS; ?> hari (retensi maksimal) sejak didaftarkan — mana yang lebih dulu tercapai. Dicek otomatis 1x/hari.</p>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Waktu Dihapus</th>
                        <th>Nama Toko</th>
                        <th>Email</th>
                        <th>Alasan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( !empty($goweb_deletion_log) && is_array($goweb_deletion_log) ): ?>
                        <?php foreach ( array_reverse($goweb_deletion_log) as $log_row ): ?>
                            <tr>
                                <td><?php echo esc_html($log_row['deleted_at']); ?></td>
                                <td><strong><?php echo esc_html($log_row['store_name']); ?></strong></td>
                                <td><?php echo esc_html($log_row['email']); ?></td>
                                <td><?php echo esc_html($log_row['reason']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 20px;">Belum ada tenant yang kehapus otomatis.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- MODAL POP-UP EDIT TENANT -->
<div id="edit-tenant-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: #fff; width: 100%; max-width: 500px; padding: 32px; border-radius: var(--radius-lg); box-shadow: 0 10px 25px rgba(0,0,0,0.1); position: relative; max-height: 90vh; overflow-y: auto;">
        <button type="button" id="close-edit-modal" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted);"><i class="fa-solid fa-xmark"></i></button>
        
        <h3 style="font-size: 1.2rem; margin-bottom: 6px;"><i class="fa-solid fa-pen-to-square" style="color: var(--pastel-purple);"></i> Edit Data Tenant & Fitur</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 20px;">Perbarui informasi toko, status online/offline, dan modul fitur yang diizinkan.</p>
        
        <form method="POST">
            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
            <input type="hidden" name="edit_tenant_id" id="modal-edit-id">
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Nama Proxy / Jastip / WH</label>
                <input type="text" name="edit_store_name" id="modal-edit-store" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Nama Pemilik / Admin</label>
                <input type="text" name="edit_admin_name" id="modal-edit-admin" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Email Akses</label>
                <input type="email" name="edit_email" id="modal-edit-email" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Status Toko</label>
                <select name="edit_status" id="modal-edit-status" class="form-control" style="background:#fff;">
                    <option value="Active">Active (Online & Normal)</option>
                    <option value="Offline">Offline (Terkunci)</option>
                </select>
            </div>

            <!-- CHECKBOX FITUR DI MODAL EDIT -->
            <div class="form-group" style="margin-bottom: 24px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 8px;">Akses Fitur Tenant:</label>
                <div style="display: flex; flex-direction: column; gap: 8px; background: #faf8ff; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border-soft);">
                    <label style="font-size: 0.85rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="edit_features[]" value="calculator" id="feat-calc"> Kalkulator Harga Jual (Dashboard)</label>
                    <label style="font-size: 0.85rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="edit_features[]" value="public_calc" id="feat-pubcalc"> Kalkulator Estimasi (Halaman Depan)</label>
                    <label style="font-size: 0.85rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="edit_features[]" value="customer_payment" id="feat-payment"> Konfirmasi Bayar Pelanggan</label>
                    <label style="font-size: 0.85rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="edit_features[]" value="po_recap" id="feat-po"> Kelola Rekapan PO</label>
                    <label style="font-size: 0.85rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="edit_features[]" value="tagihan_recap" id="feat-tagihan"> Kelola Rekapan Tagihan</label>
                    <label style="font-size: 0.85rem; font-weight: 600; cursor: pointer;"><input type="checkbox" name="edit_features[]" value="warehouse" id="feat-wh"> Database Warehouse</label>
                </div>
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="submit" name="update_tenant" class="btn-submit" style="flex: 1; justify-content: center;"><i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan</button>
                <button type="button" id="cancel-edit-modal" style="padding: 10px 20px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; cursor: pointer;">Batal</button>
            </div>
        </form>

        <!-- RESET PASSWORD — form TERPISAH dari "update_tenant" di atas
             (aksi beda, dan HTML gak boleh nested <form>). Pakai modal
             konfirmasi generic yang udah ada di file ini, bukan confirm()
             bawaan browser. -->
        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Generate password baru buat tenant ini? Password lama langsung gak berlaku begitu tombol ini ditekan.');" style="margin-top: 4px; padding-top: 16px; border-top: 1px dashed var(--border-soft);">
            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
            <input type="hidden" name="reset_tenant_id" id="modal-reset-password-id">
            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 8px;">Lupa Password Tenant?</label>
            <button type="submit" name="reset_tenant_password" style="width: 100%; padding: 10px; border-radius: var(--radius-md); border: 1.5px solid #fca5a5; background: #fef2f2; color: #b91c1c; font-weight: 700; cursor: pointer;"><i class="fa-solid fa-key"></i> Generate Password Baru</button>
            <small style="color: var(--text-muted); font-size: 11px; display: block; margin-top: 6px;">Password lama langsung gak berlaku. Password baru cuma ditampilkan sekali — salin & kirim ke tenant secara pribadi.</small>
        </form>
    </div>
</div>

<!-- MODAL KONFIRMASI GENERIC (gantiin confirm() bawaan browser) -->
<div class="modal-overlay" id="genericConfirmModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 10000; padding: 20px;">
    <div style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;"><i class="fa-solid fa-circle-question"></i></div>
        <p id="genericConfirmMessage" style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 24px;"></p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="genericConfirmCancel" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; font-weight: 700; color: #64748b; cursor: pointer;">Batal</button>
            <button type="button" id="genericConfirmOk" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<script>
let goweb_pendingConfirmForm = null;

// Dipanggil dari onsubmit form yang butuh konfirmasi. Cegah submit native
// (event.preventDefault()), simpan form-nya, tampilin modal. Submit
// beneran cuma jalan kalau user klik "Ya, Lanjutkan".
function goweb_confirmFormSubmit(event, form, message) {
    event.preventDefault();
    goweb_pendingConfirmForm = form;
    document.getElementById('genericConfirmMessage').textContent = message;
    document.getElementById('genericConfirmModal').style.display = 'flex';
    return false;
}
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Wiring modal konfirmasi generic
    const genericModal = document.getElementById('genericConfirmModal');
    const genericCancel = document.getElementById('genericConfirmCancel');
    const genericOk = document.getElementById('genericConfirmOk');

    if (genericCancel) genericCancel.addEventListener('click', () => {
        genericModal.style.display = 'none';
        goweb_pendingConfirmForm = null;
    });
    if (genericModal) genericModal.addEventListener('click', (e) => {
        if (e.target === genericModal) { genericModal.style.display = 'none'; goweb_pendingConfirmForm = null; }
    });
    if (genericOk) genericOk.addEventListener('click', () => {
        if (goweb_pendingConfirmForm) {
            // Tiru name/value tombol submit form ini (form.submit() programatik
            // gak nyertain name/value tombol yg "diklik" secara native)
            const btn = goweb_pendingConfirmForm.querySelector('button[type="submit"]');
            if (btn && btn.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = btn.name;
                hidden.value = btn.value;
                goweb_pendingConfirmForm.appendChild(hidden);
            }
            goweb_pendingConfirmForm.submit();
        }
    });

    const editModal = document.getElementById('edit-tenant-modal');
    const closeEdit = document.getElementById('close-edit-modal');
    const cancelEdit = document.getElementById('cancel-edit-modal');
    
    const inputId = document.getElementById('modal-edit-id');
    const inputStore = document.getElementById('modal-edit-store');
    const inputAdmin = document.getElementById('modal-edit-admin');
    const inputEmail = document.getElementById('modal-edit-email');
    const inputStatus = document.getElementById('modal-edit-status');
    
    const featCalc = document.getElementById('feat-calc');
    const featPubCalc = document.getElementById('feat-pubcalc');
    const featPayment = document.getElementById('feat-payment');
    const featPo = document.getElementById('feat-po');
    const featTagihan = document.getElementById('feat-tagihan');
    const featWh = document.getElementById('feat-wh');

    document.querySelectorAll('.btn-edit-trigger').forEach(btn => {
        btn.addEventListener('click', () => {
            inputId.value = btn.getAttribute('data-id');
            const resetPasswordIdInput = document.getElementById('modal-reset-password-id');
            if (resetPasswordIdInput) resetPasswordIdInput.value = btn.getAttribute('data-id');
            inputStore.value = btn.getAttribute('data-store');
            inputAdmin.value = btn.getAttribute('data-admin');
            inputEmail.value = btn.getAttribute('data-email');
            inputStatus.value = btn.getAttribute('data-status');
            
            try {
                const features = JSON.parse(btn.getAttribute('data-features') || '[]');
                featCalc.checked = features.includes('calculator');
                featPubCalc.checked = features.includes('public_calc');
                featPayment.checked = features.includes('customer_payment');
                featPo.checked = features.includes('po_recap');
                featTagihan.checked = features.includes('tagihan_recap');
                featWh.checked = features.includes('warehouse');
            } catch(e) {
                featCalc.checked = false;
                featPubCalc.checked = false;
                featPayment.checked = false;
                featPo.checked = false;
                featTagihan.checked = false;
                featWh.checked = false;
            }
            
            editModal.style.display = 'flex';
        });
    });

    if(editModal) {
        closeEdit.addEventListener('click', () => { editModal.style.display = 'none'; });
        cancelEdit.addEventListener('click', () => { editModal.style.display = 'none'; });
        editModal.addEventListener('click', (e) => { if(e.target === editModal) editModal.style.display = 'none'; });
    }
});
</script>

<?php get_footer(); ?>