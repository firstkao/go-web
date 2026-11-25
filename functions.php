<?php
/**
 * ======================================================================
 * SISTEM AUTH CUSTOM UNTUK TENANT (BUKAN akun WordPress)
 * ======================================================================
 * Tenant TIDAK dibuatkan akun di tabel wp_users. Login tenant dicek
 * langsung ke option goweb_all_tenants (field 'email' + 'password' yg
 * di-hash pakai wp_hash_password saat registrasi/approve). Sesi dipegang
 * lewat cookie custom berisi tenant_id + token acak; yang disimpan di
 * database cuma HASH dari token itu (bukan token mentahnya), supaya kalau
 * option goweb_all_tenants ini bocor pun sesi yang aktif tidak bisa
 * dibajak langsung dari situ.
 *
 * Super Admin TETAP pakai akun WP asli (current_user_can('administrator'))
 * karena dia memang perlu masuk /wp-admin — beda konteks dari tenant.
 * ======================================================================
 */

define( 'GOWEB_TENANT_COOKIE', 'goweb_tenant_auth' );
define( 'GOWEB_TENANT_SESSION_DAYS', 14 );

/**
 * ======================================================================
 * TABEL DATABASE CUSTOM (menggantikan wp_options untuk data yang tumbuh
 * terus per-transaksi)
 * ======================================================================
 * Data seperti riwayat pembayaran, order PO, dan ledger keuangan
 * SEBELUMNYA disimpan sebagai array PHP yang di-serialize jadi satu blob
 * per tenant di wp_options (goweb_sub_history_*, jastip_orders_*,
 * goweb_finance_data_*). Ini punya 2 masalah kalau tenant makin banyak:
 *   1. Setiap baca/tulis HARUS ambil SELURUH riwayat tenant itu sekaligus
 *      (gak bisa "ambil 20 baris terakhir doang" di level database).
 *   2. Kalau opsi itu ke-mark autoload, tiap request di SELURUH situs ikut
 *      menanggung beban unserialize-nya, padahal cuma dashboard tenant
 *      itu doang yang butuh datanya.
 *
 * Tabel-tabel di bawah ini: 1 baris = 1 transaksi, punya index di
 * store_id, jadi bisa di-query pakai WHERE/ORDER BY/LIMIT sungguhan.
 * Data lama tetap dibiarkan ada di wp_options sampai proses migrasi tiap
 * modul selesai dan sudah dites.
 * ======================================================================
 */

define( 'GOWEB_DB_VERSION', '1.16.0' ); // 1.16.0: kategori tagihan 255 karakter, konsisten dengan pengaturan/pembayaran

function goweb_get_table_names() {
    global $wpdb;
    return array(
        'payments'    => $wpdb->prefix . 'goweb_payments',
        'orders'      => $wpdb->prefix . 'goweb_orders',
        'invoices'    => $wpdb->prefix . 'goweb_invoices',
        'warehouse'   => $wpdb->prefix . 'goweb_warehouse',
        'wh_boxes'    => $wpdb->prefix . 'goweb_wh_boxes',
        'ledger'      => $wpdb->prefix . 'goweb_finance_ledger',
        'wallets'     => $wpdb->prefix . 'goweb_wallets',
        'tenants'     => $wpdb->prefix . 'goweb_tenants',
        'pending'     => $wpdb->prefix . 'goweb_pending_registrations',
        'settings'    => $wpdb->prefix . 'goweb_tenant_settings',
        'testimonials' => $wpdb->prefix . 'goweb_testimonials',
    );
}

/**
 * Bikin (atau update skema) 4 tabel custom di atas. Aman dipanggil
 * berkali-kali — dbDelta() cuma bikin perubahan kalau skema di database
 * beneran beda dari yang didefinisikan di sini.
 */
function goweb_create_custom_tables() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $charset_collate = $wpdb->get_charset_collate();
    $t = goweb_get_table_names();

    // 1. RIWAYAT PEMBAYARAN (gabung PO + perpanjangan sewa, dibedakan
    //    lewat kolom payment_category — gantiin trik lama yg nebak dari
    //    string 'PO:' di field period)
    $sql_payments = "CREATE TABLE {$t['payments']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        payment_category VARCHAR(20) NOT NULL DEFAULT 'po',
        period VARCHAR(255) NOT NULL DEFAULT '',
        payment_type VARCHAR(255) NULL,
        payment_destination VARCHAR(255) NULL,
        nominal BIGINT NOT NULL DEFAULT 0,
        sender VARCHAR(255) NOT NULL DEFAULT '',
        proof_url TEXT NULL,
        payment_date DATE NULL,
        verified_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        requested_package VARCHAR(20) NULL,
        requested_duration_months INT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY status (status),
        KEY payment_category (payment_category)
    ) $charset_collate;";

    // 2. REKAPAN ORDER PO (modul ini sebelumnya belum pernah beneran
    //    tersambung ke database — dibangun langsung di sini)
    $sql_orders = "CREATE TABLE {$t['orders']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        batch VARCHAR(255) NOT NULL DEFAULT '',
        buyer_name VARCHAR(255) NOT NULL DEFAULT '',
        item_detail TEXT NULL,
        qty INT NOT NULL DEFAULT 1,
        total_value DECIMAL(18,2) NOT NULL DEFAULT 0,
        order_status VARCHAR(50) NOT NULL DEFAULT '',
        payment_status VARCHAR(20) NOT NULL DEFAULT 'Belum Lunas',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY order_status (order_status)
    ) $charset_collate;";

    // 2B. REKAPAN TAGIHAN (terpisah dari orders — 1 PO bisa punya banyak
    //    baris tagihan: DP, Ongkir WH, Ongkir Kargo, Pajak, Packing,
    //    Pelunasan, dll, masing2 status bayarnya sendiri2 dan MANUAL,
    //    gak ke-link otomatis ke Konfirmasi Pembayaran)
    $sql_invoices = "CREATE TABLE {$t['invoices']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(255) NOT NULL DEFAULT 'lainnya',
        nominal BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Belum Bayar',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY order_id (order_id),
        KEY status (status)
    ) $charset_collate;";

    // 2C. DATABASE WAREHOUSE (tracking fisik barang di gudang — beda dari
    //    order_status di Rekapan PO yang cuma garis besar. order_id NULLABLE
    //    karena tenant bisa milih mode "wajib link ke PO" atau "bebas
    //    berdiri sendiri" lewat Pengaturan, lihat wh_link_mode)
    $sql_warehouse = "CREATE TABLE {$t['warehouse']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        order_id BIGINT UNSIGNED NULL,
        box_id BIGINT UNSIGNED NULL,
        buyer_name VARCHAR(255) NOT NULL DEFAULT '',
        resi_masuk VARCHAR(100) NOT NULL DEFAULT '',
        resi_keluar VARCHAR(100) NOT NULL DEFAULT '',
        slot VARCHAR(50) NOT NULL DEFAULT '',
        lokasi_wh VARCHAR(100) NOT NULL DEFAULT '',
        foto_bukti TEXT NULL,
        berat DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(50) NOT NULL DEFAULT 'Menunggu',
        tanggal_masuk DATE NULL,
        tanggal_keluar DATE NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY order_id (order_id),
        KEY box_id (box_id),
        KEY status (status)
    ) $charset_collate;";

    // 2D. BOX KONSOLIDASI WAREHOUSE — beberapa item Warehouse dari
    //    customer berbeda dikumpulin jadi 1 Box sebelum dikirim bareng.
    //    Box nge-track kapasitas berat gabungan vs total berat item yang
    //    ditugaskan ke situ.
    $sql_wh_boxes = "CREATE TABLE {$t['wh_boxes']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        box_name VARCHAR(100) NOT NULL DEFAULT '',
        lokasi VARCHAR(100) NOT NULL DEFAULT '',
        kapasitas_kg DECIMAL(10,2) NOT NULL DEFAULT 20,
        status VARCHAR(50) NOT NULL DEFAULT 'Packing In Progress',
        -- status_changed_at: kapan status box terakhir berubah. Dipakai buat
        -- aturan: box yang sudah sampai Indo cuma disimpan ~2 minggu
        -- (dihitung dari perubahan status, bukan dari tanggal box dibuat).
        -- archived_at: penanda box sudah kedaluwarsa & disembunyikan otomatis
        -- dari UI. Barisnya TIDAK langsung dihapus, supaya riwayat kiriman
        -- pembeli masih bisa diaudit kalau ada sengketa.
        status_changed_at DATETIME NULL,
        archived_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY status (status),
        KEY archived_at (archived_at)
    ) $charset_collate;";

    // 3. LEDGER MUTASI KEUANGAN
    $sql_ledger = "CREATE TABLE {$t['ledger']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        tx_date DATE NOT NULL,
        tx_type VARCHAR(30) NOT NULL,
        wallet_name VARCHAR(100) NOT NULL DEFAULT '-',
        title VARCHAR(255) NOT NULL DEFAULT '',
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        rate DECIMAL(18,2) NOT NULL DEFAULT 0,
        idr_value DECIMAL(18,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY tx_date (tx_date)
    ) $charset_collate;";

    // 4. SALDO DOMPET DIGITAL (satu baris per tenant per nama dompet)
    $sql_wallets = "CREATE TABLE {$t['wallets']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        wallet_name VARCHAR(100) NOT NULL,
        balance DECIMAL(18,2) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY store_wallet (store_id, wallet_name)
    ) $charset_collate;";

    // 5. DATA TENANT UTAMA (gantiin option goweb_all_tenants — 1 baris per
    //    tenant, bukan 1 array besar yang ke-load penuh tiap request).
    //    tenant_id tetap format string 'tenant_<timestamp>' yang sama kayak
    //    sebelumnya, biar semua foreign key store_id di 4 tabel di atas
    //    (payments/orders/ledger/wallets) tetap valid tanpa perlu diubah.
    $sql_tenants = "CREATE TABLE {$t['tenants']} (
        tenant_id VARCHAR(64) NOT NULL,
        store_name VARCHAR(255) NOT NULL DEFAULT '',
        admin_name VARCHAR(255) NOT NULL DEFAULT '',
        email VARCHAR(255) NOT NULL DEFAULT '',
        slug VARCHAR(255) NOT NULL DEFAULT '',
        password VARCHAR(255) NOT NULL DEFAULT '',
        package_key VARCHAR(20) NOT NULL DEFAULT 'starter',
        duration INT NOT NULL DEFAULT 30,
        active_until BIGINT NOT NULL DEFAULT 0,
        cycle_daily_rate DECIMAL(18,2) NOT NULL DEFAULT 0,
        storage_limit BIGINT NOT NULL DEFAULT 250,
        storage_used BIGINT NOT NULL DEFAULT 0,
        features TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        session_token_hash VARCHAR(255) NULL,
        session_expires BIGINT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (tenant_id),
        KEY email (email),
        KEY slug (slug),
        KEY status (status)
    ) $charset_collate;";

    // 6. ANTREAN PENDAFTARAN TENANT BARU (gantiin option goweb_pending_registrations)
    $sql_pending = "CREATE TABLE {$t['pending']} (
        request_id VARCHAR(64) NOT NULL,
        store_name VARCHAR(255) NOT NULL DEFAULT '',
        admin_name VARCHAR(255) NOT NULL DEFAULT '',
        email VARCHAR(255) NOT NULL DEFAULT '',
        slug VARCHAR(255) NOT NULL DEFAULT '',
        password VARCHAR(255) NOT NULL DEFAULT '',
        package_key VARCHAR(20) NOT NULL DEFAULT 'starter',
        package_name VARCHAR(50) NOT NULL DEFAULT 'Starter',
        storage_limit BIGINT NOT NULL DEFAULT 250,
        proof_url TEXT NULL,
        features TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (request_id)
    ) $charset_collate;";

    // 7. PENGATURAN PER-TENANT (gantiin jastip_active_rates_*,
    //    jastip_cargo_rates_*, jastip_custom_statuses_* — key-value biar
    //    fleksibel, 1 tenant bisa punya beberapa baris setting berbeda)
    $sql_settings = "CREATE TABLE {$t['settings']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY tenant_setting (tenant_id, setting_key)
    ) $charset_collate;";

    dbDelta( $sql_payments );
    // Pastikan panjang snapshot pilihan buyer sesuai batas pengaturan. dbDelta
    // kadang tidak mengubah tabel pada hosting dengan skema lama/collation
    // tertentu; fallback ALTER mencegah submit publik gagal atau memotong nilai.
    $payment_type_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_type'", ARRAY_A );
    if ( ! $payment_type_column ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} ADD COLUMN payment_type VARCHAR(255) NULL" );
        $payment_type_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_type'", ARRAY_A );
    }
    $payment_type_column_type = strtolower( (string) ( $payment_type_column['Type'] ?? '' ) );
    $payment_type_column_ok = false;
    if ( preg_match( '/^varchar\\((\\d+)\\)$/', $payment_type_column_type, $payment_type_length ) ) {
        $payment_type_column_ok = intval( $payment_type_length[1] ) >= 255;
    } elseif ( in_array( $payment_type_column_type, array( 'text', 'mediumtext', 'longtext' ), true ) ) {
        $payment_type_column_ok = true;
    }
    if ( ! $payment_type_column_ok ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} MODIFY COLUMN payment_type VARCHAR(255) NULL" );
        $payment_type_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_type'", ARRAY_A );
        $payment_type_column_type = strtolower( (string) ( $payment_type_column['Type'] ?? '' ) );
        $payment_type_column_ok = preg_match( '/^varchar\\((\\d+)\\)$/', $payment_type_column_type, $payment_type_length )
            ? intval( $payment_type_length[1] ) >= 255
            : in_array( $payment_type_column_type, array( 'text', 'mediumtext', 'longtext' ), true );
    }
    $payment_period_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['payments']} LIKE 'period'", ARRAY_A );
    if ( ! $payment_period_column ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} ADD COLUMN period VARCHAR(255) NOT NULL DEFAULT ''" );
        $payment_period_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['payments']} LIKE 'period'", ARRAY_A );
    }
    $payment_period_column_type = strtolower( (string) ( $payment_period_column['Type'] ?? '' ) );
    $payment_period_column_ok = false;
    if ( preg_match( '/^varchar\\((\\d+)\\)$/', $payment_period_column_type, $period_length ) ) {
        $payment_period_column_ok = intval( $period_length[1] ) >= 255;
    } elseif ( in_array( $payment_period_column_type, array( 'text', 'mediumtext', 'longtext' ), true ) ) {
        $payment_period_column_ok = true;
    }
    if ( ! $payment_period_column_ok ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} MODIFY COLUMN period VARCHAR(255) NOT NULL DEFAULT ''" );
        $payment_period_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['payments']} LIKE 'period'", ARRAY_A );
        $payment_period_column_type = strtolower( (string) ( $payment_period_column['Type'] ?? '' ) );
        $payment_period_column_ok = preg_match( '/^varchar\\((\\d+)\\)$/', $payment_period_column_type, $period_length )
            ? intval( $period_length[1] ) >= 255
            : in_array( $payment_period_column_type, array( 'text', 'mediumtext', 'longtext' ), true );
    }
    $payment_destination_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_destination'" );
    if ( $payment_destination_column !== 'payment_destination' ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} ADD COLUMN payment_destination VARCHAR(255) NULL" );
        $payment_destination_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_destination'" );
    }
    $payment_date_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_date'" );
    if ( $payment_date_column !== 'payment_date' ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} ADD COLUMN payment_date DATE NULL" );
        $payment_date_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['payments']} LIKE 'payment_date'" );
    }
    $payment_verified_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['payments']} LIKE 'verified_at'" );
    if ( $payment_verified_column !== 'verified_at' ) {
        $wpdb->query( "ALTER TABLE {$t['payments']} ADD COLUMN verified_at DATETIME NULL" );
        $payment_verified_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['payments']} LIKE 'verified_at'" );
    }
    dbDelta( $sql_orders );
    // 2D. TESTIMONI — dikirim pembeli lewat portal publik, dimoderasi admin
    //    tenant (Pending -> Approved/Rejected). Cuma yang Approved tampil publik.
    $sql_testimonials = "CREATE TABLE {$t['testimonials']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id VARCHAR(64) NOT NULL,
        buyer_name VARCHAR(100) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
        message TEXT NOT NULL,
        photo_url TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY store_id (store_id),
        KEY status (status)
    ) $charset_collate;";

    dbDelta( $sql_invoices );
    // Jenis pembayaran/tagihan yang sama dipakai oleh Konfirmasi Pembayaran
    // dan Rekapan Tagihan. Pertahankan batas 255 karakter pada kedua modul;
    // fallback ini menangani hosting lama yang dbDelta-nya tidak melebarkan kolom.
    $invoice_category_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['invoices']} LIKE 'category'", ARRAY_A );
    if ( ! $invoice_category_column ) {
        $wpdb->query( "ALTER TABLE {$t['invoices']} ADD COLUMN category VARCHAR(255) NOT NULL DEFAULT 'lainnya'" );
        $invoice_category_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['invoices']} LIKE 'category'", ARRAY_A );
    }
    $invoice_category_column_type = strtolower( (string) ( $invoice_category_column['Type'] ?? '' ) );
    $invoice_category_column_ok = false;
    if ( preg_match( '/^varchar\\((\\d+)\\)$/', $invoice_category_column_type, $invoice_category_length ) ) {
        $invoice_category_column_ok = intval( $invoice_category_length[1] ) >= 255;
    } elseif ( in_array( $invoice_category_column_type, array( 'text', 'mediumtext', 'longtext' ), true ) ) {
        $invoice_category_column_ok = true;
    }
    if ( ! $invoice_category_column_ok ) {
        $wpdb->query( "ALTER TABLE {$t['invoices']} MODIFY COLUMN category VARCHAR(255) NOT NULL DEFAULT 'lainnya'" );
        $invoice_category_column = $wpdb->get_row( "SHOW COLUMNS FROM {$t['invoices']} LIKE 'category'", ARRAY_A );
        $invoice_category_column_type = strtolower( (string) ( $invoice_category_column['Type'] ?? '' ) );
        $invoice_category_column_ok = preg_match( '/^varchar\\((\\d+)\\)$/', $invoice_category_column_type, $invoice_category_length )
            ? intval( $invoice_category_length[1] ) >= 255
            : in_array( $invoice_category_column_type, array( 'text', 'mediumtext', 'longtext' ), true );
    }
    dbDelta( $sql_testimonials );
    // Fallback untuk hosting/tabel lama yang tidak menerapkan perubahan dbDelta.
    $testimonial_photo_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['testimonials']} LIKE 'photo_url'" );
    if ( $testimonial_photo_column !== 'photo_url' ) {
        $wpdb->query( "ALTER TABLE {$t['testimonials']} ADD COLUMN photo_url TEXT NULL" );
        $testimonial_photo_column = $wpdb->get_var( "SHOW COLUMNS FROM {$t['testimonials']} LIKE 'photo_url'" );
    }
    dbDelta( $sql_warehouse );
    dbDelta( $sql_wh_boxes );
    dbDelta( $sql_ledger );
    dbDelta( $sql_wallets );
    dbDelta( $sql_tenants );
    dbDelta( $sql_pending );
    dbDelta( $sql_settings );

    // Jangan tandai upgrade selesai jika kolom pembayaran, kategori tagihan,
    // atau foto testimoni belum berhasil diperbarui; request berikutnya mencoba ulang.
    if ( $payment_type_column_ok && $payment_period_column_ok && $payment_destination_column === 'payment_destination' && $payment_date_column === 'payment_date' && $payment_verified_column === 'verified_at' && $invoice_category_column_ok && $testimonial_photo_column === 'photo_url' ) {
        update_option( 'goweb_db_version', GOWEB_DB_VERSION );
    }
}

