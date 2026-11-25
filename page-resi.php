<?php
if (!defined('ABSPATH')) exit;

// Ambil semua entri warehouse tenant ini, di-join ke data order (kalau mode
// linked) buat ditampilin nama PO-nya. Read-only — customer cuma bisa lihat,
// gak bisa ubah apapun (itu tetap manual dari sisi admin di Database Warehouse).
$warehouse_table = goweb_get_table_names()['warehouse'];
$orders_table    = goweb_get_table_names()['orders'];
$box_table       = goweb_get_table_names()['wh_boxes'];

$saved_warehouse = $wpdb->get_results( $wpdb->prepare(
    "SELECT w.*, o.batch, b.box_name, b.status AS box_status FROM {$warehouse_table} w
     LEFT JOIN {$orders_table} o ON w.order_id = o.id
     LEFT JOIN {$box_table} b ON w.box_id = b.id
     WHERE w.store_id = %s ORDER BY w.created_at DESC, w.id DESC", $store_id
), ARRAY_A );

// Alur lacak resi DIHAPUS sepenuhnya atas keputusan pemilik: resi lokal
// sifatnya rahasia, jadi tidak boleh ada jalur pencarian resi di sisi
// publik. Customer yang butuh info resi menghubungi admin toko.
// (Sebelumnya: form cari-by-resi server-side #9 + filter $_GET['resi'].)

// Box yang punya minimal 1 item nempel — biar customer bisa lihat progress
// konsolidasi & status pengiriman gabungannya
$active_boxes = $wpdb->get_results( $wpdb->prepare(
    "SELECT b.* FROM {$box_table} b WHERE b.store_id = %s
     AND b.archived_at IS NULL
     AND EXISTS (SELECT 1 FROM {$warehouse_table} w WHERE w.box_id = b.id)
     ORDER BY b.created_at DESC", $store_id
), ARRAY_A );
foreach ( $active_boxes as &$ab ) {
    $ab['filled_kg'] = goweb_calculate_box_filled_weight( $ab['id'] );
}
unset( $ab );

// Masking resi lokal buat tampilan publik — customer tetap bisa cari
// pakai nomor resi ASLI (data-search pakai nilai lengkap), tapi yang
// ditampilin cuma sebagian + "XXX" biar gak disalahgunakan orang lain.
function goweb_mask_resi( $resi ) {
    if ( empty( $resi ) ) return '-';
    $visible_len = min( 5, strlen( $resi ) );
    return substr( $resi, 0, $visible_len ) . 'XXX';
}
$po_like_default = array('#f4f2f8', '#514b63', '#e5e0f2');
$wh_status_colors = array(
    'menunggu'   => array('#fef3c7', '#b45309', '#fde68a'),
    'di gudang'  => array('#ede9fe', '#6d28d9', '#ddd6fe'),
    'siap kirim' => array('#dbeafe', '#1d4ed8', '#bfdbfe'),
    'terkirim'   => array('#dcfce7', '#15803d', '#bbf7d0'),
);
?>

<div class="public-wh">
<div class="public-wh-heading">
    <span class="public-wh-module-icon" aria-hidden="true"><i class="fa-solid fa-box-open"></i></span>
    <div>
        <span class="public-wh-eyebrow">PELACAKAN PAKET</span>
        <h3>Paket Tiba di Warehouse</h3>
        <p>Daftar paket yang tiba di gudang <strong><?php echo esc_html($store_name); ?></strong>. Klik foto untuk melihat kondisi barang yang datang.</p>
    </div>
</div>

<!-- Pencarian nama/PO client-side; resi pribadi difilter secara server-side. -->
<div class="public-search-row">
    <label for="public-wh-search">Cari namamu, nomor invoice, nama PO atau batch</label>
    <input type="search" id="public-wh-search" placeholder="Ketik kata kunci…" oninput="filterPublicWarehouse(this.value)" aria-controls="public-wh-tbody" aria-describedby="public-wh-result-count">
</div>

