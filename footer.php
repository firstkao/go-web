<style id="goweb-footer-style">
    footer.site-footer { width: 100%; flex-shrink: 0; padding: 0; background: #f2eff7; border-top: 1px solid #e4deed; color: #625d70; }
    .site-footer-inner { width: 100%; min-height: 68px; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; gap: 8px 24px; flex-wrap: wrap; font-size: .875rem; }
    .site-footer-brand { min-width: 0; color: #514b63; font-weight: 700; overflow-wrap: anywhere; }
    .site-footer-credit { color: #625d70; }
    .site-footer-credit a { min-height: 44px; display: inline-flex; align-items: center; padding: 0 8px; color: #51416f; text-decoration: underline; text-underline-offset: 3px; font-weight: 800; }
    .site-footer-credit a:hover { color: #493769; }
    .site-footer-credit a:focus-visible { outline: 3px solid #8069a3; outline-offset: 2px; border-radius: 4px; }
    @media (max-width: 640px) {
        .site-footer-inner { width: 100%; min-height: 74px; padding: 10px 16px; flex-direction: column; justify-content: center; gap: 2px; text-align: center; }
        .site-footer-credit a { padding-inline: 12px; }
    }
</style>

<?php
$tenant_portal_footer = $GLOBALS['goweb_tenant_portal_footer'] ?? null;
$is_tenant_portal_footer = is_array( $tenant_portal_footer ) && isset( $tenant_portal_footer['store_name'] );
if ( $is_tenant_portal_footer ) {
    unset( $GLOBALS['goweb_tenant_portal_footer'] );
    $footer_brand = '&copy; ' . date('Y') . ' ' . esc_html($tenant_portal_footer['store_name']) . '.';
    $footer_credit_label = 'Didukung oleh';
    $footer_link = '<a href="' . esc_url(home_url('/')) . '" aria-label="Kunjungi halaman utama GO-WEB">GO-WEB</a>';
} else {
    $footer_brand = '&copy; ' . date('Y') . ' Platform GO-WEB. Hak cipta dilindungi.';
    $footer_credit_label = 'Powered by';
    $footer_link = '<a href="https://x.com/first_kao" target="_blank" rel="noopener noreferrer" aria-label="Kunjungi profil First Kao di X, buka tab baru">First Kao</a>';
}
?>
<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-brand"><?php echo $footer_brand; ?></div>
        <div class="site-footer-credit"><?php echo esc_html($footer_credit_label); ?> <?php echo $footer_link; ?></div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>