function goweb_maybe_upgrade_db() {
    if ( get_option( 'goweb_db_version' ) !== GOWEB_DB_VERSION ) {
        goweb_create_custom_tables();
    }
}
add_action( 'after_setup_theme', 'goweb_maybe_upgrade_db' );

/**
 * ======================================================================
 * MIGRASI DATA LAMA: wp_options -> tabel custom
 * ======================================================================
 * Jalan SEKALI doang (dijaga lewat opsi goweb_data_migration_version).
 * Data lama di wp_options TIDAK dihapus di tahap ini — cuma disalin. Ini
 * jaring pengaman: kalau ada yang meleset di tabel baru, data asli masih
 * utuh dan gampang dicek ulang. Penghapusan data lama itu langkah
 * terpisah, dilakukan nanti setelah semua modul beneran jalan di atas
 * tabel baru dan sudah dites.
 * ======================================================================
 */

define( 'GOWEB_DATA_MIGRATION_VERSION', '1.0.0' );

/**
 * Parse tanggal format lama ('d/m/Y' atau 'd/m/Y H:i') jadi format
 * DATETIME MySQL ('Y-m-d H:i:s'). Fallback ke waktu sekarang kalau
 * parsing gagal (misal field-nya kosong/rusak), biar migrasi gak berhenti
 * gara-gara 1 baris data lama yang formatnya aneh.
 */
function goweb_parse_legacy_datetime( $legacy_date_str ) {
    if ( empty( $legacy_date_str ) ) {
        return current_time( 'mysql' );
    }
    $ts = strtotime( str_replace( '/', '-', $legacy_date_str ) );
    if ( $ts === false ) {
        return current_time( 'mysql' );
    }
    return date( 'Y-m-d H:i:s', $ts );
}

function goweb_migrate_legacy_data_to_tables() {
    global $wpdb;
    $t = goweb_get_table_names();
    $all_tenants = get_option( 'goweb_all_tenants', array() );

    $summary = array( 'payments' => 0, 'orders' => 0, 'ledger' => 0, 'wallets' => 0, 'tenants_processed' => 0 );

    foreach ( $all_tenants as $tenant_id => $tenant ) {
        $summary['tenants_processed']++;

        // --- 1. RIWAYAT PEMBAYARAN (goweb_sub_history_*) ---
        $already_migrated_payments = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['payments']} WHERE store_id = %s", $tenant_id
        ) );
        if ( intval( $already_migrated_payments ) === 0 ) {
            $history = get_option( 'goweb_sub_history_' . $tenant_id, array() );
            foreach ( $history as $row ) {
                $period = isset( $row['period'] ) ? $row['period'] : '';
                $is_po  = ( strpos( $period, 'PO:' ) !== false );
                $clean_period = $is_po ? trim( str_replace( 'PO:', '', $period ) ) : $period;

                $wpdb->insert( $t['payments'], array(
                    'store_id'          => $tenant_id,
                    'payment_category'  => $is_po ? 'po' : 'subscription',
                    'period'            => $clean_period,
                    'payment_type'      => isset( $row['payment_type'] ) ? $row['payment_type'] : null,
                    'nominal'           => isset( $row['nominal'] ) ? intval( $row['nominal'] ) : 0,
                    'sender'            => isset( $row['sender'] ) ? $row['sender'] : '',
                    'proof_url'         => isset( $row['proof'] ) ? $row['proof'] : '',
                    'status'            => isset( $row['status'] ) ? $row['status'] : 'Pending',
                    'created_at'        => goweb_parse_legacy_datetime( isset( $row['date'] ) ? $row['date'] : '' ),
                ) );
                $summary['payments']++;
            }
        }

        // --- 2. REKAPAN ORDER PO (jastip_orders_*) ---
        $already_migrated_orders = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['orders']} WHERE store_id = %s", $tenant_id
        ) );
        if ( intval( $already_migrated_orders ) === 0 ) {
            $orders = get_option( 'jastip_orders_' . $tenant_id, array() );
            foreach ( $orders as $row ) {
                $wpdb->insert( $t['orders'], array(
                    'store_id'       => $tenant_id,
                    'batch'          => isset( $row['batch'] ) ? $row['batch'] : '',
                    'buyer_name'     => isset( $row['name'] ) ? $row['name'] : '',
                    'item_detail'    => isset( $row['item'] ) ? $row['item'] : '',
                    'qty'            => isset( $row['qty'] ) ? intval( $row['qty'] ) : 1,
                    'total_value'    => isset( $row['total'] ) ? floatval( $row['total'] ) : 0,
                    'order_status'   => isset( $row['status'] ) ? $row['status'] : '',
                    'payment_status' => isset( $row['pay'] ) ? $row['pay'] : 'Belum Lunas',
                    'created_at'     => goweb_parse_legacy_datetime( isset( $row['date'] ) ? $row['date'] : '' ),
                ) );
                $summary['orders']++;
            }
        }

        // --- 3 & 4. KEUANGAN: LEDGER + SALDO DOMPET (goweb_finance_data_*) ---
        $already_migrated_ledger = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['ledger']} WHERE store_id = %s", $tenant_id
        ) );
        if ( intval( $already_migrated_ledger ) === 0 ) {
            $finance = get_option( 'goweb_finance_data_' . $tenant_id, array( 'wallets' => array(), 'ledger' => array() ) );

            foreach ( ( isset( $finance['ledger'] ) ? $finance['ledger'] : array() ) as $row ) {
                $tx_date = isset( $row['date'] ) && $row['date'] !== '' ? $row['date'] : date( 'Y-m-d' );
                $wpdb->insert( $t['ledger'], array(
                    'store_id'    => $tenant_id,
                    'tx_date'     => $tx_date,
                    'tx_type'     => isset( $row['type'] ) ? $row['type'] : '',
                    'wallet_name' => isset( $row['wallet'] ) ? $row['wallet'] : '-',
                    'title'       => isset( $row['title'] ) ? $row['title'] : '',
                    'amount'      => isset( $row['amount'] ) ? floatval( $row['amount'] ) : 0,
                    'rate'        => isset( $row['rate'] ) ? floatval( $row['rate'] ) : 0,
                    'idr_value'   => isset( $row['idr'] ) ? floatval( $row['idr'] ) : 0,
                    'created_at'  => $tx_date . ' 00:00:00',
                ) );
                $summary['ledger']++;
            }

            foreach ( ( isset( $finance['wallets'] ) ? $finance['wallets'] : array() ) as $wallet_name => $balance ) {
                $wpdb->replace( $t['wallets'], array(
                    'store_id'    => $tenant_id,
                    'wallet_name' => $wallet_name,
                    'balance'     => floatval( $balance ),
                ) );
                $summary['wallets']++;
            }
        }
    }

    update_option( 'goweb_migration_summary', array_merge( $summary, array( 'finished_at' => current_time( 'mysql' ) ) ) );
    update_option( 'goweb_data_migration_version', GOWEB_DATA_MIGRATION_VERSION );
}