<!-- TABEL DAFTAR WAREHOUSE -->
<div class="wh-table-card">
    <div class="public-table-scroll" tabindex="0" role="region" aria-label="Daftar paket warehouse; geser mendatar untuk melihat semua kolom">
        <table class="public-data-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="background: var(--bg-page); border-bottom: 1.5px solid var(--border-soft); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="padding: 14px 16px;">Foto Bukti</th>
                    <th style="padding: 14px 16px;">Resi Lokal</th>
                    <th style="padding: 14px 16px;">Nama / PO</th>
                    <th style="padding: 14px 16px;">Lokasi gudang</th>
                    <th style="padding: 14px 16px;">Tgl Tiba</th>
                    <th style="padding: 14px 16px;">Status</th>
                    <th style="padding: 14px 16px;">Box</th>
                </tr>
            </thead>
            <tbody id="public-wh-tbody">
                <?php if (empty($saved_warehouse)): ?>
                    <tr class="public-initial-empty" data-empty-row>
                        <td colspan="7" class="public-no-results"><strong>Belum ada data warehouse yang tercatat.</strong><p>Data akan tampil otomatis di sini setelah barang Anda diproses admin toko.</p></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($saved_warehouse as $wh): ?>
                        <tr class="wh-row-item" data-search="<?php echo esc_attr(strtolower(($wh['batch'] ?? '') . ' ' . $wh['buyer_name'])); ?>" style="border-bottom: 1px solid var(--border-soft); transition: background 0.15s;">
                            <td data-label="Foto bukti" style="padding: 14px 16px;">
                                <?php if ( ! empty($wh['foto_bukti']) ): ?>
                                    <a href="<?php echo esc_url($wh['foto_bukti']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Buka foto bukti paket di tab baru"><img src="<?php echo esc_url($wh['foto_bukti']); ?>" alt="Bukti kondisi paket yang tiba di warehouse" style="width:44px; height:44px; object-fit:cover; border-radius:8px; border:1px solid var(--border-soft);"></a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Resi lokal" style="padding: 14px 16px; color: var(--text-dark); font-weight: 600;">
                                <?php echo esc_html( goweb_mask_resi($wh['resi_masuk']) ); ?>
                            </td>
                            <td data-label="Pemesan / pesanan" style="padding: 14px 16px; font-weight: 700; color: var(--pastel-purple);">
                                <?php echo esc_html($wh['buyer_name']); ?>
                                <?php if ( ! empty($wh['batch']) ): ?><br><small style="color: var(--text-muted); font-weight: 600;"><?php echo esc_html($wh['batch']); ?></small><?php endif; ?>
                            </td>
                            <td data-label="Lokasi warehouse" style="padding: 14px 16px; color: var(--text-muted);">
                                <?php echo esc_html($wh['lokasi_wh'] ?: '-'); ?>
                            </td>
                            <td data-label="Tanggal tiba" style="padding: 14px 16px; color: var(--text-muted);">
                                <?php echo $wh['tanggal_masuk'] ? esc_html( date('d/m/Y', strtotime($wh['tanggal_masuk'])) ) : '-'; ?>
                            </td>
                            <td data-label="Status" style="padding: 14px 16px;">
                                <?php
                                $wh_colors = isset( $wh_status_colors[ strtolower( trim( (string) $wh['status'] ) ) ] )
                                    ? $wh_status_colors[ strtolower( trim( (string) $wh['status'] ) ) ]
                                    : $po_like_default;
                                ?>
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: <?php echo esc_attr( $wh_colors[0] ); ?>; color: <?php echo esc_attr( $wh_colors[1] ); ?>; border: 1px solid <?php echo esc_attr( $wh_colors[2] ); ?>;">
                                    <?php echo esc_html($wh['status']); ?>
                                </span>
                            </td>
                            <td data-label="Box" style="padding: 14px 16px; color: var(--text-dark); font-weight: 600;">
                                <?php echo esc_html($wh['box_name'] ?: '-'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="public-wh-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 16px 6px; flex-wrap: wrap; gap: 10px;"></div>
</div>
</div>

<!-- SECTION STATUS BOX / KONSOLIDASI -->
<?php if ( ! empty($active_boxes) ): ?>
    <div class="panel-heading public-wh-subheading" style="margin-bottom: 16px;">
        <h3 style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-box" aria-hidden="true" style="color: var(--wh-accent);"></i> Status Slot Box &amp; Forwarding</h3>
        <p>Barang Anda mungkin digabung dengan customer lain dalam 1 paket besar sebelum dikirim ke Indonesia.</p>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
        <?php foreach ( $active_boxes as $box ):
            $pct = $box['kapasitas_kg'] > 0 ? min(100, round(($box['filled_kg'] / $box['kapasitas_kg']) * 100)) : 0;
        ?>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: 18px; box-shadow: 0 4px 15px rgba(139,92,246,0.02);">
                <div style="font-weight: 800; color: var(--pastel-purple); font-size: 0.9rem; margin-bottom: 2px;"><i class="fa-solid fa-box" aria-hidden="true"></i> Box: <?php echo esc_html($box['box_name']); ?><?php echo ! empty($box['lokasi']) ? ' (' . esc_html($box['lokasi']) . ')' : ''; ?></div>
                <div style="border-top: 1px dashed var(--border-soft); margin: 10px 0;"></div>
                <div style="display: flex; justify-content: space-between; font-size: 0.82rem; padding: 4px 0;">
                    <span style="color: var(--text-muted);">Kapasitas Box</span>
                    <strong><?php echo esc_html($box['kapasitas_kg']); ?> Kg</strong>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.82rem; padding: 4px 0;">
                    <span style="color: var(--text-muted);">Slot Terisi</span>
                    <strong><?php echo esc_html($box['filled_kg']); ?> Kg (<?php echo $pct; ?>%)</strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; padding: 4px 0;">
                    <span style="color: var(--text-muted);">Status</span>
                    <span style="display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; background: var(--pastel-purple-bg); color: var(--pastel-purple); border: 1px solid var(--pastel-purple-border);"><?php echo esc_html($box['status']); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
let whPubCurrentPage = 1;
const WH_PUB_PER_PAGE = 25;

function filterPublicWarehouse(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('.wh-row-item');

    rows.forEach(row => {
        const textData = row.getAttribute('data-search') || '';
        row.dataset.matchSearch = textData.includes(q) ? 'true' : 'false';
    });
    whPubCurrentPage = 1;
    applyPublicWarehousePagination();
}

function applyPublicWarehousePagination() {
    const allRows = Array.from(document.querySelectorAll('.wh-row-item'));
    const matchedRows = allRows.filter(r => r.dataset.matchSearch !== 'false');
    const resultCount = document.getElementById('public-wh-result-count');
    if (resultCount) resultCount.textContent = matchedRows.length ? `${matchedRows.length} paket ditemukan.` : (allRows.length ? 'Tidak ada paket yang cocok. Periksa ejaan atau coba kata kunci lain.' : '');
    let emptyState = document.getElementById('public-wh-no-results');
    const initialEmpty = document.querySelector('[data-empty-row]');
    if (initialEmpty) initialEmpty.style.display = allRows.length ? 'none' : '';
    if (!matchedRows.length && allRows.length) {
        if (!emptyState) {
            emptyState = document.createElement('tr');
            emptyState.id = 'public-wh-no-results';
            emptyState.innerHTML = '<td colspan="7" class="public-no-results">Tidak ada paket yang cocok. Coba nama pemesan atau nama PO lain.</td>';
            document.getElementById('public-wh-tbody').appendChild(emptyState);
        }
        emptyState.style.display = '';
    } else if (emptyState) {
        emptyState.style.display = 'none';
    }
    allRows.forEach(r => { r.style.display = 'none'; });

    const totalPages = Math.ceil(matchedRows.length / WH_PUB_PER_PAGE) || 1;
    if (whPubCurrentPage > totalPages) whPubCurrentPage = totalPages;
    const start = (whPubCurrentPage - 1) * WH_PUB_PER_PAGE;
    matchedRows.slice(start, start + WH_PUB_PER_PAGE).forEach(r => { r.style.display = ''; });

    renderPublicPagination('public-wh-pagination', matchedRows.length, start, totalPages, WH_PUB_PER_PAGE, (p) => { whPubCurrentPage = p; applyPublicWarehousePagination(); }, 'item');
}

function renderPublicPagination(containerId, totalItems, start, totalPages, perPage, onPageClick, noun) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (totalItems <= perPage) { container.style.display = 'none'; return; }
    container.style.display = 'flex';

    const currentPage = Math.floor(start / perPage) + 1;
    let html = `<span style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">Menampilkan ${totalItems ? start + 1 : 0}–${Math.min(start + perPage, totalItems)} dari ${totalItems} ${noun}</span>`;
    html += `<div style="display: flex; gap: 6px; align-items: center;">`;
    html += `<button type="button" aria-label="Halaman sebelumnya" data-pg="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''} style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--wh-line); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === 1 ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === 1 ? '0.4' : '1'};">‹ Sebelumnya</button>`;
    html += `<span style="display: flex; gap: 4px;">`;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    startPage = Math.max(1, endPage - 4);
    for (let p = startPage; p <= endPage; p++) {
        const isActive = p === currentPage;
        html += `<button type="button" aria-label="Halaman ${p}" ${isActive ? 'aria-current="page"' : ''} data-pg="${p}" style="padding: 8px 12px; border-radius: 10px; border: 1px solid ${isActive ? 'var(--wh-accent)' : 'var(--wh-line)'}; background: ${isActive ? 'var(--wh-accent)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;">${p}</button>`;
    }
    html += `</span>`;
    html += `<button type="button" aria-label="Halaman berikutnya" data-pg="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''} style="padding: 8px 14px; border-radius: 10px; border: 1px solid var(--wh-line); background: #fff; font-weight: 700; font-size: 12.5px; cursor: ${currentPage === totalPages ? 'not-allowed' : 'pointer'}; opacity: ${currentPage === totalPages ? '0.4' : '1'};">Berikutnya ›</button>`;
    html += `</div>`;
    container.innerHTML = html;
    container.querySelectorAll('button[data-pg]').forEach(btn => {
        btn.addEventListener('click', () => onPageClick(parseInt(btn.getAttribute('data-pg'))));
    });
}

