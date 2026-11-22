<?php
if (!defined('ABSPATH')) exit;

$modal_notification = '';

// --- 1. HANDLE BACKEND / DATABASE SAVING (PHP POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ( ! isset($_POST['goweb_setting_nonce']) || ! wp_verify_nonce($_POST['goweb_setting_nonce'], 'goweb_setting_action_' . $store_id) ) {
        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }
    // Server-side gating fitur — kondisi sama dengan kondisi render kartu
    // pengaturan masing-masing di bawah. Tanpa ini, tenant bisa kirim POST
    // langsung ke /dashboard/setting/ walau fitur terkait gak dia punya.
    // Simpan Kurs (Ditambah THB)
    if (isset($_POST['action_save_rates'])) {
        if ( ! $has_calc && ! $has_public_calc ) {
            wp_die('Akses ditolak. Fitur Kalkulator tidak aktif untuk toko ini.');
        }
        $new_rates = array(
            'CNY' => floatval($_POST['rate_cny']),
            'JPY' => floatval($_POST['rate_jpy']),
            'THB' => floatval($_POST['rate_thb']),
            'KRW' => floatval($_POST['rate_krw']),
            'USD' => floatval($_POST['rate_usd'])
        );
        goweb_set_tenant_setting( $store_id, 'rates', $new_rates );
        $modal_notification = array(
            'title' => 'Kurs Berhasil Disimpan',
            'desc' => 'Nilai tukar mata uang terbaru (termasuk THB) telah berhasil disimpan permanen ke database dan siap digunakan pada kalkulator.'
        );
    }

    // Simpan Status Order Kustom
    if (isset($_POST['action_save_statuses'])) {
        if ( ! $has_po ) {
            wp_die('Akses ditolak. Fitur Rekapan PO tidak aktif untuk toko ini.');
        }
        $raw_statuses = isset($_POST['custom_statuses']) ? $_POST['custom_statuses'] : array();
        $cleaned_statuses = array_map('sanitize_text_field', array_filter($raw_statuses));
        
        goweb_set_tenant_setting( $store_id, 'custom_statuses', $cleaned_statuses );
        $modal_notification = array(
            'title' => 'Status Order Diperbarui',
            'desc' => 'Tahapan status pelacakan order berhasil disimpan permanen ke database dan disinkronkan ke menu rekapan.'
        );
    }

    // Simpan Kargo & Pajak per Mata Uang + Packing Fee
    if (isset($_POST['action_save_cargo'])) {
        if ( ! $has_calc && ! $has_public_calc ) {
            wp_die('Akses ditolak. Fitur Kalkulator tidak aktif untuk toko ini.');
        }
        $currency_codes = array('CNY', 'JPY', 'THB', 'KRW', 'USD');
        $currency_cargo = array();
        foreach ( $currency_codes as $code ) {
            $currency_cargo[$code] = array(
                'air'         => isset($_POST['cc_air_' . $code]) ? floatval($_POST['cc_air_' . $code]) : 0,
                'sea'         => isset($_POST['cc_sea_' . $code]) ? floatval($_POST['cc_sea_' . $code]) : 0,
                'tax_percent' => isset($_POST['cc_tax_' . $code]) ? floatval($_POST['cc_tax_' . $code]) : 0,
            );
        }
        goweb_set_tenant_setting( $store_id, 'currency_cargo', $currency_cargo );
        goweb_set_tenant_setting( $store_id, 'packing_fee', floatval($_POST['cargo_packing_fee'] ?? 5000) );

        $modal_notification = array(
            'title' => 'Kargo & Pajak Disimpan',
            'desc' => 'Tarif kargo & pajak per mata uang, serta packing fee, berhasil diperbarui dan disinkronkan ke kalkulator publik.'
        );
    }

    // Simpan Daftar Jenis Pembayaran/Tagihan — dipakai bareng di form
    // Konfirmasi Pembayaran publik (Jenis Pembayaran) DAN Rekapan Tagihan
    // (Jenis Tagihan), biar 1 sumber yang sama gak perlu diubah 2 tempat.
    if (isset($_POST['action_save_payment_categories'])) {
        if ( ! $has_payment && ! $has_tagihan ) {
            wp_die('Akses ditolak. Fitur Konfirmasi Bayar/Rekapan Tagihan tidak aktif untuk toko ini.');
        }
        $raw_cats = isset($_POST['payment_categories']) ? $_POST['payment_categories'] : array();
        $cleaned_cats = array_map('sanitize_text_field', array_filter($raw_cats));

        goweb_set_tenant_setting( $store_id, 'payment_categories', $cleaned_cats );
        $modal_notification = array(
            'title' => 'Daftar Jenis Pembayaran Disimpan',
            'desc' => 'Daftar ini dipakai bareng di form Konfirmasi Pembayaran publik dan Rekapan Tagihan.'
        );
    }

    // Simpan Pengaturan Warehouse (mode link ke PO + daftar status)
    if (isset($_POST['action_save_wh_settings'])) {
        if ( ! $has_wh ) {
            wp_die('Akses ditolak. Fitur Database Warehouse tidak aktif untuk toko ini.');
        }
        $wh_link_mode = ( isset($_POST['wh_link_mode']) && $_POST['wh_link_mode'] === 'linked' ) ? 'linked' : 'free';
        goweb_set_tenant_setting( $store_id, 'wh_link_mode', $wh_link_mode );

        $raw_wh_statuses = isset($_POST['wh_statuses']) ? $_POST['wh_statuses'] : array();
        $cleaned_wh_statuses = array_map('sanitize_text_field', array_filter($raw_wh_statuses));
        goweb_set_tenant_setting( $store_id, 'wh_statuses', $cleaned_wh_statuses );

        // Status Box (lapisan konsolidasi di atas item Warehouse individual)
        $raw_box_statuses = isset($_POST['box_statuses']) ? $_POST['box_statuses'] : array();
        $cleaned_box_statuses = array_map('sanitize_text_field', array_filter($raw_box_statuses));
        goweb_set_tenant_setting( $store_id, 'box_statuses', $cleaned_box_statuses );

        $modal_notification = array(
            'title' => 'Pengaturan Warehouse Disimpan',
            'desc' => 'Mode keterkaitan PO, daftar status warehouse, dan status Box berhasil diperbarui.'
        );
    }
}

