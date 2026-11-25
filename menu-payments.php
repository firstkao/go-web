<?php
if (!defined('ABSPATH')) exit;
if (!$has_payment) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup!</div>';
    return;
}

// Helper: render badge jenis pembayaran. Sekarang "Jenis Pembayaran" bisa
// diedit admin di Pengaturan (gak cuma 7 kategori hardcode lagi), jadi
// warnanya pakai goweb_category_badge_color() di functions.php — kategori
// lama tetap dapet warna asli, kategori baru/custom dapet warna otomatis
// dari rotasi palet (konsisten per kategori, gak perlu diatur manual).
function goweb_payment_type_badge( $type ) {
    if ( empty( $type ) ) {
        return '<span class="payment-type-badge is-empty">—</span>';
    }
    $c = goweb_category_badge_color( $type );
    return '<span class="payment-type-badge" style="background:' . $c['bg'] . '; color:' . $c['color'] . ';">' . esc_html( goweb_invoice_category_label( $type ) ) . '</span>';
}

$po_payments = array_filter( $subscription_history, function( $r ) { return $r['payment_category'] === 'po'; } );
$payment_count = count( $po_payments );
$payment_pending_count = 0;
$payment_verified_count = 0;
foreach ( $po_payments as $payment_row ) {
    if ( isset( $payment_row['status'] ) && $payment_row['status'] === 'Verified' ) {
        $payment_verified_count++;
    } else {
        $payment_pending_count++;
    }
}
?>
<div class="payments-page">
    <div class="payments-heading">
        <span class="payments-heading-icon" aria-hidden="true"><i class="fa-solid fa-money-check-dollar"></i></span>
        <div>
            <span class="payments-heading-eyebrow">PEMBAYARAN TOKO</span>
            <h2>Pembayaran Pelanggan</h2>
            <p>Kelola konfirmasi transfer pelanggan dan verifikasi pembayaran yang masuk melalui web.</p>
        </div>
    </div>

    <div class="payments-summary" aria-label="Ringkasan pembayaran">
        <div class="payments-summary-card">
            <span class="payments-summary-icon"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
            <div><span class="payments-summary-label">Total Pembayaran</span><strong><?php echo number_format_i18n( $payment_count ); ?></strong></div>
        </div>
        <div class="payments-summary-card is-pending">
            <span class="payments-summary-icon"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
            <div><span class="payments-summary-label">Menunggu Verifikasi</span><strong><?php echo number_format_i18n( $payment_pending_count ); ?></strong></div>
        </div>
        <div class="payments-summary-card is-verified">
            <span class="payments-summary-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
            <div><span class="payments-summary-label">Terverifikasi</span><strong><?php echo number_format_i18n( $payment_verified_count ); ?></strong></div>
        </div>
    </div>

