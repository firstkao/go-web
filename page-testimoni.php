<?php
if (!defined('ABSPATH')) exit;

// PANEL TESTIMONI PUBLIK — sisi BUYER. Pengiriman testimoni diproses di
// tenant-web.php (validasi server-side lengkap di sana, pola sama dengan
// form pembayaran publik). File ini cuma nampilin: daftar testimoni yang
// SUDAH di-approve admin tenant + form pengiriman (masuk sebagai Pending).
$approved_testi = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$testimonials_table} WHERE store_id = %s AND status = 'Approved' ORDER BY created_at DESC, id DESC",
    $store_id
), ARRAY_A );
?>

<div class="panel-heading">
    <h3>Testimoni Pembeli</h3>
    <p>Lihat pengalaman pembeli lain, atau bagikan pengalamanmu berbelanja di toko ini.</p>
</div>

<?php if ( ! empty($testi_success) ): ?>
    <div style="background: #ecfdf5; color: #059669; padding: 14px 18px; border-radius: var(--radius-md); font-weight: 700; margin-bottom: 20px; border: 1px solid #a7f3d0;">
        ✓ Terima kasih! Testimonimu sudah terkirim dan menunggu persetujuan admin toko sebelum tampil di sini.
    </div>
<?php endif; ?>

<?php if ( empty($approved_testi) ): ?>
    <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); text-align: center; padding: 40px; color: var(--text-muted); margin-bottom: 24px;">
        <div style="font-size: 2rem; margin-bottom: 8px;">💬</div>
        <strong>Belum ada testimoni yang tampil.</strong>
        <p style="font-size: 0.8rem; margin-top: 4px;">Jadilah yang pertama menulis pengalamanmu di bawah!</p>
    </div>
<?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
        <?php foreach ( $approved_testi as $at ):
            $at_rating = max( 1, min( 5, intval( $at['rating'] ) ) ); ?>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: 16px 18px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
                    <div>
                        <strong style="color: var(--text-dark);"><?php echo esc_html( $at['buyer_name'] ); ?></strong>
                        <span style="color: #f59e0b; letter-spacing: 1px; margin-left: 8px;"><?php echo str_repeat( '★', $at_rating ) . str_repeat( '☆', 5 - $at_rating ); ?></span>
                    </div>
                    <span style="font-size: 11px; color: var(--text-muted);"><?php echo $at['created_at'] ? esc_html( date('d M Y', strtotime($at['created_at'])) ) : ''; ?></span>
                </div>
                <p style="font-size: 0.9rem; color: var(--text-dark); white-space: pre-line; margin: 8px 0 0;"><?php echo esc_html( $at['message'] ); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- FORM KIRIM TESTIMONI — status awal Pending, dimoderasi admin tenant -->
<div style="background: #faf8ff; border: 1.5px dashed #c4b5fd; border-radius: var(--radius-md); padding: 20px;">
    <h4 style="margin: 0 0 12px; color: var(--text-dark);">✍️ Tulis Testimonimu</h4>
    <form method="POST" action="<?php echo esc_url( add_query_arg('tab', 'testimoni', $base_url) ); ?>">
        <?php wp_nonce_field( 'goweb_public_testi_' . $store_id, 'goweb_public_testi_nonce' ); ?>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;">
            <div style="flex: 1; min-width: 200px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 4px;">Nama Kamu</label>
                <input type="text" name="testi_name" id="field-testi-name" class="form-control" maxlength="100" required>
            </div>
            <div style="width: 160px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 4px;">Rating</label>
                <select name="testi_rating" id="field-testi-rating" class="form-control" required>
                    <option value="5">★★★★★ (5)</option>
                    <option value="4">★★★★☆ (4)</option>
                    <option value="3">★★★☆☆ (3)</option>
                    <option value="2">★★☆☆☆ (2)</option>
                    <option value="1">★☆☆☆☆ (1)</option>
                </select>
            </div>
        </div>
        <div style="margin-bottom: 12px;">
            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 4px;">Pengalamanmu</label>
            <textarea name="testi_message" id="field-testi-message" class="form-control" rows="4" maxlength="1000" required placeholder="Ceritakan pengalamanmu berbelanja di toko ini..."></textarea>
        </div>
        <button type="submit" name="submit_public_testimonial" value="1" style="padding: 10px 20px; border-radius: 10px; border: none; background: var(--accent-gradient); color: #fff; font-weight: 800; cursor: pointer;">Kirim Testimoni</button>
        <p style="font-size: 0.72rem; color: var(--text-muted); margin: 8px 0 0;">Testimoni akan ditampilkan setelah disetujui admin toko.</p>
    </form>
</div>
