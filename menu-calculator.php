<?php
if (!defined('ABSPATH')) exit;
if (!$has_calc) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup! Modul kalkulator dinonaktifkan.</div>';
    return;
}
?>
<div style="margin-bottom: 24px;">
    <h2>Kalkulator Harga Jual</h2>
    <p style="color: var(--text-muted); font-size: 0.85rem;">Hitung harga jual final secara akurat dengan opsi kargo, pajak (% / Rp), dan packing fee.</p>
</div>

<div class="mc-layout-grid">
    <div style="background: #fff; border: 1px solid var(--border-soft); padding: 28px; border-radius: var(--radius-md);">
        
        <!-- PILIH VALUTA ASAL -->
        <div style="margin-bottom: 24px;">
            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 8px;">Pilih Valuta Asal:</label>
            <div id="currency-pills" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="curr-pill active" data-rate="<?php echo esc_attr($active_rates['CNY']); ?>" data-symbol="¥" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇨🇳 Yuan (CNY)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr($active_rates['JPY']); ?>" data-symbol="¥" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇯🇵 Yen (JPY)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr( isset($active_rates['THB']) ? $active_rates['THB'] : 460 ); ?>" data-symbol="฿" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇹🇭 Baht (THB)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr($active_rates['KRW']); ?>" data-symbol="₩" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇰🇷 Won (KRW)</button>
                <button type="button" class="curr-pill" data-rate="<?php echo esc_attr($active_rates['USD']); ?>" data-symbol="$" style="padding: 10px 18px; border-radius: 12px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇺🇸 USD ($)</button>
            </div>
        </div>

        <!-- 1. HARGA BELI ASAL & KURS -->
        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">1. HARGA BELI ASAL & KURS</h4>
            <div class="mc-inline-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>HARGA BARANG ASLI:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol" style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-price-valas" class="form-control" value="120" style="border: none; background: transparent;">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>KURS BUFFER (IDR):</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">Rp</span>
                        <input type="number" id="calc-rate" class="form-control" value="<?php echo esc_attr($active_rates['CNY']); ?>" style="border: none; background: transparent;">
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. ONGKIR LOKAL & KARGO -->
        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">2. ONGKIR LOKAL (WH) & KARGO</h4>
            <div class="mc-inline-grid" style="margin-bottom: 16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>ONGKIR LOKAL KE WH:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol-2" style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-local-ship" class="form-control" value="10" style="border: none; background: transparent;">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>ESTIMASI BOBOT BARANG:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-weight" class="form-control" value="350" style="border: none; background: transparent;">
                        <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">gram</span>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Jalur Pengiriman Kargo Int'l:</label>
                <div class="mc-ship-pills">
                    <button type="button" class="ship-pill active" data-rate="<?php echo esc_attr($active_currency_cargo['CNY']['air'] ?? 140000); ?>" style="flex:1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">✈️ Air (Udara)</button>
                    <button type="button" class="ship-pill" data-rate="<?php echo esc_attr($active_currency_cargo['CNY']['sea'] ?? 85000); ?>" style="flex:1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🚢 Sea (Laut)</button>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">TARIF KARGO INT'L PER KG (IDR):</label>
                <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                    <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">Rp</span>
                    <input type="number" id="calc-shipping-rate" class="form-control" value="<?php echo esc_attr($active_currency_cargo['CNY']['air'] ?? 140000); ?>" style="border: none; background: transparent;">
                    <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">/ kg</span>
                </div>
            </div>
        </div>

        <!-- 3. PAJAK, FEE PACKING & PROFIT -->
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h4 style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin:0;">3. PAJAK, FEE PACKING & PROFIT</h4>
                <div style="display: flex; background: #f1f5f9; padding: 3px; border-radius: 8px; border: 1px solid var(--border);">
                    <button type="button" class="tax-mode-pill active" data-mode="percent" style="padding: 4px 12px; border-radius: 6px; border: none; background: var(--pastel-purple); color: #fff; font-weight: 800; font-size: 0.75rem; cursor: pointer;">Mode (%)</button>
                    <button type="button" class="tax-mode-pill" data-mode="flat" style="padding: 4px 12px; border-radius: 6px; border: none; background: transparent; color: var(--text-muted); font-weight: 800; font-size: 0.75rem; cursor: pointer;">Mode (Rp)</button>
                </div>
                <input type="hidden" id="calc-tax-mode" value="percent">
            </div>

            <div class="mc-inline-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label id="tax-label-title">ESTIMASI TAX / BEA MASUK (%):</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-tax-val" class="form-control" value="<?php echo esc_attr($active_currency_cargo['CNY']['tax_percent'] ?? 7.5); ?>" style="border: none; background: transparent;">
                        <span id="tax-suffix-unit" style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>MARGIN PROFIT JASTIP (%):</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-margin" class="form-control" value="15" style="border: none; background: transparent;">
                        <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>PACKING & HANDLING FEE:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span style="padding: 0 12px; background: #faf8ff; color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.85rem;">Rp</span>
                        <input type="number" id="calc-packing-fee" class="form-control" value="<?php echo esc_attr($active_packing_fee); ?>" style="border: none; background: transparent;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KARTU HASIL KANAN -->
    <div class="mc-result-card" style="background: linear-gradient(135deg, #a78bfa, #818cf8); color: #fff; padding: 28px; border-radius: 20px; box-shadow: 0 10px 25px rgba(139,92,246,0.35);">
        <span style="font-size: 0.72rem; font-weight: 700; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.5px;">REKOMENDASI HARGA JUAL (IDR)</span>
        <h1 id="res-final-price" style="font-size: 2.3rem; font-weight: 800; color: #ffffff; margin: 6px 0 20px;">Rp 0</h1>
        
        <div style="display: flex; flex-direction: column; gap: 12px; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 16px;">
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Modal Barang Asli</span><strong id="res-modal-valas" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Ongkir Lokal (WH)</span><strong id="res-local-ship" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Beban Kargo Int'l</span><strong id="res-cargo" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Pajak / Tax Bea</span><strong id="res-tax" style="color:#fff;">Rp 0</strong></div>
            <div style="display: flex; justify-content: space-between;"><span style="color:rgba(255,255,255,0.75);">Packing / Handling</span><strong id="res-packing" style="color:#fff;">Rp 0</strong></div>
            
            <div style="display: flex; justify-content: space-between; border-top: 1px dashed rgba(255,255,255,0.45); padding-top: 12px; margin-top: 4px;">
                <span style="color: #fbbf24; font-weight: 800;">Total HPP / Modal Bersih</span>
                <strong id="res-hpp" style="color: #fbbf24;">Rp 0</strong>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #34d399; font-weight: 800;">Laba Bersih Jastip</span>
                <strong id="res-profit" style="color: #34d399;">+Rp 0</strong>
            </div>
            
            <div style="display: flex; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 12px; font-size: 0.78rem; color: rgba(255,255,255,0.75);">
                <span>Pembulatan Otomatis</span>
                <span style="color: rgba(255,255,255,0.9); font-weight: 700;">Ke atas (Rp 1.000)</span>
            </div>
        </div>
    </div>
