<?php
if (!defined('ABSPATH')) exit;

// Daftar Jenis Pembayaran — sekarang dikelola admin di Pengaturan (sama
// sumbernya dengan "Jenis Tagihan" di Rekapan Tagihan), bukan hardcode lagi.
$payment_category_options = goweb_get_tenant_setting( $store_id, 'payment_categories', goweb_get_default_payment_categories() );
?>

<div class="panel-heading">
    <h3>Form Konfirmasi Pembayaran</h3>
    <p>Kirim bukti transfer setelah melakukan pembayaran pesanan ke <?php echo esc_html($store_name); ?>.</p>
</div>

<?php if (isset($submission_success) && $submission_success): ?>
    <div style="background: var(--pastel-mint-bg); border: 1.5px solid #a7f3d0; color: #065f46; padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 700; font-size: 0.9rem; text-align: center;">
        <i class="fa-solid fa-circle-check"></i> Konfirmasi pembayaran berhasil dikirim! Data akan segera diverifikasi oleh admin.
    </div>
<?php endif; ?>

<div id="form-error-notice" style="display: none; background: #fef2f2; border: 1.5px solid #fecaca; color: #991b1b; padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 700; font-size: 0.88rem; align-items: center; gap: 10px;">
    <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.1rem;"></i> 
    <span>Mohon lengkapi semua data form konfirmasi dan unggah bukti transfer!</span>
</div>

<!-- PERBAIKAN: Action form diarahkan secara eksplisit ke URL saat ini agar tidak kena 405 -->
<form id="public-confirm-form" method="POST" action="<?php echo esc_url(remove_query_arg('tab') . (isset($_GET['tab']) ? '?tab=' . sanitize_text_field($_GET['tab']) : '')); ?>" enctype="multipart/form-data">
    <?php wp_nonce_field('goweb_public_confirm_' . $store_id, 'goweb_public_confirm_nonce'); ?>
    <div class="form-group">
        <label>Jenis Pembayaran</label>
        <select name="payment_type" id="field-payment-type" class="form-control" required>
            <option value="">-- Pilih jenis pembayaran --</option>
            <?php foreach ( $payment_category_options as $cat ): ?>
                <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Nama PO / Referensi (Invoice, Batch, dll)</label>
        <input type="text" name="po_name" id="field-po" class="form-control" placeholder="cth: Batch 3 Genshin Standee" required>
    </div>
    <div class="payment-form-row">
        <div class="form-group">
            <label>Nama Pemesan / Akun</label>
            <input type="text" name="buyer_name" id="field-buyer" class="form-control" placeholder="cth: Devita Auriel" required>
        </div>
        <div class="form-group">
            <label>ID Line / ID Twitter / No WhatsApp</label>
            <input type="text" name="social_id" id="field-social" class="form-control" placeholder="cth: @firstkao" required>
        </div>
    </div>
    <div class="form-group">
        <label>Nominal Transfer (Rp)</label>
        <input type="number" name="nominal" id="field-nominal" class="form-control" placeholder="cth: 150000" required>
    </div>
    <div class="form-group" style="margin-bottom: 24px;">
        <label>Unggah Bukti Pembayaran (Screenshot)</label>
        <input type="file" name="payment_proof" id="field-proof" class="form-control" accept="image/*" required>
    </div>
    
    <button type="button" id="btn-trigger-modal" class="btn-submit">
        <i class="fa-solid fa-paper-plane"></i> Kirim Konfirmasi Pembayaran
    </button>
</form>

<!-- MODAL KONFIRMASI YAKIN -->
<div id="confirmation-notice-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
    <div class="payment-modal-box" style="background: #fff; width: 100%; max-width: 440px; border-radius: var(--radius-lg); box-shadow: 0 10px 25px rgba(0,0,0,0.15); text-align: center; position: relative;">
        <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;"><i class="fa-solid fa-circle-question"></i></div>
        <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Apakah Anda Yakin?</h3>
        <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 24px;">Pastikan data nama PO, nominal, dan bukti transfer yang Anda masukkan sudah benar sebelum dikirim ke admin.</p>
        <div class="payment-modal-actions">
            <button type="button" id="btn-confirm-yes" class="btn-submit" style="flex: 1; background: var(--pastel-mint); box-shadow: none;">Ya, Kirim Sekarang</button>
            <button type="button" id="btn-confirm-no" style="flex: 1; padding: 12px 20px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; cursor: pointer; color: var(--text-muted);">Cek Lagi</button>
        </div>
    </div>
</div>

<style>
/* ===== Field "Nama Pemesan" + "ID Line/Twitter" sejajar di desktop ===== */
.payment-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.payment-modal-box {
    padding: 32px;
}

.payment-modal-actions {
    display: flex;
    gap: 12px;
}

/* ===== RESPONSIVE: HP (di bawah 600px) ===== */
@media (max-width: 600px) {
    .payment-form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .payment-modal-box {
        padding: 24px 20px;
        max-width: calc(100% - 32px) !important;
    }
    .payment-modal-actions {
        flex-direction: column;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const triggerBtn = document.getElementById('btn-trigger-modal');
    const modalOverlay = document.getElementById('confirmation-notice-modal');
    const errorNotice = document.getElementById('form-error-notice');
    const yesBtn = document.getElementById('btn-confirm-yes');
    const noBtn = document.getElementById('btn-confirm-no');
    const targetForm = document.getElementById('public-confirm-form');

    if(triggerBtn && modalOverlay) {
        triggerBtn.addEventListener('click', () => {
            const paymentType = document.getElementById('field-payment-type').value.trim();
            const po = document.getElementById('field-po').value.trim();
            const buyer = document.getElementById('field-buyer').value.trim();
            const nominal = document.getElementById('field-nominal').value.trim();
            const proof = document.getElementById('field-proof').value;

            if(!paymentType || !po || !buyer || !nominal || !proof) {
                errorNotice.style.display = 'flex';
                errorNotice.scrollIntoView({ behavior: 'smooth', block: 'center' });
                modalOverlay.style.display = 'none';
                return;
            }

            errorNotice.style.display = 'none';
            modalOverlay.style.display = 'flex';
        });

        noBtn.addEventListener('click', () => {
            modalOverlay.style.display = 'none';
        });

        yesBtn.addEventListener('click', () => {
            let hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'submit_public_confirmation';
            hiddenInput.value = '1';
            targetForm.appendChild(hiddenInput);
            targetForm.submit();
        });

        modalOverlay.addEventListener('click', (e) => {
            if(e.target === modalOverlay) modalOverlay.style.display = 'none';
        });
    }
});
</script>