function goweb_maybe_run_data_migration() {
    if ( get_option( 'goweb_data_migration_version' ) !== GOWEB_DATA_MIGRATION_VERSION ) {
        goweb_migrate_legacy_data_to_tables();
    }
}
add_action( 'init', 'goweb_maybe_run_data_migration', 20 ); // priority 20: pastikan jalan setelah tabel dibikin (after_setup_theme lebih dulu dari init)

function goweb_generate_tenant_token() {
    return bin2hex( random_bytes( 32 ) );
}

function goweb_tenant_cookie_args( $expire ) {
    return array(
        'expires'  => $expire,
        'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
        'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    );
}

function goweb_set_tenant_auth_cookie( $tenant_id, $token ) {
    $expire = time() + ( GOWEB_TENANT_SESSION_DAYS * DAY_IN_SECONDS );
    setcookie( GOWEB_TENANT_COOKIE, $tenant_id . '|' . $token, goweb_tenant_cookie_args( $expire ) );
    $_COOKIE[ GOWEB_TENANT_COOKIE ] = $tenant_id . '|' . $token; // biar langsung kebaca di request yg sama
}

function goweb_clear_tenant_auth_cookie() {
    setcookie( GOWEB_TENANT_COOKIE, '', goweb_tenant_cookie_args( time() - 3600 ) );
    unset( $_COOKIE[ GOWEB_TENANT_COOKIE ] );
}

/**
 * Ambil data tenant yang sedang login lewat cookie custom.
 * Return array tenant (berikut 'id' di dalamnya) kalau valid & belum
 * kedaluwarsa, atau null kalau tidak login / sesi invalid.
 */
function goweb_get_logged_in_tenant() {
    if ( empty( $_COOKIE[ GOWEB_TENANT_COOKIE ] ) ) return null;

    $parts = explode( '|', $_COOKIE[ GOWEB_TENANT_COOKIE ], 2 );
    if ( count( $parts ) !== 2 ) return null;
    list( $tenant_id, $token ) = $parts;

    global $wpdb;
    $tenants_table = goweb_get_table_names()['tenants'];
    $tenant = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$tenants_table} WHERE tenant_id = %s", $tenant_id
    ), ARRAY_A );
    if ( ! $tenant ) return null;

    if ( empty( $tenant['session_token_hash'] ) || empty( $tenant['session_expires'] ) ) return null;
    if ( time() > intval( $tenant['session_expires'] ) ) return null;
    if ( ! hash_equals( $tenant['session_token_hash'], hash( 'sha256', $token ) ) ) return null;

    $tenant['id'] = $tenant_id; // pastikan selalu ada, dipakai buat ownership check
    $tenant['features'] = ! empty( $tenant['features'] ) ? json_decode( $tenant['features'], true ) : array();
    return $tenant;
}

function goweb_tenant_is_logged_in() {
    return goweb_get_logged_in_tenant() !== null;
}

/**
 * Proses login: cocokkan email+password ke tabel wp_goweb_tenants, lalu
 * terbitkan sesi (cookie + simpan hash token di record tenant).
 * Return true kalau sukses, WP_Error kalau gagal.
 */
function goweb_tenant_login( $email, $raw_password ) {
    global $wpdb;
    $tenants_table = goweb_get_table_names()['tenants'];

    $tenant = $wpdb->get_row( $wpdb->prepare(
        "SELECT tenant_id, password FROM {$tenants_table} WHERE LOWER(email) = LOWER(%s)", $email
    ), ARRAY_A );

    if ( ! $tenant || empty( $tenant['password'] ) ) {
        return new WP_Error( 'invalid', 'Email atau kata sandi salah.' );
    }

    if ( ! wp_check_password( $raw_password, $tenant['password'], 0 ) ) {
        return new WP_Error( 'invalid', 'Email atau kata sandi salah.' );
    }

    $token = goweb_generate_tenant_token();
    $wpdb->update( $tenants_table, array(
        'session_token_hash' => hash( 'sha256', $token ),
        'session_expires'    => time() + ( GOWEB_TENANT_SESSION_DAYS * DAY_IN_SECONDS ),
    ), array( 'tenant_id' => $tenant['tenant_id'] ) );

    goweb_set_tenant_auth_cookie( $tenant['tenant_id'], $token );
    return true;
}

function goweb_tenant_logout() {
    $tenant = goweb_get_logged_in_tenant();
    if ( $tenant ) {
        global $wpdb;
        $tenants_table = goweb_get_table_names()['tenants'];
        $wpdb->update( $tenants_table, array(
            'session_token_hash' => null,
            'session_expires'    => null,
        ), array( 'tenant_id' => $tenant['id'] ) );
    }
    goweb_clear_tenant_auth_cookie();
}

// Tangkap ?goweb_logout=1&_wpnonce=... (dipakai tombol Keluar di header.php).
// Nonce WAJIB sekarang — dulu GET tanpa proteksi, artinya situs/aplikasi lain
// bisa memaksa logout tenant hanya dengan membuat dia membuka link (logout-CSRF).
function goweb_handle_tenant_logout() {
    if ( isset( $_GET['goweb_logout'] ) && $_GET['goweb_logout'] === '1' ) {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'goweb_logout' ) ) {
            wp_die( 'Link logout tidak valid atau kedaluwarsa. Silakan buka menu Keluar dari header situs.' );
        }
        goweb_tenant_logout();
        wp_redirect( home_url('/login') );
        exit;
    }
}
add_action( 'template_redirect', 'goweb_handle_tenant_logout', 0 );

/**
 * Helper terpusat: cari tenant berdasarkan slug URL.
 * Query langsung ke tabel wp_goweb_tenants by kolom slug — kalau slug
 * kosong (tenant lama sebelum kolom ini konsisten diisi), fallback cari
 * semua lalu cocokkan sanitize_title(store_name) di PHP.
 */
function goweb_find_tenant_by_slug( $slug ) {
    if ( empty( $slug ) ) return null;
    global $wpdb;
    $tenants_table = goweb_get_table_names()['tenants'];

    $tenant = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$tenants_table} WHERE slug = %s", $slug
    ), ARRAY_A );

    if ( ! $tenant ) {
        // Fallback buat tenant yang kolom slug-nya kosong/belum keisi
        $candidates = $wpdb->get_results(
            "SELECT * FROM {$tenants_table} WHERE slug = '' OR slug IS NULL", ARRAY_A
        );
        foreach ( $candidates as $t ) {
            if ( sanitize_title( $t['store_name'] ) === $slug ) {
                $tenant = $t;
                break;
            }
        }
    }

    if ( $tenant ) {
        $tenant['id'] = $tenant['tenant_id'];
        $tenant['features'] = ! empty( $tenant['features'] ) ? json_decode( $tenant['features'], true ) : array();
    }
    return $tenant;
}

/* ------------------------------------------------------------------
   TOKEN DESAIN (warna, radius, gradient) DIKELUARKAN VIA PHP.

   Kenapa tidak di style.css? Karena style.css adalah file statis: browser,
   page-cache (LiteSpeed/WP Rocket) dan CDN bisa terus menyajikan versi LAMA
   walau file di disk sudah berubah. Dulu hal itu membuat token baru
   (mis. --accent-gradient) tidak pernah sampai ke browser, sehingga semua
   elemen yang memakainya tampil TANPA background: tombol putih dengan
   huruf putih (tak terlihat) dan section CTA putih di latar terang
   (tak terbaca). Header HTML selalu segar, jadi menaruh token di sini
   membuat nilai tema SELALU ikut file terbaru.

   Ini SATU-SATUNYA sumber nilai token. style.css hanya berisi komponen
   dan memakai var() dari sini. Kalau mau ganti warna/radius tema, ubah
   fungsi ini.
   ------------------------------------------------------------------ */
function goweb_get_design_tokens() {
    return array(
        // Palet pastel (identitas utama GO-WEB)
        'pastel-purple'        => '#8b5cf6',
        'pastel-purple-bg'     => '#f5f3ff',
        'pastel-purple-border' => '#ddd6fe',
        'pastel-pink'          => '#f472b6',
        'pastel-pink-bg'       => '#fdf2f8',
        'pastel-mint'          => '#10b981',
        'pastel-mint-bg'       => '#ecfdf5',
        'pastel-mint-border'   => '#a7f3d0',
        'pastel-peach'         => '#fb923c',
        'pastel-peach-bg'      => '#fff7ed',
        'pastel-blue-bg'       => '#eff6ff',
        // Netral
        'bg-page'              => '#faf8ff',
        'card-bg'              => '#ffffff',
        'text-dark'            => '#2e2a47',
        'text-muted'           => '#7c7794',
        'border-soft'          => '#ede9fe',
        // Radius
        'radius-lg'            => '20px',
        'radius-md'            => '12px',
        // Gradient CTA. Nilainya HARUS literal (bukan var() ke dirinya
        // sendiri) — referensi melingkar membuat properti invalid dan semua
        // tombol gradient kehilangan latar.
        /* Gradient berkontras tinggi agar teks putih pada CTA benar-benar
         * terbaca. Gradient pastel sebelumnya (#a78bfa -> #818cf8) terlalu
         * terang; tombol terlihat seperti teks putih mengambang. */
        'accent-gradient'      => 'linear-gradient(135deg, #7c3aed, #4f46e5)',
        'accent-gradient-deep' => 'linear-gradient(135deg, #6d28d9, #4338ca)',
        // Alias modul dashboard (dulu hanya ada di :root admin.php)
        'primary'              => '#7c3aed',
        'primary-dark'         => '#6d28d9',
        'slate-900'            => 'var(--text-dark)',
        'slate-800'            => 'var(--text-dark)',
        'slate-700'            => 'var(--text-dark)',
        'slate-600'            => 'var(--text-muted)',
        'slate-500'            => 'var(--text-muted)',
        'slate-400'            => 'var(--text-muted)',
        'slate-100'            => 'var(--pastel-purple-bg)',
        'slate-50'             => 'var(--bg-page)',
        'border'               => 'var(--border-soft)',
        // Semantik
        'emerald'              => 'var(--pastel-mint)',
        'emerald-bg'           => 'var(--pastel-mint-bg)',
        'amber'                => '#f59e0b',
        'amber-bg'             => 'var(--pastel-peach-bg)',
        'rose'                 => '#ef4444',
        'rose-bg'              => '#fef2f2',
    );
}

/**
 * Cetak token desain sebagai custom property CSS.
 * Dipasang di wp_head prioritas 20 supaya SELALU ikut (style.css statis
 * bisa tertahan cache, blok ini tidak).
 */
function goweb_print_design_tokens() {
    $css = ':root{';
    foreach ( goweb_get_design_tokens() as $name => $value ) {
        $css .= '--' . $name . ':' . $value . ';';
    }
    $css .= '}';
    echo "\n<style id=\"goweb-design-tokens\">" . $css . "</style>\n";
}
add_action( 'wp_head', 'goweb_print_design_tokens', 20 );

function goweb_enqueue_styles() {
    // Versi style.css diambil dari waktu modifikasi file, BUKAN nomor versi
    // tema statis. Tanpa ini, browser & page-cache (LiteSpeed, WP Rocket,
    // CDN) bisa terus menyajikan style.css LAMA setelah file diubah, sehingga
    // token baru (mis. --accent-gradient) tidak pernah sampai ke browser dan
    // tombol/teks yang memakainya tampil transparan alias tidak terlihat.
    $style_path = get_template_directory() . '/style.css';
    $style_ver  = file_exists( $style_path ) ? filemtime( $style_path ) : null;
    wp_enqueue_style( 'goweb-style', get_stylesheet_uri(), array(), $style_ver );
}
add_action('wp_enqueue_scripts', 'goweb_enqueue_styles');

function goweb_register_query_vars( $vars ) {
    $vars[] = 'tenant_slug';
    $vars[] = 'dash_subpage';
    return $vars;
}
add_filter( 'query_vars', 'goweb_register_query_vars' );

function goweb_tenant_rewrite_rules() {
    add_rewrite_rule('^register/?$', 'index.php?pagename=register', 'top');
    add_rewrite_rule('^login/?$', 'index.php?pagename=login', 'top');
    add_rewrite_rule('^dashboard/([^/]+)/?$', 'index.php?dash_subpage=$matches[1]', 'top');
    add_rewrite_rule('^dashboard/?$', 'index.php?dash_subpage=overview', 'top');
    add_rewrite_rule('^([^/]+)/?$', 'index.php?tenant_slug=$matches[1]', 'top');
}
add_action( 'init', 'goweb_tenant_rewrite_rules' );

