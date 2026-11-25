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

// Warna badge status: status bawaan punya warna semantik masing-masing;
// status kustom tenant yang tidak dikenal pakai pill netral (bukan ikut amber).
$po_status_colors = array(
    'antrean'      => array('#fef3c7', '#b45309', '#fde68a'),
    'on process'   => array('#dbeafe', '#1d4ed8', '#bfdbfe'),
    'arrived wh'   => array('#ede9fe', '#6d28d9', '#ddd6fe'),
    'shipped indo' => array('#e0e7ff', '#4338ca', '#c7d2fe'),
    'done'         => array('#dcfce7', '#15803d', '#bbf7d0'),
);
$po_status_default = array('#f4f2f8', '#514b63', '#e5e0f2');
?>

<div class="public-po">
<div class="public-po-heading">
    <span class="public-po-module-icon" aria-hidden="true"><i class="fa-solid fa-clipboard-list"></i></span>
    <div>
        <span class="public-po-eyebrow">STATUS PESANAN</span>
        <h3>Daftar Pre-order (PO)</h3>
        <p>Cari status pesananmu di <strong><?php echo esc_html($store_name); ?></strong> dengan namamu, nomor invoice, nama PO atau batch.</p>
    </div>
</div>

<!-- KOTAK PENCARIAN LIVE -->
<div class="public-search-row">
    <label for="public-order-search">Cari namamu, nomor invoice, nama PO atau batch</label>
    <input type="search" id="public-order-search" placeholder="Ketik kata kunci…" oninput="filterPublicOrders(this.value)" aria-controls="public-order-tbody" aria-describedby="public-order-result-count">
 </div>

