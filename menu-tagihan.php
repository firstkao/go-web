<?php
if (!defined('ABSPATH')) exit;
if (!$has_tagihan) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup!</div>';
    return;
}
// Daftar Jenis Tagihan — sumber SAMA persis kayak "Jenis Pembayaran" di
// page-payment.php, dikelola admin di Pengaturan.
$payment_category_options = goweb_get_tenant_setting( $store_id, 'payment_categories', goweb_get_default_payment_categories() );
?>
<div class="fin-wrap">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark, #0f172a); margin-bottom: 6px;">Rekapan Tagihan</h2>
        <p style="color: var(--text-muted, #64748b); font-size: 0.875rem;">Kelola tagihan per PO/order — DP, Ongkir, Pajak, Packing, Pelunasan, dll — beserta status pembayarannya (manual, gak ke-link otomatis ke Konfirmasi Pembayaran).</p>
    </div>

    <!-- DIAGNOSA KESEHATAN FINANSIAL — dipindah dari Rekapan PO, sekarang
         dihitung dari status tagihan beneran (Lunas = 100% kas masuk,
         Belum Bayar = 0%), bukan dari input manual di Rekapan PO lagi. -->
    <div style="background: #ffffff; border: 1px solid var(--border-soft, #e2e8f0); border-radius: 22px; padding: 24px 26px; margin-bottom: 20px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-soft, #e2e8f0);">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span style="font-size: 13px; font-weight: 800; color: var(--slate-700, #334155);">📅 Filter Siklus Diagnosa:</span>
                <select id="diag-select-year" class="year-select" onchange="changeDiagCycle()" style="padding: 6px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-weight: 700; font-size: 12.5px; background: #fff; outline: none;">
                    <?php
                    // Tahun digenerate dinamis (2 tahun ke belakang s/d 2 tahun ke
                    // depan dari tahun sekarang) — sebelumnya hardcode 2026-2028,
                    // bikin data di tahun lain (mis. 2025) gak PERNAH muncul di
                    // tabel karena filter siklusnya gak bisa dipilih.
                    foreach ( range( intval( date('Y') ) - 2, intval( date('Y') ) + 2 ) as $diag_year_opt ):
                        $diag_year_opt = (string) $diag_year_opt; ?>
                        <option value="<?php echo esc_attr($diag_year_opt); ?>" <?php selected($diag_year_opt, date('Y')); ?>><?php echo esc_html($diag_year_opt); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="diag-select-month" class="cycle-select" onchange="changeDiagCycle()" style="padding: 6px 12px; border: 1.5px solid var(--border-soft, #e2e8f0); border-radius: 8px; font-weight: 700; font-size: 12.5px; background: #fff; outline: none;">
                    <option value="1">Januari</option>
                    <option value="2">Februari</option>
                    <option value="3">Maret</option>
                    <option value="4">April</option>
                    <option value="5">Mei</option>
                    <option value="6">Juni</option>
                    <option value="7">Juli</option>
                    <option value="8">Agustus</option>
                    <option value="9">September</option>
                    <option value="10">Oktober</option>
                    <option value="11">November</option>
                    <option value="12">Desember</option>
                </select>
            </div>
            <div id="diag-range-label" style="font-size: 12px; font-weight: 700; color: var(--text-muted, #64748b); background: var(--slate-50, #f8fafc); padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border-soft, #e2e8f0);">
                Rentang: Memuat...
            </div>
        </div>

        <div class="po-diag-grid">
            <div>
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                    <div style="width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">📊</div>
                    <div>
                        <h3 style="font-size: 17px; font-weight: 800; color: var(--text-dark, #0f172a); margin: 0;">Diagnosa Kesehatan Finansial Tagihan</h3>
                        <p id="health-advice" style="font-size: 13px; color: var(--text-muted, #64748b); margin: 2px 0 0;">Menganalisa perputaran kas masuk &amp; piutang tagihan...</p>
                    </div>
                </div>
                <div style="margin-top: 14px;">
                    <span id="health-badge" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;">● Status: Belum Ada Transaksi</span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; background: var(--slate-50, #f8fafc); border: 1px solid var(--border-soft, #e2e8f0); border-radius: 14px; padding: 12px 16px;">
                <div>
                    <div style="font-size: 11px; color: var(--text-muted, #64748b); font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Cash In</div>
                    <div id="metric-cash-in" style="font-size: 15px; font-weight: 800; color: #15803d; margin-top: 2px;">Rp 0</div>
                </div>
                <div>
                    <div style="font-size: 11px; color: var(--text-muted, #64748b); font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Piutang</div>
                    <div id="metric-piutang" style="font-size: 15px; font-weight: 800; color: #d97706; margin-top: 2px;">Rp 0</div>
                </div>
                <div>
                    <div style="font-size: 11px; color: var(--text-muted, #64748b); font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Collection</div>
                    <div id="metric-col-rate" style="font-size: 15px; font-weight: 800; color: var(--text-dark, #0f172a); margin-top: 2px;">0%</div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .po-diag-grid { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 24px; align-items: center; }
        @media (max-width: 800px) { .po-diag-grid { grid-template-columns: 1fr; } }
    </style>

    <div class="metrics-grid" style="margin-bottom: 24px;">
        <div class="metric-card blue">
            <div class="metric-label">Total Baris Tagihan</div>
            <div class="metric-val" id="stat-total-items" style="font-size: 22px;">0</div>
            <div class="metric-sub">Berdasarkan siklus bulan aktif</div>
        </div>
        <div class="metric-card green">
            <div class="metric-label">Total Nilai Tagihan</div>
            <div class="metric-val" id="stat-total-omset" style="font-size: 22px; color: #15803d;">Rp 0</div>
            <div class="metric-sub">Akumulasi semua kategori tagihan</div>
        </div>
        <div class="metric-card highlight">
            <div class="metric-label">Tagihan Lunas</div>
            <div class="metric-val" id="stat-done" style="font-size: 22px;">0</div>
            <div class="metric-sub">Sudah dibayar penuh</div>
        </div>
    </div>

    <!-- FORM TAMBAH TAGIHAN BARU -->
    <div class="form-box" style="margin-bottom: 24px;">
        <div class="form-box-title">🧾 Tambah Tagihan Baru</div>
        <form method="POST" action="<?php echo esc_url( home_url('/dashboard/tagihan/') . $store_query_suffix ); ?>">
            <?php wp_nonce_field('goweb_invoice_action_' . $store_id, 'goweb_invoice_nonce'); ?>
            <input type="hidden" name="action_add_invoice" value="1">

            <div class="form-group-fin" style="margin-bottom: 14px; position: relative;">
                <label class="form-label-fin">Pilih PO / Order:</label>
                <input type="text" id="inv_order_search" class="form-input-fin" placeholder="Ketik nama PO/Invoice atau nama customer..." autocomplete="off" <?php echo empty($tenant_orders_rows) ? 'disabled' : ''; ?>>
                <input type="hidden" name="inv_order_id" id="inv_order_id_hidden" required>
                <div id="inv_order_suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1.5px solid var(--border-soft, #e2e8f0); border-radius:10px; margin-top:4px; max-height:220px; overflow-y:auto; box-shadow:0 8px 20px -4px rgba(15,23,42,0.12);"></div>
                <?php if ( empty($tenant_orders_rows) ): ?>
                    <small style="color: var(--text-muted, #64748b); font-size: 11.5px;">Belum ada PO/order tercatat di Rekapan PO. Bikin order dulu di sana.</small>
                <?php endif; ?>
            </div>

            <div class="form-group-fin" style="margin-bottom: 14px;">
                <label class="form-label-fin">Jenis Tagihan:</label>
                <select name="inv_category" class="form-select-fin" required>
                    <?php foreach ( $payment_category_options as $cat ): ?>
                        <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group-fin" style="margin-bottom: 16px;">
                <label class="form-label-fin">Nominal (IDR):</label>
                <input type="number" name="inv_nominal" class="form-input-fin" placeholder="Misal: 150000" min="0" required>
            </div>

            <button type="submit" class="btn-submit-action btn-blue">💾 Simpan Tagihan (Status: Belum Bayar)</button>
        </form>
    </div>

    <script>
    (function() {
        // Data order buat autocomplete "Pilih PO/Order" — search-typing,
        // bukan dropdown biasa, biar gak annoying kalau order-nya udah banyak.
        const GOWEB_ORDERS_LIST = <?php echo wp_json_encode( array_map( function( $ord ) {
            return array( 'id' => intval( $ord['id'] ), 'label' => $ord['batch'] . ' — ' . $ord['buyer_name'] );
        }, $tenant_orders_rows ) ); ?>;

        // Proteksi XSS: label (nama PO + nama customer) diketik tenant, tapi
        // halaman ini bisa dibuka Super Admin via ?store= — jadi jangan
        // masukkan mentah ke innerHTML. Escape sebelum dirender.
        function escHtml(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }

        const searchInput = document.getElementById('inv_order_search');
        const hiddenInput = document.getElementById('inv_order_id_hidden');
        const suggestionsBox = document.getElementById('inv_order_suggestions');
        if (!searchInput || !hiddenInput || !suggestionsBox) return;

        function renderSuggestions(list) {
            if (list.length === 0) {
                suggestionsBox.innerHTML = '<div style="padding:12px 14px; color:#94a3b8; font-size:13px;">Gak ada PO/order yang cocok.</div>';
            } else {
                suggestionsBox.innerHTML = list.map(o =>
                    `<div class="inv-suggestion-item" data-id="${o.id}" data-label="${escHtml(o.label)}" style="padding:10px 14px; cursor:pointer; font-size:13.5px; font-weight:600; border-bottom:1px solid #f1f5f9;">${escHtml(o.label)}</div>`
                ).join('');
            }
            suggestionsBox.style.display = 'block';
        }

        searchInput.addEventListener('focus', () => {
            // Kosongin ID pas fokus lagi ke input — cegah nyubmit ID lama
            // kalau user ngubah teksnya tanpa milih ulang dari daftar.
            const q = searchInput.value.trim().toLowerCase();
            const filtered = q === '' ? GOWEB_ORDERS_LIST : GOWEB_ORDERS_LIST.filter(o => o.label.toLowerCase().includes(q));
            renderSuggestions(filtered);
        });

        searchInput.addEventListener('input', () => {
            hiddenInput.value = ''; // teks berubah = pilihan lama gak valid lagi
            const q = searchInput.value.trim().toLowerCase();
            const filtered = q === '' ? GOWEB_ORDERS_LIST : GOWEB_ORDERS_LIST.filter(o => o.label.toLowerCase().includes(q));
            renderSuggestions(filtered);
        });

        suggestionsBox.addEventListener('click', (e) => {
            const item = e.target.closest('.inv-suggestion-item');
            if (!item) return;
            hiddenInput.value = item.getAttribute('data-id');
            searchInput.value = item.getAttribute('data-label');
            suggestionsBox.style.display = 'none';
        });

        document.addEventListener('click', (e) => {
            if (e.target !== searchInput && !suggestionsBox.contains(e.target)) {
                suggestionsBox.style.display = 'none';
            }
        });

        // Cegah submit kalau belum beneran milih dari daftar (hidden ID kosong)
        const invoiceForm = searchInput.closest('form');
        if (invoiceForm) {
            invoiceForm.addEventListener('submit', (e) => {
                if (!hiddenInput.value) {
                    e.preventDefault();
                    searchInput.style.borderColor = '#ef4444';
                    searchInput.placeholder = 'Pilih PO dari daftar dulu (klik salah satu opsi)';
                }
            });
        }
    })();
    </script>

    <!-- TABEL DAFTAR TAGIHAN -->
    <div class="ledger-card">
        <div class="ledger-head" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <h3 style="font-size: 14.5px; font-weight: 800; color: var(--slate-900, #0f172a);">Daftar Tagihan</h3>
            <button type="button" onclick="downloadTagihanCSV()" style="background: #15803d; color: #fff; border: none; padding: 8px 16px; border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 12.5px;">📥 Download CSV</button>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>PO / Customer</th>
                        <th>Jenis Tagihan</th>
                        <th>Nominal</th>
                        <th>Status</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tagihan-rows">
                    <?php if ( !empty($tenant_invoices_rows) ): foreach ( $tenant_invoices_rows as $inv ): ?>
                        <tr class="invoice-row-item">
                            <td style="color: var(--slate-500); font-size: 12.5px;"><?php echo esc_html( date('d/m/Y', strtotime($inv['created_at'])) ); ?></td>
                            <td>
                                <strong><?php echo esc_html($inv['batch'] ?: '(order terhapus)'); ?></strong><br>
                                <span style="font-size: 12px; color: var(--text-muted, #64748b);"><?php echo esc_html($inv['buyer_name'] ?? ''); ?></span>
                            </td>
                            <td><?php echo esc_html( goweb_invoice_category_label($inv['category']) ); ?></td>
                            <td style="font-weight: 700;">Rp <?php echo number_format(intval($inv['nominal']), 0, ',', '.'); ?></td>
                            <td>
                                <?php if ($inv['status'] === 'Lunas'): ?>
                                    <span class="status-badge badge-mint">✓ Lunas</span>
                                <?php else: ?>
                                    <span class="status-badge badge-peach">✕ Belum Bayar</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; gap: 6px; justify-content: center;">
                                    <form method="POST" action="<?php echo esc_url( home_url('/dashboard/tagihan/') . $store_query_suffix ); ?>" style="display:inline;">
                                        <?php wp_nonce_field('goweb_invoice_action_' . $store_id, 'goweb_invoice_nonce'); ?>
                                        <input type="hidden" name="invoice_id" value="<?php echo esc_attr($inv['id']); ?>">
                                        <button type="submit" name="action_toggle_invoice_status" style="padding: 6px 12px; font-size: 0.75rem; background:<?php echo $inv['status'] === 'Lunas' ? '#fef3c7' : '#dcfce7'; ?>; color:<?php echo $inv['status'] === 'Lunas' ? '#b45309' : '#15803d'; ?>; border:none; border-radius:6px; cursor:pointer; font-weight:700;">
                                            <?php echo $inv['status'] === 'Lunas' ? 'Tandai Belum Bayar' : 'Tandai Lunas'; ?>
                                        </button>
                                    </form>
                                    <button type="button" onclick="goweb_confirmDeleteInvoice(<?php echo esc_attr($inv['id']); ?>)" style="background: #fee2e2; color: #ef4444; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer;">✕</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--slate-400); padding: 30px;">Belum ada tagihan tercatat.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- KONTROL PAGINATION -->
        <div id="tagihan-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 4px 2px; flex-wrap: wrap; gap: 10px;">
            <span id="tagihan-page-info" style="font-size: 12.5px; color: var(--slate-500, #64748b); font-weight: 600;"></span>
            <div style="display: flex; gap: 6px; align-items: center;">
                <button type="button" id="tagihan-prev" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft, #e2e8f0); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">‹ Sebelumnya</button>
                <span id="tagihan-page-numbers" style="display: flex; gap: 4px;"></span>
                <button type="button" id="tagihan-next" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft, #e2e8f0); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">Berikutnya ›</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI HAPUS TAGIHAN -->
