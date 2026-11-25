<?php
if (!defined('ABSPATH')) exit;

// CATATAN: handler simpan/hapus/reset mutasi keuangan (action_save_finance,
// del_fin, reset_all_finance) ada di admin.php — dijalankan SEBELUM
// get_header(). File ini di-include SETELAH get_header() (HTML sudah mulai
// dikirim ke browser), jadi wp_redirect()/header() di sini tidak akan
// berfungsi ("headers already sent"). Data ($active_wallets,
// $finance_ledger_rows) sudah disiapkan admin.php dari tabel
// wp_goweb_finance_ledger / wp_goweb_wallets, tinggal dipakai di sini.
?>
<div class="fin-wrap menu-finance">
    <div class="finance-heading">
        <span class="finance-heading-icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
        <div>
            <h2>Laporan Keuangan</h2>
            <p>Pencatatan mutasi kas riil per siklus bulanan, saldo digital, modal dan laba bersih.</p>
        </div>
    </div>

    <style>
        .menu-finance { --finance-ink: #302b46; --finance-muted: #625d70; --finance-line: #e5e0f2; }
        .finance-heading { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
        .finance-heading-icon { display: grid; flex: 0 0 48px; width: 48px; height: 48px; place-items: center; border: 1px solid #ded7e9; border-radius: 14px; background: linear-gradient(145deg, #f8f5fb, #f0ebf7); color: var(--primary); font-size: 1.08rem; }
        .finance-heading h2 { margin: 0 0 5px; color: var(--finance-ink); font-size: 1.3rem; font-weight: 800; line-height: 1.3; letter-spacing: -.03em; }
        .finance-heading p { max-width: 68ch; margin: 0; color: var(--finance-muted); font-size: .875rem; line-height: 1.55; }
        .finance-cycle-actions { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
        .menu-finance .cycle-bar, .menu-finance .wallet-status-bar { border-color: var(--finance-line); border-radius: 16px; box-shadow: 0 5px 18px rgba(59, 48, 95, .045); }
        .menu-finance .cycle-bar { padding: 16px 19px; }
        .menu-finance .cycle-left { gap: 9px; }
        .menu-finance .cycle-title-label, .wallet-status-title { color: #514b63; font-size: .8rem; font-weight: 800; }
        .menu-finance .cycle-title-label i, .wallet-status-title i { margin-right: 5px; color: var(--primary); }
        .menu-finance .cycle-select, .menu-finance .year-select { min-height: 40px; }
        .menu-finance .cycle-info-pill { min-height: 38px; display: inline-flex; align-items: center; padding: 7px 12px; border-radius: 10px; font-size: .75rem; }
        .wallet-status-title { display: flex; align-items: center; }
        .wallet-pills-wrap { min-width: 0; }
        .menu-finance .wallet-pill { padding: 7px 12px; border-radius: 10px; border-color: #ded7e9; background: #f5f1f9; color: #51416f; font-size: .74rem; }
        .menu-finance .metrics-grid { gap: 14px; }
        .menu-finance .metric-card { min-width: 0; border: 1px solid var(--finance-line); border-radius: 16px; }
        .menu-finance .metric-card.green { border-bottom: 3px solid #6f9278; }
        .menu-finance .metric-card.blue { border-bottom: 3px solid #5b7aa6; }
        .menu-finance .metric-card.amber { border-bottom: 3px solid #a56a13; }
        .menu-finance .metric-card.highlight { border: 1px solid #786592; border-bottom: 3px solid #c4b2db; }
        .menu-finance .metric-label { display: flex; align-items: center; gap: 7px; min-height: 30px; color: #625d70; font-size: .69rem; line-height: 1.4; letter-spacing: .035em; }
        .menu-finance .metric-label i { color: var(--primary); font-size: .85rem; }
        .menu-finance .metric-card.highlight .metric-label { color: #f0ebf7; }
        .menu-finance .metric-card.highlight .metric-label i { color: #e3d9ef; }
        .menu-finance .metric-val { overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .menu-finance .metric-card.highlight .metric-val { white-space: nowrap; }
        .menu-finance .metric-sub { color: #625d70; line-height: 1.45; }
        .menu-finance .metric-card.highlight .metric-sub { color: #f0ebf7; }
        .menu-finance .roadmap-card, .menu-finance .form-box, .menu-finance .ledger-card { border: 1px solid var(--finance-line); border-radius: 16px; box-shadow: 0 5px 18px rgba(59, 48, 95, .045); }
        .menu-finance .roadmap-card { padding: 20px 22px; }
        .menu-finance .roadmap-head { align-items: flex-start; }
        .menu-finance .roadmap-title { color: var(--finance-ink); line-height: 1.45; }
        .menu-finance .roadmap-title i { margin-right: 6px; color: var(--primary); }
        .menu-finance .roadmap-legend { display: flex; gap: 8px 13px; flex-wrap: wrap; color: #514b63; line-height: 1.5; }
        .menu-finance .roadmap-legend span { white-space: nowrap; }
        .menu-finance .roadmap-legend i { margin-right: 4px; font-size: .55rem; }
        .menu-finance .roadmap-item { min-width: 0; border-radius: 12px; }
        .menu-finance .roadmap-item:focus-visible { outline: 3px solid rgba(109, 90, 145, .3); outline-offset: 2px; }
        .menu-finance .roadmap-icon { min-height: 20px; display: grid; place-items: center; font-size: 12px; }
        .menu-finance .roadmap-icon i { font-size: .9rem; }
        .menu-finance .forms-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .menu-finance .form-box { min-width: 0; padding: 20px; }
        .menu-finance .form-box-title { align-items: flex-start; gap: 9px; min-height: 44px; color: var(--finance-ink); line-height: 1.45; }
        .menu-finance .form-box-title i { flex: 0 0 18px; margin-top: 2px; color: var(--primary); text-align: center; }
        .menu-finance .form-group-fin { min-width: 0; }
        .menu-finance .form-label-fin { color: #514b63; }
        .menu-finance .form-input-fin, .menu-finance .form-select-fin { min-height: 40px; }
        .menu-finance .btn-submit-action { min-height: 42px; display: inline-flex; justify-content: center; align-items: center; gap: 8px; }
        #supplier-idr-preview { color: #302b46; font-variant-numeric: tabular-nums; }
        .menu-finance .ledger-card { padding: 20px; }
        .menu-finance .ledger-head { align-items: flex-start; }
        .menu-finance .filter-pills { gap: 7px; }
        .menu-finance .filter-btn { min-height: 40px; padding: 8px 13px; border-color: var(--finance-line); border-radius: 10px; background: #fff; color: #514b63; line-height: 1.35; }
        .menu-finance .filter-btn:hover:not(.active) { border-color: #c9bddb; background: #f8f5fb; color: #51416f; }
        .menu-finance .filter-btn.active { border-color: var(--primary); background: var(--primary); color: #fff; }
        .menu-finance .filter-btn:focus-visible, .finance-page-control:focus-visible, .finance-page-number:focus-visible, .btn-del-fin:focus-visible { outline: 3px solid rgba(109, 90, 145, .32); outline-offset: 2px; }
        .menu-finance .data-table { min-width: 850px; }
        .menu-finance .data-table td { font-variant-numeric: tabular-nums; }
        .finance-pagination-controls, .finance-page-numbers { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
        .finance-page-control, .finance-page-number { min-height: 40px; min-width: 40px; padding: 8px 11px; border: 1px solid var(--finance-line); border-radius: 10px; background: #fff; color: var(--finance-ink); font: inherit; font-size: .75rem; font-weight: 750; cursor: pointer; }
        .finance-page-control:hover:not(:disabled), .finance-page-number:hover:not(.is-active) { border-color: #c9bddb; background: #f8f5fb; }
        .finance-page-control:disabled { cursor: not-allowed; opacity: .5; }
        .finance-page-number.is-active { border-color: var(--primary); background: var(--primary); color: #fff; }
        .menu-finance .btn-export-csv, .menu-finance .btn-reset-data { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
        .menu-finance .btn-export-csv { background: #52775a; box-shadow: 0 4px 12px rgba(66, 98, 73, .16); }
        .menu-finance .btn-export-csv:hover { background: #426249; }
        .menu-finance .btn-reset-data { border-color: #e9c7c5; background: #fbf1ef; color: #8d3936; }
        .menu-finance .btn-reset-data:hover { background: #f6e5e3; }
        @media (max-width: 920px) {
            .menu-finance .forms-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .menu-finance .forms-grid > .form-box:last-child { grid-column: 1 / -1; }
        }
        @media (max-width: 600px) {
            .finance-heading { align-items: flex-start; gap: 11px; margin-bottom: 18px; }
            .finance-heading-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
            .finance-heading h2 { font-size: 1.15rem; }
            .finance-heading p { font-size: .79rem; }
            .menu-finance .cycle-bar, .menu-finance .wallet-status-bar { align-items: stretch; padding: 15px; }
            .menu-finance .cycle-left { width: 100%; gap: 8px; }
            .menu-finance .cycle-title-label { flex: 1 0 100%; }
            .menu-finance .year-select, .menu-finance .cycle-select { flex: 1 1 120px; min-width: 0; }
            .menu-finance .cycle-info-pill { flex: 1 0 100%; justify-content: center; }
            .finance-cycle-actions { display: grid; grid-template-columns: 1fr; width: 100%; }
            .finance-cycle-actions button { width: 100%; }
            .wallet-status-bar { align-items: flex-start; }
            .wallet-pills-wrap { width: 100%; }
            .menu-finance .metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
            .menu-finance .metric-card { padding: 14px 13px; border-left-width: 3px; }
            .menu-finance .metric-label { min-height: 42px; align-items: flex-start; font-size: .61rem; }
            .menu-finance .metric-val { font-size: .76rem !important; letter-spacing: -.035em; white-space: nowrap; }
            .menu-finance .metric-sub { font-size: .66rem; }
            .menu-finance .roadmap-card, .menu-finance .ledger-card { padding: 16px 14px; }
            .menu-finance .roadmap-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; }
            .menu-finance .roadmap-head { display: grid; }
            .menu-finance .roadmap-legend { gap: 6px 10px; }
            .menu-finance .forms-grid { grid-template-columns: 1fr; gap: 12px; }
            .menu-finance .forms-grid > .form-box:last-child { grid-column: auto; }
            .menu-finance .form-box { padding: 17px 15px; }
            .menu-finance .form-box-title { min-height: 0; }
            .menu-finance .filter-pills { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; }
            .menu-finance .filter-btn { padding-inline: 9px; font-size: .7rem; }
            #fin-pagination { align-items: flex-start !important; }
            .finance-pagination-controls { width: 100%; justify-content: space-between; gap: 4px; }
            .finance-page-control { min-width: 40px; padding-inline: 8px; }
            .finance-page-numbers { gap: 3px; }
            .finance-page-number { min-width: 36px; min-height: 40px; padding-inline: 8px; }
        }
        @media (max-width: 360px) {
            .menu-finance .metrics-grid { grid-template-columns: 1fr; }
            .menu-finance .metric-label { min-height: 0; }
        }
    </style>

    <div class="cycle-bar">
        <div class="cycle-left">
            <span class="cycle-title-label"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Siklus Aktif:</span>
            <select id="select-year" class="year-select" onchange="changeYearCycle(this.value)">
                <?php
                // Tahun digenerate dinamis (2 tahun ke belakang s/d 2 tahun ke
                // depan dari tahun berjalan) — sebelumnya hardcode 2026-2028,
                // dropdown jadi blank di tahun lain & user bisa salah paham
                // datanya "hilang" (filter-nya sendiri kebetulan masih jalan
                // via variabel PHP, tapi UI-nya gak bisa dipercaya).
                foreach ( range( intval( $current_real_year ) - 2, intval( $current_real_year ) + 2 ) as $fin_year_opt ):
                    $fin_year_opt = (string) $fin_year_opt; ?>
                    <option value="<?php echo esc_attr($fin_year_opt); ?>" <?php selected($fin_year_opt, $current_real_year); ?>><?php echo esc_html($fin_year_opt); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="select-month" class="cycle-select" onchange="changeMonthCycle(this.value)">
                <option value="1" <?php echo ($current_real_month == 1) ? 'selected' : ''; ?>>Januari</option>
                <option value="2" <?php echo ($current_real_month == 2) ? 'selected' : ''; ?>>Februari</option>
                <option value="3" <?php echo ($current_real_month == 3) ? 'selected' : ''; ?>>Maret</option>
                <option value="4" <?php echo ($current_real_month == 4) ? 'selected' : ''; ?>>April</option>
                <option value="5" <?php echo ($current_real_month == 5) ? 'selected' : ''; ?>>Mei</option>
                <option value="6" <?php echo ($current_real_month == 6) ? 'selected' : ''; ?>>Juni</option>
                <option value="7" <?php echo ($current_real_month == 7) ? 'selected' : ''; ?>>Juli</option>
                <option value="8" <?php echo ($current_real_month == 8) ? 'selected' : ''; ?>>Agustus</option>
                <option value="9" <?php echo ($current_real_month == 9) ? 'selected' : ''; ?>>September</option>
                <option value="10" <?php echo ($current_real_month == 10) ? 'selected' : ''; ?>>Oktober</option>
                <option value="11" <?php echo ($current_real_month == 11) ? 'selected' : ''; ?>>November</option>
                <option value="12" <?php echo ($current_real_month == 12) ? 'selected' : ''; ?>>Desember</option>
            </select>
            <div class="cycle-info-pill" id="cycle-range-text">Rentang: 01 ...</div>
        </div>

        <div class="finance-cycle-actions">
            <button type="button" class="btn-export-csv" onclick="downloadFinancialCSV()"><i class="fa-solid fa-download" aria-hidden="true"></i> Download Laporan (CSV)</button>
            <button type="button" class="btn-reset-data" onclick="resetFinanceData()"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Reset Data</button>
        </div>
    </div>

    <div class="wallet-status-bar">
        <div class="wallet-status-title"><i class="fa-solid fa-wallet" aria-hidden="true"></i> Saldo Aktif (Dompet Digital):</div>
        <div class="wallet-pills-wrap">
            <?php if (!empty($active_wallets)): foreach($active_wallets as $w_name => $w_val): ?>
                <div class="wallet-pill"><?php echo esc_html($w_name); ?>: <strong><?php echo number_format($w_val, 2, ',', '.'); ?></strong></div>
            <?php endforeach; else: ?>
                <span style="font-size: 12px; color: var(--text-muted);">Belum ada saldo dompet digital tercatat.</span>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Rekap per bulan dipakai bersama oleh kartu metrik siklus aktif dan roadmap tahunan.
    $monthly_financial_data = array();
    $ensure_finance_month = function($key) use (&$monthly_financial_data) {
        if (!isset($monthly_financial_data[$key])) {
            $monthly_financial_data[$key] = array('income' => 0, 'outflow' => 0, 'supplier' => 0, 'cargo' => 0, 'ops' => 0);
        }
    };

    if (!empty($finance_ledger_rows)) {
        foreach ($finance_ledger_rows as $tx) {
            $tx_type = isset($tx['tx_type']) ? $tx['tx_type'] : '';
            if (!in_array($tx_type, array('supplier_purchase', 'cargo_1', 'cargo_2', 'operational'), true)) continue;

            $key = date('Y-n', strtotime($tx['tx_date']));
            $ensure_finance_month($key);
            $tx_idr = isset($tx['idr_value']) ? floatval($tx['idr_value']) : 0;
            $monthly_financial_data[$key]['outflow'] += $tx_idr;

            if ($tx_type === 'supplier_purchase') {
                $monthly_financial_data[$key]['supplier'] += $tx_idr;
            } elseif ($tx_type === 'cargo_1' || $tx_type === 'cargo_2') {
                $monthly_financial_data[$key]['cargo'] += $tx_idr;
            } elseif ($tx_type === 'operational') {
                $monthly_financial_data[$key]['ops'] += $tx_idr;
            }
        }
    }

    if (!empty($subscription_history) && is_array($subscription_history)) {
        foreach ($subscription_history as $h) {
            if (($h['status'] ?? '') !== 'Verified' || ($h['payment_category'] ?? '') !== 'po' || empty($h['verified_at'])) continue;

            // Timestamp DB sudah memakai zona waktu WordPress; ambil bulan langsung agar tidak bergeser zona.
            $verified_at = (string) $h['verified_at'];
            $key = intval(substr($verified_at, 0, 4)) . '-' . intval(substr($verified_at, 5, 2));
            $ensure_finance_month($key);
            $monthly_financial_data[$key]['income'] += isset($h['nominal']) ? floatval($h['nominal']) : 0;
        }
    }

    $current_finance_key = intval($current_real_year) . '-' . intval($current_real_month);
    $current_finance_data = $monthly_financial_data[$current_finance_key] ?? array('income' => 0, 'outflow' => 0, 'supplier' => 0, 'cargo' => 0, 'ops' => 0);
    $total_omset_db = $current_finance_data['income'];
    $total_supplier_db = $current_finance_data['supplier'];
    $total_cargo_db = $current_finance_data['cargo'];
    $total_outflow_db = $total_supplier_db + $total_cargo_db + $current_finance_data['ops'];
    $net_profit_db = $total_omset_db - $total_outflow_db;
    ?>

    <div class="metrics-grid">
        <div class="metric-card green">
            <div class="metric-label"><i class="fa-solid fa-arrow-down-to-line" aria-hidden="true"></i> Total Pemasukan (Omset)</div>
            <div class="metric-val" id="finance-income-value" style="color: #15803d;">Rp <?php echo number_format($total_omset_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Dari konfirmasi pembayaran PO terverifikasi</div>
        </div>
        <div class="metric-card blue">
            <div class="metric-label"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Modal Belanja (HPP)</div>
            <div class="metric-val" id="finance-supplier-value">Rp <?php echo number_format($total_supplier_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Potong Saldo Dompet Digital</div>
        </div>
        <div class="metric-card amber">
            <div class="metric-label"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Ongkir Kargo (Tahap 1+2)</div>
            <div class="metric-val" id="finance-cargo-value" style="color: #d97706;">Rp <?php echo number_format($total_cargo_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Kargo Lokal WH + Forwarder Indo</div>
        </div>
        <div class="metric-card highlight">
            <div class="metric-label"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i> Surplus Laba Bersih</div>
            <div class="metric-val" id="finance-net-value">Rp <?php echo number_format($net_profit_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Pemasukan dikurangi pengeluaran riil</div>
        </div>
    </div>

    <div class="roadmap-card">
        <div class="roadmap-head">
            <div class="roadmap-title" id="roadmap-title-text"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Rekap Kesehatan Finansial Tahunan (12 Siklus Bulanan)</div>
            <div class="roadmap-legend"><span><i class="fa-solid fa-circle" style="color:#52775a" aria-hidden="true"></i> Prima (≥25%)</span><span><i class="fa-solid fa-circle" style="color:#2563eb" aria-hidden="true"></i> Aman (15-24%)</span><span><i class="fa-solid fa-circle" style="color:#9a6b00" aria-hidden="true"></i> Tipis (1-14%)</span><span><i class="fa-solid fa-circle" style="color:#b42318" aria-hidden="true"></i> Defisit (&lt;0%)</span></div>
        </div>
        <div class="roadmap-grid" id="roadmap-container"></div>
    </div>

    <div class="forms-grid">
        <div class="form-box">
            <div>
                <div class="form-box-title"><i class="fa-solid fa-wallet" aria-hidden="true"></i> 1. Top-Up Saldo Dompet Digital</div>
                <form method="POST" action="">
                    <?php wp_nonce_field('goweb_finance_action_' . $store_id, 'goweb_finance_nonce'); ?>
                    <input type="hidden" name="fin_type" value="wallet_topup">
                    <div class="form-group-fin"><label class="form-label-fin">Tanggal Transaksi:</label><input type="date" name="fin_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Nama Dompet (cth: Alipay, PromptPay):</label><input type="text" name="fin_wallet_name" class="form-input-fin" placeholder="cth: Alipay" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Nominal Saldo Masuk:</label><input type="number" step="0.01" name="fin_amount" class="form-input-fin" placeholder="Misal: 1500" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Total Bayar (IDR):</label><input type="number" name="fin_idr" class="form-input-fin" placeholder="Misal: 3675000" required></div>
                    <input type="hidden" name="fin_title" value="Top-Up Saldo Dompet Digital">
                    <button type="submit" name="action_save_finance" class="btn-submit-action btn-blue"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Simpan Top-Up Saldo</button>
                </form>
            </div>
        </div>

        <div class="form-box">
            <div>
                <div class="form-box-title"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> 2. Belanja Supplier (Potong Saldo)</div>
                <form method="POST" action="">
                    <?php wp_nonce_field('goweb_finance_action_' . $store_id, 'goweb_finance_nonce'); ?>
                    <input type="hidden" name="fin_type" value="supplier_purchase">
                    <div class="form-group-fin"><label class="form-label-fin">Tanggal Transaksi:</label><input type="date" name="fin_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Dompet yang Dipotong:</label>
                        <select name="fin_wallet_name" id="supplier-wallet-select" class="form-input-fin" required>
                            <option value="">— Pilih dompet —</option>
                            <?php foreach ( $active_wallets as $wn => $bal ):
                                $wallet_cost = $wallet_moving_costs[$wn] ?? array('average_rate' => 0, 'is_valid' => false, 'balance_matches' => false);
                                $wn_rate = ($wallet_cost['is_valid'] && $wallet_cost['balance_matches']) ? $wallet_cost['average_rate'] : 0;
                            ?>
                                <option value="<?php echo esc_attr($wn); ?>" data-rate="<?php echo esc_attr($wn_rate); ?>" data-balance="<?php echo esc_attr($bal); ?>"><?php echo esc_html($wn); ?> (Saldo: <?php echo esc_html($bal); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ( empty($active_wallets) ): ?>
                            <small style="color: #ef4444; font-size: 11px;">Belum ada dompet — Top-Up Saldo dulu di form sebelah.</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group-fin"><label class="form-label-fin">Keterangan / Nama Batch:</label><input type="text" name="fin_title" class="form-input-fin" placeholder="Misal: Batch 15 PO Standee" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Nominal Saldo Terpotong:</label><input type="number" step="0.01" id="supplier-amount-input" name="fin_amount" class="form-input-fin" placeholder="Misal: 280" required></div>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Estimasi Total Rupiah (otomatis):</label>
                        <div id="supplier-idr-preview" style="padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; background: var(--slate-50); font-weight: 700; color: var(--text-dark);">Rp 0</div>
                        <small style="color: var(--text-muted); font-size: 11px;">Pakai moving average dari modal Top-Up yang masih tersisa. Top-Up menambah modal; belanja mengurangi saldo dan modal pada rate rata-rata saat itu.</small>
                        <small id="supplier-cost-warning" role="status" style="display:none; color:#9a3412; font-size:11px;">Riwayat modal dan saldo dompet belum cocok. Periksa transaksi sebelum mencatat belanja.</small>
                    </div>
                    <button type="submit" name="action_save_finance" class="btn-submit-action btn-green"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Catat Belanja Supplier</button>
                </form>
            </div>
        </div>

        <div class="form-box">
            <div>
                <div class="form-box-title"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> 3. Biaya Kargo &amp; Operasional</div>
                <form method="POST" action="">
                    <?php wp_nonce_field('goweb_finance_action_' . $store_id, 'goweb_finance_nonce'); ?>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Kategori Pengeluaran:</label>
                        <select name="fin_type" class="form-select-fin" required>
                            <option value="cargo_1">Ongkir Tahap 1 (Lokal WH)</option>
                            <option value="cargo_2">Ongkir Tahap 2 (Forwarder Indo)</option>
                            <option value="operational">Operasional / Bubble Wrap</option>
                        </select>
                    </div>
                    <div class="form-group-fin"><label class="form-label-fin">Tanggal Transaksi:</label><input type="date" name="fin_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Keterangan Tagihan:</label><input type="text" name="fin_title" class="form-input-fin" placeholder="Misal: Invoice Kargo Laut" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Total Nominal (IDR):</label><input type="number" name="fin_idr" class="form-input-fin" placeholder="Misal: 420000" required></div>
                    <input type="hidden" name="fin_wallet_name" value="-">
                    <input type="hidden" name="fin_amount" value="0">
                    <button type="submit" name="action_save_finance" class="btn-submit-action btn-amber"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Catat Tagihan Biaya</button>
                </form>
            </div>
        </div>
    </div>

    <div class="ledger-card finance-ledger">
        <div class="ledger-head">
            <div class="filter-pills" role="group" aria-label="Filter jenis mutasi">
                <button type="button" class="filter-btn active" aria-pressed="true" onclick="filterLedger('ALL', this)">Semua Mutasi (<span id="count-all">0</span>)</button>
                <button type="button" class="filter-btn" aria-pressed="false" onclick="filterLedger('wallet_topup', this)">Top-Up Saldo</button>
                <button type="button" class="filter-btn" aria-pressed="false" onclick="filterLedger('supplier_purchase', this)">Belanja Supplier</button>
                <button type="button" class="filter-btn" aria-pressed="false" onclick="filterLedger('cargo', this)">Tagihan Kargo (1+2)</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Sumber / Dompet</th>
                        <th>Keterangan Transaksi</th>
                        <th>Nominal Saldo</th>
                        <th>Kurs / Rate</th>
                        <th>Total (IDR)</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="ledger-rows">
                    <?php if (!empty($finance_ledger_rows)): foreach($finance_ledger_rows as $tx): 
                        $tx_year = date('Y', strtotime($tx['tx_date']));
                        $tx_month = date('n', strtotime($tx['tx_date']));
                    ?>
                        <tr data-type="<?php echo esc_attr($tx['tx_type']); ?>" data-year="<?php echo esc_attr($tx_year); ?>" data-month="<?php echo esc_attr($tx_month); ?>">
                            <td style="color: var(--slate-500); font-size: 12.5px;"><?php echo esc_html( date('d/m/Y', strtotime($tx['tx_date'])) ); ?></td>
                            <td>
                                <?php if($tx['tx_type']=='wallet_topup'): ?><span class="badge-type type-topup">Top-Up Saldo</span>
                                <?php elseif($tx['tx_type']=='supplier_purchase'): ?><span class="badge-type type-tmall">Belanja Supplier</span>
                                <?php elseif($tx['tx_type']=='cargo_1' || $tx['tx_type']=='cargo_2'): ?><span class="badge-type type-cargo1">Kargo & Ongkir</span>
                                <?php else: ?><span class="badge-type type-ops">Operasional</span><?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html($tx['wallet_name']); ?></strong></td>
                            <td style="font-weight: 700;"><?php echo esc_html($tx['title']); ?></td>
                            <td><?php echo $tx['amount'] > 0 ? number_format($tx['amount'], 2, ',', '.') : '—'; ?></td>
                            <td style="color: var(--slate-500); font-size: 12px;"><?php echo $tx['rate'] > 0 ? 'Rp ' . number_format($tx['rate'], 0, ',', '.') : '—'; ?></td>
                            <td style="font-weight: 800;">Rp <?php echo number_format($tx['idr_value'], 0, ',', '.'); ?></td>
                            <td style="text-align: center;">
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg(array('del_fin' => $tx['id'], 'goweb_tenant_id' => $store_id)), 'goweb_finance_delete_' . $store_id ) ); ?>" onclick="openDeleteFinanceModal(event, this.href); return false;" class="btn-del-fin" title="Hapus Mutasi" aria-label="Hapus mutasi"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr data-year="<?php echo $current_real_year; ?>" data-month="<?php echo $current_real_month; ?>"><td colspan="8" style="text-align: center; color: var(--slate-400); padding: 25px;">Belum ada mutasi tercatat di database.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- KONTROL PAGINATION -->
        <div id="fin-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 4px 2px; flex-wrap: wrap; gap: 10px;">
            <span id="fin-page-info" style="font-size: 12.5px; color: var(--slate-500); font-weight: 600;"></span>
            <div class="finance-pagination-controls">
                <button type="button" id="fin-prev" class="finance-page-control"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Sebelumnya</button>
                <span id="fin-page-numbers" class="finance-page-numbers"></span>
                <button type="button" id="fin-next" class="finance-page-control">Berikutnya <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI HAPUS 1 MUTASI -->
<div class="modal-overlay" id="deleteFinanceModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; padding: 20px;">
    <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"><i class="fa-solid fa-trash-can"></i></div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--slate-900); margin-bottom: 8px;">Hapus Mutasi Ini?</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px;">Mutasi ini akan dihapus permanen dan saldo dompet terkait akan dikoreksi balik otomatis.</p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="cancel-delete-finance" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; color: var(--text-muted); cursor: pointer;">Batal</button>
            <a id="confirm-delete-finance" href="#" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">Ya, Hapus</a>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI RESET SEMUA DATA KEUANGAN -->
<div class="modal-overlay" id="resetFinanceModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; padding: 20px;">
    <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 420px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--slate-900); margin-bottom: 8px;">Reset SELURUH Data Keuangan?</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px;">Semua mutasi &amp; saldo dompet tenant ini akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.</p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="cancel-reset-finance" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; color: var(--text-muted); cursor: pointer;">Batal</button>
            <button type="button" id="confirm-reset-finance" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Reset Semua</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Data Omset dikelompokkan berdasarkan timestamp verifikasi yang dicatat sistem.
    // Kunci bulanan tetap "YYYY-N" tanpa nol di depan.
    const MONTHLY_FIN_DATA = <?php echo wp_json_encode( $monthly_financial_data ); ?>;

    // Live preview estimasi Total Rupiah di form Belanja Supplier — dihitung
    // dari rate rata-rata top-up dompet yang dipilih (data-rate di tiap opsi).
    const supplierWalletSelect = document.getElementById('supplier-wallet-select');
    const supplierAmountInput = document.getElementById('supplier-amount-input');
    const supplierIdrPreview = document.getElementById('supplier-idr-preview');
    const supplierCostWarning = document.getElementById('supplier-cost-warning');

    function updateSupplierPreview() {
        if (!supplierWalletSelect || !supplierAmountInput || !supplierIdrPreview) return;
        const selectedOption = supplierWalletSelect.options[supplierWalletSelect.selectedIndex];
        const rate = selectedOption ? parseFloat(selectedOption.getAttribute('data-rate')) || 0 : 0;
        const amount = parseFloat(supplierAmountInput.value) || 0;
        const estimatedIdr = Math.round(rate * amount);
        supplierIdrPreview.textContent = 'Rp ' + estimatedIdr.toLocaleString('id-ID');
        if (supplierCostWarning) supplierCostWarning.style.display = 'none';
        if (rate === 0 && selectedOption && selectedOption.value !== '') {
            supplierIdrPreview.textContent = 'Belum bisa dihitung';
            supplierIdrPreview.style.color = '#9a3412';
            if (supplierCostWarning) {
                supplierCostWarning.textContent = 'Biaya belum dapat dihitung; periksa riwayat saldo/modal dompet.';
                supplierCostWarning.style.display = 'block';
            }
        } else if (selectedOption && selectedOption.value !== '' && amount > (parseFloat(selectedOption.getAttribute('data-balance')) || 0)) {
            supplierIdrPreview.textContent = 'Saldo tidak cukup';
            supplierIdrPreview.style.color = '#9a3412';
            if (supplierCostWarning) {
                supplierCostWarning.textContent = 'Nominal belanja melebihi saldo dompet yang tersedia.';
                supplierCostWarning.style.display = 'block';
            }
        } else {
            supplierIdrPreview.style.color = '';
        }
    }
    if (supplierWalletSelect) supplierWalletSelect.addEventListener('change', updateSupplierPreview);
    if (supplierAmountInput) supplierAmountInput.addEventListener('input', updateSupplierPreview);

    const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    let selectedYear = '<?php echo $current_real_year; ?>';
    let selectedMonth = parseInt('<?php echo $current_real_month; ?>');
    const FIN_PER_PAGE = 25;
    let finCurrentPage = 1;
    let currentLedgerFilterType = 'ALL';

    const yearSelectEl = document.getElementById('select-year');
    const monthSelectEl = document.getElementById('select-month');
    if(yearSelectEl) yearSelectEl.value = selectedYear;
    if(monthSelectEl) monthSelectEl.value = selectedMonth;

    window.changeYearCycle = function(yearVal) {
        selectedYear = yearVal;
        filterRowsByCycle();
    }

    window.changeMonthCycle = function(monthVal) {
        selectedMonth = parseInt(monthVal);
        const mName = MONTH_NAMES[selectedMonth - 1];
        const lastDay = new Date(parseInt(selectedYear), selectedMonth, 0).getDate();
        const rangeText = document.getElementById('cycle-range-text');
        if(rangeText) rangeText.innerText = `Rentang: 01 ${mName.substring(0,3)} ${selectedYear} s/d ${lastDay} ${mName.substring(0,3)} ${selectedYear}`;
        filterRowsByCycle();
    }

    function updateCycleMetrics() {
        const data = MONTHLY_FIN_DATA[`${selectedYear}-${selectedMonth}`] || {};
        const income = Number(data.income) || 0;
        const supplier = Number(data.supplier) || 0;
        const cargo = Number(data.cargo) || 0;
        const ops = Number(data.ops) || 0;
        const formatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

        const incomeValue = document.getElementById('finance-income-value');
        const supplierValue = document.getElementById('finance-supplier-value');
        const cargoValue = document.getElementById('finance-cargo-value');
        const netValue = document.getElementById('finance-net-value');
        if (incomeValue) incomeValue.textContent = `Rp ${formatter.format(income)}`;
        if (supplierValue) supplierValue.textContent = `Rp ${formatter.format(supplier)}`;
        if (cargoValue) cargoValue.textContent = `Rp ${formatter.format(cargo)}`;
        if (netValue) netValue.textContent = `Rp ${formatter.format(income - supplier - cargo - ops)}`;
    }

    function filterRowsByCycle() {
        const rows = document.querySelectorAll('#ledger-rows tr');
        let visibleCount = 0;
        rows.forEach(row => {
            if (row.cells.length <= 1) return;
            const y = row.getAttribute('data-year');
            const mo = row.getAttribute('data-month');
            if (!y || !mo) return; 
            if (y === selectedYear && parseInt(mo) === selectedMonth) {
                row.dataset.matchCycle = 'true';
                visibleCount++;
            } else {
                row.dataset.matchCycle = 'false';
            }
        });
        const countAll = document.getElementById('count-all');
        if(countAll) countAll.innerText = visibleCount;
        updateCycleMetrics();
        renderRoadmap();
        finCurrentPage = 1; // reset ke halaman 1 tiap ganti siklus bulan/tahun
        applyLedgerFilterAndPaginate();
    }

    function renderRoadmap() {
        const container = document.getElementById('roadmap-container');
        const titleText = document.getElementById('roadmap-title-text');
        if(!container) return;
        container.innerHTML = '';
        if(titleText) titleText.innerHTML = `<i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Rekap Kesehatan Finansial Tahunan (${selectedYear})`;

        for (let m = 1; m <= 12; m++) {
            let icon = 'fa-circle-minus', label = 'Belum Ada', labelColor = 'var(--text-muted)', marginStr = '—';
            const key = `${selectedYear}-${m}`;
            const data = MONTHLY_FIN_DATA[key];

            if (data && (data.income > 0 || data.outflow > 0)) {
                const net = data.income - data.outflow;
                // Margin dihitung dari INCOME (omset) — kalau bulan itu gak
                // ada pemasukan sama sekali tapi ada pengeluaran, otomatis
                // Defisit (gak mungkin dibagi nol jadi "aman").
                const marginPct = data.income > 0 ? (net / data.income) * 100 : (data.outflow > 0 ? -100 : 0);
                marginStr = marginPct.toFixed(0) + '%';

                if (marginPct < 0) {
                    icon = 'fa-circle-xmark'; label = 'Defisit'; labelColor = '#b42318';
                } else if (marginPct < 15) {
                    icon = 'fa-circle-exclamation'; label = 'Tipis'; labelColor = '#805719';
                } else if (marginPct < 25) {
                    icon = 'fa-circle-check'; label = 'Aman'; labelColor = '#2563eb';
                } else {
                    icon = 'fa-circle-check'; label = 'Prima'; labelColor = '#45634d';
                }
            }

            const div = document.createElement('div');
            div.className = `roadmap-item ${m === selectedMonth ? 'active' : ''}`;
            div.setAttribute('role', 'button');
            div.setAttribute('tabindex', '0');
            div.setAttribute('aria-label', `${MONTH_NAMES[m - 1]}: ${label}, ${marginStr}. Pilih siklus`);
            div.onclick = () => {
                if(monthSelectEl) monthSelectEl.value = m;
                window.changeMonthCycle(m);
            };
            div.onkeydown = (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    div.click();
                }
            };
            div.innerHTML = `
                <div class="roadmap-month">${MONTH_NAMES[m - 1].substring(0, 3)}</div>
                <div class="roadmap-icon" style="color:${labelColor};"><i class="fa-solid ${icon}" aria-hidden="true"></i></div>
                <div class="roadmap-status-text" style="color:${labelColor};">${label}</div>
                <div class="roadmap-margin">${marginStr}</div>
            `;
            container.appendChild(div);
        }
    }

    if(document.getElementById('select-month')) {
        window.changeMonthCycle(selectedMonth);
    }

    window.filterLedger = function(filterType, btn) {
        document.querySelectorAll('.filter-btn').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
        btn.classList.add('active');
        btn.setAttribute('aria-pressed', 'true');
        currentLedgerFilterType = filterType;
        const rows = document.querySelectorAll('#ledger-rows tr');
        rows.forEach(row => {
            if (row.cells.length <= 1) return;
            const y = row.getAttribute('data-year');
            const mo = row.getAttribute('data-month');
            const t = row.getAttribute('data-type');
            if (!t) return;
            let matchCycle = (y === selectedYear && parseInt(mo) === selectedMonth);
            if (!matchCycle) {
                row.dataset.matchCycle = 'false';
                return;
            }
            row.dataset.matchCycle = 'true';
            if (filterType === 'ALL') {
                row.dataset.matchType = 'true';
            } else if (filterType === 'cargo') {
                row.dataset.matchType = (t === 'cargo_1' || t === 'cargo_2') ? 'true' : 'false';
            } else {
                row.dataset.matchType = (t === filterType) ? 'true' : 'false';
            }
        });
        finCurrentPage = 1; // reset ke halaman 1 tiap ganti filter jenis mutasi
        applyLedgerFilterAndPaginate();
    }

    // ---- PAGINATION (sisi browser) ----
    // Nempel DI ATAS 2 filter yang udah ada (siklus bulan + jenis mutasi).
    // Kedua filter di atas cuma nandain data-matchCycle/data-matchType —
    // fungsi ini yang nentuin display FINAL, biar gak bentrok sama pagination.
    function applyLedgerFilterAndPaginate() {
        const allRows = Array.from(document.querySelectorAll('#ledger-rows tr')).filter(r => r.cells.length > 1);
        const matchedRows = allRows.filter(r => r.dataset.matchCycle === 'true' && r.dataset.matchType !== 'false');

        allRows.forEach(r => { r.style.display = 'none'; });

        const totalPages = Math.ceil(matchedRows.length / FIN_PER_PAGE) || 1;
        if (finCurrentPage > totalPages) finCurrentPage = totalPages;
        const start = (finCurrentPage - 1) * FIN_PER_PAGE;
        const end = start + FIN_PER_PAGE;
        matchedRows.slice(start, end).forEach(r => { r.style.display = ''; });

        renderFinPagination(matchedRows.length, start, totalPages);
    }

    function renderFinPagination(totalItems, start, totalPages) {
        const pagination = document.getElementById('fin-pagination');
        const pageInfo = document.getElementById('fin-page-info');
        const pageNumbers = document.getElementById('fin-page-numbers');
        const prevBtn = document.getElementById('fin-prev');
        const nextBtn = document.getElementById('fin-next');
        if (!pagination) return;

        if (totalItems <= FIN_PER_PAGE) {
            pagination.style.display = 'none';
            return;
        }
        pagination.style.display = 'flex';
        if (pageInfo) pageInfo.textContent = `Menampilkan ${start + 1}–${Math.min(start + FIN_PER_PAGE, totalItems)} dari ${totalItems} mutasi`;
        if (prevBtn) { prevBtn.disabled = finCurrentPage === 1; prevBtn.style.opacity = finCurrentPage === 1 ? '0.4' : '1'; prevBtn.style.cursor = finCurrentPage === 1 ? 'not-allowed' : 'pointer'; }
        if (nextBtn) { nextBtn.disabled = finCurrentPage === totalPages; nextBtn.style.opacity = finCurrentPage === totalPages ? '0.4' : '1'; nextBtn.style.cursor = finCurrentPage === totalPages ? 'not-allowed' : 'pointer'; }

        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            let startPage = Math.max(1, finCurrentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            startPage = Math.max(1, endPage - 4);
            for (let p = startPage; p <= endPage; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = p;
                const isActive = p === finCurrentPage;
                btn.className = 'finance-page-number' + (isActive ? ' is-active' : '');
                if (isActive) btn.setAttribute('aria-current', 'page');
                btn.addEventListener('click', () => { finCurrentPage = p; applyLedgerFilterAndPaginate(); });
                pageNumbers.appendChild(btn);
            }
        }
    }

    const finPrevBtn = document.getElementById('fin-prev');
    const finNextBtn = document.getElementById('fin-next');
    if (finPrevBtn) finPrevBtn.addEventListener('click', () => { if (finCurrentPage > 1) { finCurrentPage--; applyLedgerFilterAndPaginate(); } });
    if (finNextBtn) finNextBtn.addEventListener('click', () => { finCurrentPage++; applyLedgerFilterAndPaginate(); });

    // Modal konfirmasi hapus 1 mutasi (gantiin confirm() bawaan browser)
    window.openDeleteFinanceModal = function(e, deleteUrl) {
        const modal = document.getElementById('deleteFinanceModal');
        const confirmLink = document.getElementById('confirm-delete-finance');
        if (modal && confirmLink) {
            confirmLink.href = deleteUrl;
            modal.style.opacity = '1';
            modal.style.pointerEvents = 'auto';
        }
    }
    const deleteFinanceModalEl = document.getElementById('deleteFinanceModal');
    const cancelDeleteFinanceBtn = document.getElementById('cancel-delete-finance');
    if (cancelDeleteFinanceBtn) cancelDeleteFinanceBtn.addEventListener('click', () => {
        deleteFinanceModalEl.style.opacity = '0';
        deleteFinanceModalEl.style.pointerEvents = 'none';
    });
    if (deleteFinanceModalEl) {
        deleteFinanceModalEl.addEventListener('click', function(e) {
            if (e.target === this) { this.style.opacity = '0'; this.style.pointerEvents = 'none'; }
        });
    }

    // Modal konfirmasi reset semua data (gantiin confirm() bawaan browser)
    window.resetFinanceData = function() {
        const modal = document.getElementById('resetFinanceModal');
        if (modal) { modal.style.opacity = '1'; modal.style.pointerEvents = 'auto'; }
    }
    const resetFinanceModalEl = document.getElementById('resetFinanceModal');
    const cancelResetFinanceBtn = document.getElementById('cancel-reset-finance');
    const confirmResetFinanceBtn = document.getElementById('confirm-reset-finance');
    if (cancelResetFinanceBtn) cancelResetFinanceBtn.addEventListener('click', () => {
        resetFinanceModalEl.style.opacity = '0';
        resetFinanceModalEl.style.pointerEvents = 'none';
    });
    if (resetFinanceModalEl) {
        resetFinanceModalEl.addEventListener('click', function(e) {
            if (e.target === this) { this.style.opacity = '0'; this.style.pointerEvents = 'none'; }
        });
    }
    if (confirmResetFinanceBtn) {
        confirmResetFinanceBtn.addEventListener('click', function() {
            // & bukan ? di depan store_id karena $store_query_suffix (kalau ada) sudah bawa tanda ? sendiri
            window.location.href = window.location.pathname + '<?php echo esc_js( $store_query_suffix ); ?>' + '<?php echo esc_js( empty($store_query_suffix) ? "?" : "&" ); ?>goweb_tenant_id=<?php echo esc_js($store_id); ?>&reset_all_finance=1&_wpnonce=<?php echo esc_js( wp_create_nonce('goweb_finance_reset_' . $store_id) ); ?>';
        });
    }

    window.downloadFinancialCSV = function() {
        const mName = MONTH_NAMES[selectedMonth - 1];
        let csvContent = "\uFEFFLAPORAN KEUANGAN & KAS JASTIP\n";
        csvContent += `Periode Siklus,${mName} ${selectedYear}\n\n`;
        csvContent += "TANGGAL,KATEGORI,SUMBER DOMPET,KETERANGAN,NOMINAL,KURS,TOTAL (IDR)\n";
        // Cek data-matchCycle/data-matchType (status filter LOGIS), BUKAN
        // r.style.display — soalnya display sekarang juga dipengaruhi
        // pagination (cuma nunjukkin 1 halaman), padahal ekspor harus
        // tetap ambil SEMUA hasil filter.
        const clean = (txt) => {
            let s = String(txt == null ? '' : txt).replace(/"/g, '""').replace(/\n/g, ' - ').trim();
            if (/^[=+\-@]/.test(s)) s = "'" + s;
            return s;
        };
        const rows = document.querySelectorAll('#ledger-rows tr');
        rows.forEach(r => {
            const matchesFilter = r.dataset.matchCycle === 'true' && r.dataset.matchType !== 'false';
            if(r.cells.length > 1 && matchesFilter) {
                const cols = r.querySelectorAll('td');
                if(cols.length >= 7) {
                    csvContent += `"${clean(cols[0].innerText)}","${clean(cols[1].innerText)}","${clean(cols[2].innerText)}","${clean(cols[3].innerText)}",${clean(cols[4].innerText)},${clean(cols[5].innerText)},"${clean(cols[6].innerText)}"\n`;
                }
            }
        });
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", `Laporan_Keuangan_${mName}_${selectedYear}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
});
</script>