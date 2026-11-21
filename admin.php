<?php
/*
Template Name: Tenant Dashboard (Admin GO)
*/

// ----------------------------------------------------
// WAJIB: JANGAN PERNAH DI-CACHE (page cache / CDN)
// Halaman ini berisi data privat per-tenant dan loginnya pakai cookie
// custom (goweb_tenant_auth), BUKAN cookie login WordPress standar. Cache
// plugin (LiteSpeed Cache, WP Rocket, dll) biasanya cuma ngenalin cookie
// login WP untuk mutusin "halaman ini private, jangan di-cache" — karena
// cookie kita beda, mereka bisa nganggep /dashboard sebagai halaman publik
// biasa dan nge-cache-nya, lalu nge-serve HASIL CACHE itu ke SIAPAPUN
// (termasuk yang belum login / tenant lain) tanpa pernah menjalankan PHP
// lagi — auth check di bawah jadi percuma karena gak sempat dieksekusi.
// ----------------------------------------------------
if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true); // dikenali WP Rocket, W3TC, LiteSpeed Cache, dll
nocache_headers(); // header standar WP: Cache-Control/Pragma/Expires: no-cache
if ( function_exists('do_action') ) {
    do_action('litespeed_control_set_nocache', 'goweb tenant dashboard - private per-tenant content');
}
// Fallback header langsung untuk LiteSpeed Web Server (jaga2 kalau hook di atas belum ke-load)
header('X-LiteSpeed-Cache-Control: no-cache');

show_admin_bar( true );

// ----------------------------------------------------
// AUTH + OWNERSHIP CHECK
// Dashboard tenant hanya boleh diakses oleh:
//  (a) tenant yang login lewat sesi custom (goweb_get_logged_in_tenant(),
//      lihat functions.php — BUKAN akun WP, cocokkan by tenant id), atau
//  (b) administrator WP (super admin) yang butuh akses lintas-tenant lewat
//      /wp-admin, ini tetap pakai WP asli karena memang beda konteks.
// Tidak ada lagi fallback "tenant pertama" untuk visitor anonim/nyasar.
// ----------------------------------------------------
global $wpdb;
$goweb_tables = goweb_get_table_names();
$tenants_table = $goweb_tables['tenants'];

$owned_tenant   = goweb_get_logged_in_tenant();
$is_super_admin = is_user_logged_in() && current_user_can('administrator');

if ( ! $owned_tenant && ! $is_super_admin ) {
    wp_redirect( home_url('/login') );
    exit;
}

// Slug tenant target (dipakai super admin buat intip dashboard tenant lain)
// dibaca dari query string ?store=slug — BUKAN get_query_var('tenant_slug'),
// karena query var itu cuma keisi buat URL toko publik satu-segmen
// (/nama-toko/), gak pernah ke-populate buat struktur URL /dashboard/*.
$tenant_slug = isset($_GET['store']) ? sanitize_title( wp_unslash( $_GET['store'] ) ) : '';
$current_tenant = null;

if ( ! empty( $tenant_slug ) ) {
    $current_tenant = goweb_find_tenant_by_slug( $tenant_slug );
    // Slug di URL cuma valid dipakai kalau itu tenant milik sendiri, ATAU
    // yang mengakses adalah super admin (boleh lihat tenant manapun).
    if ( $current_tenant && ! $is_super_admin ) {
        $owner_match = $owned_tenant && $owned_tenant['id'] === $current_tenant['id'];
        if ( ! $owner_match ) {
            wp_die('Akses ditolak! Kamu tidak memiliki akses ke dashboard toko ini.');
        }
    }
}

if ( ! $current_tenant ) {
    // Tidak ada slug valid di URL: default ke tenant milik sendiri
    $current_tenant = $owned_tenant;
}

if ( ! $current_tenant ) {
    if ( $is_super_admin ) {
        // Super admin gak nentuin tenant mana (gak ada ?store= di URL) —
        // tampilin daftar semua tenant biar bisa diklik, bukan langsung error
        // polos atau (parahnya, versi lama) auto-nampilin tenant pertama.
        $all_tenants_for_picker = $wpdb->get_results( "SELECT * FROM {$tenants_table} ORDER BY store_name ASC", ARRAY_A );
        goweb_render_tenant_picker( $all_tenants_for_picker );
        exit;
    }
    wp_die('Sesi login kamu tidak valid. Silakan login ulang.');
}

$store_id        = $current_tenant['id'];
$store_name      = $current_tenant['store_name'];
$admin_name      = $current_tenant['admin_name'];
$store_slug      = isset($current_tenant['slug']) ? $current_tenant['slug'] : sanitize_title($store_name);
$store_url       = home_url('/' . $store_slug . '/');

// Dipakai buat nempelin ?store=slug ke link menu sidebar & redirect setelah
// aksi — supaya pas super admin lagi intip dashboard tenant lain, konteksnya
// gak ke-reset ke "tenant sendiri" (yang notabene kosong/null buat WP admin)
// tiap kali pindah menu atau abis submit form.
$store_query_suffix = ( ! empty($tenant_slug) ) ? '?store=' . rawurlencode($tenant_slug) : '';

$is_store_online = (isset($current_tenant['status']) && $current_tenant['status'] == 'Active');
$features        = isset($current_tenant['features']) ? $current_tenant['features'] : array();

$has_calc        = ($is_store_online && in_array('calculator', $features));
$has_public_calc = ($is_store_online && in_array('public_calc', $features));
$has_payment     = ($is_store_online && in_array('customer_payment', $features));
$has_po          = ($is_store_online && in_array('po_recap', $features));
$has_tagihan     = ($is_store_online && in_array('tagihan_recap', $features));
$has_wh          = ($is_store_online && in_array('warehouse', $features));

$payments_table  = $goweb_tables['payments'];

$created_date_str = isset($current_tenant['created_at']) ? $current_tenant['created_at'] : date('d/m/Y');
$created_timestamp = strtotime(str_replace('/', '-', $created_date_str));
// Pakai active_until (diperpanjang otomatis tiap perpanjangan di-verify —
// lihat handler pembayaran di bawah), bukan created_at+duration mentah yang
// sebelumnya gak pernah ke-update sama sekali walau tenant rajin bayar.
$due_timestamp = goweb_get_tenant_active_until( $current_tenant );
$remaining_days = ceil(($due_timestamp - time()) / 86400);
if ($remaining_days < 0) $remaining_days = 0;

// Level urgensi masa aktif — dipakai buat warna & banner peringatan.
// Penting sekarang karena kebijakan auto-offline H+1 (lewat jatuh tempo
// + 1 hari langsung offline & data kehapus), jadi tenant perlu tanda
// visual yang jelas SEBELUM itu kejadian, bukan baru sadar pas udah telat.
if ( $remaining_days <= 0 ) {
    $active_urgency = 'critical'; // udah lewat jatuh tempo / offline
} elseif ( $remaining_days <= 3 ) {
    $active_urgency = 'critical';
} elseif ( $remaining_days <= 7 ) {
    $active_urgency = 'warning';
} else {
    $active_urgency = 'safe';
}
$active_urgency_color = array( 'safe' => 'var(--pastel-mint)', 'warning' => '#d97706', 'critical' => '#ef4444' )[ $active_urgency ];

$orders_table = $goweb_tables['orders'];

// CATATAN: fitur auto-archive/hapus PO "Done" >90 hari yang dulu ada di
// sini udah DICABUT. Alasannya: storage kepake ternyata jauh di bawah
// limit hosting (lihat panel Kesehatan Database di Super Admin), jadi
// alasan "biar database gak numpuk" udah gak relevan — dan fitur ini
// dulu jadi SATU-SATUNYA sumber invoice/tagihan "orphan" (nempel ke
// order yang udah gak ada) di seluruh sistem, soalnya cuma hapus order-
// nya doang tanpa nyentuh tagihan yang masih nempel di situ. Sekarang
// satu-satunya cara data kehapus itu lewat penghapusan tenant SELURUHNYA
// (goweb_delete_tenant_completely — telat bayar H+2 atau retensi 1
// tahun), yang nyapu semua tabel bareng2, jadi gak akan ada lagi yang
// nyisa setengah-setengah kayak gini.

