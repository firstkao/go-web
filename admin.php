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
$has_testi        = ($is_store_online && in_array('testimonials', $features));

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
    // Server-side gating fitur — menu sidebar cuma di-disable via CSS, jadi
    // tanpa cek ini tenant tanpa fitur PO tetap bisa kirim POST langsung ke
    // /dashboard/po/ dan datanya tetap kesimpen. Guard di menu-po.php doang
    // gak cukup (handler ini jalan SEBELUM menu di-include).
    if ( ! $has_po ) {
        wp_die('Akses ditolak. Fitur Rekapan PO tidak aktif untuk toko ini. Hubungi Super Admin kalau ingin mengaktifkannya.');
    }

    if ( isset($_POST['action_add_order']) ) {
        // Validasi format tanggal (#35): dulu nilai apa pun diterima, dan
        // tanggal ngawur bikin baris PO HILANG dari daftar (filter JS
        // `new Date(...)` jadi invalid) padahal datanya tetap ada di DB.
        // Kalau kosong/ngawur -> pakai tanggal hari ini.
        $po_date = isset($_POST['po_date']) ? sanitize_text_field($_POST['po_date']) : '';
        if ( $po_date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $po_date) ) {
            $po_date = current_time('Y-m-d');
        }
        // Validasi server-side (#20): status order harus dari daftar status
        // toko (dulu free-text — status ngawur merusak badge/filter/portal),
        // dan Total Nilai PO tidak boleh negatif (dulu floatval polos ->
        // rekap nilai negatif).
        $po_status_new = sanitize_text_field($_POST['po_status'] ?? '');
        $po_statuses_valid = goweb_get_tenant_setting( $store_id, 'custom_statuses', array('Antrean', 'On Process', 'Arrived WH', 'Shipped Indo', 'Done') );
        if ( ! in_array( $po_status_new, (array) $po_statuses_valid, true ) ) {
            wp_die('Gagal simpan: status order "' . esc_html($po_status_new) . '" tidak ada dalam daftar status toko. Pilih dari daftar status yang tersedia.');
        }
        $po_total_value_new = isset($_POST['po_total_value']) && $_POST['po_total_value'] !== '' ? floatval($_POST['po_total_value']) : 0;
        if ( $po_total_value_new < 0 ) {
            wp_die('Gagal simpan: Total Nilai PO tidak boleh negatif.');
        }
        $wpdb->insert( $orders_table, array(
            'store_id'       => $store_id,
            'batch'          => sanitize_text_field( $_POST['po_batch'] ?? '' ),
            'buyer_name'     => sanitize_text_field( $_POST['po_buyer_name'] ?? '' ),
            'item_detail'    => sanitize_textarea_field( $_POST['po_item_detail'] ?? '' ),
            'qty'            => 1,
            // Total nilai PO (opsional, Rp) — kolom sebelumnya tidak pernah
            // diisi form apapun (selalu 0). qty memang 1 karena 1 baris =
            // 1 item/PO; nilai totalnya dicatat di sini.
            'total_value'    => $po_total_value_new,
            'order_status'   => $po_status_new,
            'created_at'     => ( $po_date !== '' ) ? $po_date . ' 00:00:00' : current_time('mysql'),
        ) );
    } elseif ( isset($_POST['action_update_status']) ) {
        // Status update juga divalidasi terhadap daftar status toko (#20)
        $new_status = sanitize_text_field( $_POST['new_status'] ?? '' );
        $po_statuses_valid2 = goweb_get_tenant_setting( $store_id, 'custom_statuses', array('Antrean', 'On Process', 'Arrived WH', 'Shipped Indo', 'Done') );
        if ( ! in_array( $new_status, (array) $po_statuses_valid2, true ) ) {
            wp_die('Gagal ubah status: status "' . esc_html($new_status) . '" tidak ada dalam daftar status toko.');
        }
        $wpdb->update( $orders_table,
            array( 'order_status' => $new_status ),
            array( 'id' => intval($_POST['order_id']), 'store_id' => $store_id )
        );
    } elseif ( isset($_POST['action_delete_order']) ) {
        $del_order_id = intval($_POST['order_id']);
        // Ownership check dulu — jangan biarkan ID dari POST menghapus data
        // toko lain (sebelumnya delete memang sudah di-scope store_id, ini
        // menjaga cascade di bawah ikut aman).
        $del_order_owned = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders_table} WHERE id = %d AND store_id = %s", $del_order_id, $store_id
        ) );
        if ( $del_order_owned ) {
            // CASCADE (#30): dulu baris order dihapus sendirian, sisa tagihan &
            // item warehouse jadi YATIM (order_id menunjuk baris yang sudah
            // tidak ada) — di portal publik buyer PO-nya tampil kosong dan
            // tagihan gak bisa dikenali lagi.
            // 1) Tagihan selalu milik 1 PO di sistem ini (form tagihan wajib
            //    pilih PO), jadi tanpa PO tagihannya tidak bisa dipakai →
            //    ikut dihapus.
            //    CATATAN: pakai $goweb_tables langsung (bukan $invoices_table)
            //    karena variabel itu baru didefinisikan di handler Tagihan
            //    yang letaknya SETELAH blok ini.
            $wpdb->delete( $goweb_tables['invoices'], array( 'order_id' => $del_order_id, 'store_id' => $store_id ) );
            // 2) Item warehouse JANGAN dihapus (itu catatan barang fisik) —
            //    cukup dilepas dari PO-nya.
            $wpdb->update( $goweb_tables['warehouse'], array( 'order_id' => null ), array( 'order_id' => $del_order_id, 'store_id' => $store_id ) );
            // 3) Baru hapus PO-nya.
            $wpdb->delete( $orders_table, array( 'id' => $del_order_id, 'store_id' => $store_id ) );
        }
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
    // Server-side gating fitur (lihat catatan di handler PO di atas).
    if ( ! $has_tagihan ) {
        wp_die('Akses ditolak. Fitur Rekapan Tagihan tidak aktif untuk toko ini. Hubungi Super Admin kalau ingin mengaktifkannya.');
    }

    if ( isset($_POST['action_add_invoice']) ) {
        $inv_order_id = intval($_POST['inv_order_id']);
        // Pastikan order yg dipilih beneran milik tenant ini (ownership check)
        $order_owned = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders_table} WHERE id = %d AND store_id = %s", $inv_order_id, $store_id
        ) );
        // Validasi server-side (#21): kategori harus dari daftar jenis
        // pembayaran toko (sama sumbernya dengan select UI), nominal harus
        // angka > 0 — dulu bebas string & nominal 0/negatif bisa masuk.
        $inv_category_new = sanitize_text_field( $_POST['inv_category'] ?? '' );
        $inv_categories_valid = goweb_get_tenant_setting( $store_id, 'payment_categories', goweb_get_default_payment_categories() );
        if ( ! in_array( $inv_category_new, (array) $inv_categories_valid, true ) ) {
            wp_die('Gagal simpan: jenis tagihan "' . esc_html($inv_category_new) . '" tidak ada dalam daftar jenis pembayaran toko. Pilih dari daftar.');
        }
        $inv_nominal_new = isset($_POST['inv_nominal']) ? intval($_POST['inv_nominal']) : 0;
        if ( $inv_nominal_new <= 0 ) {
            wp_die('Gagal simpan: nominal tagihan harus angka lebih dari 0.');
        }
        if ( $order_owned ) {
            $wpdb->insert( $invoices_table, array(
                'store_id'   => $store_id,
                'order_id'   => $inv_order_id,
                'category'   => $inv_category_new,
                'nominal'    => $inv_nominal_new,
                'status'     => 'Belum Bayar',
                'created_at' => current_time('mysql'),
            ) );
            // Status bayar PO diturunkan dari tagihannya (#33)
            goweb_sync_order_payment_status( $store_id, $inv_order_id );
        }
    } elseif ( isset($_POST['action_toggle_invoice_status']) ) {
        $invoice_id = intval($_POST['invoice_id']);
        $inv_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$invoices_table} WHERE id = %d AND store_id = %s", $invoice_id, $store_id
        ), ARRAY_A );
        if ( $inv_row ) {
            $new_status = ( $inv_row['status'] === 'Lunas' ) ? 'Belum Bayar' : 'Lunas';
            $wpdb->update( $invoices_table, array( 'status' => $new_status ), array( 'id' => $invoice_id, 'store_id' => $store_id ) );
            // Status bayar PO ikut disesuaikan (#33)
            goweb_sync_order_payment_status( $store_id, intval( $inv_row['order_id'] ) );
        }
    } elseif ( isset($_POST['action_delete_invoice']) ) {
        $del_inv_id = intval($_POST['invoice_id']);
        // Ambil order_id dulu supaya status bayar PO-nya bisa dihitung ulang
        // setelah tagihan ini hilang (#33).
        $del_inv_order = $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$invoices_table} WHERE id = %d AND store_id = %s", $del_inv_id, $store_id
        ) );
        $wpdb->delete( $invoices_table, array( 'id' => $del_inv_id, 'store_id' => $store_id ) );
        if ( $del_inv_order ) {
            goweb_sync_order_payment_status( $store_id, intval( $del_inv_order ) );
        }
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
// HANDLER TESTIMONI (setujui / tolak / hapus)
// Testimoni dikirim pembeli lewat portal publik (status awal Pending);
// admin tenant yang menentukan mana yang tampil. Semua query diikat ke
// store_id biar tenant gak bisa menyentuh testimoni tenant lain.
// ----------------------------------------------------
$testimonials_table = $goweb_tables['testimonials'];

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['goweb_testi_nonce']) ) {
    if ( ! wp_verify_nonce($_POST['goweb_testi_nonce'], 'goweb_testi_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }
    if ( ! $has_testi ) {
        wp_die('Akses ditolak. Fitur Testimoni tidak aktif untuk toko ini. Hubungi Super Admin kalau ingin mengaktifkannya.');
    }
    $testi_id = intval( $_POST['testi_id'] ?? 0 );
    if ( isset($_POST['action_testi_approve']) ) {
        $wpdb->update( $testimonials_table, array( 'status' => 'Approved' ), array( 'id' => $testi_id, 'store_id' => $store_id ) );
    } elseif ( isset($_POST['action_testi_reject']) ) {
        $wpdb->update( $testimonials_table, array( 'status' => 'Rejected' ), array( 'id' => $testi_id, 'store_id' => $store_id ) );
    } elseif ( isset($_POST['action_testi_delete']) ) {
        $testi_to_delete = $wpdb->get_row( $wpdb->prepare(
            "SELECT photo_url FROM {$testimonials_table} WHERE id = %d AND store_id = %s", $testi_id, $store_id
        ), ARRAY_A );
        $testi_deleted = $wpdb->delete( $testimonials_table, array( 'id' => $testi_id, 'store_id' => $store_id ) );
        if ( $testi_deleted && ! empty( $testi_to_delete['photo_url'] ) ) {
            goweb_delete_uploaded_file_by_url( $testi_to_delete['photo_url'] );
        }
    }
    wp_redirect( home_url('/dashboard/testimonials/') . $store_query_suffix );
    exit;
}

// ----------------------------------------------------
// HANDLER DATABASE WAREHOUSE (tambah / ubah status / hapus)
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
    // Server-side gating fitur (lihat catatan di handler PO di atas).
    if ( ! $has_wh ) {
        wp_die('Akses ditolak. Fitur Database Warehouse tidak aktif untuk toko ini. Hubungi Super Admin kalau ingin mengaktifkannya.');
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
            // Kuota storage dicek JUGA di sisi dashboard (#22) — dulu cek kuota
            // cuma ada di upload publik (tenant-web.php), jadi tenant bisa
            // menembus kuota paketnya sendiri lewat upload foto warehouse.
            $wh_storage_limit_b = ( isset($current_tenant['storage_limit']) ? floatval($current_tenant['storage_limit']) : 250 ) * 1048576;
            $wh_storage_used_b = goweb_calculate_tenant_storage_bytes( $store_id );
            if ( ( $wh_storage_used_b + intval( $_FILES['wh_foto_bukti']['size'] ) ) > $wh_storage_limit_b ) {
                wp_die('Gagal upload: kapasitas penyimpanan toko sudah penuh. Hapus beberapa data/file atau upgrade paket dulu.');
            }
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

        // array_values (#27): daftar status bisa "berlubang" kalau baris
        // pertama dihapus di Pengaturan, sehingga [0] gak ada dan status
        // item baru tersimpan kosong. reset() selalu ambil elemen pertama
        // yang benar-benar ada.
        $wh_statuses_list = array_values( (array) $wh_statuses );
        // Validasi format tanggal masuk (#35) — sama alasannya dengan PO:
        // tanggal ngawur bikin tampilan jadi 01/01/1970 dan filter kacau.
        $wh_date = isset($_POST['wh_tanggal_masuk']) ? sanitize_text_field($_POST['wh_tanggal_masuk']) : '';
        if ( $wh_date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $wh_date) ) {
            $wh_date = current_time('Y-m-d');
        }
        $wpdb->insert( $warehouse_table, array(
            'store_id'      => $store_id,
            'order_id'      => $wh_order_id,
            'buyer_name'    => $wh_buyer_name,
            'resi_masuk'    => sanitize_text_field( $_POST['wh_resi_masuk'] ?? '' ),
            'slot'          => sanitize_text_field( $_POST['wh_slot'] ?? '' ),
            'lokasi_wh'     => sanitize_text_field( $_POST['wh_lokasi'] ?? '' ),
            'foto_bukti'    => $wh_foto_url,
            'berat'         => isset($_POST['wh_berat']) ? floatval($_POST['wh_berat']) : 0,
            'status'        => ! empty($wh_statuses_list) ? $wh_statuses_list[0] : 'Menunggu',
            'tanggal_masuk' => $wh_date !== '' ? $wh_date : current_time('Y-m-d'),
            'created_at'    => current_time('mysql'),
        ) );
    } elseif ( isset($_POST['action_update_wh_status']) ) {
        // Status WH divalidasi ke daftar status toko (#26) — pola sama dengan
        // status order di Rekapan PO; dulu free-text -> status ngawur
        // merusak badge/filter/portal.
        $wh_status_new = sanitize_text_field( $_POST['new_status'] ?? '' );
        $wh_statuses_valid = goweb_get_tenant_setting( $store_id, 'wh_statuses', goweb_get_default_warehouse_statuses() );
        if ( ! in_array( $wh_status_new, (array) $wh_statuses_valid, true ) ) {
            wp_die('Gagal ubah status: status "' . esc_html($wh_status_new) . '" tidak ada dalam daftar status warehouse toko.');
        }
        $wpdb->update( $warehouse_table,
            array( 'status' => $wh_status_new ),
            array( 'id' => intval($_POST['wh_id']), 'store_id' => $store_id )
        );
    } elseif ( isset($_POST['action_update_wh_outgoing']) ) {
        wp_die('Fitur resi keluar sudah tidak tersedia di Database Warehouse.');
    } elseif ( isset($_POST['action_delete_warehouse']) ) {
        // Bersihkan file foto dari disk SEBELUM baris dihapus (#25) — dulu
        // baris hilang tapi file foto tetap yatim di disk selamanya.
        $wh_foto_url = $wpdb->get_var( $wpdb->prepare(
            "SELECT foto_bukti FROM {$warehouse_table} WHERE id = %d AND store_id = %s AND foto_bukti IS NOT NULL AND foto_bukti != ''",
            intval($_POST['wh_id']), $store_id
        ) );
        if ( $wh_foto_url ) {
            goweb_delete_uploaded_file_by_url( $wh_foto_url );
        }
        $wpdb->delete( $warehouse_table, array( 'id' => intval($_POST['wh_id']), 'store_id' => $store_id ) );

    // --- HANDLER BOX KONSOLIDASI ---
    } elseif ( isset($_POST['action_create_box']) ) {
        // array_values (#27) — alasan sama dengan status warehouse di atas.
        $box_statuses_list = array_values( (array) $box_statuses );
        $box_capacity = isset($_POST['box_kapasitas']) ? floatval($_POST['box_kapasitas']) : 20;
        if ( ! is_finite( $box_capacity ) || $box_capacity <= 0 ) {
            wp_die('Kapasitas box harus berupa angka lebih dari 0 kg.');
        }
        $wpdb->insert( $box_table, array(
            'store_id'     => $store_id,
            'box_name'     => sanitize_text_field( $_POST['box_name'] ?? '' ),
            'lokasi'       => sanitize_text_field( $_POST['box_lokasi'] ?? '' ),
            'kapasitas_kg' => $box_capacity,
            'status'       => ! empty($box_statuses_list) ? $box_statuses_list[0] : 'Packing In Progress',
            // status_changed_at dipakai auto-arsip box "sampai Indo" (2 minggu)
            'status_changed_at' => current_time('mysql'),
            'created_at'   => current_time('mysql'),
        ) );
    } elseif ( isset($_POST['action_update_box_status']) ) {
        // Status box divalidasi ke daftar status box toko (#26)
        $box_status_new = sanitize_text_field( $_POST['new_status'] ?? '' );
        $box_statuses_valid = goweb_get_tenant_setting( $store_id, 'box_statuses', goweb_get_default_box_statuses() );
        if ( ! in_array( $box_status_new, (array) $box_statuses_valid, true ) ) {
            wp_die('Gagal ubah status: status "' . esc_html($box_status_new) . '" tidak ada dalam daftar status box toko.');
        }
        // Timer auto-arsip: clock-nya direset SETIAP status berubah, bukan
        // hanya saat masuk status arrived — jadi box yang balik ke process
        // (mis. salah set status) dapat jendela 2 minggu baru saat sampai lagi.
        $box_id_upd      = intval( $_POST['box_id'] );
        $box_status_prev = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$box_table} WHERE id = %d AND store_id = %s", $box_id_upd, $store_id
        ) );
        $box_update_data = array( 'status' => $box_status_new );
        if ( $box_status_prev !== $box_status_new ) {
            $box_update_data['status_changed_at'] = current_time('mysql');
            // Keluar dari arsip: box yang diubah statusnya dianggap aktif lagi.
            $box_update_data['archived_at'] = null;
        }
        $wpdb->update( $box_table, $box_update_data, array( 'id' => $box_id_upd, 'store_id' => $store_id ) );
    } elseif ( isset($_POST['action_delete_box']) ) {
        $box_id_del = intval($_POST['box_id']);
        // Lepasin dulu semua item yg nempel ke Box ini (jangan ikut kehapus)
        $wpdb->update( $warehouse_table, array( 'box_id' => null ), array( 'box_id' => $box_id_del, 'store_id' => $store_id ) );
        $wpdb->delete( $box_table, array( 'id' => $box_id_del, 'store_id' => $store_id ) );
    } elseif ( isset($_POST['action_assign_to_box']) ) {
        $assign_box_id = intval($_POST['assign_box_id'] ?? 0);
        $wh_id_to_assign = intval($_POST['wh_id'] ?? 0);
        // Pastikan item warehouse milik tenant ini sebelum memindahkannya.
        $wh_item_to_assign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$warehouse_table} WHERE id = %d AND store_id = %s", $wh_id_to_assign, $store_id
        ), ARRAY_A );
        if ( ! $wh_item_to_assign ) {
            wp_die('Paket tidak ditemukan atau bukan milik toko ini.');
        }

        if ( $assign_box_id === 0 ) {
            $wpdb->update( $warehouse_table, array( 'box_id' => null ), array( 'id' => $wh_id_to_assign, 'store_id' => $store_id ) );
        } else {
            // Ownership check box + enforce kapasitas sebelum menyimpan assignment.
            $box_to_assign = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$box_table} WHERE id = %d AND store_id = %s AND archived_at IS NULL", $assign_box_id, $store_id
            ), ARRAY_A );
            if ( ! $box_to_assign ) {
                wp_die('Box tidak ditemukan atau sudah tidak aktif.');
            }
            $box_to_assign['filled_kg'] = goweb_calculate_box_filled_weight( $assign_box_id );
            $capacity_error = goweb_get_box_assignment_capacity_error(
                $box_to_assign,
                isset( $wh_item_to_assign['berat'] ) ? (float) $wh_item_to_assign['berat'] : 0,
                isset( $wh_item_to_assign['box_id'] ) ? (int) $wh_item_to_assign['box_id'] : 0
            );
            if ( $capacity_error !== '' ) {
                wp_die( esc_html( $capacity_error ) );
            }
            $wpdb->update( $warehouse_table, array( 'box_id' => $assign_box_id ), array( 'id' => $wh_id_to_assign, 'store_id' => $store_id ) );
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
    // archived_at IS NULL: box "sampai Indo" yang lewat 2 minggu menghilang
    // otomatis dari daftar (auto-arsip di cron goweb_archive_stale_arrived_boxes).
    "SELECT * FROM {$box_table} WHERE store_id = %s AND archived_at IS NULL ORDER BY created_at DESC", $store_id
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
    // Server-side gating fitur (lihat catatan di handler PO di atas).
    if ( ! $has_payment ) {
        wp_die('Akses ditolak. Fitur Konfirmasi Pembayaran Pelanggan tidak aktif untuk toko ini. Hubungi Super Admin kalau ingin mengaktifkannya.');
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

        if ( $_POST['goweb_payment_action'] === 'update_payment_date' ) {
            wp_die('Tanggal pembayaran dicatat otomatis oleh sistem dan tidak dapat diedit manual.');
        } elseif ( $_POST['goweb_payment_action'] === 'verify' ) {
            $verification_data = array('status' => 'Verified');
            if ( $row['status'] !== 'Verified' ) {
                $verification_data['verified_at'] = current_time('mysql');
            }
            $wpdb->update( $payments_table, $verification_data, array( 'id' => $payment_id, 'store_id' => $store_id ) );

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
                        $net_paid = goweb_subscription_net_value( $row, $current_tenant_package_local );
                        $update_data['cycle_daily_rate'] = goweb_subscription_renewal_daily_rate(
                            $old_rate, $remaining_days_before, $net_paid, $duration_days
                        );
                    }

                    $wpdb->update( $tenants_table, $update_data, array( 'tenant_id' => $store_id ) );
                }
            }
        } elseif ( $_POST['goweb_payment_action'] === 'delete' ) {
            // Bersihkan file bukti transfer dari disk SEBELUM baris dihapus
            // (#25) — dulu baris hilang tapi file bukti tetap yatim selamanya.
            $proof_to_delete = $wpdb->get_var( $wpdb->prepare(
                "SELECT proof_url FROM {$payments_table} WHERE id = %d AND store_id = %s AND proof_url IS NOT NULL AND proof_url != ''",
                $payment_id, $store_id
            ) );
            if ( $proof_to_delete ) {
                goweb_delete_uploaded_file_by_url( $proof_to_delete );
            }
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
// Konfirmasi Pembayaran kategori PO yang UDAH Verified. Sistem mencatat
// waktu uang masuk saat konfirmasi diterima dan waktu verifikasi saat admin
// menyetujui; Omset dikelompokkan berdasarkan waktu verifikasi.
$chart_omset_data = array_fill_keys( array_keys($chart_months), 0 );
foreach ( $subscription_history as $h ) {
    if ( isset($h['payment_category']) && $h['payment_category'] === 'po' && $h['status'] === 'Verified' && ! empty($h['verified_at']) ) {
        $ym = substr( (string) $h['verified_at'], 0, 7 );
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

// Kurs & tarif buat kalkulator internal — dibaca dari setting tenant yang
// dikelola lewat halaman Pengaturan (satu sumber, bug kurs ganda sudah
// dibereskan di functions.php).
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

    // Validasi server-side (#23): nominal mutasi harus positif (dulu top-up
    // negatif bisa mengurangi saldo dompet), nilai IDR tidak boleh negatif,
    // dan tanggal harus format YYYY-MM-DD.
    if ( $fin_amount <= 0 ) {
        wp_die('Gagal simpan: nominal mutasi keuangan harus angka lebih dari 0.');
    }
    if ( $fin_idr < 0 ) {
        wp_die('Gagal simpan: nilai Rupiah (IDR) tidak boleh negatif.');
    }
    if ( ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fin_date) ) {
        wp_die('Gagal simpan: format tanggal tidak valid (harus YYYY-MM-DD).');
    }

    $valid_types = array('wallet_topup', 'supplier_purchase', 'cargo_1', 'cargo_2', 'operational');
    if ( in_array($fin_type, $valid_types, true) && $fin_date !== '' ) {

        // Belanja Supplier memakai moving weighted average dari modal yang
        // masih melekat pada saldo. Replay ledger dipakai sama oleh preview
        // dan handler supaya nominal final tidak bergantung pada rate lama.
        if ( $fin_type === 'supplier_purchase' ) {
            $wallet_ledger_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$ledger_table} WHERE store_id = %s AND wallet_name = %s",
                $store_id, $fin_wallet
            ), ARRAY_A );
            $wallet_cost = goweb_calculate_wallet_moving_average( $wallet_ledger_rows, $fin_wallet );
            $wallet_balance_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wallets_table} WHERE store_id = %s AND wallet_name = %s",
                $store_id, $fin_wallet
            ), ARRAY_A );
            $actual_wallet_balance = $wallet_balance_row ? floatval($wallet_balance_row['balance']) : 0.0;
            if ( ! $wallet_cost['is_valid'] || abs($wallet_cost['balance'] - $actual_wallet_balance) > 0.01 ) {
                wp_die('Gagal simpan: riwayat modal dan saldo dompet "' . esc_html($fin_wallet) . '" belum cocok. Periksa ulang transaksi Top-Up/Belanja sebelum mencatat biaya baru.');
            }
            if ( ! $wallet_cost['has_topup'] || $wallet_cost['balance'] <= 0 || $wallet_cost['average_rate'] <= 0 ) {
                wp_die('Gagal simpan: dompet "' . esc_html($fin_wallet) . '" belum memiliki saldo Top-Up bermodal positif. Lakukan Top-Up dulu sebelum mencatat Belanja Supplier.');
            }
            if ( $fin_amount > $wallet_cost['balance'] + 0.005 ) {
                wp_die('Gagal simpan: saldo dompet "' . esc_html($fin_wallet) . '" tidak cukup untuk belanja ini.');
            }
            $fin_rate = $wallet_cost['average_rate'];
            $fin_idr  = round( $fin_amount * $fin_rate );
        } elseif ( $fin_amount > 0 ) {
            // Tipe lain (wallet_topup, cargo, operational) — IDR-nya emang
            // diketik manual (uang asli yg dikeluarkan), tapi kolom rate
            // ikut dihitung dari situ buat jadi acuan wallet_topup selanjutnya.
            $fin_rate = $fin_idr / $fin_amount;
        }

        $ledger_inserted = $wpdb->insert( $ledger_table, array(
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
        if ( $ledger_inserted === false ) {
            wp_die('Gagal simpan: mutasi tidak berhasil dicatat ke ledger. Silakan coba lagi.');
        }

        // Ledger dulu, saldo sesudahnya. Kalau update saldo gagal, buang
        // baris yang baru dibuat agar modal dan saldo tidak terpisah.
        if ( $fin_wallet !== '-' && in_array( $fin_type, array('wallet_topup', 'supplier_purchase'), true ) ) {
            $delta = ( $fin_type === 'wallet_topup' ) ? $fin_amount : -$fin_amount;
            $wallet_updated = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wallets_table} (store_id, wallet_name, balance) VALUES (%s, %s, %f)
                 ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)",
                $store_id, $fin_wallet, $delta
            ) );
            if ( $wallet_updated === false ) {
                $wpdb->delete( $ledger_table, array('id' => $wpdb->insert_id, 'store_id' => $store_id) );
                wp_die('Gagal simpan: saldo dompet tidak berhasil diperbarui. Mutasi dibatalkan; silakan coba lagi.');
            }
        }

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
        $is_wallet_tx = $tx_to_delete['wallet_name'] !== '-' && in_array( $tx_to_delete['tx_type'], array('wallet_topup', 'supplier_purchase'), true );
        $reverse_delta = 0.0;
        if ( $is_wallet_tx ) {
            $reverse_delta = ( $tx_to_delete['tx_type'] === 'wallet_topup' ) ? -floatval($tx_to_delete['amount']) : floatval($tx_to_delete['amount']);
            $wallet_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$ledger_table} WHERE store_id = %s AND wallet_name = %s",
                $store_id, $tx_to_delete['wallet_name']
            ), ARRAY_A );
            $remaining_rows = array_values( array_filter( (array) $wallet_rows, function($row) use ($del_id) { return intval($row['id'] ?? 0) !== $del_id; } ) );
            $remaining_cost = goweb_calculate_wallet_moving_average( $remaining_rows, $tx_to_delete['wallet_name'] );
            $wallet_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wallets_table} WHERE store_id = %s AND wallet_name = %s",
                $store_id, $tx_to_delete['wallet_name']
            ), ARRAY_A );
            $expected_balance = floatval($wallet_row['balance'] ?? 0) + $reverse_delta;
            if ( ! $remaining_cost['is_valid'] || abs($remaining_cost['balance'] - $expected_balance) > 0.01 ) {
                wp_die('Gagal hapus: penghapusan ini membuat saldo atau modal dompet tidak cocok dengan riwayat. Periksa transaksi terkait terlebih dahulu.');
            }
        }

        $deleted = $wpdb->delete( $ledger_table, array( 'id' => $del_id, 'store_id' => $store_id ) );
        if ( $deleted === false ) {
            wp_die('Gagal hapus: mutasi tidak berhasil dihapus.');
        }
        if ( $is_wallet_tx ) {
            $wallet_updated = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wallets_table} (store_id, wallet_name, balance) VALUES (%s, %s, %f)
                 ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)",
                $store_id, $tx_to_delete['wallet_name'], $reverse_delta
            ) );
            if ( $wallet_updated === false ) {
                wp_die('Mutasi sudah terhapus, tetapi saldo dompet gagal diperbarui. Segera periksa saldo dompet.');
            }
        }
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
    // Catat dulu berapa yang dihapus. Reset ini PERMANEN (tanpa backup/soft
    // delete) dan bisa dipicu tenant sendiri maupun Super Admin lewat ?store=,
    // jadi tanpa log tidak ada cara membedakan "sengaja" vs "kepencet" —
    // polanya sama dengan log hapus tenant (#2). Log juga menyimpan siapa
    // pelakunya, karena reset oleh Super Admin tidak terlihat oleh tenant.
    $reset_ledger_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ledger_table} WHERE store_id = %s", $store_id ) );
    $reset_wallet_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wallets_table} WHERE store_id = %s", $store_id ) );

    $wpdb->delete( $ledger_table, array( 'store_id' => $store_id ) );
    $wpdb->delete( $wallets_table, array( 'store_id' => $store_id ) );

    $reset_actor = $is_super_admin
        ? 'Super Admin' . ( ! empty( $current_tenant['email'] ) ? ' (via ?store=)' : '' )
        : 'Tenant ' . ( isset( $current_tenant['email'] ) ? $current_tenant['email'] : $store_id );
    $reset_log   = get_option( 'goweb_finance_reset_log', array() );
    if ( ! is_array( $reset_log ) ) { $reset_log = array(); }
    $reset_log[] = array(
        'store_id'         => $store_id,
        'store_name'       => isset( $current_tenant['store_name'] ) ? $current_tenant['store_name'] : '',
        'actor'            => $reset_actor,
        'deleted_ledger'   => $reset_ledger_count,
        'deleted_wallets'  => $reset_wallet_count,
        'reset_at'         => current_time('mysql'),
    );
    // Simpan maksimal 200 entri terakhir biar option tidak membengkak.
    if ( count( $reset_log ) > 200 ) {
        $reset_log = array_slice( $reset_log, -200 );
    }
    update_option( 'goweb_finance_reset_log', $reset_log, false );

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

