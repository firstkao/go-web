<?php
if (!defined('ABSPATH')) exit;

// Ambil kurs aktif tenant dari database (termasuk THB dengan default 460 jika belum ada)
$active_rates = goweb_get_tenant_setting( $store_id, 'rates', array('CNY' => 2450, 'JPY' => 110, 'THB' => 460, 'KRW' => 12, 'USD' => 16200) );

// Kargo & pajak nempel LANGSUNG ke tiap mata uang — pas customer pilih
// Kurs, kargo & pajaknya otomatis ikut (gak perlu pilih "negara" terpisah)
$active_currency_cargo = goweb_get_tenant_setting( $store_id, 'currency_cargo', goweb_get_default_currency_cargo() );
$default_packing_fee = goweb_get_tenant_setting( $store_id, 'packing_fee', 5000 );
$default_cc = isset($active_currency_cargo['CNY']) ? $active_currency_cargo['CNY'] : array('air' => 140000, 'sea' => 85000, 'tax_percent' => 7.5);
?>
<div class="panel-heading">
    <h3>Kalkulator Estimasi Biaya</h3>
    <p>Hitung perkiraan total biaya belanja barang luar negeri ke Rupiah secara transparan dan akurat.</p>
</div>

<div class="calc-layout-grid">
    <!-- SISI KIRI: INPUT FORM PELANGGAN -->
    <div style="background: #fff; border: 1px solid var(--border-soft); padding: 28px; border-radius: var(--radius-md);">
        
        <!-- PILIH VALUTA ASAL (kargo & pajak otomatis ikut mata uang yg dipilih) -->
        <div style="margin-bottom: 24px;">
            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 8px;">Pilih Kurs:</label>
            <div id="currency-pills" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php
                $cc_cny = isset($active_currency_cargo['CNY']) ? $active_currency_cargo['CNY'] : array('air'=>140000,'sea'=>85000,'tax_percent'=>7.5);
                $cc_jpy = isset($active_currency_cargo['JPY']) ? $active_currency_cargo['JPY'] : array('air'=>160000,'sea'=>95000,'tax_percent'=>10);
                $cc_thb = isset($active_currency_cargo['THB']) ? $active_currency_cargo['THB'] : array('air'=>120000,'sea'=>75000,'tax_percent'=>7.5);
                $cc_krw = isset($active_currency_cargo['KRW']) ? $active_currency_cargo['KRW'] : array('air'=>150000,'sea'=>90000,'tax_percent'=>10);
                $cc_usd = isset($active_currency_cargo['USD']) ? $active_currency_cargo['USD'] : array('air'=>220000,'sea'=>130000,'tax_percent'=>12.5);
                ?>
                <button type="button" class="curr-pill active" data-rate="<?php echo esc_attr($active_rates['CNY']); ?>" data-symbol="¥" data-air="<?php echo esc_attr($cc_cny['air']); ?>" data-sea="<?php echo esc_attr($cc_cny['sea']); ?>" data-tax="<?php echo esc_attr($cc_cny['tax_percent']); ?>" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇨🇳 Yuan (CNY)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr($active_rates['JPY']); ?>" data-symbol="¥" data-air="<?php echo esc_attr($cc_jpy['air']); ?>" data-sea="<?php echo esc_attr($cc_jpy['sea']); ?>" data-tax="<?php echo esc_attr($cc_jpy['tax_percent']); ?>" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇯🇵 Yen (JPY)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr(isset($active_rates['THB']) ? $active_rates['THB'] : 460); ?>" data-symbol="฿" data-air="<?php echo esc_attr($cc_thb['air']); ?>" data-sea="<?php echo esc_attr($cc_thb['sea']); ?>" data-tax="<?php echo esc_attr($cc_thb['tax_percent']); ?>" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇹🇭 Baht (THB)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr($active_rates['KRW']); ?>" data-symbol="₩" data-air="<?php echo esc_attr($cc_krw['air']); ?>" data-sea="<?php echo esc_attr($cc_krw['sea']); ?>" data-tax="<?php echo esc_attr($cc_krw['tax_percent']); ?>" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇰🇷 Won (KRW)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr($active_rates['USD']); ?>" data-symbol="$" data-air="<?php echo esc_attr($cc_usd['air']); ?>" data-sea="<?php echo esc_attr($cc_usd['sea']); ?>" data-tax="<?php echo esc_attr($cc_usd['tax_percent']); ?>" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇺🇸 USD ($)</button>
            </div>
        </div>

        <!-- 1. HARGA BELI ASAL -->
        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">1. HARGA BARANG ASLI &amp; JUMLAH</h4>
            <div class="calc-inline-grid calc-grid-2-1">
                <div class="form-group" style="margin-bottom:0;">
                    <label>HARGA SATUAN:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol" style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
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
            <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">2. ONGKIR LOKAL (WH) &amp; BERAT BARANG</h4>
            <div class="calc-inline-grid calc-grid-1-1" style="margin-bottom: 16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>ONGKIR LOKAL KE WH:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol-2" style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-local-ship" class="form-control" value="10" style="border: none; background: transparent;" min="0">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>ESTIMASI BOBOT / BERAT:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-weight" class="form-control" value="350" style="border: none; background: transparent;" min="0">
                        <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">gram</span>
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
    <div class="calc-result-card" style="background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; padding: 28px; border-radius: 20px; box-shadow: 0 10px 25px rgba(139,92,246,0.35);">
        <span style="font-size: 0.72rem; font-weight: 700; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.5px;">ESTIMASI TOTAL BIAYA (IDR)</span>
        <h1 id="res-final-price" style="font-size: 2.2rem; font-weight: 800; color: #ffffff; margin: 6px 0 20px;">Rp 0</h1>
        
        <div style="display: flex; flex-direction: column; gap: 12px; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 16px;">
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Harga Barang (Asli x Qty)</span><strong id="res-modal-valas" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Ongkir Lokal (WH)</span><strong id="res-local-ship" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Beban Kargo Int'l</span><strong id="res-cargo" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Pajak / Bea Masuk (Est.)</span><strong id="res-tax" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Packing &amp; Handling Fee</span><strong id="res-packing" style="color:#fff;">Rp <?php echo number_format($default_packing_fee, 0, ',', '.'); ?></strong></div>
            
            <div style="display: flex; justify-content: space-between; border-top: 1px dashed rgba(255,255,255,0.45); padding-top: 12px; margin-top: 4px;">
                <span style="color: #34d399; font-weight: 800;">Total Estimasi Bersih</span>
                <strong id="res-hpp" style="color: #34d399;">Rp 0</strong>
            </div>
            
            <div style="display: flex; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 12px; font-size: 0.78rem; color: rgba(255,255,255,0.75);">
                <span>Catatan</span>
                <span style="color: rgba(255,255,255,0.9); font-weight: 600;">Belum termasuk ongkir lokal domestik Indo.</span>
            </div>
        </div>
    </div>
