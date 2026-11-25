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

// Pagination server-side: 6 testimoni per halaman (2 kolom x 3 baris).
// Query tetap ambil semua approved (dataset kecil), pemenggalan di sini.
$testi_per_page = 6;
$testi_total = count( $approved_testi );
$testi_total_pages = max( 1, (int) ceil( $testi_total / $testi_per_page ) );
$testi_page = isset($_GET['tpage']) ? intval( $_GET['tpage'] ) : 1;
if ( $testi_page < 1 || $testi_page > $testi_total_pages ) $testi_page = 1;
$testi_page_items = array_slice( $approved_testi, ( $testi_page - 1 ) * $testi_per_page, $testi_per_page );
$testi_page_base = add_query_arg( 'tab', 'testimoni', $base_url );
?>

<div class="public-testi">
    <div class="public-testi-heading">
        <span class="public-testi-module-icon" aria-hidden="true"><i class="fa-solid fa-quote-left"></i></span>
        <div>
            <span class="public-testi-eyebrow">PENGALAMAN PEMBELI</span>
            <h3>Testimoni Pembeli</h3>
            <p>Lihat cerita pembeli lain di <strong><?php echo esc_html($store_name); ?></strong>, atau bagikan pengalamanmu. Testimoni tampil setelah disetujui admin.</p>
        </div>
    </div>

<?php if ( ! empty($testi_success) ): ?>
    <div class="testi-status testi-success" role="status" aria-live="polite" tabindex="-1">
        <span class="testi-notice-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
        <span class="testi-notice-body"><strong>Testimoni terkirim</strong><small>Terima kasih! Testimonimu menunggu persetujuan admin sebelum tampil di sini.</small></span>
    </div>
<?php endif; ?>
<?php if ( ! empty( $testi_submission_error ) ): ?>
    <div class="testi-status testi-error" role="alert" tabindex="-1">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span><?php echo esc_html( $testi_submission_error ); ?></span>
    </div>
<?php endif; ?>