// ----------------------------------------------------
// HANDLER REKAPAN ORDER PO (tambah / ubah status / hapus)
// Modul ini SEBELUMNYA 100% client-side JS — data ilang tiap reload
// halaman, gak pernah beneran tersambung ke database sama sekali.
// Dibangun di sini (sebelum get_header(), pola sama kayak handler
// pembayaran & keuangan di atas).
// ----------------------------------------------------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['goweb_po_nonce']) ) {
    if ( ! wp_verify_nonce($_POST['goweb_po_nonce'], 'goweb_po_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }

    if ( isset($_POST['action_add_order']) ) {
        $po_date = isset($_POST['po_date']) ? sanitize_text_field($_POST['po_date']) : '';
        $wpdb->insert( $orders_table, array(
            'store_id'       => $store_id,
            'batch'          => sanitize_text_field( $_POST['po_batch'] ?? '' ),
            'buyer_name'     => sanitize_text_field( $_POST['po_buyer_name'] ?? '' ),
            'item_detail'    => sanitize_textarea_field( $_POST['po_item_detail'] ?? '' ),
            'qty'            => 1,
            'order_status'   => sanitize_text_field( $_POST['po_status'] ?? '' ),
            'created_at'     => ( $po_date !== '' ) ? $po_date . ' 00:00:00' : current_time('mysql'),
        ) );
    } elseif ( isset($_POST['action_update_status']) ) {
        $wpdb->update( $orders_table,
            array( 'order_status' => sanitize_text_field( $_POST['new_status'] ?? '' ) ),
            array( 'id' => intval($_POST['order_id']), 'store_id' => $store_id )
        );
    } elseif ( isset($_POST['action_delete_order']) ) {
        $wpdb->delete( $orders_table, array( 'id' => intval($_POST['order_id']), 'store_id' => $store_id ) );
    }

    wp_redirect( home_url('/dashboard/po/') . $store_query_suffix );
    exit;
}

$tenant_orders_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$orders_table} WHERE store_id = %s ORDER BY created_at DESC, id DESC", $store_id
), ARRAY_A );

// ----------------------------------------------------
// HANDLER REKAPAN TAGIHAN (tambah / toggle status / hapus)
// Terpisah dari Rekapan PO — 1 PO/order bisa punya banyak baris tagihan
// (DP, Ongkir WH, Ongkir Kargo, Pajak, Packing, Pelunasan, dll), masing2
// status bayarnya MANUAL, gak ke-link otomatis ke Konfirmasi Pembayaran.
// ----------------------------------------------------
$invoices_table = $goweb_tables['invoices'];

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['goweb_invoice_nonce']) ) {
    if ( ! wp_verify_nonce($_POST['goweb_invoice_nonce'], 'goweb_invoice_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }

    if ( isset($_POST['action_add_invoice']) ) {
        $inv_order_id = intval($_POST['inv_order_id']);
        // Pastikan order yg dipilih beneran milik tenant ini (ownership check)
        $order_owned = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders_table} WHERE id = %d AND store_id = %s", $inv_order_id, $store_id
        ) );
        if ( $order_owned ) {
            $wpdb->insert( $invoices_table, array(
                'store_id'   => $store_id,
                'order_id'   => $inv_order_id,
                'category'   => sanitize_text_field( $_POST['inv_category'] ?? 'lainnya' ),
                'nominal'    => isset($_POST['inv_nominal']) ? intval($_POST['inv_nominal']) : 0,
                'status'     => 'Belum Bayar',
                'created_at' => current_time('mysql'),
            ) );
        }
    } elseif ( isset($_POST['action_toggle_invoice_status']) ) {
        $invoice_id = intval($_POST['invoice_id']);
        $inv_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$invoices_table} WHERE id = %d AND store_id = %s", $invoice_id, $store_id
        ), ARRAY_A );
        if ( $inv_row ) {
            $new_status = ( $inv_row['status'] === 'Lunas' ) ? 'Belum Bayar' : 'Lunas';
            $wpdb->update( $invoices_table, array( 'status' => $new_status ), array( 'id' => $invoice_id, 'store_id' => $store_id ) );
        }
    } elseif ( isset($_POST['action_delete_invoice']) ) {
        $wpdb->delete( $invoices_table, array( 'id' => intval($_POST['invoice_id']), 'store_id' => $store_id ) );
    }

    wp_redirect( home_url('/dashboard/tagihan/') . $store_query_suffix );
    exit;
}

$tenant_invoices_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT i.*, o.batch, o.buyer_name FROM {$invoices_table} i
     LEFT JOIN {$orders_table} o ON i.order_id = o.id
     WHERE i.store_id = %s ORDER BY i.created_at DESC, i.id DESC", $store_id
), ARRAY_A );

// ----------------------------------------------------
// HANDLER DATABASE WAREHOUSE (tambah / ubah status / isi resi keluar / hapus)
// Tracking fisik barang di gudang — beda dari order_status di Rekapan PO
// yang cuma garis besar. Tenant bisa pilih mode "wajib link ke PO" atau
// "bebas berdiri sendiri" lewat Pengaturan (wh_link_mode).
// ----------------------------------------------------
$warehouse_table = $goweb_tables['warehouse'];
$box_table       = $goweb_tables['wh_boxes'];
$wh_link_mode = goweb_get_tenant_setting( $store_id, 'wh_link_mode', 'free' );
$wh_statuses  = goweb_get_tenant_setting( $store_id, 'wh_statuses', goweb_get_default_warehouse_statuses() );
$box_statuses = goweb_get_tenant_setting( $store_id, 'box_statuses', goweb_get_default_box_statuses() );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['goweb_wh_nonce']) ) {
    if ( ! wp_verify_nonce($_POST['goweb_wh_nonce'], 'goweb_wh_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }

    if ( isset($_POST['action_add_warehouse']) ) {
        $wh_order_id   = null;
        $wh_buyer_name = '';
        $wh_blocked    = false;

        if ( $wh_link_mode === 'linked' ) {
            $candidate_order_id = intval($_POST['wh_order_id'] ?? 0);
            // Ownership check — pastikan order yg dipilih beneran milik tenant ini
            $order_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$orders_table} WHERE id = %d AND store_id = %s", $candidate_order_id, $store_id
            ), ARRAY_A );
            if ( $order_row ) {
                $wh_order_id   = $candidate_order_id;
                $wh_buyer_name = $order_row['buyer_name'];
            } else {
                // Mode "wajib link ke PO" tapi order-nya gak valid/gak ketemu —
                // TOLAK TEGAS, jangan diam-diam bikin entri kosong tanpa nama
                // pemesan & tanpa keterkaitan order (celah kalau ada yg
                // ngelewatin form normal / JS-nya dimatiin).
                $wh_blocked = true;
            }
        } else {
            $wh_buyer_name = sanitize_text_field( $_POST['wh_buyer_name'] ?? '' );
        }

        if ( $wh_blocked ) {
            wp_die('Gagal simpan: mode "wajib pilih dari Rekapan PO" aktif, tapi order yang dipilih tidak valid atau bukan milik toko ini. Silakan pilih ulang dari daftar PO yang tersedia.');
        }

        // Upload foto bukti kondisi paket (opsional)
        $wh_foto_url = '';
        if ( ! empty($_FILES['wh_foto_bukti']['name']) ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            $upload_overrides = array( 'test_form' => false, 'mimes' => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ) );
            $uploaded = wp_handle_upload($_FILES['wh_foto_bukti'], $upload_overrides);
            if ( isset($uploaded['url']) && isset($uploaded['file']) ) {
                $image_editor = wp_get_image_editor( $uploaded['file'] );
                if ( ! is_wp_error( $image_editor ) ) {
                    $image_editor->resize( 800, 1200, false );
                    $image_editor->set_quality( 70 );
                    $image_editor->save( $uploaded['file'] );
                }
                $wh_foto_url = esc_url_raw( $uploaded['url'] );
            }
        }

        $wh_date = isset($_POST['wh_tanggal_masuk']) ? sanitize_text_field($_POST['wh_tanggal_masuk']) : '';
        $wpdb->insert( $warehouse_table, array(
            'store_id'      => $store_id,
            'order_id'      => $wh_order_id,
            'buyer_name'    => $wh_buyer_name,
            'resi_masuk'    => sanitize_text_field( $_POST['wh_resi_masuk'] ?? '' ),
            'slot'          => sanitize_text_field( $_POST['wh_slot'] ?? '' ),
            'lokasi_wh'     => sanitize_text_field( $_POST['wh_lokasi'] ?? '' ),
            'foto_bukti'    => $wh_foto_url,
            'berat'         => isset($_POST['wh_berat']) ? floatval($_POST['wh_berat']) : 0,
            'status'        => ! empty($wh_statuses) ? $wh_statuses[0] : 'Menunggu',
            'tanggal_masuk' => $wh_date !== '' ? $wh_date : current_time('Y-m-d'),
            'created_at'    => current_time('mysql'),
        ) );
    } elseif ( isset($_POST['action_update_wh_status']) ) {
        $wpdb->update( $warehouse_table,
            array( 'status' => sanitize_text_field( $_POST['new_status'] ?? '' ) ),
            array( 'id' => intval($_POST['wh_id']), 'store_id' => $store_id )
        );
    } elseif ( isset($_POST['action_delete_warehouse']) ) {
        $wpdb->delete( $warehouse_table, array( 'id' => intval($_POST['wh_id']), 'store_id' => $store_id ) );

    // --- HANDLER BOX KONSOLIDASI ---
    } elseif ( isset($_POST['action_create_box']) ) {
        $wpdb->insert( $box_table, array(
            'store_id'     => $store_id,
            'box_name'     => sanitize_text_field( $_POST['box_name'] ?? '' ),
            'lokasi'       => sanitize_text_field( $_POST['box_lokasi'] ?? '' ),
            'kapasitas_kg' => isset($_POST['box_kapasitas']) ? floatval($_POST['box_kapasitas']) : 20,
            'status'       => ! empty($box_statuses) ? $box_statuses[0] : 'Packing In Progress',
            'created_at'   => current_time('mysql'),
        ) );
    } elseif ( isset($_POST['action_update_box_status']) ) {
        $wpdb->update( $box_table,
            array( 'status' => sanitize_text_field( $_POST['new_status'] ?? '' ) ),
            array( 'id' => intval($_POST['box_id']), 'store_id' => $store_id )
        );
    } elseif ( isset($_POST['action_delete_box']) ) {
        $box_id_del = intval($_POST['box_id']);
        // Lepasin dulu semua item yg nempel ke Box ini (jangan ikut kehapus)
        $wpdb->update( $warehouse_table, array( 'box_id' => null ), array( 'box_id' => $box_id_del, 'store_id' => $store_id ) );
        $wpdb->delete( $box_table, array( 'id' => $box_id_del, 'store_id' => $store_id ) );
    } elseif ( isset($_POST['action_assign_to_box']) ) {
        $assign_box_id = intval($_POST['assign_box_id'] ?? 0);
        // Ownership check box (kalau bukan "lepas dari box", assign_box_id = 0)
        $box_owned = $assign_box_id === 0 || $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$box_table} WHERE id = %d AND store_id = %s", $assign_box_id, $store_id
        ) );
        if ( $box_owned ) {
            $wpdb->update( $warehouse_table,
                array( 'box_id' => $assign_box_id > 0 ? $assign_box_id : null ),
                array( 'id' => intval($_POST['wh_id']), 'store_id' => $store_id )
            );
        }
    }

    wp_redirect( home_url('/dashboard/warehouse/') . $store_query_suffix );
    exit;
}

