<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title('|', true, 'right'); ?> GO-WEB | Portal Pelanggan & Rekapan</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php wp_head(); ?>

    <!-- Header/footer styling is inline intentionally: unlike style.css, this
         arrives with fresh HTML even when a host/CDN serves a cached stylesheet. -->
    <style id="goweb-site-chrome">
        .site-header { width: 100%; position: sticky; top: 0; z-index: 100; background: rgba(255,255,255,.96); border-bottom: 1px solid #e8e2f1; box-shadow: 0 3px 14px rgba(59,48,95,.035); backdrop-filter: blur(12px); }
        .site-header-inner { width: 100%; min-height: 72px; padding: 0 32px; display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .site-brand { display: flex; align-items: center; gap: 10px; color: #302b46; font-size: 1.3rem; font-weight: 800; letter-spacing: -.04em; text-decoration: none; white-space: nowrap; }
        .site-brand-name { color: #302b46; }
        .site-brand-name > span { color: #6d5a91; }
        .site-brand .brand-badge { padding: 3px 8px; border: 1px solid #e0d7ed; border-radius: 999px; background: #eee8f6; color: #62517f; font-size: .62rem; font-weight: 700; letter-spacing: .04em; box-shadow: none; }
        .site-header-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .site-greeting { display: inline-flex; align-items: center; gap: 8px; color: #514b63; font-size: .84rem; font-weight: 700; }
        .site-greeting-dot { width: 7px; height: 7px; border-radius: 50%; background: #7a9a7f; box-shadow: 0 0 0 3px #e8f0e9; }
        .site-greeting-role { color: #7c758a; font-weight: 600; }
        .site-action { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 0 14px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .81rem; font-weight: 750; text-decoration: none; transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease; }
        .site-action:hover { transform: translateY(-1px); }
        .site-action-soft { background: #eee9f6; border-color: #e4dced; color: #574675; }
        .site-action-soft:hover { background: #e7e0f1; color: #43345f; }
        .site-action-outline { background: #fff; border-color: #ddd5e8; color: #5d536f; }
        .site-action-outline:hover { background: #f8f6fb; border-color: #c9bddb; color: #43345f; }
        .site-action-danger { background: #f8eeee; border-color: #efdede; color: #9b5555; }
        .site-action-danger:hover { background: #f4e5e5; border-color: #e8cdcd; color: #814343; }
        footer.site-footer { width: 100%; flex-shrink: 0; padding: 0; background: #f2eff7; border-top: 1px solid #e4deed; color: #726b81; }
        .site-footer-inner { width: 100%; min-height: 68px; padding: 14px 32px; display: flex; align-items: center; justify-content: space-between; gap: 12px 24px; flex-wrap: wrap; font-size: .79rem; }
        .site-footer-brand { color: #514b63; font-weight: 750; }
        .site-footer-credit { color: #777188; }
        .site-footer-credit a { color: #62518e; text-decoration: none; font-weight: 800; }
        .site-footer-credit a:hover { color: #493769; text-decoration: underline; }
        @media (max-width: 640px) {
            .site-header-inner { width: 100%; min-height: 64px; padding: 10px 16px; gap: 10px; }
            .site-brand { font-size: 1.08rem; gap: 7px; }
            .site-brand .brand-badge { padding: 2px 7px; font-size: .56rem; }
            .site-header-actions { gap: 7px; }
            .site-action { min-height: 36px; padding: 0 10px; font-size: .75rem; }
            .site-greeting { display: none; }
            .site-footer-inner { width: 100%; min-height: 74px; padding: 14px 16px; flex-direction: column; justify-content: center; gap: 3px; text-align: center; }
        }
    </style>
</head>
<body <?php body_class(); ?>>

<header class="site-header">
    <div class="site-header-inner">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="brand site-brand" aria-label="GO-WEB Beranda">
            <span class="site-brand-name">GO-<span>WEB</span></span>
            <span class="brand-badge">CORE</span>
        </a>
        
        <div class="site-header-actions">
            <?php $header_logged_in_tenant = goweb_get_logged_in_tenant(); ?>
            <?php if ( $header_logged_in_tenant ): ?>
                <span class="header-greeting-text site-greeting">
                    <span class="site-greeting-dot" aria-hidden="true"></span>
                    Hi, <?php echo esc_html($header_logged_in_tenant['admin_name']); ?>
                </span>
                <!-- Logout kini ber-nonce: URL tanpa nonce valid akan ditolak handler (anti logout-CSRF #15) -->
                <a class="site-action site-action-danger" href="<?php echo esc_url( wp_nonce_url( add_query_arg('goweb_logout', '1', home_url('/login')), 'goweb_logout' ) ); ?>">
                    <i class="fa-solid fa-power-off" aria-hidden="true"></i> Keluar
                </a>
            <?php elseif ( is_user_logged_in() && current_user_can('administrator') ): ?>
                <?php $header_wp_user = wp_get_current_user(); ?>
                <span class="header-greeting-text site-greeting">
                    <span class="site-greeting-dot" aria-hidden="true"></span>
                    Hi, <?php echo esc_html($header_wp_user->display_name); ?> <span class="site-greeting-role">Super Admin</span>
                </span>
                <a class="site-action site-action-danger" href="<?php echo esc_url( wp_logout_url( home_url('/') ) ); ?>">
                    <i class="fa-solid fa-power-off" aria-hidden="true"></i> Keluar
                </a>
            <?php else: ?>
                <!-- Tombol Menuju Halaman Pendaftaran (Register) -->
                <a class="site-action site-action-soft" href="<?php echo esc_url(home_url('/register')); ?>">
                    <i class="fa-solid fa-store" aria-hidden="true"></i> Daftar Tenant
                </a>
                
                <!-- Tombol Login Tenant -->
                <a class="site-action site-action-outline" href="<?php echo esc_url(home_url('/login')); ?>">
                    <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Login
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>