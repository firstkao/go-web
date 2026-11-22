<?php
if (!defined('ABSPATH')) exit;
if (!$has_po) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup! Modul Rekapan PO dinonaktifkan.</div>';
    return;
}

$active_custom_statuses = goweb_get_tenant_setting( $store_id, 'custom_statuses', array("Antrean", "On Process", "Arrived WH", "Shipped Indo", "Done") );
$active_rates = goweb_get_tenant_setting( $store_id, 'rates', array('CNY' => 2450, 'JPY' => 110, 'THB' => 460, 'KRW' => 12, 'USD' => 16200) );
?>
<div class="fin-wrap">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Rekapan Order PO</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Manajemen status antrean kargo per PO/Invoice. Buat pencatatan tagihan &amp; status pembayaran, cek menu Rekapan Tagihan.</p>
    </div>

    <!-- FILTER SIKLUS BULAN (dipakai buat filter statistik di bawah) -->
    <div style="background: #ffffff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 18px 24px; margin-bottom: 20px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span style="font-size: 13px; font-weight: 800; color: var(--slate-700);">📅 Filter Siklus:</span>
            <select id="diag-select-year" class="year-select" onchange="changeDiagCycle()" style="padding: 6px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-weight: 700; font-size: 12.5px; background: #fff; outline: none;">
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
            <select id="diag-select-month" class="cycle-select" onchange="changeDiagCycle()" style="padding: 6px 12px; border: 1.5px solid var(--border-soft); border-radius: 8px; font-weight: 700; font-size: 12.5px; background: #fff; outline: none;">
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
        <div id="diag-range-label" style="font-size: 12px; font-weight: 700; color: var(--text-muted); background: var(--slate-50); padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border-soft);">
            Rentang: Memuat...
        </div>
    </div>

    <!-- STATS OVERVIEW CARDS -->
    <style>
        .po-diag-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 24px;
            align-items: center;
        }
        @media (max-width: 800px) {
            .po-diag-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .po-search-input { width: 100% !important; }
        }
    </style>
    <div class="metrics-grid" style="margin-bottom: 24px;">
        <div class="metric-card blue">
            <div class="metric-label">Total Item Order</div>
            <div class="metric-val" id="stat-total-items" style="font-size: 22px;">0 Pcs</div>
            <div class="metric-sub">Berdasarkan siklus bulan aktif</div>
        </div>
        <div class="metric-card amber">
            <div class="metric-label">Sedang di WH / Kargo</div>
            <div class="metric-val" id="stat-arrived" style="font-size: 22px; color: #d97706;">0 Pcs</div>
            <div class="metric-sub">Dalam perjalanan logistik</div>
        </div>
        <div class="metric-card highlight">
            <div class="metric-label">Pesanan Selesai (Done)</div>
            <div class="metric-val" id="stat-done" style="font-size: 22px;">0 Pcs</div>
            <div class="metric-sub">Siap / sudah dikirim lokal</div>
        </div>
    </div>

    <!-- MAIN TABLE CARD -->
    <div class="ledger-card">
        <div class="ledger-head" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
            <div class="filter-pills" id="filter-container" style="display: flex; gap: 6px; flex-wrap: wrap;">
                <button class="filter-btn active" onclick="filterOrders('ALL', this)">Semua (<span id="count-all">0</span>)</button>
                <?php foreach($active_custom_statuses as $st_filter): ?>
                    <button class="filter-btn" onclick="filterOrders('<?php echo esc_attr($st_filter); ?>', this)"><?php echo esc_html($st_filter); ?></button>
                <?php endforeach; ?>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <!-- KOLOM SEARCH BAR -->
                <input type="text" id="order-search" class="form-input-fin po-search-input" placeholder="🔍 Cari PO, Invoice, Customer..." oninput="handleSearchInput(this.value)" style="padding: 8px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-size: 13px; font-weight: 600; width: 240px;">
                
                <button type="button" class="btn-export-csv" onclick="downloadOrdersCSV()" style="background: #15803d; color: #fff; border: none; padding: 9px 18px; border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 13px;">📥 Download CSV</button>
                <button type="button" class="btn-export-csv" onclick="openOrderModal()" style="background: var(--primary); color: #fff; border: none; padding: 9px 18px; border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 13px;">+ Tambah Order Baru</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table" style="table-layout: fixed; width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 110px;">Bulan</th>
                        <th style="width: 150px;">Nama PO / ID Invoice</th>
                        <th style="width: 140px;">Nama Customer</th>
                        <th style="width: 300px;">Detail Item</th>
                        <th style="width: 150px;">Status Order</th>
                        <th style="width: 130px;">Status Bayar</th>
                        <th style="width: 60px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="order-rows">
                    <!-- DIRENDER DYNAMIC VIA JS -->
                </tbody>
            </table>
        </div>

        <!-- KONTROL PAGINATION -->
        <div id="po-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 4px 2px; flex-wrap: wrap; gap: 10px;">
            <span id="po-page-info" style="font-size: 12.5px; color: var(--slate-500); font-weight: 600;"></span>
            <div style="display: flex; gap: 6px; align-items: center;">
                <button type="button" id="po-prev" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">‹ Sebelumnya</button>
                <span id="po-page-numbers" style="display: flex; gap: 4px;"></span>
                <button type="button" id="po-next" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">Berikutnya ›</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL FORM INPUT ORDER -->