$tenant_warehouse_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT w.*, o.batch FROM {$warehouse_table} w
     LEFT JOIN {$orders_table} o ON w.order_id = o.id
     WHERE w.store_id = %s ORDER BY w.created_at DESC, w.id DESC", $store_id
), ARRAY_A );

$tenant_boxes_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$box_table} WHERE store_id = %s ORDER BY created_at DESC", $store_id
), ARRAY_A );
foreach ( $tenant_boxes_rows as &$box_row ) {
    $box_row['filled_kg'] = goweb_calculate_box_filled_weight( $box_row['id'] );
}
unset( $box_row );

// HITUNG PERSENTASE STORAGE UNTUK SIDEBAR — sekarang dihitung LIVE dari
// semua data tenant di database (bukan cuma file upload), lihat
// goweb_calculate_tenant_storage_bytes() di functions.php. Disimpan di
// $tenant_storage_bytes (byte mentah) biar menu-subscription.php bisa
// pakai ulang nilai ini, gak perlu ngitung ulang (5 query + baca file
// disk lagi) di halaman yang sama.
$limit_mb = isset($current_tenant['storage_limit']) ? floatval($current_tenant['storage_limit']) : 250;
$tenant_storage_bytes = goweb_calculate_tenant_storage_bytes( $store_id );
$used_mb = round( $tenant_storage_bytes / 1048576, 2 );
$storage_pct = ($limit_mb > 0) ? min(100, round(($used_mb / $limit_mb) * 100)) : 0;

// Tangkap sub-halaman dari URL
$request_uri_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
$path_segments = explode( '/', $request_uri_path );
$dash_index = array_search( 'dashboard', $path_segments );
$current_subpage = 'overview';
if ( $dash_index !== false && isset( $path_segments[$dash_index + 1] ) ) {
    $current_subpage = sanitize_text_field( $path_segments[$dash_index + 1] );
}

// ----------------------------------------------------
// HANDLER VERIFIKASI PEMBAYARAN PELANGGAN DI ADMIN.PHP
// Diganti dari GET (rentan CSRF, bisa dipicu tanpa login) menjadi POST + nonce.
// Ownership sudah dijamin oleh blok auth check di atas ($current_tenant hanya
// bisa terisi tenant milik sendiri atau, untuk super admin, tenant manapun).
// ----------------------------------------------------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['goweb_payment_action']) && isset($_POST['index']) ) {
    if ( ! isset($_POST['goweb_payment_nonce']) || ! wp_verify_nonce($_POST['goweb_payment_nonce'], 'goweb_payment_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }
    // CATATAN: field 'index' sekarang berisi ID baris asli dari tabel
    // wp_goweb_payments (dulu: index array di wp_options). WHERE store_id
    // WAJIB ada di tiap query — tabel ini sekarang dipakai bareng SEMUA
    // tenant, jadi harus dipastikan baris yg diutak-atik beneran milik
    // tenant yg sedang login (bukan cuma tebak2an dari ID doang).
    $payment_id = intval($_POST['index']);
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$payments_table} WHERE id = %d AND store_id = %s", $payment_id, $store_id
    ), ARRAY_A );

    if ( $row ) {
        // Pembayaran kategori 'subscription' (perpanjangan sewa ke platform)
        // cuma boleh diverifikasi/dihapus oleh SUPER ADMIN — itu duitnya
        // masuk ke pemilik platform, bukan ke tenant itu sendiri. Tenant
        // gak boleh approve pembayaran sewanya sendiri. Ini proteksi
        // server-side; tombolnya sendiri udah dihapus dari UI tenant
        // (menu-subscription.php), verifikasi beneran dilakukan lewat
        // Super Admin panel.
        if ( $row['payment_category'] === 'subscription' && ! $is_super_admin ) {
            wp_die('Akses ditolak. Verifikasi pembayaran perpanjangan sewa hanya bisa dilakukan oleh Super Admin.');
        }

        if ( $_POST['goweb_payment_action'] === 'verify' ) {
            $wpdb->update( $payments_table, array( 'status' => 'Verified' ), array( 'id' => $payment_id, 'store_id' => $store_id ) );

            // Kalau entri ini perpanjangan sewa (bukan pembayaran PO — sekarang
            // dibedakan lewat kolom payment_category, bukan tebak string 'PO:'
            // dari field period kayak sebelumnya), perpanjang active_until
            // tenant SESUAI DURASI yang diajukan — atau kalau ini upgrade
            // (requested_package keisi), ganti paket TANPA ubah active_until
            // (tenant udah bayar cuma buat "nyampein" ke paket baru buat
            // sisa hari yang ada, bukan beli hari tambahan).
            if ( $row['payment_category'] === 'subscription' ) {
                $tenant_row_renew = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$tenants_table} WHERE tenant_id = %s", $store_id
                ), ARRAY_A );
                if ( $tenant_row_renew ) {
                    $current_active_until = goweb_get_tenant_active_until( $tenant_row_renew );
                    $is_upgrade = ! empty( $row['requested_package'] );
                    $update_data = array( 'status' => 'Active' );

                    if ( $is_upgrade ) {
                        $target_package = goweb_get_package( $row['requested_package'] );
                        $update_data['package_key']     = $target_package['key'];
                        $update_data['storage_limit']   = $target_package['storage_limit'];
                        $update_data['cycle_daily_rate'] = round( $target_package['price'] / 30, 2 );
                    } else {
                        $current_tenant_package_local = goweb_get_package( isset($tenant_row_renew['package_key']) ? $tenant_row_renew['package_key'] : 'starter' );
                        $duration_months = ! empty( $row['requested_duration_months'] ) ? intval( $row['requested_duration_months'] ) : 1;
                        $duration_days = $duration_months * 30;
                        $base = max( time(), $current_active_until ); // perpanjangan lebih awal dari jatuh tempo tetap dihitung dari sisa waktu yg ada
                        $update_data['active_until'] = $base + ( $duration_days * DAY_IN_SECONDS );

                        $remaining_days_before = max( 0, ceil( ( $current_active_until - time() ) / DAY_IN_SECONDS ) );
                        $old_rate = isset($tenant_row_renew['cycle_daily_rate']) && floatval($tenant_row_renew['cycle_daily_rate']) > 0
                            ? floatval($tenant_row_renew['cycle_daily_rate'])
                            : ( $current_tenant_package_local['price'] / 30 );
                        $value_remaining_before = $old_rate * $remaining_days_before;
                        $total_days_after = $remaining_days_before + $duration_days;
                        $update_data['cycle_daily_rate'] = $total_days_after > 0
                            ? round( ( $value_remaining_before + floatval($row['nominal']) ) / $total_days_after, 2 )
                            : round( floatval($row['nominal']) / max(1, $duration_days), 2 );
                    }

                    $wpdb->update( $tenants_table, $update_data, array( 'tenant_id' => $store_id ) );
                }
            }
        } elseif ( $_POST['goweb_payment_action'] === 'delete' ) {
            $wpdb->delete( $payments_table, array( 'id' => $payment_id, 'store_id' => $store_id ) );
        }
    }
    wp_redirect( home_url('/dashboard/payments/') . $store_query_suffix );
    exit;
}