<div class="card-table-wrap payments-table-card">
    <div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Waktu Uang Masuk</th>
                <th>Waktu Verifikasi</th>
                <th>Invoice</th>
                <th>Jenis</th>
                <th>Metode</th>
                <th>Pemesan</th>
                <th>Nominal</th>
                <th>Bukti</th>
                <th>Status</th>
                <th style="text-align: center;">Aksi</th>
            </tr>
        </thead>
        <tbody id="payments-rows">
            <?php
            if ( ! empty( $po_payments ) ): foreach ( $po_payments as $row ):
                $is_verified = ( isset($row['status']) && $row['status'] == 'Verified' );
                $row_payment_type = isset($row['payment_type']) ? $row['payment_type'] : '';
                $row_payment_destination = isset($row['payment_destination']) ? $row['payment_destination'] : '';
            ?>
                <tr class="payment-row-item">
                    <td data-label="Waktu Uang Masuk" class="payment-date-cell">
                        <?php $received_at = $row['created_at']; ?>
                        <time datetime="<?php echo esc_attr($received_at); ?>"><?php echo esc_html( date('d/m/Y H:i', strtotime($received_at)) ); ?></time>
                    </td>
                    <td data-label="Waktu Verifikasi" class="payment-date-cell">
                        <?php if (! empty($row['verified_at'])): ?>
                            <time datetime="<?php echo esc_attr($row['verified_at']); ?>"><?php echo esc_html( date('d/m/Y H:i', strtotime($row['verified_at'])) ); ?></time>
                        <?php else: ?>
                            <span class="payment-not-recorded"><?php echo $is_verified ? 'Belum tercatat' : 'Menunggu verifikasi'; ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Invoice"><strong><?php echo esc_html($row['period']); ?></strong></td>
                    <td data-label="Jenis"><?php echo goweb_payment_type_badge( $row_payment_type ); ?></td>
                    <td data-label="Metode"><?php echo $row_payment_destination !== '' ? esc_html($row_payment_destination) : '—'; ?></td>
                    <td data-label="Pemesan"><?php echo esc_html($row['sender']); ?></td>
                    <td data-label="Nominal"><strong class="payment-amount">Rp <?php echo number_format(intval($row['nominal']), 0, ',', '.'); ?></strong></td>
                    <td data-label="Bukti">
                        <?php if (!empty($row['proof_url'])): ?>
                            <a href="<?php echo esc_url($row['proof_url']); ?>" target="_blank" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Lihat Bukti</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <?php if ($is_verified): ?>
                            <span class="status-badge badge-mint">Verified</span>
                        <?php else: ?>
                            <span class="status-badge badge-peach">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Aksi" class="payment-actions-cell">
                        <?php if (!$is_verified): ?>
                            <form method="POST" action="<?php echo esc_url( home_url('/dashboard/payments/') . $store_query_suffix ); ?>" class="payment-action-form">
                                <?php wp_nonce_field('goweb_payment_action_' . $store_id, 'goweb_payment_nonce'); ?>
                                <input type="hidden" name="index" value="<?php echo esc_attr($row['id']); ?>">
                                <button type="submit" name="goweb_payment_action" value="verify" class="btn-submit payment-verify-button">Verifikasi</button>
                                <button type="submit" name="goweb_payment_action" value="delete" class="payment-delete-button" aria-label="Hapus pembayaran" onclick="return goweb_confirmSubmit(this, 'Hapus data pembayaran ini?');"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="<?php echo esc_url( home_url('/dashboard/payments/') . $store_query_suffix ); ?>" class="payment-action-form is-complete">
                                <?php wp_nonce_field('goweb_payment_action_' . $store_id, 'goweb_payment_nonce'); ?>
                                <input type="hidden" name="index" value="<?php echo esc_attr($row['id']); ?>">
                                <span class="payment-complete-label">Selesai</span>
                                <button type="submit" name="goweb_payment_action" value="delete" class="payment-delete-button" onclick="return goweb_confirmSubmit(this, 'Hapus data pembayaran yang udah Verified ini? Transaksi ini udah selesai, hapus buat bebasin storage.');" title="Hapus (bebasin storage)" aria-label="Hapus pembayaran selesai"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr class="payment-empty-row"><td colspan="10">Belum ada konfirmasi pembayaran dari pelanggan.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <!-- KONTROL PAGINATION -->
    <div id="payments-pagination" class="payments-pagination" style="display: none;">
        <span id="payments-page-info" class="payments-page-info"></span>
        <div class="payments-page-controls">
            <button type="button" id="payments-prev" class="payments-page-button">‹ Sebelumnya</button>
            <span id="payments-page-numbers" class="payments-page-numbers"></span>
            <button type="button" id="payments-next" class="payments-page-button">Berikutnya ›</button>
        </div>
    </div>
</div>
</div>

