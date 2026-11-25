<?php
if (!defined('ABSPATH')) exit;

// Daftar Jenis Pembayaran — sekarang dikelola admin di Pengaturan (sama
// sumbernya dengan "Jenis Tagihan" di Rekapan Tagihan), bukan hardcode lagi.
$payment_category_options = goweb_get_tenant_setting( $store_id, 'payment_categories', goweb_get_default_payment_categories() );
$payment_destination_options = array_values( array_filter( array_map( 'sanitize_text_field', (array) goweb_get_tenant_setting( $store_id, 'payment_destinations', array() ) ) ) );
?>

<div class="public-payment">
    <div class="public-payment-heading">
        <span class="public-payment-module-icon" aria-hidden="true"><i class="fa-solid fa-money-check-dollar"></i></span>
        <div>
            <span class="public-module-eyebrow">PEMBAYARAN PESANAN</span>
            <h3>Konfirmasi Pembayaran</h3>
            <p>Kirim bukti transfer untuk pesanan di <strong><?php echo esc_html($store_name); ?></strong>. Admin akan memeriksa pembayaranmu.</p>
        </div>
    </div>

<?php if (isset($submission_success) && $submission_success): ?>
    <div class="payment-success-notice" role="status" aria-live="polite" tabindex="-1">
        <span class="payment-notice-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
        <span><strong>Konfirmasi berhasil dikirim</strong><small>Data pembayaranmu sudah diterima dan akan segera diverifikasi oleh admin.</small></span>
    </div>
<?php endif; ?>
<?php if ( ! empty( $payment_submission_error ) ): ?>
    <div class="payment-error-notice payment-server-error" role="alert" tabindex="-1">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span><?php echo esc_html( $payment_submission_error ); ?></span>
    </div>
<?php endif; ?>

<div class="public-payment-grid">
    <aside class="payment-help-card">
        <span class="payment-help-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
        <h4>Sebelum mengirim</h4>
        <p>Pastikan informasi pembayaran dan bukti transfer sudah sesuai agar admin dapat memverifikasi pesananmu.</p>
        <ul>
            <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Pilih jenis pembayaran yang sesuai.</span></li>
            <li><i class="fa-solid fa-check" aria-hidden="true"></i><span><?php if ( $has_po ): ?>Buka menu Daftar Pre-order untuk menyalin nama pesanan dan nominal yang tepat.<?php else: ?>Gunakan nama pesanan dan nominal yang tertera pada invoice atau informasi dari admin toko.<?php endif; ?></span></li>
            <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Unggah screenshot bukti transfer yang jelas.</span></li>
        </ul>
        <div class="payment-help-foot"><i class="fa-solid fa-lock" aria-hidden="true"></i> Informasi dikirim secara aman ke admin.</div>
    </aside>