// Ambil data tersimpan dari database (Default THB diset 460 jika belum ada)
$saved_rates = goweb_get_tenant_setting( $store_id, 'rates', array('CNY' => 2450, 'JPY' => 110, 'THB' => 460, 'KRW' => 12, 'USD' => 16200) );
$saved_statuses = goweb_get_tenant_setting( $store_id, 'custom_statuses', array("Antrean", "On Process", "Arrived WH", "Shipped Indo", "Done") );
$saved_currency_cargo = goweb_get_tenant_setting( $store_id, 'currency_cargo', goweb_get_default_currency_cargo() );
$saved_packing_fee = goweb_get_tenant_setting( $store_id, 'packing_fee', 5000 );
$currency_labels = array( 'CNY' => '🇨🇳 Yuan (CNY)', 'JPY' => '🇯🇵 Yen (JPY)', 'THB' => '🇹🇭 Baht (THB)', 'KRW' => '🇰🇷 Won (KRW)', 'USD' => '🇺🇸 USD ($)' );
// Default 7 kategori pembayaran/tagihan standar — tenant boleh ubah/hapus/tambah
$saved_payment_categories = goweb_get_tenant_setting( $store_id, 'payment_categories', array(
    '💰 DP / Uang Muka Barang',
    '✅ Pelunasan / Sisa Bayar',
    '🚚 Ongkir Lokal (ke WH)',
    "✈️ Ongkir Kargo Int'l",
    '🧾 Pajak / Bea Masuk',
    '📦 Packing / Handling Fee',
    '🔖 Lainnya',
) );
$saved_wh_link_mode = goweb_get_tenant_setting( $store_id, 'wh_link_mode', 'free' );
$saved_wh_statuses = goweb_get_tenant_setting( $store_id, 'wh_statuses', goweb_get_default_warehouse_statuses() );
$saved_box_statuses = goweb_get_tenant_setting( $store_id, 'box_statuses', goweb_get_default_box_statuses() );
?>

