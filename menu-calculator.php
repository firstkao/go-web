<?php
if (!defined('ABSPATH')) exit;
if (!$has_calc) {
    echo '<div style="background: #fef2f2; color: #991b1b; padding: 20px; border-radius: 8px; font-weight: 700;">Akses ditutup! Modul kalkulator dinonaktifkan.</div>';
    return;
}
?>
<div class="mc-calculator">
    <div class="mc-page-heading">
        <span class="mc-heading-icon" aria-hidden="true"><i class="fa-solid fa-calculator"></i></span>
        <div>
            <span class="mc-heading-eyebrow">ALAT BANTU OPERASIONAL</span>
            <h2>Kalkulator Harga Jual</h2>
            <p>Hitung harga jual final dengan mempertimbangkan kurs, kargo, pajak, dan biaya packing.</p>
        </div>
    </div>

<div class="mc-layout-grid">
    <div class="mc-input-card" style="background: #fff; border: 1px solid var(--border-soft); padding: 28px; border-radius: var(--radius-md);">
        
        <!-- PILIH VALUTA ASAL -->
        <div class="mc-currency-section" style="margin-bottom: 24px;">
            <div class="mc-currency-label" id="currency-group-label">Pilih Valuta Asal:</div>
            <div id="currency-pills" role="group" aria-labelledby="currency-group-label">
                <button type="button" class="curr-pill active" aria-pressed="true" data-rate="<?php echo esc_attr($active_rates['CNY']); ?>" data-symbol="¥" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇨🇳 Yuan (CNY)</button>
                <button type="button" class="curr-pill" aria-pressed="false" data-rate="<?php echo esc_attr($active_rates['JPY']); ?>" data-symbol="¥" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇯🇵 Yen (JPY)</button>
                <button type="button" class="curr-pill" aria-pressed="false" data-rate="<?php echo esc_attr( isset($active_rates['THB']) ? $active_rates['THB'] : 460 ); ?>" data-symbol="฿" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇹🇭 Baht (THB)</button>
                <button type="button" class="curr-pill" aria-pressed="false" data-rate="<?php echo esc_attr($active_rates['KRW']); ?>" data-symbol="₩" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇰🇷 Won (KRW)</button>
                <button type="button" class="curr-pill" aria-pressed="false" data-rate="<?php echo esc_attr($active_rates['USD']); ?>" data-symbol="$" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇺🇸 USD ($)</button>
                <button type="button" class="curr-pill" aria-pressed="false" data-rate="<?php echo esc_attr( isset($active_rates['TWD']) ? $active_rates['TWD'] : 510 ); ?>" data-symbol="NT$" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇹🇼 Taiwan Dollar (TWD)</button>
                <button type="button" class="curr-pill" aria-pressed="false" data-rate="<?php echo esc_attr( isset($active_rates['PHP']) ? $active_rates['PHP'] : 285 ); ?>" data-symbol="₱" style="padding: 10px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🇵🇭 Philippine Peso (PHP)</button>
            </div>
        </div>

        <!-- 1. HARGA BELI ASAL & KURS -->
        <div class="mc-form-section" style="margin-bottom: 24px;">
            <h4 class="mc-section-title" style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">1. HARGA BELI ASAL & KURS</h4>
            <div class="mc-inline-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="calc-price-valas">HARGA BARANG ASLI:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol" style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-price-valas" class="form-control" value="120" style="border: none; background: transparent;">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="calc-rate">KURS BUFFER (IDR):</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">Rp</span>
                        <input type="number" id="calc-rate" class="form-control" value="<?php echo esc_attr($active_rates['CNY']); ?>" style="border: none; background: transparent;">
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. ONGKIR LOKAL & KARGO -->
        <div class="mc-form-section" style="margin-bottom: 24px;">
            <h4 class="mc-section-title" style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin-bottom: 12px;">2. ONGKIR LOKAL (WH) & KARGO</h4>
            <div class="mc-inline-grid" style="margin-bottom: 16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="calc-local-ship">ONGKIR LOKAL KE WH:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span id="curr-symbol-2" style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">¥</span>
                        <input type="number" id="calc-local-ship" class="form-control" value="10" style="border: none; background: transparent;">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="calc-weight">ESTIMASI BOBOT BARANG:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-weight" class="form-control" value="350" style="border: none; background: transparent;">
                        <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">gram</span>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">Jalur Pengiriman Kargo Int'l:</label>
                <div class="mc-ship-pills" role="group" aria-label="Pilih jalur pengiriman kargo">
                    <button type="button" class="ship-pill active" aria-pressed="true" data-rate="<?php echo esc_attr($active_currency_cargo['CNY']['air'] ?? 140000); ?>" style="flex:1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">✈️ Air (Udara)</button>
                    <button type="button" class="ship-pill" aria-pressed="false" data-rate="<?php echo esc_attr($active_currency_cargo['CNY']['sea'] ?? 85000); ?>" style="flex:1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border-soft); background: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: var(--text-muted);">🚢 Sea (Laut)</button>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="calc-shipping-rate" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px;">TARIF KARGO INT'L PER KG (IDR):</label>
                <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                    <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.9rem;">Rp</span>
                    <input type="number" id="calc-shipping-rate" class="form-control" value="<?php echo esc_attr($active_currency_cargo['CNY']['air'] ?? 140000); ?>" style="border: none; background: transparent;">
                    <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">kg</span>
                </div>
            </div>
        </div>

        <!-- 3. PAJAK, FEE PACKING & PROFIT -->
        <div class="mc-form-section mc-form-section-last">
            <div class="mc-tax-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h4 class="mc-section-title" style="font-size: 0.9rem; font-weight: 800; color: var(--pastel-purple); margin:0;">3. PAJAK, FEE PACKING & PROFIT</h4>
                <div role="group" aria-label="Pilih mode pajak" style="display: flex; background: var(--slate-100); padding: 3px; border-radius: 8px; border: 1px solid var(--border);">
                    <button type="button" class="tax-mode-pill active" aria-pressed="true" data-mode="percent" style="padding: 4px 12px; border: none; font-weight: 800; font-size: 0.75rem; cursor: pointer;">Mode (%)</button>
                    <button type="button" class="tax-mode-pill" aria-pressed="false" data-mode="flat" style="padding: 4px 12px; border: none; font-weight: 800; font-size: 0.75rem; cursor: pointer;">Mode (Rp)</button>
                </div>
                <input type="hidden" id="calc-tax-mode" value="percent">
            </div>

            <div class="mc-inline-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label id="tax-label-title" for="calc-tax-val">ESTIMASI TAX / BEA MASUK (%):</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-tax-val" class="form-control" value="<?php echo esc_attr($active_currency_cargo['CNY']['tax_percent'] ?? 7.5); ?>" style="border: none; background: transparent;">
                        <span id="tax-suffix-unit" style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="calc-margin">MARGIN PROFIT JASTIP (%):</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <input type="number" id="calc-margin" class="form-control" value="15" style="border: none; background: transparent;">
                        <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-left: 1.5px solid var(--border-soft); font-size: 0.85rem;">%</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="calc-packing-fee">PACKING & HANDLING FEE:</label>
                    <div style="display: flex; align-items: center; border: 1.5px solid var(--border-soft); border-radius: var(--radius-md); background: #fff; overflow: hidden;">
                        <span style="padding: 0 12px; background: var(--bg-page); color: var(--text-muted); font-weight: 700; border-right: 1.5px solid var(--border-soft); font-size: 0.85rem;">Rp</span>
                        <input type="number" id="calc-packing-fee" class="form-control" value="<?php echo esc_attr($active_packing_fee); ?>" style="border: none; background: transparent;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KARTU HASIL KANAN -->
    <div class="mc-result-card" style="background: var(--accent-gradient); color: #fff; padding: 28px; border-radius: var(--radius-lg); box-shadow: 0 10px 25px rgba(139,92,246,0.35);">
        <span class="mc-result-label" style="font-size: 0.72rem; font-weight: 700; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.5px;">REKOMENDASI HARGA JUAL (IDR)</span>
        <h1 id="res-final-price" aria-live="polite" style="font-size: 2.3rem; font-weight: 800; color: #ffffff; margin: 6px 0 20px;">Rp 0</h1>
        
        <div class="mc-result-breakdown" style="display: flex; flex-direction: column; gap: 12px; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 16px;">
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
</div>

