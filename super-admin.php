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
$goweb_known_features = array('calculator', 'public_calc', 'customer_payment', 'po_recap', 'tagihan_recap', 'warehouse', 'testimonials');

$pending_requests = $wpdb->get_results( "SELECT * FROM {$pending_table} ORDER BY created_at DESC", ARRAY_A );
$goweb_super_admin_notice = '';
$goweb_super_admin_is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower( sanitize_text_field($_SERVER['HTTP_X_REQUESTED_WITH']) ) === 'xmlhttprequest';

// Handler Aksi Super Admin
if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {

    // Verifikasi nonce untuk SEMUA aksi POST di halaman ini (proteksi CSRF)
    if ( ! isset($_POST['goweb_super_admin_nonce']) || ! wp_verify_nonce($_POST['goweb_super_admin_nonce'], 'goweb_super_admin_action') ) {
        if ( $goweb_super_admin_is_ajax ) {
            wp_send_json_error( array( 'message' => 'Sesi tidak valid atau kedaluwarsa. Muat ulang halaman dan coba lagi.' ), 403 );
        }
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
            $req_features = ! empty( $req['features'] ) ? json_decode( $req['features'], true ) : array('calculator', 'public_calc', 'customer_payment', 'po_recap', 'tagihan_recap', 'warehouse', 'testimonials');

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
                $error_message = 'Gagal menyimpan: email "' . esc_html( $edit_email ) . '" sudah dipakai tenant lain. Login tenant dicari lewat email, jadi email tidak boleh duplikat.';
                if ( $goweb_super_admin_is_ajax ) {
                    wp_send_json_error( array( 'message' => wp_strip_all_tags( $error_message ) ), 400 );
                }
                wp_die( $error_message );
            }
            // Status harus dari daftar yang dikenal — dulu free-text dari POST,
            // cukup bikin status "ngawur" yang gak dikenali cek
            // "status === 'Active'" di mana-mana (toko bricked).
            if ( ! in_array( $edit_status, array('Active', 'Offline'), true ) ) {
                if ( $goweb_super_admin_is_ajax ) {
                    wp_send_json_error( array( 'message' => 'Status toko tidak valid. Gunakan Active atau Offline.' ), 400 );
                }
                wp_die('Status toko tidak valid. Gunakan Active atau Offline.');
            }

            $updated_tenant_data = array(
                'store_name' => sanitize_text_field($_POST['edit_store_name']),
                'admin_name' => sanitize_text_field($_POST['edit_admin_name']),
                'email'      => $edit_email,
                'status'     => $edit_status,
                'features'   => wp_json_encode( $edit_features ),
            );
            $tenant_update_result = $wpdb->update( $tenants_table, $updated_tenant_data, array( 'tenant_id' => $t_id ) );
            if ( $tenant_update_result === false && $goweb_super_admin_is_ajax ) {
                wp_send_json_error( array( 'message' => 'Data tenant gagal disimpan. Silakan coba lagi.' ), 500 );
            }

            if ( $goweb_super_admin_is_ajax ) {
                wp_send_json_success( array(
                    'message' => 'Data tenant berhasil diperbarui!',
                    'tenant'  => array(
                        'id'         => $t_id,
                        'store_name' => $updated_tenant_data['store_name'],
                        'admin_name' => $updated_tenant_data['admin_name'],
                        'email'      => $updated_tenant_data['email'],
                        'status'     => $updated_tenant_data['status'],
                        'features'   => $edit_features,
                    ),
                ) );
            }
            $goweb_super_admin_notice = 'Data tenant berhasil diperbarui!';
        } elseif ( $goweb_super_admin_is_ajax ) {
            wp_send_json_error( array( 'message' => 'Tenant tidak ditemukan. Muat ulang halaman dan coba lagi.' ), 404 );
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
            echo '<div style="background:var(--bg-page); border: 1.5px solid #c4b5fd; color:#5b21b6; padding:16px 24px; text-align:center; font-weight:700; margin-bottom:20px; border-radius:10px;">
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
                        $verification_data = array('status' => 'Verified');
                        if ( $row['status'] !== 'Verified' ) {
                            $verification_data['verified_at'] = current_time('mysql');
                        }
                        $wpdb->update( $payments_table, $verification_data, array( 'id' => $payment_id ) );

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
                            // Nilai BERSIH (#11): nominal yang tersimpan sudah
                            // termasuk biaya layanan QRIS. Kalau dipakai mentah,
                            // rate harian jadi lebih tinggi dari harga paket dan
                            // prorata upgrade berikutnya ikut membengkak.
                            $net_paid = goweb_subscription_net_value( $row, $current_tenant_package );
                            $update_data['cycle_daily_rate'] = goweb_subscription_renewal_daily_rate(
                                $old_rate, $remaining_days_before, $net_paid, $duration_days
                            );

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
    "SELECT p.*, t.store_name, t.package_key FROM {$pending_subs_payments_table} p
     LEFT JOIN {$tenants_table} t ON p.store_id = t.tenant_id
     WHERE p.payment_category = 'subscription' AND p.status = 'Pending'
     ORDER BY p.created_at ASC", ARRAY_A
);

get_header();
?>

