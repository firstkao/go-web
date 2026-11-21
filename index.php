<?php get_header();

// Ambil daftar paket dari kamus terpusat — biar section harga di sini
// OTOMATIS kesinkron kalau nanti paket/harga diubah, gak perlu diedit manual
// di 2 tempat.
$landing_packages = goweb_get_package_definitions();

// Ambil nama toko tenant yang statusnya Active — buat section "Dipercaya
// oleh" di bawah, biar otomatis kesinkron sama tenant asli, gak hardcode
// lagi. Diurutin dari yang paling lama daftar (created_at ASC).
global $wpdb;
$landing_tenants_table = $wpdb->prefix . 'goweb_tenants';
$landing_active_tenants = $wpdb->get_col(
    "SELECT store_name FROM {$landing_tenants_table} WHERE status = 'Active' ORDER BY created_at ASC"
);
?>

<main>
    <!-- HERO -->
    <section class="hero">
        <div class="container">
            <span class="hero-tag"><i class="fa-solid fa-sparkles"></i> Platform Web Sharing buat GO, WH & Proxy</span>
            <h1>Bisnis GO, WH & Proxy Kamu, Siap Online Hari Ini Juga</h1>
            <p>GO-WEB kasih kamu dashboard lengkap — kalkulator estimasi biaya, rekapan PO, tagihan, database warehouse, sampai laporan keuangan — tanpa perlu bikin website sendiri dari nol.</p>
            <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 24px;">
                <a href="<?php echo esc_url(home_url('/register')); ?>" style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; padding: 14px 28px; border-radius: 999px; font-weight: 800; text-decoration: none; box-shadow: 0 8px 20px rgba(139,92,246,0.25);">
                    <i class="fa-solid fa-rocket"></i> Daftar Sekarang
                </a>
                <a href="#preview" style="display: inline-flex; align-items: center; gap: 8px; background: #fff; color: var(--text-dark); border: 1.5px solid var(--border-soft); padding: 14px 28px; border-radius: 999px; font-weight: 700; text-decoration: none;">
                    <i class="fa-solid fa-eye"></i> Lihat Preview
                </a>
            </div>
        </div>
    </section>

    <!-- DIPERCAYA OLEH — dinamis dari tenant yang statusnya Active -->
    <?php if ( ! empty( $landing_active_tenants ) ): ?>
    <section class="container" style="padding: 36px 24px 8px;">
        <p style="text-align: center; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px;">Dipercaya oleh GO, WH & Proxy</p>
        <div style="display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;">
            <?php foreach ( $landing_active_tenants as $client_name ): ?>
                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 999px; padding: 10px 22px; font-size: 0.85rem; font-weight: 700; color: var(--text-dark);"><?php echo esc_html($client_name); ?></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- FITUR HIGHLIGHT -->
    <section class="container" style="padding: 50px 24px;">
        <div style="text-align: center; margin-bottom: 36px;">
            <h2 style="font-size: 1.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Semua yang Kamu Butuh, Udah Ada</h2>
            <p style="color: var(--text-muted);">Gak perlu gonta-ganti aplikasi buat ngurus tiap bagian bisnis GO, WH, atau Proxy kamu.</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 18px;">
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">🧮</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Kalkulator Estimasi Biaya</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Kurs valas, tarif kargo udara/laut, otomatis kehitung — customer bisa cek sendiri estimasinya.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">💵</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Kalkulator Harga Jual</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Tool internal kamu — hitung HPP, margin profit, sampai harga jual final, bebas diutak-atik sendiri.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">📋</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Rekapan PO</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Status order per customer, dari antrean sampai selesai kirim — customer bisa cek progress-nya sendiri.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">🧾</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Rekapan Tagihan</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">DP, ongkir, pajak, pelunasan — dicatat per kategori, gampang dipantau mana yang udah/belum lunas.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">📦</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Database Warehouse & Box</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Tracking resi masuk, foto bukti paket, sampai konsolidasi ke Box sebelum dikirim ke Indonesia.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">💰</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Laporan Keuangan</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Pantau kas masuk, saldo dompet digital, dan mutasi keuangan toko real-time.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">💳</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Konfirmasi Pembayaran</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Customer upload bukti transfer sendiri, jenis pembayarannya bisa kamu atur sesuai kebutuhan toko.</p>
            </div>
            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px;">
                <div style="font-size: 1.8rem; margin-bottom: 10px;">⚙️</div>
                <h3 style="font-size: 1rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Semua Bisa Disesuaikan</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Kurs, tarif kargo, status order, jenis pembayaran — semua bisa kamu atur sendiri lewat Pengaturan.</p>
            </div>
        </div>
    </section>

    <!-- PREVIEW DASHBOARD & TENANT WEB (INTERAKTIF — klik menu/tab-nya!) -->
    <section id="preview" class="container" style="padding: 30px 24px 60px;">
        <div style="text-align: center; margin-bottom: 36px;">
            <h2 style="font-size: 1.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Coba Langsung Tampilannya</h2>
            <p style="color: var(--text-muted);">Klik menu/tab di bawah ini — beneran bisa dipencet, bukan cuma gambar.</p>
        </div>

        <!-- PREVIEW 1: DASHBOARD ADMIN -->
        <div style="margin-bottom: 40px;">
            <h3 style="font-size: 1.05rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 14px; display: flex; align-items: center; gap: 8px;"><i class="fa-solid fa-gauge"></i> Dashboard Admin (Buat Kamu)</h3>
            <div style="background: #1e1b2e; border-radius: 16px; padding: 10px; box-shadow: 0 20px 40px -10px rgba(30,27,46,0.3);">
                <div style="display: flex; align-items: center; gap: 6px; padding: 6px 8px;">
                    <span style="width:10px; height:10px; border-radius:50%; background:#ef4444; display:inline-block;"></span>
                    <span style="width:10px; height:10px; border-radius:50%; background:#f59e0b; display:inline-block;"></span>
                    <span style="width:10px; height:10px; border-radius:50%; background:#22c55e; display:inline-block;"></span>
                    <div style="background: rgba(255,255,255,0.1); border-radius: 6px; padding: 3px 10px; font-size: 0.55rem; color: rgba(255,255,255,0.6); margin-left: 6px;">go-web.cloud/dashboard/overview/</div>
                </div>
                <div style="background: #faf8ff; border-radius: 10px; overflow: hidden; display: grid; grid-template-columns: 150px 1fr;">
                    <div style="background: #fff; border-right: 1px solid var(--border-soft); padding: 14px 10px; display: flex; flex-direction: column; gap: 6px;">
                        <div style="background: var(--pastel-purple-bg, #f5f3ff); border: 1px solid var(--border-soft); border-radius: 8px; padding: 8px; font-size: 0.6rem; font-weight: 800; color: var(--text-dark);">TESTY GO</div>
                        <?php
                        $admin_panels = array(
                            'ringkasan' => array('📊', 'Ringkasan Toko'),
                            'kalkulator' => array('🧮', 'Kalkulator Harga Jual'),
                            'pembayaran' => array('🧾', 'Pembayaran'),
                            'po' => array('📋', 'Rekapan PO'),
                            'tagihan' => array('💳', 'Tagihan'),
                            'warehouse' => array('📦', 'Warehouse'),
                            'keuangan' => array('💰', 'Keuangan'),
                        );
                        $first_admin_key = array_key_first( $admin_panels );
                        foreach ( $admin_panels as $pkey => $pinfo ):
                            $is_first = ( $pkey === $first_admin_key );
                        ?>
                            <div id="admin-nav-<?php echo esc_attr($pkey); ?>" class="admin-preview-nav" onclick="showAdminPanel('<?php echo esc_js($pkey); ?>')" style="padding: 6px 8px; border-radius: 6px; font-size: 0.6rem; font-weight: 700; cursor: pointer; transition: 0.15s; <?php echo $is_first ? 'background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff;' : 'color: var(--text-muted);'; ?>"><?php echo $pinfo[0] . ' ' . $pinfo[1]; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="padding: 16px; min-height: 220px;">

                        <!-- PANEL: RINGKASAN TOKO -->
                        <div id="admin-panel-ringkasan" class="admin-preview-panel" style="display: block;">
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark); margin-bottom: 10px;">Dashboard GO/WH/Proxy: Testy Go</div>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 10px;">
                                <?php
                                $mock_stats = array(
                                    array('Total PO Aktif', '8 Batch', '#8b5cf6'),
                                    array('Barang Sampai WH', '24 Item', '#0ea5e9'),
                                    array('Jalan ke Indo', '6 Item', '#f59e0b'),
                                    array('Sampai Indo', '11 Item', '#10b981'),
                                    array('Konfirmasi Pending', '3', '#ef4444'),
                                    array('Masa Aktif Sewa', '23 Hari', '#7c3aed'),
                                );
                                foreach ( $mock_stats as $ms ):
                                ?>
                                    <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 8px;">
                                        <div style="font-size: 0.45rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;"><?php echo esc_html($ms[0]); ?></div>
                                        <div style="font-size: 0.75rem; font-weight: 800; color: <?php echo esc_attr($ms[2]); ?>; margin-top: 2px;"><?php echo esc_html($ms[1]); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="background: #faf8ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; font-size: 0.55rem;">
                                <span style="color: var(--text-muted);">Paket Aktif: <strong style="color: #7c3aed;">Standard (1 GB)</strong></span>
                                <span style="padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #15803d; font-weight: 700;">Aktif & Terverifikasi</span>
                            </div>
                            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 12px;">
                                <div style="font-size: 0.55rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">STATUS AKUN & HAK AKSES FITUR</div>
                                <?php foreach ( array('Kalkulator Harga Jual', 'Kalkulator Publik', 'Pembayaran Pelanggan', 'Rekapan PO', 'Rekapan Tagihan', 'Database Warehouse', 'Laporan Keuangan', 'Status Langganan') as $feat ): ?>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.55rem; padding: 4px 0; border-bottom: 1px dashed var(--border-soft); color: var(--text-muted);">
                                        <span><?php echo esc_html($feat); ?></span><strong style="color: #10b981;">Aktif</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- PANEL: KALKULATOR -->
                        <div id="admin-panel-kalkulator" class="admin-preview-panel" style="display: none;">
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark); margin-bottom: 10px;">Kalkulator Harga Jual</div>
                            <div style="display: flex; gap: 5px; margin-bottom: 10px; flex-wrap: wrap;">
                                <span style="padding: 4px 9px; border-radius: 999px; font-size: 0.5rem; font-weight: 700; background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff;">🇨🇳 CNY</span>
                                <span style="padding: 4px 9px; border-radius: 999px; font-size: 0.5rem; font-weight: 700; background: #fff; border: 1px solid var(--border-soft); color: var(--text-muted);">🇯🇵 JPY</span>
                                <span style="padding: 4px 9px; border-radius: 999px; font-size: 0.5rem; font-weight: 700; background: #fff; border: 1px solid var(--border-soft); color: var(--text-muted);">🇹🇭 THB</span>
                                <span style="padding: 4px 9px; border-radius: 999px; font-size: 0.5rem; font-weight: 700; background: #fff; border: 1px solid var(--border-soft); color: var(--text-muted);">🇰🇷 KRW</span>
                                <span style="padding: 4px 9px; border-radius: 999px; font-size: 0.5rem; font-weight: 700; background: #fff; border: 1px solid var(--border-soft); color: var(--text-muted);">🇺🇸 USD</span>
                            </div>
                            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; font-size: 0.52rem; color: var(--text-muted);">
                                <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Harga Barang (¥250 × Rp2.450)</span><strong style="color:var(--text-dark);">Rp612.500</strong></div>
                                <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Ongkir Kargo (2kg × Rp140rb)</span><strong style="color:var(--text-dark);">Rp280.000</strong></div>
                                <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Pajak/Bea Masuk (7.5%)</span><strong style="color:var(--text-dark);">Rp45.900</strong></div>
                                <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Packing/Handling</span><strong style="color:var(--text-dark);">Rp15.000</strong></div>
                            </div>
                            <div style="background: linear-gradient(135deg, #a78bfa, #818cf8); border-radius: 8px; padding: 10px; text-align: center;">
                                <div style="font-size: 0.5rem; color: rgba(255,255,255,0.8);">Estimasi Harga Jual Total</div>
                                <div style="font-size: 0.95rem; font-weight: 800; color: #fff;">Rp 953.400</div>
                            </div>
                        </div>

                        <!-- PANEL: PEMBAYARAN -->
                        <div id="admin-panel-pembayaran" class="admin-preview-panel" style="display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark);">Pembayaran Pelanggan</div>
                                <span style="background:#ef4444; color:#fff; padding:2px 8px; border-radius:999px; font-size:0.5rem; font-weight:700;">2 PENDING</span>
                            </div>
                            <?php
                            $mock_payments = array(
                                array('PO Labubu Batch 1', 'DP', 'Rp100.000', 'Verified', '#10b981'),
                                array('Packing Jenaka', 'Packing', 'Rp50.000', 'Pending', '#f59e0b'),
                                array('Ongkir Wina', 'Ongkir WH', 'Rp75.000', 'Verified', '#10b981'),
                                array('PO Genshin #14', 'Pelunasan', 'Rp340.000', 'Pending', '#f59e0b'),
                                array('PO Standee Batch 2', 'Pajak', 'Rp45.900', 'Verified', '#10b981'),
                            );
                            foreach ( $mock_payments as $mp ):
                            ?>
                                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; font-size: 0.52rem;">
                                    <div><strong style="color: var(--text-dark);"><?php echo esc_html($mp[0]); ?></strong><br><span style="color: var(--text-muted);"><?php echo esc_html($mp[1]); ?> · <?php echo esc_html($mp[2]); ?></span></div>
                                    <span style="padding: 3px 8px; border-radius: 999px; font-weight: 700; background: <?php echo $mp[4]; ?>1a; color: <?php echo $mp[4]; ?>;"><?php echo esc_html($mp[3]); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- PANEL: REKAPAN PO -->
                        <div id="admin-panel-po" class="admin-preview-panel" style="display: none;">
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark); margin-bottom: 10px;">Rekapan Order PO</div>
                            <?php
                            $mock_orders = array(
                                array('PO Labubu Batch 1', 'Wina', 'Arrived WH', '#8b5cf6', '#f5f3ff'),
                                array('PO Genshin #14', 'Adriana', 'On Process', '#b45309', '#fef3c7'),
                                array('Packing an Jenaka', 'Jenaka', 'Antrean', '#64748b', '#f1f5f9'),
                                array('PO Standee Batch 2', 'Rizky', 'Shipped Indo', '#0369a1', '#e0f2fe'),
                                array('PO Acrylic Set', 'Devina', 'Done', '#15803d', '#dcfce7'),
                            );
                            foreach ( $mock_orders as $mo ):
                            ?>
                                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; font-size: 0.52rem;">
                                    <div><strong style="color: var(--text-dark);"><?php echo esc_html($mo[0]); ?></strong><br><span style="color: var(--text-muted);"><?php echo esc_html($mo[1]); ?></span></div>
                                    <span style="padding: 3px 8px; border-radius: 999px; font-weight: 700; background: <?php echo esc_attr($mo[4]); ?>; color: <?php echo esc_attr($mo[3]); ?>;"><?php echo esc_html($mo[2]); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- PANEL: TAGIHAN -->
                        <div id="admin-panel-tagihan" class="admin-preview-panel" style="display: none;">
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark); margin-bottom: 4px;">Rekapan Tagihan</div>
                            <div style="font-size: 0.5rem; color: var(--text-muted); margin-bottom: 8px;">PO Labubu Batch 1 — 4 tagihan tercatat</div>
                            <?php
                            $mock_invoices = array(
                                array('💰 DP', 'Rp100.000', 'Lunas', '#10b981'),
                                array('🚚 Ongkir WH', 'Rp30.000', 'Lunas', '#10b981'),
                                array('🧾 Pajak', 'Rp35.000', 'Belum Bayar', '#ef4444'),
                                array('✅ Pelunasan', 'Rp447.500', 'Belum Bayar', '#ef4444'),
                            );
                            foreach ( $mock_invoices as $mi ):
                            ?>
                                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; font-size: 0.52rem;">
                                    <div><strong style="color: var(--text-dark);"><?php echo esc_html($mi[0]); ?></strong> <span style="color: var(--text-muted);">· <?php echo esc_html($mi[1]); ?></span></div>
                                    <span style="padding: 3px 8px; border-radius: 999px; font-weight: 700; background: <?php echo $mi[3]; ?>1a; color: <?php echo $mi[3]; ?>;"><?php echo esc_html($mi[2]); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- PANEL: WAREHOUSE -->
                        <div id="admin-panel-warehouse" class="admin-preview-panel" style="display: none;">
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Database Warehouse</div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px;">
                                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 8px;">
                                    <div style="font-weight: 800; color: #7c3aed; font-size: 0.55rem;">📦 WH-CN-AUG26</div>
                                    <div style="background: var(--border-soft); border-radius: 999px; height: 5px; width: 100%; overflow: hidden; margin: 5px 0;"><div style="background: #7c3aed; height: 100%; width: 72%;"></div></div>
                                    <div style="font-size: 0.45rem; color: var(--text-muted);">14.5/20 Kg (72%)</div>
                                </div>
                                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 8px;">
                                    <div style="font-weight: 800; color: #7c3aed; font-size: 0.55rem;">📦 WH-JP-AUG26</div>
                                    <div style="background: var(--border-soft); border-radius: 999px; height: 5px; width: 100%; overflow: hidden; margin: 5px 0;"><div style="background: #f59e0b; height: 100%; width: 95%;"></div></div>
                                    <div style="font-size: 0.45rem; color: var(--text-muted);">9.5/10 Kg (95%)</div>
                                </div>
                            </div>
                            <?php
                            $mock_wh_items = array(
                                array('🖼️', 'SF674XXX', 'Guangzhou WH', 'Di Gudang'),
                                array('🖼️', 'YT982XXX', 'Tokyo WH', 'Siap Kirim'),
                                array('—', 'JD315XXX', 'Guangzhou WH', 'Menunggu'),
                            );
                            foreach ( $mock_wh_items as $wi ):
                            ?>
                                <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 6px 10px; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center; font-size: 0.5rem;">
                                    <span><?php echo $wi[0]; ?> <strong style="color: var(--text-dark);"><?php echo esc_html($wi[1]); ?></strong> <span style="color:var(--text-muted);"><?php echo esc_html($wi[2]); ?></span></span>
                                    <span style="padding: 2px 7px; border-radius: 999px; background: #fef3c7; color: #b45309; font-weight: 700;"><?php echo esc_html($wi[3]); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- PANEL: KEUANGAN -->
                        <div id="admin-panel-keuangan" class="admin-preview-panel" style="display: none;">
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Laporan Keuangan</div>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; margin-bottom: 8px;">
                                <div style="background: #fff; border-left: 3px solid #10b981; border-radius: 8px; padding: 8px;">
                                    <div style="font-size: 0.45rem; color: var(--text-muted); text-transform: uppercase;">Total Pemasukan</div>
                                    <div style="font-size: 0.72rem; font-weight: 800; color: var(--text-dark);">Rp2.450.000</div>
                                </div>
                                <div style="background: #fff; border-left: 3px solid #ef4444; border-radius: 8px; padding: 8px;">
                                    <div style="font-size: 0.45rem; color: var(--text-muted); text-transform: uppercase;">Piutang</div>
                                    <div style="font-size: 0.72rem; font-weight: 800; color: var(--text-dark);">Rp482.500</div>
                                </div>
                                <div style="background: #fff; border-left: 3px solid #0ea5e9; border-radius: 8px; padding: 8px;">
                                    <div style="font-size: 0.45rem; color: var(--text-muted); text-transform: uppercase;">Dompet Alipay</div>
                                    <div style="font-size: 0.72rem; font-weight: 800; color: var(--text-dark);">Rp590.000</div>
                                </div>
                                <div style="background: #fff; border-left: 3px solid #7c3aed; border-radius: 8px; padding: 8px;">
                                    <div style="font-size: 0.45rem; color: var(--text-muted); text-transform: uppercase;">Dompet Cash</div>
                                    <div style="font-size: 0.72rem; font-weight: 800; color: var(--text-dark);">Rp300.000</div>
                                </div>
                            </div>
                            <div style="background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; padding: 8px 10px; font-size: 0.5rem;">
                                <div style="font-weight: 800; color: var(--text-dark); margin-bottom: 4px;">Mutasi Terbaru</div>
                                <div style="display:flex; justify-content:space-between; color: var(--text-muted); padding: 2px 0;"><span>Top Up Alipay</span><strong style="color:#10b981;">+Rp500.000</strong></div>
                                <div style="display:flex; justify-content:space-between; color: var(--text-muted); padding: 2px 0;"><span>Belanja Supplier</span><strong style="color:#ef4444;">-Rp210.000</strong></div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- PREVIEW 2: TENANT WEB PUBLIK -->
        <div>
            <h3 style="font-size: 1.05rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 14px; display: flex; align-items: center; gap: 8px;"><i class="fa-solid fa-globe"></i> Portal Publik (Buat Customer Kamu)</h3>
            <div style="background: #1e1b2e; border-radius: 16px; padding: 10px; box-shadow: 0 20px 40px -10px rgba(30,27,46,0.3);">
                <div style="display: flex; align-items: center; gap: 8px; padding: 6px 8px;">
                    <span style="width:10px; height:10px; border-radius:50%; background:#ef4444; display:inline-block;"></span>
                    <span style="width:10px; height:10px; border-radius:50%; background:#f59e0b; display:inline-block;"></span>
                    <span style="width:10px; height:10px; border-radius:50%; background:#22c55e; display:inline-block;"></span>
                    <div style="background: rgba(255,255,255,0.1); border-radius: 6px; padding: 3px 10px; font-size: 0.55rem; color: rgba(255,255,255,0.6); margin-left: 6px;">go-web.cloud/testy-go/</div>
                </div>
                <div style="background: #faf8ff; border-radius: 10px; padding: 20px;">
                    <div style="text-align: center; margin-bottom: 14px;">
                        <div style="font-size: 0.85rem; font-weight: 800; color: var(--text-dark);">Portal Pelanggan & Monitoring</div>
                        <div style="font-size: 0.6rem; color: var(--text-muted); margin-top: 2px;">Akses kalkulator, cek status PO, hingga konfirmasi pembayaran</div>
                    </div>
                    <div style="display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin-bottom: 16px;">
                        <?php
                        $tenant_panels = array(
                            'kalkulator' => array('🧮', 'Kalkulator Biaya'),
                            'po' => array('📋', 'Rekapan PO'),
                            'tagihan' => array('💳', 'Tagihan'),
                            'resi' => array('📦', 'Slot & Resi WH'),
                            'bayar' => array('🧾', 'Konfirmasi Bayar'),
                        );
                        $first_tenant_key = array_key_first( $tenant_panels );
                        foreach ( $tenant_panels as $tkey => $tinfo ):
                            $is_first_t = ( $tkey === $first_tenant_key );
                        ?>
                            <div id="tenant-nav-<?php echo esc_attr($tkey); ?>" class="tenant-preview-nav" onclick="showTenantPanel('<?php echo esc_js($tkey); ?>')" style="padding: 6px 12px; border-radius: 999px; font-size: 0.55rem; font-weight: 700; cursor: pointer; transition: 0.15s; <?php echo $is_first_t ? 'background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff;' : 'background: #fff; border: 1px solid var(--border-soft); color: var(--text-muted);'; ?>"><?php echo $tinfo[0] . ' ' . $tinfo[1]; ?></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- PANEL: KALKULATOR -->
                    <div id="tenant-panel-kalkulator" class="tenant-preview-panel" style="display: block; background: #fff; border: 1px solid var(--border-soft); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 0.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Kalkulator Estimasi / Biaya</div>
                        <div style="display: flex; gap: 5px; margin-bottom: 8px;">
                            <span style="padding: 3px 9px; border-radius: 999px; font-size: 0.48rem; font-weight: 700; background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff;">✈️ Kargo Udara</span>
                            <span style="padding: 3px 9px; border-radius: 999px; font-size: 0.48rem; font-weight: 700; background: #f8fafc; border: 1px solid var(--border-soft); color: var(--text-muted);">🚢 Kargo Laut</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 8px; margin-bottom: 8px; font-size: 0.5rem; color: var(--text-muted);">
                            <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Harga Barang</span><strong style="color:var(--text-dark);">Rp245.000</strong></div>
                            <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Ongkir (2kg)</span><strong style="color:var(--text-dark);">Rp280.000</strong></div>
                            <div style="display:flex; justify-content:space-between; padding:2px 0;"><span>Pajak & Packing</span><strong style="color:var(--text-dark);">Rp60.900</strong></div>
                        </div>
                        <div style="background: linear-gradient(135deg, #a78bfa, #818cf8); border-radius: 8px; padding: 10px; text-align: center;">
                            <div style="font-size: 0.5rem; color: rgba(255,255,255,0.8);">Estimasi Total</div>
                            <div style="font-size: 0.95rem; font-weight: 800; color: #fff;">Rp 585.900</div>
                        </div>
                    </div>

                    <!-- PANEL: REKAPAN PO -->
                    <div id="tenant-panel-po" class="tenant-preview-panel" style="display: none; background: #fff; border: 1px solid var(--border-soft); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 0.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Rekapan Order PO</div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">PO Labubu Batch 1</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #f5f3ff; color: #8b5cf6; font-weight: 700;">Arrived WH</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">PO Genshin #14</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #fef3c7; color: #b45309; font-weight: 700;">On Process</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">PO Acrylic Set</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #15803d; font-weight: 700;">Done</span>
                        </div>
                    </div>

                    <!-- PANEL: TAGIHAN -->
                    <div id="tenant-panel-tagihan" class="tenant-preview-panel" style="display: none; background: #fff; border: 1px solid var(--border-soft); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 0.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Status Tagihan — PO Labubu Batch 1</div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">💰 DP</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #15803d; font-weight: 700;">Lunas</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">🚚 Ongkir WH</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #15803d; font-weight: 700;">Lunas</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">🧾 Pajak</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-weight: 700;">Belum Bayar</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; display: flex; justify-content: space-between; font-size: 0.52rem;">
                            <span style="font-weight: 700; color: var(--text-dark);">✅ Pelunasan</span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-weight: 700;">Belum Bayar</span>
                        </div>
                    </div>

                    <!-- PANEL: SLOT & RESI WH -->
                    <div id="tenant-panel-resi" class="tenant-preview-panel" style="display: none; background: #fff; border: 1px solid var(--border-soft); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 0.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Rekapan Kedatangan Warehouse</div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; font-size: 0.52rem;">
                            <span>🖼️ <strong style="color: var(--text-dark);">SF674XXX</strong></span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #fef3c7; color: #b45309; font-weight: 700;">Di Gudang</span>
                        </div>
                        <div style="background: #f8fafc; border-radius: 6px; padding: 7px 10px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 0.52rem;">
                            <span>🖼️ <strong style="color: var(--text-dark);">YT982XXX</strong></span>
                            <span style="padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #15803d; font-weight: 700;">Siap Kirim</span>
                        </div>
                        <div style="background: #faf8ff; border: 1px dashed #c4b5fd; border-radius: 6px; padding: 8px;">
                            <div style="font-size: 0.55rem; font-weight: 700; color: #7c3aed;">📦 Box: WH-CN-AUG26</div>
                            <div style="background: var(--border-soft); border-radius: 999px; height: 4px; width: 100%; overflow: hidden; margin: 4px 0;"><div style="background: #7c3aed; height: 100%; width: 72%;"></div></div>
                            <div style="font-size: 0.48rem; color: var(--text-muted);">14.5/20 Kg (72%) · Packing In Progress</div>
                        </div>
                    </div>

                    <!-- PANEL: KONFIRMASI BAYAR -->
                    <div id="tenant-panel-bayar" class="tenant-preview-panel" style="display: none; background: #fff; border: 1px solid var(--border-soft); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 0.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Konfirmasi Pembayaran</div>
                        <div style="font-size: 0.48rem; color: var(--text-muted); margin-bottom: 3px;">Jenis Pembayaran</div>
                        <div style="background: #f8fafc; border: 1px solid var(--border-soft); border-radius: 6px; padding: 7px 10px; margin-bottom: 6px; font-size: 0.52rem; color: var(--text-dark); font-weight: 700; display:flex; justify-content:space-between;">💰 DP / Uang Muka Barang <span style="color:var(--text-muted);">▾</span></div>
                        <div style="font-size: 0.48rem; color: var(--text-muted); margin-bottom: 3px;">Nama PO / Referensi</div>
                        <div style="background: #f8fafc; border: 1px solid var(--border-soft); border-radius: 6px; padding: 7px 10px; margin-bottom: 6px; font-size: 0.52rem; color: var(--text-dark);">PO Labubu Batch 1</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px;">
                            <div>
                                <div style="font-size: 0.48rem; color: var(--text-muted); margin-bottom: 3px;">Nominal</div>
                                <div style="background: #f8fafc; border: 1px solid var(--border-soft); border-radius: 6px; padding: 7px 10px; font-size: 0.52rem; color: var(--text-dark); font-weight: 700;">Rp100.000</div>
                            </div>
                            <div>
                                <div style="font-size: 0.48rem; color: var(--text-muted); margin-bottom: 3px;">Bukti Transfer</div>
                                <div style="background: #f8fafc; border: 1px dashed var(--border-soft); border-radius: 6px; padding: 7px 10px; font-size: 0.5rem; color: var(--text-muted);">📎 upload.jpg</div>
                            </div>
                        </div>
                        <div style="background: linear-gradient(135deg, #a78bfa, #818cf8); border-radius: 8px; padding: 8px; text-align: center; font-size: 0.55rem; font-weight: 700; color: #fff;">Kirim Konfirmasi Pembayaran</div>
                    </div>

                </div>
            </div>
        </div>
    </section>

    <script>
    function showAdminPanel(key) {
        document.querySelectorAll('.admin-preview-panel').forEach(p => p.style.display = 'none');
        const panel = document.getElementById('admin-panel-' + key);
        if (panel) panel.style.display = 'block';
        document.querySelectorAll('.admin-preview-nav').forEach(n => {
            n.style.background = 'transparent';
            n.style.color = '#7c7794';
        });
        const nav = document.getElementById('admin-nav-' + key);
        if (nav) { nav.style.background = 'linear-gradient(135deg, #a78bfa, #818cf8)'; nav.style.color = '#fff'; }
    }
    function showTenantPanel(key) {
        document.querySelectorAll('.tenant-preview-panel').forEach(p => p.style.display = 'none');
        const panel = document.getElementById('tenant-panel-' + key);
        if (panel) panel.style.display = 'block';
        document.querySelectorAll('.tenant-preview-nav').forEach(n => {
            n.style.background = '#fff';
            n.style.border = '1px solid #ede9fe';
            n.style.color = '#7c7794';
        });
        const nav = document.getElementById('tenant-nav-' + key);
        if (nav) { nav.style.background = 'linear-gradient(135deg, #a78bfa, #818cf8)'; nav.style.border = 'none'; nav.style.color = '#fff'; }
    }
    </script>

    <!-- HARGA / PRICING -->
    <section class="container" style="padding: 20px 24px 60px;">
        <div style="text-align: center; margin-bottom: 36px;">
            <h2 style="font-size: 1.6rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px;">Pilih Paket Sesuai Kebutuhan</h2>
            <p style="color: var(--text-muted);">Semua paket dapet fitur yang sama — bedanya cuma kapasitas storage. Bisa upgrade kapan aja.</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px;">
            <?php foreach ( $landing_packages as $pkg ):
                $storage_display = $pkg['storage_limit'] >= 1024 ? round($pkg['storage_limit'] / 1024, 1) . ' GB' : $pkg['storage_limit'] . ' MB';
                $is_popular = ( $pkg['key'] === 'standard' );
            ?>
                <div style="background: #fff; border: 1.5px solid <?php echo $is_popular ? '#a78bfa' : 'var(--border-soft)'; ?>; border-radius: var(--radius-lg); padding: 20px; text-align: center; position: relative; <?php echo $is_popular ? 'box-shadow: 0 10px 25px rgba(139,92,246,0.15); transform: scale(1.03);' : ''; ?>">
                    <?php if ($is_popular): ?><div style="position:absolute; top:-10px; left:50%; transform:translateX(-50%); background:#a78bfa; color:#fff; font-size:0.55rem; font-weight:800; padding:3px 12px; border-radius:999px;">TERPOPULER</div><?php endif; ?>
                    <div style="font-weight: 800; font-size: 0.95rem; color: var(--text-dark); margin-top: 6px;"><?php echo esc_html($pkg['name']); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin: 4px 0 10px;"><?php echo esc_html($storage_display); ?> Storage</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 4px;">Rp<?php echo number_format($pkg['price'], 0, ',', '.'); ?></div>
                    <div style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 14px;">/ bulan</div>
                    <a href="<?php echo esc_url(home_url('/register')); ?>" style="display: block; padding: 9px; border-radius: 10px; font-size: 0.75rem; font-weight: 700; text-decoration: none; <?php echo $is_popular ? 'background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff;' : 'background: #f8fafc; color: var(--text-dark); border: 1px solid var(--border-soft);'; ?>">Pilih Paket</a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- CTA FINAL -->
    <section style="background: linear-gradient(135deg, #a78bfa, #818cf8); padding: 50px 24px; text-align: center;">
        <div class="container">
            <h2 style="font-size: 1.5rem; font-weight: 800; color: #fff; margin-bottom: 10px;">Siap Buka Toko Online Kamu?</h2>
            <p style="color: rgba(255,255,255,0.85); margin-bottom: 22px;">Daftar sekarang, toko kamu langsung online setelah diverifikasi.</p>
            <a href="<?php echo esc_url(home_url('/register')); ?>" style="display: inline-flex; align-items: center; gap: 8px; background: #fff; color: var(--pastel-purple); padding: 14px 32px; border-radius: 999px; font-weight: 800; text-decoration: none;">
                <i class="fa-solid fa-rocket"></i> Daftar Sekarang
            </a>
        </div>
    </section>
</main>

<?php get_footer(); ?>