<?php
if (!defined('ABSPATH')) exit;
if (!$has_testi) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup!</div>';
    return;
}

// Filter status lewat query string — whitelist, bukan langsung dipakai ke SQL.
$testi_filter = isset($_GET['status']) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'Pending';
if ( ! in_array( $testi_filter, array('Pending', 'Approved', 'Rejected', 'all'), true ) ) $testi_filter = 'Pending';

$testi_counts = array( 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0 );
foreach ( (array) $wpdb->get_results( $wpdb->prepare(
    "SELECT status, COUNT(*) AS c FROM {$testimonials_table} WHERE store_id = %s GROUP BY status", $store_id
), ARRAY_A ) as $cnt_row ) {
    if ( isset( $testi_counts[ $cnt_row['status'] ] ) ) $testi_counts[ $cnt_row['status'] ] = intval( $cnt_row['c'] );
}

if ( $testi_filter === 'all' ) {
    $testi_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$testimonials_table} WHERE store_id = %s ORDER BY created_at DESC, id DESC", $store_id
    ), ARRAY_A );
} else {
    $testi_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$testimonials_table} WHERE store_id = %s AND status = %s ORDER BY created_at DESC, id DESC", $store_id, $testi_filter
    ), ARRAY_A );
}

$testi_base = home_url('/dashboard/testimonials/');
$testi_tabs = array( 'Pending' => 'Menunggu', 'Approved' => 'Tampil', 'Rejected' => 'Ditolak', 'all' => 'Semua' );
$testi_badge = array(
    'Pending'  => array( '#fef3c7', '#b45309', 'Menunggu' ),
    'Approved' => array( '#dcfce7', '#15803d', 'Tampil' ),
    'Rejected' => array( '#fee2e2', '#b91c1c', 'Ditolak' ),
);
?>
<div class="fin-wrap">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Testimoni Pelanggan</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Testimoni yang dikirim pembeli dari portal toko masuk ke sini dulu. Hanya yang kamu setujui yang tampil di halaman publik.</p>
    </div>

    <div class="metrics-grid" style="margin-bottom: 24px;">
        <div class="metric-card highlight">
            <div class="metric-label">Menunggu Moderasi</div>
            <div class="metric-val" style="font-size: 22px;"><?php echo $testi_counts['Pending']; ?></div>
        </div>
        <div class="metric-card green">
            <div class="metric-label">Tampil di Publik</div>
            <div class="metric-val" style="font-size: 22px; color: #15803d;"><?php echo $testi_counts['Approved']; ?></div>
        </div>
        <div class="metric-card blue">
            <div class="metric-label">Ditolak</div>
            <div class="metric-val" style="font-size: 22px;"><?php echo $testi_counts['Rejected']; ?></div>
        </div>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <?php foreach ( $testi_tabs as $tk => $tl ): $is_on = ( $testi_filter === $tk ); ?>
            <a href="<?php echo esc_url( add_query_arg( 'status', $tk, $testi_base ) . ( $store_query_suffix ? '&' . ltrim( $store_query_suffix, '?' ) : '' ) ); ?>"
               style="padding: 7px 16px; border-radius: 999px; font-size: 12.5px; font-weight: 800; text-decoration: none; border: 1.5px solid <?php echo $is_on ? 'var(--pastel-purple)' : 'var(--border-soft)'; ?>; background: <?php echo $is_on ? 'var(--pastel-purple)' : '#fff'; ?>; color: <?php echo $is_on ? '#fff' : 'var(--text-muted)'; ?>;"><?php echo esc_html($tl); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ( empty( $testi_rows ) ): ?>
        <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 2rem; margin-bottom: 8px;">💬</div>
            <strong>Belum ada testimoni di kategori ini.</strong>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php foreach ( $testi_rows as $tr ):
                $b = isset( $testi_badge[ $tr['status'] ] ) ? $testi_badge[ $tr['status'] ] : $testi_badge['Pending'];
                $rating = max( 1, min( 5, intval( $tr['rating'] ) ) ); ?>
                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 18px 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 8px;">
                        <div>
                            <strong style="color: var(--text-dark);"><?php echo esc_html( $tr['buyer_name'] ); ?></strong>
                            <span style="color: #f59e0b; letter-spacing: 1px; margin-left: 8px;"><?php echo str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ); ?></span>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;"><?php echo esc_html( mysql2date( 'd M Y H:i', $tr['created_at'] ) ); ?></div>
                        </div>
                        <span style="padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 800; background: <?php echo esc_attr($b[0]); ?>; color: <?php echo esc_attr($b[1]); ?>;"><?php echo esc_html($b[2]); ?></span>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-dark); white-space: pre-line; margin-bottom: 14px;"><?php echo esc_html( $tr['message'] ); ?></p>
                    <form method="POST" action="<?php echo esc_url( $testi_base . $store_query_suffix ); ?>" style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <?php wp_nonce_field( 'goweb_testi_action_' . $store_id, 'goweb_testi_nonce' ); ?>
                        <input type="hidden" name="testi_id" value="<?php echo intval( $tr['id'] ); ?>">
                        <?php if ( $tr['status'] !== 'Approved' ): ?>
                            <button type="submit" name="action_testi_approve" value="1" style="padding: 7px 14px; border-radius: 8px; border: none; background: #16a34a; color: #fff; font-weight: 800; font-size: 12.5px; cursor: pointer;">✓ Tampilkan</button>
                        <?php endif; ?>
                        <?php if ( $tr['status'] !== 'Rejected' ): ?>
                            <button type="submit" name="action_testi_reject" value="1" style="padding: 7px 14px; border-radius: 8px; border: none; background: #d97706; color: #fff; font-weight: 800; font-size: 12.5px; cursor: pointer;"><?php echo $tr['status'] === 'Approved' ? 'Sembunyikan' : '✕ Tolak'; ?></button>
                        <?php endif; ?>
                        <button type="submit" name="action_testi_delete" value="1" onclick="return confirm('Hapus testimoni ini permanen?');" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid #fecaca; background: #fff; color: #b91c1c; font-weight: 800; font-size: 12.5px; cursor: pointer;"><i class="fa-solid fa-trash"></i> Hapus</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