// Pengaman: WordPress nyimpen aturan URL custom (rewrite rules) di database,
// dan itu GAK otomatis ke-refresh cuma karena ganti domain/pindah server/
// restore backup. Kalau itu terjadi, halaman kayak /nama-toko/ bisa 404
// walau kodenya sendiri gak ada yang salah. Ini nge-flush otomatis SEKALI
// tiap kali nomor versi di bawah berubah — jadi kalau suatu saat perlu
// dipaksa refresh lagi (situasi serupa lagi), tinggal ganti angka '1' di
// bawah jadi '2', dst.
function goweb_maybe_flush_rewrite_rules() {
    $current_version = '1';
    if ( get_option( 'goweb_rewrite_rules_version' ) !== $current_version ) {
        flush_rewrite_rules();
        update_option( 'goweb_rewrite_rules_version', $current_version );
    }
}
add_action( 'init', 'goweb_maybe_flush_rewrite_rules', 20 ); // priority 20: pastiin jalan SETELAH goweb_tenant_rewrite_rules (default priority 10) daftarin rule-nya dulu

function goweb_force_custom_templates() {
    $request_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    $path_segments = explode( '/', $request_path );

    // A. JIKA AKSES /dashboard -> Langsung tembak admin.php
    if ( $path_segments[0] === 'dashboard' ) {
        $file = get_stylesheet_directory() . '/admin.php';
        if ( file_exists( $file ) ) {
            include( $file );
            exit;
        }
    }
    
    // B. JIKA AKSES /super-admin
    if ( $request_path === 'super-admin' || strpos( $request_path, 'super-admin/' ) === 0 || 
         $request_path === 'dashboard-super-admin' || strpos( $request_path, 'dashboard-super-admin/' ) === 0 ) {
        $file = get_stylesheet_directory() . '/super-admin.php';
        if ( file_exists( $file ) ) { include( $file ); exit; }
    }

    // C. JIKA AKSES /register
    if ( $request_path === 'register' || strpos( $request_path, 'register/' ) === 0 ) {
        $file = get_stylesheet_directory() . '/register.php';
        if ( file_exists( $file ) ) { include( $file ); exit; }
    }
    
    // D. JIKA AKSES /login
    if ( $request_path === 'login' || strpos( $request_path, 'login/' ) === 0 ) {
        $file = get_stylesheet_directory() . '/login.php';
        if ( file_exists( $file ) ) { include( $file ); exit; }
    }

    // E. TANGKAP SLUG TENANT PUBLIK
    $tenant_slug = get_query_var( 'tenant_slug' );
    if ( ! empty( $tenant_slug ) ) {
        $ignored_slugs = array('wp-admin', 'wp-login.php', 'login', 'admin-ajax.php', 'dashboard', 'super-admin', 'dashboard-super-admin', 'register');
        if ( ! in_array( $tenant_slug, $ignored_slugs ) ) {
            $matched_tenant = goweb_find_tenant_by_slug( $tenant_slug );
            if ( $matched_tenant ) {
                $file = get_stylesheet_directory() . '/tenant-web.php';
                if ( file_exists( $file ) ) { include( $file ); exit; }
            } else {
                global $wp_query;
                $wp_query->set_404();
                status_header( 404 );
                nocache_headers();
                return;
            }
        }
    }
}
add_action('template_redirect', 'goweb_force_custom_templates', 1);


/**
 * ======================================================================
 * RETENSI & PEMBERSIHAN DATA TENANT OTOMATIS
 * ======================================================================
 * Kebijakan (keputusan pemilik platform, bukan asumsi Claude):
 *   Data tenant dihapus PERMANEN kalau salah satu kondisi berikut lebih
 *   dulu tercapai:
 *   (a) LAPSED   — lewat batas jatuh tempo sewa + masa tenggang
 *                  GOWEB_LAPSE_GRACE_DAYS hari tanpa perpanjangan yang
 *                  ter-verifikasi, ATAU
 *   (b) MAX AGE  — akun sudah genap GOWEB_MAX_RETENTION_DAYS (1 tahun)
 *                  sejak pertama didaftarkan, TIDAK PEDULI status sewa
 *                  aktif/rajin bayar atau tidak (retensi maksimal mutlak).
 *
 *   Yang dihapus kalau kena salah satu: SEMUA — record tenant di
 *   goweb_all_tenants, seluruh option terkait (riwayat pembayaran, data
 *   keuangan, data order, pengaturan kurs/kargo/status), DAN file bukti
 *   transfer yang ke-upload di disk (bukan cuma record-nya doang).
 *
 *   Dijalankan otomatis 1x/hari lewat WP-Cron. Setiap penghapusan dicatat
 *   ke goweb_deleted_tenants_log (maks 500 entri terakhir) untuk audit —
 *   super admin bisa lihat siapa aja yang kehapus dan kenapa.
 * ======================================================================
 */

define( 'GOWEB_OFFLINE_GRACE_DAYS', 1 ); // H+1 — baru offline setelah lewat 1 hari dari jatuh tempo (H sendiri masih online normal)
define( 'GOWEB_LAPSE_GRACE_DAYS', 2 );   // H+2 — data dihapus permanen kalau masih belum diperpanjang
define( 'GOWEB_MAX_RETENTION_DAYS', 365 );
// Usia maksimal pendaftaran yang masih menggantung di antrean Super Admin.
// Pendaftaran yang tidak pernah di-approve/reject dulu tersimpan SELAMANYA
// (baris + file bukti transfer di disk) — antrean jadi makin panjang dan
// disk terus terpakai. 90 hari cukup longgar: kalau selama 3 bulan tidak
// diurus, memang sudah bukan pendaftaran serius.
define( 'GOWEB_PENDING_MAX_DAYS', 90 );

function goweb_schedule_cleanup_cron() {
    // Throttle: cek/jadwal ulang cron ini cukup 1x/jam, bukan di SETIAP
    // page load. Sebelumnya wp_next_scheduled()/wp_schedule_event() jalan
    // tiap ada kunjungan ke situs (nambah query wp_options gak perlu di
    // setiap request), padahal jadwalnya jarang berubah.
    if ( get_transient( 'goweb_cron_check_throttle' ) ) {
        return;
    }
    set_transient( 'goweb_cron_check_throttle', 1, HOUR_IN_SECONDS );

    // Bersihin jadwal versi lama kalau masih nyangkut (dari sebelum fix delay
    // di bawah ini ditambahkan — versi lama jadwalnya "langsung sekarang").
    $old_scheduled = wp_next_scheduled( 'goweb_daily_tenant_cleanup' );
    if ( $old_scheduled ) {
        wp_unschedule_event( $old_scheduled, 'goweb_daily_tenant_cleanup' );
    }

    if ( ! wp_next_scheduled( 'goweb_daily_tenant_cleanup_v2' ) ) {
        // Mulai 24 jam dari sekarang (bukan langsung "sekarang") — biar gak
        // ada resiko eksekusi mendadak begitu kode ini pertama kali ter-deploy
        // atau ter-update di server (WP-Cron jalan berbasis kunjungan, jadi
        // kalau start time-nya "sekarang", bisa langsung ke-trigger di
        // kunjungan pertama setelah deploy).
        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'goweb_daily_tenant_cleanup_v2' );
    }
}
add_action( 'wp', 'goweb_schedule_cleanup_cron' );
add_action( 'goweb_daily_tenant_cleanup_v2', 'goweb_run_tenant_cleanup' );

/**
 * Hitung kapan masa aktif sewa tenant berakhir.
 * Pakai field 'active_until' (timestamp) kalau ada — field ini di-set saat
 * approve tenant baru (super-admin.php) dan diperpanjang otomatis saat
 * pembayaran perpanjangan di-verify (admin.php). Fallback ke
 * created_at + duration untuk tenant lama yang dibuat sebelum field ini
 * ada.
 */
/**
 * Kamus diskon durasi langganan — CUMA dipakai di Perpanjangan/Upgrade
 * (menu-subscription.php), BUKAN di pendaftaran awal. Makin lama
 * komitmen, makin gede diskonnya (konservatif, bukan banting harga —
 * disesuaikan sama skala tenant sekarang).
 */
function goweb_get_subscription_durations() {
    return array(
        1  => array( 'months' => 1,  'label' => '1 Bulan',  'discount_pct' => 0 ),
        3  => array( 'months' => 3,  'label' => '3 Bulan',  'discount_pct' => 3 ),
        6  => array( 'months' => 6,  'label' => '6 Bulan',  'discount_pct' => 5 ),
        12 => array( 'months' => 12, 'label' => '12 Bulan', 'discount_pct' => 10 ),
    );
}

/**
 * Harga final buat 1 paket + durasi tertentu, udah kena diskon.
 * Dibulatin ke rupiah penuh (gak ada sen).
 */
function goweb_calculate_duration_price( $package, $duration_months ) {
    $durations = goweb_get_subscription_durations();
    $discount_pct = isset( $durations[ $duration_months ] ) ? $durations[ $duration_months ]['discount_pct'] : 0;
    $gross = $package['price'] * $duration_months;
    return round( $gross * ( 1 - ( $discount_pct / 100 ) ) );
}

/**
 * Daftar CIDR proxy/CDN yang boleh dipercaya (opsional).
 * Default KOSONG = hanya REMOTE_ADDR yang dipakai (aman, tidak bisa dipalsukan).
 *
 * Situs ini di belakang LiteSpeed CDN (QUIC.cloud). Dalam kondisi itu
 * REMOTE_ADDR berisi IP edge CDN — SEMUA pengunjung terlihat satu IP, jadi
 * throttle per-IP jadi tidak berguna (dan berisiko memblokir semua orang).
 * Kalau mau memakai IP pengunjung asli, isi konstanta ini dengan daftar CIDR
 * edge CDN, contoh di wp-config.php:
 *     define( 'GOWEB_TRUSTED_PROXY_IPS', '203.0.113.0/24,2400:cb00::/32' );
 * Header X-Forwarded-For HANYA dipercaya kalau REMOTE_ADDR ada di daftar ini,
 * supaya orang luar tidak bisa memalsukan header untuk membobol throttle.
 */
if ( ! defined( 'GOWEB_TRUSTED_PROXY_IPS' ) ) {
    define( 'GOWEB_TRUSTED_PROXY_IPS', '' );
}
/**
 * Ambil otomatis daftar IP edge LiteSpeed CDN (QUIC.cloud) dari
 * https://quic.cloud/ips dan cache harian. Tujuannya supaya situs yang di
 * belakang CDN tetap bisa memakai IP pengunjung ASLI tanpa perlu menyalin
 * CIDR manual (daftar itu berubah-ubah). Set false kalau tidak mau ada
 * request keluar sama sekali.
 */
if ( ! defined( 'GOWEB_AUTO_TRUST_CDN_IPS' ) ) {
    define( 'GOWEB_AUTO_TRUST_CDN_IPS', true );
}

/** Gabungan proxy tepercaya: konstanta manual + hasil cache daftar CDN. */
function goweb_get_trusted_proxy_list() {
    $list = array();
    if ( defined( 'GOWEB_TRUSTED_PROXY_IPS' ) && GOWEB_TRUSTED_PROXY_IPS !== '' ) {
        $list = array_filter( array_map( 'trim', explode( ',', (string) GOWEB_TRUSTED_PROXY_IPS ) ) );
    }
    $cached = get_option( 'goweb_cdn_edge_ips', array() );
    if ( is_array( $cached ) ) {
        $list = array_merge( $list, $cached );
    }
    return array_values( array_unique( array_filter( $list ) ) );
}

/**
 * Segarkan daftar IP edge CDN (dipanggil dari cron harian, bukan di jalur
 * request pengunjung supaya tidak menambah latensi).
 * Kalau gagal (timeout/format aneh), daftar lama DIPERTAHANKAN — jangan
 * sampai kegagalan sesaat membuat throttle mati atau malah salah percaya.
 */
function goweb_refresh_cdn_edge_ips() {
    if ( ! defined( 'GOWEB_AUTO_TRUST_CDN_IPS' ) || ! GOWEB_AUTO_TRUST_CDN_IPS ) return false;
    if ( ! function_exists( 'wp_remote_get' ) ) return false;

    $resp = wp_remote_get( 'https://quic.cloud/ips', array( 'timeout' => 10 ) );
    if ( function_exists( 'is_wp_error' ) && is_wp_error( $resp ) ) return false;
    $code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
    if ( $code !== 200 ) return false;
    $body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
    if ( $body === '' ) return false;

    // Sumbernya HTML sederhana: daftar IP dipisah <br /> / spasi / koma.
    $ips = array();
    foreach ( preg_split( '/<br\s*\/?>|[\s,]+/', $body ) as $candidate ) {
        $candidate = trim( strip_tags( (string) $candidate ) );
        if ( $candidate !== '' && filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
            $ips[] = $candidate;
        }
    }
    if ( empty( $ips ) ) return false; // format berubah -> simpan daftar lama

    update_option( 'goweb_cdn_edge_ips', array_values( array_unique( $ips ) ), false );
    update_option( 'goweb_cdn_edge_ips_updated', time(), false );
    return count( $ips );
}