<!-- PERBAIKAN: Action form diarahkan secara eksplisit ke URL saat ini agar tidak kena 405 -->
<form id="public-confirm-form" class="payment-form-card" method="POST" action="<?php echo esc_url(remove_query_arg('tab') . (isset($_GET['tab']) ? '?tab=' . sanitize_text_field($_GET['tab']) : '')); ?>" enctype="multipart/form-data">
    <?php wp_nonce_field('goweb_public_confirm_' . $store_id, 'goweb_public_confirm_nonce'); ?>
    <!-- Honeypot anti-bot (#40): tersembunyi dari manusia; bot pengisi-semua-field terdeteksi -->
    <div style="position: absolute; left: -9999px; top: auto; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">
        <label for="pay-website-trap">Website</label>
        <input type="text" name="website_url_trap" id="pay-website-trap" tabindex="-1" autocomplete="off">
    </div>
    <div class="payment-form-topline"><span><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Detail pembayaran</span><small>Semua kolom wajib diisi</small></div>
    <div class="form-group">
        <label for="field-payment-type">Jenis Pembayaran</label>
        <select name="payment_type" id="field-payment-type" class="form-control" required>
            <option value="">-- Pilih jenis pembayaran --</option>
            <?php foreach ( $payment_category_options as $cat ): ?>
                <option value="<?php echo esc_attr($cat); ?>"<?php selected( $payment_form_values['payment_type'], $cat ); ?>><?php echo esc_html($cat); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="field-payment-destination">Metode Pembayaran</label>
        <?php if ( ! empty( $payment_destination_options ) ): ?>
            <select name="payment_destination" id="field-payment-destination" class="form-control" required>
                <option value="">-- Pilih metode pembayaran --</option>
                <?php foreach ( $payment_destination_options as $destination ): ?>
                    <option value="<?php echo esc_attr($destination); ?>"<?php selected( $payment_form_values['payment_destination'], $destination ); ?>><?php echo esc_html($destination); ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <p class="payment-field-hint payment-destination-unconfigured">Toko ini belum mengatur metode pembayaran. Silakan hubungi admin toko.</p>
        <?php endif; ?>
    </div>
    <div class="form-group">
        <label for="field-po">Nama pre-order / referensi (invoice atau batch)</label>
        <input type="text" name="po_name" id="field-po" class="form-control" placeholder="cth: Batch 3 Genshin Standee" value="<?php echo esc_attr( $payment_form_values['po_name'] ); ?>" required>
    </div>
    <div class="payment-form-row">
        <div class="form-group">
            <label for="field-buyer">Nama pemesan (sesuai nama pada pesanan)</label>
            <input type="text" name="buyer_name" id="field-buyer" class="form-control" placeholder="cth: Devita Auriel" value="<?php echo esc_attr( $payment_form_values['buyer_name'] ); ?>" required>
        </div>
        <div class="form-group">
            <label for="field-social">Kontak aktif (LINE / X / WhatsApp)</label>
            <input type="text" name="social_id" id="field-social" class="form-control" placeholder="cth: @username atau 0812-3456-7890" value="<?php echo esc_attr( $payment_form_values['social_id'] ); ?>" required>
            <small class="payment-field-hint">Isi satu kontak yang bisa dihubungi admin.</small>
        </div>
    </div>
    <div class="form-group">
        <label for="field-nominal">Nominal transfer (Rp)</label>
        <input type="number" name="nominal" id="field-nominal" class="form-control" placeholder="cth: 150000" value="<?php echo esc_attr( $payment_form_values['nominal'] ); ?>" min="1" required>
    </div>
    <div class="form-group payment-proof-group">
        <label for="field-proof">Unggah Bukti Pembayaran (Screenshot)</label>
        <input type="file" name="payment_proof" id="field-proof" class="form-control payment-proof-input" accept="image/*" required>
        <small class="payment-field-hint">Format gambar JPG, PNG, GIF, atau WebP. Pastikan nominal dan status transfer terlihat.</small>
    </div>

    <button type="button" id="btn-trigger-modal" class="btn-submit payment-submit-button" <?php echo empty($payment_destination_options) ? 'disabled' : ''; ?> title="<?php echo empty($payment_destination_options) ? esc_attr('Toko belum mengatur metode pembayaran.') : ''; ?>">
        <i class="fa-solid fa-paper-plane"></i> Kirim Konfirmasi Pembayaran
    </button>
</form>
</div>

<!-- MODAL KONFIRMASI YAKIN -->
<dialog id="confirmation-notice-modal" class="payment-confirm-dialog" aria-labelledby="payment-confirm-title" aria-describedby="payment-confirm-description">
    <div class="payment-modal-box">
        <div class="payment-modal-icon"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></div>
        <h3 id="payment-confirm-title">Periksa sebelum dikirim</h3>
        <p id="payment-confirm-description">Pastikan nama PO, nama pemesan, kontak, nominal, dan bukti transfer sudah benar. Konfirmasi akan dikirim ke admin untuk diverifikasi.</p>
        <div class="payment-modal-actions">
            <button type="button" id="btn-confirm-yes" class="btn-submit" style="flex: 1; background: var(--pastel-mint); box-shadow: none;">Ya, Kirim Sekarang</button>
            <button type="button" id="btn-confirm-no" autofocus style="flex: 1; padding: 12px 20px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; cursor: pointer; color: var(--text-muted);">Cek Lagi</button>
        </div>
    </div>
</dialog>
</div>

<style>
.public-payment .payment-form-card .form-control:not(select) { border: 1px solid var(--pay-line) !important; border-radius: 10px !important; background: #fff !important; color: var(--pay-ink) !important; }
.public-payment .payment-form-card select.form-control { border: 1px solid var(--pay-line) !important; border-radius: 10px !important; background-color: #fff !important; color: var(--pay-ink) !important; }
.public-payment .payment-form-card select.form-control:focus, .public-payment .payment-form-card input.form-control:focus { border-color: #9c8ab8 !important; box-shadow: 0 0 0 3px rgba(109,90,145,.12) !important; }
.public-payment .payment-form-card input[type="file"].form-control { background: #faf9fc !important; }
.public-payment {
    --pay-ink: #302b46;
    --pay-muted: #777188;
    --pay-line: #e5e0f2;
    --pay-accent: #6d5a91;
    --pay-accent-soft: #f0ebf7;
    color: var(--pay-ink);
}
.public-payment-heading { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
.public-payment-module-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--pay-accent);
    font-size: 1.08rem;
}
.public-module-eyebrow { display: block; margin-bottom: 3px; color: var(--pay-accent); font-size: .65rem; font-weight: 800; letter-spacing: .09em; }
.public-payment-heading h3 { margin: 0 0 4px; color: var(--pay-ink); font-size: clamp(1.25rem, 2vw, 1.5rem); font-weight: 800; line-height: 1.25; letter-spacing: -.03em; }
.public-payment-heading p { margin: 0; color: var(--pay-muted); font-size: .84rem; line-height: 1.55; }
.payment-success-notice,
.payment-error-notice { display: flex; align-items: center; gap: 12px; margin: 0 0 18px; padding: 14px 16px; border: 1px solid; border-radius: 13px; font-size: .82rem; line-height: 1.5; }
.payment-success-notice { border-color: #d6e5d8; background: #edf4ee; color: #45634d; }
.payment-error-notice { border-color: #f0d8d6; background: #fbf1ef; color: #965a58; font-weight: 700; }
.payment-notice-icon { display: grid; flex: 0 0 36px; width: 36px; height: 36px; place-items: center; border-radius: 11px; background: #dfece1; color: #62816a; font-size: 1rem; }
.payment-success-notice > span:last-child { display: flex; flex-direction: column; gap: 2px; }
.payment-success-notice strong { color: #45634d; font-size: .85rem; }
.payment-success-notice small { color: #66816d; font-size: .74rem; }
.payment-error-notice > i { flex: 0 0 auto; font-size: 1.05rem; }
.public-payment-grid { display: grid; grid-template-columns: minmax(210px, .72fr) minmax(0, 1.45fr); align-items: start; gap: 18px; }
.payment-help-card { padding: 22px 20px; border: 1px solid #e5e0f2; border-radius: 16px; background: linear-gradient(155deg, #faf8fc, #f4f0f8); }
.payment-help-icon { display: grid; width: 40px; height: 40px; margin-bottom: 14px; place-items: center; border: 1px solid #ded7e9; border-radius: 12px; background: #fff; color: var(--pay-accent); }
.payment-help-card h4 { margin: 0 0 6px; color: var(--pay-ink); font-size: .95rem; font-weight: 800; }
.payment-help-card > p { margin: 0; color: var(--pay-muted); font-size: .77rem; line-height: 1.6; }
.payment-help-card ul { display: grid; gap: 12px; margin: 19px 0; padding: 0; list-style: none; }
.payment-help-card li { display: flex; align-items: flex-start; gap: 9px; color: #514b63; font-size: .75rem; line-height: 1.5; }
.payment-help-card li > i { flex: 0 0 16px; margin-top: 2px; color: #6f9278; font-size: .7rem; }
.payment-help-foot { display: flex; align-items: flex-start; gap: 7px; padding-top: 13px; border-top: 1px solid #e4deec; color: #6d6680; font-size: .68rem; font-weight: 650; line-height: 1.45; }
.payment-help-foot i { margin-top: 2px; color: var(--pay-accent); }
.payment-form-card { min-width: 0; padding: 22px; border: 1px solid var(--pay-line); border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(59,48,95,.045); }
.payment-form-topline { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 17px; padding-bottom: 12px; border-bottom: 1px solid #efecf3; }
.payment-form-topline > span { display: flex; align-items: center; gap: 8px; color: var(--pay-ink); font-size: .83rem; font-weight: 800; }
.payment-form-topline > span i { color: var(--pay-accent); }
.payment-form-topline small { color: var(--pay-muted); font-size: .66rem; }
.payment-form-card .form-group { margin-bottom: 14px; }
.payment-form-card .form-group label { color: #514b63; font-size: .73rem; font-weight: 750; }
.payment-form-card .form-control { min-height: 43px; border-color: var(--pay-line); border-radius: 10px; font-size: .83rem; }
.payment-form-card .form-control:focus { border-color: #9c8ab8; box-shadow: 0 0 0 3px rgba(109,90,145,.12); }
.payment-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 13px; }
.payment-proof-group { margin-bottom: 18px !important; }
.payment-form-card .payment-proof-input { min-height: auto; padding: 9px; background: #faf9fc; font-size: .75rem; }
.payment-proof-input::file-selector-button { margin: -3px 10px -3px -3px; padding: 8px 11px; border: 1px solid #ded7e9; border-radius: 8px; background: #fff; color: #594a75; font: inherit; font-size: .72rem; font-weight: 750; cursor: pointer; }
.payment-field-hint { display: block; margin-top: 6px; color: var(--pay-muted); font-size: .67rem; line-height: 1.45; }
.payment-form-card .payment-submit-button { min-height: 46px; background: linear-gradient(135deg, #6d5a91, #51416f); }
.payment-form-card .payment-submit-button:disabled { opacity: .55; cursor: not-allowed; }
.payment-modal-box { padding: 30px; border: 1px solid var(--pay-line); background: #fff; width: 100%; max-width: 440px; border-radius: var(--radius-lg); box-shadow: 0 20px 55px rgba(36,31,52,.24); text-align: center; position: relative; }
.payment-confirm-dialog { width: min(440px, calc(100% - 32px)); max-width: none; max-height: calc(100% - 32px); margin: auto; padding: 0; border: 0; background: transparent; overflow: visible; }
.payment-confirm-dialog::backdrop { background: rgba(36, 31, 52, .58); backdrop-filter: blur(5px); }
.payment-confirm-dialog[open] { animation: payment-dialog-in .16s ease-out; }
@keyframes payment-dialog-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .payment-confirm-dialog[open] { animation: none; } }
.payment-modal-icon { margin-bottom: 12px; color: #94733b; font-size: 2.2rem; }
.payment-modal-box h3 { margin: 0 0 7px; color: var(--pay-ink); font-size: 1.16rem; font-weight: 800; }
.payment-modal-box > p { margin: 0 0 22px; color: var(--pay-muted); font-size: .82rem; line-height: 1.6; }
.payment-modal-actions { display: flex; gap: 10px; }
.payment-modal-actions > button { min-height: 44px; padding: 10px 13px !important; border-radius: 10px !important; font-size: .8rem; }
.payment-modal-actions > #btn-confirm-yes { background: #6f9278 !important; }
.payment-modal-actions > #btn-confirm-no { border-color: var(--pay-line) !important; color: #625d70 !important; }
.public-payment button:focus-visible { outline: 3px solid rgba(157,138,184,.42); outline-offset: 2px; }
@media (max-width: 760px) {
    .public-payment-grid { grid-template-columns: minmax(0, 1fr); gap: 13px; }
    .payment-help-card { padding: 18px; }
    .payment-help-card ul { grid-template-columns: 1fr 1fr; column-gap: 14px; }
    .payment-form-card { padding: 19px; }
}
@media (max-width: 600px) {
    .public-payment-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .public-payment-heading p { font-size: .79rem; }
    .public-payment-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .payment-help-card { padding: 16px; border-radius: 14px; }
    .payment-help-icon { width: 36px; height: 36px; margin-bottom: 10px; border-radius: 11px; }
    .payment-help-card ul { grid-template-columns: 1fr; gap: 9px; margin: 14px 0; }
    .payment-form-card { padding: 16px 14px; border-radius: 14px; }
    .payment-form-topline { align-items: flex-start; flex-direction: column; gap: 3px; }
    .payment-form-row { grid-template-columns: 1fr; gap: 0; }
    .payment-modal-box { padding: 24px 20px; max-width: 100% !important; border-radius: 17px !important; }
    .payment-modal-actions { flex-direction: column; }
}
</style>

<script>
(() => {
    const triggerBtn = document.getElementById('btn-trigger-modal');
    const confirmDialog = document.getElementById('confirmation-notice-modal');
    const yesBtn = document.getElementById('btn-confirm-yes');
    const noBtn = document.getElementById('btn-confirm-no');
    const targetForm = document.getElementById('public-confirm-form');

    if (!triggerBtn || !confirmDialog || !targetForm) return;

    const closeDialog = () => {
        if (confirmDialog.open) confirmDialog.close();
        if (triggerBtn.isConnected) triggerBtn.focus();
    };

    const submitConfirmed = () => {
        const submitter = document.createElement('button');
        submitter.type = 'submit';
        submitter.name = 'submit_public_confirmation';
        submitter.value = '1';
        submitter.hidden = true;
        targetForm.appendChild(submitter);
        if (typeof targetForm.requestSubmit === 'function') targetForm.requestSubmit(submitter);
        else submitter.click();
    };

    triggerBtn.addEventListener('click', () => {
        if (!targetForm.checkValidity()) {
            targetForm.reportValidity();
            return;
        }
        if (typeof confirmDialog.showModal === 'function') {
            confirmDialog.showModal();
        } else if (window.confirm('Pastikan semua data dan bukti transfer sudah benar. Kirim konfirmasi pembayaran sekarang?')) {
            // Fallback browser lama tetap menjalankan validasi native dan
            // mengirimkan penanda handler yang sama.
            submitConfirmed();
        }
    });

    noBtn.addEventListener('click', closeDialog);
    confirmDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeDialog();
    });
    confirmDialog.addEventListener('close', () => {
        if (triggerBtn.isConnected) triggerBtn.focus();
    });
    confirmDialog.addEventListener('click', (event) => {
        if (event.target === confirmDialog) closeDialog();
    });

    yesBtn.addEventListener('click', () => {
        if (!targetForm.checkValidity()) {
            closeDialog();
            targetForm.reportValidity();
            return;
        }
        submitConfirmed();
    });

    const serverError = document.querySelector('.payment-server-error');
    const serverSuccess = document.querySelector('.payment-success-notice');
    const statusNotice = serverError || serverSuccess;
    if (statusNotice) {
        statusNotice.focus({ preventScroll: true });
        statusNotice.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    }
})();
</script>