</div>

<style>
.mc-layout-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
    align-items: start;
}
.mc-result-card {
    position: sticky;
    top: 20px;
}
.mc-inline-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.mc-ship-pills {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ===== RESPONSIVE: TABLET & HP (di bawah 900px) ===== */
@media (max-width: 900px) {
    .mc-layout-grid { grid-template-columns: 1fr; }
    .mc-result-card { position: static; }
}

/* ===== RESPONSIVE: HP KECIL (di bawah 480px) ===== */
@media (max-width: 480px) {
    .mc-inline-grid { grid-template-columns: 1fr !important; }
    .mc-ship-pills { flex-direction: column; }
    .mc-ship-pills .ship-pill { width: 100%; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currPills = document.querySelectorAll('#currency-pills .curr-pill');
    const shipPills = document.querySelectorAll('.ship-pill');
    const taxModePills = document.querySelectorAll('.tax-mode-pill');
    
    const inputPrice = document.getElementById('calc-price-valas');
    const inputRate = document.getElementById('calc-rate');
    const inputLocalShip = document.getElementById('calc-local-ship');
    const inputWeight = document.getElementById('calc-weight');
    const inputShippingRate = document.getElementById('calc-shipping-rate');
    const inputTaxMode = document.getElementById('calc-tax-mode');
    const inputTaxVal = document.getElementById('calc-tax-val');
    const inputMargin = document.getElementById('calc-margin');

    const currSymbol = document.getElementById('curr-symbol');
    const currSymbol2 = document.getElementById('curr-symbol-2');
    const taxLabelTitle = document.getElementById('tax-label-title');
    const taxSuffixUnit = document.getElementById('tax-suffix-unit');

    const resModalValas = document.getElementById('res-modal-valas');
    const resLocalShip = document.getElementById('res-local-ship');
    const resCargo = document.getElementById('res-cargo');
    const resTax = document.getElementById('res-tax');
    const resHpp = document.getElementById('res-hpp');
    const resProfit = document.getElementById('res-profit');
    const resPacking = document.getElementById('res-packing');
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
            runExactCalc();
        });
    });

    shipPills.forEach(pill => {
        pill.addEventListener('click', () => {
            shipPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            if(inputShippingRate) {
                inputShippingRate.value = pill.getAttribute('data-rate');
            }
            runExactCalc();
        });
    });

    taxModePills.forEach(pill => {
        pill.addEventListener('click', () => {
            taxModePills.forEach(p => {
                p.classList.remove('active');
                p.style.background = 'transparent';
                p.style.color = 'var(--text-muted)';
            });
            pill.classList.add('active');
            pill.style.background = 'var(--pastel-purple)';
            pill.style.color = '#fff';

            let mode = pill.getAttribute('data-mode');
            inputTaxMode.value = mode;

            if(mode === 'percent') {
                taxLabelTitle.innerText = 'ESTIMASI TAX / BEA MASUK (%):';
                taxSuffixUnit.innerText = '%';
                inputTaxVal.value = '<?php echo esc_js($active_currency_cargo['CNY']['tax_percent'] ?? 7.5); ?>';
            } else {
                taxLabelTitle.innerText = 'ESTIMASI TAX / BEA MASUK (IDR):';
                taxSuffixUnit.innerText = 'Rp';
                inputTaxVal.value = '50000';
            }
            runExactCalc();
        });
    });

    function runExactCalc() {
        let price = parseFloat(inputPrice.value) || 0;
        let rate = parseFloat(inputRate.value) || 0;
        let localShip = parseFloat(inputLocalShip.value) || 0;
        let weightGram = parseFloat(inputWeight.value) || 0;
        let shipRateKg = inputShippingRate ? (parseFloat(inputShippingRate.value) || 0) : 0;
        let taxMode = inputTaxMode.value;
        let taxVal = parseFloat(inputTaxVal.value) || 0;
        let marginPct = parseFloat(inputMargin.value) || 0;
        const inputPackingFee = document.getElementById('calc-packing-fee');
        let packingFee = inputPackingFee ? (parseFloat(inputPackingFee.value) || 0) : 5000;

        let modalValasIDR = price * rate;
        let localShipIDR = localShip * rate;
        let cargoIDR = (weightGram / 1000) * shipRateKg;
        
        let taxIDR = (taxMode === 'percent') ? (modalValasIDR * (taxVal / 100)) : taxVal;
        
        let hpp = modalValasIDR + localShipIDR + cargoIDR + taxIDR + packingFee;
        let profitIDR = hpp * (marginPct / 100);
        let finalPriceUnrounded = hpp + profitIDR;
        
        let finalPrice = Math.ceil(finalPriceUnrounded / 1000) * 1000;

        if(resModalValas) resModalValas.innerText = 'Rp ' + Math.round(modalValasIDR).toLocaleString('id-ID');
        if(resLocalShip) resLocalShip.innerText = 'Rp ' + Math.round(localShipIDR).toLocaleString('id-ID');
        if(resCargo) resCargo.innerText = 'Rp ' + Math.round(cargoIDR).toLocaleString('id-ID');
        if(resTax) resTax.innerText = 'Rp ' + Math.round(taxIDR).toLocaleString('id-ID');
        if(resHpp) resHpp.innerText = 'Rp ' + Math.round(hpp).toLocaleString('id-ID');
        if(resProfit) resProfit.innerText = '+Rp ' + Math.round(profitIDR).toLocaleString('id-ID');
        if(resPacking) resPacking.innerText = 'Rp ' + Math.round(packingFee).toLocaleString('id-ID');
        if(resFinalPrice) resFinalPrice.innerText = 'Rp ' + finalPrice.toLocaleString('id-ID');
    }

    [inputPrice, inputRate, inputLocalShip, inputWeight, inputShippingRate, inputTaxVal, inputMargin].forEach(el => {
        if(el) {
            el.addEventListener('input', runExactCalc);
            el.addEventListener('change', runExactCalc);
        }
    });

    runExactCalc();
});
</script>