<style>
    .super-admin-page {
        /* Palet lokal mengikuti beranda dan halaman auth tanpa mengubah
           warna halaman lain yang masih memakai token tema global. */
        --pastel-purple: #6d5a91;
        --pastel-purple-bg: #f0ebf7;
        --pastel-purple-border: #ded7e9;
        --border-soft: #e5e0f2;
        --text-dark: #302b46;
        --text-muted: #777188;
        --bg-page: #faf9fc;
        --sa-ink: #302b46;
        --sa-muted: #777188;
        --sa-line: #e5e0f2;
        --sa-accent: #594a75;
        --sa-accent-soft: #f0ebf7;
        --sa-accent-border: #ded7e9;
        width: min(100%, 1480px);
        margin-inline: auto;
        padding: clamp(24px, 4vw, 48px) clamp(16px, 3.5vw, 52px) 72px;
        color: var(--sa-ink);
    }
    .super-admin-page .sa-action-notice {
        margin: 0 0 20px;
        padding: 16px 20px;
        border: 1px solid #cbded0;
        border-radius: 13px;
        background: #edf4ee;
        color: #45634d;
        box-shadow: 0 6px 18px rgba(59, 86, 65, .07);
        font-size: .9rem;
        font-weight: 750;
        line-height: 1.5;
        scroll-margin-top: 96px;
    }
    .super-admin-page .sa-action-notice i { margin-right: 7px; color: #62816a; }
    .super-admin-page > .sa-page-heading {
        position: relative;
        isolation: isolate;
        overflow: hidden;
        margin-bottom: 22px;
        padding: clamp(24px, 3.2vw, 38px);
        border: 1px solid #dfd8ed;
        border-radius: 22px;
        background: linear-gradient(145deg, #fff 8%, #f5f1fc 100%);
        box-shadow: 0 16px 40px rgba(59, 48, 95, 0.07);
    }
    .super-admin-page > .sa-page-heading::after {
        position: absolute;
        z-index: -1;
        top: -65px;
        right: -30px;
        width: 190px;
        height: 190px;
        border-radius: 50%;
        background: #ebe4f8;
        content: '';
        opacity: 0.65;
    }
    .super-admin-page > .sa-page-heading h1 {
        margin: 0 0 8px;
        color: var(--sa-ink);
        font-size: clamp(1.8rem, 3vw, 2.35rem);
        line-height: 1.2;
        letter-spacing: -0.045em;
    }
    .super-admin-page > .sa-page-heading p {
        max-width: 760px;
        color: var(--sa-muted);
        font-size: 0.95rem;
        line-height: 1.7;
    }
    .super-admin-page > .sa-page-heading .hero-tag {
        margin-bottom: 13px;
        border-color: var(--sa-accent-border);
        background: var(--sa-accent-soft);
        color: var(--sa-accent);
        box-shadow: none;
    }
    .sa-summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .sa-summary-card {
        display: flex;
        align-items: center;
        gap: 16px;
        min-width: 0;
        padding: 20px 22px;
        color: var(--sa-ink);
        text-decoration: none;
        background: #fff;
        border: 1px solid var(--sa-line);
        border-radius: 16px;
        box-shadow: 0 5px 18px rgba(59, 48, 95, 0.045);
        transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }
    .sa-summary-card:hover {
        transform: translateY(-2px);
        border-color: #cfc3df;
        box-shadow: 0 10px 24px rgba(59, 48, 95, 0.09);
    }
    .sa-summary-card:focus-visible { outline: 3px solid #c4b5fd; outline-offset: 3px; }
    .sa-summary-icon {
        display: grid;
        flex: 0 0 46px;
        width: 46px;
        height: 46px;
        place-items: center;
        color: #645287;
        background: #e9e2f5;
        border-radius: 13px;
        font-size: 1.1rem;
    }
    .sa-summary-card.is-pending { border-color: #eadab2; }
    .sa-summary-card.is-pending .sa-summary-icon { color: #94733b; background: #f7f0df; }
    .sa-summary-card.is-pending .sa-summary-count { color: #785d31; }
    .sa-summary-count { font-size: 1.55rem; font-weight: 800; line-height: 1.2; }
    .sa-summary-label { margin-top: 3px; color: var(--sa-muted); font-size: 0.82rem; font-weight: 600; }
    .super-admin-page > .tab-panel {
        margin-bottom: 24px !important;
        padding: clamp(20px, 2.5vw, 30px);
        border: 1px solid var(--sa-line);
        border-radius: 18px;
        box-shadow: 0 8px 28px rgba(59, 48, 95, 0.055);
    }
    .super-admin-page .panel-title { color: var(--sa-ink); font-size: 1.15rem; line-height: 1.4; }
    .super-admin-page .panel-title i { color: #766294 !important; }
    .super-admin-page .panel-desc { max-width: 900px; color: var(--sa-muted); line-height: 1.65; }
    .super-admin-page .table-responsive {
        margin-bottom: 0;
        border-color: var(--sa-line);
        border-radius: 12px;
        background: #fff;
    }
    .super-admin-page .data-table { min-width: 920px; }
    .super-admin-page .sa-tenants-table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
    }
    .super-admin-page .sa-tenants-table th,
    .super-admin-page .sa-tenants-table td {
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .super-admin-page .sa-tenants-table th:nth-child(1) { width: 14%; }
    .super-admin-page .sa-tenants-table th:nth-child(2) { width: 9%; }
    .super-admin-page .sa-tenants-table th:nth-child(3) { width: 12%; }
    .super-admin-page .sa-tenants-table th:nth-child(4) { width: 16%; }
    .super-admin-page .sa-tenants-table th:nth-child(5) { width: 10%; }
    .super-admin-page .sa-tenants-table th:nth-child(6) { width: 10%; }
    .super-admin-page .sa-tenants-table th:nth-child(7) { width: 18%; }
    .super-admin-page .sa-tenants-table th:nth-child(8) { width: 11%; }
    .super-admin-page .sa-tenants-table td:last-child { white-space: nowrap; }
    @media (max-width: 1100px) and (min-width: 681px) {
        .super-admin-page .sa-tenants-table td:last-child { white-space: normal; }
        .super-admin-page .sa-tenant-actions { flex-wrap: wrap; }
    }
    .super-admin-page .data-table th {
        background: #f2eef8;
        color: #62517f;
        font-size: 0.74rem;
        letter-spacing: 0.035em;
        text-transform: uppercase;
    }
    .super-admin-page .data-table td { border-bottom-color: #f0edf4; color: #403a53; font-size: 0.82rem; }
    .sa-tenant-feature-list { display: flex; flex-wrap: wrap; gap: 4px; max-width: 190px; }
    .sa-tenant-feature-pill {
        padding: 3px 7px;
        border: 1px solid #ded7e9;
        border-radius: 20px;
        background: #f0ebf7;
        color: #594a75;
        font-size: .65rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .sa-tenant-no-features { color: var(--sa-muted); font-size: .75rem; }
    .sa-edit-feedback {
        margin: -7px 0 17px;
        padding: 11px 13px;
        border: 1px solid;
        border-radius: 10px;
        font-size: .82rem;
        font-weight: 700;
        line-height: 1.5;
    }
    .sa-edit-feedback[hidden] { display: none; }
    .sa-edit-feedback.is-success { border-color: #cbded0; background: #edf4ee; color: #45634d; }
    .sa-edit-feedback.is-error { border-color: #ead3d0; background: #fbf1ef; color: #965a58; }
    #edit-tenant-modal .sa-edit-actions .btn-submit:disabled { opacity: .72; cursor: wait; transform: none; }
    .super-admin-page .data-table tr:hover td { background: #faf9fc; }
    .super-admin-page .status-badge.badge-purple { color: #62517f; background: #f0ebf7; border-color: #ded7e9; }
    .super-admin-page .status-badge.badge-mint { color: #56745d; background: #edf4ee; border-color: #d6e5d8; }
    .super-admin-page .status-badge.badge-peach { color: #946a43; background: #f8f1e8; border-color: #eadbc8; }
    .super-admin-page .data-table tbody tr:last-child td { border-bottom: 0; }
    .sa-action-group { display: flex; align-items: center; gap: 7px; white-space: nowrap; }
    .sa-btn {
        min-height: 34px;
        padding: 7px 12px;
        border: 1px solid transparent;
        border-radius: 8px;
        font: inherit;
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1;
        cursor: pointer;
        transition: filter 0.15s ease, transform 0.15s ease;
    }
    .sa-btn:hover { filter: brightness(0.96); transform: translateY(-1px); }
    .sa-btn-approve { color: #fff; background: #62816a; }
    .sa-btn-edit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 36px;
        padding: 8px 12px;
        color: #51416f;
        background: #fff;
        border-color: #d9d0e6;
        box-shadow: 0 1px 2px rgba(59, 48, 95, 0.05);
    }
    .sa-btn-edit:hover { color: #43345f; background: #f5f1f9; border-color: #bfb1d2; }
    .sa-btn-edit i { color: #766294; }
    .sa-btn-danger { color: #965a58; background: #fbf1ef; border-color: #efdeda; }
    .sa-btn:focus-visible { outline: 3px solid #c4b5fd; outline-offset: 2px; }
    .super-admin-page code {
        padding: 3px 6px;
        color: #594a75;
        background: #f0ebf7;
        border-radius: 5px;
        font-size: 0.78rem;
    }
    #edit-tenant-modal > div,
    #genericConfirmModal > div {
        border: 1px solid var(--sa-line);
        box-shadow: 0 22px 58px rgba(59, 48, 95, 0.18);
    }
    #edit-tenant-modal,
    #genericConfirmModal {
        --sa-line: #e5e0f2;
    }
    #edit-tenant-modal {
        --sa-ink: #302b46;
        --pastel-purple: #6d5a91;
        --pastel-purple-bg: #f0ebf7;
        --pastel-purple-border: #ded7e9;
        --border-soft: #e5e0f2;
        --text-dark: #302b46;
        --text-muted: #777188;
        --bg-page: #faf9fc;
    }
    #edit-tenant-modal h3 { color: var(--sa-ink); }
    #edit-tenant-modal h3 i { color: #766294 !important; }
    #genericConfirmModal > div > div:first-child { color: #a78348 !important; }
    #genericConfirmModal #genericConfirmCancel { color: #6f687d !important; }
    #genericConfirmModal #genericConfirmOk { background: #a76561 !important; }
    @media (max-width: 680px) {
        .super-admin-page { padding: 24px 14px 48px; }
        .super-admin-page > .sa-page-heading { margin-bottom: 22px; }
        .super-admin-page > .sa-page-heading p { font-size: 0.88rem; }
        .sa-summary-grid { grid-template-columns: 1fr; gap: 10px; margin-bottom: 18px; }
        .sa-summary-card { padding: 16px; border-radius: 14px; }
        .super-admin-page > .tab-panel { padding: 20px 14px; border-radius: 14px; }
        .super-admin-page .panel-title { font-size: 1.02rem; }
        .super-admin-page .panel-desc { margin-bottom: 18px; font-size: 0.82rem; }
        .super-admin-page .data-table th,
        .super-admin-page .data-table td { padding: 12px 13px; }
        .super-admin-page .sa-tenants-table-wrap { overflow: visible; border: 0; background: transparent; }
        .super-admin-page .sa-tenants-table,
        .super-admin-page .sa-tenants-table tbody,
        .super-admin-page .sa-tenants-table tr,
        .super-admin-page .sa-tenants-table td { display: block; width: 100%; min-width: 0; }
        .super-admin-page .sa-tenants-table thead {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            clip-path: inset(50%);
        }
        .super-admin-page .sa-tenants-table tbody tr {
            margin-bottom: 12px;
            overflow: hidden;
            border: 1px solid var(--sa-line);
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(59, 48, 95, 0.04);
        }
        .super-admin-page .sa-tenants-table td,
        .super-admin-page .sa-tenants-table td:last-child {
            display: grid;
            grid-template-columns: minmax(92px, 30%) minmax(0, 1fr);
            align-items: start;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid #f0edf4;
            white-space: normal;
            font-size: 0.78rem;
            overflow-wrap: anywhere;
        }
        .super-admin-page .sa-tenants-table td::before {
            content: attr(data-label);
            color: var(--sa-muted);
            font-size: 0.7rem;
            font-weight: 700;
        }
        .super-admin-page .sa-tenants-table td:last-child { border-bottom: 0; }
        .super-admin-page .sa-tenants-table td[colspan] { display: block; }
        .super-admin-page .sa-tenants-table td[colspan]::before { content: none; }
    }
    #edit-tenant-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(37, 32, 52, 0.54);
        backdrop-filter: blur(5px);
    }
    #edit-tenant-modal .sa-edit-dialog {
        position: relative;
        width: min(100%, 580px);
        max-height: min(90vh, 820px);
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        padding: 30px;
        border: 1px solid #e5e0f2;
        border-radius: 20px;
        background: #fff;
        box-shadow: 0 24px 64px rgba(43, 35, 65, 0.2);
    }
    .sa-edit-heading { margin: 0 40px 22px 0; }
    .sa-edit-heading h3 { margin: 0 0 7px; color: #302b46; font-size: 1.2rem; font-weight: 800; letter-spacing: -.025em; }
    .sa-edit-heading p { margin: 0; color: #777188; font-size: .84rem; line-height: 1.6; }
    .sa-modal-close {
        position: absolute;
        top: 20px;
        right: 20px;
        display: grid;
        width: 36px;
        height: 36px;
        place-items: center;
        border: 1px solid #e5e0f2;
        border-radius: 10px;
        background: #faf9fc;
        color: #777188;
        font-size: 1rem;
        cursor: pointer;
        transition: background .16s ease, color .16s ease, border-color .16s ease;
    }
    .sa-modal-close:hover { border-color: #d9d0e6; background: #f0ebf7; color: #51416f; }
    .sa-modal-close:focus-visible { outline: 3px solid #c4b5fd; outline-offset: 2px; }
    .sa-edit-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 14px; }
    #edit-tenant-modal .sa-edit-fields .form-group { min-width: 0; margin: 0 !important; gap: 7px; }
    #edit-tenant-modal .sa-edit-fields .form-group > label {
        color: #514b63;
        font-size: .78rem;
        font-weight: 750;
        letter-spacing: 0;
        text-transform: none;
    }
    #edit-tenant-modal .sa-edit-fields .form-control {
        min-height: 44px;
        padding: 10px 12px;
        border: 1px solid #ded8e8;
        border-radius: 10px;
        background: #fbfafc;
        color: #302b46;
        font: inherit;
        font-size: .86rem;
    }
    #edit-tenant-modal .sa-edit-fields .form-control:focus {
        border-color: #9c8ab8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(109, 90, 145, .13);
    }
    .sa-feature-section { margin: 22px 0 20px; }
    .sa-feature-heading { margin-bottom: 10px; color: #403a53; font-size: .82rem; font-weight: 800; }
    .sa-feature-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        padding: 10px;
        border: 1px solid #e5e0f2;
        border-radius: 12px;
        background: #faf9fc;
    }
    #edit-tenant-modal .sa-feature-list label {
        display: flex;
        min-height: 42px;
        align-items: center;
        gap: 9px;
        padding: 8px 10px;
        border: 1px solid transparent;
        border-radius: 9px;
        color: #514b63;
        font-size: .77rem;
        font-weight: 600;
        letter-spacing: 0;
        line-height: 1.4;
        text-transform: none;
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
    }
    #edit-tenant-modal .sa-feature-list label:hover { border-color: #e2d9ec; background: #fff; }
    #edit-tenant-modal .sa-feature-list input[type="checkbox"] {
        width: 16px;
        height: 16px;
        flex: 0 0 16px;
        margin: 0;
        accent-color: #6d5a91;
    }
    .sa-edit-actions { display: flex; gap: 10px; }
    #edit-tenant-modal .sa-edit-actions .btn-submit {
        min-height: 44px;
        flex: 1;
        justify-content: center;
        border-radius: 10px;
        font: inherit;
        font-size: .85rem;
        font-weight: 800;
    }
    .sa-modal-secondary {
        min-height: 44px;
        padding: 0 18px;
        border: 1px solid #ded8e8;
        border-radius: 10px;
        background: #fff;
        color: #625b70;
        font: inherit;
        font-size: .84rem;
        font-weight: 700;
        cursor: pointer;
        transition: background .16s ease, border-color .16s ease;
    }
    .sa-modal-secondary:hover { border-color: #cfc3df; background: #f8f6fb; }
    .sa-reset-form { margin-top: 22px; padding-top: 18px; border-top: 1px solid #eeeaf3; }
    .sa-reset-heading { display: block; margin-bottom: 5px; color: #514b63; font-size: .82rem; font-weight: 800; }
    .sa-reset-copy { display: block; margin: 0 0 11px; color: #777188; font-size: .75rem; line-height: 1.55; }
    #edit-tenant-modal .sa-reset-button {
        display: inline-flex;
        min-height: 40px;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0 14px;
        border: 1px solid #ead7d5;
        border-radius: 10px;
        background: #fbf4f3;
        color: #965a58;
        font: inherit;
        font-size: .8rem;
        font-weight: 750;
        cursor: pointer;
        transition: background .16s ease, border-color .16s ease;
    }
    #edit-tenant-modal .sa-reset-button:hover { border-color: #dfc2bf; background: #f7eae8; }
    @media (max-width: 680px) {
        #edit-tenant-modal { padding: 12px; }
        #edit-tenant-modal .sa-edit-dialog { max-height: 92vh; padding: 23px 17px; border-radius: 16px; }
        .sa-edit-heading { margin-bottom: 19px; }
        .sa-edit-heading h3 { font-size: 1.08rem; }
        .sa-edit-fields { grid-template-columns: 1fr; gap: 13px; }
        .sa-feature-section { margin-top: 18px; }
        .sa-feature-list { grid-template-columns: 1fr; gap: 4px; }
        .sa-edit-actions { flex-direction: column; }
        .sa-modal-secondary { width: 100%; }
        #edit-tenant-modal .sa-reset-button { width: 100%; }
    }
</style>

<main class="super-admin-page">
    <?php if ( $goweb_super_admin_notice !== '' ): ?>
        <div class="alert-box center alert-success sa-action-notice" id="sa-action-notice" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i><?php echo esc_html( $goweb_super_admin_notice ); ?>
        </div>
    <?php endif; ?>
    <div class="sa-page-heading">
        <span class="hero-tag"><i class="fa-solid fa-shield-halved"></i> Pusat Kontrol Utama</span>
        <h1>Super Admin Dashboard</h1>
        <p>Kelola pendaftaran tenant baru dari form registrasi, verifikasi pembayaran, edit toko, dan atur fitur GO-WEB.</p>
    </div>

    <!-- RINGKASAN CEPAT — biar gak perlu scroll ke tiap section satu-satu
         cuma buat tau ada berapa yang perlu ditindaklanjuti. -->
    <div class="sa-summary-grid">
        <a href="#section-pending-registrations" class="sa-summary-card<?php echo count($pending_requests) > 0 ? ' is-pending' : ''; ?>">
            <span class="sa-summary-icon" aria-hidden="true"><i class="fa-solid fa-user-clock"></i></span>
            <span>
                <span class="sa-summary-count" style="display:block;"><?php echo count($pending_requests); ?></span>
                <span class="sa-summary-label" style="display:block;">Pendaftaran baru menunggu</span>
            </span>
        </a>
        <a href="#section-pending-subscriptions" class="sa-summary-card<?php echo count($pending_subscriptions) > 0 ? ' is-pending' : ''; ?>">
            <span class="sa-summary-icon" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span>
            <span>
                <span class="sa-summary-count" style="display:block;"><?php echo count($pending_subscriptions); ?></span>
                <span class="sa-summary-label" style="display:block;">Perpanjangan sewa menunggu verifikasi</span>
            </span>
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
        <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 20px 24px; margin-top: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <span style="font-size: 1.5rem; font-weight: 800; color: var(--text-dark);"><?php echo number_format($db_size_mb, 1); ?> MB</span>
                    <span style="color: var(--text-muted); font-size: 0.9rem;"> / <?php echo number_format(GOWEB_DB_SIZE_LIMIT_MB, 0); ?> MB (<?php echo GOWEB_DB_SIZE_LIMIT_MB / 1024; ?> GB)</span>
                </div>
                <span style="padding: 4px 12px; border-radius: 999px; background: <?php echo esc_attr($db_health_color); ?>1a; color: <?php echo esc_attr($db_health_color); ?>; font-weight: 800; font-size: 0.8rem;"><?php echo $db_size_pct; ?>% — <?php echo esc_html($db_health_label); ?></span>
            </div>
            <div style="background: var(--border-soft); border-radius: 999px; height: 10px; width: 100%; overflow: hidden;">
                <div style="background: <?php echo esc_attr($db_health_color); ?>; height: 100%; width: <?php echo $db_size_pct; ?>%; transition: width 0.3s;"></div>
            </div>
            <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 10px;">Dihitung langsung dari MySQL (<code>information_schema</code>), bukan estimasi — jadi angkanya akurat 100% sesuai kondisi database kamu saat ini.</p>
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
                                            <button type="submit" name="approve_registration" class="sa-btn sa-btn-approve">
                                                <i class="fa-solid fa-check"></i> Setujui
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Tolak pendaftaran ini?');">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="request_id" value="<?php echo esc_attr($req_id); ?>">
                                            <button type="submit" name="reject_registration" class="sa-btn sa-btn-danger" aria-label="Tolak pendaftaran">
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
                                <td>
                                    <?php
                                    // Tampilkan nominal yang SEHARUSNYA (dihitung ulang dari
                                    // kamus paket & durasi, #11) supaya Super Admin tidak
                                    // memverifikasi buta — mis. bukti transfer yang diedit
                                    // akan langsung kelihatan bedanya.
                                    $sub_expected = null;
                                    if ( empty( $sub['requested_package'] ) ) {
                                        // package_key sudah ikut di JOIN query antrean (bukan query per baris)
                                        $sub_pkg = goweb_get_package( ! empty( $sub['package_key'] ) ? $sub['package_key'] : 'starter' );
                                        $sub_months = ! empty( $sub['requested_duration_months'] ) ? intval( $sub['requested_duration_months'] ) : 1;
                                        $sub_durations = goweb_get_subscription_durations();
                                        if ( ! isset( $sub_durations[ $sub_months ] ) ) $sub_months = 1;
                                        $sub_expected = goweb_calculate_qris_fee( goweb_calculate_duration_price( $sub_pkg, $sub_months ) )['total'];
                                    }
                                    ?>
                                    Rp <?php echo number_format( intval($sub['nominal']), 0, ',', '.' ); ?>
                                    <?php if ( $sub_expected !== null && intval($sub['nominal']) !== intval($sub_expected) ): ?>
                                        <br><small style="color:#b45309; font-size:0.72rem; font-weight:700;">harusnya Rp <?php echo number_format( $sub_expected, 0, ',', '.' ); ?></small>
                                    <?php endif; ?>
                                </td>
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
                                            <button type="submit" name="verify_subscription_payment" class="sa-btn sa-btn-approve">Verifikasi</button>
                                        </form>
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Tolak & hapus pengajuan ini?');" style="display:inline;">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo esc_attr($sub['id']); ?>">
                                            <button type="submit" name="reject_subscription_payment" class="sa-btn sa-btn-danger" aria-label="Tolak dan hapus pengajuan">✕</button>
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
        
        <div class="table-responsive sa-tenants-table-wrap">
            <table class="data-table sa-tenants-table">
                <thead>
                    <tr>
                        <th>Nama Toko / Proxy</th>
                        <th>URL Path</th>
                        <th>Admin / Pemilik</th>
                        <th>Email Login</th>
                        <th>Tanggal Daftar</th>
                        <th>Status Akun</th>
                        <th>Fitur Aktif</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( !empty($all_tenants) && is_array($all_tenants) ): ?>
                        <?php foreach ( $all_tenants as $tenant ): $t_id = $tenant['tenant_id']; $t_features = ! empty($tenant['features']) ? json_decode($tenant['features'], true) : array(); ?>
                            <tr>
                                <td data-label="Nama Toko / Proxy"><strong><?php echo esc_html($tenant['store_name']); ?></strong></td>
                                <td data-label="URL Path"><code>/<?php echo esc_html(!empty($tenant['slug']) ? $tenant['slug'] : sanitize_title($tenant['store_name'])); ?></code></td>
                                <td data-label="Admin / Pemilik"><?php echo esc_html($tenant['admin_name']); ?></td>
                                <td data-label="Email Login"><?php echo esc_html($tenant['email']); ?></td>
                                <td data-label="Tanggal Daftar"><?php echo esc_html( date('d/m/Y', strtotime($tenant['created_at'])) ); ?></td>
                                <td data-label="Status Akun">
                                    <?php if($tenant['status'] == 'Active'): ?>
                                        <span class="status-badge badge-mint">Active</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-peach">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Fitur Aktif">
                                    <?php
                                    // Ringkasan fitur di daftar (#38): sebelumnya fitur
                                    // hanya kelihatan setelah buka modal Edit, jadi Super
                                    // Admin tak bisa membandingkan paket antar tenant sekilas.
                                    // Label pendek dipakai supaya kolom tidak melebar.
                                    $t_feature_labels = array(
                                        'calculator'       => 'Kalkulator',
                                        'public_calc'      => 'Kalk. Publik',
                                        'customer_payment' => 'Bayar Pelanggan',
                                        'po_recap'         => 'Rekapan PO',
                                        'tagihan_recap'    => 'Rekapan Tagihan',
                                        'warehouse'        => 'Warehouse',
                                        'testimonials'     => 'Testimoni',
                                    );
                                    $t_features_valid = array_values( array_intersect( (array) $t_features, array_keys( $t_feature_labels ) ) );
                                    ?>
                                    <?php if ( ! empty( $t_features_valid ) ): ?>
                                        <div class="sa-tenant-feature-list">
                                            <?php foreach ( $t_features_valid as $t_feat ): ?>
                                                <span class="sa-tenant-feature-pill"><?php echo esc_html( $t_feature_labels[ $t_feat ] ); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="sa-tenant-no-features">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Aksi">
                                    <div class="sa-tenant-actions" style="display: flex; gap: 6px;">
                                        <button type="button"
                                            data-id="<?php echo esc_attr($t_id); ?>" 
                                            data-store="<?php echo esc_attr($tenant['store_name']); ?>" 
                                            data-admin="<?php echo esc_attr($tenant['admin_name']); ?>" 
                                            data-email="<?php echo esc_attr($tenant['email']); ?>" 
                                            data-status="<?php echo esc_attr($tenant['status']); ?>" 
                                            data-features="<?php echo esc_attr(json_encode($t_features)); ?>"
                                            class="btn-edit-trigger sa-btn sa-btn-edit">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </button>
                                        
                                        <form method="POST" onsubmit="return goweb_confirmFormSubmit(event, this, 'Yakin ingin menghapus tenant ini beserta seluruh datanya?');" style="display:inline;">
                                            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
                                            <input type="hidden" name="delete_tenant_id" value="<?php echo esc_attr($t_id); ?>">
                                            <button type="submit" name="delete_tenant" class="sa-btn sa-btn-danger" aria-label="Hapus tenant"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 20px;">Belum ada data tenant aktif di database.</td>
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
<div id="edit-tenant-modal" role="dialog" aria-modal="true" aria-labelledby="sa-edit-dialog-title">
    <div class="sa-edit-dialog">
        <button type="button" id="close-edit-modal" class="sa-modal-close" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>

        <div class="sa-edit-heading">
            <h3 id="sa-edit-dialog-title"><i class="fa-solid fa-pen-to-square" style="color: #766294;"></i> Edit Data Tenant & Fitur</h3>
            <p>Perbarui informasi toko, status akun, dan modul yang dapat digunakan tenant.</p>
        </div>
        <div class="sa-edit-feedback" id="sa-edit-feedback" role="status" aria-live="polite" hidden></div>

        <form method="POST" id="sa-edit-tenant-form">
            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
            <input type="hidden" name="edit_tenant_id" id="modal-edit-id">
            
            <div class="sa-edit-fields">
                <div class="form-group">
                    <label for="modal-edit-store">Nama Proxy / Jastip / WH</label>
                    <input type="text" name="edit_store_name" id="modal-edit-store" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="modal-edit-admin">Nama Pemilik / Admin</label>
                    <input type="text" name="edit_admin_name" id="modal-edit-admin" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="modal-edit-email">Email Akses</label>
                    <input type="email" name="edit_email" id="modal-edit-email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="modal-edit-status">Status Toko</label>
                    <select name="edit_status" id="modal-edit-status" class="form-control" style="background:#fff;">
                        <option value="Active">Active (Online & Normal)</option>
                        <option value="Offline">Offline (Terkunci)</option>
                    </select>
                </div>
            </div>

            <!-- CHECKBOX FITUR DI MODAL EDIT -->
            <div class="sa-feature-section">
                <div class="sa-feature-heading">Akses Fitur Tenant</div>
                <div class="sa-feature-list">
                    <label><input type="checkbox" name="edit_features[]" value="calculator" id="feat-calc"> Kalkulator Harga Jual (Dashboard)</label>
                    <label><input type="checkbox" name="edit_features[]" value="public_calc" id="feat-pubcalc"> Kalkulator Estimasi (Halaman Depan)</label>
                    <label><input type="checkbox" name="edit_features[]" value="customer_payment" id="feat-payment"> Konfirmasi Bayar Pelanggan</label>
                    <label><input type="checkbox" name="edit_features[]" value="po_recap" id="feat-po"> Kelola Rekapan PO</label>
                    <label><input type="checkbox" name="edit_features[]" value="tagihan_recap" id="feat-tagihan"> Kelola Rekapan Tagihan</label>
                    <label><input type="checkbox" name="edit_features[]" value="warehouse" id="feat-wh"> Database Warehouse</label>
                    <label><input type="checkbox" name="edit_features[]" value="testimonials" id="feat-testi"> Kelola Testimoni</label>
                </div>
            </div>

            <div class="sa-edit-actions">
                <button type="submit" name="update_tenant" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan</button>
                <button type="button" id="cancel-edit-modal" class="sa-modal-secondary">Batal</button>
            </div>
        </form>

        <!-- RESET PASSWORD — form TERPISAH dari "update_tenant" di atas
             (aksi beda, dan HTML gak boleh nested <form>). Pakai modal
             konfirmasi generic yang udah ada di file ini, bukan confirm()
             bawaan browser. -->
        <form method="POST" class="sa-reset-form" onsubmit="return goweb_confirmFormSubmit(event, this, 'Generate password baru buat tenant ini? Password lama langsung gak berlaku begitu tombol ini ditekan.');">
            <?php wp_nonce_field('goweb_super_admin_action', 'goweb_super_admin_nonce'); ?>
            <input type="hidden" name="reset_tenant_id" id="modal-reset-password-id">
            <label class="sa-reset-heading">Reset password tenant</label>
            <small class="sa-reset-copy">Password lama langsung tidak berlaku. Password baru hanya ditampilkan sekali—salin dan kirim secara pribadi.</small>
            <button type="submit" name="reset_tenant_password" class="sa-reset-button"><i class="fa-solid fa-key"></i> Generate Password Baru</button>
        </form>
    </div>
</div>

<!-- MODAL KONFIRMASI GENERIC (gantiin confirm() bawaan browser) -->
<div class="modal-overlay" id="genericConfirmModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 10000; padding: 20px;">
    <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;"><i class="fa-solid fa-circle-question"></i></div>
        <p id="genericConfirmMessage" style="font-size: 0.95rem; font-weight: 700; color: var(--text-dark); margin-bottom: 24px;"></p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="genericConfirmCancel" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; color: var(--text-muted); cursor: pointer;">Batal</button>
            <button type="button" id="genericConfirmOk" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<script>
let goweb_pendingConfirmForm = null;
let goweb_pageScrollLocked = false;
let goweb_savedScrollY = 0;
let goweb_savedScrollStyles = null;

function goweb_syncSuperAdminScrollLock() {
    const editModal = document.getElementById('edit-tenant-modal');
    const confirmModal = document.getElementById('genericConfirmModal');
    const shouldLock = (editModal && editModal.style.display === 'flex')
        || (confirmModal && confirmModal.style.display === 'flex');
    const body = document.body;
    const root = document.documentElement;

    if (shouldLock && !goweb_pageScrollLocked) {
        goweb_pageScrollLocked = true;
        goweb_savedScrollY = window.scrollY || window.pageYOffset || 0;
        goweb_savedScrollStyles = {
            position: body.style.position,
            top: body.style.top,
            left: body.style.left,
            right: body.style.right,
            width: body.style.width,
            overflow: body.style.overflow,
            paddingRight: body.style.paddingRight,
            rootOverflow: root.style.overflow
        };
        const scrollbarWidth = Math.max(0, window.innerWidth - root.clientWidth);
        const currentPaddingRight = parseFloat(window.getComputedStyle(body).paddingRight) || 0;
        body.style.position = 'fixed';
        body.style.top = `-${goweb_savedScrollY}px`;
        body.style.left = '0';
        body.style.right = '0';
        body.style.width = 'auto';
        body.style.overflow = 'hidden';
        if (scrollbarWidth) body.style.paddingRight = `${currentPaddingRight + scrollbarWidth}px`;
        root.style.overflow = 'hidden';
    } else if (!shouldLock && goweb_pageScrollLocked) {
        goweb_pageScrollLocked = false;
        const saved = goweb_savedScrollStyles || {};
        body.style.position = saved.position || '';
        body.style.top = saved.top || '';
        body.style.left = saved.left || '';
        body.style.right = saved.right || '';
        body.style.width = saved.width || '';
        body.style.overflow = saved.overflow || '';
        body.style.paddingRight = saved.paddingRight || '';
        root.style.overflow = saved.rootOverflow || '';
        goweb_savedScrollStyles = null;
        window.scrollTo(0, goweb_savedScrollY);
    }
}

// Dipanggil dari onsubmit form yang butuh konfirmasi. Cegah submit native
// (event.preventDefault()), simpan form-nya, tampilin modal. Submit
// beneran cuma jalan kalau user klik "Ya, Lanjutkan".
function goweb_confirmFormSubmit(event, form, message) {
    event.preventDefault();
    goweb_pendingConfirmForm = form;
    document.getElementById('genericConfirmMessage').textContent = message;
    document.getElementById('genericConfirmModal').style.display = 'flex';
    goweb_syncSuperAdminScrollLock();
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
        goweb_syncSuperAdminScrollLock();
    });
    if (genericModal) genericModal.addEventListener('click', (e) => {
        if (e.target === genericModal) {
            genericModal.style.display = 'none';
            goweb_pendingConfirmForm = null;
            goweb_syncSuperAdminScrollLock();
        }
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
    const editTenantForm = document.getElementById('sa-edit-tenant-form');
    const editFeedback = document.getElementById('sa-edit-feedback');
    const updateTenantButton = editTenantForm ? editTenantForm.querySelector('[name="update_tenant"]') : null;
    
    const featCalc = document.getElementById('feat-calc');
    const featPubCalc = document.getElementById('feat-pubcalc');
    const featPayment = document.getElementById('feat-payment');
    const featPo = document.getElementById('feat-po');
    const featTagihan = document.getElementById('feat-tagihan');
    const featWh = document.getElementById('feat-wh');
    const featTesti = document.getElementById('feat-testi');

    function showTenantEditFeedback(message, isError) {
        if (!editFeedback) return;
        editFeedback.hidden = false;
        editFeedback.className = 'sa-edit-feedback ' + (isError ? 'is-error' : 'is-success');
        editFeedback.textContent = message;
    }

    function updateTenantListRow(tenant) {
        const editButton = Array.from(document.querySelectorAll('.btn-edit-trigger')).find(button => button.dataset.id === tenant.id);
        if (!editButton) return;

        editButton.dataset.store = tenant.store_name;
        editButton.dataset.admin = tenant.admin_name;
        editButton.dataset.email = tenant.email;
        editButton.dataset.status = tenant.status;
        editButton.dataset.features = JSON.stringify(tenant.features || []);

        const row = editButton.closest('tr');
        if (!row) return;
        const cells = row.querySelectorAll('td');
        if (cells.length < 8) return;

        const storeName = cells[0].querySelector('strong');
        if (storeName) storeName.textContent = tenant.store_name;
        cells[2].textContent = tenant.admin_name;
        cells[3].textContent = tenant.email;

        const statusBadge = cells[5].querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.className = 'status-badge ' + (tenant.status === 'Active' ? 'badge-mint' : 'badge-peach');
            statusBadge.textContent = tenant.status === 'Active' ? 'Active' : 'Offline';
        }

        const featureNames = {
            calculator: 'Kalkulator',
            public_calc: 'Kalk. Publik',
            customer_payment: 'Bayar Pelanggan',
            po_recap: 'Rekapan PO',
            tagihan_recap: 'Rekapan Tagihan',
            warehouse: 'Warehouse',
            testimonials: 'Testimoni'
        };
        const featuresCell = cells[6];
        featuresCell.replaceChildren();
        const featuresWrap = document.createElement('div');
        featuresWrap.className = 'sa-tenant-feature-list';
        (tenant.features || []).filter(feature => featureNames[feature]).forEach(feature => {
            const badge = document.createElement('span');
            badge.className = 'sa-tenant-feature-pill';
            badge.textContent = featureNames[feature];
            featuresWrap.appendChild(badge);
        });
        if (featuresWrap.childElementCount) {
            featuresCell.appendChild(featuresWrap);
        } else {
            const emptyFeatures = document.createElement('span');
            emptyFeatures.className = 'sa-tenant-no-features';
            emptyFeatures.textContent = '—';
            featuresCell.appendChild(emptyFeatures);
        }
    }

    if (editTenantForm) editTenantForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (editFeedback) editFeedback.hidden = true;
        if (updateTenantButton) {
            updateTenantButton.disabled = true;
            updateTenantButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Menyimpan…';
        }

        try {
            const formData = new FormData(editTenantForm);
            formData.append('update_tenant', '1');
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.data && result.data.message ? result.data.message : 'Perubahan belum berhasil disimpan. Coba lagi.');
            }
            updateTenantListRow(result.data.tenant);
            showTenantEditFeedback(result.data.message, false);
        } catch (error) {
            showTenantEditFeedback(error.message || 'Koneksi bermasalah. Perubahan belum berhasil disimpan.', true);
        } finally {
            if (updateTenantButton) {
                updateTenantButton.disabled = false;
                updateTenantButton.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan';
            }
        }
    });

    document.querySelectorAll('.btn-edit-trigger').forEach(btn => {
        btn.addEventListener('click', () => {
            inputId.value = btn.getAttribute('data-id');
            const resetPasswordIdInput = document.getElementById('modal-reset-password-id');
            if (resetPasswordIdInput) resetPasswordIdInput.value = btn.getAttribute('data-id');
            inputStore.value = btn.getAttribute('data-store');
            inputAdmin.value = btn.getAttribute('data-admin');
            inputEmail.value = btn.getAttribute('data-email');
            inputStatus.value = btn.getAttribute('data-status');
            if (editFeedback) editFeedback.hidden = true;
            
            try {
                const features = JSON.parse(btn.getAttribute('data-features') || '[]');
                featCalc.checked = features.includes('calculator');
                featPubCalc.checked = features.includes('public_calc');
                featPayment.checked = features.includes('customer_payment');
                featPo.checked = features.includes('po_recap');
                featTagihan.checked = features.includes('tagihan_recap');
                featWh.checked = features.includes('warehouse');
                featTesti.checked = features.includes('testimonials');
            } catch(e) {
                featCalc.checked = false;
                featPubCalc.checked = false;
                featPayment.checked = false;
                featPo.checked = false;
                featTagihan.checked = false;
                featWh.checked = false;
                featTesti.checked = false;
            }
            
            editModal.style.display = 'flex';
            goweb_syncSuperAdminScrollLock();
            closeEdit.focus();
        });
    });

    if(editModal) {
        const closeTenantEditModal = () => {
            editModal.style.display = 'none';
            goweb_syncSuperAdminScrollLock();
        };
        closeEdit.addEventListener('click', closeTenantEditModal);
        cancelEdit.addEventListener('click', closeTenantEditModal);
        editModal.addEventListener('click', (e) => {
            if (e.target === editModal) closeTenantEditModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && editModal.style.display === 'flex') closeTenantEditModal();
        });
    }
});
</script>

<?php get_footer(); ?>