<?php
if (!defined('ABSPATH')) exit;

// Ambil semua tagihan tenant ini, di-join ke data order (batch/nama customer)
// buat ditampilin. Read-only — customer cuma bisa lihat, gak bisa ubah
// status (itu tetap manual dari sisi admin di Rekapan Tagihan).
$invoices_table = goweb_get_table_names()['invoices'];
$orders_table   = goweb_get_table_names()['orders'];
$saved_invoices = $wpdb->get_results( $wpdb->prepare(
    "SELECT i.*, o.batch, o.buyer_name FROM {$invoices_table} i
     LEFT JOIN {$orders_table} o ON i.order_id = o.id
     WHERE i.store_id = %s ORDER BY i.created_at DESC, i.id DESC", $store_id
), ARRAY_A );
?>

<div class="public-tgh">
<div class="public-tgh-heading">
    <span class="public-tgh-module-icon" aria-hidden="true"><i class="fa-solid fa-file-invoice-dollar"></i></span>
    <div>
        <span class="public-tgh-eyebrow">STATUS TAGIHAN</span>
        <h3>Cek Status Tagihan</h3>
        <p>Cari tagihanmu di <strong><?php echo esc_html($store_name); ?></strong> berdasarkan namamu, nomor invoice, nama PO atau batch.</p>
    </div>
</div>

<!-- KOTAK PENCARIAN LIVE -->
<div class="public-search-row">
    <label for="public-invoice-search">Cari namamu, nomor invoice, nama PO atau batch</label>
    <input type="search" id="public-invoice-search" placeholder="Ketik kata kunci…" oninput="filterPublicInvoices(this.value)" aria-controls="public-invoice-tbody" aria-describedby="public-invoice-result-count">
</div>

<!-- TABEL DAFTAR TAGIHAN -->
<div class="tgh-table-card">
    <div class="public-table-scroll" tabindex="0" role="region" aria-label="Daftar tagihan; geser mendatar untuk melihat semua kolom">
        <table class="public-data-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="background: var(--bg-page); border-bottom: 1.5px solid var(--border-soft); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="padding: 14px 16px;">Nama PO/Invoice/Batch</th>
                    <th style="padding: 14px 16px;">Nama Pemesan</th>
                    <th style="padding: 14px 16px;">Jenis Tagihan</th>
                    <th style="padding: 14px 16px;">Nominal</th>
                    <th style="padding: 14px 16px;">Status</th>
                </tr>
            </thead>
            <tbody id="public-invoice-tbody">
                <?php if (empty($saved_invoices)): ?>
                    <tr class="public-initial-empty" data-empty-row>
                        <td colspan="5" class="public-no-results"><strong>Belum ada data tagihan yang tercatat.</strong><p>Tagihan akan tampil otomatis di sini setelah diinput oleh admin.</p></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($saved_invoices as $inv): ?>
                        <tr class="invoice-row-item" data-search="<?php echo esc_attr(strtolower(($inv['batch'] ?? '') . ' ' . ($inv['buyer_name'] ?? ''))); ?>" style="border-bottom: 1px solid var(--border-soft); transition: background 0.15s;">
                            <td data-label="PO / Invoice" style="padding: 14px 16px; font-weight: 700; color: var(--pastel-purple);">
                                <?php echo esc_html($inv['batch'] ?: '(order terhapus)'); ?>
                            </td>
                            <td data-label="Nama pemesan" style="padding: 14px 16px; font-weight: 600; color: var(--text-dark);">
                                <?php echo esc_html($inv['buyer_name'] ?? ''); ?>
                            </td>
                            <td data-label="Jenis tagihan" style="padding: 14px 16px; color: var(--text-muted);">
                                <?php echo esc_html( goweb_invoice_category_label($inv['category']) ); ?>
                            </td>
                            <td data-label="Nominal" style="padding: 14px 16px; font-weight: 700; color: var(--text-dark);">
                                Rp <?php echo number_format(intval($inv['nominal']), 0, ',', '.'); ?>
                            </td>
                            <td data-label="Status" style="padding: 14px 16px;">
                                <?php if ($inv['status'] === 'Lunas'): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: var(--pastel-mint-bg); color: #15803d; border: 1px solid #bbf7d0;"><i class="fa-solid fa-check" aria-hidden="true"></i> Lunas</span>
                                <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Belum Bayar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="public-invoice-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 16px 6px; flex-wrap: wrap; gap: 10px;"></div>
</div>
</div>

<script>
let invPubCurrentPage = 1;
const INV_PUB_PER_PAGE = 25;

function filterPublicInvoices(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('.invoice-row-item');

    rows.forEach(row => {
        const textData = row.getAttribute('data-search') || '';
        row.dataset.matchSearch = textData.includes(q) ? 'true' : 'false';
    });
    invPubCurrentPage = 1;
    applyPublicInvoicePagination();
}