<div class="fin-wrap">
    <style>
        .ms-2col-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        @media (max-width: 800px) {
            .ms-2col-grid { grid-template-columns: 1fr; gap: 16px; }
        }
    </style>
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">Pengaturan Sistem &amp; Status Order</h2>
        <p style="color: var(--text-muted, #64748b); font-size: 0.875rem;">Atur nilai tukar mata uang, tarif kargo internasional, dan kustomisasi tahapan status order.</p>
    </div>

    <?php if ( ! $has_calc && ! $has_public_calc && ! $has_po && ! $has_payment && ! $has_tagihan && ! $has_wh ): ?>
        <div style="background: #fff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 20px; padding: 40px; text-align: center; color: var(--text-muted, #64748b);">
            <div style="font-size: 2rem; margin-bottom: 8px;">⚙️</div>
            <strong>Belum ada fitur aktif yang butuh pengaturan.</strong>
            <p style="font-size: 0.85rem; margin-top: 4px;">Hubungi Super Admin buat mengaktifkan fitur toko kamu.</p>
        </div>
    <?php endif; ?>

    <div class="ms-2col-grid" style="margin-bottom: 24px;">
        
        <?php if ($has_calc || $has_public_calc): ?>
        <!-- KARTU 1: PENGATURAN KURS (TERMASUK THB) -->
        <div style="background: #ffffff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 20px; padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04);">
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 4px;">Pengaturan Kurs</h3>
                <p style="font-size: 12.5px; color: var(--text-muted, #64748b);">Nilai tukar tersimpan di database dan dipakai otomatis pada kalkulator.</p>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">🇨🇳 KURS CNY / YUAN (IDR)</label>
                    <input type="number" name="rate_cny" class="form-input-fin" value="<?php echo esc_attr($saved_rates['CNY']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">🇯🇵 KURS JPY / YEN (IDR)</label>
                    <input type="number" name="rate_jpy" class="form-input-fin" value="<?php echo esc_attr($saved_rates['JPY']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">🇹🇭 KURS THB / BAHT (IDR)</label>
                    <input type="number" name="rate_thb" class="form-input-fin" value="<?php echo esc_attr(isset($saved_rates['THB']) ? $saved_rates['THB'] : 460); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">🇰🇷 KURS KRW / WON (IDR)</label>
                    <input type="number" name="rate_krw" class="form-input-fin" value="<?php echo esc_attr($saved_rates['KRW']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; font-weight: 600;" required>
                </div>

                <div class="form-group-fin" style="margin-bottom: 24px;">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">🇺🇸 USD / DOLLAR (IDR)</label>
                    <input type="number" name="rate_usd" class="form-input-fin" value="<?php echo esc_attr($saved_rates['USD']); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; font-weight: 600;" required>
                </div>

                <button type="submit" name="action_save_rates" class="btn-submit-action" style="background: #6366f1; color: #fff; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">🔒 Simpan Kurs</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($has_po): ?>
        <!-- KARTU 2: PENGATURAN STATUS ORDER KUSTOM (DB CONNECTED) -->
        <div style="background: #ffffff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 20px; padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04);">
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 4px;">Pengaturan Status Order</h3>
                <p style="font-size: 12.5px; color: var(--text-muted, #64748b);">Tahapan status ini tersimpan di database dan langsung terhubung ke menu rekapan.</p>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">Daftar Status Aktif Saat Ini:</label>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="status-list-container">
                        <?php foreach($saved_statuses as $st): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="status-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="moveStatusRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="moveStatusRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="custom_statuses[]" class="form-input-fin" value="<?php echo esc_attr($st); ?>" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.status-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addDatabaseStatusRow()" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: 1px dashed #cbd5e1; width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Status Baru</button>
                </div>

                <button type="submit" name="action_save_statuses" class="btn-submit-action" style="background: var(--primary, #0284c7); color: #fff; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">💾 Simpan Status Order</button>
            </form>
        </div>
        <?php endif; ?>

    </div>

    <div class="ms-2col-grid">

        <?php if ($has_calc || $has_public_calc): ?>
        <!-- KARTU 3: KARGO & PAJAK PER MATA UANG (NEMPEL LANGSUNG KE KURS) -->
        <div style="background: #ffffff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 20px; padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04);">
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 4px;">✈️ Kargo & Pajak per Mata Uang</h3>
                <p style="font-size: 12.5px; color: var(--text-muted, #64748b);">Tarif kargo & pajak nempel langsung ke tiap mata uang — pas customer pilih Kurs di kalkulator publik, kargo & pajaknya otomatis ikut.</p>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>

                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px;">
                    <?php foreach ( $currency_labels as $code => $label ):
                        $cc = isset($saved_currency_cargo[$code]) ? $saved_currency_cargo[$code] : array('air' => 0, 'sea' => 0, 'tax_percent' => 0);
                    ?>
                        <div style="border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; padding: 10px;">
                            <div style="font-size: 13px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 8px;"><?php echo esc_html($label); ?></div>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;">
                                <div>
                                    <label style="font-size: 10px; font-weight: 700; color: var(--text-muted, #64748b); display: block; margin-bottom: 2px;">✈️ Udara/Kg</label>
                                    <input type="number" name="cc_air_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($cc['air']); ?>" style="width: 100%; padding: 6px 8px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 6px; font-size: 12px; font-weight: 600;" required>
                                </div>
                                <div>
                                    <label style="font-size: 10px; font-weight: 700; color: var(--text-muted, #64748b); display: block; margin-bottom: 2px;">🚢 Laut/Kg</label>
                                    <input type="number" name="cc_sea_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($cc['sea']); ?>" style="width: 100%; padding: 6px 8px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 6px; font-size: 12px; font-weight: 600;" required>
                                </div>
                                <div>
                                    <label style="font-size: 10px; font-weight: 700; color: var(--text-muted, #64748b); display: block; margin-bottom: 2px;">🧾 Pajak %</label>
                                    <input type="number" name="cc_tax_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($cc['tax_percent']); ?>" step="0.1" style="width: 100%; padding: 6px 8px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 6px; font-size: 12px; font-weight: 600;" required>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-group-fin" style="margin-bottom: 14px; padding-top: 12px; border-top: 1px dashed var(--border-soft, #e2e8f0);">
                    <label class="form-label-fin" style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">📦 PACKING & HANDLING FEE (SAMA UNTUK SEMUA MATA UANG)</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 10px; background: #fff; overflow: hidden;">
                        <span style="padding: 0 12px; background: #f8fafc; color: var(--text-muted, #64748b); font-weight: 700; border-right: 1.5px solid var(--border-soft, #e2e8f0); font-size: 13px;">Rp</span>
                        <input type="number" name="cargo_packing_fee" class="form-input-fin" value="<?php echo esc_attr($saved_packing_fee); ?>" style="width: 100%; padding: 10px 14px; border: none; font-weight: 600;" required>
                    </div>
                </div>

                <button type="submit" name="action_save_cargo" class="btn-submit-action" style="background: #0d9488; color: #fff; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">🌏 Simpan Negara & Tarif</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($has_payment || $has_tagihan): ?>
        <!-- KARTU 4: DAFTAR JENIS PEMBAYARAN/TAGIHAN (DIPAKAI BARENG DI
             KONFIRMASI PEMBAYARAN PUBLIK & REKAPAN TAGIHAN) -->
        <div style="background: #ffffff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 20px; padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04);">
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 4px;">💳 Jenis Pembayaran / Tagihan</h3>
                <p style="font-size: 12.5px; color: var(--text-muted, #64748b);">Dipakai BARENG di 2 tempat: dropdown "Jenis Pembayaran" (form Konfirmasi Pembayaran publik) dan "Jenis Tagihan" (Rekapan Tagihan). Ubah di sini otomatis nyambung ke keduanya.</p>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">Daftar Jenis Aktif Saat Ini:</label>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="payment-cat-list-container">
                        <?php foreach($saved_payment_categories as $cat): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="payment-cat-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="movePaymentCatRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="movePaymentCatRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="payment_categories[]" class="form-input-fin" value="<?php echo esc_attr($cat); ?>" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.payment-cat-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addPaymentCatRow()" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: 1px dashed #cbd5e1; width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Jenis Baru</button>
                </div>

                <button type="submit" name="action_save_payment_categories" class="btn-submit-action" style="background: #db2777; color: #fff; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">💾 Simpan Jenis Pembayaran</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($has_wh): ?>
        <!-- KARTU 5: PENGATURAN WAREHOUSE (MODE LINK KE PO + DAFTAR STATUS) -->
        <div style="background: #ffffff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 20px; padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04);">
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 4px;">📦 Pengaturan Warehouse</h3>
                <p style="font-size: 12.5px; color: var(--text-muted, #64748b);">Atur apakah entri Warehouse wajib nyambung ke Rekapan PO, dan tahapan status barang di gudang.</p>
            </div>

            <form method="POST" action="">
                <?php wp_nonce_field('goweb_setting_action_' . $store_id, 'goweb_setting_nonce'); ?>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 8px;">Keterkaitan dengan Rekapan PO:</label>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid <?php echo $saved_wh_link_mode === 'free' ? '#a78bfa' : 'var(--border-soft, #e2e8f0)'; ?>; border-radius: 10px; margin-bottom: 8px; cursor: pointer; font-size: 13px; font-weight: 600;">
                        <input type="radio" name="wh_link_mode" value="free" <?php checked($saved_wh_link_mode, 'free'); ?>>
                        Bebas berdiri sendiri (isi nama pemesan manual)
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid <?php echo $saved_wh_link_mode === 'linked' ? '#a78bfa' : 'var(--border-soft, #e2e8f0)'; ?>; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;">
                        <input type="radio" name="wh_link_mode" value="linked" <?php checked($saved_wh_link_mode, 'linked'); ?>>
                        Wajib pilih dari Rekapan PO yang sudah ada
                    </label>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">Tahapan Status Warehouse:</label>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="wh-status-list-container">
                        <?php foreach($saved_wh_statuses as $st): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="wh-status-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="moveWhStatusRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="moveWhStatusRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="wh_statuses[]" class="form-input-fin" value="<?php echo esc_attr($st); ?>" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.wh-status-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addWhStatusRow()" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: 1px dashed #cbd5e1; width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Status Baru</button>
                </div>

                <div style="margin-bottom: 16px; padding-top: 12px; border-top: 1px dashed var(--border-soft, #e2e8f0);">
                    <label style="display: block; font-size: 12px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">📦 Tahapan Status Box (Konsolidasi):</label>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;" id="box-status-list-container">
                        <?php foreach($saved_box_statuses as $st): ?>
                            <div style="display: flex; gap: 6px; align-items: center;" class="box-status-row-item">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <button type="button" onclick="moveBoxStatusRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
                                    <button type="button" onclick="moveBoxStatusRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
                                </div>
                                <input type="text" name="box_statuses[]" class="form-input-fin" value="<?php echo esc_attr($st); ?>" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
                                <button type="button" onclick="this.closest('.box-status-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addBoxStatusRow()" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: 1px dashed #cbd5e1; width: 100%; padding: 9px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;">+ Tambah Status Box Baru</button>
                </div>

                <button type="submit" name="action_save_wh_settings" class="btn-submit-action" style="background: #ea580c; color: #fff; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">💾 Simpan Pengaturan Warehouse</button>
            </form>
        </div>
        <?php endif; ?>

    </div>

</div>

<!-- CUSTOM MODAL POPUP -->
<div class="modal-overlay" id="successModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999; <?php echo !empty($modal_notification) ? 'opacity: 1; pointer-events: auto;' : 'opacity: 0; pointer-events: none;'; ?> transition: opacity 0.25s ease; padding: 20px;">
    <div class="modal-box" style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 40px; margin-bottom: 12px;">✅</div>
        <h3 style="font-size: 18px; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 8px;"><?php echo isset($modal_notification['title']) ? $modal_notification['title'] : 'Berhasil'; ?></h3>
        <p style="font-size: 13.5px; color: var(--text-muted, #64748b); margin-bottom: 24px; line-height: 1.5;"><?php echo isset($modal_notification['desc']) ? $modal_notification['desc'] : ''; ?></p>
        <button type="button" onclick="closeSuccessModal()" style="background: var(--primary, #0284c7); color: #fff; font-weight: 800; padding: 11px 24px; border-radius: 10px; border: none; cursor: pointer; width: 100%;">OK, Mengerti</button>
    </div>
</div>

<script>
function addDatabaseStatusRow() {
    const container = document.getElementById('status-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "status-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="moveStatusRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="moveStatusRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="custom_statuses[]" class="form-input-fin" value="Status Baru" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.status-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

// Naik/turunin urutan 1 baris status. Urutan DOM ini yang nentuin urutan
// array custom_statuses[] pas form disubmit — gak perlu ubah apapun di
// backend, cukup pindahin posisi elemennya di halaman.
function moveStatusRow(btn, direction) {
    const row = btn.closest('.status-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addPaymentCatRow() {
    const container = document.getElementById('payment-cat-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "payment-cat-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="movePaymentCatRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="movePaymentCatRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="payment_categories[]" class="form-input-fin" value="Jenis Baru" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.payment-cat-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function movePaymentCatRow(btn, direction) {
    const row = btn.closest('.payment-cat-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addWhStatusRow() {
    const container = document.getElementById('wh-status-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "wh-status-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="moveWhStatusRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="moveWhStatusRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="wh_statuses[]" class="form-input-fin" value="Status Baru" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.wh-status-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function moveWhStatusRow(btn, direction) {
    const row = btn.closest('.wh-status-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function addBoxStatusRow() {
    const container = document.getElementById('box-status-list-container');
    if(!container) return;
    const row = document.createElement('div');
    row.style.cssText = "display: flex; gap: 6px; align-items: center;";
    row.className = "box-status-row-item";
    row.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <button type="button" onclick="moveBoxStatusRow(this, -1)" title="Naikkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▲</button>
            <button type="button" onclick="moveBoxStatusRow(this, 1)" title="Turunkan urutan" style="background: #f1f5f9; color: var(--text-dark, #0f172a); border: none; width: 26px; height: 18px; border-radius: 4px; cursor: pointer; font-size: 10px; line-height: 1;">▼</button>
        </div>
        <input type="text" name="box_statuses[]" class="form-input-fin" value="Status Baru" style="flex: 1; padding: 8px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-size: 13px; font-weight: 600;" required>
        <button type="button" onclick="this.closest('.box-status-row-item').remove()" style="background: #fee2e2; color: #ef4444; border: none; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;">✕</button>
    `;
    container.appendChild(row);
}

function moveBoxStatusRow(btn, direction) {
    const row = btn.closest('.box-status-row-item');
    if (!row) return;
    if (direction === -1) {
        const prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else {
        const next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    if(modal) {
        modal.style.opacity = '0';
        modal.style.pointerEvents = 'none';
    }
}
</script>