$subscription_history = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$payments_table} WHERE store_id = %s ORDER BY created_at DESC", $store_id
), ARRAY_A );
// $pending_count SEKARANG cuma ngitung pembayaran kategori PO — dipakai
// buat badge menu "Pembayaran Pelanggan" & stat overview. SEBELUMNYA
// ngitung SEMUA kategori (termasuk perpanjangan sewa), jadi badge bisa
// nunjukin "1 Pending" padahal tabel Pembayaran Pelanggan kosong (yang
// pending itu perpanjangan sewa, beda menu).
$pending_count = 0;
$pending_subscription_count = 0;
foreach ( $subscription_history as $h ) {
    if ( isset($h['status']) && $h['status'] == 'Pending' ) {
        if ( isset($h['payment_category']) && $h['payment_category'] === 'po' ) {
            $pending_count++;
        } elseif ( isset($h['payment_category']) && $h['payment_category'] === 'subscription' ) {
            $pending_subscription_count++;
        }
    }
}

// STAT OVERVIEW "RINGKASAN TOKO" — dihitung dari data asli (Rekapan PO &
// Database Warehouse), BUKAN angka dummy hardcode kayak sebelumnya
// ("12 Batch", "48 Item", dst yang gak pernah kesambung ke database).
$saved_order_statuses = goweb_get_tenant_setting( $store_id, 'custom_statuses', array("Antrean", "On Process", "Arrived WH", "Shipped Indo", "Done") );
$last_order_status = end( $saved_order_statuses ); // status terakhir dianggap "selesai", posisinya bisa beda2 tiap tenant (custom)

$stat_po_aktif = 0;
foreach ( $tenant_orders_rows as $o ) {
    if ( $o['order_status'] !== $last_order_status ) { $stat_po_aktif++; }
}

// "Sampai WH" = total item yang pernah tercatat masuk Database Warehouse
$stat_barang_wh = count( $tenant_warehouse_rows );

// "Jalan ke Indo" / "Sampai Indo" ditentuin dari status BOX (bukan status
// item warehouse individual) — soalnya pengiriman ke Indonesia itu levelnya
// per-Box (konsolidasi), bukan per-item. Dicocokkan lewat KATA KUNCI di
// nama status (BUKAN posisi ke-berapa di daftar) — status Box itu bebas
// diatur nama & urutannya sendiri sama tenant, jadi nebak posisi gak akan
// pernah akurat (sama kayak bug yang sebelumnya ada di stat "Sedang di
// WH/Kargo" punya Rekapan PO).
$WH_TRANSIT_KEYWORDS = array( 'jalan', 'transit', 'otw', 'menuju' );
$WH_ARRIVED_KEYWORDS = array( 'sampai', 'tiba', 'arrived' );

$stat_jalan_ke_indo = 0;
$stat_sampai_indo   = 0;
foreach ( $tenant_warehouse_rows as $wh ) {
    if ( empty( $wh['box_id'] ) ) continue;
    foreach ( $tenant_boxes_rows as $box ) {
        if ( $box['id'] == $wh['box_id'] ) {
            $box_status_lower = mb_strtolower( $box['status'] );
            foreach ( $WH_TRANSIT_KEYWORDS as $kw ) {
                if ( strpos( $box_status_lower, $kw ) !== false ) { $stat_jalan_ke_indo++; break; }
            }
            foreach ( $WH_ARRIVED_KEYWORDS as $kw ) {
                if ( strpos( $box_status_lower, $kw ) !== false ) { $stat_sampai_indo++; break; }
            }
            break;
        }
    }
}

// DATA BUAT 3 GRAFIK ANALITIK DI RINGKASAN TOKO — semua dihitung dari
// data yang UDAH di-fetch di atas ($tenant_orders_rows, $subscription_history),
// gak nambah query baru sama sekali.
$MONTH_LABELS_ID = array('Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des');

// 1. TREN VOLUME ORDER (12 bulan terakhir) — jumlah baris order per bulan
$chart_months = array();
for ( $i = 11; $i >= 0; $i-- ) {
    $ts = strtotime( "-$i months", current_time('timestamp') );
    $chart_months[ date('Y-m', $ts) ] = $MONTH_LABELS_ID[ intval(date('n', $ts)) - 1 ] . " '" . date('y', $ts);
}
$chart_volume_data = array_fill_keys( array_keys($chart_months), 0 );
foreach ( $tenant_orders_rows as $o ) {
    $ym = date( 'Y-m', strtotime( $o['created_at'] ) );
    if ( isset( $chart_volume_data[$ym] ) ) { $chart_volume_data[$ym]++; }
}

// 2. TREN OMSET DARI CUSTOMER (12 bulan terakhir) — total nominal
// Konfirmasi Pembayaran kategori PO yang UDAH Verified. Ini duit yang
// bener2 masuk dari customer — BEDA dari Laporan Keuangan internal
// (yang nyatet modal/belanja supplier kamu sendiri, bukan pemasukan ini).
$chart_omset_data = array_fill_keys( array_keys($chart_months), 0 );
foreach ( $subscription_history as $h ) {
    if ( isset($h['payment_category']) && $h['payment_category'] === 'po' && $h['status'] === 'Verified' ) {
        $ym = date( 'Y-m', strtotime( $h['created_at'] ) );
        if ( isset( $chart_omset_data[$ym] ) ) { $chart_omset_data[$ym] += floatval($h['nominal']); }
    }
}

// 3. DISTRIBUSI STATUS ORDER (saat ini) — jumlah order per status,
// urutannya ngikutin daftar status custom tenant (bukan acak)
$chart_status_data = array_fill_keys( $saved_order_statuses, 0 );
foreach ( $tenant_orders_rows as $o ) {
    if ( isset( $chart_status_data[ $o['order_status'] ] ) ) {
        $chart_status_data[ $o['order_status'] ]++;
    }
}
// Buang status yang jumlahnya 0 biar pie chart gak penuh label kosong
$chart_status_data = array_filter( $chart_status_data, function($v) { return $v > 0; } );