// Moving weighted average per dompet. Replay Top-Up + Belanja agar preview
// memakai saldo/modal yang sama dengan nilai final di handler POST.
$wallet_moving_costs = array();
foreach ( $active_wallets as $wn => $bal ) {
    $wallet_moving_costs[$wn] = goweb_calculate_wallet_moving_average( $finance_ledger_rows, $wn );
    $wallet_moving_costs[$wn]['balance_matches'] = abs($wallet_moving_costs[$wn]['balance'] - floatval($bal)) <= 0.01;
}

$current_real_year = date('Y');
$current_real_month = date('n');

get_header();
?>
<style>
        /* Semua token warna — termasuk token primary, slate, dan border —
           kini hanya didefinisikan SEKALI di blok root style.css. Blok root
           duplikat yang dulu ada di sini sudah dihapus: itulah yang bikin
           dashboard dan halaman lain punya nilai token berbeda (sumber bug
           elemen transparan saat token tidak ketemu).
           CATATAN: jangan menulis tanda tutup komentar di dalam komentar,
           itu memotong komentar dan membuang deklarasi CSS setelahnya. */
        /* Font ikut body dari style.css (Plus Jakarta Sans) — dulu system-ui. */
        * { box-sizing: border-box; margin: 0; padding: 0; }
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
        .dash-menu-item.active { background: var(--accent-gradient); color: #fff; box-shadow: 0 4px 12px rgba(167, 139, 250, 0.3); }
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
        
        .form-control { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; background: #fff; outline: none; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .btn-submit { padding: 10px 20px; border-radius: var(--radius-md); background: var(--accent-gradient); color: #fff; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 0.82rem; }
        
        .card-table-wrap { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; box-shadow: 0 4px 12px -2px rgba(139, 92, 246, 0.06); }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; text-align: left; }
        .data-table th, .data-table td { padding: 14px 16px; border-bottom: 1px solid var(--border-soft); vertical-align: middle; }
        .data-table th { background: var(--pastel-purple-bg); font-weight: 800; color: var(--pastel-purple); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .data-table tr:hover { background: var(--bg-page); }

        .curr-pill.active, .ship-pill.active, .tax-pill.active { background: var(--pastel-purple) !important; color: #fff !important; border-color: var(--pastel-purple) !important; box-shadow: 0 4px 12px rgba(139,92,246,0.25); }

        .fin-wrap { font-family: inherit; color: var(--text-dark); width: 100%; }
        .cycle-bar { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 16px 22px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 12px -2px rgba(139, 92, 246, 0.06); }
        .cycle-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .cycle-title-label { font-size: 13px; font-weight: 800; color: var(--slate-700); }
        .cycle-select, .year-select { padding: 8px 14px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; color: var(--slate-900); background: var(--slate-50); outline: none; cursor: pointer; }
        .cycle-info-pill { background: var(--pastel-purple-bg); padding: 8px 16px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--pastel-purple); border: 1px solid var(--pastel-purple-border); }

        .wallet-status-bar { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 14px 20px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; box-shadow: 0 2px 8px rgba(139,92,246,0.05); }
        .wallet-pills-wrap { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .wallet-pill { background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; color: #0369a1; }

        .btn-export-csv { background: #15803d; color: #ffffff; border: none; padding: 8px 18px; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(21, 128, 61, 0.25); transition: 0.2s; }
        .btn-export-csv:hover { background: #166534; transform: translateY(-2px); }
        .btn-reset-data { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 8px 14px; border-radius: 10px; font-family: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-reset-data:hover { background: #fecaca; }

        .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        @media (max-width: 980px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 540px) { .metrics-grid { grid-template-columns: 1fr; } }
        .metric-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px 22px; box-shadow: 0 4px 12px -2px rgba(139, 92, 246, 0.06); border-left: 5px solid var(--slate-400); }
        .metric-card.green { border-left-color: var(--emerald); }
        .metric-card.blue { border-left-color: #3b82f6; }
        .metric-card.amber { border-left-color: var(--amber); }
        .metric-card.highlight { background: var(--accent-gradient); color: #ffffff; border: 1px solid #8b5cf6; border-left: 5px solid #c084fc; box-shadow: 0 10px 25px -5px rgba(139, 92, 246, 0.35); }
        .metric-label { font-size: 11.5px; font-weight: 700; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
        .metric-val { font-size: 24px; font-weight: 800; color: var(--slate-900); letter-spacing: -0.5px; }
        .metric-card.highlight .metric-val { color: #ffffff; }
        .metric-card.highlight .metric-label { color: #ffffff; }
        .metric-sub { font-size: 12px; color: var(--slate-500); margin-top: 4px; font-weight: 600; }
        .metric-card.highlight .metric-sub { color: rgba(255, 255, 255, 0.85); }

        .roadmap-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 4px 12px -2px rgba(139, 92, 246, 0.06); }
        .roadmap-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
        .roadmap-title { font-size: 14px; font-weight: 800; color: var(--slate-900); }
        .roadmap-legend { font-size: 11px; font-weight: 700; color: var(--slate-400); }
        .roadmap-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 8px; }
        @media (max-width: 900px) { .roadmap-grid { grid-template-columns: repeat(6, 1fr); } }
        @media (max-width: 480px) { .roadmap-grid { grid-template-columns: repeat(3, 1fr); } }
        .roadmap-item { background: var(--slate-50); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 4px; text-align: center; cursor: pointer; transition: 0.2s; }
        .roadmap-item:hover { transform: translateY(-2px); border-color: var(--primary); }
        .roadmap-item.active { border: 2px solid var(--pastel-purple); background: var(--pastel-purple-bg); }
        .roadmap-month { font-size: 11px; font-weight: 800; color: var(--slate-700); margin-bottom: 2px; }
        .roadmap-icon { font-size: 13px; margin-bottom: 2px; }
        .roadmap-status-text { font-size: 10px; font-weight: 800; letter-spacing: 0.2px; }
        .roadmap-margin { font-size: 9.5px; color: var(--slate-500); font-weight: 700; margin-top: 2px; }

        .forms-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 24px; }
        @media (max-width: 920px) { .forms-grid { grid-template-columns: 1fr; } }
        .form-box { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 22px; box-shadow: 0 4px 12px -2px rgba(139, 92, 246, 0.06); display: flex; flex-direction: column; justify-content: space-between; }
        .form-box-title { font-size: 14.5px; font-weight: 800; color: var(--slate-900); border-bottom: 1px dashed var(--border); padding-bottom: 10px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .form-group-fin { margin-bottom: 12px; }
        .form-label-fin { display: block; font-size: 12px; font-weight: 700; color: var(--slate-700); margin-bottom: 4px; }
        .form-input-fin, .form-select-fin { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 600; color: var(--slate-900); outline: none; background: #fff; }
        .form-input-fin:focus, .form-select-fin:focus { border-color: #c4b5fd; box-shadow: 0 0 0 3px rgba(196, 181, 253, 0.25); }
        .btn-submit-action { width: 100%; padding: 10px; border-radius: 10px; border: none; font-family: inherit; font-size: 13px; font-weight: 800; color: #ffffff; cursor: pointer; margin-top: 6px; transition: 0.2s; }
        .btn-blue { background: var(--primary); } .btn-blue:hover { background: var(--primary-dark); }
        .btn-green { background: #16a34a; } .btn-green:hover { background: #15803d; }
        .btn-amber { background: #d97706; } .btn-amber:hover { background: #b45309; }

        .ledger-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; box-shadow: 0 10px 30px -5px rgba(139, 92, 246, 0.08); }
        .ledger-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-btn { background: var(--slate-100); border: 1px solid transparent; color: var(--slate-600); font-size: 12px; font-weight: 700; padding: 6px 14px; border-radius: 999px; cursor: pointer; transition: 0.2s; font-family: inherit; }
        .filter-btn:hover { background: var(--pastel-purple-border); }
        .filter-btn.active { background: var(--primary); color: #ffffff; }

        .badge-type { display: inline-block; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: 0.2px; text-transform: uppercase; }
        .type-topup { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .type-tmall { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .type-cargo1 { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .type-ops { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .btn-del-fin { background: transparent; border: none; color: var(--slate-400); cursor: pointer; font-size: 14px; padding: 4px 6px; border-radius: 6px; }
        .btn-del-fin:hover { color: var(--rose); background: #fee2e2; }

        /* Dashboard tenant — palet & komponen diselaraskan dengan beranda/auth. */
        .dashboard-wrapper {
            --pastel-purple: #6d5a91;
            --pastel-purple-bg: #f0ebf7;
            --pastel-purple-border: #ded7e9;
            --pastel-mint: #6f9278;
            --pastel-mint-bg: #edf4ee;
            --pastel-mint-border: #d6e5d8;
            --text-dark: #302b46;
            --text-muted: #625d70;
            --border-soft: #e5e0f2;
            --border: #e5e0f2;
            --bg-page: #faf9fc;
            --card-bg: #ffffff;
            --primary: #6d5a91;
            --primary-dark: #594a75;
            --accent-gradient: linear-gradient(135deg, #6d5a91, #51416f);
            --emerald: #6f9278;
            --slate-900: #302b46;
            --slate-800: #403a53;
            --slate-700: #514b63;
            --slate-600: #625d70;
            --slate-500: #625d70;
            --slate-400: #6b6579;
            --slate-100: #f0ebf7;
            --slate-50: #faf9fc;
            background: #faf9fc;
            color: var(--text-dark);
            min-height: calc(100vh - 140px);
        }
        body.admin-bar .dashboard-wrapper { min-height: calc(100vh - 172px); }
        .dash-sidebar {
            gap: 18px;
            padding: 22px 18px;
            border-right-color: #e5e0f2;
            background: #fff;
        }
        .dash-user-card {
            padding: 18px;
            border-color: #e2d9ec;
            border-radius: 16px;
            background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
        }
        .dash-user-card h4 { color: #302b46; font-size: .98rem; line-height: 1.4; overflow-wrap: anywhere; }
        .dash-user-card p { color: #6d5a91; }
        .dash-user-card > a {
            min-height: 38px;
            border: 1px solid rgba(89, 74, 117, .14);
            border-radius: 10px !important;
            box-shadow: 0 4px 12px rgba(79, 70, 149, .16) !important;
        }
        .dash-storage-card {
            border-color: #e5e0f2 !important;
            border-radius: 14px !important;
            box-shadow: 0 4px 14px rgba(59, 48, 95, .035);
        }
        .dash-menu { gap: 5px; }
        .dash-menu-item {
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid transparent;
            border-radius: 10px;
            color: #696478;
            font-size: .82rem;
            transition: background .16s ease, border-color .16s ease, color .16s ease;
        }
        .dash-menu-item > i { width: 18px; flex: 0 0 18px; color: #82788f; text-align: center; }
        .dash-menu-item:hover { border-color: #e6deef; background: #f7f4fa; color: #51416f; }
        .dash-menu-item:hover > i { color: #6d5a91; }
        .dash-menu-item.active {
            border-color: #e2d9ec;
            background: #f0ebf7;
            color: #51416f;
            box-shadow: inset 3px 0 #6d5a91;
        }
        .dash-menu-item.active > i { color: #6d5a91; }
        .dash-menu-item.disabled-menu { opacity: .48; }
        .dash-main-content {
            min-width: 0;
            padding: clamp(20px, 3vw, 34px);
            overflow-x: hidden;
        }
        .dash-main-content .card-table-wrap,
        .dash-main-content .ledger-card,
        .dash-main-content .table-responsive { max-width: 100%; overflow-x: auto; }
        .dash-overview-heading { margin-bottom: 22px; }
        .dash-overview-heading-row { display: flex; align-items: center; gap: 14px; }
        .dash-overview-module-icon {
            display: grid;
            flex: 0 0 48px;
            width: 48px;
            height: 48px;
            place-items: center;
            border: 1px solid #ded7e9;
            border-radius: 14px;
            background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
            color: #6d5a91;
            font-size: 1.08rem;
        }
        .dash-overview-heading h2 {
            margin: 0 0 4px;
            color: #302b46;
            font-size: clamp(1.3rem, 2.2vw, 1.6rem);
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -.035em;
            overflow-wrap: anywhere;
        }
        .dash-overview-heading p { max-width: 720px; color: #625d70; font-size: .9rem; line-height: 1.65; }
        @media (max-width: 600px) {
            .dash-overview-heading-row { align-items: flex-start; gap: 11px; }
            .dash-overview-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
            .dash-overview-heading p { font-size: .79rem; }
        }
        .stat-card,
        .metric-card,
        .overview-status-box,
        .card-table-wrap,
        .form-box,
        .ledger-card,
        .roadmap-card {
            border-color: #e5e0f2;
            border-radius: 16px;
            box-shadow: 0 5px 18px rgba(59, 48, 95, .045);
        }
        .stat-card { padding: 18px 19px; }
        .stat-card span { color: #625d70; letter-spacing: .035em; }
        .stat-card h3 { color: #302b46; font-size: clamp(1.1rem, 1.7vw, 1.35rem); line-height: 1.35; overflow-wrap: anywhere; }
        .overview-status-box { padding: clamp(18px, 2.4vw, 26px); }
        .panel-title { color: #302b46; }
        .panel-desc { color: #625d70; line-height: 1.65; }
        .info-row { gap: 14px; color: #514b63; }
        .info-row > span { color: #625d70; }
        .data-table th { background: #f2eef8; color: #62517f; }
        .data-table tr:hover { background: #faf9fc; }
        .form-control:focus,
        .form-input-fin:focus,
        .form-select-fin:focus,
        .cycle-select:focus,
        .year-select:focus {
            border-color: #9c8ab8;
            box-shadow: 0 0 0 3px rgba(109, 90, 145, .13);
        }
        .btn-submit,
        .btn-submit-action { border-radius: 10px; font-family: inherit; }
        .metric-card.highlight {
            border-color: #786592;
            border-left-color: #d6cae4;
            background: linear-gradient(135deg, #6d5a91, #51416f);
            box-shadow: 0 10px 24px rgba(81, 65, 111, .18);
        }
        .metric-card.highlight .metric-label { color: rgba(255,255,255,.78); }
        .wallet-pill { background: #f0ebf7; border-color: #ded7e9; color: #594a75; }
        .cycle-info-pill { color: #594a75; }
        .analytics-grid-3 canvas { max-width: 100%; }
        @media (max-width: 900px) {
            .dashboard-wrapper { grid-template-columns: minmax(0, 1fr); }
            body.admin-bar .dashboard-wrapper { min-height: auto; }
            .dash-sidebar {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(220px, .72fr);
                align-items: start;
                gap: 14px;
                padding: 16px 20px;
                border-right: 0;
                border-bottom: 1px solid #e5e0f2;
            }
            .dash-menu {
                grid-column: 1 / -1;
                display: flex;
                flex-wrap: nowrap;
                gap: 7px;
                overflow-x: auto;
                padding: 2px 2px 7px;
                scrollbar-width: thin;
                scrollbar-color: #d9d0e6 transparent;
            }
            .dash-menu-item { width: auto; flex: 0 0 auto; white-space: nowrap; }
            .dash-main-content { padding: 24px 20px; }
        }
        @media (max-width: 600px) {
            .dashboard-wrapper { min-height: calc(100vh - 138px); }
            .dash-sidebar { grid-template-columns: minmax(0, 1fr); gap: 12px; padding: 14px 16px; }
            .dash-menu { grid-column: 1; margin-inline: -2px; }
            .dash-main-content { padding: 20px 16px 32px; }
            .dash-overview-heading { margin-bottom: 18px; }
            .stats-grid-6 { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .stat-card { padding: 14px 13px; border-radius: 13px; }
            .stat-card span { font-size: .65rem; }
            .stat-card h3 { font-size: 1rem; }
            .overview-status-box,
            .card-table-wrap,
            .form-box,
            .ledger-card { padding: 17px 14px; border-radius: 14px; }
            .info-row { align-items: flex-start; font-size: .8rem; }
            .info-row strong { text-align: right; }
            .analytics-grid-3 { gap: 16px; }
        }
        @media (max-width: 380px) {
            .stats-grid-6 { grid-template-columns: 1fr; }
        }
    </style>

<div class="dashboard-wrapper">
    <!-- SIDEBAR -->
    <aside class="dash-sidebar">
        <div class="dash-user-card">
            <h4><?php echo esc_html($store_name); ?></h4>
            <p><i class="fa-solid fa-circle-check"></i> Owner: <?php echo esc_html($admin_name); ?></p>
            <a href="<?php echo esc_url($store_url); ?>" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; padding: 8px 12px; background: var(--accent-gradient); color: #fff; font-size: 0.78rem; font-weight: 700; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 10px rgba(167,139,250,0.2);">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Lihat Web Publik
            </a>
        </div>
        
        <!-- PROGRESS BAR STORAGE -->
        <div class="dash-storage-card" style="background: #fff; border: 1px solid var(--border-soft); padding: 16px; border-radius: var(--radius-md);">
            <h4 style="font-size: 0.8rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Kapasitas Storage</h4>
            <div style="background: var(--border-soft); border-radius: 999px; height: 8px; width: 100%; overflow: hidden; margin-bottom: 6px;">
                <div style="background: <?php echo ($storage_pct > 85) ? '#ef4444' : 'var(--pastel-purple)'; ?>; height: 100%; width: <?php echo $storage_pct; ?>%;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.72rem; font-weight: 700; color: var(--text-muted);">
                <span><?php echo esc_html( goweb_format_storage_size( $used_mb ) ); ?></span>
                <span><?php echo esc_html( goweb_format_storage_size( $limit_mb ) ); ?></span>
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

            <a href="<?php echo esc_url(home_url('/dashboard/testimonials/') . $store_query_suffix); ?>" class="dash-menu-item <?php echo (!$has_testi) ? 'disabled-menu' : (($current_subpage == 'testimonials') ? 'active' : ''); ?>"><i class="fa-solid fa-comments"></i> Testimoni</a>
            
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
            <div class="alert-box solo alert-error">
                <span><i class="fa-solid fa-triangle-exclamation"></i> Akun Anda sedang Offline / Dinonaktifkan oleh Super Admin.</span>
            </div>
        <?php elseif ($active_urgency === 'critical'): ?>
            <div class="alert-box alert-error">
                <span><i class="fa-solid fa-circle-exclamation"></i> <?php echo $remaining_days > 0 ? 'Masa aktif sewa tinggal ' . $remaining_days . ' hari lagi! Lewat jatuh tempo, toko langsung offline & data berisiko terhapus.' : 'Masa aktif sewa udah habis! Segera perpanjang sebelum data terhapus permanen.'; ?></span>
                <a href="<?php echo esc_url(home_url('/dashboard/subscription/') . $store_query_suffix); ?>" style="background:#991b1b; color:#fff; padding:8px 16px; border-radius:8px; font-weight:800; text-decoration:none; white-space:nowrap;">Perpanjang Sekarang</a>
            </div>
        <?php elseif ($active_urgency === 'warning'): ?>
            <div class="alert-box alert-warning">
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
            'testimonials' => 'menu-testimonials.php',
            'finance'      => 'menu-finance.php',
            'subscription' => 'menu-subscription.php',
            'setting'      => 'menu-setting.php'
        );

        if ($current_subpage == 'overview' || empty($current_subpage)) {
            // Tampilkan Ringkasan Toko langsung di sini
            ?>
            <div class="dash-overview-heading">
                <div class="dash-overview-heading-row">
                    <span class="dash-overview-module-icon" aria-hidden="true"><i class="fa-solid fa-chart-pie"></i></span>
                    <div>
                        <h2>Dashboard Proxy/Jastip: <?php echo esc_html($store_name); ?></h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">Berikut ringkasan aktivitas operasional dan status modul fitur akun kamu.</p>
                    </div>
                </div>
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
                        <div style="font-size: 13px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Tren Volume Order (12 Bulan)</div>
                        <canvas id="chartVolumeOrder" height="180"></canvas>
                    </div>
                    <div style="min-width: 0;">
                        <div style="font-size: 13px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Tren Omset dari Customer (12 Bulan)</div>
                        <canvas id="chartOmsetCustomer" height="180"></canvas>
                    </div>
                    <div style="min-width: 0;">
                        <div style="font-size: 13px; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Distribusi Status Order</div>
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
                const PALETTE = ['#6d5a91','#7890a3','#b89462','#6f9278','#b56e69','#9b7899','#777188','#6f9994'];

                if (window.Chart) {
                    new Chart(document.getElementById('chartVolumeOrder'), {
                        type: 'bar',
                        data: { labels: monthLabels, datasets: [{ label: 'Order', data: volumeData, backgroundColor: '#8b79a7', borderRadius: 4 }] },
                        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                    });
                    new Chart(document.getElementById('chartOmsetCustomer'), {
                        type: 'line',
                        data: { labels: monthLabels, datasets: [{ label: 'Omset (Rp)', data: omsetData, borderColor: '#7890a3', backgroundColor: 'rgba(120,144,163,0.14)', fill: true, tension: 0.3 }] },
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
                <div class="info-row"><span>Testimoni</span><strong style="color: <?php echo $has_testi ? 'var(--pastel-mint)' : '#ef4444'; ?>;"><?php echo $has_testi ? 'Aktif' : 'Nonaktif'; ?></strong></div>
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
        <style id="goweb-admin-ui-system">
            .dash-main-content {
                --admin-surface: #ffffff;
                --admin-canvas: #faf9fc;
                --admin-line: #e5e0f2;
                --admin-ink: #302b46;
                --admin-muted: #514b63;
                --admin-brand: #6d5a91;
                --admin-brand-dark: #51416f;
                --admin-brand-tint: #f0ebf7;
                --admin-radius-card: 16px;
                --admin-radius-control: 10px;
                --admin-shadow-card: 0 5px 18px rgba(59, 48, 95, .045);
            }
            .dash-main-content .mc-page-heading,
            .dash-main-content .payments-heading,
            .dash-main-content .testi-heading,
            .dash-main-content .finance-heading,
            .dash-main-content .subscription-heading,
            .dash-main-content .ms-setting-page-heading,
            .dash-main-content .dash-overview-heading-row {
                align-items: center;
                gap: 14px;
                margin-bottom: 22px;
            }
            .dash-main-content .menu-po-heading-row,
            .dash-main-content .menu-tagihan-heading-row,
            .dash-main-content .menu-warehouse-heading-row {
                align-items: center;
                gap: 14px;
            }
            .dash-main-content .mc-heading-icon,
            .dash-main-content .payments-heading-icon,
            .dash-main-content .menu-po-module-icon,
            .dash-main-content .menu-tagihan-module-icon,
            .dash-main-content .menu-warehouse-module-icon,
            .dash-main-content .testi-heading-icon,
            .dash-main-content .finance-heading-icon,
            .dash-main-content .subscription-heading-icon,
            .dash-main-content .dash-overview-module-icon,
            .dash-main-content .ms-setting-page-heading .ms-setting-icon {
                flex-basis: 48px;
                width: 48px;
                height: 48px;
                border: 1px solid #ded7e9;
                border-radius: 14px;
                background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
                color: var(--admin-brand);
                font-size: 1.08rem;
            }
            .dash-main-content .mc-page-heading h2,
            .dash-main-content .payments-heading h2,
            .dash-main-content .menu-po-heading h2,
            .dash-main-content .menu-tagihan-heading h2,
            .dash-main-content .menu-warehouse-heading h2,
            .dash-main-content .testi-heading h2,
            .dash-main-content .finance-heading h2,
            .dash-main-content .subscription-heading h2,
            .dash-main-content .ms-setting-page-heading h2,
            .dash-main-content .dash-overview-heading h2 {
                color: var(--admin-ink) !important;
                font-size: clamp(1.25rem, 2vw, 1.5rem) !important;
                font-weight: 800;
                line-height: 1.3;
                letter-spacing: -.03em;
            }
            .dash-main-content .mc-page-heading p,
            .dash-main-content .payments-heading p,
            .dash-main-content .menu-po-heading p,
            .dash-main-content .menu-tagihan-heading p,
            .dash-main-content .menu-warehouse-heading p,
            .dash-main-content .testi-heading p,
            .dash-main-content .finance-heading p,
            .dash-main-content .subscription-heading p,
            .dash-main-content .ms-setting-page-heading p,
            .dash-main-content .dash-overview-heading p {
                color: var(--admin-muted) !important;
                font-size: .84rem !important;
                line-height: 1.6;
            }
            .dash-main-content .stat-card,
            .dash-main-content .metric-card,
            .dash-main-content .overview-status-box,
            .dash-main-content .card-table-wrap,
            .dash-main-content .form-box,
            .dash-main-content .ledger-card,
            .dash-main-content .roadmap-card,
            .dash-main-content .mc-input-card,
            .dash-main-content .payments-summary-card,
            .dash-main-content .payments-table-card,
            .dash-main-content .testi-entry,
            .dash-main-content .testi-empty,
            .dash-main-content .subscription-panel,
            .dash-main-content .settings-page .ms-setting-card {
                border-color: var(--admin-line) !important;
                border-radius: var(--admin-radius-card) !important;
                box-shadow: var(--admin-shadow-card) !important;
            }
            .dash-main-content .form-control,
            .dash-main-content .form-input-fin,
            .dash-main-content .form-select-fin,
            .dash-main-content .cycle-select,
            .dash-main-content .year-select {
                min-height: 42px;
                border-color: var(--admin-line);
                border-radius: var(--admin-radius-control);
                color: var(--admin-ink);
                font-family: inherit;
            }
            .dash-main-content button,
            .dash-main-content [role="button"] {
                font-family: inherit;
            }
            .dash-main-content .filter-btn,
            .dash-main-content .curr-pill,
            .dash-main-content .ship-pill,
            .dash-main-content .tax-mode-pill,
            .dash-main-content .testi-tab,
            .dash-main-content .btn-submit,
            .dash-main-content .btn-submit-action,
            .dash-main-content .btn-export-csv,
            .dash-main-content .btn-reset-data,
            .dash-main-content .testi-action,
            .dash-main-content .payments-page-button,
            .dash-main-content .finance-page-control,
            .dash-main-content .finance-page-number,
            .dash-main-content .wh-page-button,
            .dash-main-content .ms-settings-tab {
                min-height: 40px;
                border-radius: var(--admin-radius-control);
                font-family: inherit;
            }
            .dash-main-content .data-table th {
                background: #f2eef8 !important;
                color: #51416f !important;
                font-size: .72rem !important;
                font-weight: 800;
                letter-spacing: .035em;
            }
            .dash-main-content .data-table td { border-bottom-color: #f0edf5; }
            .dash-main-content .testi-metric-label { color: #514b63; }
            .dash-main-content .menu-finance .metric-label { font-size: .69rem; }
            .dash-main-content .payment-delete-button,
            .dash-main-content .btn-del-fin { min-width: 40px; min-height: 40px; }
            .dash-main-content :is(a, button, input, select, textarea, [tabindex]):focus-visible {
                outline: 3px solid rgba(109, 90, 145, .48);
                outline-offset: 2px;
            }
            .dash-main-content .modal-overlay,
            .dash-main-content .subscription-modal-overlay {
                background: rgba(31, 27, 41, .58) !important;
                backdrop-filter: blur(4px);
            }
            .dash-main-content .modal-overlay > .modal-box,
            .dash-main-content .modal-overlay > div {
                border: 1px solid var(--admin-line);
                border-radius: 18px !important;
                box-shadow: 0 18px 54px rgba(36, 29, 52, .24) !important;
            }
            @media (max-width: 600px) {
                .dash-main-content .mc-page-heading,
                .dash-main-content .payments-heading,
                .dash-main-content .testi-heading,
                .dash-main-content .finance-heading,
                .dash-main-content .subscription-heading,
                .dash-main-content .ms-setting-page-heading,
                .dash-main-content .dash-overview-heading-row {
                    align-items: flex-start;
                    gap: 11px;
                    margin-bottom: 18px;
                }
                .dash-main-content .menu-po-heading-row,
                .dash-main-content .menu-tagihan-heading-row,
                .dash-main-content .menu-warehouse-heading-row {
                    align-items: flex-start;
                    gap: 11px;
                }
                .dash-main-content .mc-heading-icon,
                .dash-main-content .payments-heading-icon,
                .dash-main-content .menu-po-module-icon,
                .dash-main-content .menu-tagihan-module-icon,
                .dash-main-content .menu-warehouse-module-icon,
                .dash-main-content .testi-heading-icon,
                .dash-main-content .finance-heading-icon,
                .dash-main-content .subscription-heading-icon,
                .dash-main-content .dash-overview-module-icon,
                .dash-main-content .ms-setting-page-heading .ms-setting-icon {
                    flex-basis: 42px;
                    width: 42px;
                    height: 42px;
                    border-radius: 13px;
                    font-size: .98rem;
                }
                .dash-main-content .mc-page-heading h2,
                .dash-main-content .payments-heading h2,
                .dash-main-content .menu-po-heading h2,
                .dash-main-content .menu-tagihan-heading h2,
                .dash-main-content .menu-warehouse-heading h2,
                .dash-main-content .testi-heading h2,
                .dash-main-content .finance-heading h2,
                .dash-main-content .subscription-heading h2,
                .dash-main-content .ms-setting-page-heading h2,
                .dash-main-content .dash-overview-heading h2 { font-size: 1.15rem !important; }
                .dash-main-content .mc-page-heading p,
                .dash-main-content .payments-heading p,
                .dash-main-content .menu-po-heading p,
                .dash-main-content .menu-tagihan-heading p,
                .dash-main-content .menu-warehouse-heading p,
                .dash-main-content .testi-heading p,
                .dash-main-content .finance-heading p,
                .dash-main-content .subscription-heading p,
                .dash-main-content .ms-setting-page-heading p,
                .dash-main-content .dash-overview-heading p { font-size: .79rem !important; }
                .dash-main-content .ms-setting-card .ms-setting-card-heading h3 { font-size: .94rem; }
                .dash-main-content .ms-setting-card .ms-setting-card-heading p { font-size: .76rem !important; }
            }
            @media (prefers-reduced-motion: reduce) {
                .dash-main-content .dash-menu-item,
                .dash-main-content button,
                .dash-main-content a,
                .dash-main-content .stat-card,
                .dash-main-content .metric-card,
                .dash-main-content .testi-entry { transition: none !important; }
            }
        </style>
    </main>
</div>

<?php get_footer(); ?>