<div class="public-testi-grid">
    <!-- ATAS: DAFTAR TESTIMONI APPROVED (2 kolom) -->
    <div class="testi-list" aria-live="polite">
        <?php if ( empty($testi_page_items) ): ?>
            <div class="testi-empty">
                <span class="testi-empty-icon" aria-hidden="true"><i class="fa-regular fa-comment-dots"></i></span>
                <strong>Belum ada testimoni yang tampil.</strong>
                <p>Jadilah yang pertama menulis pengalamanmu lewat form Tulis Testimoni di bawah.</p>
            </div>
        <?php else: ?>
            <?php foreach ( $testi_page_items as $at ):
                $at_rating = max( 1, min( 5, intval( $at['rating'] ) ) ); ?>
                <article class="testi-item">
                    <div class="testi-item-head">
                        <span class="testi-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( trim( $at['buyer_name'] ), 0, 1 ) ) ?: '?' ); ?></span>
                        <div class="testi-item-id">
                            <strong><?php echo esc_html( $at['buyer_name'] ); ?></strong>
                            <span class="testi-stars" aria-label="Rating <?php echo (int) $at_rating; ?> dari 5 bintang"><?php echo esc_html( str_repeat( '★', $at_rating ) . str_repeat( '☆', 5 - $at_rating ) ); ?></span>
                        </div>
                        <span class="testi-item-date"><?php echo $at['created_at'] ? esc_html( date('d M Y', strtotime($at['created_at'])) ) : ''; ?></span>
                    </div>
                    <p class="testi-item-msg"><?php echo esc_html( $at['message'] ); ?></p>
                    <?php if ( ! empty( $at['photo_url'] ) ): ?>
                        <img class="approved-testimonial-photo" src="<?php echo esc_url( $at['photo_url'] ); ?>" alt="Foto dari testimoni <?php echo esc_attr( $at['buyer_name'] ); ?>" loading="lazy" decoding="async">
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ( $testi_total_pages > 1 ): ?>
        <nav class="testi-pagination" aria-label="Navigasi halaman testimoni">
            <?php if ( $testi_page > 1 ): ?>
                <a class="testi-page-btn" href="<?php echo esc_url( add_query_arg( 'tpage', $testi_page - 1, $testi_page_base ) ); ?>" rel="prev">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Sebelumnya
                </a>
            <?php else: ?>
                <span class="testi-page-btn is-disabled" aria-disabled="true"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Sebelumnya</span>
            <?php endif; ?>
            <span class="testi-page-info" role="status"><?php echo (int) $testi_page; ?> dari <?php echo (int) $testi_total_pages; ?> halaman · <?php echo (int) $testi_total; ?> testimoni</span>
            <?php if ( $testi_page < $testi_total_pages ): ?>
                <a class="testi-page-btn" href="<?php echo esc_url( add_query_arg( 'tpage', $testi_page + 1, $testi_page_base ) ); ?>" rel="next">
                    Berikutnya <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <span class="testi-page-btn is-disabled" aria-disabled="true">Berikutnya <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <!-- BAWAH: FORM KIRIM TESTIMONI (Pending, dimoderasi admin tenant) + KARTU INFO -->
    <div class="testi-bottom">
        <form class="testi-form-card" method="POST" action="<?php echo esc_url( add_query_arg('tab', 'testimoni', $base_url) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'goweb_public_testi_' . $store_id, 'goweb_public_testi_nonce' ); ?>
            <!-- Honeypot anti-bot (#40) -->
            <div style="position: absolute; left: -9999px; top: auto; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">
                <label for="testi-website-trap">Website</label>
                <input type="text" name="website_url_trap" id="testi-website-trap" tabindex="-1" autocomplete="off">
            </div>
            <div class="testi-form-topline"><span><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Tulis Testimoni</span><small>Dimoderasi admin</small></div>
            <div class="testi-form-row">
                <div class="form-group">
                    <label for="field-testi-name">Nama Anda</label>
                    <input type="text" name="testi_name" id="field-testi-name" class="form-control" maxlength="100" placeholder="cth: Devita Auriel" value="<?php echo esc_attr( $testi_form_values['name'] ); ?>" autocomplete="name" required>
                </div>
                <div class="form-group">
                    <label for="field-testi-rating">Rating</label>
                    <select name="testi_rating" id="field-testi-rating" class="form-control" required>
                        <option value=""<?php selected( $testi_form_values['rating'], 0 ); ?>>-- Pilih --</option>
                        <option value="5"<?php selected( $testi_form_values['rating'], 5 ); ?>>★★★★★ (5)</option>
                        <option value="4"<?php selected( $testi_form_values['rating'], 4 ); ?>>★★★★☆ (4)</option>
                        <option value="3"<?php selected( $testi_form_values['rating'], 3 ); ?>>★★★☆☆ (3)</option>
                        <option value="2"<?php selected( $testi_form_values['rating'], 2 ); ?>>★★☆☆☆ (2)</option>
                        <option value="1"<?php selected( $testi_form_values['rating'], 1 ); ?>>★☆☆☆☆ (1)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="field-testi-message">Pengalaman Anda</label>
                <textarea name="testi_message" id="field-testi-message" class="form-control" rows="4" maxlength="1000" required placeholder="Ceritakan pengalaman berbelanja disini..."><?php echo esc_textarea( $testi_form_values['message'] ); ?></textarea>
            </div>
            <div class="testi-photo-field">
                <label for="field-testi-photo">Foto yang ingin diberikan (opsional)</label>
                <input type="file" name="testi_photo" id="field-testi-photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="testi-photo-help testi-photo-status">
                <small id="testi-photo-help">Foto JPG, PNG, atau WebP; maksimal 2 MiB. Video tidak didukung.</small>
                <p id="testi-photo-status" class="testi-photo-status" role="status" aria-live="polite"></p>
                <div class="testi-photo-preview" id="testi-photo-preview" hidden>
                    <img id="testi-photo-preview-image" alt="Pratinjau foto yang dipilih">
                    <button type="button" id="testi-photo-remove" aria-label="Hapus foto yang dipilih">Hapus foto</button>
                </div>
            </div>
            <button type="submit" name="submit_public_testimonial" value="1" class="testi-submit-button">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Kirim Testimoni
            </button>
            <p class="testi-form-hint">Maksimal 100 karakter untuk nama dan 1.000 karakter untuk testimoni.</p>
        </form>

        <aside class="testi-help-card">
            <span class="testi-help-icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
            <h4>Sebelum menulis</h4>
            <p>Bantu admin memverifikasi pengalamanmu dengan isian yang jujur dan jelas.</p>
            <ul>
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Testimoni tampil publik setelah disetujui admin.</span></li>
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Gunakan nama yang sama dengan pesananmu.</span></li>
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Foto opsional — JPG, PNG, atau WebP maksimal 2 MiB.</span></li>
            </ul>
            <div class="testi-help-foot"><i class="fa-solid fa-lock" aria-hidden="true"></i> Testimoni menunggu dulu sebagai Pending, tidak langsung tampil.</div>
        </aside>
    </div>
</div>
</div>
</div>

<style>
.public-testi {
    --testi-ink: #302b46;
    --testi-muted: #777188;
    --testi-line: #e5e0f2;
    --testi-accent: #6d5a91;
    --testi-accent-soft: #f0ebf7;
    color: var(--testi-ink);
}
.public-testi-heading { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
.public-testi-module-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--testi-accent);
    font-size: 1.08rem;
}
.public-testi-eyebrow { display: block; margin-bottom: 3px; color: var(--testi-accent); font-size: .65rem; font-weight: 800; letter-spacing: .09em; }
.public-testi-heading h3 { margin: 0 0 4px; color: var(--testi-ink); font-size: clamp(1.25rem, 2vw, 1.5rem); font-weight: 800; line-height: 1.25; letter-spacing: -.03em; }
.public-testi-heading p { margin: 0; color: var(--testi-muted); font-size: .84rem; line-height: 1.55; }

