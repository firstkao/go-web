<?php
if (!defined('ABSPATH')) exit;

$current_package = goweb_get_package( isset($current_tenant['package_key']) ? $current_tenant['package_key'] : 'starter' );
$all_packages = goweb_get_package_definitions();
$subscription_durations = goweb_get_subscription_durations();
// Rate harian efektif dari siklus yang lagi jalan — dipakai buat ngitung
// proration kalau upgrade di tengah jalan. Kalau kosong (tenant lama
// sebelum fitur ini ada), fallback ke harga paket sekarang / 30.
$tenant_cycle_daily_rate = isset($current_tenant['cycle_daily_rate']) && floatval($current_tenant['cycle_daily_rate']) > 0
    ? floatval($current_tenant['cycle_daily_rate'])
    : ( $current_package['price'] / 30 );
// Pakai nilai yang UDAH dihitung admin.php ($tenant_storage_bytes) — jangan
// hitung ulang di sini, biar gak dobel query+baca disk buat 1 page load yang sama.
$current_storage_used_display = isset($tenant_storage_bytes) ? $tenant_storage_bytes : goweb_calculate_tenant_storage_bytes( $store_id );
$storage_used_mb = round( $current_storage_used_display / 1048576, 1 );
?>
<h2>Status Langganan</h2>
<p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 20px;">Informasi paket sewa platform bulanan dan riwayat pembayaran toko ke pusat.</p>

<div style="background: #fff; border: 1px solid var(--border-soft); padding: 24px; border-radius: var(--radius-md); margin-bottom: 24px;">
    <div class="info-row"><span>Paket Aktif</span><strong><?php echo esc_html($current_package['name']); ?></strong></div>
    <div class="info-row"><span>Biaya Sewa Platform</span><strong>Rp<?php echo number_format($current_package['price'], 0, ',', '.'); ?> / bulan</strong></div>
    <div class="info-row"><span>Pemakaian Storage</span><strong><?php echo $storage_used_mb; ?> MB / <?php echo esc_html($current_package['storage_limit']); ?> MB</strong></div>
    <div class="info-row"><span>Status Akun</span><span class="status-badge <?php echo $is_store_online ? 'badge-mint' : 'badge-peach'; ?>"><?php echo $is_store_online ? 'Aktif & Terverifikasi' : 'Menunggu Perpanjangan / Offline'; ?></span></div>
    <div class="info-row"><span>Sisa Masa Aktif</span><strong style="color: <?php echo esc_attr($active_urgency_color); ?>;"><?php echo $remaining_days; ?> Hari Lagi</strong></div>
    
    <div style="margin-top: 20px;">
        <button type="button" id="open-confirm-modal" class="btn-submit"><i class="fa-solid fa-receipt"></i> Konfirmasi Perpanjangan / Ganti Paket</button>
    </div>
</div>