<style>
.mc-calculator {
    --mc-ink: #302b46;
    --mc-muted: #777188;
    --mc-line: #e5e0f2;
    --mc-accent: #6d5a91;
    --mc-accent-dark: #51416f;
    --mc-accent-soft: #f0ebf7;
    color: var(--mc-ink);
}
.mc-page-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 0 0 20px;
}
.mc-heading-icon {
    display: grid;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    place-items: center;
    border: 1px solid #ded7e9;
    border-radius: 14px;
    background: linear-gradient(145deg, #f8f5fb, #f0ebf7);
    color: var(--mc-accent);
    font-size: 1.08rem;
}
.mc-heading-eyebrow {
    display: block;
    margin-bottom: 4px;
    color: var(--mc-accent);
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .09em;
}
.mc-page-heading h2 {
    margin: 0 0 4px;
    color: var(--mc-ink);
    font-size: clamp(1.25rem, 2vw, 1.5rem);
    font-weight: 800;
    line-height: 1.25;
    letter-spacing: -.03em;
}
.mc-page-heading p {
    margin: 0;
    color: var(--mc-muted);
    font-size: .84rem;
    line-height: 1.6;
}
.mc-layout-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 24px;
    align-items: start;
}
.mc-input-card {
    min-width: 0;
    padding: clamp(20px, 2.5vw, 28px) !important;
    border-color: var(--mc-line) !important;
    border-radius: 18px !important;
    box-shadow: 0 5px 18px rgba(59, 48, 95, .045);
}
.mc-currency-section {
    padding-bottom: 21px;
    border-bottom: 1px solid var(--mc-line);
}
.mc-calculator #currency-pills {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
.mc-calculator #currency-pills .curr-pill {
    min-height: 43px;
    padding: 9px 12px !important;
    border: 1px solid var(--mc-line) !important;
    border-radius: 11px !important;
    background: #fff !important;
    color: var(--mc-muted) !important;
    font: inherit;
    font-size: .8rem;
    font-weight: 750;
    transition: border-color .16s ease, background .16s ease, color .16s ease, transform .16s ease;
}
.mc-calculator #currency-pills .curr-pill:hover:not(.active) {
    transform: translateY(-1px);
    border-color: #cfc3df !important;
    background: #faf8fc !important;
}
.mc-calculator #currency-pills .curr-pill.active {
    border-color: var(--mc-accent) !important;
    background: var(--mc-accent) !important;
    color: #fff !important;
    box-shadow: 0 4px 10px rgba(89, 74, 117, .16);
}
.mc-form-section {
    margin-bottom: 22px !important;
    padding-bottom: 21px;
    border-bottom: 1px solid var(--mc-line);
}
.mc-section-title {
    padding-left: 10px;
    border-left: 3px solid #a696be;
    color: #62517f !important;
    font-size: .82rem !important;
    line-height: 1.45;
    letter-spacing: .025em;
}
.mc-tax-heading {
    flex-wrap: wrap;
    gap: 10px;
}
.mc-calculator .form-group > label,
.mc-calculator .mc-currency-section > label,
.mc-calculator .mc-currency-label,
.mc-calculator .mc-form-section label {
    color: #514b63;
    font-size: .72rem !important;
    font-weight: 750 !important;
    letter-spacing: .025em;
    line-height: 1.5;
}
.mc-input-card .form-group > div:focus-within {
    border-color: #9c8ab8 !important;
    box-shadow: 0 0 0 3px rgba(109, 90, 145, .11);
}
.mc-input-card input[type="number"].form-control {
    min-width: 0;
    min-height: 44px;
    padding: 9px 11px;
    color: var(--mc-ink);
    font-size: .9rem;
}
.mc-input-card input[type="number"].form-control:focus {
    box-shadow: none;
}
.mc-calculator .mc-ship-pills .ship-pill {
    min-height: 43px;
    border: 1px solid var(--mc-line) !important;
    border-radius: 11px !important;
    font-size: .8rem !important;
    transition: border-color .16s ease, background .16s ease, color .16s ease, transform .16s ease;
}
.mc-calculator .mc-ship-pills .ship-pill:hover:not(.active) {
    transform: translateY(-1px);
    border-color: #cfc3df !important;
    background: #faf8fc !important;
}
.mc-calculator .mc-ship-pills .ship-pill.active {
    border-color: var(--mc-accent) !important;
    background: var(--mc-accent) !important;
    color: #fff !important;
}
.mc-calculator .tax-mode-pill {
    min-height: 32px;
    padding-inline: 11px !important;
    border-radius: 7px !important;
    background: transparent;
    color: var(--mc-muted);
}
.mc-calculator .tax-mode-pill.active {
    background: var(--mc-accent) !important;
    color: #fff !important;
}
.mc-form-section-last {
    margin-bottom: 0 !important;
    padding-bottom: 0;
    border-bottom: 0;
}
.mc-result-card {
    position: sticky;
    top: 20px;
    min-width: 0;
    padding: clamp(22px, 2.5vw, 30px) !important;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 18px !important;
    background: linear-gradient(145deg, #6d5a91 0%, #51416f 100%) !important;
    box-shadow: 0 12px 28px rgba(81, 65, 111, .2) !important;
}
.mc-result-label {
    display: block;
    font-size: .67rem !important;
    letter-spacing: .075em !important;
}
.mc-result-card #res-final-price {
    margin: 8px 0 20px !important;
    font-size: clamp(1.8rem, 3vw, 2.3rem) !important;
    line-height: 1.2;
    letter-spacing: -.035em;
    overflow-wrap: anywhere;
}
.mc-result-breakdown > div {
    align-items: flex-start;
    gap: 14px;
    line-height: 1.45;
}
.mc-result-breakdown > div > span { min-width: 0; }
.mc-result-breakdown > div > strong {
    flex: 0 0 auto;
    max-width: 52%;
    text-align: right;
    overflow-wrap: anywhere;
}
.mc-result-breakdown > div:last-child > span:last-child { text-align: right; }
.mc-calculator button:focus-visible {
    outline: 3px solid rgba(157, 138, 184, .42);
    outline-offset: 2px;
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
@media (max-width: 1100px) {
    .mc-layout-grid { grid-template-columns: 1fr; }
    .mc-result-card { position: static; }
}

/* ===== RESPONSIVE: HP KECIL (di bawah 480px) ===== */
@media (max-width: 600px) {
    .mc-page-heading { align-items: flex-start; gap: 11px; margin-bottom: 17px; }
    .mc-heading-icon { flex-basis: 42px; width: 42px; height: 42px; border-radius: 13px; font-size: .98rem; }
    .mc-page-heading p { font-size: .79rem; }
    .mc-input-card { padding: 18px 15px !important; }
    .mc-calculator .mc-currency-label { font-size: .8rem; }
    .mc-inline-grid { grid-template-columns: 1fr !important; }
    .mc-ship-pills { flex-direction: column; }
    .mc-ship-pills .ship-pill { width: 100%; }
    .mc-tax-heading { align-items: flex-start !important; }
    .mc-tax-heading .mc-section-title { flex: 1 1 100%; }
    .mc-result-card { padding: 21px 18px !important; }
    .mc-result-breakdown { gap: 11px !important; }
    .mc-calculator #currency-pills { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 400px) {
    .mc-result-breakdown > div { gap: 9px; font-size: .79rem; }
    .mc-result-breakdown > div > strong { max-width: 47%; }
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
            currPills.forEach(p => { p.classList.remove('active'); p.setAttribute('aria-pressed', 'false'); });
            pill.classList.add('active');
            pill.setAttribute('aria-pressed', 'true');
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
            shipPills.forEach(p => { p.classList.remove('active'); p.setAttribute('aria-pressed', 'false'); });
            pill.classList.add('active');
            pill.setAttribute('aria-pressed', 'true');
            if(inputShippingRate) {
                inputShippingRate.value = pill.getAttribute('data-rate');
            }
            runExactCalc();
        });
    });

    taxModePills.forEach(pill => {
        pill.addEventListener('click', () => {
            taxModePills.forEach(p => { p.classList.remove('active'); p.setAttribute('aria-pressed', 'false'); });
            pill.classList.add('active');
            pill.setAttribute('aria-pressed', 'true');

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