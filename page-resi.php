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

// Filter RESI via SERVER-SIDE (#9): sebelumnya resi lengkap ikut ke atribut
// data-search di HTML (masking jadi kosmetik doang — tinggal view-source).
// Sekarang resi tidak lagi dimasukkan ke HTML; customer yang mau cari pakai
// nomor resi penuh memakai form di bawah, difilter di sini (server-side).
$resi_search = isset($_GET['resi']) ? trim( sanitize_text_field( wp_unslash( $_GET['resi'] ) ) ) : '';
if ( $resi_search !== '' ) {
    $saved_warehouse = array_values( array_filter( $saved_warehouse, function( $w ) use ( $resi_search ) {
        return ( stripos( (string) $w['resi_masuk'], $resi_search ) !== false )
            || ( stripos( (string) $w['resi_keluar'], $resi_search ) !== false );
    } ) );
}

// Box yang punya minimal 1 item nempel — biar customer bisa lihat progress
// konsolidasi & status pengiriman gabungannya
$active_boxes = $wpdb->get_results( $wpdb->prepare(
    "SELECT b.* FROM {$box_table} b WHERE b.store_id = %s
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
?>

<div class="panel-heading">
    <h3>Rekapan Kedatangan Warehouse</h3>
    <p>Daftar paket tiba di warehouse. Klik foto untuk memperbesar bukti kondisi paket.</p>
</div>

<!-- KOTAK PENCARIAN LIVE (nama/PO — client-side; resi pakai form server-side di bawah) -->
<div style="display: flex; gap: 10px; margin-bottom: 16px;">
    <div style="position: relative; flex: 1;">
        <input type="text" id="public-wh-search" placeholder="Cari Nama atau Nama PO..." style="width: 100%; padding: 11px 16px 11px 40px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600; outline: none; background: #fff;" oninput="filterPublicWarehouse(this.value)">
        <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"><i class="fa-solid fa-search"></i></span>
    </div>
</div>

<!-- CARI BY RESI — server-side (#9): nomor resi penuh tidak lagi dibawa ke
     HTML (dulu cuma di-mask tampilannya tapi tetap kebaca di view-source).
     Customer cari pakai resi ASLI di sini, hasilnya difilter di server. -->
<form method="GET" style="display: flex; gap: 10px; margin-bottom: 24px;">
    <input type="text" name="resi" value="<?php echo esc_attr($resi_search); ?>" placeholder="Cari pakai nomor resi (masuk/keluar)..." style="flex: 1; padding: 11px 16px; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); font-size: 0.9rem; font-weight: 600; outline: none; background: #fff;">
    <button type="submit" class="btn-submit" style="width: auto; padding: 11px 22px;"><i class="fa-solid fa-magnifying-glass"></i> Cari Resi</button>
    <?php if ( $resi_search !== '' ): ?>
        <a href="<?php echo esc_url( remove_query_arg('resi') ); ?>" style="align-self: center; color: var(--text-muted); font-weight: 700; font-size: 0.85rem;">Reset</a>
    <?php endif; ?>
</form>
<?php if ( $resi_search !== '' ): ?>
    <div style="background: #e0f2fe; border: 1.5px solid #bae6fd; color: #0369a1; padding: 10px 16px; border-radius: var(--radius-md); font-weight: 700; font-size: 0.85rem; margin-bottom: 20px;">
        <i class="fa-solid fa-filter"></i> Menampilkan hasil untuk resi "<?php echo esc_html($resi_search); ?>" — <?php echo count($saved_warehouse); ?> paket ketemu.
    </div>
<?php endif; ?>

<!-- TABEL DAFTAR WAREHOUSE -->
<div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); overflow: hidden; box-shadow: 0 4px 15px rgba(139,92,246,0.02); margin-bottom: 28px;">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="background: var(--bg-page); border-bottom: 1.5px solid var(--border-soft); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="padding: 14px 16px;">Foto Bukti</th>
                    <th style="padding: 14px 16px;">Resi Lokal</th>
                    <th style="padding: 14px 16px;">Nama / PO</th>
                    <th style="padding: 14px 16px;">Lokasi WH</th>
                    <th style="padding: 14px 16px;">Tgl Tiba</th>
                    <th style="padding: 14px 16px;">Status</th>
                    <th style="padding: 14px 16px;">Box</th>
                </tr>
            </thead>
            <tbody id="public-wh-tbody">
                <?php if (empty($saved_warehouse)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 8px;">📦</div>
                            <strong>Belum ada data warehouse yang tercatat.</strong>
                            <p style="font-size: 0.8rem; margin-top: 4px;">Data akan tampil otomatis di sini setelah barang Anda diproses admin toko.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($saved_warehouse as $wh): ?>
                        <tr class="wh-row-item" data-search="<?php echo esc_attr(strtolower(($wh['batch'] ?? '') . ' ' . $wh['buyer_name'])); ?>" style="border-bottom: 1px solid var(--border-soft); transition: background 0.15s;">
                            <td style="padding: 14px 16px;">
                                <?php if ( ! empty($wh['foto_bukti']) ): ?>
                                    <a href="<?php echo esc_url($wh['foto_bukti']); ?>" target="_blank"><img src="<?php echo esc_url($wh['foto_bukti']); ?>" style="width:44px; height:44px; object-fit:cover; border-radius:8px; border:1px solid var(--border-soft);"></a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 14px 16px; color: var(--text-dark); font-weight: 600;">
                                <?php echo esc_html( goweb_mask_resi($wh['resi_masuk']) ); ?>
                                <?php if ( ! empty($wh['resi_keluar']) ): ?>
                                    <br><small style="color: var(--text-muted); font-weight: 600;">&rarr; <?php echo esc_html( goweb_mask_resi($wh['resi_keluar']) ); ?> <?php echo ! empty($wh['tanggal_keluar']) ? '(' . esc_html( date('d/m/Y', strtotime($wh['tanggal_keluar'])) ) . ')' : ''; ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 14px 16px; font-weight: 700; color: var(--pastel-purple);">
                                <?php echo esc_html($wh['buyer_name']); ?>
                                <?php if ( ! empty($wh['batch']) ): ?><br><small style="color: var(--text-muted); font-weight: 600;"><?php echo esc_html($wh['batch']); ?></small><?php endif; ?>
                            </td>
                            <td style="padding: 14px 16px; color: var(--text-muted);">
                                <?php echo esc_html($wh['lokasi_wh'] ?: '-'); ?>
                            </td>
                            <td style="padding: 14px 16px; color: var(--text-muted);">
                                <?php echo $wh['tanggal_masuk'] ? esc_html( date('d/m/Y', strtotime($wh['tanggal_masuk'])) ) : '-'; ?>
                            </td>
                            <td style="padding: 14px 16px;">
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                                    <?php echo esc_html($wh['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 14px 16px; color: var(--text-dark); font-weight: 600;">
                                <?php echo esc_html($wh['box_name'] ?: '-'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="public-wh-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 16px 4px; flex-wrap: wrap; gap: 10px;"></div>
</div>

<!-- SECTION STATUS BOX / KONSOLIDASI -->
<?php if ( ! empty($active_boxes) ): ?>
    <div class="panel-heading" style="margin-bottom: 16px;">
        <h3 style="display:flex; align-items:center; gap:8px;">📦 Status Slot Box &amp; Forwarding</h3>
        <p>Barang Anda mungkin digabung dengan customer lain dalam 1 paket besar sebelum dikirim ke Indonesia.</p>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
        <?php foreach ( $active_boxes as $box ):
            $pct = $box['kapasitas_kg'] > 0 ? min(100, round(($box['filled_kg'] / $box['kapasitas_kg']) * 100)) : 0;
        ?>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: 18px; box-shadow: 0 4px 15px rgba(139,92,246,0.02);">
                <div style="font-weight: 800; color: var(--pastel-purple); font-size: 0.9rem; margin-bottom: 2px;">📦 Box: <?php echo esc_html($box['box_name']); ?><?php echo ! empty($box['lokasi']) ? ' (' . esc_html($box['lokasi']) . ')' : ''; ?></div>
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

document.addEventListener('DOMContentLoaded', () => { applyPublicWarehousePagination(); });
</script>