function applyPublicInvoicePagination() {
    const allRows = Array.from(document.querySelectorAll('.invoice-row-item'));
    const matchedRows = allRows.filter(r => r.dataset.matchSearch !== 'false');
    const resultCount = document.getElementById('public-invoice-result-count');
    if (resultCount) resultCount.textContent = matchedRows.length ? `${matchedRows.length} tagihan ditemukan.` : (allRows.length ? 'Tidak ada tagihan yang cocok. Periksa ejaan atau coba kata kunci lain.' : '');
    let emptyState = document.getElementById('public-invoice-no-results');
    const initialEmpty = document.querySelector('[data-empty-row]');
    if (initialEmpty) initialEmpty.style.display = allRows.length ? 'none' : '';
    if (!matchedRows.length && allRows.length) {
        if (!emptyState) {
            emptyState = document.createElement('tr');
            emptyState.id = 'public-invoice-no-results';
            emptyState.innerHTML = '<td colspan="5" class="public-no-results">Tidak ada tagihan yang cocok. Coba kata kunci lain.</td>';
            document.getElementById('public-invoice-tbody').appendChild(emptyState);
        }
        emptyState.style.display = '';
    } else if (emptyState) {
        emptyState.style.display = 'none';
    }
    allRows.forEach(r => { r.style.display = 'none'; });

    const totalPages = Math.ceil(matchedRows.length / INV_PUB_PER_PAGE) || 1;
    if (invPubCurrentPage > totalPages) invPubCurrentPage = totalPages;
    const start = (invPubCurrentPage - 1) * INV_PUB_PER_PAGE;
    matchedRows.slice(start, start + INV_PUB_PER_PAGE).forEach(r => { r.style.display = ''; });

    renderPublicPagination('public-invoice-pagination', matchedRows.length, start, totalPages, INV_PUB_PER_PAGE, (p) => { invPubCurrentPage = p; applyPublicInvoicePagination(); }, 'tagihan');
}

function renderPublicPagination(containerId, totalItems, start, totalPages, perPage, onPageClick, noun) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (totalItems <= perPage) { container.style.display = 'none'; return; }
    container.style.display = 'flex';

    const currentPage = Math.floor(start / perPage) + 1;
    let html = `<span style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">Menampilkan ${totalItems ? start + 1 : 0}–${Math.min(start + perPage, totalItems)} dari ${totalItems} ${noun}</span>`;
    html += `<div style="display: flex; gap: 6px; align-items: center;">`;
    html += `<button type="button" aria-label="Halaman sebelumnya" data-pg="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''} style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--tgh-line); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === 1 ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === 1 ? '0.4' : '1'};">‹ Sebelumnya</button>`;
    html += `<span style="display: flex; gap: 4px;">`;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    startPage = Math.max(1, endPage - 4);
    for (let p = startPage; p <= endPage; p++) {
        const isActive = p === currentPage;
        html += `<button type="button" aria-label="Halaman ${p}" ${isActive ? 'aria-current="page"' : ''} data-pg="${p}" style="padding: 8px 12px; border-radius: 10px; border: 1px solid ${isActive ? 'var(--tgh-accent)' : 'var(--tgh-line)'}; background: ${isActive ? 'var(--tgh-accent)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;">${p}</button>`;
    }
    html += `</span>`;
    html += `<button type="button" aria-label="Halaman berikutnya" data-pg="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''} style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--tgh-line); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === totalPages ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === totalPages ? '0.4' : '1'};">Berikutnya ›</button>`;
    html += `</div>`;
    container.innerHTML = html;
    container.querySelectorAll('button[data-pg]').forEach(btn => {
        btn.addEventListener('click', () => onPageClick(parseInt(btn.getAttribute('data-pg'))));
    });
}

applyPublicInvoicePagination();
</script>
<style>
.public-tgh {
    --tgh-ink: #302b46;
    --tgh-muted: #777188;
    --tgh-line: #e5e0f2;
    --tgh-accent: #6d5a91;
    color: var(--tgh-ink);
}
.public-tgh-heading { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
.public-tgh-module-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--tgh-accent);
    font-size: 1.08rem;
}
.public-tgh-eyebrow { display: block; margin-bottom: 3px; color: var(--tgh-accent); font-size: .65rem; font-weight: 800; letter-spacing: .09em; }
.public-tgh-heading h3 { margin: 0 0 4px; color: var(--tgh-ink); font-size: clamp(1.25rem, 2vw, 1.5rem); font-weight: 800; line-height: 1.25; letter-spacing: -.03em; }
.public-tgh-heading p { margin: 0; color: var(--tgh-muted); font-size: .84rem; line-height: 1.55; }
.tgh-table-card { border: 1px solid var(--tgh-line); border-radius: 16px; overflow: hidden; background: #fff; box-shadow: 0 5px 18px rgba(59, 48, 95, .045); }
.public-tgh .public-search-row { margin-bottom: 20px; }
.public-tgh .public-search-row input:focus-visible { border-color: #9c8ab8; }
@media (max-width: 600px) {
    .public-tgh-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .public-tgh-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .public-tgh-heading p { font-size: .79rem; }
    .tgh-table-card { border-radius: 14px; }
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