<div style="background: #fff; border: 1px solid var(--border-soft); padding: 24px; border-radius: var(--radius-md);">
    <h3 style="font-size: 1.1rem; margin-bottom: 16px;"><i class="fa-solid fa-clock-rotate-left" style="color: var(--pastel-purple);"></i> Riwayat Pembayaran Sewa</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Tanggal Bayar</th><th>Periode Sewa</th><th>Nominal</th><th>Pengirim</th><th>Bukti</th><th>Status</th><th style="text-align:center;">Keterangan</th></tr>
            </thead>
            <tbody>
                <?php 
                if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_extension_proof']) ) {
                    if ( ! isset($_POST['goweb_subscription_nonce']) || ! wp_verify_nonce($_POST['goweb_subscription_nonce'], 'goweb_subscription_action_' . $store_id) ) {
                        wp_die('Sesi tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
                    }
                    $sender_name = sanitize_text_field($_POST['confirm_name']);
                    $chosen_package_key = isset($_POST['chosen_package']) ? sanitize_text_field($_POST['chosen_package']) : $current_package['key'];
                    $chosen_package = goweb_get_package( $chosen_package_key );
                    $proof_url = '';
                    $submission_blocked = false;
                    $is_same_package = ( $chosen_package_key === $current_package['key'] );

                    // Downgrade gak didukung sama sekali (keputusan produk: biar
                    // data historis tenant gak bikin sesak database & runyam
                    // sistem retensi) — proteksi server-side ini jaga-jaga kalau
                    // ada yang coba kirim request manual di luar UI (yang udah
                    // gak nampilin opsi downgrade sama sekali).
                    if ( $chosen_package['storage_limit'] < $current_package['storage_limit'] ) {
                        $submission_blocked = true;
                        echo '<div style="background:#fef2f2; color:#991b1b; padding:12px 16px; border-radius:8px; font-weight:700; margin-bottom:16px;"><i class="fa-solid fa-triangle-exclamation"></i> Downgrade paket tidak didukung. Pilih paket sekarang atau upgrade ke paket lebih besar.</div>';
                    }

                    // Paket yang dipilih (termasuk paket sekarang) harus beneran
                    // muat storage yang UDAH terpakai — kalau kepenuhan, wajib
                    // pilih paket yang lebih besar. Proteksi server-side, jaga-jaga
                    // di luar UI yang udah nge-disable opsi ini otomatis.
                    if ( ! $submission_blocked && ( $chosen_package['storage_limit'] * 1048576 ) < $current_storage_used_display ) {
                        $submission_blocked = true;
                        echo '<div style="background:#fef2f2; color:#991b1b; padding:12px 16px; border-radius:8px; font-weight:700; margin-bottom:16px;"><i class="fa-solid fa-triangle-exclamation"></i> Paket ' . esc_html($chosen_package['name']) . ' kapasitasnya gak cukup buat storage kamu yang udah terpakai (' . round($current_storage_used_display/1048576, 1) . ' MB). Pilih paket yang lebih besar.</div>';
                    }

                    // Tentuin nominal yang harus dibayar — beda formula buat
                    // Perpanjangan (pilih durasi, kena diskon) vs Upgrade
                    // (proration nilai sisa hari, gak ada pilihan durasi karena
                    // active_until gak berubah).
                    $duration_months = null;
                    $payment_nominal = 0;
                    $period_text = '';

                    if ( ! $submission_blocked && $is_same_package ) {
                        $duration_months = isset($_POST['duration_months']) ? intval($_POST['duration_months']) : 1;
                        if ( ! isset($subscription_durations[$duration_months]) ) $duration_months = 1;
                        $payment_nominal = goweb_calculate_duration_price( $chosen_package, $duration_months );
                        $period_text = $subscription_durations[$duration_months]['label'] . ' ke Depan (Paket ' . $chosen_package['name'] . ')';
                    } elseif ( ! $submission_blocked && ! $is_same_package ) {
                        // UPGRADE: proration berdasar NILAI (Rupiah) sisa hari yang
                        // belum kepake, bukan cuma pindahin sisa hari mentah — biar
                        // upgrade ke paket lebih mahal gak jadi "gratis" buat sisa
                        // durasinya.
                        $due_ts_now = goweb_get_tenant_active_until( $current_tenant );
                        $remaining_days_now = max( 0, ceil( ( $due_ts_now - time() ) / DAY_IN_SECONDS ) );

                        // Kalau masa aktif udah 0 (expired/offline), proration-nya
                        // bakal selalu Rp0 (gak ada nilai sisa buat dikonversi) —
                        // upgrade jadi gak masuk akal di kondisi ini. Wajib
                        // perpanjang dulu (pilih paket yang sama) baru boleh upgrade.
                        if ( $remaining_days_now <= 0 ) {
                            $submission_blocked = true;
                            echo '<div style="background:#fef2f2; color:#991b1b; padding:12px 16px; border-radius:8px; font-weight:700; margin-bottom:16px;"><i class="fa-solid fa-triangle-exclamation"></i> Masa aktif kamu udah habis, jadi belum bisa upgrade (gak ada sisa nilai buat dikonversi). Perpanjang paket sekarang dulu, baru upgrade kalau perlu.</div>';
                        } else {
                            $remaining_value = $tenant_cycle_daily_rate * $remaining_days_now;
                            $new_daily_rate = $chosen_package['price'] / 30;
                            $new_value_for_remaining = $new_daily_rate * $remaining_days_now;
                            $payment_nominal = max( 0, round( $new_value_for_remaining - $remaining_value ) );
                            $period_text = 'Upgrade ke Paket ' . $chosen_package['name'] . ' (sisa ' . $remaining_days_now . ' hari, prorata)';
                        }
                    }

                    // LOGIKA BIAYA QRIS DITAMBAHKAN KE NOMINAL DATABASE
                    if ( ! $submission_blocked && $payment_nominal > 0 ) {
                        $qris_fee_pct = ($payment_nominal < 500000) ? 0.003 : 0.007; // 0.3% atau 0.7%
                        $qris_fee = ceil($payment_nominal * $qris_fee_pct);
                        $payment_nominal = $payment_nominal + $qris_fee;
                    }

                    if ( ! $submission_blocked && ! empty($_FILES['confirm_proof']['name']) ) {
                        require_once( ABSPATH . 'wp-admin/includes/file.php' );
                        $upload_overrides = array( 'test_form' => false, 'mimes' => array(
                            'jpg|jpeg|jpe' => 'image/jpeg',
                            'png'          => 'image/png',
                            'gif'          => 'image/gif',
                            'webp'         => 'image/webp',
                        ) );
                        $uploaded = wp_handle_upload($_FILES['confirm_proof'], $upload_overrides);
                        
                        if ( isset($uploaded['url']) && isset($uploaded['file']) ) { 
                            $image_editor = wp_get_image_editor( $uploaded['file'] );
                            if ( ! is_wp_error( $image_editor ) ) {
                                $image_editor->resize( 800, 1200, false ); // Maksimal lebar 800px
                                $image_editor->set_quality( 70 ); // Kompres 70%
                                $image_editor->save( $uploaded['file'] );
                            }
                            $proof_url = esc_url($uploaded['url']); 
                            // CATATAN: gak perlu increment storage_used manual lagi —
                            // sekarang dihitung LIVE dari semua data tenant tiap kali
                            // dibutuhkan (goweb_calculate_tenant_storage_bytes()).
                        }
                    }

                    if ( ! $submission_blocked ) {
                        $wpdb->insert( $payments_table, array(
                            'store_id'                   => $store_id,
                            'payment_category'           => 'subscription',
                            'period'                     => $period_text,
                            'payment_type'               => null,
                            'nominal'                    => $payment_nominal,
                            'sender'                     => $sender_name,
                            'proof_url'                  => $proof_url,
                            'status'                     => 'Pending',
                            'requested_package'          => $is_same_package ? null : $chosen_package_key,
                            'requested_duration_months'  => $is_same_package ? $duration_months : null,
                            'created_at'                 => current_time('mysql'),
                        ) );
                        echo '<div style="background:#ecfdf5; color:#059669; padding:12px 16px; border-radius:8px; font-weight:700; margin-bottom:16px;"><i class="fa-solid fa-circle-check"></i> Konfirmasi ' . ( $is_same_package ? 'perpanjangan' : 'upgrade' ) . ' terkirim! Menunggu verifikasi Super Admin.</div>';
                    }
                }
                $sub_history = array_filter( $subscription_history, function( $r ) { return $r['payment_category'] === 'subscription'; } );
                if ( !empty($sub_history) ):
                    foreach ( $sub_history as $row ):
                ?>
                    <tr>
                        <td><?php echo esc_html( date('d/m/Y H:i', strtotime($row['created_at'])) ); ?></td>
                        <td><?php echo esc_html($row['period']); ?></td>
                        <td>Rp <?php echo number_format(intval($row['nominal']), 0, ',', '.'); ?></td>
                        <td><?php echo esc_html($row['sender']); ?></td>
                        <td><?php if (!empty($row['proof_url'])): ?><a href="<?php echo esc_url($row['proof_url']); ?>" target="_blank" style="color: var(--pastel-purple); font-weight: 700; text-decoration: underline;">Lihat</a><?php else: ?>-<?php endif; ?></td>
                        <td><?php if ($row['status'] == 'Verified'): ?><span class="status-badge badge-mint">Verified</span><?php else: ?><span class="status-badge badge-peach">Pending</span><?php endif; ?></td>
                        <td style="text-align: center;">
                            <?php if ($row['status'] != 'Verified'): ?>
                                <span style="font-size: 0.78rem; color: var(--pastel-peach, #ea580c); font-weight: 700;"><i class="fa-solid fa-clock"></i> Menunggu verifikasi Super Admin</span>
                            <?php elseif ( strpos($row['period'], 'Sewa Awal') !== false ): ?>
                                <span style="font-size: 0.78rem; color: #0ea5e9; font-weight: 700;"><i class="fa-solid fa-flag-checkered"></i> Pendaftaran Awal</span>
                            <?php elseif ( ! empty($row['requested_package']) ): $hist_pkg = goweb_get_package($row['requested_package']); ?>
                                <span style="font-size: 0.78rem; color: var(--pastel-purple); font-weight: 700;"><i class="fa-solid fa-arrow-up"></i> Upgrade ke <?php echo esc_html($hist_pkg['name']); ?></span>
                            <?php elseif ( ! empty($row['requested_duration_months']) && $row['requested_duration_months'] > 1 ): ?>
                                <span style="font-size: 0.78rem; color: #15803d; font-weight: 700;"><i class="fa-solid fa-rotate"></i> Perpanjangan <?php echo esc_html($row['requested_duration_months']); ?> Bulan</span>
                            <?php else: ?>
                                <span style="font-size: 0.78rem; color: #15803d; font-weight: 700;"><i class="fa-solid fa-rotate"></i> Perpanjangan</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 20px;">Belum ada riwayat perpanjangan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL KONFIRMASI PERPANJANGAN DENGAN QRIS — 3 STEP -->