/** Cek sebuah IP ada di dalam blok CIDR (IPv4 & IPv6). */
function goweb_ip_in_cidr( $ip, $cidr ) {
    $cidr = trim( (string) $cidr );
    if ( $cidr === '' ) return false;
    if ( strpos( $cidr, '/' ) === false ) return $ip === $cidr;
    list( $subnet, $bits ) = explode( '/', $cidr, 2 );
    $ip_bin     = @inet_pton( $ip );
    $subnet_bin = @inet_pton( trim( $subnet ) );
    if ( $ip_bin === false || $subnet_bin === false || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) return false;
    $bits  = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
    $bytes = intdiv( $bits, 8 );
    $rem   = $bits % 8;
    if ( $bytes > 0 && strncmp( $ip_bin, $subnet_bin, $bytes ) !== 0 ) return false;
    if ( $rem === 0 ) return true;
    $mask = chr( ( 0xFF << ( 8 - $rem ) ) & 0xFF );
    return ( ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask ) );
}

/**
 * IP klien untuk keperluan throttle / lockout.
 * Default selalu REMOTE_ADDR. Header proxy hanya dipakai kalau REMOTE_ADDR
 * sudah terverifikasi sebagai proxy tepercaya (lihat GOWEB_TRUSTED_PROXY_IPS).
 */
function goweb_get_client_ip() {
    $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
    if ( $remote === '' ) return 'unknown';

    $list = goweb_get_trusted_proxy_list();
    if ( empty( $list ) ) return $remote; // default aman: jangan percaya header

    $is_trusted = false;
    foreach ( $list as $cidr ) {
        if ( goweb_ip_in_cidr( $remote, $cidr ) ) { $is_trusted = true; break; }
    }
    if ( ! $is_trusted ) return $remote;

    // Urutan prioritas header yang biasa dipasang CDN/proxy.
    foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP' ) as $hdr ) {
        if ( empty( $_SERVER[ $hdr ] ) ) continue;
        // X-Forwarded-For bisa berisi rantai: "klien, proxy1, proxy2" -> ambil yang pertama valid
        foreach ( explode( ',', (string) $_SERVER[ $hdr ] ) as $candidate ) {
            $candidate = trim( $candidate );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
        }
    }
    return $remote;
}

function goweb_get_tenant_active_until( $tenant ) {
    if ( ! empty( $tenant['active_until'] ) ) {
        return intval( $tenant['active_until'] );
    }
    // Fallback ini cuma kepake buat tenant lama yang belum punya active_until
    // tersimpan. created_at disimpen via current_time('mysql') (udah
    // disesuaikan GMT situs), tapi strtotime() nafsirinnya pakai timezone
    // default PHP server (biasanya UTC) — kalau dipakai matematika mentah
    // (dibandingin langsung ke time()) tanpa dikompensasi, bisa geser
    // beberapa jam sesuai GMT offset situs. Beda sama pemakaian strtotime()
    // buat tampilan doang (parse lalu format ulang) yang aman karena
    // mismatch-nya saling niadain sendiri.
    $created_ts = isset( $tenant['created_at'] )
        ? strtotime( str_replace( '/', '-', $tenant['created_at'] ) ) - ( get_option('gmt_offset') * HOUR_IN_SECONDS )
        : time();
    $duration_days = isset( $tenant['duration'] ) ? intval( $tenant['duration'] ) : 30;
    return $created_ts + ( $duration_days * DAY_IN_SECONDS );
}

/**
 * Hapus 1 file upload berdasarkan URL-nya (convert ke path lokal dulu).
 * Aman dipanggil walau file sudah gak ada / URL bukan dari folder upload
 * situs ini.
 */
function goweb_delete_uploaded_file_by_url( $url ) {
    if ( empty( $url ) ) return;
    $upload_dir = wp_upload_dir();
    if ( strpos( $url, $upload_dir['baseurl'] ) !== 0 ) return; // bukan file upload situs ini, skip
    $local_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
    if ( file_exists( $local_path ) ) {
        @unlink( $local_path );
    }
}

/**
 * Terima foto testimoni opsional: hanya satu JPEG/PNG/WebP raster, maksimal
 * 2 MiB, lewat API upload WordPress, setelah lolos kuota tenant. Coba
 * resize/re-encode ke maksimal 1200x1200; jika editor tidak mendukung format,
 * file yang sudah berada dalam batas dimensi tetap dapat digunakan.
 * Melempar DomainException untuk input/pemrosesan yang gagal.
 */
function goweb_upload_testimonial_photo( $file, $tenant_id, $storage_limit_mb, $row_bytes = 0 ) {
    if ( $file === null ) return '';
    $max_bytes = 2 * 1024 * 1024;
    $uploaded_url = '';
    $uploaded_path = '';

    try {
        if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['tmp_name'], $file['size'] )
            || ! is_scalar( $file['error'] ) || ! is_scalar( $file['name'] )
            || ! is_scalar( $file['tmp_name'] ) || ! is_scalar( $file['size'] ) ) {
            throw new DomainException( 'Lampiran foto tidak valid. Pilih satu foto JPG, PNG, atau WebP.' );
        }
        $upload_error = (int) $file['error'];
        if ( $upload_error === UPLOAD_ERR_NO_FILE ) return '';
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            throw new DomainException( $upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE
                ? 'Ukuran foto maksimal 2 MiB. Silakan pilih foto yang lebih kecil.'
                : 'Foto gagal diterima. Silakan pilih ulang foto JPG, PNG, atau WebP.' );
        }

        $tmp_path = (string) $file['tmp_name'];
        $file['name'] = sanitize_file_name( (string) $file['name'] );
        $file_size = is_file( $tmp_path ) ? filesize( $tmp_path ) : false;
        if ( $file['name'] === '' || $file_size === false || $file_size < 1 || $file_size > $max_bytes
            || (int) $file['size'] !== (int) $file_size ) {
            throw new DomainException( 'Foto tidak valid atau melebihi batas 2 MiB. Pilih foto JPG, PNG, atau WebP.' );
        }

        $allowed_mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        );
        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $extension_mimes = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp',
        );
        $image_info = @getimagesize( $tmp_path );
        $detected_mime = ( is_array( $image_info ) && isset( $image_info['mime'] ) ) ? $image_info['mime'] : '';
        $finfo_mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $file_info = finfo_open( FILEINFO_MIME_TYPE );
            if ( $file_info ) {
                $finfo_mime = finfo_file( $file_info, $tmp_path );
                unset( $file_info ); // releases the finfo handle across supported PHP versions
            }
        }
        $dimensions_valid = is_array( $image_info ) && isset( $image_info[0], $image_info[1] )
            && $image_info[0] > 0 && $image_info[1] > 0
            && $image_info[0] <= 12000 && $image_info[1] <= 12000
            && ( $image_info[0] * $image_info[1] ) <= 40000000;
        if ( ! $dimensions_valid || ! isset( $extension_mimes[ $extension ] )
            || $detected_mime !== $extension_mimes[ $extension ]
            || ! in_array( $detected_mime, array_values( $extension_mimes ), true )
            || ( in_array( $finfo_mime, array_values( $extension_mimes ), true ) && $finfo_mime !== $detected_mime ) ) {
            throw new DomainException( 'Format foto tidak didukung atau isi file tidak cocok. Gunakan foto JPEG, PNG, atau WebP yang valid.' );
        }
        if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
            $checked_type = wp_check_filetype_and_ext( $tmp_path, $file['name'], $allowed_mimes );
            if ( empty( $checked_type['type'] ) || $checked_type['type'] !== $detected_mime ) {
                throw new DomainException( 'Format foto tidak valid. Gunakan foto JPEG, PNG, atau WebP.' );
            }
        }

        $storage_limit_bytes = (float) $storage_limit_mb * 1048576;
        $storage_used_bytes = goweb_calculate_tenant_storage_bytes( $tenant_id );
        $upload_dir = wp_upload_dir();
        $upload_url_base = isset( $upload_dir['url'] ) ? $upload_dir['url'] : ( isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '' );
        $estimated_photo_url_bytes = strlen( rtrim( $upload_url_base, '/' ) . '/' . $file['name'] ) + 32;
        if ( $storage_used_bytes + $file_size + max( 0, (int) $row_bytes ) + $estimated_photo_url_bytes > $storage_limit_bytes ) {
            throw new DomainException( 'Kapasitas penyimpanan toko sedang penuh. Foto tidak dapat diunggah; hubungi admin toko.' );
        }

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        $uploaded = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $allowed_mimes ) );
        if ( is_array( $uploaded ) ) {
            $uploaded_url = ! empty( $uploaded['url'] ) ? esc_url_raw( $uploaded['url'] ) : '';
            $uploaded_path = ! empty( $uploaded['file'] ) ? $uploaded['file'] : '';
        }
        if ( is_wp_error( $uploaded ) || ! is_array( $uploaded ) || $uploaded_url === '' || $uploaded_path === '' ) {
            throw new DomainException( 'Foto gagal diunggah. Pastikan foto JPEG, PNG, atau WebP dan coba lagi.' );
        }
        if ( $uploaded_url === '' || ! is_file( $uploaded_path ) ) {
            throw new DomainException( 'Foto gagal diunggah. Silakan pilih ulang foto.' );
        }

        $editor = wp_get_image_editor( $uploaded_path );
        if ( is_wp_error( $editor ) ) {
            if ( $image_info[0] > 1200 || $image_info[1] > 1200 ) {
                throw new DomainException( 'Server tidak dapat memproses foto ini. Gunakan JPEG, PNG, atau WebP dengan sisi maksimal 1200 piksel.' );
            }
        } else {
            $resize_result = $editor->resize( 1200, 1200, false );
            if ( is_wp_error( $resize_result ) ) {
                throw new DomainException( 'Foto tidak dapat diproses. Silakan pilih foto lain.' );
            }
            if ( method_exists( $editor, 'set_quality' ) ) $editor->set_quality( 82 );
            $saved = $editor->save( $uploaded_path );
            if ( is_wp_error( $saved ) ) {
                throw new DomainException( 'Foto tidak dapat diproses. Silakan pilih foto lain.' );
            }
            if ( ! empty( $saved['path'] ) && realpath( $saved['path'] ) !== realpath( $uploaded_path ) ) {
                throw new DomainException( 'Foto gagal diproses dengan aman. Silakan pilih foto lain.' );
            }
        }

        $processed_info = @getimagesize( $uploaded_path );
        $processed_size = is_file( $uploaded_path ) ? filesize( $uploaded_path ) : false;
        if ( ! is_array( $processed_info ) || ! isset( $processed_info['mime'], $processed_info[0], $processed_info[1] )
            || $processed_info['mime'] !== $detected_mime
            || $processed_info[0] > 1200 || $processed_info[1] > 1200
            || $processed_size === false || $processed_size < 1 || $processed_size > $max_bytes ) {
            throw new DomainException( 'Hasil pemrosesan foto tidak valid atau melebihi batas 2 MiB. Silakan pilih foto lain.' );
        }
        if ( $storage_used_bytes + $processed_size + max( 0, (int) $row_bytes ) + strlen( $uploaded_url ) > $storage_limit_bytes ) {
            throw new DomainException( 'Kapasitas penyimpanan toko sedang penuh. Foto tidak dapat disimpan; hubungi admin toko.' );
        }
        return $uploaded_url;
    } catch ( Throwable $error ) {
        if ( $uploaded_url !== '' ) {
            goweb_delete_uploaded_file_by_url( $uploaded_url );
        } elseif ( $uploaded_path !== '' && is_file( $uploaded_path ) ) {
            $upload_dir = wp_upload_dir();
            $base_path = isset( $upload_dir['basedir'] ) ? realpath( $upload_dir['basedir'] ) : false;
            $real_path = realpath( $uploaded_path );
            if ( $base_path && $real_path && strpos( $real_path, $base_path . DIRECTORY_SEPARATOR ) === 0 ) @unlink( $real_path );
        }
        throw $error;
    }
}

/**
 * Hapus SEMUA jejak satu tenant: record utama, semua data terkait di
 * tabel custom, dan semua file upload (bukti transfer) yang
 * direferensikan di riwayat pembayarannya.
 */