// ditulis oleh halaman Pengaturan sama sekali, jadi kalkulator ini selalu
// nampilin kurs default apapun yang diubah admin di Pengaturan.
$active_rates = goweb_get_tenant_setting( $store_id, 'rates', array('CNY' => 2450, 'JPY' => 110, 'THB' => 460, 'KRW' => 12, 'USD' => 16200) );
$active_currency_cargo = goweb_get_tenant_setting( $store_id, 'currency_cargo', goweb_get_default_currency_cargo() );
$active_packing_fee = goweb_get_tenant_setting( $store_id, 'packing_fee', 5000 );
$ledger_table  = $goweb_tables['ledger'];
$wallets_table = $goweb_tables['wallets'];

// ----------------------------------------------------
// HANDLER LAPORAN KEUANGAN (simpan mutasi / hapus mutasi / reset semua)
// Ditaruh di sini (sebelum get_header()) karena menu-finance.php di-include
// SETELAH get_header() sudah mengeluarkan HTML — wp_redirect()/header() di
// titik itu tidak akan berfungsi ("headers already sent").
// ----------------------------------------------------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_finance']) ) {
    if ( ! isset($_POST['goweb_finance_nonce']) || ! wp_verify_nonce($_POST['goweb_finance_nonce'], 'goweb_finance_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }

    $fin_type   = sanitize_text_field($_POST['fin_type'] ?? '');
    $fin_date   = sanitize_text_field($_POST['fin_date'] ?? date('Y-m-d'));
    $fin_wallet = sanitize_text_field($_POST['fin_wallet_name'] ?? '-');
    $fin_title  = sanitize_text_field($_POST['fin_title'] ?? '');
    $fin_amount = isset($_POST['fin_amount']) ? floatval($_POST['fin_amount']) : 0;
    $fin_idr    = isset($_POST['fin_idr']) ? floatval($_POST['fin_idr']) : 0;
    $fin_rate   = 0;

    $valid_types = array('wallet_topup', 'supplier_purchase', 'cargo_1', 'cargo_2', 'operational');
    if ( in_array($fin_type, $valid_types, true) && $fin_date !== '' ) {

        // Belanja Supplier: "Total Rupiah (IDR)" GAK diketik manual lagi —
        // dihitung otomatis dari rata-rata rate top-up dompet yang sama
        // (weighted average dari histori Top-Up Saldo dompet ini), biar
        // konsisten sama cost basis-nya sendiri, bukan angka bebas.
        if ( $fin_type === 'supplier_purchase' ) {
            $topup_sums = $wpdb->get_row( $wpdb->prepare(
                "SELECT SUM(idr_value) AS total_idr, SUM(amount) AS total_amount
                 FROM {$ledger_table} WHERE store_id = %s AND wallet_name = %s AND tx_type = 'wallet_topup'",
                $store_id, $fin_wallet
            ), ARRAY_A );
            $topup_total_amount = $topup_sums ? floatval($topup_sums['total_amount']) : 0;
            if ( $topup_total_amount <= 0 ) {
                wp_die('Gagal simpan: dompet "' . esc_html($fin_wallet) . '" belum pernah di-Top Up, jadi rate-nya belum bisa dihitung. Lakukan Top-Up Saldo dulu buat dompet ini sebelum mencatat Belanja Supplier.');
            }
            $fin_rate = floatval($topup_sums['total_idr']) / $topup_total_amount;
            $fin_idr  = round( $fin_amount * $fin_rate );
        } elseif ( $fin_amount > 0 ) {
            // Tipe lain (wallet_topup, cargo, operational) — IDR-nya emang
            // diketik manual (uang asli yg dikeluarkan), tapi kolom rate
            // ikut dihitung dari situ buat jadi acuan wallet_topup selanjutnya.
            $fin_rate = $fin_idr / $fin_amount;
        }

        // Update saldo dompet digital untuk top-up / belanja supplier.
        // Pakai INSERT ... ON DUPLICATE KEY UPDATE (dompet ini punya
        // UNIQUE KEY store_id+wallet_name) biar nambah/kurangin saldo
        // dalam 1 query atomik, gak perlu SELECT dulu baru UPDATE.
        if ( $fin_wallet !== '-' && in_array( $fin_type, array('wallet_topup', 'supplier_purchase'), true ) ) {
            $delta = ( $fin_type === 'wallet_topup' ) ? $fin_amount : -$fin_amount;
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wallets_table} (store_id, wallet_name, balance) VALUES (%s, %s, %f)
                 ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)",
                $store_id, $fin_wallet, $delta
            ) );
        }

        $wpdb->insert( $ledger_table, array(
            'store_id'   => $store_id,
            'tx_date'    => $fin_date,
            'tx_type'    => $fin_type,
            'wallet_name'=> $fin_wallet,
            'title'      => $fin_title,
            'amount'     => $fin_amount,
            'rate'       => $fin_rate,
            'idr_value'  => $fin_idr,
            'created_at' => current_time('mysql'),
        ) );

        wp_redirect( home_url('/dashboard/finance/') . $store_query_suffix );
        exit;
    }
}

// HANDLER HAPUS SATU MUTASI (link "✕" di tabel ledger)
// CATATAN: 'del_fin' berisi ID baris asli dari tabel ledger. Saldo dompet
// SEKARANG dikoreksi balik otomatis pas mutasi dihapus — sebelumnya cuma
// hapus baris ledger doang, saldo dompet gak ikut ke-update (bug: hapus
// top-up Rp1jt, saldo dompetnya tetep nambah Rp1jt selamanya).
if ( isset($_GET['del_fin']) && isset($_GET['goweb_tenant_id']) && $_GET['goweb_tenant_id'] == $store_id ) {
    if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'goweb_finance_delete_' . $store_id) ) {
        wp_die('Link kedaluwarsa atau tidak valid.');
    }
    $del_id = intval($_GET['del_fin']);
    $tx_to_delete = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$ledger_table} WHERE id = %d AND store_id = %s", $del_id, $store_id
    ), ARRAY_A );

    if ( $tx_to_delete ) {
        // Balikin efek mutasi ini ke saldo dompet sebelum baris ledgernya dihapus
        if ( $tx_to_delete['wallet_name'] !== '-' && in_array( $tx_to_delete['tx_type'], array('wallet_topup', 'supplier_purchase'), true ) ) {
            $reverse_delta = ( $tx_to_delete['tx_type'] === 'wallet_topup' ) ? -floatval($tx_to_delete['amount']) : floatval($tx_to_delete['amount']);
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wallets_table} (store_id, wallet_name, balance) VALUES (%s, %s, %f)
                 ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)",
                $store_id, $tx_to_delete['wallet_name'], $reverse_delta
            ) );
        }
        $wpdb->delete( $ledger_table, array( 'id' => $del_id, 'store_id' => $store_id ) );
    }
    wp_redirect( home_url('/dashboard/finance/') . $store_query_suffix );
    exit;
}

// HANDLER RESET SELURUH DATA KEUANGAN TENANT
// CATATAN: parameter penanda tenant sengaja dinamain 'goweb_tenant_id'
// (bukan 'store_id') — waktu masih pakai nama 'store_id', parameter itu
// hilang di tengah jalan (kemungkinan ke-strip layer hCDN/cache karena
// kebentrok nama generik), padahal parameter lain di URL yang sama
// (reset_all_finance, _wpnonce) tetap nyampe utuh.
if ( isset($_GET['reset_all_finance']) && isset($_GET['goweb_tenant_id']) && $_GET['goweb_tenant_id'] == $store_id ) {
    if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'goweb_finance_reset_' . $store_id) ) {
        wp_die('Link kedaluwarsa atau tidak valid.');
    }
    $wpdb->delete( $ledger_table, array( 'store_id' => $store_id ) );
    $wpdb->delete( $wallets_table, array( 'store_id' => $store_id ) );
    wp_redirect( home_url('/dashboard/finance/') . $store_query_suffix );
    exit;
}