applyPublicWarehousePagination();
</script>
<style>
.public-wh {
    --wh-ink: #302b46;
    --wh-muted: #777188;
    --wh-line: #e5e0f2;
    --wh-accent: #6d5a91;
    color: var(--wh-ink);
}
.public-wh-heading { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
.public-wh-module-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--wh-accent);
    font-size: 1.08rem;
}
.public-wh-eyebrow { display: block; margin-bottom: 3px; color: var(--wh-accent); font-size: .65rem; font-weight: 800; letter-spacing: .09em; }
.public-wh-heading h3 { margin: 0 0 4px; color: var(--wh-ink); font-size: clamp(1.25rem, 2vw, 1.5rem); font-weight: 800; line-height: 1.25; letter-spacing: -.03em; }
.public-wh-heading p { margin: 0; color: var(--wh-muted); font-size: .84rem; line-height: 1.55; }
.public-wh .panel-heading h3 { margin-bottom: 4px; color: var(--wh-ink); font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; }
.public-wh .panel-heading p { color: var(--wh-muted); font-size: .84rem; line-height: 1.55; }
.wh-table-card { border: 1px solid var(--wh-line); border-radius: 16px; overflow: hidden; background: #fff; box-shadow: 0 5px 18px rgba(59, 48, 95, .045); margin-bottom: 28px; }
.public-wh .public-search-row { margin-bottom: 20px; }
@media (max-width: 600px) {
    .public-wh-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .public-wh-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .public-wh-heading p { font-size: .79rem; }
    .wh-table-card { border-radius: 14px; }
}
.public-search-row { display: grid; gap: 6px; margin-bottom: 16px; }
.public-search-row label { color: var(--text-dark); font-size: .8rem; font-weight: 750; }
.public-search-row input { width: 100%; min-width: 0; min-height: 46px; padding: 10px 14px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; color: var(--text-dark); font: inherit; }
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