function goweb_delete_tenant_completely( $tenant_id ) {
    global $wpdb;
    $t = goweb_get_table_names();

    // 1. Hapus semua file bukti transfer, foto bukti warehouse, dan foto
    //    testimoni yang direferensikan oleh data tenant ini.
    $proof_urls = $wpdb->get_col( $wpdb->prepare(
        "SELECT proof_url FROM {$t['payments']} WHERE store_id = %s AND proof_url IS NOT NULL AND proof_url != ''", $tenant_id
    ) );
    $foto_urls = $wpdb->get_col( $wpdb->prepare(
        "SELECT foto_bukti FROM {$t['warehouse']} WHERE store_id = %s AND foto_bukti IS NOT NULL AND foto_bukti != ''", $tenant_id
    ) );
    $testi_photo_urls = $wpdb->get_col( $wpdb->prepare(
        "SELECT photo_url FROM {$t['testimonials']} WHERE store_id = %s AND photo_url IS NOT NULL AND photo_url != ''", $tenant_id
    ) );
    foreach ( array_merge( $proof_urls, $foto_urls, $testi_photo_urls ) as $url ) {
        goweb_delete_uploaded_file_by_url( $url );
    }

    // 2. Hapus semua baris terkait tenant ini di tabel custom
    $wpdb->delete( $t['payments'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['orders'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['invoices'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['testimonials'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['warehouse'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['wh_boxes'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['ledger'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['wallets'], array( 'store_id' => $tenant_id ) );
    $wpdb->delete( $t['settings'], array( 'tenant_id' => $tenant_id ) );

    // 3. Hapus record tenant utama
    $wpdb->delete( $t['tenants'], array( 'tenant_id' => $tenant_id ) );

    // 4. Jaring pengaman: bersihin juga sisa opsi wp_options lama kalau ada
    // (tenant lama yang belum sepenuhnya di sistem baru)
    delete_option( 'goweb_sub_history_' . $tenant_id );
    delete_option( 'goweb_custom_rates_' . $tenant_id );
    delete_option( 'goweb_finance_data_' . $tenant_id );
    delete_option( 'jastip_active_rates_' . $tenant_id );
    delete_option( 'jastip_cargo_rates_' . $tenant_id );
    delete_option( 'jastip_custom_statuses_' . $tenant_id );
    delete_option( 'jastip_orders_' . $tenant_id );
}

/**
 * ======================================================================
 * HITUNG PEMAKAIAN STORAGE TENANT (LIVE, BUKAN COUNTER MANUAL)
 * ======================================================================
 * SEBELUMNYA storage_used itu angka yang di-increment manual tiap ada
 * upload file — gampang "ngambang" dari kondisi asli (kalau ada file
 * yang somehow gak ke-track, atau data dihapus tapi angkanya gak
 * dikurangin). Sekarang dihitung LANGSUNG dari kondisi database + disk
 * tiap kali dibutuhkan, mencakup SEMUA data tenant tanpa terkecuali:
 * baris di 5 tabel (payments, orders, ledger, wallets, settings) DAN
 * file bukti transfer asli di disk. Ini juga yang bikin tenant bisa
 * lihat kalau datanya udah banyak dan makan storage, bukan cuma dari
 * foto doang.
 * ======================================================================
 */
function goweb_calculate_tenant_storage_bytes( $tenant_id ) {
    global $wpdb;
    $t = goweb_get_table_names();
    $total = 0;

    // Perkiraan ukuran baris di tiap tabel transaksi (jumlah panjang
    // kolom teks/angka yang variabel per baris + overhead tetap kecil)
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(period)+LENGTH(sender)+LENGTH(COALESCE(proof_url,''))+LENGTH(COALESCE(payment_type,''))+32), 0)
         FROM {$t['payments']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(batch)+LENGTH(buyer_name)+LENGTH(COALESCE(item_detail,''))+LENGTH(order_status)+LENGTH(payment_status)+32), 0)
         FROM {$t['orders']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(tx_type)+LENGTH(wallet_name)+LENGTH(title)+32), 0)
         FROM {$t['ledger']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(wallet_name)+16), 0)
         FROM {$t['wallets']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(setting_key)+LENGTH(COALESCE(setting_value,''))), 0)
         FROM {$t['settings']} WHERE tenant_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(buyer_name)+LENGTH(message)+LENGTH(COALESCE(photo_url,''))+LENGTH(status)+16), 0)
         FROM {$t['testimonials']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(category)+LENGTH(status)+16), 0)
         FROM {$t['invoices']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(buyer_name)+LENGTH(resi_masuk)+LENGTH(resi_keluar)+LENGTH(slot)+LENGTH(lokasi_wh)+LENGTH(COALESCE(foto_bukti,''))+LENGTH(status)+32), 0)
         FROM {$t['warehouse']} WHERE store_id = %s", $tenant_id
    ) );
    $total += (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(box_name)+LENGTH(lokasi)+LENGTH(status)+16), 0)
         FROM {$t['wh_boxes']} WHERE store_id = %s", $tenant_id
    ) );

    // Ukuran file bukti transfer & foto bukti warehouse — dibaca ASLI dari
    // disk (bukan counter), biar akurat walau ada file yang somehow gak
    // pernah ke-track.
    $proof_urls = $wpdb->get_col( $wpdb->prepare(
        "SELECT proof_url FROM {$t['payments']} WHERE store_id = %s AND proof_url IS NOT NULL AND proof_url != ''", $tenant_id
    ) );
    $foto_urls = $wpdb->get_col( $wpdb->prepare(
        "SELECT foto_bukti FROM {$t['warehouse']} WHERE store_id = %s AND foto_bukti IS NOT NULL AND foto_bukti != ''", $tenant_id
    ) );
    $testi_photo_urls = $wpdb->get_col( $wpdb->prepare(
        "SELECT photo_url FROM {$t['testimonials']} WHERE store_id = %s AND photo_url IS NOT NULL AND photo_url != ''", $tenant_id
    ) );
    $all_files = array_merge( $proof_urls, $foto_urls, $testi_photo_urls );
    $upload_dir = wp_upload_dir();
    foreach ( $all_files as $url ) {
        if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
            $local_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
            if ( file_exists( $local_path ) ) {
                $total += filesize( $local_path );
            }
        }
    }

    return $total;
}
function goweb_run_tenant_cleanup() {
    global $wpdb;
    $tenants_table = goweb_get_table_names()['tenants'];
    $all_tenants = $wpdb->get_results( "SELECT * FROM {$tenants_table}", ARRAY_A );
    $now = time();
    $deleted_log = get_option( 'goweb_deleted_tenants_log', array() );
    $any_deleted = false;

    foreach ( $all_tenants as $t ) {
        $t_id = $t['tenant_id'];
        // Sama kayak goweb_get_tenant_active_until() — created_at disimpen
        // dalam timezone situs (GMT+7 dst), tapi strtotime() nafsirinnya
        // pakai timezone default PHP server. Dikompensasi biar cek retensi
        // 1 tahun akurat, bukan geser beberapa jam.
        $created_ts    = isset( $t['created_at'] ) ? strtotime( str_replace( '/', '-', $t['created_at'] ) ) - ( get_option('gmt_offset') * HOUR_IN_SECONDS ) : $now;
        $active_until  = goweb_get_tenant_active_until( $t );

        $lapsed  = $now > ( $active_until + ( GOWEB_LAPSE_GRACE_DAYS * DAY_IN_SECONDS ) );
        $expired = $now > ( $created_ts + ( GOWEB_MAX_RETENTION_DAYS * DAY_IN_SECONDS ) );

        if ( $lapsed || $expired ) {
            $deleted_log[] = array(
                'tenant_id'  => $t_id,
                'store_name' => isset( $t['store_name'] ) ? $t['store_name'] : '',
                'email'      => isset( $t['email'] ) ? $t['email'] : '',
                'reason'     => $lapsed ? 'Lewat jatuh tempo + ' . GOWEB_LAPSE_GRACE_DAYS . ' hari masa tenggang, tidak diperpanjang' : 'Retensi maksimal 1 tahun tercapai',
                'deleted_at' => date( 'Y-m-d H:i:s' ),
            );
            goweb_delete_tenant_completely( $t_id );
            $any_deleted = true;
        } elseif ( $now > ( $active_until + ( GOWEB_OFFLINE_GRACE_DAYS * DAY_IN_SECONDS ) ) && isset( $t['status'] ) && $t['status'] === 'Active' ) {
            // H sendiri (hari jatuh tempo) masih online normal. Baru
            // offline-in setelah lewat GOWEB_OFFLINE_GRACE_DAYS (H+1).
            // Belum dihapus — tenant masih dikasih sisa waktu sampai
            // GOWEB_LAPSE_GRACE_DAYS (H+2) buat perpanjang sebelum data
            // beneran hilang permanen.
            $wpdb->update( $tenants_table, array( 'status' => 'Offline' ), array( 'tenant_id' => $t_id ) );
        }
    }

    if ( $any_deleted ) {
        // Batasi log ke 500 entri terakhir biar option ini sendiri gak membengkak
        if ( count( $deleted_log ) > 500 ) {
            $deleted_log = array_slice( $deleted_log, -500 );
        }
        update_option( 'goweb_deleted_tenants_log', $deleted_log );
    }

    // Arsipkan box "sampai Indo" yang sudah lewat masa simpan (aturan pemilik)
    goweb_archive_stale_arrived_boxes();

    // Segarkan daftar IP edge LiteSpeed CDN (#40) — maksimal sekali/12 jam,
    // ditempatkan di cron supaya tidak menambah latensi request pengunjung.
    $cdn_ips_updated = (int) get_option( 'goweb_cdn_edge_ips_updated', 0 );
    if ( $now - $cdn_ips_updated > ( 12 * HOUR_IN_SECONDS ) ) {
        goweb_refresh_cdn_edge_ips();
    }

    // Bersihkan pendaftaran yang menggantung terlalu lama (#39).
    // Sebelum ini, pendaftaran yang tidak pernah diurus Super Admin
    // tersimpan selamanya: baris di wp_goweb_pending_registrations + file
    // bukti transfernya di disk. Tidak ada satu pun kode yang menghapusnya
    // kecuali approve/reject manual, jadi antrean & disk terus membengkak.
    // Di fungsi ini tidak ada variabel $t (cuma $tenants_table) — pakai
    // helper tabel langsung biar tidak fatal error "undefined variable".
    $pending_table = goweb_get_table_names()['pending'];
    $pending_rows  = $wpdb->get_results( "SELECT * FROM {$pending_table}", ARRAY_A );
    if ( ! empty( $pending_rows ) ) {
        $pending_cutoff = $now - ( GOWEB_PENDING_MAX_DAYS * DAY_IN_SECONDS );
        $pending_log    = get_option( 'goweb_deleted_pending_log', array() );
        if ( ! is_array( $pending_log ) ) { $pending_log = array(); }
        $pending_changed = false;

        foreach ( $pending_rows as $p ) {
            $p_created = ! empty( $p['created_at'] )
                ? strtotime( str_replace( '/', '-', $p['created_at'] ) ) - ( get_option('gmt_offset') * HOUR_IN_SECONDS )
                : $now;
            if ( $p_created > $pending_cutoff ) continue;

            // File bukti transfer dibersihkan dulu, baru barisnya.
            // CATATAN: tabel pending tidak punya kolom `id` — primary key-nya
            // `request_id` (lihat skema di goweb_create_tables).
            if ( ! empty( $p['proof_url'] ) ) {
                goweb_delete_uploaded_file_by_url( $p['proof_url'] );
            }
            $wpdb->delete( $pending_table, array( 'request_id' => $p['request_id'] ) );
            $pending_log[] = array(
                'store_name' => isset( $p['store_name'] ) ? $p['store_name'] : '',
                'email'      => isset( $p['email'] ) ? $p['email'] : '',
                'created_at' => isset( $p['created_at'] ) ? $p['created_at'] : '',
                'deleted_at' => current_time('mysql'),
            );
            $pending_changed = true;
        }

        if ( $pending_changed ) {
            if ( count( $pending_log ) > 200 ) {
                $pending_log = array_slice( $pending_log, -200 );
            }
            update_option( 'goweb_deleted_pending_log', $pending_log );
        }
    }
}

/**
 * Halaman pemilih tenant untuk super admin, ditampilkan saat akses
 * /dashboard tanpa ?store=slug (atau slug-nya gak valid). Menggantikan
 * pesan error polos, dan (versi lama, sebelum dipatch) auto-nampilin
 * tenant pertama — yang itu lubang keamanannya.
 */
