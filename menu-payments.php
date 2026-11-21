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
        return '<span style="display:inline-block; padding:3px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; background:#f1f5f9; color:#64748b;">—</span>';
    }
    $c = goweb_category_badge_color( $type );
    return '<span style="display:inline-block; padding:3px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; background:' . $c['bg'] . '; color:' . $c['color'] . ';">' . esc_html( goweb_invoice_category_label( $type ) ) . '</span>';
}
?>
<div style="margin-bottom: 24px;">
    <h2>Pembayaran Pelanggan</h2>
    <p style="color: var(--text-muted); font-size: 0.85rem;">Daftar konfirmasi transfer dari pelanggan yang masuk melalui web.</p>
</div>

<div class="card-table-wrap">
    <div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Invoice</th>
                <th>Jenis</th>
                <th>Pemesan</th>
                <th>Nominal</th>
                <th>Bukti</th>
                <th>Status</th>
                <th style="text-align: center;">Aksi</th>
            </tr>
        </thead>
        <tbody id="payments-rows">
            <?php
            $po_payments = array_filter( $subscription_history, function( $r ) { return $r['payment_category'] === 'po'; } );
            if ( ! empty( $po_payments ) ): foreach ( $po_payments as $row ):
                $is_verified = ( isset($row['status']) && $row['status'] == 'Verified' );
                $row_payment_type = isset($row['payment_type']) ? $row['payment_type'] : '';
            ?>
                <tr class="payment-row-item">
                    <td style="color: var(--slate-500); font-size: 12.5px;"><?php echo esc_html( date('d/m/Y H:i', strtotime($row['created_at'])) ); ?></td>
                    <td><strong><?php echo esc_html($row['period']); ?></strong></td>
                    <td><?php echo goweb_payment_type_badge( $row_payment_type ); ?></td>
                    <td><?php echo esc_html($row['sender']); ?></td>
                    <td><strong>Rp <?php echo number_format(intval($row['nominal']), 0, ',', '.'); ?></strong></td>
                    <td>
                        <?php if (!empty($row['proof_url'])): ?>
                            <a href="<?php echo esc_url($row['proof_url']); ?>" target="_blank" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Lihat Bukti</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_verified): ?>
                            <span class="status-badge badge-mint">Verified</span>
                        <?php else: ?>
                            <span class="status-badge badge-peach">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if (!$is_verified): ?>
                            <form method="POST" action="<?php echo esc_url( home_url('/dashboard/payments/') . $store_query_suffix ); ?>" style="display:inline;">
                                <?php wp_nonce_field('goweb_payment_action_' . $store_id, 'goweb_payment_nonce'); ?>
                                <input type="hidden" name="index" value="<?php echo esc_attr($row['id']); ?>">
                                <button type="submit" name="goweb_payment_action" value="verify" class="btn-submit" style="padding: 6px 14px; font-size: 0.75rem; border: none; cursor: pointer;">Verifikasi</button>
                                <button type="submit" name="goweb_payment_action" value="delete" onclick="return goweb_confirmSubmit(this, 'Hapus data pembayaran ini?');" style="padding: 6px 10px; font-size: 0.75rem; background:#fee2e2; color:#ef4444; border:none; border-radius:6px; cursor:pointer;">✕</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="<?php echo esc_url( home_url('/dashboard/payments/') . $store_query_suffix ); ?>" style="display:inline; display:flex; align-items:center; gap:8px; justify-content:center;">
                                <?php wp_nonce_field('goweb_payment_action_' . $store_id, 'goweb_payment_nonce'); ?>
                                <input type="hidden" name="index" value="<?php echo esc_attr($row['id']); ?>">
                                <span style="font-size: 0.78rem; color: var(--slate-400); font-weight: 700;">Selesai</span>
                                <button type="submit" name="goweb_payment_action" value="delete" onclick="return goweb_confirmSubmit(this, 'Hapus data pembayaran yang udah Verified ini? Transaksi ini udah selesai, hapus buat bebasin storage.');" title="Hapus (bebasin storage)" style="padding: 4px 9px; font-size: 0.75rem; background:#fee2e2; color:#ef4444; border:none; border-radius:6px; cursor:pointer;">✕</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" style="text-align:center; color: var(--slate-400); padding: 30px;">Belum ada konfirmasi pembayaran dari pelanggan.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <!-- KONTROL PAGINATION -->
    <div id="payments-pagination" style="display: none; justify-content: space-between; align-items: center; padding: 14px 4px 2px; flex-wrap: wrap; gap: 10px;">
        <span id="payments-page-info" style="font-size: 12.5px; color: var(--slate-500, #64748b); font-weight: 600;"></span>
        <div style="display: flex; gap: 6px; align-items: center;">
            <button type="button" id="payments-prev" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft, #e2e8f0); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">‹ Sebelumnya</button>
            <span id="payments-page-numbers" style="display: flex; gap: 4px;"></span>
            <button type="button" id="payments-next" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid var(--border-soft, #e2e8f0); background: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;">Berikutnya ›</button>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI GENERIC (gantiin confirm() bawaan browser) -->
<div class="modal-overlay" id="genericConfirmModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;">
    <div style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
        <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;"><i class="fa-solid fa-circle-question"></i></div>
        <p id="genericConfirmMessage" style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 24px;"></p>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="genericConfirmCancel" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; font-weight: 700; color: #64748b; cursor: pointer;">Batal</button>
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
                btn.style.cssText = `padding: 7px 12px; border-radius: 8px; border: 1.5px solid ${isActive ? 'var(--pastel-purple, #8b5cf6)' : 'var(--border-soft, #e2e8f0)'}; background: ${isActive ? 'var(--pastel-purple, #8b5cf6)' : '#fff'}; color: ${isActive ? '#fff' : 'inherit'}; font-weight: 700; font-size: 12.5px; cursor: pointer;`;
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