<!-- TABEL LIST REKAPAN PO (MENARIK DATA DARI DATABASE) -->
<div class="po-table-card">
    <div class="public-table-scroll" tabindex="0" role="region" aria-label="Daftar pesanan PO; geser mendatar untuk melihat semua kolom">
        <table class="public-data-table public-po-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="background: var(--bg-page); border-bottom: 1.5px solid var(--border-soft); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="padding: 14px 16px;">Nama PO/Invoice/Batch</th>
                    <th style="padding: 14px 16px;">Nama Pemesan</th>
                    <th style="padding: 14px 16px;">Detail pesanan</th>
                    <th style="padding: 14px 16px;">Status Pesanan</th>
                </tr>
            </thead>
            <tbody id="public-order-tbody">
                <?php if (empty($saved_orders)): ?>

                    <tr class="public-initial-empty" data-empty-row>
                        <td colspan="4" class="public-no-results"><strong>Belum ada data pre-order yang tercatat.</strong><p>Data pesanan akan tampil otomatis di sini setelah diinput oleh admin toko.</p></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($saved_orders as $o): ?>
                        <tr class="order-row-item" data-search="<?php echo esc_attr(strtolower($o['batch'] . ' ' . $o['buyer_name'] . ' ' . $o['item_detail'])); ?>" style="border-bottom: 1px solid var(--border-soft); transition: background 0.15s;">
                            <td data-label="PO / Invoice" style="padding: 14px 16px; font-weight: 700; color: var(--pastel-purple);">
                                <?php echo esc_html($o['batch']); ?>
                            </td>
                            <td data-label="Nama pemesan" style="padding: 14px 16px; font-weight: 600; color: var(--text-dark);">
                                <?php echo esc_html($o['buyer_name']); ?>
                            </td>
                            <td data-label="Detail pesanan" style="padding: 14px 16px; color: var(--text-muted); max-width: 220px; line-height: 1.4;">
                                <?php echo esc_html($o['item_detail']); ?>
                            </td>
                            <td data-label="Status pesanan" style="padding: 14px 16px;">
                                <?php
                                $po_colors = isset( $po_status_colors[ strtolower( trim( (string) $o['order_status'] ) ) ] )
                                    ? $po_status_colors[ strtolower( trim( (string) $o['order_status'] ) ) ]
                                    : $po_status_default;
                                ?>
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: <?php echo esc_attr( $po_colors[0] ); ?>; color: <?php echo esc_attr( $po_colors[1] ); ?>; border: 1px solid <?php echo esc_attr( $po_colors[2] ); ?>;">
                                    <?php echo esc_html($o['order_status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="public-order-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 16px 6px; flex-wrap: wrap; gap: 10px;"></div>
</div>
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
    const resultCount = document.getElementById('public-order-result-count');
    if (resultCount) resultCount.textContent = matchedRows.length ? `${matchedRows.length} pesanan ditemukan.` : (allRows.length ? 'Tidak ada pesanan yang cocok. Periksa ejaan atau coba kata kunci lain.' : '');
    let emptyState = document.getElementById('public-order-no-results');
    const initialEmpty = document.querySelector('[data-empty-row]');
    if (initialEmpty) initialEmpty.style.display = allRows.length ? 'none' : '';
    if (!matchedRows.length && allRows.length) {
        if (!emptyState) {
            emptyState = document.createElement('tr');
            emptyState.id = 'public-order-no-results';
            emptyState.innerHTML = '<td colspan="4" class="public-no-results">Tidak ada pesanan yang cocok. Coba nama PO, invoice, atau nama pemesan lain.</td>';
            document.getElementById('public-order-tbody').appendChild(emptyState);
        }
        emptyState.style.display = '';
    } else if (emptyState) {
        emptyState.style.display = 'none';
    }
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
    let html = `<span style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">Menampilkan ${totalItems ? start + 1 : 0}–${Math.min(start + perPage, totalItems)} dari ${totalItems} ${noun}</span>`;
    html += `<div style="display: flex; gap: 6px; align-items: center;">`;
    html += `<button type="button" aria-label="Halaman sebelumnya" data-pg="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''} style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--po-line); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === 1 ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === 1 ? '0.4' : '1'};">‹ Sebelumnya</button>`;
    html += `<span style="display: flex; gap: 4px;">`;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    startPage = Math.max(1, endPage - 4);
    for (let p = startPage; p <= endPage; p++) {
        const isActive = p === currentPage;
        html += `<button type="button" aria-label="Halaman ${p}" ${isActive ? 'aria-current="page"' : ''} data-pg="${p}" style="padding: 8px 12px; border-radius: 10px; border: 1px solid ${isActive ? 'var(--po-accent)' : 'var(--po-line)'}; background: ${isActive ? 'var(--po-accent)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;">${p}</button>`;
    }
    html += `</span>`;
    html += `<button type="button" aria-label="Halaman berikutnya" data-pg="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''} style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--po-line); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === totalPages ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === totalPages ? '0.4' : '1'};">Berikutnya ›</button>`;
    html += `</div>`;
    container.innerHTML = html;
    container.querySelectorAll('button[data-pg]').forEach(btn => {
        btn.addEventListener('click', () => onPageClick(parseInt(btn.getAttribute('data-pg'))));
    });
}

applyPublicOrderPagination();
</script>
<style>
.public-po {
    --po-ink: #302b46;
    --po-muted: #777188;
    --po-line: #e5e0f2;
    --po-accent: #6d5a91;
    color: var(--po-ink);
}
.public-po-heading { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
.public-po-module-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--po-accent);
    font-size: 1.08rem;
}
.public-po-eyebrow { display: block; margin-bottom: 3px; color: var(--po-accent); font-size: .65rem; font-weight: 800; letter-spacing: .09em; }
.public-po-heading h3 { margin: 0 0 4px; color: var(--po-ink); font-size: clamp(1.25rem, 2vw, 1.5rem); font-weight: 800; line-height: 1.25; letter-spacing: -.03em; }
.public-po-heading p { margin: 0; color: var(--po-muted); font-size: .84rem; line-height: 1.55; }
.po-table-card { border: 1px solid var(--po-line); border-radius: 16px; overflow: hidden; background: #fff; box-shadow: 0 5px 18px rgba(59, 48, 95, .045); }
.public-po .public-search-row { margin-bottom: 20px; }
.public-po .public-search-row input:focus-visible { border-color: #9c8ab8; }
@media (max-width: 600px) {
    .public-po-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .public-po-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .public-po-heading p { font-size: .79rem; }
    .po-table-card { border-radius: 14px; }
}
.public-search-row { display: grid; gap: 6px; margin-bottom: 20px; }
.public-search-row label { color: var(--text-dark); font-size: .8rem; font-weight: 750; }
.public-search-row input { width: 100%; min-height: 46px; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; color: var(--text-dark); font: inherit; }
.public-search-row input:focus-visible, .public-table-scroll:focus-visible { outline: 3px solid rgba(109,90,145,.3); outline-offset: 2px; }
.public-search-status { min-height: 1.2em; color: var(--text-muted); font-size: .75rem; }
.public-table-scroll { max-width: 100%; overflow-x: auto; }
.public-no-results { padding: 24px 16px; color: var(--text-muted); text-align: center; }
@media (max-width: 640px) {
    .public-table-scroll { overflow: visible; }
    .public-data-table, .public-data-table tbody, .public-data-table tr, .public-data-table td { display: block; width: 100%; }
    .public-data-table thead { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; clip-path: inset(50%); }
    .public-data-table tbody tr.order-row-item, .public-data-table tbody tr.invoice-row-item, .public-data-table tbody tr.wh-row-item, .public-data-table tbody tr.public-initial-empty, .public-data-table tbody tr[id$="no-results"] { display: block; margin: 10px 0; padding: 10px 12px; border: 1px solid var(--border-soft) !important; border-radius: 12px; background: #fff; box-shadow: 0 3px 12px rgba(59,48,95,.04); }
    .public-data-table tbody td[data-label] { display: grid; grid-template-columns: minmax(90px, .65fr) minmax(0, 1.35fr); gap: 10px; max-width: none !important; padding: 9px 2px !important; border-bottom: 1px solid #f0edf4; text-align: left; overflow-wrap: anywhere; }
    .public-data-table tbody td[data-label]:last-child { border-bottom: 0; }
    .public-data-table tbody td[data-label]::before { content: attr(data-label); color: var(--text-muted); font-size: .68rem; font-weight: 700; }
}
</style>