</div>

<style>
.curr-pill.active { background: var(--pastel-purple) !important; color: #fff !important; border-color: var(--pastel-purple) !important; box-shadow: 0 4px 12px rgba(139,92,246,0.25); }
.ship-pill.active { background: var(--pastel-purple) !important; color: #fff !important; border-color: var(--pastel-purple) !important; box-shadow: 0 4px 12px rgba(139,92,246,0.25); }

/* ===== LAYOUT UTAMA: form kiri + kartu hasil kanan ===== */
.calc-layout-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 24px;
    align-items: start;
}
.calc-result-card {
    position: sticky;
    top: 20px;
}

/* ===== GRID INPUT DI DALAM FORM (harga/qty, ongkir/berat) ===== */
.calc-inline-grid {
    display: grid;
    gap: 16px;
}
.calc-grid-2-1 { grid-template-columns: 2fr 1fr; }
.calc-grid-1-1 { grid-template-columns: 1fr 1fr; }

.calc-ship-pills {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ===== RESPONSIVE: TABLET & HP (di bawah 900px) ===== */
@media (max-width: 900px) {
    .calc-layout-grid {
        grid-template-columns: 1fr;
    }
    .calc-result-card {
        position: static;
    }
}

/* ===== RESPONSIVE: HP KECIL (di bawah 480px) ===== */
@media (max-width: 480px) {
    .calc-inline-grid {
        grid-template-columns: 1fr !important;
    }
    .calc-ship-pills {
        flex-direction: column;
    }
    .calc-ship-pills .ship-pill {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currPills = document.querySelectorAll('#currency-pills .curr-pill');
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

    currPills.forEach(pill => {
        pill.addEventListener('click', () => {
            currPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            let rate = pill.getAttribute('data-rate');
            let sym = pill.getAttribute('data-symbol');
            inputRate.value = rate;
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