<div class="modal-overlay" id="orderModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 999; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; padding: 20px;">
    <div class="modal-box" style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 480px; padding: 30px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border-soft);">
            <div style="font-size: 18px; font-weight: 800; color: var(--text-dark);">Tambah Data Order PO Baru</div>
            <button type="button" onclick="closeOrderModal()" style="background: none; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer; font-weight: 700;">&times;</button>
        </div>
        
        <form method="POST" action="<?php echo esc_url( home_url('/dashboard/po/') . $store_query_suffix ); ?>">
            <?php wp_nonce_field('goweb_po_action_' . $store_id, 'goweb_po_nonce'); ?>
            <input type="hidden" name="action_add_order" value="1">

            <div class="form-group-fin" style="margin-bottom: 14px;">
                <label class="form-label-fin" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Tanggal Transaksi (Auto Deteksi Bulan):</label>
                <input type="date" id="in-date" name="po_date" class="form-input-fin" value="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
            </div>

            <div class="form-group-fin" style="margin-bottom: 14px;">
                <label class="form-label-fin" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Nama PO / ID Invoice:</label>
                <input type="text" id="in-batch" name="po_batch" class="form-input-fin" placeholder="Misal: PO Genshin Standee #14 atau INV-9821" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
            </div>

            <div class="form-group-fin" style="margin-bottom: 14px;">
                <label class="form-label-fin" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Nama Customer:</label>
                <input type="text" id="in-name" name="po_buyer_name" class="form-input-fin" placeholder="Misal: Adriana S." style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
            </div>

            <div class="form-group-fin" style="margin-bottom: 14px;">
                <label class="form-label-fin" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Detail Item Pesanan:</label>
                <input type="text" id="in-item" name="po_item_detail" class="form-input-fin" placeholder="Misal: Genshin Acrylic Standee x2" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" required>
            </div>

            <div class="form-group-fin" style="margin-bottom: 20px;">
                <label class="form-label-fin" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Total Nilai PO (Rp, opsional):</label>
                <input type="number" id="in-total" name="po_total_value" class="form-input-fin" placeholder="Misal: 850000 — kosongkan kalau belum fix" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;" min="0">
            </div>

            <div class="form-group-fin" style="margin-bottom: 20px;">
                <label class="form-label-fin" style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Status Proses Barang:</label>
                <select id="in-status" name="po_status" class="form-select-fin" style="width: 100%; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: 10px; font-weight: 600;">
                    <?php foreach($active_custom_statuses as $st_opt): ?>
                        <option value="<?php echo esc_attr($st_opt); ?>"><?php echo esc_html($st_opt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="closeOrderModal()" style="flex: 1; background: var(--slate-100); color: var(--slate-700); font-weight: 700; padding: 12px; border-radius: 10px; border: none; cursor: pointer;">Batal</button>
                <button type="submit" style="flex: 1; background: var(--primary); color: #fff; font-weight: 800; padding: 12px; border-radius: 10px; border: none; cursor: pointer;">Simpan Order</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL KONFIRMASI HAPUS ORDER -->
<div class="modal-overlay" id="deleteOrderModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; padding: 20px;">
    <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"><i class="fa-solid fa-trash-can"></i></div>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--slate-900); margin-bottom: 8px;">Hapus Order Ini?</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px;">Tagihan yang terikat ke PO ini ikut terhapus, dan item Warehouse-nya dilepas dari PO (tidak dihapus). Tindakan ini tidak bisa dibatalkan.</p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="cancel-delete-order" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; color: var(--text-muted); cursor: pointer;">Batal</button>
            <button type="button" id="confirm-delete-order" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Hapus</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dbStatuses = <?php echo json_encode($active_custom_statuses); ?>;
    const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    // DATA ASLI DARI DATABASE (tabel wp_goweb_orders) — sebelumnya array
    // kosong yang cuma hidup di memori browser, ilang tiap reload.
    let orders = <?php echo wp_json_encode( array_map( function( $o ) {
        return array(
            'uid'    => intval( $o['id'] ),
            'date'   => date( 'Y-m-d', strtotime( $o['created_at'] ) ),
            'batch'  => $o['batch'],
            'name'   => $o['buyer_name'],
            'item'   => $o['item_detail'],
            'status' => $o['order_status'],
            'total'  => floatval( $o['total_value'] ),
            // Status bayar diturunkan dari tagihan PO ini (#33) - kolom
            // payment_status dulu mati, kini ikut ditampilkan di tabel.
            'pay'    => isset( $o['payment_status'] ) && $o['payment_status'] !== '' ? $o['payment_status'] : 'Belum Lunas',
        );
    }, $tenant_orders_rows ) ); ?>;

    const GOWEB_PO_NONCE = <?php echo wp_json_encode( wp_create_nonce('goweb_po_action_' . $store_id) ); ?>;
    const GOWEB_PO_ACTION_URL = <?php echo wp_json_encode( home_url('/dashboard/po/') . $store_query_suffix ); ?>;

    // Proteksi XSS: data order (nama PO, customer, detail item, status) itu
    // diketik tenant, tapi halaman ini juga bisa dibuka SUPER ADMIN yang
    // sedang intip dashboard tenant lewat ?store= — jadi teks tenant gak
    // boleh masuk ke innerHTML mentah. Escape semua interpolasi sebelum
    // dirender.
    function escHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function submitPoAction( fields ) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = GOWEB_PO_ACTION_URL;
        form.style.display = 'none';

        const baseFields = Object.assign( { goweb_po_nonce: GOWEB_PO_NONCE }, fields );
        Object.keys( baseFields ).forEach( key => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = baseFields[key];
            form.appendChild( input );
        } );

        document.body.appendChild( form );
        form.submit();
    }

    let currentFilter = 'ALL';
    let searchQuery = '';
    const PO_PER_PAGE = 25;
    let poCurrentPage = 1;

    // Auto-set dropdown diagnosa ke bulan/tahun aktif
    const activeYear = typeof selectedYear !== 'undefined' ? selectedYear : new Date().getFullYear().toString();
    const activeMonth = typeof selectedMonth !== 'undefined' ? parseInt(selectedMonth) : new Date().getMonth() + 1;

    const yearEl = document.getElementById('diag-select-year');
    const monthEl = document.getElementById('diag-select-month');
    if(yearEl) yearEl.value = activeYear;
    if(monthEl) monthEl.value = activeMonth;

    function getFormattedMonth(dateStr) {
        if(!dateStr) return '-';
        const d = new Date(dateStr);
        if(isNaN(d)) return '-';
        return MONTH_NAMES[d.getMonth()] + ' ' + d.getFullYear();
    }

    window.changeDiagCycle = function() {
        const yearVal = document.getElementById('diag-select-year').value;
        const monthVal = parseInt(document.getElementById('diag-select-month').value);
        const mName = MONTH_NAMES[monthVal - 1];
        const lastDay = new Date(parseInt(yearVal), monthVal, 0).getDate();
        
        document.getElementById('diag-range-label').innerText = `Rentang: 01 ${mName.substring(0,3)} ${yearVal} s/d ${lastDay} ${mName.substring(0,3)} ${yearVal}`;
        poCurrentPage = 1; // reset ke halaman 1 tiap ganti siklus bulan/tahun
        renderTable();
    }

    window.updateStats = function(filteredByCycle) {
        const totalCount = filteredByCycle.length;
        const doneStatusName = dbStatuses[dbStatuses.length - 1] || "Done";
        const doneCount = filteredByCycle.filter(o => o.status === doneStatusName).length;

        // "Sedang di WH / Kargo" dikenali dari KATA KUNCI di nama status-nya
        // sendiri (bukan nebak posisi urutan) — soalnya status itu bebas
        // diatur tenant sendiri, bisa beda-beda urutan & jumlahnya. Status
        // yang namanya mengandung "wh"/"warehouse" atau "ship"/"kirim"
        // (nyakup "shipped", "dikirim", dst) dianggap termasuk WH/Kargo.
        const whCargoKeywords = ['wh', 'warehouse', 'ship', 'kirim'];
        const whCargoStatuses = dbStatuses.filter(s => {
            const lower = s.toLowerCase();
            return whCargoKeywords.some(kw => lower.includes(kw));
        });
        const arrivedCount = filteredByCycle.filter(o => whCargoStatuses.includes(o.status)).length;

        document.getElementById('stat-total-items').innerText = totalCount + ' Pcs';
        document.getElementById('stat-arrived').innerText = arrivedCount + ' Pcs';
        document.getElementById('stat-done').innerText = doneCount + ' Pcs';
    }

    window.changeOrderStatus = function(uid, newStatus) {
        submitPoAction( { action_update_status: '1', order_id: uid, new_status: newStatus } );
    }

    function renderTable() {
        const tbody = document.getElementById('order-rows');
        if(!tbody) return;
        tbody.innerHTML = '';

        const activeYear = document.getElementById('diag-select-year').value;
        const activeMonth = parseInt(document.getElementById('diag-select-month').value);

        const filteredByCycle = orders.filter(o => {
            if(!o.date) return false;
            const d = new Date(o.date);
            return d.getFullYear().toString() === activeYear && (d.getMonth() + 1) === activeMonth;
        });

        document.getElementById('count-all').innerText = filteredByCycle.length;

        const filtered = filteredByCycle.filter(o => {
            const matchStatus = (currentFilter === 'ALL' || o.status === currentFilter);
            const query = searchQuery.toLowerCase();
            const monthStr = getFormattedMonth(o.date).toLowerCase();
            const matchSearch = (
                o.batch.toLowerCase().includes(query) ||
                o.name.toLowerCase().includes(query) ||
                o.item.toLowerCase().includes(query) ||
                monthStr.includes(query)
            );
            return matchStatus && matchSearch;
        });

        updateStats(filteredByCycle);

        if (filtered.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--slate-400); padding: 30px;">Belum ada data order pada bulan ini. Silakan klik "+ Tambah Order Baru".</td></tr>`;
            renderPoPagination(0, 0, 0);
            return;
        }

        // PAGINATION: render cuma baris di halaman aktif. Filter/search di
        // atas tetap jalan ke SELURUH data dulu, baru hasilnya dipaginasi —
        // jadi nyari data gak kebatas halaman yang lagi kebuka.
        const totalPages = Math.ceil(filtered.length / PO_PER_PAGE) || 1;
        if (poCurrentPage > totalPages) poCurrentPage = totalPages;
        const startIdx = (poCurrentPage - 1) * PO_PER_PAGE;
        const pageRows = filtered.slice(startIdx, startIdx + PO_PER_PAGE);

        pageRows.forEach(o => {
            let optionsHTML = '';
            dbStatuses.forEach(st => {
                const stEsc = escHtml(st);
                optionsHTML += `<option value="${stEsc}" ${o.status === st ? 'selected' : ''}>${stEsc}</option>`;
            });

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-weight: 700; color: var(--slate-600); font-size: 13px; white-space: nowrap;">📅 ${getFormattedMonth(o.date)}</td>
                <td><span class="badge-type" style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 8px; font-weight: 800; font-size: 11.5px; border: 1px solid #bae6fd; display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escHtml(o.batch)}">${escHtml(o.batch)}</span></td>
                <td style="font-weight: 700; word-break: break-word; overflow-wrap: break-word; white-space: normal; max-width: 140px;">${escHtml(o.name)}</td>
                <td style="word-break: break-word; overflow-wrap: break-word; white-space: normal; max-width: 300px; line-height: 1.4; color: var(--slate-700);">${escHtml(o.item)}</td>
                <td>
                    <select class="inline-select-status" onchange="changeOrderStatus('${o.uid}', this.value)" style="font-family: inherit; font-size: 11.5px; font-weight: 800; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-soft); outline: none; cursor: pointer; text-transform: uppercase; background: #fef3c7; color: #b45309;">
                        ${optionsHTML}
                    </select>
                </td>
                <td>
                    <span style="display:inline-block; padding: 5px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 800; background: ${o.pay === 'Lunas' ? '#dcfce7' : '#fee2e2'}; color: ${o.pay === 'Lunas' ? '#15803d' : '#b91c1c'};">${escHtml(o.pay)}</span>
                </td>
                <td style="text-align: center;">
                    <button type="button" onclick="deleteOrder('${o.uid}')" style="background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 15px; padding: 4px 8px; border-radius: 6px;" title="Hapus Baris">✕</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        renderPoPagination(filtered.length, startIdx, totalPages);
    }

    function renderPoPagination(totalItems, startIdx, totalPages) {
        const pagination = document.getElementById('po-pagination');
        const pageInfo = document.getElementById('po-page-info');
        const pageNumbers = document.getElementById('po-page-numbers');
        const prevBtn = document.getElementById('po-prev');
        const nextBtn = document.getElementById('po-next');
        if (!pagination) return;

        if (totalItems <= PO_PER_PAGE) {
            pagination.style.display = 'none';
            return;
        }
        pagination.style.display = 'flex';
        if (pageInfo) pageInfo.textContent = `Menampilkan ${startIdx + 1}–${Math.min(startIdx + PO_PER_PAGE, totalItems)} dari ${totalItems} order`;
        if (prevBtn) { prevBtn.disabled = poCurrentPage === 1; prevBtn.style.opacity = poCurrentPage === 1 ? '0.4' : '1'; prevBtn.style.cursor = poCurrentPage === 1 ? 'not-allowed' : 'pointer'; }
        if (nextBtn) { nextBtn.disabled = poCurrentPage === totalPages; nextBtn.style.opacity = poCurrentPage === totalPages ? '0.4' : '1'; nextBtn.style.cursor = poCurrentPage === totalPages ? 'not-allowed' : 'pointer'; }

        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            let startPage = Math.max(1, poCurrentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            startPage = Math.max(1, endPage - 4);
            for (let p = startPage; p <= endPage; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = p;
                const isActive = p === poCurrentPage;
                btn.style.cssText = `padding: 7px 12px; border-radius: 8px; border: 1.5px solid ${isActive ? 'var(--primary)' : 'var(--border-soft)'}; background: ${isActive ? 'var(--primary)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;`;
                btn.addEventListener('click', () => { poCurrentPage = p; renderTable(); });
                pageNumbers.appendChild(btn);
            }
        }
    }

    window.filterOrders = function(filter, btn) {
        currentFilter = filter;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        poCurrentPage = 1; // reset ke halaman 1 tiap ganti filter, biar gak nyangkut di halaman kosong
        renderTable();
    }

    window.handleSearchInput = function(val) {
        searchQuery = val;
        poCurrentPage = 1; // reset ke halaman 1 tiap ganti pencarian
        renderTable();
    }

    let pendingDeleteUid = null;

    window.deleteOrder = function(uid) {
        pendingDeleteUid = uid;
        const modal = document.getElementById('deleteOrderModal');
        if (modal) { modal.style.opacity = '1'; modal.style.pointerEvents = 'auto'; }
    }

    const deleteModalEl = document.getElementById('deleteOrderModal');
    const cancelDeleteBtn = document.getElementById('cancel-delete-order');
    const confirmDeleteBtn = document.getElementById('confirm-delete-order');

    function closeDeleteModal() {
        if (deleteModalEl) { deleteModalEl.style.opacity = '0'; deleteModalEl.style.pointerEvents = 'none'; }
        pendingDeleteUid = null;
    }

    if (cancelDeleteBtn) cancelDeleteBtn.addEventListener('click', closeDeleteModal);
    if (deleteModalEl) {
        // Beda dari modal Tambah Order: klik area kosong di modal konfirmasi
        // ini AMAN buat nutup (sama efeknya kayak klik Batal, gak ada data
        // yang hilang), jadi backdrop-click-to-close tetap dipertahankan di sini.
        deleteModalEl.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    }
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            if (pendingDeleteUid !== null) {
                submitPoAction( { action_delete_order: '1', order_id: pendingDeleteUid } );
            }
        });
    }

    window.openOrderModal = function() {
        const modal = document.getElementById('orderModal');
        if(modal) {
            modal.style.opacity = '1';
            modal.style.pointerEvents = 'auto';
            const firstInput = document.getElementById('in-batch');
            if(firstInput) firstInput.focus();
        }
    }

    window.closeOrderModal = function() {
        const modal = document.getElementById('orderModal');
        if(modal) {
            modal.style.opacity = '0';
            modal.style.pointerEvents = 'none';
        }
    }

    // CATATAN: sengaja TIDAK ada listener "klik area kosong = tutup modal"
    // di sini. Modal Tambah Order cuma bisa ditutup lewat tombol × atau
    // "Batal" — biar gak ke-tutup gak sengaja pas lagi isi form panjang
    // dan data yang udah diketik ilang.

    window.downloadOrdersCSV = function() {
        let csvContent = "\uFEFFREKAPAN ORDER PO\n\n";
        csvContent += "BULAN,NAMA PO / ID INVOICE,NAMA CUSTOMER,DETAIL ITEM,STATUS ORDER,STATUS BAYAR,TOTAL NILAI (RP)\n";

        // Ekspor dari DATA (bukan dari baris tabel yang kerender), biar
        // tetap dapet SELURUH hasil filter — bukan cuma 25 baris di
        // halaman yang lagi kebuka.
        const activeYear = document.getElementById('diag-select-year').value;
        const activeMonth = parseInt(document.getElementById('diag-select-month').value);
        const query = searchQuery.toLowerCase();
        const exportRows = orders.filter(o => {
            if (!o.date) return false;
            const d = new Date(o.date);
            if (d.getFullYear().toString() !== activeYear || (d.getMonth() + 1) !== activeMonth) return false;
            const matchStatus = (currentFilter === 'ALL' || o.status === currentFilter);
            const monthStr = getFormattedMonth(o.date).toLowerCase();
            const matchSearch = (
                o.batch.toLowerCase().includes(query) ||
                o.name.toLowerCase().includes(query) ||
                o.item.toLowerCase().includes(query) ||
                monthStr.includes(query)
            );
            return matchStatus && matchSearch;
        });

        const clean = (txt) => {
            let s = String(txt == null ? '' : txt).replace(/"/g, '""').replace(/\n/g, ' - ').trim();
            // Proteksi CSV/Formula Injection — kalau teks diawali =, +, -,
            // atau @, Excel bisa nganggep itu formula pas file dibuka.
            // Prefix kutip tunggal biar dianggap teks biasa, bukan rumus.
            if (/^[=+\-@]/.test(s)) s = "'" + s;
            return s;
        };
        exportRows.forEach(o => {
            csvContent += `"${clean(getFormattedMonth(o.date))}","${clean(o.batch)}","${clean(o.name)}","${clean(o.item)}","${clean(o.status)}","${clean(o.pay)}","${clean(o.total)}"\n`;
        });

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", `Rekapan_PO_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    const poPrevBtn = document.getElementById('po-prev');
    const poNextBtn = document.getElementById('po-next');
    if (poPrevBtn) poPrevBtn.addEventListener('click', () => { if (poCurrentPage > 1) { poCurrentPage--; renderTable(); } });
    if (poNextBtn) poNextBtn.addEventListener('click', () => { poCurrentPage++; renderTable(); });

    changeDiagCycle();
});
</script>