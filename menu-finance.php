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
<div class="fin-wrap">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Laporan Keuangan</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Pencatatan mutasi kas riil per siklus bulanan, saldo digital, modal dan laba bersih.</p>
    </div>

    <div class="cycle-bar">
        <div class="cycle-left">
            <span class="cycle-title-label">📅 Siklus Aktif:</span>
            <select id="select-year" class="year-select" onchange="changeYearCycle(this.value)">
                <option value="2026" <?php echo ($current_real_year == '2026') ? 'selected' : ''; ?>>2026</option>
                <option value="2027" <?php echo ($current_real_year == '2027') ? 'selected' : ''; ?>>2027</option>
                <option value="2028" <?php echo ($current_real_year == '2028') ? 'selected' : ''; ?>>2028</option>
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

        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn-export-csv" onclick="downloadFinancialCSV()">📥 Download Laporan (CSV)</button>
            <button type="button" class="btn-reset-data" onclick="resetFinanceData()">🗑️ Reset Data</button>
        </div>
    </div>

    <div class="wallet-status-bar">
        <div style="font-size: 13px; font-weight: 800; color: var(--slate-700);">🌐 Saldo Aktif (Dompet Digital):</div>
        <div class="wallet-pills-wrap">
            <?php if (!empty($active_wallets)): foreach($active_wallets as $w_name => $w_val): ?>
                <div class="wallet-pill"><?php echo esc_html($w_name); ?>: <strong><?php echo number_format($w_val, 2, ',', '.'); ?></strong></div>
            <?php endforeach; else: ?>
                <span style="font-size: 12px; color: var(--text-muted);">Belum ada saldo dompet digital tercatat.</span>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $total_omset_db = 0; 
    $total_supplier_db = 0; 
    $total_cargo_db = 0; 
    $total_ops_db = 0;
    
    if (!empty($finance_ledger_rows)) {
        foreach($finance_ledger_rows as $tx) {
            $tx_type = isset($tx['tx_type']) ? $tx['tx_type'] : '';
            $tx_idr  = isset($tx['idr_value']) ? floatval($tx['idr_value']) : 0;
            
            if ($tx_type === 'supplier_purchase') {
                $total_supplier_db += $tx_idr;
            } elseif ($tx_type === 'cargo_1' || $tx_type === 'cargo_2') {
                $total_cargo_db += $tx_idr;
            } elseif ($tx_type === 'operational') {
                $total_ops_db += $tx_idr;
            }
        }
    }

    if (!empty($subscription_history) && is_array($subscription_history)) {
        foreach($subscription_history as $h) {
            $h_status = isset($h['status']) ? $h['status'] : '';
            $h_category = isset($h['payment_category']) ? $h['payment_category'] : '';
            $h_nominal = isset($h['nominal']) ? intval($h['nominal']) : 0;
            
            if ($h_status === 'Verified' && $h_category === 'po') {
                $total_omset_db += $h_nominal;
            }
        }
    }

    $total_outflow_db = $total_supplier_db + $total_cargo_db + $total_ops_db;
    $net_profit_db = $total_omset_db - $total_outflow_db;
    ?>

    <div class="metrics-grid">
        <div class="metric-card green">
            <div class="metric-label">📥 Total Pemasukan (Omset)</div>
            <div class="metric-val" style="color: #15803d;">Rp <?php echo number_format($total_omset_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Dari konfirmasi pembayaran PO terverifikasi</div>
        </div>
        <div class="metric-card blue">
            <div class="metric-label">🛒 Modal Belanja (HPP)</div>
            <div class="metric-val">Rp <?php echo number_format($total_supplier_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Potong Saldo Dompet Digital</div>
        </div>
        <div class="metric-card amber">
            <div class="metric-label">✈️ Ongkir Kargo (Tahap 1+2)</div>
            <div class="metric-val" style="color: #d97706;">Rp <?php echo number_format($total_cargo_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Kargo Lokal WH + Forwarder Indo</div>
        </div>
        <div class="metric-card highlight">
            <div class="metric-label">💎 Surplus Laba Bersih</div>
            <div class="metric-val">Rp <?php echo number_format($net_profit_db, 0, ',', '.'); ?></div>
            <div class="metric-sub">Pemasukan dikurangi pengeluaran riil</div>
        </div>
    </div>

    <div class="roadmap-card">
        <div class="roadmap-head">
            <div class="roadmap-title" id="roadmap-title-text">🗓️ Rekap Kesehatan Finansial Tahunan (12 Siklus Bulanan)</div>
            <div class="roadmap-legend">🟢 Prima (≥25%) | 🔵 Aman (15-24%) | 🟡 Tipis (1-14%) | 🔴 Defisit (&lt;0%)</div>
        </div>
        <div class="roadmap-grid" id="roadmap-container"></div>
    </div>

    <div class="forms-grid">
        <div class="form-box">
            <div>
                <div class="form-box-title">➕ 1. Top-Up Saldo Dompet Digital</div>
                <form method="POST" action="">
                    <?php wp_nonce_field('goweb_finance_action_' . $store_id, 'goweb_finance_nonce'); ?>
                    <input type="hidden" name="fin_type" value="wallet_topup">
                    <div class="form-group-fin"><label class="form-label-fin">Tanggal Transaksi:</label><input type="date" name="fin_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Nama Dompet (cth: Alipay, PromptPay):</label><input type="text" name="fin_wallet_name" class="form-input-fin" placeholder="cth: Alipay" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Nominal Saldo Masuk:</label><input type="number" step="0.01" name="fin_amount" class="form-input-fin" placeholder="Misal: 1500" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Total Bayar (IDR):</label><input type="number" name="fin_idr" class="form-input-fin" placeholder="Misal: 3675000" required></div>
                    <input type="hidden" name="fin_title" value="Top-Up Saldo Dompet Digital">
                    <button type="submit" name="action_save_finance" class="btn-submit-action btn-blue">💾 Simpan Top-Up Saldo</button>
                </form>
            </div>
        </div>

        <div class="form-box">
            <div>
                <div class="form-box-title">🛍️ 2. Belanja Supplier (Potong Saldo)</div>
                <form method="POST" action="">
                    <?php wp_nonce_field('goweb_finance_action_' . $store_id, 'goweb_finance_nonce'); ?>
                    <input type="hidden" name="fin_type" value="supplier_purchase">
                    <div class="form-group-fin"><label class="form-label-fin">Tanggal Transaksi:</label><input type="date" name="fin_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Dompet yang Dipotong:</label>
                        <select name="fin_wallet_name" id="supplier-wallet-select" class="form-input-fin" required>
                            <option value="">— Pilih dompet —</option>
                            <?php foreach ( $active_wallets as $wn => $bal ):
                                $wn_rate = isset($wallet_avg_rates[$wn]) ? $wallet_avg_rates[$wn] : 0;
                            ?>
                                <option value="<?php echo esc_attr($wn); ?>" data-rate="<?php echo esc_attr($wn_rate); ?>"><?php echo esc_html($wn); ?> (Saldo: <?php echo esc_html($bal); ?>)</option>
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
                        <div id="supplier-idr-preview" style="padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; background: #f8fafc; font-weight: 700; color: var(--text-dark);">Rp 0</div>
                        <small style="color: var(--text-muted); font-size: 11px;">Dihitung otomatis berdasarkan rata-rata rate Top-Up dompet ini, sehingga nilainya tetap konsisten dengan cost basis yang sebenarnya.</small>
                    </div>
                    <button type="submit" name="action_save_finance" class="btn-submit-action btn-green">💾 Catat Belanja Supplier</button>
                </form>
            </div>
        </div>

        <div class="form-box">
            <div>
                <div class="form-box-title">📦 3. Biaya Kargo &amp; Operasional</div>
                <form method="POST" action="">
                    <?php wp_nonce_field('goweb_finance_action_' . $store_id, 'goweb_finance_nonce'); ?>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Kategori Pengeluaran:</label>
                        <select name="fin_type" class="form-select-fin" required>
                            <option value="cargo_1">✈️ Ongkir Tahap 1 (Lokal WH)</option>
                            <option value="cargo_2">🚢 Ongkir Tahap 2 (Forwarder Indo)</option>
                            <option value="operational">📦 Operasional / Bubble Wrap</option>
                        </select>
                    </div>
                    <div class="form-group-fin"><label class="form-label-fin">Tanggal Transaksi:</label><input type="date" name="fin_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Keterangan Tagihan:</label><input type="text" name="fin_title" class="form-input-fin" placeholder="Misal: Invoice Kargo Laut" required></div>
                    <div class="form-group-fin"><label class="form-label-fin">Total Nominal (IDR):</label><input type="number" name="fin_idr" class="form-input-fin" placeholder="Misal: 420000" required></div>
                    <input type="hidden" name="fin_wallet_name" value="-">
                    <input type="hidden" name="fin_amount" value="0">
                    <button type="submit" name="action_save_finance" class="btn-submit-action btn-amber">💾 Catat Tagihan Biaya</button>
                </form>
            </div>
        </div>
    </div>

    <div class="ledger-card">
        <div class="ledger-head">
            <div class="filter-pills">
                <button class="filter-btn active" onclick="filterLedger('ALL', this)">Semua Mutasi (<span id="count-all">0</span>)</button>
                <button class="filter-btn" onclick="filterLedger('wallet_topup', this)">Top-Up Saldo</button>
                <button class="filter-btn" onclick="filterLedger('supplier_purchase', this)">Belanja Supplier</button>
                <button class="filter-btn" onclick="filterLedger('cargo', this)">Tagihan Kargo (1+2)</button>
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
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg(array('del_fin' => $tx['id'], 'goweb_tenant_id' => $store_id)), 'goweb_finance_delete_' . $store_id ) ); ?>" onclick="openDeleteFinanceModal(event, this.href); return false;" class="btn-del-fin" title="Hapus Mutasi">✕</a>
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
            <span id="fin-page-info" style="font-size: 12.5px; color: var(--slate-500, #64748b); font-weight: 600;"></span>
            <div style="display: flex; gap: 6px; align-items: center;">
                <button type="button" id="fin-prev" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft, #e2e8f0); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">‹ Sebelumnya</button>
                <span id="fin-page-numbers" style="display: flex; gap: 4px;"></span>
                <button type="button" id="fin-next" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft, #e2e8f0); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">Berikutnya ›</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI HAPUS 1 MUTASI -->
<div class="modal-overlay" id="deleteFinanceModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; padding: 20px;">
    <div style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"><i class="fa-solid fa-trash-can"></i></div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Hapus Mutasi Ini?</h3>
        <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 24px;">Mutasi ini akan dihapus permanen dan saldo dompet terkait akan dikoreksi balik otomatis.</p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="cancel-delete-finance" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; font-weight: 700; color: #64748b; cursor: pointer;">Batal</button>
            <a id="confirm-delete-finance" href="#" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">Ya, Hapus</a>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI RESET SEMUA DATA KEUANGAN -->
<div class="modal-overlay" id="resetFinanceModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; padding: 20px;">
    <div style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 420px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Reset SELURUH Data Keuangan?</h3>
        <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 24px;">Semua mutasi &amp; saldo dompet tenant ini akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.</p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="cancel-reset-finance" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; font-weight: 700; color: #64748b; cursor: pointer;">Batal</button>
            <button type="button" id="confirm-reset-finance" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Reset Semua</button>
        </div>
    </div>
</div>

<?php
// DATA FINANSIAL PER BULAN — dipakai buat Rekap Kesehatan Finansial
// Tahunan yang BENERAN ngitung margin (income vs outflow), bukan cuma
// ngecek "ada transaksi apa enggak" kayak versi sebelumnya.
$monthly_financial_data = array();
if ( ! empty($finance_ledger_rows) ) {
    foreach ( $finance_ledger_rows as $tx ) {
        $tx_type = isset($tx['tx_type']) ? $tx['tx_type'] : '';
        if ( ! in_array( $tx_type, array('supplier_purchase', 'cargo_1', 'cargo_2', 'operational'), true ) ) continue;
        $key = date('Y-n', strtotime($tx['tx_date']));
        if ( ! isset($monthly_financial_data[$key]) ) $monthly_financial_data[$key] = array('income' => 0, 'outflow' => 0);
        $monthly_financial_data[$key]['outflow'] += floatval($tx['idr_value']);
    }
}
if ( ! empty($subscription_history) && is_array($subscription_history) ) {
    foreach ( $subscription_history as $h ) {
        if ( ($h['status'] ?? '') === 'Verified' && ($h['payment_category'] ?? '') === 'po' ) {
            $key = date('Y-n', strtotime($h['created_at']));
            if ( ! isset($monthly_financial_data[$key]) ) $monthly_financial_data[$key] = array('income' => 0, 'outflow' => 0);
            $monthly_financial_data[$key]['income'] += floatval($h['nominal']);
        }
    }
}
?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Data mentah income/outflow per bulan (kunci "YYYY-N", N tanpa nol di depan)
    const MONTHLY_FIN_DATA = <?php echo wp_json_encode( $monthly_financial_data ); ?>;

    // Live preview estimasi Total Rupiah di form Belanja Supplier — dihitung
    // dari rate rata-rata top-up dompet yang dipilih (data-rate di tiap opsi).
    const supplierWalletSelect = document.getElementById('supplier-wallet-select');
    const supplierAmountInput = document.getElementById('supplier-amount-input');
    const supplierIdrPreview = document.getElementById('supplier-idr-preview');

    function updateSupplierPreview() {
        if (!supplierWalletSelect || !supplierAmountInput || !supplierIdrPreview) return;
        const selectedOption = supplierWalletSelect.options[supplierWalletSelect.selectedIndex];
        const rate = selectedOption ? parseFloat(selectedOption.getAttribute('data-rate')) || 0 : 0;
        const amount = parseFloat(supplierAmountInput.value) || 0;
        const estimatedIdr = Math.round(rate * amount);
        supplierIdrPreview.textContent = 'Rp ' + estimatedIdr.toLocaleString('id-ID');
        if (rate === 0 && selectedOption && selectedOption.value !== '') {
            supplierIdrPreview.textContent += ' (dompet ini belum pernah di-Top Up)';
            supplierIdrPreview.style.color = '#ef4444';
        } else {
            supplierIdrPreview.style.color = '';
        }
    }
    if (supplierWalletSelect) supplierWalletSelect.addEventListener('change', updateSupplierPreview);
    if (supplierAmountInput) supplierAmountInput.addEventListener('input', updateSupplierPreview);

    const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    let selectedYear = '<?php echo $current_real_year; ?>';
    let selectedMonth = parseInt('<?php echo $current_real_month; ?>');

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
        renderRoadmap();
        finCurrentPage = 1; // reset ke halaman 1 tiap ganti siklus bulan/tahun
        applyLedgerFilterAndPaginate();
    }

    function renderRoadmap() {
        const container = document.getElementById('roadmap-container');
        const titleText = document.getElementById('roadmap-title-text');
        if(!container) return;
        container.innerHTML = '';
        if(titleText) titleText.innerText = `🗓️ Rekap Kesehatan Finansial Tahunan (${selectedYear})`;

        for (let m = 1; m <= 12; m++) {
            let icon = '⚪', label = 'Belum Ada', labelColor = '#64748b', marginStr = '—';
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
                    icon = '🔴'; label = 'Defisit'; labelColor = '#dc2626';
                } else if (marginPct < 15) {
                    icon = '🟡'; label = 'Tipis'; labelColor = '#ca8a04';
                } else if (marginPct < 25) {
                    icon = '🔵'; label = 'Aman'; labelColor = '#2563eb';
                } else {
                    icon = '🟢'; label = 'Prima'; labelColor = '#15803d';
                }
            }

            const div = document.createElement('div');
            div.className = `roadmap-item ${m === selectedMonth ? 'active' : ''}`;
            div.onclick = () => { 
                if(monthSelectEl) monthSelectEl.value = m; 
                window.changeMonthCycle(m); 
            };
            div.innerHTML = `
                <div class="roadmap-month">${MONTH_NAMES[m - 1].substring(0, 3)}</div>
                <div class="roadmap-icon">${icon}</div>
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
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
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
    const FIN_PER_PAGE = 25;
    let finCurrentPage = 1;
    let currentLedgerFilterType = 'ALL';

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
                btn.style.cssText = `padding: 7px 12px; border-radius: 8px; border: 1.5px solid ${isActive ? 'var(--pastel-purple, #8b5cf6)' : 'var(--border-soft, #e2e8f0)'}; background: ${isActive ? 'var(--pastel-purple, #8b5cf6)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;`;
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