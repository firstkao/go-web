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
<div class="fin-wrap menu-testimonials">
    <div class="testi-heading">
        <span class="testi-heading-icon" aria-hidden="true"><i class="fa-solid fa-comments"></i></span>
        <div>
            <h2>Testimoni Pelanggan</h2>
            <p>Testimoni yang dikirim pembeli dari portal toko masuk ke sini dulu. Hanya yang kamu setujui yang tampil di halaman publik.</p>
        </div>
    </div>

    <style>
        .menu-testimonials { --testi-line: #e5e0f2; --testi-ink: #302b46; --testi-muted: #6d687a; }
        .testi-heading { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
        .testi-heading-icon { display: grid; flex: 0 0 48px; width: 48px; height: 48px; place-items: center; border: 1px solid #ded7e9; border-radius: 14px; background: linear-gradient(145deg, #f8f5fb, #f0ebf7); color: var(--primary); font-size: 1.08rem; }
        .testi-heading h2 { margin: 0 0 5px; color: var(--testi-ink); font-size: 1.25rem; font-weight: 800; line-height: 1.3; }
        .testi-heading p { margin: 0; color: var(--testi-muted); font-size: .875rem; line-height: 1.55; }
        .testi-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
        .testi-metric { display: flex; align-items: center; justify-content: space-between; gap: 14px; min-width: 0; padding: 17px 19px; border: 1px solid var(--testi-line); border-radius: var(--radius-lg); background: #fff; box-shadow: 0 4px 14px -5px rgba(48, 43, 70, .09); }
        .testi-metric-copy { min-width: 0; }
        .testi-metric-label { display: block; margin-bottom: 4px; color: var(--testi-muted); font-size: .7rem; font-weight: 750; line-height: 1.4; }
        .testi-metric-value { color: var(--testi-ink); font-size: 1.5rem; font-weight: 800; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .testi-metric-icon { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border: 1px solid; border-radius: 12px; font-size: .95rem; }
        .testi-metric.pending .testi-metric-icon { border-color: #ead7a8; background: #fbf5e7; color: #805719; }
        .testi-metric.approved .testi-metric-icon { border-color: #d6e5d8; background: #edf4ee; color: #45634d; }
        .testi-metric.rejected .testi-metric-icon { border-color: #efdede; background: #fbf1ef; color: #965a58; }
        .testi-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .testi-tab { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; padding: 8px 15px; border: 1px solid var(--testi-line); border-radius: 10px; background: #fff; color: var(--testi-muted); font-size: .78rem; font-weight: 750; line-height: 1.2; text-decoration: none; transition: background-color .16s ease, border-color .16s ease, color .16s ease; }
        .testi-tab:hover:not(.is-active) { border-color: #c9bddb; background: #f8f5fb; color: #51416f; }
        .testi-tab.is-active { border-color: var(--primary); background: var(--primary); color: #fff; }
        .testi-tab:focus-visible, .testi-action:focus-visible { outline: 3px solid rgba(109, 90, 145, .32); outline-offset: 2px; }
        .testi-list { display: grid; gap: 12px; }
        .testi-entry { min-width: 0; padding: 18px 20px; border: 1px solid var(--testi-line); border-radius: var(--radius-lg); background: #fff; box-shadow: 0 4px 14px -6px rgba(48, 43, 70, .08); }
        .testi-entry-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .testi-buyer { color: var(--testi-ink); font-size: .9rem; }
        .testi-rating { color: #9a5b00; letter-spacing: 1px; margin-left: 8px; }
        .testi-date { margin-top: 3px; color: var(--testi-muted); font-size: .72rem; }
        .testi-status { display: inline-flex; min-height: 28px; align-items: center; gap: 6px; padding: 4px 10px; border: 1px solid; border-radius: 999px; font-size: .72rem; font-weight: 800; white-space: nowrap; }
        .testi-status.pending { border-color: #ead7a8; background: #fbf5e7; color: #805719; }
        .testi-status.approved { border-color: #d6e5d8; background: #edf4ee; color: #45634d; }
        .testi-status.rejected { border-color: #efdede; background: #fbf1ef; color: #965a58; }
        .testi-message { margin: 0 0 14px; color: var(--testi-ink); font-size: .875rem; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere; }
        .testi-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .testi-action { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 7px; padding: 8px 13px; border: 1px solid transparent; border-radius: 9px; color: #fff; font: inherit; font-size: .76rem; font-weight: 750; line-height: 1.2; cursor: pointer; transition: background-color .16s ease, border-color .16s ease, transform .16s ease; }
        .testi-action:hover { transform: translateY(-1px); }
        .testi-action.approve { background: #52775a; }
        .testi-action.approve:hover { background: #426249; }
        .testi-action.reject { background: #91600f; }
        .testi-action.reject:hover { background: #774e0b; }
        .testi-action.delete { border-color: #e9c7c5; background: #fff; color: #8d3936; }
        .testi-action.delete:hover { border-color: #d99a96; background: #fbf1ef; }
        .testi-empty { padding: 36px 20px; border: 1px solid var(--testi-line); border-radius: var(--radius-lg); background: #fff; color: var(--testi-muted); text-align: center; }
        .testi-empty-icon { display: grid; width: 44px; height: 44px; margin: 0 auto 11px; place-items: center; border: 1px solid #ded7e9; border-radius: 14px; background: #f8f5fb; color: var(--primary); font-size: 1rem; }
        .testi-empty strong { color: var(--testi-ink); font-size: .86rem; }
        @media (max-width: 700px) {
            .testi-metrics { gap: 10px; }
            .testi-metric { align-items: flex-start; padding: 14px 13px; }
            .testi-metric-icon { display: none; }
            .testi-entry { padding: 16px; }
        }
        @media (max-width: 480px) {
            .testi-heading { align-items: flex-start; gap: 11px; }
            .testi-heading-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
            .testi-heading h2 { font-size: 1.12rem; }
            .testi-heading p { font-size: .8rem; }
            .testi-metrics { grid-template-columns: 1fr; gap: 8px; }
            .testi-metric { align-items: center; padding: 12px 14px; }
            .testi-metric-icon { display: grid; }
            .testi-metric-value { font-size: 1.25rem; }
            .testi-tab { flex: 1 1 auto; padding-inline: 11px; }
            .testi-actions { display: grid; grid-template-columns: 1fr; }
            .testi-action { width: 100%; }
        }
    </style>

    <div class="testi-metrics" aria-label="Ringkasan status testimoni">
        <div class="testi-metric pending">
            <div class="testi-metric-copy"><span class="testi-metric-label">Menunggu Moderasi</span><div class="testi-metric-value"><?php echo $testi_counts['Pending']; ?></div></div>
            <span class="testi-metric-icon" aria-hidden="true"><i class="fa-regular fa-clock"></i></span>
        </div>
        <div class="testi-metric approved">
            <div class="testi-metric-copy"><span class="testi-metric-label">Tampil di Publik</span><div class="testi-metric-value"><?php echo $testi_counts['Approved']; ?></div></div>
            <span class="testi-metric-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
        </div>
        <div class="testi-metric rejected">
            <div class="testi-metric-copy"><span class="testi-metric-label">Ditolak</span><div class="testi-metric-value"><?php echo $testi_counts['Rejected']; ?></div></div>
            <span class="testi-metric-icon" aria-hidden="true"><i class="fa-solid fa-circle-xmark"></i></span>
        </div>
    </div>

    <nav class="testi-tabs" aria-label="Filter status testimoni">
        <?php foreach ( $testi_tabs as $tk => $tl ): $is_on = ( $testi_filter === $tk ); ?>
            <a href="<?php echo esc_url( add_query_arg( 'status', $tk, $testi_base ) . ( $store_query_suffix ? '&' . ltrim( $store_query_suffix, '?' ) : '' ) ); ?>"
               class="testi-tab<?php echo $is_on ? ' is-active' : ''; ?>"<?php echo $is_on ? ' aria-current="page"' : ''; ?>><?php echo esc_html($tl); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ( empty( $testi_rows ) ): ?>
        <div class="testi-empty" role="status">
            <span class="testi-empty-icon" aria-hidden="true"><i class="fa-regular fa-comments"></i></span>
            <strong>Belum ada testimoni di kategori ini.</strong>
        </div>
    <?php else: ?>
        <div class="testi-list">
            <?php foreach ( $testi_rows as $tr ):
                $b = isset( $testi_badge[ $tr['status'] ] ) ? $testi_badge[ $tr['status'] ] : $testi_badge['Pending'];
                $rating = max( 1, min( 5, intval( $tr['rating'] ) ) );
                $status_class = $tr['status'] === 'Approved' ? 'approved' : ( $tr['status'] === 'Rejected' ? 'rejected' : 'pending' );
                $status_icon = $tr['status'] === 'Approved' ? 'fa-circle-check' : ( $tr['status'] === 'Rejected' ? 'fa-circle-xmark' : 'fa-clock' ); ?>
                <article class="testi-entry">
                    <div class="testi-entry-head">
                        <div>
                            <strong class="testi-buyer"><?php echo esc_html( $tr['buyer_name'] ); ?></strong>
                            <span class="testi-rating" aria-label="Rating <?php echo $rating; ?> dari 5"><?php echo str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ); ?></span>
                            <div class="testi-date"><?php echo esc_html( mysql2date( 'd M Y H:i', $tr['created_at'] ) ); ?></div>
                        </div>
                        <span class="testi-status <?php echo esc_attr($status_class); ?>"><i class="fa-solid <?php echo esc_attr($status_icon); ?>" aria-hidden="true"></i><?php echo esc_html($b[2]); ?></span>
                    </div>
                    <p class="testi-message"><?php echo esc_html( $tr['message'] ); ?></p>
                    <form method="POST" action="<?php echo esc_url( $testi_base . $store_query_suffix ); ?>" class="testi-actions">
                        <?php wp_nonce_field( 'goweb_testi_action_' . $store_id, 'goweb_testi_nonce' ); ?>
                        <input type="hidden" name="testi_id" value="<?php echo intval( $tr['id'] ); ?>">
                        <?php if ( $tr['status'] !== 'Approved' ): ?>
                            <button type="submit" name="action_testi_approve" value="1" class="testi-action approve"><i class="fa-solid fa-check" aria-hidden="true"></i> Tampilkan</button>
                        <?php endif; ?>
                        <?php if ( $tr['status'] !== 'Rejected' ): ?>
                            <button type="submit" name="action_testi_reject" value="1" class="testi-action reject"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i> <?php echo $tr['status'] === 'Approved' ? 'Sembunyikan' : 'Tolak'; ?></button>
                        <?php endif; ?>
                        <button type="submit" name="action_testi_delete" value="1" onclick="return confirm('Hapus testimoni ini permanen?');" class="testi-action delete"><i class="fa-solid fa-trash" aria-hidden="true"></i> Hapus</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
