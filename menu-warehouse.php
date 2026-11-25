<?php
if (!defined('ABSPATH')) exit;
if (!$has_wh) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup!</div>';
    return;
}
?>
<div class="fin-wrap">
    <div class="menu-warehouse-heading" style="margin-bottom: 24px;">
        <div class="menu-warehouse-heading-row">
            <span class="menu-warehouse-module-icon" aria-hidden="true"><i class="fa-solid fa-warehouse"></i></span>
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin-bottom: 6px;">Database Warehouse</h2>
                <p style="color: var(--text-muted); font-size: 0.875rem;">Tracking fisik barang di gudang — resi masuk, foto bukti dan konsolidasi ke Box sebelum dikirim. <?php echo $wh_link_mode === 'linked' ? 'Setiap entri wajib nyambung ke Rekapan PO.' : 'Entri berdiri bebas, gak wajib nyambung ke order manapun.'; ?></p>
            </div>
        </div>
    </div>
    <style>
        .menu-warehouse-heading-row { display: flex; align-items: center; gap: 14px; }
        .menu-warehouse-module-icon {
            display: grid;
            flex: 0 0 48px;
            width: 48px;
            height: 48px;
            place-items: center;
            border: 1px solid #ded7e9;
            border-radius: 14px;
            background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
            color: var(--primary);
            font-size: 1.08rem;
        }
        .wh-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
        .wh-form-span { grid-column: 1 / -1; }
        .wh-photo-field { display: grid; gap: 6px; }
        .wh-photo-field input[type="file"] {
            width: 100%;
            min-width: 0;
            padding: 9px;
            border: 1px solid #e5e0f2;
            border-radius: 10px;
            background: #faf9fc;
            color: #514b63;
            font: inherit;
            font-size: .75rem;
        }
        .wh-photo-field input[type="file"]::file-selector-button {
            margin: -3px 10px -3px -3px;
            padding: 8px 11px;
            border: 1px solid #ded7e9;
            border-radius: 8px;
            background: #fff;
            color: #594a75;
            font: inherit;
            font-size: .72rem;
            font-weight: 750;
            cursor: pointer;
        }
        .wh-photo-field input[type="file"]:focus-visible {
            outline: 3px solid rgba(109, 90, 145, .32);
            outline-offset: 2px;
        }
        .wh-box-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); gap: 14px; }
        .wh-page-button {
            display: inline-grid;
            min-width: 40px;
            min-height: 40px;
            place-items: center;
            padding: 7px 12px;
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            background: #ffffff;
            color: var(--text-dark);
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            transition: background-color .18s ease, border-color .18s ease, color .18s ease;
        }
        .wh-page-button:hover:not(:disabled):not(.is-active) { background: var(--pastel-purple-bg); border-color: var(--primary); }
        .wh-page-button.is-active { background: var(--primary); border-color: var(--primary); color: #ffffff; }
        .wh-page-button:disabled { background: var(--slate-50); color: var(--text-muted); cursor: not-allowed; opacity: .65; }
        @media (max-width: 600px) {
            .menu-warehouse-heading-row { align-items: flex-start; gap: 11px; }
            .menu-warehouse-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
            .wh-form-grid { grid-template-columns: minmax(0, 1fr); }
            .wh-table-tools { flex-wrap: wrap; }
            .wh-table-tools #wh-search, .wh-table-tools button { width: 100%; }
        }
    </style>

    <!-- SECTION BOX KONSOLIDASI -->
    <div style="background: #ffffff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04); margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px;"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Box Konsolidasi</h3>
                <p style="font-size: 12.5px; color: var(--text-muted);">Kumpulan item Warehouse dari beberapa customer yang dikirim bareng dalam 1 paket besar.</p>
            </div>
            <button type="button" onclick="document.getElementById('create-box-form-wrap').style.display = document.getElementById('create-box-form-wrap').style.display === 'none' ? 'block' : 'none';" style="background: var(--primary); color: #fff; border: none; padding: 9px 16px; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer;"><i class="fa-solid fa-plus" aria-hidden="true"></i> Buat Box Baru</button>
        </div>

        <div id="create-box-form-wrap" style="display: none; background: var(--bg-page); border: 1.5px dashed #c4b5fd; border-radius: var(--radius-md); padding: 16px; margin-bottom: 18px;">
            <form method="POST" action="">
                <?php wp_nonce_field('goweb_wh_action_' . $store_id, 'goweb_wh_nonce'); ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 12px;">
                    <div class="form-group-fin">
                        <label class="form-label-fin">Nama Box:</label>
                        <input type="text" name="box_name" class="form-input-fin" placeholder="Misal: WH-CN-AUG26" required>
                    </div>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Lokasi:</label>
                        <input type="text" name="box_lokasi" class="form-input-fin" placeholder="Misal: Guangzhou">
                    </div>
                    <div class="form-group-fin">
                        <label class="form-label-fin">Kapasitas (Kg):</label>
                        <input type="number" name="box_kapasitas" class="form-input-fin" step="0.1" min="0.1" value="20" required>
                    </div>
                </div>
                <button type="submit" name="action_create_box" class="btn-submit-action btn-blue" style="width: auto; padding: 9px 20px;">Simpan Box</button>
            </form>
        </div>

        <?php if ( empty($tenant_boxes_rows) ): ?>
            <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 13.5px;">Belum ada Box. Buat 1 dulu buat mulai konsolidasi item Warehouse.</div>
        <?php else: ?>
            <div class="wh-box-grid">
                <?php foreach ( $tenant_boxes_rows as$box ):
                    $pct =$box['kapasitas_kg'] > 0 ? min(100, round(($box['filled_kg'] /$box['kapasitas_kg']) * 100)) : 0;
                ?>
                    <div style="border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: 16px;">
                        <div style="font-weight: 800; color: var(--primary); font-size: 13.5px; margin-bottom: 2px;"><i class="fa-solid fa-box" aria-hidden="true"></i> <?php echo esc_html($box['box_name']); ?></div>
                        <?php if ( ! empty($box['lokasi']) ): ?><div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 10px;"><?php echo esc_html($box['lokasi']); ?></div><?php endif; ?>

                        <div style="display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); margin-bottom: 4px;">
                            <span>Terisi</span>
                            <span><?php echo esc_html($box['filled_kg']); ?> Kg / <?php echo esc_html($box['kapasitas_kg']); ?> Kg (<?php echo$pct; ?>%)</span>
                        </div>
                        <div style="background: var(--border-soft); border-radius: 999px; height: 7px; width: 100%; overflow: hidden; margin-bottom: 12px;">
                            <div style="background: <?php echo $pct >= 100 ? '#ef4444' : 'var(--primary)'; ?>; height: 100%; width: <?php echo $pct; ?>%;"></div>
                        </div>

                        <div style="display: flex; gap: 6px; align-items: center;">
                            <select onchange="submitWhAction({ action_update_box_status: '1', box_id: '<?php echo esc_js($box['id']); ?>', new_status: this.value });" style="flex:1; padding: 6px 8px; border-radius: 8px; border: 1.5px solid var(--border-soft); font-weight: 700; font-size: 11.5px;">
                                <?php foreach ( $box_statuses as$bst ): ?>
                                    <option value="<?php echo esc_attr($bst); ?>" <?php selected($box['status'], $bst); ?>><?php echo esc_html($bst); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="goweb_confirmSubmit({ action_delete_box: '1', box_id: '<?php echo esc_js($box['id']); ?>' }, 'Hapus Box ini? Item yang nempel akan dilepas (gak ikut kehapus).')" aria-label="Hapus box" title="Hapus box" style="background:#fee2e2; color:#ef4444; border:none; padding: 6px 9px; border-radius:8px; cursor:pointer; font-size: 11.5px;"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- FORM TAMBAH ENTRI WAREHOUSE -->
    <div style="background: #ffffff; border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 24px 28px; box-shadow: 0 4px 15px -2px rgba(15, 23, 42, 0.04); margin-bottom: 24px;">
        <h3 style="font-size: 16px; font-weight: 800; color: var(--text-dark); margin-bottom: 16px;"><i class="fa-solid fa-plus" aria-hidden="true"></i> Tambah Entri Warehouse</h3>

        <form method="POST" action="" id="wh-add-form" enctype="multipart/form-data">
            <?php wp_nonce_field('goweb_wh_action_' . $store_id, 'goweb_wh_nonce'); ?>

            <div class="wh-form-grid">
                <?php if ( $wh_link_mode === 'linked' ): ?>
                    <div class="form-group-fin wh-form-span" style="position: relative;">
                        <label class="form-label-fin">Pilih PO / Order:</label>
                        <input type="text" id="wh_order_search" class="form-input-fin" placeholder="Ketik nama PO/Invoice atau nama customer..." autocomplete="off" <?php echo empty($tenant_orders_rows) ? 'disabled' : ''; ?>>
                        <input type="hidden" name="wh_order_id" id="wh_order_id_hidden" required>
                        <div id="wh_order_suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:50; background:#fff; border:1.5px solid var(--border-soft); border-radius:10px; margin-top:4px; max-height:220px; overflow-y:auto; box-shadow:0 8px 20px -4px rgba(15,23,42,0.12);"></div>
                        <?php if ( empty($tenant_orders_rows) ): ?>
                            <small style="color: var(--text-muted); font-size: 11.5px;">Belum ada PO/order tercatat di Rekapan PO.</small>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="form-group-fin wh-form-span">
                        <label class="form-label-fin">Nama Pemesan:</label>
                        <input type="text" name="wh_buyer_name" class="form-input-fin" placeholder="Misal: Adriana S." required>
                    </div>
                <?php endif; ?>

                <div class="form-group-fin">
                    <label class="form-label-fin">No. Resi Masuk:</label>
                    <input type="text" name="wh_resi_masuk" class="form-input-fin" placeholder="Misal: SF1234567890CN">
                </div>

                <div class="form-group-fin">
                    <label class="form-label-fin">Lokasi WH:</label>
                    <input type="text" name="wh_lokasi" class="form-input-fin" placeholder="Misal: Guangzhou WH">
                </div>

                <div class="form-group-fin">
                    <label class="form-label-fin">Slot / Rak:</label>
                    <input type="text" name="wh_slot" class="form-input-fin" placeholder="Misal: A-12">
                </div>

                <div class="form-group-fin">
                    <label class="form-label-fin">Berat (Kg):</label>
                    <input type="number" name="wh_berat" class="form-input-fin" step="0.01" min="0" placeholder="Misal: 2.5">
                </div>

                <div class="form-group-fin">
                    <label class="form-label-fin">Tanggal Masuk:</label>
                    <input type="date" name="wh_tanggal_masuk" class="form-input-fin" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                </div>

                <div class="form-group-fin wh-form-span wh-photo-field">
                    <label class="form-label-fin" for="wh-foto-bukti"><i class="fa-solid fa-camera" aria-hidden="true"></i> Foto Bukti Kondisi Paket (opsional):</label>
                    <input type="file" name="wh_foto_bukti" id="wh-foto-bukti" accept="image/*">
                </div>
            </div>

            <button type="submit" name="action_add_warehouse" class="btn-submit-action btn-blue"><i class="fa-solid fa-warehouse" aria-hidden="true"></i> Simpan Entri Warehouse</button>
        </form>
    </div>

    <!-- TABEL DAFTAR ENTRI WAREHOUSE DENGAN PAGINATION -->
    <div class="card-table-wrap">
        <div class="wh-table-tools" style="display: flex; gap: 10px; margin-bottom: 16px;">
            <input type="text" id="wh-search" placeholder="Cari nama, resi atau slot..." class="form-input-fin" style="flex:1; padding: 10px 14px;" oninput="filterWhTable(this.value)">
            <button type="button" onclick="downloadWarehouseCSV()" style="background: #15803d; color: #fff; border: none; padding: 10px 18px; border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 13px; white-space: nowrap;"><i class="fa-solid fa-download" aria-hidden="true"></i> Download CSV</button>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table" id="wh-table">
                <thead>
                    <tr>
                        <th>Foto</th>
                        <th>Nama / PO</th>
                        <th>Resi Masuk</th>
                        <th>Lokasi WH</th>
                        <th>Berat</th>
                        <th>Status</th>
                        <th>Box</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="wh-rows">
                    <?php if ( !empty($tenant_warehouse_rows) ): ?>
                        <?php foreach ( $tenant_warehouse_rows as$wh ): ?>
                            <tr class="wh-row-item" data-search="<?php echo esc_attr(strtolower(($wh['batch'] ?? '') . ' ' .$wh['buyer_name'] . ' ' . $wh['resi_masuk'] . ' ' .$wh['slot'])); ?>" style="display:none;">
                                <td>
                                    <?php if ( ! empty($wh['foto_bukti']) ): ?>
                                        <a href="<?php echo esc_url($wh['foto_bukti']); ?>" target="_blank"><img src="<?php echo esc_url($wh['foto_bukti']); ?>" style="width:36px; height:36px; object-fit:cover; border-radius:6px; border:1px solid var(--border-soft);"></a>
                                    <?php else: ?>
                                        <span style="color:var(--slate-400);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($wh['buyer_name']); ?></strong>
                                    <?php if ( ! empty($wh['batch']) ): ?><br><small style="color:var(--text-muted);"><?php echo esc_html($wh['batch']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo esc_html($wh['resi_masuk'] ?: '-'); ?></td>
                                <td><?php echo esc_html($wh['lokasi_wh'] ?: '-'); ?></td>
                                <td><?php echo $wh['berat'] > 0 ? esc_html($wh['berat']) . ' kg' : '-'; ?></td>
                                <td>
                                    <select onchange="submitWhAction({ action_update_wh_status: '1', wh_id: '<?php echo esc_js($wh['id']); ?>', new_status: this.value });" style="padding: 6px 8px; border-radius: 8px; border: 1.5px solid var(--border-soft); font-weight: 700; font-size: 12px;">
                                        <?php foreach ( $wh_statuses as$st ): ?>
                                            <option value="<?php echo esc_attr($st); ?>" <?php selected($wh['status'], $st); ?>><?php echo esc_html($st); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if ( empty($tenant_boxes_rows) ): ?>
                                        <span style="color:var(--slate-400); font-size:11.5px;">Belum ada Box</span>
                                    <?php else: ?>
                                        <select onchange="submitWhAction({ action_assign_to_box: '1', wh_id: '<?php echo esc_js($wh['id']); ?>', assign_box_id: this.value });" style="padding: 6px 8px; border-radius: 8px; border: 1.5px solid var(--border-soft); font-weight: 700; font-size: 12px;">
                                            <option value="0" <?php selected(empty($wh['box_id'])); ?>>— Belum di-assign —</option>
                                            <?php foreach ( $tenant_boxes_rows as$box ): ?>
                                                <option value="<?php echo esc_attr($box['id']); ?>" <?php selected($wh['box_id'], $box['id']); ?>><?php echo esc_html($box['box_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="goweb_confirmSubmit({ action_delete_warehouse: '1', wh_id: '<?php echo esc_js($wh['id']); ?>' }, 'Hapus entri warehouse ini?')" aria-label="Hapus entri warehouse" title="Hapus entri warehouse" style="background:#fee2e2; color:#ef4444; border:none; padding: 6px 10px; border-radius:6px; cursor:pointer;"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="empty-wh-state"><td colspan="8" style="text-align:center; color: var(--slate-400); padding: 30px;">Belum ada entri warehouse tercatat.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- ELEMENT PAGINATION BAWAH -->
        <div id="wh-pagination-container" style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; flex-wrap: wrap; gap: 10px;">
            <div style="font-size: 13px; color: var(--text-muted);">Menampilkan <strong id="wh-page-info">0 - 0 dari 0</strong> entri</div>
            <div id="wh-page-buttons" style="display: flex; gap: 4px; flex-wrap: wrap;"></div>
        </div>
    </div>

    <!-- MODAL KONFIRMASI GENERIC -->
    <div class="modal-overlay" id="genericConfirmModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;">
        <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 400px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
            <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;"><i class="fa-solid fa-circle-question"></i></div>
            <p id="genericConfirmMessage" style="font-size: 0.95rem; font-weight: 700; color: var(--slate-900); margin-bottom: 24px;"></p>
            <div style="display: flex; gap: 10px;">
                <button type="button" id="genericConfirmCancel" style="flex: 1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; color: var(--text-muted); cursor: pointer;">Batal</button>
                <button type="button" id="genericConfirmOk" style="flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-weight: 800; cursor: pointer;">Ya, Hapus</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const GOWEB_WH_NONCE = <?php echo wp_json_encode( wp_create_nonce('goweb_wh_action_' . $store_id) ); ?>;
    const GOWEB_WH_ACTION_URL = <?php echo wp_json_encode( home_url('/dashboard/warehouse/') . $store_query_suffix ); ?>;

    window.submitWhAction = function( fields ) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = GOWEB_WH_ACTION_URL;
        form.style.display = 'none';
        const baseFields = Object.assign( { goweb_wh_nonce: GOWEB_WH_NONCE }, fields );
        Object.keys( baseFields ).forEach( key => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = baseFields[key];
            form.appendChild( input );
        } );
        document.body.appendChild( form );
        form.submit();
    };

    let pendingConfirmFields = null;
    window.goweb_confirmSubmit = function( fields, message ) {
        pendingConfirmFields = fields;
        document.getElementById('genericConfirmMessage').textContent = message;
        document.getElementById('genericConfirmModal').style.display = 'flex';
    };

    // --- LOGIKA PAGINATION ---
    let currentPage = 1;
    const rowsPerPage = 25; // jumlah baris yang tampil per halaman
    let allRows = [];
    let filteredRows = [];

    function initPagination() {
        allRows = Array.from(document.querySelectorAll('.wh-row-item'));
        filteredRows = allRows; // Default: Semua data sebelum di-search
        renderTablePage();
    }

    function renderTablePage() {
        const totalRows = filteredRows.length;
        const totalPages = Math.ceil(totalRows / rowsPerPage);
        
        // Jaga-jaga kalau filter bikin jumlah halaman ngecil drastis
        if (currentPage > totalPages) currentPage = totalPages > 0 ? totalPages : 1;

        const startIdx = (currentPage - 1) * rowsPerPage;
        const endIdx = startIdx + rowsPerPage;

        // Sembunyikan SEMUA baris dulu
        allRows.forEach(row => row.style.display = 'none');

        // Tampilkan baris HANYA untuk halaman yang aktif sekarang
        for (let i = startIdx; i < endIdx && i < totalRows; i++) {
            filteredRows[i].style.display = '';
        }

        // Update tulisan "Menampilkan x-y dari Z"
        const startDisplay = totalRows === 0 ? 0 : startIdx + 1;
        const endDisplay = Math.min(endIdx, totalRows);
        document.getElementById('wh-page-info').innerHTML = `${startDisplay} - ${endDisplay} dari ${totalRows}`;

        renderPaginationButtons(totalPages);
    }

    function renderPaginationButtons(totalPages) {
        const container = document.getElementById('wh-page-buttons');
        container.innerHTML = '';

        if (totalPages <= 1) return; // Kalo cuma 1 halaman, sembunyiin tombolnya

        const createBtn = (text, page, disabled = false, active = false) => {
            const btn = document.createElement('button');
            btn.className = 'wh-page-button' + (active ? ' is-active' : '');
            btn.disabled = disabled;
            if (text === '←' || text === '→') {
                btn.setAttribute('aria-label', text === '←' ? 'Halaman sebelumnya' : 'Halaman berikutnya');
                btn.innerHTML = `<i class="fa-solid ${text === '←' ? 'fa-chevron-left' : 'fa-chevron-right'}" aria-hidden="true"></i>`;
            } else {
                btn.textContent = text;
                if (active) btn.setAttribute('aria-current', 'page');
            }
            if (!disabled && !active) {
                btn.onclick = () => { currentPage = page; renderTablePage(); };
            }
            return btn;
        };

        // Tombol Back
        container.appendChild(createBtn('←', currentPage - 1, currentPage === 1));
        
        // Logika render nomor halaman (kalau banyak, buat jadi titik-titik)
        for (let i = 1; i <= totalPages; i++) {
            if (totalPages > 7) {
                if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                    container.appendChild(createBtn(i, i, false, i === currentPage));
                } else if (i === currentPage - 2 || i === currentPage + 2) {
                    const dot = document.createElement('span');
                    dot.innerText = '...';
                    dot.style.padding = '5px';
                    dot.style.color = 'var(--slate-400)';
                    container.appendChild(dot);
                }
            } else {
                container.appendChild(createBtn(i, i, false, i === currentPage));
            }
        }

        // Tombol Next
        container.appendChild(createBtn('→', currentPage + 1, currentPage === totalPages));
    }

    // Ubah logika Filter/Search supaya update array `filteredRows` lalu panggil `renderTablePage`
    window.filterWhTable = function(query) {
        const q = query.toLowerCase().trim();
        filteredRows = allRows.filter(row => {
            const textData = row.getAttribute('data-search') || '';
            return textData.includes(q);
        });
        
        currentPage = 1; // Kembali ke halaman 1 kalau lagi nyari barang
        renderTablePage();
    };

    // Ubah logika Export CSV supaya cuma export data dari `filteredRows` (sesuai pencarian)
    // Dan pastikan tidak error saat ada row yang posisinya di halaman selanjutnya (display: none)
    window.downloadWarehouseCSV = function() {
        let csvContent = "\uFEFFDATABASE WAREHOUSE\n\n";
        csvContent += "NAMA / PO,RESI MASUK,LOKASI WH,BERAT,STATUS,BOX\n";
        
        filteredRows.forEach(row => {
            const cols = row.querySelectorAll('td');
            if (cols.length >= 8) {
                const clean = (txt) => {
                    let s = txt.replace(/"/g, '""').replace(/\n/g, ' - ').trim();
                    if (/^[=+\-@]/.test(s)) s = "'" + s;
                    return s;
                };
                csvContent += `"${clean(cols[1].innerText)}","${clean(cols[2].innerText)}","${clean(cols[3].innerText)}","${clean(cols[4].innerText)}","${clean(cols[5].innerText)}","${clean(cols[6].innerText)}"\n`;
            }
        });
        
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", `Database_Warehouse_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    document.addEventListener('DOMContentLoaded', () => {
        // Init Modal Cancel
        const modal = document.getElementById('genericConfirmModal');
        const cancelBtn = document.getElementById('genericConfirmCancel');
        const okBtn = document.getElementById('genericConfirmOk');
        if (cancelBtn) cancelBtn.addEventListener('click', () => { modal.style.display = 'none'; pendingConfirmFields = null; });
        if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) { modal.style.display = 'none'; pendingConfirmFields = null; } });
        if (okBtn) okBtn.addEventListener('click', () => {
            if (pendingConfirmFields) { submitWhAction(pendingConfirmFields); }
        });

        // Init Pagination
        initPagination();

        // Autocomplete JS
        const GOWEB_ORDERS_LIST = <?php echo wp_json_encode( array_map( function( $ord ) {
            return array( 'id' => intval( $ord['id'] ), 'label' => $ord['batch'] . ' — ' .$ord['buyer_name'] );
        }, $tenant_orders_rows ) ); ?>;

        // Proteksi XSS: label (nama PO + nama customer) diketik tenant, tapi
        // halaman ini bisa dibuka Super Admin via ?store= — jadi jangan
        // masukkan mentah ke innerHTML. Escape sebelum dirender.
        function escHtml(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }
        const searchInput = document.getElementById('wh_order_search');
        const hiddenInput = document.getElementById('wh_order_id_hidden');
        const suggestionsBox = document.getElementById('wh_order_suggestions');

        if (searchInput && hiddenInput && suggestionsBox) {
            function renderSuggestions(list) {
                if (list.length === 0) {
                    suggestionsBox.innerHTML = '<div style="padding:12px 14px; color:var(--text-muted); font-size:13px;">Gak ada PO/order yang cocok.</div>';
                } else {
                    suggestionsBox.innerHTML = list.map(o =>
                        `<div class="wh-suggestion-item" data-id="${o.id}" data-label="${escHtml(o.label)}" style="padding:10px 14px; cursor:pointer; font-size:13.5px; font-weight:600; border-bottom:1px solid var(--slate-100);">${escHtml(o.label)}</div>`
                    ).join('');
                }
                suggestionsBox.style.display = 'block';
            }
            searchInput.addEventListener('focus', () => {
                const q = searchInput.value.trim().toLowerCase();
                renderSuggestions(q === '' ? GOWEB_ORDERS_LIST : GOWEB_ORDERS_LIST.filter(o => o.label.toLowerCase().includes(q)));
            });
            searchInput.addEventListener('input', () => {
                hiddenInput.value = '';
                const q = searchInput.value.trim().toLowerCase();
                renderSuggestions(q === '' ? GOWEB_ORDERS_LIST : GOWEB_ORDERS_LIST.filter(o => o.label.toLowerCase().includes(q)));
            });
            suggestionsBox.addEventListener('click', (e) => {
                const item = e.target.closest('.wh-suggestion-item');
                if (!item) return;
                hiddenInput.value = item.getAttribute('data-id');
                searchInput.value = item.getAttribute('data-label');
                suggestionsBox.style.display = 'none';
            });
            document.addEventListener('click', (e) => {
                if (e.target !== searchInput && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.style.display = 'none';
                }
            });
            const addForm = document.getElementById('wh-add-form');
            if (addForm) {
                addForm.addEventListener('submit', (e) => {
                    if (!hiddenInput.value) {
                        e.preventDefault();
                        searchInput.style.borderColor = '#ef4444';
                        searchInput.placeholder = 'Pilih PO dari daftar dulu (klik salah satu opsi)';
                    }
                });
            }
        }
    });
})();
</script>