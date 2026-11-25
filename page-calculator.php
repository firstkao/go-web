<?php
if (!defined('ABSPATH')) exit;

// Ambil kurs aktif tenant dari database.
$active_rates = goweb_get_tenant_setting( $store_id, 'rates', array('CNY' => 2450, 'JPY' => 110, 'THB' => 460, 'KRW' => 12, 'USD' => 16200, 'TWD' => 510, 'PHP' => 285) );

// Kargo & pajak nempel LANGSUNG ke tiap mata uang — pas customer pilih
// Kurs, kargo & pajaknya otomatis ikut (gak perlu pilih "negara" terpisah)
$active_currency_cargo = goweb_get_tenant_setting( $store_id, 'currency_cargo', goweb_get_default_currency_cargo() );
$default_packing_fee = goweb_get_tenant_setting( $store_id, 'packing_fee', 5000 );
$default_cc = isset($active_currency_cargo['CNY']) ? $active_currency_cargo['CNY'] : array('air' => 140000, 'sea' => 85000, 'tax_percent' => 7.5);
?>
<div class="public-calculator">
<div class="public-calc-heading">
    <span class="public-calc-module-icon" aria-hidden="true"><i class="fa-solid fa-calculator"></i></span>
    <div>
        <span class="public-module-eyebrow">HITUNG ESTIMASI</span>
        <h3>Kalkulator Biaya</h3>
        <p>Perkirakan total biaya belanja dari luar negeri ke Rupiah secara transparan.</p>
    </div>
</div>