<div class="modal-overlay" id="deleteInvoiceModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;">
    <div style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"><i class="fa-solid fa-trash-can"></i></div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Hapus Tagihan Ini?</h3>
        <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 24px;">Data tagihan ini akan dihapus permanen.</p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="cancel-delete-invoice" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; font-weight: 700; color: #64748b; cursor: pointer;">Batal</button>
            <form method="POST" action="<?php echo esc_url( home_url('/dashboard/tagihan/') . $store_query_suffix ); ?>" style="flex: 1;">
                <?php wp_nonce_field('goweb_invoice_action_' . $store_id, 'goweb_invoice_nonce'); ?>
                <input type="hidden" name="invoice_id" id="delete-invoice-id" value="">
                <button type="submit" name="action_delete_invoice" style="width: 100%; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Hapus</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    // Data tagihan dari database, dipetakan buat perhitungan diagnosa siklus bulan
    let invoices = <?php echo wp_json_encode( array_map( function( $inv ) {
        return array(
            'date'   => date( 'Y-m-d', strtotime( $inv['created_at'] ) ),
            'nominal'=> $inv['nominal'],
            'status' => $inv['status'],
        );
    }, $tenant_invoices_rows ) ); ?>;

    const yearEl = document.getElementById('diag-select-year');
    const monthEl = document.getElementById('diag-select-month');
    const nowY = new Date().getFullYear().toString();
    const nowM = new Date().getMonth() + 1;
    if (yearEl) yearEl.value = nowY;
    if (monthEl) monthEl.value = nowM;

    window.changeDiagCycle = function() {
        const yearVal = document.getElementById('diag-select-year').value;
        const monthVal = parseInt(document.getElementById('diag-select-month').value);
        const mName = MONTH_NAMES[monthVal - 1];
        const lastDay = new Date(parseInt(yearVal), monthVal, 0).getDate();

        document.getElementById('diag-range-label').innerText = `Rentang: 01 ${mName.substring(0,3)} ${yearVal} s/d ${lastDay} ${mName.substring(0,3)} ${yearVal}`;

        const filtered = invoices.filter(inv => {
            if (!inv.date) return false;
            const d = new Date(inv.date);
            return d.getFullYear().toString() === yearVal && (d.getMonth() + 1) === monthVal;
        });

        const totalCount = filtered.length;
        const totalNilai = filtered.reduce((sum, inv) => sum + Number(inv.nominal), 0);
        const doneCount = filtered.filter(inv => inv.status === 'Lunas').length;

        let cashIn = 0, piutang = 0;
        filtered.forEach(inv => {
            const nom = Number(inv.nominal);
            if (inv.status === 'Lunas') cashIn += nom; else piutang += nom;
        });

        const collectionRate = totalNilai > 0 ? Math.round((cashIn / totalNilai) * 100) : 0;

        document.getElementById('stat-total-items').innerText = totalCount;
        document.getElementById('stat-total-omset').innerText = 'Rp ' + totalNilai.toLocaleString('id-ID');
        document.getElementById('stat-done').innerText = doneCount;
        document.getElementById('metric-cash-in').innerText = 'Rp ' + Math.round(cashIn).toLocaleString('id-ID');
        document.getElementById('metric-piutang').innerText = 'Rp ' + Math.round(piutang).toLocaleString('id-ID');
        document.getElementById('metric-col-rate').innerText = collectionRate + '%';

        const badgeEl = document.getElementById('health-badge');
        const adviceEl = document.getElementById('health-advice');

        if (totalCount === 0) {
            badgeEl.style.background = '#f1f5f9'; badgeEl.style.color = '#64748b'; badgeEl.style.borderColor = '#cbd5e1';
            badgeEl.innerHTML = '● Status: Belum Ada Transaksi';
            adviceEl.innerText = 'Belum ada tagihan tercatat pada siklus bulan ini.';
        } else if (totalNilai >= 3000000 && collectionRate >= 75) {
            badgeEl.style.background = '#dcfce7'; badgeEl.style.color = '#15803d'; badgeEl.style.borderColor = '#bbf7d0';
            badgeEl.innerHTML = '● Status: Sehat &amp; Collection Bagus';
            adviceEl.innerText = 'Arus kas bulan ini lancar. Sebagian besar tagihan sudah lunas.';
        } else if (collectionRate < 70 && totalNilai > 1000000) {
            badgeEl.style.background = '#fef3c7'; badgeEl.style.color = '#b45309'; badgeEl.style.borderColor = '#fde68a';
            badgeEl.innerHTML = '● Status: Waspada Piutang';
            adviceEl.innerText = `Ada piutang Rp ${Math.round(piutang).toLocaleString('id-ID')} yang belum dibayar bulan ini. Segera follow-up ke customer.`;
        } else {
            badgeEl.style.background = '#e0f2fe'; badgeEl.style.color = '#0369a1'; badgeEl.style.borderColor = '#bae6fd';
            badgeEl.innerHTML = '● Status: Normal / Berjalan';
            adviceEl.innerText = 'Pencatatan tagihan di bulan ini berjalan stabil.';
        }
    }

    changeDiagCycle();

    // Modal konfirmasi hapus tagihan
    window.goweb_confirmDeleteInvoice = function(invoiceId) {
        document.getElementById('delete-invoice-id').value = invoiceId;
        document.getElementById('deleteInvoiceModal').style.display = 'flex';
    }
    const deleteInvModal = document.getElementById('deleteInvoiceModal');
    const cancelDeleteInv = document.getElementById('cancel-delete-invoice');
    if (cancelDeleteInv) cancelDeleteInv.addEventListener('click', () => { deleteInvModal.style.display = 'none'; });
    if (deleteInvModal) deleteInvModal.addEventListener('click', (e) => { if (e.target === deleteInvModal) deleteInvModal.style.display = 'none'; });

    window.downloadTagihanCSV = function() {
        let csvContent = "\uFEFFREKAPAN TAGIHAN\n\n";
        csvContent += "TANGGAL,PO / CUSTOMER,JENIS TAGIHAN,NOMINAL,STATUS\n";
        // Sengaja pakai .invoice-row-item (SEMUA baris), bukan cek
        // display:none — biar ekspor tetap ngambil SELURUH data, bukan
        // cuma halaman yang lagi kebuka.
        const rows = document.querySelectorAll('#tagihan-rows .invoice-row-item');
        rows.forEach(r => {
            const cols = r.querySelectorAll('td');
            if (cols.length >= 5) {
                const clean = (txt) => {
                    let s = txt.replace(/"/g, '""').replace(/\n/g, ' - ').trim();
                    if (/^[=+\-@]/.test(s)) s = "'" + s;
                    return s;
                };
                csvContent += `"${clean(cols[0].innerText)}","${clean(cols[1].innerText)}","${clean(cols[2].innerText)}","${clean(cols[3].innerText)}","${clean(cols[4].innerText)}"\n`;
            }
        });
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", `Rekapan_Tagihan_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // ---- PAGINATION (sisi browser) ----
    const INV_PER_PAGE = 25;
    let invCurrentPage = 1;
    const invRows = Array.from(document.querySelectorAll('#tagihan-rows .invoice-row-item'));
    const invPagination = document.getElementById('tagihan-pagination');
    const invPageInfo = document.getElementById('tagihan-page-info');
    const invPageNumbers = document.getElementById('tagihan-page-numbers');
    const invPrev = document.getElementById('tagihan-prev');
    const invNext = document.getElementById('tagihan-next');

    function renderInvPage() {
        const totalPages = Math.ceil(invRows.length / INV_PER_PAGE) || 1;
        if (invCurrentPage > totalPages) invCurrentPage = totalPages;
        const start = (invCurrentPage - 1) * INV_PER_PAGE;
        const end = start + INV_PER_PAGE;

        invRows.forEach((row, i) => {
            row.style.display = (i >= start && i < end) ? '' : 'none';
        });

        if (invRows.length <= INV_PER_PAGE) {
            if (invPagination) invPagination.style.display = 'none';
            return;
        }
        if (invPagination) invPagination.style.display = 'flex';
        if (invPageInfo) invPageInfo.textContent = `Menampilkan ${start + 1}–${Math.min(end, invRows.length)} dari ${invRows.length} tagihan`;
        if (invPrev) { invPrev.disabled = invCurrentPage === 1; invPrev.style.opacity = invCurrentPage === 1 ? '0.4' : '1'; invPrev.style.cursor = invCurrentPage === 1 ? 'not-allowed' : 'pointer'; }
        if (invNext) { invNext.disabled = invCurrentPage === totalPages; invNext.style.opacity = invCurrentPage === totalPages ? '0.4' : '1'; invNext.style.cursor = invCurrentPage === totalPages ? 'not-allowed' : 'pointer'; }

        if (invPageNumbers) {
            invPageNumbers.innerHTML = '';
            let startPage = Math.max(1, invCurrentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            startPage = Math.max(1, endPage - 4);
            for (let p = startPage; p <= endPage; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = p;
                const isActive = p === invCurrentPage;
                btn.style.cssText = `padding: 7px 12px; border-radius: 8px; border: 1.5px solid ${isActive ? 'var(--pastel-purple, #8b5cf6)' : 'var(--border-soft, #e2e8f0)'}; background: ${isActive ? 'var(--pastel-purple, #8b5cf6)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;`;
                btn.addEventListener('click', () => { invCurrentPage = p; renderInvPage(); });
                invPageNumbers.appendChild(btn);
            }
        }
    }

    if (invPrev) invPrev.addEventListener('click', () => { if (invCurrentPage > 1) { invCurrentPage--; renderInvPage(); } });
    if (invNext) invNext.addEventListener('click', () => { const tp = Math.ceil(invRows.length / INV_PER_PAGE) || 1; if (invCurrentPage < tp) { invCurrentPage++; renderInvPage(); } });
    if (invRows.length > 0) renderInvPage();
});
</script>