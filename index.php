<?php get_header();

// Paket dan tenant tetap diambil dari data aktif agar konten beranda
// mengikuti konfigurasi produk, bukan angka/nama contoh yang di-hardcode.
$landing_packages = goweb_get_package_definitions();

global $wpdb;
$landing_tenants_table = $wpdb->prefix . 'goweb_tenants';
$landing_active_tenants = $wpdb->get_col(
    "SELECT store_name FROM {$landing_tenants_table} WHERE status = 'Active' ORDER BY created_at ASC"
);
?>

<style>
    .home-page { --home-ink: #302b46; --home-muted: #6f6a83; --home-line: #e5e0f2; --home-lilac: #f1edfb; --home-lilac-strong: #e8e0f8; --home-surface: #fff; color: var(--home-ink); }
    .home-section { width: min(1120px, calc(100% - 48px)); margin-inline: auto; }
    .home-kicker { display: inline-flex; align-items: center; gap: 8px; color: #62518e; font-size: .78rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .home-kicker i { color: #7864ad; }
    .home-hero { padding: 74px 0 58px; }
    .home-hero-grid { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(350px, .95fr); align-items: center; gap: 64px; }
    .home-hero h1 { max-width: 650px; margin: 18px 0 18px; color: var(--home-ink); font-size: clamp(2.35rem, 4.2vw, 3.65rem); font-weight: 800; line-height: 1.12; letter-spacing: -.055em; }
    .home-hero-copy { max-width: 590px; color: var(--home-muted); font-size: 1.05rem; line-height: 1.75; }
    .home-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 28px; }
    .home-button { min-height: 48px; display: inline-flex; align-items: center; justify-content: center; gap: 9px; padding: 0 20px; border: 1px solid transparent; border-radius: 12px; font: inherit; font-size: .92rem; font-weight: 800; text-decoration: none; transition: transform .18s ease, box-shadow .18s ease, background .18s ease; }
    .home-button:hover { transform: translateY(-2px); }
    .home-button-primary { background: linear-gradient(135deg, #6d28d9, #4338ca); color: #fff; box-shadow: 0 8px 20px rgba(79, 70, 149, .2); }
    .home-button-primary:hover { color: #fff; box-shadow: 0 11px 24px rgba(79, 70, 149, .27); }
    .home-button-secondary { background: #fff; color: #594781; border-color: #dcd4ed; }
    .home-button-secondary:hover { color: #433267; background: #faf8ff; }
    .home-hero-note { margin-top: 15px; color: #777188; font-size: .82rem; }
    .home-flow-card { position: relative; padding: 26px; border: 1px solid #dfd8ed; border-radius: 22px; background: linear-gradient(145deg, #fff 5%, #f5f1fc 100%); box-shadow: 0 22px 55px rgba(59, 48, 95, .10); }
    .home-flow-card::before { content: ''; position: absolute; width: 125px; height: 125px; top: -24px; right: -18px; border-radius: 50%; background: #ebe4f8; opacity: .8; z-index: 0; }
    .home-flow-content { position: relative; z-index: 1; }
    .home-flow-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 20px; border-bottom: 1px solid #e7e1f0; }
    .home-flow-head h2 { margin: 5px 0 0; font-size: 1.12rem; font-weight: 800; color: var(--home-ink); }
    .home-flow-caption { color: #777188; font-size: .78rem; }
    .home-flow-mark { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 13px; background: #e9e1f7; color: #604b91; font-size: 1rem; }
    .home-flow-list { display: grid; gap: 0; padding-top: 9px; }
    .home-flow-step { display: grid; grid-template-columns: 38px 1fr auto; align-items: center; gap: 12px; min-height: 68px; position: relative; }
    .home-flow-step:not(:last-child)::after { content: ''; position: absolute; left: 18px; top: 47px; bottom: -1px; border-left: 1px dashed #c9bfdc; }
    .home-flow-number { width: 36px; height: 36px; display: grid; place-items: center; border: 1px solid #ded6ed; border-radius: 12px; background: #fff; color: #68588b; font-size: .82rem; font-weight: 800; }
    .home-flow-step strong { display: block; color: var(--home-ink); font-size: .88rem; }
    .home-flow-step small { display: block; margin-top: 2px; color: #777188; font-size: .76rem; }
    .home-flow-status { padding: 5px 9px; border-radius: 999px; background: #e6f2eb; color: #38664c; font-size: .68rem; font-weight: 800; white-space: nowrap; }
    .home-proof { padding: 26px 0; border-top: 1px solid rgba(221, 214, 238, .75); border-bottom: 1px solid rgba(221, 214, 238, .75); background: rgba(255,255,255,.35); }
    .home-proof-card { padding: 18px 20px; border: 1px solid #e0d8ed; border-radius: 16px; background: #f0ebf7; }
    .home-proof-label { display: flex; align-items: center; gap: 9px; margin-bottom: 13px; color: #594a75; font-size: .78rem; font-weight: 800; }
    .home-proof-label::before { content: ''; width: 7px; height: 7px; flex: 0 0 7px; border-radius: 50%; background: #8773a8; box-shadow: 0 0 0 3px rgba(135,115,168,.14); }
    .home-proof-list { display: flex; flex-wrap: wrap; gap: 8px; }
    .home-proof-name { padding: 7px 12px; border: 1px solid #ddd5e9; border-radius: 999px; background: rgba(255,255,255,.88); color: #514b64; font-size: .75rem; font-weight: 700; }
    .home-icon { display: block; width: 1.1em; height: 1.1em; flex: 0 0 auto; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .home-section-block { padding-top: 82px; padding-bottom: 82px; }
    .home-section-heading { max-width: 650px; margin-bottom: 32px; }
    .home-section-heading.center { margin-inline: auto; text-align: center; }
    .home-section-heading h2 { margin: 10px 0 9px; color: var(--home-ink); font-size: clamp(1.75rem, 3vw, 2.35rem); font-weight: 800; line-height: 1.2; letter-spacing: -.035em; }
    .home-section-heading p { color: var(--home-muted); font-size: .98rem; line-height: 1.7; }
    .home-workflow { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .home-work-card { position: relative; padding: 24px; border: 1px solid var(--home-line); border-radius: 16px; background: rgba(255,255,255,.85); }
    .home-work-card:nth-child(2) { background: #f8f6fc; }
    .home-work-card:nth-child(3) { background: #f4f7f3; }
    .home-work-count { color: #8978ae; font-size: .75rem; font-weight: 800; letter-spacing: .08em; }
    .home-work-icon { width: 42px; height: 42px; display: grid; place-items: center; margin: 16px 0 15px; border-radius: 13px; background: #e9e2f5; color: #645287; font-size: 1rem; }
    .home-work-card:nth-child(3) .home-work-icon { background: #e4eee5; color: #4e7056; }
    .home-work-card h3 { margin: 0 0 8px; color: var(--home-ink); font-size: 1.03rem; font-weight: 800; }
    .home-work-card p { margin: 0; color: var(--home-muted); font-size: .88rem; line-height: 1.65; }
    .home-features-wrap { padding: 76px 0; background: #f2eff8; border-top: 1px solid #e8e2f1; border-bottom: 1px solid #e8e2f1; }
    .home-features { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .home-feature { display: grid; grid-template-columns: 40px 1fr; gap: 14px; padding: 19px 20px; border: 1px solid #e5dfef; border-radius: 14px; background: rgba(255,255,255,.8); }
    .home-feature-icon { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; background: #eee9f7; color: #67558f; }
    .home-feature h3 { margin: 1px 0 4px; color: var(--home-ink); font-size: .93rem; font-weight: 800; }
    .home-feature p { margin: 0; color: var(--home-muted); font-size: .82rem; line-height: 1.55; }
    .home-pricing { padding-top: 82px; padding-bottom: 86px; }
    .home-package-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(155px, 1fr)); align-items: stretch; gap: 13px; }
    .home-package { position: relative; display: flex; flex-direction: column; padding: 22px 18px 18px; border: 1px solid #e3ddec; border-radius: 16px; background: #fff; text-align: left; }
    .home-package.popular { border-color: #9b88c2; background: #fbfaff; box-shadow: 0 10px 28px rgba(91, 70, 132, .10); }
    .home-package-badge { position: absolute; top: -12px; right: 14px; padding: 4px 9px; border-radius: 999px; background: #e9e2f5; color: #57447f; font-size: .65rem; font-weight: 800; }
    .home-package h3 { margin: 0 0 5px; color: var(--home-ink); font-size: .95rem; font-weight: 800; }
    .home-package-storage { color: #777188; font-size: .76rem; }
    .home-package-price { margin-top: 19px; color: #51416f; font-size: 1.28rem; font-weight: 800; letter-spacing: -.03em; }
    .home-package-period { margin-top: 1px; color: #807a90; font-size: .72rem; }
    .home-package-link { display: inline-flex; align-items: center; justify-content: center; min-height: 39px; margin-top: 18px; border: 1px solid #dcd3eb; border-radius: 10px; background: #f2eef8; color: #51416f; font-size: .79rem; font-weight: 800; text-decoration: none; transition: background .18s ease, border-color .18s ease; }
    .home-package-link:hover { border-color: #b7a8cf; background: #e9e2f3; color: #413458; }
    .home-bottom-cta { padding: 54px 24px; background: #ebe6f4; border-top: 1px solid #ded6eb; }
    .home-bottom-cta-inner { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
    .home-bottom-cta h2 { margin: 0 0 7px; color: var(--home-ink); font-size: clamp(1.45rem, 2.5vw, 2rem); font-weight: 800; letter-spacing: -.03em; }
    .home-bottom-cta p { margin: 0; color: var(--home-muted); font-size: .94rem; line-height: 1.6; }
    .home-bottom-cta .home-button { flex: 0 0 auto; }
    @media (max-width: 850px) {
        .home-hero { padding-top: 56px; }
        .home-hero-grid { grid-template-columns: 1fr; gap: 34px; }
        .home-flow-card { max-width: 620px; }
        .home-proof-card { padding: 16px; }
        .home-proof-list { justify-content: flex-start; }
    }
    @media (max-width: 640px) {
        .home-section { width: calc(100% - 32px); max-width: 520px; }
        .home-hero { padding: 44px 0 42px; }
        .home-hero h1 { font-size: clamp(2rem, 10vw, 2.8rem); }
        .home-hero-copy { font-size: .96rem; }
        .home-actions { align-items: stretch; }
        .home-actions .home-button { flex: 1 1 100%; }
        .home-flow-card { padding: 20px 17px; }
        .home-flow-status { font-size: .62rem; padding: 5px 7px; }
        .home-section-block { padding-top: 60px; padding-bottom: 60px; }
        .home-workflow { grid-template-columns: 1fr; }
        .home-work-card { padding: 20px; }
        .home-features-wrap { padding: 60px 0; }
        .home-features { grid-template-columns: 1fr; }
        .home-package-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 10px; }
        .home-package { padding: 19px 13px 14px; }
        .home-package-price { font-size: 1.12rem; }
        .home-bottom-cta-inner { align-items: flex-start; flex-direction: column; }
        .home-bottom-cta .home-button { width: 100%; }
    }
</style>

<main class="home-page">
    <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" width="0" height="0" style="position:absolute;overflow:hidden">
        <symbol id="home-icon-boxes" viewBox="0 0 24 24"><path d="m3 7 9-4 9 4-9 4-9-4Z"/><path d="M3 7v10l9 4 9-4V7M12 11v10M7.5 5l9 4"/></symbol>
        <symbol id="home-icon-route" viewBox="0 0 24 24"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v3a4 4 0 0 0 4 4h4a4 4 0 0 1 4 4M18 17v-3a4 4 0 0 0-4-4h-2"/></symbol>
        <symbol id="home-icon-clipboard" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="16" rx="2"/><path d="M9 5.5V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5M9 11h6M9 15h6"/></symbol>
        <symbol id="home-icon-box" viewBox="0 0 24 24"><path d="m3 7 9-4 9 4-9 4-9-4Z"/><path d="M3 7v10l9 4 9-4V7M12 11v10M8 5l9 4"/></symbol>
        <symbol id="home-icon-receipt" viewBox="0 0 24 24"><path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/></symbol>
        <symbol id="home-icon-calculator" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8v3H8zM8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01M16 17h.01"/></symbol>
        <symbol id="home-icon-tags" viewBox="0 0 24 24"><path d="m20 13-7 7-10-10V3h7l10 10Z"/><circle cx="7.5" cy="7.5" r="1"/><path d="m14 3 7 7"/></symbol>
        <symbol id="home-icon-warehouse" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V10Z"/><path d="M8 21v-7h8v7M9 10h.01M15 10h.01"/></symbol>
        <symbol id="home-icon-payment" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></symbol>
        <symbol id="home-icon-chart" viewBox="0 0 24 24"><path d="M4 19V5M4 19h17"/><path d="m7 15 4-4 3 2 6-7"/><path d="M16 6h4v4"/></symbol>
        <symbol id="home-icon-sliders" viewBox="0 0 24 24"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M2 14h4M10 8h4M18 16h4"/></symbol>
        <symbol id="home-icon-wallet" viewBox="0 0 24 24"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H20v16H5a2 2 0 0 1-2-2V6.5Z"/><path d="M3 8h17M16 13h4"/><circle cx="16" cy="13" r=".5"/></symbol>
        <symbol id="home-icon-arrow" viewBox="0 0 24 24"><path d="M5 7h13l-3-3M19 17H6l3 3"/><path d="M18 7a6 6 0 0 1 0 10M6 17A6 6 0 0 1 6 7"/></symbol>
    </svg>
    <section class="home-hero">
        <div class="home-section home-hero-grid">
            <div>
                <span class="home-kicker"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-boxes"></use></svg> Operasional GO, WH & jastip</span>
                <h1>Order masuk, barang bergerak, tagihan terpantau.</h1>
                <p class="home-hero-copy">GO-WEB membantu kamu mengelola toko jastip, group order, dan warehouse dari satu dashboard—mulai dari rekap pesanan sampai update yang bisa dicek customer.</p>
                <div class="home-actions">
                    <a href="<?php echo esc_url(home_url('/register')); ?>" class="home-button home-button-primary"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-arrow"></use></svg> Mulai kelola toko</a>
                    <a href="#alur-kerja" class="home-button home-button-secondary">Lihat cara kerjanya</a>
                </div>
                <p class="home-hero-note">Tanpa perlu membangun website operasional sendiri dari nol.</p>
            </div>
            <div class="home-flow-card" aria-label="Contoh alur kerja pesanan di GO-WEB">
                <div class="home-flow-content">
                    <div class="home-flow-head">
                        <div>
                            <span class="home-flow-caption">SATU ALUR KERJA</span>
                            <h2>Dari pesanan sampai siap dikirim</h2>
                        </div>
                        <span class="home-flow-mark"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-arrow"></use></svg></span>
                    </div>
                    <div class="home-flow-list">
                        <div class="home-flow-step">
                            <span class="home-flow-number">01</span>
                            <div><strong>Catat pesanan</strong><small>Rekap PO dan data customer</small></div>
                            <span class="home-flow-status">Order</span>
                        </div>
                        <div class="home-flow-step">
                            <span class="home-flow-number">02</span>
                            <div><strong>Kelola barang</strong><small>Resi masuk, slot, dan warehouse</small></div>
                            <span class="home-flow-status">Gudang</span>
                        </div>
                        <div class="home-flow-step">
                            <span class="home-flow-number">03</span>
                            <div><strong>Pantau pembayaran</strong><small>Tagihan dan status pesanan</small></div>
                            <span class="home-flow-status">Selesai</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if ( ! empty( $landing_active_tenants ) ): ?>
    <section class="home-proof" aria-label="Tenant aktif GO-WEB">
        <div class="home-section">
            <div class="home-proof-card">
                <span class="home-proof-label">Dipakai oleh toko GO, WH & proxy</span>
                <div class="home-proof-list">
                    <?php foreach ( $landing_active_tenants as $client_name ): ?>
                        <span class="home-proof-name"><?php echo esc_html($client_name); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section id="alur-kerja" class="home-section home-section-block">
        <div class="home-section-heading">
            <span class="home-kicker"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-route"></use></svg> Alur yang nyambung</span>
            <h2>Kurangi rekap yang tercecer.</h2>
            <p>Informasi pesanan, barang, dan pembayaran tersusun dalam alur yang sama. Kamu dan customer bisa melihat perkembangan tanpa mencari catatan di banyak tempat.</p>
        </div>
        <div class="home-workflow">
            <article class="home-work-card">
                <span class="home-work-count">LANGKAH 01</span>
                <div class="home-work-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-clipboard"></use></svg></div>
                <h3>Pesanan tercatat</h3>
                <p>Susun rekap PO dan lihat status setiap customer, dari antrean hingga pesanan selesai.</p>
            </article>
            <article class="home-work-card">
                <span class="home-work-count">LANGKAH 02</span>
                <div class="home-work-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-box"></use></svg></div>
                <h3>Barang terpantau</h3>
                <p>Kelola nomor resi, kedatangan warehouse, slot, dan konsolidasi paket dalam satu tempat.</p>
            </article>
            <article class="home-work-card">
                <span class="home-work-count">LANGKAH 03</span>
                <div class="home-work-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-receipt"></use></svg></div>
                <h3>Pembayaran lebih jelas</h3>
                <p>Catat tagihan per kategori dan bantu customer melihat apa yang sudah atau belum dibayar.</p>
            </article>
        </div>
    </section>

    <section class="home-features-wrap">
        <div class="home-section">
            <div class="home-section-heading center">
                <span class="home-kicker"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-boxes"></use></svg> Satu dashboard operasional</span>
                <h2>Alat yang mendukung kerja harianmu.</h2>
                <p>Pilih fitur yang sesuai dengan paket dan kebutuhan toko. Pengaturan operasional tetap di tanganmu.</p>
            </div>
            <div class="home-features">
                <article class="home-feature"><span class="home-feature-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-calculator"></use></svg></span><div><h3>Kalkulator estimasi customer</h3><p>Customer bisa menghitung estimasi biaya berdasarkan kurs dan tarif yang kamu atur.</p></div></article>
                <article class="home-feature"><span class="home-feature-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-tags"></use></svg></span><div><h3>Kalkulator harga jual</h3><p>Hitung HPP, biaya tambahan, dan harga jual untuk membantu menyiapkan penawaran.</p></div></article>
                <article class="home-feature"><span class="home-feature-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-warehouse"></use></svg></span><div><h3>Database warehouse</h3><p>Catat paket masuk, nomor resi, foto, dan progres konsolidasi barang.</p></div></article>
                <article class="home-feature"><span class="home-feature-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-payment"></use></svg></span><div><h3>Tagihan & konfirmasi pembayaran</h3><p>Kelola tagihan dan terima bukti pembayaran customer untuk diverifikasi.</p></div></article>
                <article class="home-feature"><span class="home-feature-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-chart"></use></svg></span><div><h3>Laporan keuangan</h3><p>Pantau mutasi, saldo dompet, dan ringkasan keuangan toko.</p></div></article>
                <article class="home-feature"><span class="home-feature-icon"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-sliders"></use></svg></span><div><h3>Pengaturan yang bisa disesuaikan</h3><p>Atur kurs, kargo, status order, dan metode pembayaran sesuai operasionalmu.</p></div></article>
            </div>
        </div>
    </section>

    <section class="home-section home-pricing" id="paket">
        <div class="home-section-heading center">
            <span class="home-kicker"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-wallet"></use></svg> Pilih sesuai skala toko</span>
            <h2>Paket sederhana, kapasitas yang berbeda.</h2>
            <p>Semua paket mendapatkan fitur yang sama. Pilih kapasitas storage yang sesuai—kamu bisa upgrade saat kebutuhan bertambah.</p>
        </div>
        <div class="home-package-grid">
            <?php foreach ( $landing_packages as $pkg ):
                $storage_display = $pkg['storage_limit'] >= 1024 ? round($pkg['storage_limit'] / 1024, 1) . ' GB' : $pkg['storage_limit'] . ' MB';
                $is_popular = ( $pkg['key'] === 'standard' );
            ?>
                <article class="home-package <?php echo $is_popular ? 'popular' : ''; ?>">
                    <?php if ($is_popular): ?><span class="home-package-badge">Paling dipilih</span><?php endif; ?>
                    <h3><?php echo esc_html($pkg['name']); ?></h3>
                    <span class="home-package-storage"><?php echo esc_html($storage_display); ?> storage</span>
                    <strong class="home-package-price">Rp<?php echo number_format($pkg['price'], 0, ',', '.'); ?></strong>
                    <span class="home-package-period">per bulan</span>
                    <a class="home-package-link" href="<?php echo esc_url(home_url('/register')); ?>">Pilih paket</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="home-bottom-cta">
        <div class="home-section home-bottom-cta-inner">
            <div>
                <h2>Siap merapikan operasional tokomu?</h2>
                <p>Mulai dari rekap pesanan, lanjut ke warehouse, sampai pembayaran—dalam satu tempat.</p>
            </div>
            <a href="<?php echo esc_url(home_url('/register')); ?>" class="home-button home-button-primary"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-arrow"></use></svg> Daftarkan toko</a>
        </div>
    </section>
</main>

<?php get_footer(); ?>
