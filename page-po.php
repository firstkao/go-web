<?php
if (!defined('ABSPATH')) exit;

// Ambil data rekapan order/PO dari tabel database (sebelumnya wp_options
// yang gak pernah beneran ke-tulis oleh apapun — modul ini sekarang
// tersambung penuh, lihat menu-po.php di sisi admin)
$orders_table = goweb_get_table_names()['orders'];
$saved_orders = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$orders_table} WHERE store_id = %s ORDER BY created_at DESC, id DESC", $store_id
), ARRAY_A );

// Ambil status kustom aktif untuk badge status
$active_statuses = goweb_get_tenant_setting( $store_id, 'custom_statuses', array("Antrean", "On Process", "Arrived WH", "Shipped Indo", "Done") );
?>

<div class="panel-heading">
    <h3>Data Rekapan PO &amp; Status Pesanan</h3>
    <p>Cek daftar pre-order aktif atau cari status pesanan Anda menggunakan nama pemesan dan nomor invoice.</p>
</div>

<!-- KOTAK PENCARIAN LIVE -->
<div style="display: flex; gap: 10px; margin-bottom: 24px;">
    <div style="position: relative; flex: 1;">
        <input type="text" id="public-order-search" placeholder="Cari Nama PO, No. Invoice, atau Nama Pemesan..." style="width: 100%; padding: 11px 16px 11px 40px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600; outline: none; background: #fff;" oninput="filterPublicOrders(this.value)">
        <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"><i class="fa-solid fa-search"></i></span>
    </div>
</div>

<!-- TABEL LIST REKAPAN PO (MENARIK DATA DARI DATABASE) -->
<div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); overflow: hidden; box-shadow: 0 4px 15px rgba(139,92,246,0.02);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="background: var(--bg-page); border-bottom: 1.5px solid var(--border-soft); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="padding: 14px 16px;">Nama PO / Invoice</th>
                    <th style="padding: 14px 16px;">Nama Pemesan</th>
                    <th style="padding: 14px 16px;">Detail Item Pesanan</th>
                    <th style="padding: 14px 16px;">Status Pesanan</th>
                </tr>
            </thead>
            <tbody id="public-order-tbody">
                <?php if (empty($saved_orders)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 8px;">📦</div>
                            <strong>Belum ada data rekapan PO publik yang tercatat.</strong>
                            <p style="font-size: 0.8rem; margin-top: 4px;">Data pesanan akan tampil otomatis di sini setelah diinput oleh admin toko.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($saved_orders as $o): ?>
                        <tr class="order-row-item" data-search="<?php echo esc_attr(strtolower($o['batch'] . ' ' . $o['buyer_name'] . ' ' . $o['item_detail'])); ?>" style="border-bottom: 1px solid var(--border-soft); transition: background 0.15s;">
                            <td style="padding: 14px 16px; font-weight: 700; color: var(--pastel-purple);">
                                <?php echo esc_html($o['batch']); ?>
                            </td>
                            <td style="padding: 14px 16px; font-weight: 600; color: var(--text-dark);">
                                <?php echo esc_html($o['buyer_name']); ?>
                            </td>
                            <td style="padding: 14px 16px; color: var(--text-muted); max-width: 220px; line-height: 1.4;">
                                <?php echo esc_html($o['item_detail']); ?>
                            </td>
                            <td style="padding: 14px 16px;">
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                                    <?php echo esc_html($o['order_status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="public-order-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 16px 4px; flex-wrap: wrap; gap: 10px;"></div>
</div>

<script>
let poPubCurrentPage = 1;
const PO_PUB_PER_PAGE = 25;

function filterPublicOrders(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('.order-row-item');

    rows.forEach(row => {
        const textData = row.getAttribute('data-search') || '';
        row.dataset.matchSearch = textData.includes(q) ? 'true' : 'false';
    });
    poPubCurrentPage = 1; // reset ke halaman 1 tiap ganti pencarian
    applyPublicOrderPagination();
}

function applyPublicOrderPagination() {
    const allRows = Array.from(document.querySelectorAll('.order-row-item'));
    const matchedRows = allRows.filter(r => r.dataset.matchSearch !== 'false');
    allRows.forEach(r => { r.style.display = 'none'; });

    const totalPages = Math.ceil(matchedRows.length / PO_PUB_PER_PAGE) || 1;
    if (poPubCurrentPage > totalPages) poPubCurrentPage = totalPages;
    const start = (poPubCurrentPage - 1) * PO_PUB_PER_PAGE;
    matchedRows.slice(start, start + PO_PUB_PER_PAGE).forEach(r => { r.style.display = ''; });

    renderPublicPagination('public-order-pagination', matchedRows.length, start, totalPages, PO_PUB_PER_PAGE, (p) => { poPubCurrentPage = p; applyPublicOrderPagination(); }, 'order');
}

// Fungsi render kontrol pagination generic — dipakai bareng di semua
// halaman publik (PO/Tagihan/Resi), biar konsisten & gak duplikat kode.
function renderPublicPagination(containerId, totalItems, start, totalPages, perPage, onPageClick, noun) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (totalItems <= perPage) { container.style.display = 'none'; return; }
    container.style.display = 'flex';

    const currentPage = Math.floor(start / perPage) + 1;
    let html = `<span style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">Menampilkan ${start + 1}–${Math.min(start + perPage, totalItems)} dari ${totalItems} ${noun}</span>`;
    html += `<div style="display: flex; gap: 6px; align-items: center;">`;
    html += `<button type="button" data-pg="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''} style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === 1 ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === 1 ? '0.4' : '1'};">‹ Sebelumnya</button>`;
    html += `<span style="display: flex; gap: 4px;">`;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    startPage = Math.max(1, endPage - 4);
    for (let p = startPage; p <= endPage; p++) {
        const isActive = p === currentPage;
        html += `<button type="button" data-pg="${p}" style="padding: 7px 12px; border-radius: 8px; border: 1.5px solid ${isActive ? 'var(--pastel-purple)' : 'var(--border-soft)'}; background: ${isActive ? 'var(--pastel-purple)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;">${p}</button>`;
    }
    html += `</span>`;
    html += `<button type="button" data-pg="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''} style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === totalPages ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === totalPages ? '0.4' : '1'};">Berikutnya ›</button>`;
    html += `</div>`;
    container.innerHTML = html;
    container.querySelectorAll('button[data-pg]').forEach(btn => {
        btn.addEventListener('click', () => onPageClick(parseInt(btn.getAttribute('data-pg'))));
    });
}

document.addEventListener('DOMContentLoaded', () => { applyPublicOrderPagination(); });
</script>