function goweb_render_tenant_picker( $all_tenants ) {
    get_header();
    ?>
    <main style="padding: 40px 32px 80px; max-width: 900px; margin: 0 auto;">
        <div style="margin-bottom: 28px;">
            <h1 style="font-size: 1.6rem; font-weight: 800; color: var(--text-dark);">Pilih Tenant</h1>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Kamu login sebagai Super Admin. Pilih toko yang mau dilihat dashboard-nya.</p>
        </div>
        <?php if ( empty( $all_tenants ) ): ?>
            <div style="background:#fff; border:1px solid var(--border-soft); border-radius:var(--radius-md); padding:32px; text-align:center; color:var(--text-muted);">
                Belum ada tenant terdaftar.
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ( $all_tenants as $t ):
                    $t_slug   = ( isset($t['slug']) && $t['slug'] !== '' ) ? $t['slug'] : sanitize_title( $t['store_name'] );
                    $t_status = isset($t['status']) ? $t['status'] : 'Offline';
                    $link     = home_url( '/dashboard/overview/?store=' . rawurlencode( $t_slug ) );
                ?>
                <a href="<?php echo esc_url($link); ?>" style="display:flex; align-items:center; justify-content:space-between; background:#fff; border:1px solid var(--border-soft); border-radius:var(--radius-md); padding:16px 20px; text-decoration:none; color:inherit;">
                    <div>
                        <div style="font-weight:700; color:var(--text-dark); font-size:0.95rem;"><?php echo esc_html($t['store_name']); ?></div>
                        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;"><?php echo esc_html($t['admin_name']); ?> &middot; <?php echo esc_html($t['email']); ?></div>
                    </div>
                    <span class="status-badge <?php echo ($t_status === 'Active') ? 'badge-mint' : 'badge-peach'; ?>"><?php echo esc_html($t_status); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <?php
    get_footer();
}

/**
 * ======================================================================
 * PENGATURAN PER-TENANT (kurs, tarif kargo, status order custom)
 * ======================================================================
 * Gantiin jastip_active_rates_*, jastip_cargo_rates_*,
 * jastip_custom_statuses_*, DAN goweb_custom_rates_* — yang terakhir ini
 * sebelumnya jadi sumber KURS TERPISAH yang gak pernah disinkron sama
 * halaman Pengaturan sama sekali (menu-calculator.php baca dari situ,
 * padahal menu-setting.php nulisnya ke jastip_active_rates_* — dua opsi
 * beda yang gak pernah ketemu, jadi kalkulator dashboard selalu nampilin
 * kurs default apapun yang diubah di halaman Pengaturan). Sekarang semua
 * baca/tulis dari SATU sumber yang sama: tabel wp_goweb_tenant_settings.
 * ======================================================================
 */
function goweb_get_tenant_setting( $tenant_id, $key, $default = null ) {
    global $wpdb;
    $settings_table = goweb_get_table_names()['settings'];
    $value = $wpdb->get_var( $wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE tenant_id = %s AND setting_key = %s",
        $tenant_id, $key
    ) );
    if ( $value === null ) return $default;
    $decoded = json_decode( $value, true );
    // Jaring pengaman (#28): daftar KOSONG yang sudah terlanjur tersimpan
    // (dari versi lama) diperlakukan seperti "belum pernah diatur" — kalau
    // dibiarkan, fitur terkait mati total (tak ada status/jenis bayar yang
    // valid). Daftar kosong tidak pernah jadi pilihan yang sah di UI.
    if ( is_array( $decoded ) && empty( $decoded ) && is_array( $default ) ) return $default;
    return ( $decoded !== null ) ? $decoded : $default;
}

/**
 * Sinkronkan orders.payment_status dari daftar tagihan PO tersebut (#33).
 * Kolom ini dulu MATI: cuma ada di skema + terisi dari migrasi data lama,
 * tak pernah diisi/ditampilkan aplikasi. Aturannya sederhana dan mengikuti
 * cara kerja menu Tagihan: kalau PO punya tagihan dan SEMUA-nya Lunas →
 * 'Lunas'; selain itu 'Belum Lunas'.
 */
function goweb_sync_order_payment_status( $store_id, $order_id ) {
    global $wpdb;
    $t = goweb_get_table_names();
    $order_id = intval( $order_id );
    if ( $order_id <= 0 ) return;

    $total = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['invoices']} WHERE store_id = %s AND order_id = %d", $store_id, $order_id
    ) ) );
    $lunas = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['invoices']} WHERE store_id = %s AND order_id = %d AND status = 'Lunas'", $store_id, $order_id
    ) ) );

    $new_status = ( $total > 0 && $total === $lunas ) ? 'Lunas' : 'Belum Lunas';
    $wpdb->update( $t['orders'], array( 'payment_status' => $new_status ), array( 'id' => $order_id, 'store_id' => $store_id ) );
}

function goweb_set_tenant_setting( $tenant_id, $key, $value ) {
    global $wpdb;
    $settings_table = goweb_get_table_names()['settings'];
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$settings_table} (tenant_id, setting_key, setting_value) VALUES (%s, %s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $tenant_id, $key, wp_json_encode( $value )
    ) );

    // WAJIB: paksa purge cache halaman publik tenant ini SEKARANG JUGA.
    // Header no-cache (DONOTCACHEPAGE dkk di tenant-web.php) seharusnya
    // udah cukup, tapi terbukti kadang gak sepenuhnya dipatuhi layer CDN
    // (hCDN Hostinger) — kalau kurs/kargo yang ditampilkan ke CUSTOMER
    // basi, itu bisa bikin customer rugi beneran. Jadi purge eksplisit
    // di sini sebagai lapisan pengaman tambahan, bukan cuma andelin
    // header pasif.
    goweb_purge_tenant_public_cache( $tenant_id );
}

/**
 * Purge cache halaman publik 1 tenant secara eksplisit (dipanggil tiap
 * ada setting yang berubah — kurs, kargo, status order). Kalau plugin
 * LiteSpeed Cache aktif, ini bakal langsung purge URL toko itu dari
 * cache-nya. CATATAN: ini menyasar cache LiteSpeed Cache (plugin WP) —
 * kalau ada layer CDN terpisah di depannya (hCDN Hostinger) yang gak
 * dengerin hook ini, tetap perlu exclude rule manual di panel hosting
 * buat path halaman publik tenant.
 */
function goweb_purge_tenant_public_cache( $tenant_id ) {
    global $wpdb;
    $tenants_table = goweb_get_table_names()['tenants'];
    $tenant = $wpdb->get_row( $wpdb->prepare(
        "SELECT store_name, slug FROM {$tenants_table} WHERE tenant_id = %s", $tenant_id
    ), ARRAY_A );
    if ( ! $tenant ) return;

    $slug = ! empty( $tenant['slug'] ) ? $tenant['slug'] : sanitize_title( $tenant['store_name'] );
    $url = home_url( '/' . $slug . '/' );

    // Hook resmi LiteSpeed Cache buat purge 1 URL spesifik. Aman dipanggil
    // walau plugin-nya gak aktif (do_action ke hook kosong gak error).
    do_action( 'litespeed_purge_url', $url );
    // Beberapa versi LSCache juga expose action generik ini
    do_action( 'litespeed_purge_post', $slug );
}

/**
 * ======================================================================
 * KAMUS PAKET LANGGANAN (Starter/Growth/Pro)
 * ======================================================================
 * SATU sumber kebenaran buat harga & kapasitas tiap paket — dipakai
 * konsisten di register.php (pilih paket awal), menu-subscription.php
 * (upgrade/downgrade saat perpanjang), dan super-admin.php (approve
 * tenant baru). Kalau harga/kapasitas berubah, cukup ubah di sini doang,
 * gak perlu cari-cari ke banyak file (persis pelajaran dari bug kurs
 * yang punya 2 sumber gak nyambung sebelumnya).
 * ======================================================================
 */
function goweb_get_package_definitions() {
    return array(
        'starter'  => array( 'key' => 'starter',  'name' => 'Starter',  'price' => 79000,  'storage_limit' => 250 ),
        'lite'     => array( 'key' => 'lite',      'name' => 'Lite',     'price' => 99000,  'storage_limit' => 500 ),
        'standard' => array( 'key' => 'standard',  'name' => 'Standard', 'price' => 149000, 'storage_limit' => 1024 ),
        'advanced' => array( 'key' => 'advanced',  'name' => 'Advanced', 'price' => 199000, 'storage_limit' => 2048 ),
        'pro'      => array( 'key' => 'pro',       'name' => 'Pro',      'price' => 299000, 'storage_limit' => 5120 ),
        'ultimate' => array( 'key' => 'ultimate',  'name' => 'Ultimate', 'price' => 449000, 'storage_limit' => 10240 ),
    );
}

/**
 * Nilai BERSIH sebuah pembayaran sewa — tanpa biaya layanan QRIS.
 * Kenapa perlu: `nominal` yang tersimpan sengaja SUDAH termasuk fee QRIS
 * (itu yang benar-benar ditransfer tenant). Tapi `cycle_daily_rate` dipakai
 * untuk prorata upgrade, jadi kalau dihitung dari nominal+fee, nilai harian
 * tenant jadi sedikit lebih tinggi dari harga paket sebenarnya — prorata
 * upgrade berikutnya ikut membengkak.
 * Untuk perpanjangan, harga bersihnya bisa dihitung ulang PERSIS dari kamus
 * paket + durasi. Untuk upgrade, nominal adalah selisih prorata + fee yang
 * tidak bisa dipisah tanpa menyimpan fee terpisah, jadi dipakai apa adanya
 * (selisihnya kecil dan sudah tercatat sebagai keterbatasan di backlog).
 */
function goweb_subscription_net_value( $payment_row, $current_package ) {
    $nominal = isset( $payment_row['nominal'] ) ? floatval( $payment_row['nominal'] ) : 0;
    if ( ! empty( $payment_row['requested_package'] ) ) {
        return $nominal; // upgrade
    }
    $months    = ! empty( $payment_row['requested_duration_months'] ) ? intval( $payment_row['requested_duration_months'] ) : 1;
    $durations = goweb_get_subscription_durations();
    if ( ! isset( $durations[ $months ] ) ) $months = 1;
    return (float) goweb_calculate_duration_price( $current_package, $months );
}

/**
 * Rate harian baru setelah pembayaran perpanjangan disetujui.
 * Perpanjangan lebih awal menggabungkan nilai sisa lama dengan harga paket
 * bersih untuk durasi baru; upgrade tidak memakai helper ini.
 */
function goweb_subscription_renewal_daily_rate( $old_rate, $remaining_days, $net_paid, $duration_days ) {
    $duration_days = max( 1, (int) $duration_days );
    $total_days = max( 0, (int) $remaining_days ) + $duration_days;
    $remaining_value = max( 0, (float) $old_rate ) * max( 0, (int) $remaining_days );
    return round( ( $remaining_value + max( 0, (float) $net_paid ) ) / $total_days, 2 );
}

/**
 * Format kapasitas storage (angka dalam MB) jadi teks yang konsisten.
 * Sebelumnya landing & register menulis "1 GB" sementara dashboard &
 * halaman perpanjangan menulis "1024 MB" untuk paket yang SAMA — bagian dari
 * masalah "satu kebijakan, banyak salinan" (#11): calon pembeli melihat
 * 1 GB, tenant melihat 1024 MB.
 */
function goweb_format_storage_size( $mb ) {
    $mb = (float) $mb;
    if ( $mb >= 1024 ) {
        $gb = $mb / 1024;
        // 1 GB / 2 GB / 5 GB / 10 GB — buang desimal nol biar rapi.
        $gb_text = ( floor( $gb ) === $gb ) ? number_format( $gb, 0 ) : number_format( $gb, 1, ',', '.' );
        return $gb_text . ' GB';
    }
    return number_format( $mb, 0 ) . ' MB';
}

function goweb_get_package( $package_key ) {
    $packages = goweb_get_package_definitions();
    return isset( $packages[ $package_key ] ) ? $packages[ $package_key ] : $packages['starter'];
}

/**
 * KONFIGURASI BIAYA LAYANAN QRIS — SATU sumber kebenaran. Sebelumnya
 * persentase + threshold + label hardcode terpisah di 3 tempat
 * (register.php JS, menu-subscription.php JS, menu-subscription.php PHP
 * handler) yang gak pernah saling sinkron.
 * CATATAN POLICY: persentase mengikuti aturan lama persis (di bawah
 * threshold 0,3%, sama/lebih besar 0,7%) — gak diubah di sini.
 */
function goweb_qris_fee_config() {
    return array(
        'threshold' => 500000,
        'low_pct'   => 0.003,
        'high_pct'  => 0.007,
        'low_label' => '0,3%',
        'high_label'=> '0,7%',
    );
}

/**
 * Hitung biaya layanan QRIS + total akhir. Dibulatikan ke atas (ceil)
 * per komponen fee, sama seperti perilaku lama.
 */