$finance_ledger_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$ledger_table} WHERE store_id = %s ORDER BY tx_date DESC, id DESC", $store_id
), ARRAY_A );
$active_wallets_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT wallet_name, balance FROM {$wallets_table} WHERE store_id = %s", $store_id
), ARRAY_A );
$active_wallets = array();
foreach ( $active_wallets_rows as $w ) {
    $active_wallets[ $w['wallet_name'] ] = floatval( $w['balance'] );
}

// Rata-rata rate top-up per dompet (weighted average) — dipakai buat
// nampilin preview "Total Rupiah (IDR)" estimasi di form Belanja Supplier.
// Perhitungan RESMI/final tetap dilakukan ulang di dalam handler POST
// (lihat action_save_finance), soalnya kode di sini jalan SETELAH handler
// POST redirect+exit, jadi gak bisa dipakai buat validasi submit itu sendiri.
$wallet_avg_rates = array();
$wallet_topup_sums = array();
foreach ( $finance_ledger_rows as $tx ) {
    if ( $tx['tx_type'] === 'wallet_topup' && floatval($tx['amount']) > 0 ) {
        $wn = $tx['wallet_name'];
        if ( ! isset($wallet_topup_sums[$wn]) ) $wallet_topup_sums[$wn] = array('idr' => 0, 'amount' => 0);
        $wallet_topup_sums[$wn]['idr']    += floatval($tx['idr_value']);
        $wallet_topup_sums[$wn]['amount'] += floatval($tx['amount']);
    }
}
foreach ( $wallet_topup_sums as $wn => $sums ) {
    if ( $sums['amount'] > 0 ) {
        $wallet_avg_rates[$wn] = $sums['idr'] / $sums['amount'];
    }
}

$current_real_year = date('Y');
$current_real_month = date('n');