<style>
.payments-page {
    --payments-ink: #302b46;
    --payments-muted: #625d70;
    --payments-line: #e5e0f2;
    --payments-accent: #6d5a91;
    --payments-accent-soft: #f0ebf7;
    color: var(--payments-ink);
}
.payments-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 0 0 20px;
}
.payments-heading-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--payments-accent);
    font-size: 1.08rem;
}
.payments-heading-eyebrow {
    display: block;
    margin-bottom: 4px;
    color: var(--payments-accent);
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .09em;
}
.payments-heading h2 {
    margin: 0 0 4px;
    color: var(--payments-ink);
    font-size: clamp(1.25rem, 2vw, 1.5rem);
    font-weight: 800;
    line-height: 1.25;
    letter-spacing: -.03em;
}
.payments-heading p {
    margin: 0;
    color: var(--payments-muted);
    font-size: .84rem;
    line-height: 1.55;
}
.payments-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.payments-summary-card {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 13px;
    padding: 16px 18px;
    border: 1px solid var(--payments-line);
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(59, 48, 95, .04);
}
.payments-summary-icon {
    display: grid;
    flex: 0 0 40px;
    width: 40px;
    height: 40px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 12px;
    background: var(--payments-accent-soft);
    color: var(--payments-accent);
}
.payments-summary-card.is-pending .payments-summary-icon { border-color: #eadab2; background: #f7f0df; color: #94733b; }
.payments-summary-card.is-verified .payments-summary-icon { border-color: #d6e5d8; background: #edf4ee; color: #62816a; }
.payments-summary-card > div { display: flex; min-width: 0; flex-direction: column; gap: 2px; }
.payments-summary-label { color: var(--payments-muted); font-size: .72rem; font-weight: 650; line-height: 1.4; }
.payments-summary-card strong { color: var(--payments-ink); font-size: 1.25rem; font-weight: 800; line-height: 1.2; }
.payments-table-card {
    padding: clamp(16px, 2vw, 24px);
    border-color: var(--payments-line);
    border-radius: 16px;
    box-shadow: 0 5px 18px rgba(59, 48, 95, .045);
}
.payments-table-card .table-responsive { border: 1px solid var(--payments-line); border-radius: 12px; }
.payments-table-card .data-table { min-width: 1100px; margin-bottom: 0; }
.payments-table-card .data-table th { padding: 13px 12px; background: #f2eef8; color: #62517f; font-size: .69rem; letter-spacing: .045em; }
.payments-table-card .data-table td { padding: 13px 12px; color: #514b63; font-size: .79rem; vertical-align: middle; }
.payments-table-card .data-table tbody tr:last-child td { border-bottom: 0; }
.payment-date-cell { color: var(--payments-muted) !important; white-space: nowrap; font-size: .73rem; font-variant-numeric: tabular-nums; }
.payment-not-recorded { color: var(--payments-muted); font-style: italic; white-space: normal; }
.payment-amount { color: var(--payments-ink); white-space: nowrap; }
.payment-type-badge {
    display: inline-block;
    max-width: 220px;
    white-space: normal;
    padding: 4px 9px;
    border: 1px solid rgba(89,74,117,.12);
    border-radius: 999px;
    font-size: .62rem;
    font-weight: 800;
    line-height: 1.4;
    text-transform: uppercase;
    hyphens: none;
}
/* Bug tenant di tab: kolom Jenis kejepit sampe badge ke-wrap 1 huruf
   per baris (D\nP\nA\n...). Kolom ini gak boleh menyusut di bawah lebar
   badge-nya — di desktop table tetep bisa scroll, di tablet/mobile udah
   pindah ke layout kartu. */
.payments-table-card .data-table th:nth-child(4),
.payments-table-card .data-table td[data-label="Jenis"] { min-width: 132px; }
.payment-type-badge.is-empty { background: #f0ebf7; color: var(--payments-muted); }
.payments-table-card .data-table .status-badge { font-size: .67rem; white-space: nowrap; }
.payment-action-form { display: flex; align-items: center; justify-content: center; gap: 6px; }
.payment-verify-button {
    min-height: 34px;
    padding: 6px 11px !important;
    border-radius: 9px !important;
    background: linear-gradient(135deg, #6d5a91, #51416f) !important;
    font-size: .72rem !important;
    white-space: nowrap;
}
.payment-delete-button {
    display: inline-grid;
    min-width: 32px;
    min-height: 32px;
    place-items: center;
    padding: 4px 8px;
    border: 1px solid #f0d8d6;
    border-radius: 9px;
    background: #fbf1ef;
    color: #965a58;
    font: inherit;
    font-size: .78rem;
    font-weight: 800;
    cursor: pointer;
    transition: border-color .16s ease, background .16s ease;
}
.payment-delete-button:hover { border-color: #dfc2bf; background: #f7eae8; }
.payment-complete-label { color: var(--payments-muted); font-size: .74rem; font-weight: 750; }
.payment-empty-row td { padding: 30px !important; color: var(--payments-muted) !important; text-align: center; }
.payments-pagination {
    justify-content: space-between;
    align-items: center;
    padding: 14px 2px 2px;
    flex-wrap: wrap;
    gap: 10px;
}
.payments-page-info { color: var(--payments-muted); font-size: .76rem; font-weight: 650; }
.payments-page-controls { display: flex; align-items: center; gap: 6px; }
.payments-page-numbers { display: flex; gap: 4px; }
.payments-page-button {
    min-height: 40px;
    padding: 7px 11px;
    border: 1px solid var(--payments-line);
    border-radius: 10px;
    background: #fff;
    color: #514b63;
    font: inherit;
    font-size: .74rem;
    font-weight: 700;
    cursor: pointer;
}
.payments-page-button:hover:not(:disabled) { border-color: #cfc3df; background: #faf8fc; }
.payments-page-button:disabled { opacity: .45; cursor: not-allowed; }
.payments-page button:focus-visible,
#genericConfirmModal button:focus-visible { outline: 3px solid rgba(157, 138, 184, .42); outline-offset: 2px; }
#genericConfirmModal { z-index: 10000 !important; }
#genericConfirmModal > div { border: 1px solid #e5e0f2; border-radius: 18px !important; }
@media (max-width: 900px) {
    .payments-summary { gap: 10px; }
    .payments-summary-card { padding: 14px; }
}
/* ---- TABLET (681–1024px): baris tabel jadi kartu 2 kolom ----
   Di rentang ini tabel 10 kolom gak muat (kolom Jenis kejepit jadi
   badge vertikal — bug di tab tenant), jadi mulai breakpoint ini
   kita pindah ke layout kartu. Mobile tinggal nurunin dari sini. */
@media (max-width: 1024px) {
    .payments-table-card .table-responsive { overflow: visible; border: 0; }
    .payments-table-card .data-table { display: block; min-width: 0; }
    .payments-table-card .data-table thead { display: none; }
    .payments-table-card .data-table tbody { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .payments-table-card .data-table tbody tr.payment-row-item {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0 14px;
        padding: 10px 14px;
        border: 1px solid var(--payments-line);
        border-radius: 13px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(59, 48, 95, .035);
    }
    .payments-table-card .data-table tbody tr.payment-row-item > td {
        display: flex;
        min-width: 0;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: 3px;
        padding: 8px 0;
        border-bottom: 1px solid #f0edf4;
        color: var(--payments-ink);
        font-size: .76rem;
        text-align: left;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .payments-table-card .data-table tbody tr.payment-row-item > td::before {
        color: var(--payments-muted);
        content: attr(data-label);
        font-size: .61rem;
        font-weight: 800;
        letter-spacing: .045em;
        text-transform: uppercase;
    }
    .payments-table-card .data-table tbody tr.payment-row-item > td:last-child { grid-column: 1 / -1; border-top: 1px solid #f0edf4; border-bottom: 0; }
    .payments-table-card .data-table tbody tr.payment-row-item > td[data-label="Waktu Uang Masuk"],
    .payments-table-card .data-table tbody tr.payment-row-item > td[data-label="Waktu Verifikasi"] { grid-column: 1 / -1; }
    .payment-action-form { justify-content: flex-start; }
    .payment-empty-row { display: block; border: 1px solid var(--payments-line); border-radius: 12px; background: #fff; }
    .payment-empty-row td { display: block !important; padding: 22px 12px !important; border: 0 !important; font-size: .8rem !important; text-align: center; }
    .payment-empty-row td::before { content: none !important; }
}

/* ---- MOBILE (≤680px): kartu jadi 1 kolom + heading/ringkasan susun ---- */
@media (max-width: 680px) {
    .payments-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .payments-heading-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: 1rem; }
    .payments-heading p { font-size: .79rem; }
    .payments-summary { grid-template-columns: 1fr; gap: 9px; margin-bottom: 15px; }
    .payments-summary-card { padding: 12px 14px; border-radius: 13px; }
    .payments-summary-icon { flex-basis: 36px; width: 36px; height: 36px; border-radius: 11px; }
    .payments-summary-card strong { font-size: 1.1rem; }
    .payments-table-card { padding: 12px; border-radius: 14px; }
    .payments-table-card .data-table tbody { grid-template-columns: minmax(0, 1fr); gap: 10px; }
    .payments-table-card .data-table tbody tr.payment-row-item { padding: 8px 12px; gap: 0 12px; }
    .payments-table-card .data-table tbody tr.payment-row-item > td { padding: 9px 0; }
    .payments-pagination { align-items: flex-start; }
    .payments-page-controls { width: 100%; justify-content: space-between; gap: 4px; }
    .payments-page-button { padding-inline: 8px; font-size: .69rem; }
    .payments-page-numbers { gap: 3px; }
    #payments-page-numbers button { padding: 7px 9px !important; font-size: .7rem !important; }
}
@media (max-width: 380px) {
    .payments-table-card .data-table tbody tr.payment-row-item { grid-template-columns: minmax(0, 1fr); }
    .payments-table-card .data-table tbody tr.payment-row-item > td { border-bottom: 1px solid #f0edf4; }
    .payments-table-card .data-table tbody tr.payment-row-item > td:last-child { border-top: 0; border-bottom: 0; }
}
</style>

<!-- MODAL KONFIRMASI GENERIC (gantiin confirm() bawaan browser) -->
<div class="modal-overlay" id="genericConfirmModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;">
    <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;"><i class="fa-solid fa-circle-question"></i></div>
        <p id="genericConfirmMessage" style="font-size: 0.95rem; font-weight: 700; color: var(--slate-900); margin-bottom: 24px;"></p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="genericConfirmCancel" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; color: var(--text-muted); cursor: pointer;">Batal</button>
            <button type="button" id="genericConfirmOk" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<script>
let goweb_pendingConfirmButton = null;

// Dipanggil dari onclick tombol yang butuh konfirmasi. Return false selalu
// (cegah submit native langsung) — submit beneran cuma jalan kalau user
// klik "Ya, Lanjutkan" di modal.
function goweb_confirmSubmit(button, message) {
    goweb_pendingConfirmButton = button;
    document.getElementById('genericConfirmMessage').textContent = message;
    document.getElementById('genericConfirmModal').style.display = 'flex';
    return false;
}

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('genericConfirmModal');
    const cancelBtn = document.getElementById('genericConfirmCancel');
    const okBtn = document.getElementById('genericConfirmOk');

    if (cancelBtn) cancelBtn.addEventListener('click', () => {
        modal.style.display = 'none';
        goweb_pendingConfirmButton = null;
    });
    if (modal) modal.addEventListener('click', (e) => {
        if (e.target === modal) { modal.style.display = 'none'; goweb_pendingConfirmButton = null; }
    });
    if (okBtn) okBtn.addEventListener('click', () => {
        if (goweb_pendingConfirmButton && goweb_pendingConfirmButton.form) {
            const form = goweb_pendingConfirmButton.form;
            // Tombol submit yang diklik native bawa name/value-nya sendiri ke
            // server — tapi form.submit() programatik gak nyertain itu, jadi
            // kita tiru manual pakai hidden input sebelum submit.
            if (goweb_pendingConfirmButton.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = goweb_pendingConfirmButton.name;
                hidden.value = goweb_pendingConfirmButton.value;
                form.appendChild(hidden);
            }
            form.submit();
        }
    });

    // ---- PAGINATION (sisi browser) ----
    // Data udah ke-load semua dari server; ini cuma ngatur baris mana yang
    // DITAMPILKAN biar halaman gak berat pas datanya banyak. Search/filter
    // (kalau ada) tetap nyari ke SELURUH data, bukan cuma halaman aktif.
    const PAY_PER_PAGE = 25;
    let payCurrentPage = 1;
    const payRows = Array.from(document.querySelectorAll('#payments-rows .payment-row-item'));
    const payPagination = document.getElementById('payments-pagination');
    const payPageInfo = document.getElementById('payments-page-info');
    const payPageNumbers = document.getElementById('payments-page-numbers');
    const payPrev = document.getElementById('payments-prev');
    const payNext = document.getElementById('payments-next');

    function renderPayPage() {
        const totalPages = Math.ceil(payRows.length / PAY_PER_PAGE) || 1;
        if (payCurrentPage > totalPages) payCurrentPage = totalPages;
        const start = (payCurrentPage - 1) * PAY_PER_PAGE;
        const end = start + PAY_PER_PAGE;

        payRows.forEach((row, i) => {
            row.style.display = (i >= start && i < end) ? '' : 'none';
        });

        if (payRows.length <= PAY_PER_PAGE) {
            if (payPagination) payPagination.style.display = 'none';
            return;
        }
        if (payPagination) payPagination.style.display = 'flex';
        if (payPageInfo) payPageInfo.textContent = `Menampilkan ${start + 1}–${Math.min(end, payRows.length)} dari ${payRows.length} data`;
        if (payPrev) { payPrev.disabled = payCurrentPage === 1; payPrev.style.opacity = payCurrentPage === 1 ? '0.4' : '1'; payPrev.style.cursor = payCurrentPage === 1 ? 'not-allowed' : 'pointer'; }
        if (payNext) { payNext.disabled = payCurrentPage === totalPages; payNext.style.opacity = payCurrentPage === totalPages ? '0.4' : '1'; payNext.style.cursor = payCurrentPage === totalPages ? 'not-allowed' : 'pointer'; }

        if (payPageNumbers) {
            payPageNumbers.innerHTML = '';
            // Tampilin maksimal 5 nomor halaman di sekitar halaman aktif,
            // biar gak kepanjangan kalau halamannya puluhan
            let startPage = Math.max(1, payCurrentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            startPage = Math.max(1, endPage - 4);
            for (let p = startPage; p <= endPage; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = p;
                const isActive = p === payCurrentPage;
                btn.style.cssText = `padding: 7px 12px; min-height: 40px; border-radius: 10px; border: 1px solid ${isActive ? 'var(--payments-accent)' : 'var(--payments-line)'}; background: ${isActive ? 'var(--payments-accent)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;`;
                btn.addEventListener('click', () => { payCurrentPage = p; renderPayPage(); });
                payPageNumbers.appendChild(btn);
            }
        }
    }

    if (payPrev) payPrev.addEventListener('click', () => { if (payCurrentPage > 1) { payCurrentPage--; renderPayPage(); } });
    if (payNext) payNext.addEventListener('click', () => { const tp = Math.ceil(payRows.length / PAY_PER_PAGE) || 1; if (payCurrentPage < tp) { payCurrentPage++; renderPayPage(); } });
    if (payRows.length > 0) renderPayPage();
});
</script>