function goweb_calculate_wallet_moving_average( $ledger_rows, $wallet_name ) {
    $rows = array_values( array_filter( (array) $ledger_rows, function( $row ) use ( $wallet_name ) {
        return isset($row['wallet_name'], $row['tx_type'])
            && (string) $row['wallet_name'] === (string) $wallet_name
            && in_array( $row['tx_type'], array('wallet_topup', 'supplier_purchase'), true );
    } ) );
    usort( $rows, function( $a, $b ) {
        // Auto-increment ID is the actual posting order; tx_date may be
        // backdated and created_at can have second-level ties.
        return intval($a['id'] ?? 0) <=> intval($b['id'] ?? 0);
    } );

    $balance = 0.0;
    $cost_basis = 0.0;
    $has_topup = false;
    $is_valid = true;

    foreach ( $rows as $row ) {
        $amount = floatval( $row['amount'] ?? 0 );
        if ( $amount <= 0 ) continue;

        if ( $row['tx_type'] === 'wallet_topup' ) {
            $has_topup = true;
            $balance += $amount;
            $cost_basis += floatval( $row['idr_value'] ?? 0 );
            continue;
        }

        // Existing supplier expenses are already posted in the ledger. Keep
        // those booked costs as the opening cost basis for remaining funds.
        if ( $amount > $balance + 0.005 ) $is_valid = false;
        $balance -= $amount;
        $cost_basis -= floatval( $row['idr_value'] ?? 0 );
    }

    if ( $balance < -0.005 || $cost_basis < -0.005 ) $is_valid = false;
    if ( abs($balance) < 0.005 ) $balance = 0.0;
    if ( abs($cost_basis) < 0.005 ) $cost_basis = 0.0;

    return array(
        'balance' => $balance,
        'cost_basis' => $cost_basis,
        'average_rate' => $is_valid && $balance > 0 ? $cost_basis / $balance : 0.0,
        'has_topup' => $has_topup,
        'is_valid' => $is_valid,
    );
}

function goweb_calculate_qris_fee( $base ) {
    $c = goweb_qris_fee_config();
    $is_low = ( $base < $c['threshold'] );
    $fee = ceil( $base * ( $is_low ? $c['low_pct'] : $c['high_pct'] ) );
    return array(
        'base'  => $base,
        'pct'   => $is_low ? $c['low_pct'] : $c['high_pct'],
        'fee'   => $fee,
        'total' => $base + $fee,
    );
}

/**
 * URL gambar QRIS pembayaran platform — SATU sumber. Sebelumnya hardcode
 * duplikat di register.php dan menu-subscription.php; kalau QRIS ganti,
 * cukup ubah di sini.
 */
function goweb_get_qris_image_url() {
    return 'https://mercatoria.id/wp-content/uploads/2026/02/qris-barcode.jpg';
}

/**
 * Label tampilan buat kategori tagihan (dp/ongkir_wh/dst) — kategori &
 * emoji sama persis kayak "Jenis Pembayaran" di page-payment.php, biar
 * konsisten di seluruh tema (Rekapan Tagihan admin + halaman publik).
 * Sekarang "Jenis Pembayaran"/"Jenis Tagihan" adalah daftar yang bisa
 * diedit admin (lihat goweb_get_tenant_setting($id,'payment_categories')),
 * jadi kategori baru yang disimpan itu udah teks label penuh — fungsi ini
 * cuma perlu nerjemahin data LAMA yang masih pakai kunci pendek (dp,
 * ongkir_wh, dst dari sebelum fitur ini bisa diedit).
 */
function goweb_invoice_category_label( $category_key ) {
    $labels = array(
        'dp'           => '💰 DP / Uang Muka',
        'ongkir_wh'    => '🚚 Ongkir Lokal (WH)',
        'ongkir_kargo' => '✈️ Ongkir Kargo',
        'pajak'        => '🧾 Pajak / Bea Masuk',
        'packing'      => '📦 Packing / Handling',
        'pelunasan'    => '✅ Pelunasan',
        'lainnya'      => '🔖 Lainnya',
    );
    return isset( $labels[ $category_key ] ) ? $labels[ $category_key ] : $category_key;
}

/**
 * Kamus default "Jenis Pembayaran/Tagihan" — dipakai sebagai nilai awal
 * pas tenant baru daftar, dan sebagai fallback kalau tenant belum pernah
 * nyimpen pengaturan sendiri. Tenant bisa edit/hapus/tambah daftar ini
 * lewat menu Pengaturan (sama persis pola custom_statuses).
 */
function goweb_get_default_payment_categories() {
    return array(
        '💰 DP / Uang Muka Barang',
        '✅ Pelunasan / Sisa Bayar',
        '🚚 Ongkir Lokal (ke WH)',
        '✈️ Ongkir Kargo Int\'l',
        '🧾 Pajak / Bea Masuk',
        '📦 Packing / Handling Fee',
        '🔖 Lainnya',
    );
}

/**
 * Kamus default "Status Warehouse" — dipakai sebagai nilai awal pas tenant
 * baru daftar, dan fallback kalau belum pernah nyimpen sendiri. Tenant bisa
 * edit/hapus/tambah lewat Pengaturan (sama pola custom_statuses).
 */
function goweb_get_default_warehouse_statuses() {
    return array( 'Menunggu', 'Di Gudang', 'Siap Kirim', 'Terkirim' );
}

/**
 * Kamus default "Status Box" — Box adalah lapisan konsolidasi di atas item
 * Warehouse individual (beberapa item dari customer berbeda dikumpulin jadi
 * 1 Box sebelum dikirim bareng). Tenant bisa edit/hapus/tambah lewat
 * Pengaturan (sama pola wh_statuses/custom_statuses).
 */
function goweb_get_default_box_statuses() {
    return array( 'Packing In Progress', 'Siap Kirim', 'Dalam Perjalanan', 'Sampai Indonesia' );
}

/**
 * Kamus default "Kargo & Pajak per Mata Uang" — nempel LANGSUNG ke 7
 * mata uang yang sama kayak Kurs, bukan daftar negara terpisah lagi.
 * Tenant bisa ubah nilainya lewat Pengaturan.
 */
function goweb_get_default_currency_cargo() {
    return array(
        'CNY' => array( 'air' => 140000, 'sea' => 85000, 'tax_percent' => 7.5 ),
        'JPY' => array( 'air' => 160000, 'sea' => 95000, 'tax_percent' => 10 ),
        'THB' => array( 'air' => 120000, 'sea' => 75000, 'tax_percent' => 7.5 ),
        'KRW' => array( 'air' => 150000, 'sea' => 90000, 'tax_percent' => 10 ),
        'USD' => array( 'air' => 220000, 'sea' => 130000, 'tax_percent' => 12.5 ),
        'TWD' => array( 'air' => 140000, 'sea' => 85000, 'tax_percent' => 7.5 ),
        'PHP' => array( 'air' => 120000, 'sea' => 75000, 'tax_percent' => 7.5 ),
    );
}

/**
 * Total berat (kg) semua item Warehouse yang ditugaskan ke sebuah Box —
 * dihitung LIVE dari data asli (bukan counter manual), biar gak pernah
 * "ngambang" kalau ada item yang ditambah/dipindah/dihapus dari Box.
 */
/**
 * ======================================================================
 * AUTO-ARSIP BOX "SAMPAI INDO" (aturan pemilik)
 * ======================================================================
 * Box yang statusnya sudah sampai Indonesia ("Sampai Indonesia" /
 * "Sampai Indo" / "Arrived Indo") hanya perlu disimpan ~2 minggu sejak
 * status itu diset. Lebih dari itu box-nya menghilang otomatis dari daftar
 * (UI) — pekerjaan gudang sudah selesai, jadi menyisakan barisnya cuma
 * bikin daftar box makin panjang dan kapasitas kelihatan penuh.
 *
 * Kenapa status_changed_at, bukan created_at: box bisa nginap lama di
 * status "Packing In Progress". Kalau dihitung dari created_at, box yang
 * baru saja sampai bisa langsung ke-arsip.
 *
 * Kenapa di-arsip (archived_at), bukan langsung DELETE: baris box dipakai
 * untuk melacak riwayat kiriman pembeli. Penghapusan permanen dilakukan
 * manual (tombol hapus) atau otomatis kalau pemilik minta.
 */
if ( ! defined( 'GOWEB_BOX_ARRIVED_HOLD_DAYS' ) ) {
    define( 'GOWEB_BOX_ARRIVED_HOLD_DAYS', 14 );
}

/** Status box dianggap "sudah sampai Indonesia"? (case-insensitive) */
function goweb_is_box_arrived_status( $status ) {
    $s = strtolower( trim( (string) $status ) );
    if ( $s === '' ) return false;
    foreach ( array( 'sampai indo', 'arrived indo', 'sampai indonesia', 'arrived' ) as $needle ) {
        if ( strpos( $s, $needle ) !== false ) return true;
    }
    return false;
}

/**
 * Arsipkan box arrived yang sudah lewat masa simpan.
 * Dipanggil dari cron harian; return jumlah box yang baru diarsipkan.
 */
function goweb_archive_stale_arrived_boxes() {
    global $wpdb;
    $box_table = goweb_get_table_names()['wh_boxes'];
    $cutoff_ts = time() - ( GOWEB_BOX_ARRIVED_HOLD_DAYS * DAY_IN_SECONDS );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, status, status_changed_at FROM {$box_table}
         WHERE archived_at IS NULL AND status_changed_at IS NOT NULL AND status_changed_at <= %s",
        gmdate( 'Y-m-d H:i:s', $cutoff_ts )
    ), ARRAY_A );
    if ( empty( $rows ) ) return 0;

    $archived  = 0;
    $now_mysql = current_time( 'mysql' );
    $warehouse_table = goweb_get_table_names()['warehouse'];
    foreach ( $rows as $row ) {
        if ( ! goweb_is_box_arrived_status( $row['status'] ) ) continue;
        $box_id = intval( $row['id'] );
        $wpdb->update( $box_table, array( 'archived_at' => $now_mysql ), array( 'id' => $box_id ) );
        // Item dilepas dari box (box_id -> NULL), sama seperti perilaku tombol
        // hapus box manual: box-nya hilang dari UI, jadi item tidak boleh
        // menyimpan referensi ke box yang tak lagi tampil.
        $wpdb->update( $warehouse_table, array( 'box_id' => null ), array( 'box_id' => $box_id ) );
        $archived++;
    }
    return $archived;
}

function goweb_calculate_box_filled_weight( $box_id ) {
    global $wpdb;
    $warehouse_table = goweb_get_table_names()['warehouse'];
    return (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(berat), 0) FROM {$warehouse_table} WHERE box_id = %d", $box_id
    ) );
}

/**
 * Hitung berat box setelah satu paket dipindah ke dalamnya.
 * Return nilai bukan-boolean agar handler bisa membedakan box hilang
 * dari penolakan kapasitas.
 */
function goweb_get_box_assignment_capacity_error( $box, $item_weight, $current_box_id = 0 ) {
    if ( ! is_array( $box ) || empty( $box['id'] ) ) return 'Box tidak ditemukan atau sudah tidak aktif.';
    $capacity = isset( $box['kapasitas_kg'] ) ? (float) $box['kapasitas_kg'] : 0;
    $filled   = isset( $box['filled_kg'] ) ? (float) $box['filled_kg'] : 0;
    $item_weight = (float) $item_weight;
    if ( $capacity <= 0 ) return 'Kapasitas box tidak valid. Perbarui kapasitasnya sebelum menambahkan paket.';
    if ( $item_weight < 0 ) return 'Berat paket tidak valid.';
    if ( $current_box_id > 0 && (int) $current_box_id === (int) $box['id'] ) return '';
    if ( $filled + $item_weight > $capacity + 0.000001 ) {
        return sprintf( 'Paket tidak dapat dimasukkan: kapasitas box %.2f kg, terisi %.2f kg, tambahan %.2f kg.', $capacity, $filled, $item_weight );
    }
    return '';
}

/**
 * Warna badge buat kategori pembayaran/tagihan. Kategori LAMA (kunci
 * pendek dari sebelum fitur ini bisa diedit) tetap dapet warna asli biar
 * konsisten. Kategori BARU (teks label bebas dari tenant) dapet warna dari
 * rotasi palet tetap, dipilih dari hash teksnya — jadi konsisten juga
 * (kategori yang sama selalu dapet warna yang sama) tanpa perlu tenant
 * ngatur warna manual satu-satu.
 */
function goweb_category_badge_color( $category_value ) {
    $legacy_colors = array(
        'dp'           => array( 'bg' => '#e0f2fe', 'color' => '#0369a1' ),
        'pelunasan'    => array( 'bg' => '#ecfdf5', 'color' => '#059669' ),
        'ongkir_wh'    => array( 'bg' => '#fef3c7', 'color' => '#b45309' ),
        'ongkir_kargo' => array( 'bg' => '#fef3c7', 'color' => '#b45309' ),
        'pajak'        => array( 'bg' => '#f3e8ff', 'color' => '#7e22ce' ),
        'packing'      => array( 'bg' => '#f1f5f9', 'color' => '#334155' ),
        'lainnya'      => array( 'bg' => '#f1f5f9', 'color' => '#334155' ),
    );
    if ( isset( $legacy_colors[ $category_value ] ) ) {
        return $legacy_colors[ $category_value ];
    }
    $palette = array(
        array( 'bg' => '#e0f2fe', 'color' => '#0369a1' ),
        array( 'bg' => '#ecfdf5', 'color' => '#059669' ),
        array( 'bg' => '#fef3c7', 'color' => '#b45309' ),
        array( 'bg' => '#f3e8ff', 'color' => '#7e22ce' ),
        array( 'bg' => '#fee2e2', 'color' => '#b91c1c' ),
        array( 'bg' => '#f1f5f9', 'color' => '#334155' ),
    );
    $index = abs( crc32( (string) $category_value ) ) % count( $palette );
    return $palette[ $index ];
}