/* Notice kirim ulang sukses/gagal — pola sama dengan notice pembayaran */
.testi-status { display: flex; align-items: center; gap: 12px; margin: 0 0 18px; padding: 14px 16px; border: 1px solid; border-radius: 13px; font-size: .82rem; line-height: 1.5; }
.testi-success { border-color: #d6e5d8; background: #edf4ee; color: #45634d; }
.testi-error { border-color: #f0d8d6; background: #fbf1ef; color: #965a58; font-weight: 700; }
.testi-notice-icon { display: grid; flex: 0 0 36px; width: 36px; height: 36px; place-items: center; border-radius: 11px; background: #dfece1; color: #62816a; font-size: 1rem; }
.testi-notice-body { display: flex; flex-direction: column; gap: 2px; }
.testi-notice-body strong { color: #45634d; font-size: .85rem; }
.testi-notice-body small { color: #66816d; font-size: .74rem; }
.testi-error > i { flex: 0 0 auto; font-size: 1.05rem; }
.testi-status:focus { outline: 3px solid rgba(109,90,145,.28); outline-offset: 2px; }

/* Layout: daftar testimoni 2 kolom di atas; form + info di bawah */
.public-testi-grid { display: grid; align-items: start; }
/* Grid 2 kolom; JS mendistribusikan kartu ke dua kolom berdasarkan tinggi
   terukur (masonry) supaya kartu ber-gambar tidak menyisakan void.
   Tanpa JS: grid biasa (fallback row-aligned). */
.public-testi-grid { display: grid; align-items: start; }
.testi-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: start; min-width: 0; }
.testi-col { display: grid; gap: 12px; align-content: start; min-width: 0; }
.testi-bottom { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(210px, .72fr); align-items: start; gap: 18px; margin-top: 18px; }
.testi-bottom .testi-form-card { min-width: 0; }
.testi-bottom .testi-help-card { min-width: 0; }

.testi-item { min-width: 0; padding: 18px 20px; border: 1px solid var(--testi-line); border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(59,48,95,.045); }
.testi-item-head { display: flex; align-items: center; gap: 11px; }
.testi-avatar { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border: 1px solid #ded7e9; border-radius: 50%; background: linear-gradient(145deg, #f8f5fb, #f0ebf7); color: var(--testi-accent); font-size: .92rem; font-weight: 800; }
.testi-item-id { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.testi-item-id strong { color: var(--testi-ink); font-size: .88rem; }
.testi-stars { color: #b48a3c; font-size: .78rem; letter-spacing: .1em; }
.testi-item-date { flex: 0 0 auto; margin-left: auto; color: var(--testi-muted); font-size: .7rem; font-weight: 650; }
.testi-item-msg { margin: 11px 0 0; padding: 0 3px; color: #514b63; font-size: .88rem; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere; }
.approved-testimonial-photo { display: block; width: auto; max-width: min(100%, 420px); max-height: 360px; margin-top: 12px; border: 1px solid var(--testi-line); border-radius: 12px; object-fit: contain; }

.testi-empty { display: grid; justify-items: center; gap: 6px; padding: 38px 22px; border: 1px dashed #d5cbe3; border-radius: 16px; background: #faf8fc; text-align: center; grid-column: 1 / -1; }
.testi-empty-icon { display: grid; width: 46px; height: 46px; margin-bottom: 4px; place-items: center; border: 1px solid #ded7e9; border-radius: 13px; background: #fff; color: var(--testi-accent); font-size: 1.15rem; }
.testi-empty strong { color: var(--testi-ink); font-size: .92rem; }
.testi-empty p { margin: 0; color: var(--testi-muted); font-size: .8rem; line-height: 1.55; }

/* Form kirim testimoni — pola sama dengan form pembayaran */
.testi-form-card { position: relative; min-width: 0; padding: 22px; border: 1px solid var(--testi-line); border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(59,48,95,.045); }
.testi-form-topline { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 17px; padding-bottom: 12px; border-bottom: 1px solid #efecf3; }
.testi-form-topline > span { display: flex; align-items: center; gap: 8px; color: var(--testi-ink); font-size: .83rem; font-weight: 800; }
.testi-form-topline > span i { color: var(--testi-accent); }
.testi-form-topline small { color: var(--testi-muted); font-size: .66rem; }
.testi-form-row { display: grid; grid-template-columns: 2fr 1fr; gap: 13px; }
.public-testi .testi-form-card .form-group { margin-bottom: 14px; }
.public-testi .testi-form-card .form-group label { color: #514b63; font-size: .73rem; font-weight: 750; }
.public-testi .testi-form-card .form-control { min-height: 43px; border-color: var(--testi-line); border-radius: 10px; color: var(--testi-ink); font-size: .83rem; }
.public-testi .testi-form-card .form-control:focus { border-color: #9c8ab8; box-shadow: 0 0 0 3px rgba(109,90,145,.12); }
.testi-form-card textarea.form-control { padding: 11px 12px; line-height: 1.55; }
.testi-form-card .testi-submit-button { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; min-height: 46px; border: none; border-radius: 10px; background: linear-gradient(135deg, #6d5a91, #51416f); color: #fff; font-size: .83rem; font-weight: 800; cursor: pointer; box-shadow: 0 8px 20px rgba(81,65,111,.22); transition: transform .16s ease, box-shadow .16s ease; }
.testi-form-card .testi-submit-button:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(81,65,111,.28); }
.testi-form-card .testi-submit-button:active { transform: translateY(0); }
.testi-form-hint { margin: 10px 0 0; color: var(--testi-muted); font-size: .68rem; line-height: 1.5; text-align: center; }

.testi-photo-field { display: grid; gap: 6px; margin: 0 0 14px; }
.testi-photo-field > label { color: #514b63; font-size: .73rem; font-weight: 750; }
.testi-photo-field input[type="file"] { width: 100%; min-width: 0; padding: 9px; border: 1px solid var(--testi-line); border-radius: 10px; background: #faf9fc; color: var(--testi-ink); font: inherit; font-size: .75rem; }
.testi-photo-field input[type="file"]::file-selector-button { margin: -3px 10px -3px -3px; padding: 8px 11px; border: 1px solid #ded7e9; border-radius: 8px; background: #fff; color: #594a75; font: inherit; font-size: .72rem; font-weight: 750; cursor: pointer; }
.testi-photo-field input[type="file"]:focus-visible, .testi-photo-preview button:focus-visible { outline: 3px solid rgba(109,90,145,.32); outline-offset: 2px; }
.testi-photo-field > small, .testi-photo-status { margin: 0; color: var(--testi-muted); font-size: .7rem; line-height: 1.5; }
.testi-photo-status:empty { display: none; }
.testi-photo-status.is-error { color: #9f3a38; font-weight: 700; }
.testi-photo-preview { display: flex; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
.testi-photo-preview[hidden] { display: none; }
.testi-photo-preview img { display: block; max-width: min(100%, 280px); max-height: 220px; border: 1px solid var(--testi-line); border-radius: 10px; object-fit: contain; }
.testi-photo-preview button { min-height: 40px; padding: 8px 12px; border: 1px solid var(--testi-line); border-radius: 9px; background: #fff; color: #625d70; font: inherit; font-size: .75rem; font-weight: 700; cursor: pointer; }

/* Kartu info — pola sama dengan kartu bantuan pembayaran */
.testi-help-card { padding: 20px 18px; border: 1px solid #e5e0f2; border-radius: 16px; background: linear-gradient(155deg, #faf8fc, #f4f0f8); }
.testi-help-icon { display: grid; width: 40px; height: 40px; margin-bottom: 14px; place-items: center; border: 1px solid #ded7e9; border-radius: 12px; background: #fff; color: var(--testi-accent); }
.testi-help-card h4 { margin: 0 0 6px; color: var(--testi-ink); font-size: .95rem; font-weight: 800; }
.testi-help-card > p { margin: 0; color: var(--testi-muted); font-size: .77rem; line-height: 1.6; }
.testi-help-card ul { display: grid; gap: 12px; margin: 15px 0; padding: 0; list-style: none; }
.testi-help-card li { display: flex; align-items: flex-start; gap: 9px; color: #514b63; font-size: .75rem; line-height: 1.5; }
.testi-help-card li > i { flex: 0 0 16px; margin-top: 2px; color: #6f9278; font-size: .7rem; }
.testi-help-foot { display: flex; align-items: flex-start; gap: 7px; padding-top: 13px; border-top: 1px solid #e4deec; color: #6d6680; font-size: .68rem; font-weight: 650; line-height: 1.45; }
.testi-help-foot i { margin-top: 2px; color: var(--testi-accent); }

.public-testi button:focus-visible { outline: 3px solid rgba(157,138,184,.42); outline-offset: 2px; }

/* Pagination testimoni */
.testi-pagination { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 16px; }
.testi-page-btn { display: inline-flex; align-items: center; gap: 7px; min-height: 42px; padding: 9px 15px; border: 1px solid var(--testi-line); border-radius: 10px; background: #fff; color: #594a75; font-size: .78rem; font-weight: 750; text-decoration: none; cursor: pointer; transition: border-color .16s ease, background .16s ease, transform .16s ease; }
a.testi-page-btn:hover { transform: translateY(-1px); border-color: #cfc3df; background: #faf8fc; }
.testi-page-btn.is-disabled { opacity: .45; cursor: default; }
.testi-page-btn i { font-size: .68rem; }
.testi-page-info { color: var(--testi-muted); font-size: .74rem; font-weight: 650; text-align: center; }
@media (max-width: 640px) {
    .testi-pagination { flex-wrap: wrap; justify-content: center; }
    .testi-page-info { order: -1; width: 100%; }
}
@media (max-width: 1000px) {
    .testi-bottom { grid-template-columns: minmax(0, 1fr); gap: 14px; }
}
@media (max-width: 760px) {
    .testi-list { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 600px) {
    .public-testi-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .public-testi-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .public-testi-heading p { font-size: .79rem; }
    .testi-item { padding: 15px 15px; border-radius: 14px; }
    .testi-item-head { align-items: flex-start; }
    .testi-form-card { padding: 17px 14px; border-radius: 14px; }
    .testi-form-topline { align-items: flex-start; flex-direction: column; gap: 3px; }
    .testi-form-row { grid-template-columns: 1fr; gap: 0; }
    .testi-help-card { padding: 17px 15px; }
    .testi-help-card ul { gap: 9px; margin: 12px 0; }
    .testi-photo-preview { flex-direction: column; }
    .testi-photo-preview img { max-width: 100%; }
}
</style>

<script>
(() => {
    // Masonry daftar testimoni: kartu didistribusikan ke dua kolom berdasarkan
    // tinggi terukur, biar kartu ber-foto panjang tidak menyisakan void di
    // kolom sebelah. Tanpa JS: grid biasa (fallback).
    const list = document.querySelector('.testi-list');
    if (list && list.querySelector('.testi-item')) {
        const twoCols = window.matchMedia('(min-width: 761px)');
        const source = Array.from(list.querySelectorAll(':scope > .testi-item'));
        let rebuildTimer = 0;
        const rebuild = () => {
            list.querySelectorAll(':scope > .testi-col').forEach((c) => c.remove());
            source.forEach((el) => list.appendChild(el)); // urutan asli dulu
            if (!twoCols.matches || source.length < 2) return;
            const colA = document.createElement('div');
            const colB = document.createElement('div');
            colA.className = 'testi-col';
            colB.className = 'testi-col';
            list.appendChild(colA);
            list.appendChild(colB);
            // Pass 1: taruh semua di satu kolom supaya tinggi terukur di lebar kolom final.
            source.forEach((el) => colA.appendChild(el));
            const heights = source.map((el) => el.offsetHeight);
            // Pass 2: greedy — tiap kartu masuk kolom yang lebih pendek.
            source.forEach((el) => list.appendChild(el));
            const sums = [0, 0];
            const cols = [colA, colB];
            heights.forEach((h, i) => {
                const target = sums[0] <= sums[1] ? 0 : 1;
                cols[target].appendChild(source[i]);
                sums[target] += h + 12; // 12px = gap antar kartu
            });
        };
        const schedule = () => {
            window.clearTimeout(rebuildTimer);
            rebuildTimer = window.setTimeout(rebuild, 80);
        };
        rebuild();
        window.addEventListener('resize', schedule);
        if (typeof twoCols.addEventListener === 'function') twoCols.addEventListener('change', schedule);
        list.addEventListener('load', schedule, true); // foto lazy-load mengubah tinggi kartu
    }

    const status = document.querySelector('.testi-status');
    if (status) {
        status.focus({ preventScroll: true });
        status.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    }

    const input = document.getElementById('field-testi-photo');
    const statusLine = document.getElementById('testi-photo-status');
    const preview = document.getElementById('testi-photo-preview');
    const previewImage = document.getElementById('testi-photo-preview-image');
    const removeButton = document.getElementById('testi-photo-remove');
    if (!input || !statusLine || !preview || !previewImage || !removeButton) return;

    const maxBytes = 2 * 1024 * 1024;
    let previewUrl = '';
    const clearPreview = () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = '';
        previewImage.removeAttribute('src');
        preview.hidden = true;
    };

    input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        statusLine.classList.remove('is-error');
        if (!file) {
            clearPreview();
            statusLine.textContent = '';
            return;
        }
        if (file.size > maxBytes) {
            input.value = '';
            clearPreview();
            statusLine.textContent = 'Ukuran foto melebihi 2 MiB. Pilih foto yang lebih kecil.';
            statusLine.classList.add('is-error');
            return;
        }
        if (!/\.(jpe?g|png|webp)$/i.test(file.name)) {
            input.value = '';
            clearPreview();
            statusLine.textContent = 'Format tidak didukung. Pilih foto JPG, PNG, atau WebP.';
            statusLine.classList.add('is-error');
            return;
        }
        statusLine.textContent = `Foto dipilih: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MiB).`;
        clearPreview();
        if (file.type.startsWith('image/')) {
            previewUrl = URL.createObjectURL(file);
            previewImage.src = previewUrl;
            preview.hidden = false;
        }
    });

    removeButton.addEventListener('click', () => {
        input.value = '';
        clearPreview();
        statusLine.classList.remove('is-error');
        statusLine.textContent = 'Foto dihapus dari pilihan.';
        input.focus();
    });
})();
</script>