<div class="calc-layout-grid">
    <!-- SISI KIRI: INPUT FORM PELANGGAN -->
    <div class="public-calc-input-card" style="background: #fff; border: 1px solid var(--border-soft); padding: 28px; border-radius: var(--radius-md);">
        
        <!-- PILIH VALUTA ASAL (kargo & pajak otomatis ikut mata uang yg dipilih) -->
        <div class="public-calc-currencies" style="margin-bottom: 24px;">
            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 8px;">Pilih Kurs:</label>
            <?php
            $currency_definitions = array(
                'CNY' => array('🇨🇳', 'Yuan (CNY)', '¥', $active_rates['CNY'], $active_currency_cargo['CNY'] ?? array('air'=>140000,'sea'=>85000,'tax_percent'=>7.5)),
                'JPY' => array('🇯🇵', 'Yen (JPY)', '¥', $active_rates['JPY'], $active_currency_cargo['JPY'] ?? array('air'=>160000,'sea'=>95000,'tax_percent'=>10)),
                'THB' => array('🇹🇭', 'Baht (THB)', '฿', $active_rates['THB'] ?? 460, $active_currency_cargo['THB'] ?? array('air'=>120000,'sea'=>75000,'tax_percent'=>7.5)),
                'KRW' => array('🇰🇷', 'Won (KRW)', '₩', $active_rates['KRW'], $active_currency_cargo['KRW'] ?? array('air'=>150000,'sea'=>90000,'tax_percent'=>10)),
                'USD' => array('🇺🇸', 'USD ($)', '$', $active_rates['USD'], $active_currency_cargo['USD'] ?? array('air'=>220000,'sea'=>130000,'tax_percent'=>12.5)),
                'TWD' => array('🇹🇼', 'Taiwan Dollar (TWD)', 'NT$', $active_rates['TWD'] ?? 510, $active_currency_cargo['TWD'] ?? array('air'=>140000,'sea'=>85000,'tax_percent'=>7.5)),
                'PHP' => array('🇵🇭', 'Philippine Peso (PHP)', '₱', $active_rates['PHP'] ?? 285, $active_currency_cargo['PHP'] ?? array('air'=>120000,'sea'=>75000,'tax_percent'=>7.5)),
            );
            ?>
            <div class="calc-currency-pills" role="group" aria-label="Pilih mata uang asal">
                <?php foreach ( $currency_definitions as $code => $currency ): ?>
                    <button type="button" class="currency-pill<?php echo $code === 'CNY' ? ' active' : ''; ?>"
                        data-rate="<?php echo esc_attr($currency[3]); ?>"
                        data-symbol="<?php echo esc_attr($currency[2]); ?>"
                        data-air="<?php echo esc_attr($currency[4]['air']); ?>"
                        data-sea="<?php echo esc_attr($currency[4]['sea']); ?>"
                        data-tax="<?php echo esc_attr($currency[4]['tax_percent']); ?>"
                        aria-pressed="<?php echo $code === 'CNY' ? 'true' : 'false'; ?>"
                        title="<?php echo esc_attr($currency[1]); ?>">
                        <span class="currency-pill-top"><span aria-hidden="true"><?php echo $currency[0]; ?></span><?php echo esc_html($code); ?></span>
                        <span class="currency-pill-rate">Rp <?php echo number_format($currency[3], 0, ',', '.'); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 1. HARGA BELI ASAL -->
        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">1. HARGA BARANG ASLI &amp; JUMLAH</h4>
            <div class="calc-inline-grid calc-grid-2-1">
                <div class="form-group" style="margin-bottom:0;">
                    <label>HARGA SATUAN:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol" style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-price-valas" class="form-control" value="120" style="border: none; background: transparent;" min="0">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>QTY (PCS):</label>
                    <input type="number" id="calc-qty" class="form-control" value="1" min="1">
                </div>
            </div>
            <!-- Hidden input kurs buffer toko (Default CNY) -->
            <input type="hidden" id="calc-rate" value="<?php echo esc_attr($active_rates['CNY']); ?>">
        </div>

        <!-- 2. ONGKIR LOKAL & KARGO INT'L -->
        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">2. ONGKIR LOKAL KE GUDANG &amp; BERAT BARANG</h4>
            <div class="calc-inline-grid calc-grid-1-1" style="margin-bottom: 16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>ONGKIR LOKAL KE GUDANG:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol-2" style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-local-ship" class="form-control" value="10" style="border: none; background: transparent;" min="0">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>ESTIMASI BOBOT / BERAT:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-weight" class="form-control" value="350" style="border: none; background: transparent;" min="0">
                        <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">gram</span>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Pilih Jalur Pengiriman Kargo Int'l:</label>
                <div class="calc-ship-pills">
                    <button type="button" class="ship-pill active" data-rate="<?php echo esc_attr($default_cc['air']); ?>" style="flex:1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">✈️ Air (Rp <span id="ship-air-label"><?php echo number_format($default_cc['air'], 0, ',', '.'); ?></span>/kg)</button>
                    <button type="button" class="ship-pill" data-rate="<?php echo esc_attr($default_cc['sea']); ?>" style="flex:1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🚢 Sea (Rp <span id="ship-sea-label"><?php echo number_format($default_cc['sea'], 0, ',', '.'); ?></span>/kg)</button>
                </div>
            </div>
            <!-- Hidden input tarif kargo per kg (Default Air, ikutin Kurs pertama) -->
            <input type="hidden" id="calc-shipping-rate" value="<?php echo esc_attr($default_cc['air']); ?>">
            <input type="hidden" id="calc-tax-percent" value="<?php echo esc_attr($default_cc['tax_percent']); ?>">
            <input type="hidden" id="calc-packing-fee" value="<?php echo esc_attr($default_packing_fee); ?>">
        </div>
    </div>

    <!-- SISI KANAN: KARTU HASIL ESTIMASI -->
    <div class="calc-result-card" style="background: var(--accent-gradient); color: #fff; padding: 28px; border-radius: var(--radius-lg); box-shadow: 0 10px 25px rgba(139,92,246,0.35);">
        <span class="public-calc-result-label" style="font-size: 0.72rem; font-weight: 700; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.5px;">ESTIMASI TOTAL BIAYA (IDR)</span>
        <h1 id="res-final-price" aria-live="polite" style="font-size: 2.2rem; font-weight: 800; color: #ffffff; margin: 6px 0 20px;">Rp 0</h1>
        
        <div class="public-calc-breakdown" style="display: flex; flex-direction: column; gap: 12px; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 16px;">
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Harga Barang (Asli x Qty)</span><strong id="res-modal-valas" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Ongkir Lokal ke Gudang</span><strong id="res-local-ship" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Beban Kargo Int'l</span><strong id="res-cargo" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Pajak / Bea Masuk (Est.)</span><strong id="res-tax" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Packing &amp; Handling Fee</span><strong id="res-packing" style="color:#fff;">Rp <?php echo number_format($default_packing_fee, 0, ',', '.'); ?></strong></div>
            
            <div style="display: flex; justify-content: space-between; border-top: 1px dashed rgba(255,255,255,0.45); padding-top: 12px; margin-top: 4px;">
                <span style="color: #34d399; font-weight: 800;">Rincian sebelum pembulatan</span>
                <strong id="res-hpp" style="color: #34d399;">Rp 0</strong>
            </div>
            
            <div style="display: flex; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 12px; font-size: 0.78rem; color: rgba(255,255,255,0.75);">
                <span>Catatan</span>
                <span style="color: rgba(255,255,255,0.9); font-weight: 600;">Ongkir domestik belum termasuk.</span>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.public-calculator {
    --pub-ink: #302b46;
    --pub-muted: #777188;
    --pub-line: #e5e0f2;
    --pub-accent: #6d5a91;
    --pub-accent-soft: #f0ebf7;
    color: var(--pub-ink);
}
.public-calc-heading { display: flex; align-items: center; gap: 14px; margin: 0 0 20px; }
.public-calc-module-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--pub-accent);
    font-size: 1.08rem;
}
.public-module-eyebrow { display: block; margin-bottom: 3px; color: var(--pub-accent); font-size: .65rem; font-weight: 800; letter-spacing: .09em; }
.public-calc-heading h3 { margin: 0 0 4px; color: var(--pub-ink); font-size: clamp(1.25rem, 2vw, 1.5rem); font-weight: 800; line-height: 1.25; letter-spacing: -.03em; }
.public-calc-heading p { margin: 0; color: var(--pub-muted); font-size: .84rem; line-height: 1.55; }
.public-calc-input-card { min-width: 0; padding: clamp(20px, 2.5vw, 28px) !important; border-color: var(--pub-line) !important; border-radius: 18px !important; box-shadow: 0 5px 18px rgba(59, 48, 95, .045); }
.public-calc-currencies { padding-bottom: 20px; border-bottom: 1px solid var(--pub-line); }
.public-calculator .calc-currency-pills { display: grid; grid-template-columns: repeat(4, 1fr); gap: 9px; }
.public-calculator .currency-pill { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-height: 60px; padding: 9px 6px; border: 1px solid var(--pub-line) !important; border-radius: 11px !important; background: #fff !important; color: var(--pub-ink); cursor: pointer; font-family: inherit; transition: border-color .16s ease, background .16s ease, color .16s ease, transform .16s ease; }
.public-calculator .currency-pill:hover:not(.active) { transform: translateY(-1px); border-color: #cfc3df !important; background: #faf8fc !important; }
.public-calculator .currency-pill.active { border-color: var(--pub-accent) !important; background: var(--pub-accent) !important; color: #fff !important; box-shadow: 0 4px 12px rgba(109, 90, 145, .25); }
.public-calculator .currency-pill .currency-pill-top { display: inline-flex; align-items: center; gap: 5px; font-size: .84rem; font-weight: 800; letter-spacing: .02em; }
.public-calculator .currency-pill .currency-pill-rate { font-size: .68rem; font-weight: 650; color: var(--pub-muted); font-variant-numeric: tabular-nums; }
.public-calculator .currency-pill.active .currency-pill-rate { color: rgba(255, 255, 255, .85); }
.public-calculator .calc-inline-grid { gap: 13px; }
.public-calculator .public-calc-input-card h4 { padding-left: 10px; border-left: 3px solid #a696be; color: #62517f !important; font-size: .8rem !important; line-height: 1.5; letter-spacing: .025em; }
.public-calculator .form-group > label,
.public-calculator .public-calc-currencies > label,
.public-calculator .public-calc-input-card > div > label { color: #514b63 !important; font-size: .71rem !important; font-weight: 750 !important; letter-spacing: .025em; line-height: 1.5; }
.public-calculator .public-calc-input-card input[type="number"].form-control { min-width: 0; min-height: 44px; padding: 9px 11px; color: var(--pub-ink); font-size: .88rem; }
.public-calculator .public-calc-input-card .form-group > div:focus-within { border-color: #9c8ab8 !important; box-shadow: 0 0 0 3px rgba(109, 90, 145, .11); }
.public-calculator .public-calc-input-card input[type="number"].form-control:focus { box-shadow: none; }
.public-calculator .calc-ship-pills .ship-pill { min-height: 43px; border: 1px solid var(--pub-line) !important; border-radius: 11px !important; font-size: .8rem !important; transition: border-color .16s ease, background .16s ease, color .16s ease, transform .16s ease; }
.public-calculator .calc-ship-pills .ship-pill:hover:not(.active) { transform: translateY(-1px); border-color: #cfc3df !important; background: #faf8fc !important; }
.public-calculator .calc-ship-pills .ship-pill.active { border-color: var(--pub-accent) !important; background: var(--pub-accent) !important; color: #fff !important; }
.public-calculator .calc-result-card { position: sticky; top: 20px; min-width: 0; padding: clamp(22px, 2.5vw, 29px) !important; border: 1px solid rgba(255,255,255,.14); border-radius: 18px !important; background: linear-gradient(145deg, #6d5a91 0%, #51416f 100%) !important; box-shadow: 0 12px 28px rgba(81,65,111,.2) !important; }
.public-calc-result-label { display: block; font-size: .67rem !important; letter-spacing: .075em !important; }
.public-calculator .calc-result-card #res-final-price { margin: 8px 0 20px !important; font-size: clamp(1.8rem, 3vw, 2.2rem) !important; line-height: 1.2; letter-spacing: -.035em; overflow-wrap: anywhere; }
.public-calc-breakdown > div { align-items: flex-start; gap: 12px; line-height: 1.45; }
.public-calc-breakdown > div > span { min-width: 0; }
.public-calc-breakdown > div > strong { max-width: 53%; flex: 0 0 auto; text-align: right; overflow-wrap: anywhere; }
.public-calc-breakdown > div:last-child > span:last-child { text-align: right; }
.public-calculator button:focus-visible { outline: 3px solid rgba(157,138,184,.42); outline-offset: 2px; }
.ship-pill.active { background: var(--pastel-purple) !important; color: #fff !important; border-color: var(--pastel-purple) !important; box-shadow: 0 4px 12px rgba(139,92,246,0.25); }

/* ===== LAYOUT UTAMA: form kiri + kartu hasil kanan ===== */
.public-calculator .calc-layout-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 24px;
    align-items: start;
}
.public-calculator .calc-result-card {
    position: sticky;
    top: 20px;
}

/* ===== GRID INPUT DI DALAM FORM (harga/qty, ongkir/berat) ===== */
.public-calculator .calc-inline-grid {
    display: grid;
    gap: 16px;
}
.public-calculator .calc-grid-2-1 { grid-template-columns: 2fr 1fr; }
.public-calculator .calc-grid-1-1 { grid-template-columns: 1fr 1fr; }

.public-calculator .calc-ship-pills {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ===== RESPONSIVE: TABLET & HP ===== */
@media (max-width: 1100px) {
    .public-calculator .calc-layout-grid { grid-template-columns: minmax(0, 1fr); }
    .public-calculator .calc-result-card { position: static; }
}
@media (max-width: 600px) {
    .public-calc-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .public-calc-module-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .public-calc-heading p { font-size: .79rem; }
    .public-calc-input-card { padding: 19px 16px !important; }
    .public-calculator .calc-inline-grid { grid-template-columns: 1fr !important; }
    .public-calculator .calc-ship-pills { flex-direction: column; }
    .public-calculator .calc-ship-pills .ship-pill { width: 100%; }
    .public-calculator .calc-currency-pills { grid-template-columns: repeat(2, 1fr); }
    .public-calculator .calc-result-card { padding: 21px 17px !important; }
    .public-calc-breakdown { gap: 10px !important; }
}
@media (max-width: 400px) {
    .public-calc-breakdown > div { gap: 8px; font-size: .78rem; }
    .public-calc-breakdown > div > strong { max-width: 48%; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currencyPills = document.querySelectorAll('.currency-pill');
    const shipPills = document.querySelectorAll('.ship-pill');
    
    const inputPrice = document.getElementById('calc-price-valas');
    const inputQty = document.getElementById('calc-qty');
    const inputRate = document.getElementById('calc-rate');
    const inputLocalShip = document.getElementById('calc-local-ship');
    const inputWeight = document.getElementById('calc-weight');
    const inputShippingRate = document.getElementById('calc-shipping-rate');

    const currSymbol = document.getElementById('curr-symbol');
    const currSymbol2 = document.getElementById('curr-symbol-2');

    const resModalValas = document.getElementById('res-modal-valas');
    const resLocalShip = document.getElementById('res-local-ship');
    const resCargo = document.getElementById('res-cargo');
    const resTax = document.getElementById('res-tax');
    const resPacking = document.getElementById('res-packing');
    const resHpp = document.getElementById('res-hpp');
    const resFinalPrice = document.getElementById('res-final-price');

    if(!inputPrice) return;

    if (currencyPills.length) {
        currencyPills.forEach(pill => {
            pill.addEventListener('click', () => {
                currencyPills.forEach(p => {
                    p.classList.remove('active');
                    p.setAttribute('aria-pressed', 'false');
                });
                pill.classList.add('active');
                pill.setAttribute('aria-pressed', 'true');
                inputRate.value = pill.getAttribute('data-rate');
                const sym = pill.getAttribute('data-symbol');
                currSymbol.innerText = sym;
                currSymbol2.innerText = sym;

                // Kargo & pajak nempel LANGSUNG di pill kurs ini — pas kurs
                // diganti, jalur pengiriman & pajak otomatis ikut, gak perlu
                // pilih "negara" terpisah lagi.
                let airRate = pill.getAttribute('data-air');
                let seaRate = pill.getAttribute('data-sea');
                let taxRate = pill.getAttribute('data-tax');

                const shipPillAir = shipPills[0];
                const shipPillSea = shipPills[1];
                if (shipPillAir) shipPillAir.setAttribute('data-rate', airRate);
                if (shipPillSea) shipPillSea.setAttribute('data-rate', seaRate);
                const shipAirLabel = document.getElementById('ship-air-label');
                const shipSeaLabel = document.getElementById('ship-sea-label');
                if (shipAirLabel) shipAirLabel.textContent = Math.round(airRate).toLocaleString('id-ID');
                if (shipSeaLabel) shipSeaLabel.textContent = Math.round(seaRate).toLocaleString('id-ID');

                // Balikin ke pilihan Air sebagai default tiap ganti kurs
                shipPills.forEach(p => p.classList.remove('active'));
                if (shipPillAir) {
                    shipPillAir.classList.add('active');
                    if (inputShippingRate) inputShippingRate.value = airRate;
                }

                const inputTaxPercent = document.getElementById('calc-tax-percent');
                if (inputTaxPercent) inputTaxPercent.value = taxRate;

                runPublicCalc();
            });
        });
    }

    shipPills.forEach(pill => {
        pill.addEventListener('click', () => {
            shipPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            if(inputShippingRate) {
                inputShippingRate.value = pill.getAttribute('data-rate');
            }
            runPublicCalc();
        });
    });

    function runPublicCalc() {
        let price = parseFloat(inputPrice.value) || 0;
        let qty = parseInt(inputQty.value) || 1;
        let rate = parseFloat(inputRate.value) || 0;
        let localShip = parseFloat(inputLocalShip.value) || 0;
        let weightGram = parseFloat(inputWeight.value) || 0;
        let shipRateKg = inputShippingRate ? (parseFloat(inputShippingRate.value) || 0) : 0;
        const inputTaxPercent = document.getElementById('calc-tax-percent');
        const inputPackingFee = document.getElementById('calc-packing-fee');
        let taxPercent = inputTaxPercent ? (parseFloat(inputTaxPercent.value) || 0) : 7.5;
        let packingFee = inputPackingFee ? (parseFloat(inputPackingFee.value) || 0) : 5000;

        let modalValasIDR = price * qty * rate;
        let localShipIDR = localShip * rate;
        let cargoIDR = (weightGram / 1000) * shipRateKg;
        
        // Est pajak — sekarang diatur tenant sendiri di Pengaturan, bukan
        // hardcode 7.5% buat semua tenant lagi
        let taxIDR = modalValasIDR * (taxPercent / 100);
        
        let totalEstimated = modalValasIDR + localShipIDR + cargoIDR + taxIDR + packingFee;
        let finalPrice = Math.ceil(totalEstimated / 1000) * 1000;

        if(resModalValas) resModalValas.innerText = 'Rp ' + Math.round(modalValasIDR).toLocaleString('id-ID');
        if(resLocalShip) resLocalShip.innerText = 'Rp ' + Math.round(localShipIDR).toLocaleString('id-ID');
        if(resCargo) resCargo.innerText = 'Rp ' + Math.round(cargoIDR).toLocaleString('id-ID');
        if(resTax) resTax.innerText = 'Rp ' + Math.round(taxIDR).toLocaleString('id-ID');
        if(resPacking) resPacking.innerText = 'Rp ' + Math.round(packingFee).toLocaleString('id-ID');
        if(resHpp) resHpp.innerText = 'Rp ' + Math.round(totalEstimated).toLocaleString('id-ID');
        if(resFinalPrice) resFinalPrice.innerText = 'Rp ' + finalPrice.toLocaleString('id-ID');
    }

    [inputPrice, inputQty, inputRate, inputLocalShip, inputWeight, inputShippingRate].forEach(el => {
        if(el) {
            el.addEventListener('input', runPublicCalc);
            el.addEventListener('change', runPublicCalc);
        }
    });

    runPublicCalc();
});
</script>