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

    <style>
        .site-header-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 32px;
            height: 70px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .site-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        @media (max-width: 600px) {
            .site-header-inner {
                padding: 10px 16px;
                height: auto;
                justify-content: space-between;
            }
            .site-header-actions {
                gap: 8px;
            }
            .site-header-actions a {
                padding: 7px 12px !important;
                font-size: 0.78rem !important;
            }
        }
        @media (max-width: 420px) {
            .header-greeting-text {
                display: none;
            }
        }
    </style>
</head>
<body <?php body_class(); ?>>

<header class="site-header" style="width: 100%; border-bottom: 1px solid var(--border-soft); background: #ffffff;">
    <div class="site-header-inner">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="brand" style="text-decoration: none;">
            GO-<span>WEB</span> <span class="brand-badge">CORE</span>
        </a>
        
        <div class="site-header-actions">
            <?php $header_logged_in_tenant = goweb_get_logged_in_tenant(); ?>
            <?php if ( $header_logged_in_tenant ): ?>
                <span class="header-greeting-text" style="font-size: 0.85rem; font-weight: 700; color: var(--text-dark);">
                    Hi, <?php echo esc_html($header_logged_in_tenant['admin_name']); ?>
                </span>
                <a href="<?php echo esc_url( add_query_arg('goweb_logout', '1', home_url('/login')) ); ?>" style="display: inline-flex; align-items: center; gap: 6px; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; text-decoration: none; transition: all 0.2s;">
                    <i class="fa-solid fa-power-off"></i> Keluar
                </a>
            <?php elseif ( is_user_logged_in() && current_user_can('administrator') ): ?>
                <?php $header_wp_user = wp_get_current_user(); ?>
                <span class="header-greeting-text" style="font-size: 0.85rem; font-weight: 700; color: var(--text-dark);">
                    Hi, <?php echo esc_html($header_wp_user->display_name); ?> (Super Admin)
                </span>
                <a href="<?php echo esc_url( wp_logout_url( home_url('/') ) ); ?>" style="display: inline-flex; align-items: center; gap: 6px; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; text-decoration: none; transition: all 0.2s;">
                    <i class="fa-solid fa-power-off"></i> Keluar
                </a>
            <?php else: ?>
                <!-- Tombol Menuju Halaman Pendaftaran (Register) -->
                <a href="<?php echo esc_url(home_url('/register')); ?>" style="display: inline-flex; align-items: center; gap: 6px; background: #f5f3ff; color: #8b5cf6; border: 1px solid #ddd6fe; padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; text-decoration: none; transition: all 0.2s;">
                    <i class="fa-solid fa-store"></i> Daftar Tenant
                </a>
                
                <!-- Tombol Login Tenant -->
                <a href="<?php echo esc_url(home_url('/login')); ?>" style="display: inline-flex; align-items: center; gap: 6px; background: var(--pastel-purple-bg); color: var(--pastel-purple); border: 1px solid var(--pastel-purple-border); padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; text-decoration: none; transition: all 0.2s;">
                    <i class="fa-solid fa-right-to-bracket"></i> Login
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>