get_header();
?>
<style>
        :root {
            --pastel-purple-bg: #f5f3ff;
            --pastel-purple-border: #ddd6fe;
            --pastel-purple: #8b5cf6;
            --pastel-mint: #10b981;
            --border-soft: #e5e7eb;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --radius-md: 12px;
            --radius-lg: 16px;
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
            --border: #e2e8f0;
            --emerald: #10b981;
            --emerald-bg: #dcfce7;
            --amber: #f59e0b;
            --amber-bg: #fef3c7;
            --rose: #ef4444;
            --rose-bg: #fee2e2;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #faf8ff; color: var(--text-dark); }
        body.admin-bar .dashboard-wrapper { min-height: calc(100vh - 32px - 70px); }
        @media (max-width: 600px) { body.admin-bar .dashboard-wrapper { min-height: auto; } }

        .dashboard-wrapper { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        @media (max-width: 900px) { .dashboard-wrapper { grid-template-columns: 1fr; } }

        .dash-sidebar { background: #ffffff; border-right: 1px solid var(--border-soft); padding: 24px; display: flex; flex-direction: column; gap: 24px; }
        .dash-user-card { background: var(--pastel-purple-bg); border: 1px solid var(--pastel-purple-border); padding: 16px; border-radius: var(--radius-md); }
        .dash-user-card h4 { font-size: 0.95rem; font-weight: 800; color: var(--text-dark); margin-bottom: 2px; }
        .dash-user-card p { font-size: 0.78rem; color: var(--pastel-purple); font-weight: 700; margin-bottom: 12px; }

        .dash-menu { display: flex; flex-direction: column; gap: 6px; }
        .dash-menu-item { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 0.88rem; font-weight: 700; color: var(--text-muted); background: transparent; border: none; cursor: pointer; text-align: left; width: 100%; transition: all 0.2s; text-decoration: none; }
        .dash-menu-item:hover { background: var(--pastel-purple-bg); color: var(--pastel-purple); }
        .dash-menu-item.active { background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; box-shadow: 0 4px 12px rgba(167, 139, 250, 0.3); }
        .dash-menu-item.disabled-menu { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

        .dash-main-content { padding: 32px; max-width: 100%; overflow-x: auto; }
        .stats-grid-6 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: 20px; box-shadow: 0 4px 12px rgba(139,92,246,0.03); }
        .stat-card span { font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .stat-card h3 { font-size: 1.35rem; font-weight: 800; color: var(--text-dark); margin-top: 4px; }
        
        .overview-status-box { background: #fff; border: 1px solid var(--border-soft); padding: 24px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(139,92,246,0.03); }
        .panel-title { font-size: 1.1rem; font-weight: 800; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; color: var(--text-dark); }
        .panel-desc { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px; }
        .info-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border-soft); font-size: 0.88rem; }
        .info-row:last-child { border-bottom: none; }
        
        .status-badge { padding: 5px 12px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-mint { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-peach { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        
        .form-control { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; background: #fff; outline: none; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .btn-submit { padding: 10px 20px; border-radius: var(--radius-md); background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 0.82rem; }
        
        .card-table-wrap { background: #ffffff; border: 1px solid var(--border); border-radius: 20px; padding: 24px; box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.03); }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; text-align: left; }
        .data-table th, .data-table td { padding: 14px 16px; border-bottom: 1px solid var(--border-soft); vertical-align: middle; }
        .data-table th { background: #f8fafc; font-weight: 800; color: var(--slate-700); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .data-table tr:hover { background: #fcfbff; }

        .curr-pill.active, .ship-pill.active, .tax-pill.active { background: var(--pastel-purple) !important; color: #fff !important; border-color: var(--pastel-purple) !important; box-shadow: 0 4px 12px rgba(139,92,246,0.25); }

        .fin-wrap { font-family: inherit; color: var(--text-dark); width: 100%; }
        .cycle-bar { background: #ffffff; border: 1px solid var(--border); border-radius: 20px; padding: 16px 22px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.03); }
        .cycle-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .cycle-title-label { font-size: 13px; font-weight: 800; color: var(--slate-700); }
        .cycle-select, .year-select { padding: 8px 14px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; color: var(--slate-900); background: var(--slate-50); outline: none; cursor: pointer; }
        .cycle-info-pill { background: #f1f5f9; padding: 8px 16px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--slate-700); border: 1px solid #e2e8f0; }

        .wallet-status-bar { background: #ffffff; border: 1px solid var(--border); border-radius: 16px; padding: 14px 20px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; box-shadow: 0 2px 8px rgba(15,23,42,0.02); }
        .wallet-pills-wrap { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .wallet-pill { background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; color: #0369a1; }

        .btn-export-csv { background: #15803d; color: #ffffff; border: none; padding: 8px 18px; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(21, 128, 61, 0.25); transition: 0.2s; }
        .btn-export-csv:hover { background: #166534; transform: translateY(-2px); }
        .btn-reset-data { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 8px 14px; border-radius: 10px; font-family: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-reset-data:hover { background: #fecaca; }

        .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        @media (max-width: 980px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 540px) { .metrics-grid { grid-template-columns: 1fr; } }
        .metric-card { background: #ffffff; border: 1px solid var(--border); border-radius: 20px; padding: 20px 22px; box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.03); border-left: 5px solid var(--slate-400); }
        .metric-card.green { border-left-color: var(--emerald); }
        .metric-card.blue { border-left-color: #3b82f6; }
        .metric-card.amber { border-left-color: var(--amber); }
        .metric-card.highlight { background: var(--slate-900); color: #ffffff; border: 1px solid #1e293b; border-left: 5px solid #38bdf8; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25); }
        .metric-label { font-size: 11.5px; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
        .metric-val { font-size: 24px; font-weight: 800; color: var(--slate-900); letter-spacing: -0.5px; }
        .metric-card.highlight .metric-val { color: #38bdf8; }
        .metric-sub { font-size: 12px; color: var(--slate-500); margin-top: 4px; font-weight: 600; }
        .metric-card.highlight .metric-sub { color: #94a3b8; }

        .roadmap-card { background: #ffffff; border: 1px solid var(--border); border-radius: 22px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.03); }
        .roadmap-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
        .roadmap-title { font-size: 14px; font-weight: 800; color: var(--slate-900); }
        .roadmap-legend { font-size: 11px; font-weight: 700; color: var(--slate-400); }
        .roadmap-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 8px; }
        @media (max-width: 900px) { .roadmap-grid { grid-template-columns: repeat(6, 1fr); } }
        @media (max-width: 480px) { .roadmap-grid { grid-template-columns: repeat(3, 1fr); } }
        .roadmap-item { background: var(--slate-50); border: 1px solid var(--border); border-radius: 12px; padding: 10px 4px; text-align: center; cursor: pointer; transition: 0.2s; }
        .roadmap-item:hover { transform: translateY(-2px); border-color: var(--primary); }
        .roadmap-item.active { border: 2px solid var(--primary); background: #f0f9ff; }
        .roadmap-month { font-size: 11px; font-weight: 800; color: var(--slate-700); margin-bottom: 2px; }
        .roadmap-icon { font-size: 13px; margin-bottom: 2px; }
        .roadmap-status-text { font-size: 10px; font-weight: 800; letter-spacing: 0.2px; }
        .roadmap-margin { font-size: 9.5px; color: var(--slate-500); font-weight: 700; margin-top: 2px; }

        .forms-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 24px; }
        @media (max-width: 920px) { .forms-grid { grid-template-columns: 1fr; } }
        .form-box { background: #ffffff; border: 1px solid var(--border); border-radius: 20px; padding: 22px; box-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.03); display: flex; flex-direction: column; justify-content: space-between; }
        .form-box-title { font-size: 14.5px; font-weight: 800; color: var(--slate-900); border-bottom: 1px dashed var(--border); padding-bottom: 10px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .form-group-fin { margin-bottom: 12px; }
        .form-label-fin { display: block; font-size: 12px; font-weight: 700; color: var(--slate-700); margin-bottom: 4px; }
        .form-input-fin, .form-select-fin { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 600; color: var(--slate-900); outline: none; background: #fff; }
        .form-input-fin:focus, .form-select-fin:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15); }
        .btn-submit-action { width: 100%; padding: 10px; border-radius: 10px; border: none; font-family: inherit; font-size: 13px; font-weight: 800; color: #ffffff; cursor: pointer; margin-top: 6px; transition: 0.2s; }
        .btn-blue { background: var(--primary); } .btn-blue:hover { background: var(--primary-dark); }
        .btn-green { background: #16a34a; } .btn-green:hover { background: #15803d; }
        .btn-amber { background: #d97706; } .btn-amber:hover { background: #b45309; }

        .ledger-card { background: #ffffff; border: 1px solid var(--border); border-radius: 22px; padding: 24px; box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.05); }
        .ledger-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-btn { background: var(--slate-100); border: 1px solid transparent; color: var(--slate-600); font-size: 12px; font-weight: 700; padding: 6px 14px; border-radius: 999px; cursor: pointer; transition: 0.2s; font-family: inherit; }
        .filter-btn:hover { background: #e2e8f0; }
        .filter-btn.active { background: var(--primary); color: #ffffff; }

        .badge-type { display: inline-block; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: 0.2px; text-transform: uppercase; }
        .type-topup { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .type-tmall { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .type-cargo1 { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .type-ops { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .btn-del-fin { background: transparent; border: none; color: var(--slate-400); cursor: pointer; font-size: 14px; padding: 4px 6px; border-radius: 6px; }
        .btn-del-fin:hover { color: var(--rose); background: #fee2e2; }
    </style>

<div class="dashboard-wrapper">
    <!-- SIDEBAR -->
    <aside class="dash-sidebar">
        <div class="dash-user-card">
            <h4><?php echo esc_html($store_name); ?></h4>
            <p><i class="fa-solid fa-circle-check"></i> Owner: <?php echo esc_html($admin_name); ?></p>
            <a href="<?php echo esc_url($store_url); ?>" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; padding: 8px 12px; background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; font-size: 0.78rem; font-weight: 700; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 10px rgba(167,139,250,0.2);">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Lihat Web Publik
            </a>
        </div>
        
        <!-- PROGRESS BAR STORAGE -->
        <div style="background: #fff; border: 1px solid var(--border-soft); padding: 16px; border-radius: var(--radius-md);">
            <h4 style="font-size: 0.8rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Kapasitas Storage</h4>
            <div style="background: var(--border-soft); border-radius: 999px; height: 8px; width: 100%; overflow: hidden; margin-bottom: 6px;">
                <div style="background: <?php echo ($storage_pct > 85) ? '#ef4444' : 'var(--pastel-purple)'; ?>; height: 100%; width: <?php echo $storage_pct; ?>%;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.72rem; font-weight: 700; color: var(--text-muted);">
                <span><?php echo $used_mb; ?> MB</span>
                <span><?php echo $limit_mb; ?> MB</span>
            </div>
        </div>

        <div class="dash-menu">
            <a href="<?php echo esc_url(home_url('/dashboard/overview/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo ($current_subpage == 'overview' || empty($current_subpage)) ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Ringkasan Toko</a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/calculator/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_calc) ? 'disabled-menu' : (($current_subpage == 'calculator') ? 'active' : ''); ?>"><i class="fa-solid fa-calculator"></i> Kalkulator Harga Jual</a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/payments/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_payment) ? 'disabled-menu' : (($current_subpage == 'payments') ? 'active' : ''); ?>">
                <i class="fa-solid fa-receipt"></i> Pembayaran Pelanggan 
                <span style="background:#ef4444; color:#fff; padding:2px 6px; border-radius:10px; font-size:0.7rem; margin-left:auto;"><?php echo $pending_count; ?></span>
            </a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/po/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_po) ? 'disabled-menu' : (($current_subpage == 'po') ? 'active' : ''); ?>"><i class="fa-solid fa-clipboard-list"></i> Rekapan PO</a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/tagihan/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_tagihan) ? 'disabled-menu' : (($current_subpage == 'tagihan') ? 'active' : ''); ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Rekapan Tagihan</a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/warehouse/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_wh) ? 'disabled-menu' : (($current_subpage == 'warehouse') ? 'active' : ''); ?>"><i class="fa-solid fa-boxes-stacked"></i> Database Warehouse</a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/finance/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo ($current_subpage == 'finance') ? 'active' : ''; ?>"><i class="fa-solid fa-wallet"></i> Laporan Keuangan</a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/subscription/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo ($current_subpage == 'subscription') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice-dollar"></i> Status Langganan
                <?php if ($pending_subscription_count > 0): ?>
                    <span style="background:#f59e0b; color:#fff; padding:2px 6px; border-radius:10px; font-size:0.7rem; margin-left:auto;"><?php echo $pending_subscription_count; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="<?php echo esc_url(home_url('/dashboard/setting/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_calc && !$has_public_calc && !$has_po && !$has_payment && !$has_tagihan && !$has_wh) ? 'disabled-menu' : (($current_subpage == 'setting') ? 'active' : ''); ?>"><i class="fa-solid fa-coins"></i> Pengaturan</a>
        </div>
    </aside>

    <!-- KONTEN UTAMA: INCLUDE FILE MENU TERPISAH -->
    <main class="dash-main-content">
        <?php if (!$is_store_online): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 24px; font-weight: 700;">
                <span><i class="fa-solid fa-triangle-exclamation"></i> Akun Anda sedang Offline / Dinonaktifkan oleh Super Admin.</span>
            </div>
        <?php elseif ($active_urgency === 'critical'): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 24px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <span><i class="fa-solid fa-circle-exclamation"></i> <?php echo $remaining_days > 0 ? 'Masa aktif sewa tinggal ' . $remaining_days . ' hari lagi! Lewat jatuh tempo, toko langsung offline & data berisiko terhapus.' : 'Masa aktif sewa udah habis! Segera perpanjang sebelum data terhapus permanen.'; ?></span>
                <a href="<?php echo esc_url(home_url('/dashboard/subscription/') . $store_query_suffix); ?>" style="background:#991b1b; color:#fff; padding:8px 16px; border-radius:8px; font-weight:800; text-decoration:none; white-space:nowrap;">Perpanjang Sekarang</a>
            </div>
        <?php elseif ($active_urgency === 'warning'): ?>
            <div style="background: #fffbeb; border: 1.5px solid #fde68a; color: #92400e; padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 24px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <span><i class="fa-solid fa-clock"></i> Masa aktif sewa tinggal <?php echo $remaining_days; ?> hari lagi. Yuk perpanjang dari sekarang biar gak kepepet.</span>
                <a href="<?php echo esc_url(home_url('/dashboard/subscription/') . $store_query_suffix); ?>" style="background:#d97706; color:#fff; padding:8px 16px; border-radius:8px; font-weight:800; text-decoration:none; white-space:nowrap;">Perpanjang Sekarang</a>
            </div>
        <?php endif; ?>

        <?php
        // Pemetaan rute sub-halaman ke file menu terpisah
        $menu_files = array(
            'overview'     => 'admin.php',
            'calculator'   => 'menu-calculator.php',
            'payments'     => 'menu-payments.php',
            'po'           => 'menu-po.php',
            'tagihan'      => 'menu-tagihan.php',
            'warehouse'    => 'menu-warehouse.php',
            'finance'      => 'menu-finance.php',
            'subscription' => 'menu-subscription.php',
            'setting'      => 'menu-setting.php'
        );

        if ($current_subpage == 'overview' || empty($current_subpage)) {
            // Tampilkan Ringkasan Toko langsung di sini
            ?>
            <div style="margin-bottom: 24px;">
                <h2>Dashboard Proxy/Jastip: <?php echo esc_html($store_name); ?></h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Berikut ringkasan aktivitas operasional dan status modul fitur akun kamu.</p>
            </div>

            <div class="stats-grid-6">
                <div class="stat-card"><span>Konfirmasi Pending</span><h3 style="color: #f59e0b;"><?php echo $pending_count; ?> Menunggu</h3></div>
                <div class="stat-card"><span>Total PO Aktif</span><h3><?php echo $stat_po_aktif; ?> Pesanan</h3></div>
                <div class="stat-card"><span>Barang Sampai WH</span><h3><?php echo $stat_barang_wh; ?> Item</h3></div>
                <div class="stat-card"><span>Barang Jalan ke Indo</span><h3><?php echo $stat_jalan_ke_indo; ?> Item</h3></div>
                <div class="stat-card"><span>Barang Sampai Indo</span><h3><?php echo $stat_sampai_indo; ?> Item</h3></div>
                <div class="stat-card"><span>Masa Aktif Sewa</span><h3 style="color: <?php echo esc_attr($active_urgency_color); ?>;"><?php echo $remaining_days; ?> Hari Lagi</h3></div>
            </div>

            <!-- ANALITIK & TREN — 3 grafik dari data yang udah ada, gak nambah tabel baru -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
            <style>
                .analytics-grid-3 { display: grid; grid-template-columns: 1.3fr 1.3fr 1fr; gap: 20px; margin-top: 16px; }
                @media (max-width: 900px) { .analytics-grid-3 { grid-template-columns: 1fr; } }
            </style>
            <div class="overview-status-box" style="margin-bottom: 24px;">
                <h3 class="panel-title"><i class="fa-solid fa-chart-line" style="color:var(--pastel-purple);"></i> Analitik & Tren</h3>
                <p class="panel-desc">Ringkasan performa toko dari waktu ke waktu, dihitung otomatis dari Rekapan PO & Konfirmasi Pembayaran.</p>
                <div class="analytics-grid-3">
                    <div style="min-width: 0;">
                        <div style="font-size: 13px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 8px;">Tren Volume Order (12 Bulan)</div>
                        <canvas id="chartVolumeOrder" height="180"></canvas>
                    </div>
                    <div style="min-width: 0;">
                        <div style="font-size: 13px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 8px;">Tren Omset dari Customer (12 Bulan)</div>
                        <canvas id="chartOmsetCustomer" height="180"></canvas>
                    </div>
                    <div style="min-width: 0;">
                        <div style="font-size: 13px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 8px;">Distribusi Status Order</div>
                        <canvas id="chartStatusDist" height="180"></canvas>
                    </div>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const monthLabels = <?php echo wp_json_encode( array_values($chart_months) ); ?>;
                const volumeData  = <?php echo wp_json_encode( array_values($chart_volume_data) ); ?>;
                const omsetData   = <?php echo wp_json_encode( array_values($chart_omset_data) ); ?>;
                const statusLabels = <?php echo wp_json_encode( array_keys($chart_status_data) ); ?>;
                const statusData   = <?php echo wp_json_encode( array_values($chart_status_data) ); ?>;
                const PALETTE = ['#8b5cf6','#0ea5e9','#f59e0b','#10b981','#ef4444','#ec4899','#64748b','#0d9488'];

                if (window.Chart) {
                    new Chart(document.getElementById('chartVolumeOrder'), {
                        type: 'bar',
                        data: { labels: monthLabels, datasets: [{ label: 'Order', data: volumeData, backgroundColor: '#8b5cf6', borderRadius: 4 }] },
                        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                    });
                    new Chart(document.getElementById('chartOmsetCustomer'), {
                        type: 'line',
                        data: { labels: monthLabels, datasets: [{ label: 'Omset (Rp)', data: omsetData, borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,0.1)', fill: true, tension: 0.3 }] },
                        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => 'Rp ' + ctx.parsed.y.toLocaleString('id-ID') } } }, scales: { y: { beginAtZero: true, ticks: { callback: (v) => 'Rp' + (v/1000) + 'rb' } } } }
                    });
                    if (statusLabels.length > 0) {
                        new Chart(document.getElementById('chartStatusDist'), {
                            type: 'doughnut',
                            data: { labels: statusLabels, datasets: [{ data: statusData, backgroundColor: PALETTE }] },
                            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } }
                        });
                    } else {
                        document.getElementById('chartStatusDist').closest('div').innerHTML += '<p style="color:var(--text-muted); font-size:12px; text-align:center; margin-top:20px;">Belum ada data order.</p>';
                    }
                }
            });
            </script>

            <div class="overview-status-box">
                <h3 class="panel-title"><i class="fa-solid fa-circle-info" style="color:var(--pastel-purple);"></i> Status Akun & Hak Akses Fitur</h3>
                <p class="panel-desc">Menampilkan status operasional serta modul fitur yang disesuaikan dengan menu sidebar akun kamu.</p>
                
                <div class="info-row"><span>Status Akun</span><strong style="color: var(--pastel-mint);"><i class="fa-solid fa-circle-check"></i> Online</strong></div>
                <div class="info-row"><span>Kalkulator Harga Jual</span><strong style="color: <?php echo $has_calc ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_calc ? 'Aktif' : 'Nonaktif'; ?></strong></div>
                <div class="info-row"><span>Kalkulator Publik</span><strong style="color: <?php echo $has_public_calc ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_public_calc ? 'Aktif' : 'Nonaktif'; ?></strong></div>
                <div class="info-row"><span>Pembayaran Pelanggan</span><strong style="color: <?php echo $has_payment ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_payment ? 'Aktif' : 'Nonaktif'; ?></strong></div>
                <div class="info-row"><span>Rekapan PO</span><strong style="color: <?php echo $has_po ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_po ? 'Aktif' : 'Nonaktif'; ?></strong></div>
                <div class="info-row"><span>Rekapan Tagihan</span><strong style="color: <?php echo $has_tagihan ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_tagihan ? 'Aktif' : 'Nonaktif'; ?></strong></div>
                <div class="info-row"><span>Database Warehouse</span><strong style="color: <?php echo $has_wh ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_wh ? 'Aktif' : 'Nonaktif'; ?></strong></div>
                <div class="info-row"><span>Laporan Keuangan</span><strong style="color: var(--pastel-mint);">Aktif</strong></div>
                <div class="info-row"><span>Status Langganan</span><strong style="color: var(--pastel-mint);">Aktif</strong></div>
                <div class="info-row"><span>Pengaturan</span><strong style="color: <?php echo ($has_calc || $has_public_calc || $has_po || $has_payment || $has_tagihan || $has_wh) ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo ($has_calc || $has_public_calc || $has_po || $has_payment || $has_tagihan || $has_wh) ? 'Aktif' : 'Nonaktif'; ?></strong></div>
            </div>
            <?php
        } else {
            $target_menu_file = isset($menu_files[$current_subpage]) ? $menu_files[$current_subpage] : '';
            $full_path = get_stylesheet_directory() . '/' . $target_menu_file;
            
            if (!empty($target_menu_file) && file_exists($full_path)) {
                include($full_path);
            } else {
                echo '<div style="background:#fef2f2; color:#991b1b; padding:20px; border-radius:8px; font-weight:700;">Menu atau file modul tidak ditemukan!</div>';
            }
        }
        ?>
    </main>
</div>

<?php get_footer(); ?>