<div id="confirm-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; overflow-y: auto; padding: 20px;">
    <div style="background: #fff; width: 100%; max-width: 560px; padding: 32px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); position: relative; margin: auto;">
        <button type="button" id="close-confirm-modal" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted);"><i class="fa-solid fa-xmark"></i></button>

        <?php
        // Tentuin paket mana yang jadi default terpilih: paket sekarang KALAU
        // masih cukup buat usage aktual, atau paket valid pertama (yg storage-nya
        // cukup) kalau paket sekarang udah kepenuhan. PENTING: hitung
        // $remaining_days_now DULUAN, soalnya loop ini juga harus ngindarin
        // milih paket upgrade (bukan paket sekarang) kalau masa aktif udah 0 —
        // kalau kebablasan, radio bisa "checked" tapi "disabled" sekaligus
        // (upgrade yang keblokir server-side tapi keliatan kepilih di UI).
        $remaining_days_now = max( 0, ceil( ( goweb_get_tenant_active_until($current_tenant) - time() ) / DAY_IN_SECONDS ) );
        $default_package_key = null;
        foreach ( $all_packages as $pkg ) {
            if ( $pkg['storage_limit'] < $current_package['storage_limit'] ) continue;
            $too_small = ( $pkg['storage_limit'] * 1048576 ) < $current_storage_used_display;
            $is_current_pkg = ( $pkg['key'] === $current_package['key'] );
            $upgrade_blocked = ( ! $is_current_pkg && $remaining_days_now <= 0 );
            if ( ! $too_small && ! $upgrade_blocked ) { $default_package_key = $pkg['key']; break; }
        }
        ?>

        <!-- STEP 1: PILIH PAKET -->
        <div id="sub-step-1">
            <h3 style="font-size: 1.15rem; font-weight: 800; color: #7c3aed; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-invoice"></i> Pilih Paket
            </h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">Tetap di paket sekarang buat perpanjang durasi, atau upgrade ke paket lebih besar.</p>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px; max-height: 340px; overflow-y: auto; padding-right: 4px;">
                <?php foreach ( $all_packages as $pkg ):
                    $is_current = ( $pkg['key'] === $current_package['key'] );
                    if ( $pkg['storage_limit'] < $current_package['storage_limit'] ) continue;
                    $too_small = ( $pkg['storage_limit'] * 1048576 ) < $current_storage_used_display;
                    $upgrade_needs_renewal_first = ( ! $is_current && $remaining_days_now <= 0 );
                    $is_disabled = $too_small || $upgrade_needs_renewal_first;
                    $is_checked = ( $pkg['key'] === $default_package_key );
                ?>
                    <label class="sub-pkg-card <?php echo $is_current ? 'is-current' : ''; ?> <?php echo $is_disabled ? 'is-disabled' : ''; ?>" style="border: 1.5px solid <?php echo $is_checked ? '#a78bfa' : '#e5e7eb'; ?>; border-radius: 12px; padding: 12px; text-align: center; cursor: <?php echo $is_disabled ? 'not-allowed' : 'pointer'; ?>; opacity: <?php echo $is_disabled ? '0.45' : '1'; ?>; position: relative;">
                        <input type="radio" name="chosen_package" value="<?php echo esc_attr($pkg['key']); ?>" data-is-current="<?php echo $is_current ? '1' : '0'; ?>" <?php echo $is_checked ? 'checked' : ''; ?> <?php echo $is_disabled ? 'disabled' : ''; ?> style="position:absolute; top:8px; left:8px;">
                        <div style="font-weight:800; font-size:0.85rem; margin-top:12px;"><?php echo esc_html($pkg['name']); ?></div>
                        <div style="font-size:0.75rem; color:var(--text-muted); margin:2px 0;"><?php echo esc_html($pkg['storage_limit']); ?> MB</div>
                        <div style="font-weight:700; font-size:0.8rem; color:#7c3aed;">Rp<?php echo number_format($pkg['price'],0,',','.'); ?> <span style="font-weight:400; color:var(--text-muted);">/bln</span></div>
                        <?php if ($is_current): ?><div style="font-size:0.65rem; color:#a78bfa; font-weight:700; margin-top:2px;">PAKET SEKARANG</div><?php endif; ?>
                        <?php if ($too_small): ?><div style="font-size:0.65rem; color:#ef4444; font-weight:700; margin-top:2px;">Storage gak cukup</div><?php endif; ?>
                        <?php if ($upgrade_needs_renewal_first): ?><div style="font-size:0.6rem; color:#ef4444; font-weight:700; margin-top:2px;">Perpanjang dulu buat upgrade</div><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if ( $default_package_key === null ): ?>
                <div style="background:#fef2f2; color:#991b1b; padding:10px 14px; border-radius:8px; font-size:12.5px; font-weight:600; margin-bottom:16px;">Gak ada paket yang bisa dipilih otomatis — mungkin storage kamu udah lebih besar dari paket paling gede yang ada, atau kombinasi storage penuh + masa aktif habis. Hubungi Super Admin buat bantuan lebih lanjut.</div>
            <?php endif; ?>

            <button type="button" id="sub-step-1-next" class="btn-submit" style="width: 100%; justify-content: center; background: linear-gradient(135deg, #a78bfa, #818cf8);" <?php echo $default_package_key === null ? 'disabled' : ''; ?>>Lanjut <i class="fa-solid fa-arrow-right"></i></button>
        </div>

        <!-- STEP 1B: DURASI ATAU RINGKASAN UPGRADE -->
        <div id="sub-step-1b" style="display: none;">
            <button type="button" class="sub-back-btn" data-back-to="1" style="background:none; border:none; color:var(--text-muted); font-size:0.8rem; font-weight:700; cursor:pointer; margin-bottom:10px; padding:0;"><i class="fa-solid fa-arrow-left"></i> Kembali pilih paket</button>

            <div id="duration-picker-section" style="display: none;">
                <h3 style="font-size: 1.15rem; font-weight: 800; color: #7c3aed; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-calendar-days"></i> Pilih Durasi
                </h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">Makin lama komitmen, makin gede diskonnya.</p>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
                    <?php foreach ( $subscription_durations as $d_months => $d ): ?>
                        <label class="duration-pick-card" data-months="<?php echo esc_attr($d_months); ?>" style="display: flex; justify-content: space-between; align-items: center; border: 1.5px solid <?php echo $d_months === 1 ? '#a78bfa' : '#e5e7eb'; ?>; border-radius: 12px; padding: 12px 16px; cursor: pointer;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <input type="radio" name="duration_months_radio" value="<?php echo esc_attr($d_months); ?>" <?php echo $d_months === 1 ? 'checked' : ''; ?>>
                                <div>
                                    <div style="font-weight:800; font-size:0.9rem; color:var(--text-dark);"><?php echo esc_html($d['label']); ?></div>
                                    <?php if ($d['discount_pct'] > 0): ?><div style="font-size:0.7rem; color:#15803d; font-weight:700;">Hemat <?php echo $d['discount_pct']; ?>%</div><?php endif; ?>
                                </div>
                            </div>
                            <div class="duration-price-display" style="font-weight:800; font-size:0.95rem; color:#7c3aed;">—</div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="upgrade-summary-section" style="display: none;">
                <h3 style="font-size: 1.15rem; font-weight: 800; color: #7c3aed; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-arrow-up-right-dots"></i> Ringkasan Upgrade
                </h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">Sisa masa aktif kamu (<strong><?php echo $remaining_days_now; ?> hari</strong>) otomatis dikonversi ke paket baru — cuma bayar selisihnya, tanggal jatuh tempo <strong>gak berubah</strong>.</p>
                <div style="background: #faf8ff; border: 1px solid #ddd6fe; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; font-size:0.82rem; padding:5px 0; color:var(--text-muted);"><span>Sisa hari aktif</span><strong style="color:var(--text-dark);"><?php echo $remaining_days_now; ?> hari</strong></div>
                    <div style="display:flex; justify-content:space-between; font-size:0.82rem; padding:5px 0; color:var(--text-muted);"><span>Nilai sisa paket lama</span><strong style="color:var(--text-dark);" id="upgrade-old-value">—</strong></div>
                    <div style="display:flex; justify-content:space-between; font-size:0.82rem; padding:5px 0; color:var(--text-muted);"><span>Nilai penuh paket baru (buat sisa hari yg sama)</span><strong style="color:var(--text-dark);" id="upgrade-new-value">—</strong></div>
                    <div style="border-top:1px dashed #ddd6fe; margin:6px 0;"></div>
                    <div style="display:flex; justify-content:space-between; font-size:0.9rem; padding:5px 0;"><span style="font-weight:700; color:var(--text-dark);">Selisih yang dibayar</span><strong style="color:#7c3aed; font-size:1.05rem;" id="upgrade-diff-value">—</strong></div>
                </div>
            </div>

            <button type="button" id="sub-step-1b-next" class="btn-submit" style="width: 100%; justify-content: center; background: linear-gradient(135deg, #a78bfa, #818cf8);">Lanjut ke Pembayaran <i class="fa-solid fa-arrow-right"></i></button>
        </div>

        <!-- STEP 2: PEMBAYARAN -->
        <div id="sub-step-2" style="display: none;">
            <button type="button" class="sub-back-btn" data-back-to="1b" style="background:none; border:none; color:var(--text-muted); font-size:0.8rem; font-weight:700; cursor:pointer; margin-bottom:10px; padding:0;"><i class="fa-solid fa-arrow-left"></i> Kembali</button>

            <h3 style="font-size: 1.15rem; font-weight: 800; color: #7c3aed; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-qrcode"></i> Konfirmasi Pembayaran
            </h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;"><strong id="chosen-summary-label"></strong></p>

            <!-- KOTAK QRIS (Rincian Biaya) -->
            <div style="background: #faf8ff; border: 1.5px dashed #c4b5fd; padding: 20px; border-radius: 16px; text-align: center; margin-bottom: 20px;">
                
                <div style="background: #fff; padding: 12px; margin-bottom: 16px; border-radius: 8px; border: 1px solid var(--border-soft); text-align: left;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 0.85rem;">
                        <span>Harga Dasar:</span>
                        <strong id="display-base-price">Rp 0</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: var(--text-muted); font-size: 0.8rem;">
                        <span id="display-fee-label">Biaya QRIS:</span>
                        <strong id="display-fee-price">Rp 0</strong>
                    </div>
                    <hr style="border: 0; border-top: 1px solid var(--border-soft); margin: 8px 0;">
                    <div style="display: flex; justify-content: space-between; font-size: 1.05rem;">
                        <strong>Total Transfer:</strong>
                        <strong id="modal-price-display" style="color: #7c3aed;">Rp 0</strong>
                    </div>
                </div>

                <span style="font-size: 0.75rem; font-weight: 800; color: #7c3aed; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 12px;">SCAN QRIS DIBAWAH INI</span>
                <div style="background: #fff; display: inline-block; padding: 10px; border-radius: 12px; border: 1px solid var(--border-soft);">
                    <img src="https://mercatoria.id/wp-content/uploads/2026/02/qris-barcode.jpg" alt="QRIS Pembayaran" style="width: 150px; height: 150px; display: block; margin: 0 auto;">
                </div>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" id="sub-payment-form">
                <?php wp_nonce_field('goweb_subscription_action_' . $store_id, 'goweb_subscription_nonce'); ?>
                <input type="hidden" name="chosen_package" id="chosen-package-hidden" value="<?php echo esc_attr($default_package_key); ?>">
                <input type="hidden" name="duration_months" id="duration-months-hidden" value="1">
                <div class="form-group"><label>Nama Pengirim / Akun</label><input type="text" name="confirm_name" class="form-control" placeholder="cth: Devina Maharani" required></div>
                <div class="form-group" style="margin-bottom: 24px;"><label>Unggah Bukti Pembayaran</label><input type="file" name="confirm_proof" class="form-control" accept="image/*" required></div>

                <div style="display: flex; gap: 12px;">
                    <button type="submit" name="submit_extension_proof" class="btn-submit" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #a78bfa, #818cf8);">Kirim Konfirmasi</button>
                    <button type="button" id="cancel-confirm-modal" style="padding: 10px 20px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; cursor: pointer; color: var(--text-muted);">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const openBtn = document.getElementById('open-confirm-modal');
    const closeBtn = document.getElementById('close-confirm-modal');
    const cancelBtn = document.getElementById('cancel-confirm-modal');
    const modalOverlay = document.getElementById('confirm-modal-overlay');
    const packagePrices = <?php echo wp_json_encode( array_combine( array_keys($all_packages), array_column($all_packages, 'price') ) ); ?>;
    const packageNames = <?php echo wp_json_encode( array_combine( array_keys($all_packages), array_column($all_packages, 'name') ) ); ?>;
    const DURATIONS = <?php echo wp_json_encode( $subscription_durations ); ?>; 
    const CURRENT_PACKAGE_KEY = <?php echo wp_json_encode( $current_package['key'] ); ?>;
    const CYCLE_DAILY_RATE = <?php echo wp_json_encode( round($tenant_cycle_daily_rate, 2) ); ?>;
    const REMAINING_DAYS_NOW = <?php echo wp_json_encode( $remaining_days_now ); ?>;

    const step1 = document.getElementById('sub-step-1');
    const step1b = document.getElementById('sub-step-1b');
    const step2 = document.getElementById('sub-step-2');
    const nextBtn = document.getElementById('sub-step-1-next');
    const step1bNextBtn = document.getElementById('sub-step-1b-next');
    const chosenSummaryLabel = document.getElementById('chosen-summary-label');
    const chosenHidden = document.getElementById('chosen-package-hidden');
    const durationHidden = document.getElementById('duration-months-hidden');
    
    // UI Elements for QRIS Breakdown
    const displayBase = document.getElementById('display-base-price');
    const displayFeeLabel = document.getElementById('display-fee-label');
    const displayFeePrice = document.getElementById('display-fee-price');
    const priceDisplay = document.getElementById('modal-price-display');
    
    const durationPickerSection = document.getElementById('duration-picker-section');
    const upgradeSummarySection = document.getElementById('upgrade-summary-section');

    let selectedDurationMonths = 1;
    let currentFlow = 'duration'; 

    function goToStep(step) {
        if (step1) step1.style.display = (step === 1) ? 'block' : 'none';
        if (step1b) step1b.style.display = (step === '1b') ? 'block' : 'none';
        if (step2) step2.style.display = (step === 2) ? 'block' : 'none';
    }

    function resetToStep1() { goToStep(1); }

    if(openBtn && modalOverlay) {
        openBtn.addEventListener('click', () => { resetToStep1(); modalOverlay.style.display = 'flex'; });
        if(closeBtn) closeBtn.addEventListener('click', () => { modalOverlay.style.display = 'none'; });
        if(cancelBtn) cancelBtn.addEventListener('click', () => { modalOverlay.style.display = 'none'; });
        modalOverlay.addEventListener('click', (e) => {
            if(e.target === modalOverlay) modalOverlay.style.display = 'none';
        });
    }

    function calcDurationPrice(pkgKey, months) {
        const price = packagePrices[pkgKey] || 0;
        const d = DURATIONS[months];
        const discountPct = d ? d.discount_pct : 0;
        return Math.round( price * months * (1 - (discountPct / 100)) );
    }

    function calcUpgradeDiff(pkgKey) {
        const newDailyRate = (packagePrices[pkgKey] || 0) / 30;
        const oldValue = CYCLE_DAILY_RATE * REMAINING_DAYS_NOW;
        const newValue = newDailyRate * REMAINING_DAYS_NOW;
        return { oldValue, newValue, diff: Math.max(0, Math.round(newValue - oldValue)) };
    }

    function fmtRp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            const selected = document.querySelector('input[name="chosen_package"]:checked');
            if (!selected) return;
            const key = selected.value;
            const isCurrent = selected.getAttribute('data-is-current') === '1';
            if (chosenHidden) chosenHidden.value = key;

            if (isCurrent) {
                currentFlow = 'duration';
                if (durationPickerSection) durationPickerSection.style.display = 'block';
                if (upgradeSummarySection) upgradeSummarySection.style.display = 'none';
                document.querySelectorAll('.duration-pick-card').forEach(card => {
                    const months = parseInt(card.getAttribute('data-months'));
                    const priceEl = card.querySelector('.duration-price-display');
                    if (priceEl) priceEl.textContent = fmtRp(calcDurationPrice(key, months));
                });
            } else {
                currentFlow = 'upgrade';
                if (durationPickerSection) durationPickerSection.style.display = 'none';
                if (upgradeSummarySection) upgradeSummarySection.style.display = 'block';
                const calc = calcUpgradeDiff(key);
                const oldValEl = document.getElementById('upgrade-old-value');
                const newValEl = document.getElementById('upgrade-new-value');
                const diffValEl = document.getElementById('upgrade-diff-value');
                if (oldValEl) oldValEl.textContent = fmtRp(calc.oldValue);
                if (newValEl) newValEl.textContent = fmtRp(calc.newValue);
                if (diffValEl) diffValEl.textContent = fmtRp(calc.diff);
            }
            goToStep('1b');
        });
    }

    document.querySelectorAll('.duration-pick-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.duration-pick-card').forEach(c => c.style.borderColor = '#e5e7eb');
            card.style.borderColor = '#a78bfa';
            const radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            selectedDurationMonths = parseInt(card.getAttribute('data-months'));
        });
    });

    if (step1bNextBtn) {
        step1bNextBtn.addEventListener('click', () => {
            const key = chosenHidden ? chosenHidden.value : CURRENT_PACKAGE_KEY;
            const pkgName = packageNames[key] !== undefined ? packageNames[key] : key;
            
            let basePrice = 0;

            if (currentFlow === 'duration') {
                const checkedRadio = document.querySelector('input[name="duration_months_radio"]:checked');
                selectedDurationMonths = checkedRadio ? parseInt(checkedRadio.value) : 1;
                if (durationHidden) durationHidden.value = selectedDurationMonths;
                
                basePrice = calcDurationPrice(key, selectedDurationMonths);
                
                const dLabel = DURATIONS[selectedDurationMonths] ? DURATIONS[selectedDurationMonths].label : selectedDurationMonths + ' Bulan';
                if (chosenSummaryLabel) chosenSummaryLabel.textContent = `Perpanjangan ${dLabel} — Paket ${pkgName}`;
            } else {
                if (durationHidden) durationHidden.value = ''; 
                
                const calc = calcUpgradeDiff(key);
                basePrice = calc.diff;
                
                if (chosenSummaryLabel) chosenSummaryLabel.textContent = `Upgrade ke Paket ${pkgName} (prorata sisa ${REMAINING_DAYS_NOW} hari)`;
            }
            
            // Hitung Biaya Tambahan QRIS
            let feePct = 0;
            let feeLabelText = "";
            if (basePrice < 500000) {
                feePct = 0.003;
                feeLabelText = "0,3%";
            } else {
                feePct = 0.007;
                feeLabelText = "0,7%";
            }
            
            const feeAmount = Math.ceil(basePrice * feePct);
            const totalPrice = basePrice + feeAmount;
            
            // Update UI Nominal
            if (displayBase) displayBase.textContent = fmtRp(basePrice);
            if (displayFeeLabel) displayFeeLabel.textContent = `Biaya Layanan QRIS (${feeLabelText}):`;
            if (displayFeePrice) displayFeePrice.textContent = fmtRp(feeAmount);
            if (priceDisplay) priceDisplay.textContent = fmtRp(totalPrice);
            
            goToStep(2);
        });
    }

    document.querySelectorAll('.sub-back-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-back-to');
            goToStep(target === '1' ? 1 : '1b');
        });
    });
});
</script>