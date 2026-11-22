<!-- Lightbox Modal Zoom Window -->
<div id="image-modal-lightbox" class="image-modal">
    <div class="modal-content-wrap">
        <button class="modal-close-btn" id="modal-close-btn"><i class="fa-solid fa-xmark"></i></button>
        <img src="" alt="Proof Zoomed" id="modal-img-tag" class="modal-img">
        <div id="modal-caption-tag" class="modal-caption"></div>
    </div>
</div>

<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-brand">&copy; <?php echo date('Y'); ?> GO-WEB Platform. All rights reserved.</div>
        <div class="site-footer-credit">Powered by <a href="https://x.com/first_kao" target="_blank" rel="noopener noreferrer">First Kao</a></div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Tabs Switching
    const tabs = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.tab-panel');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-target');
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));

            tab.classList.add('active');
            const targetPanel = document.getElementById(target);
            if (targetPanel) targetPanel.classList.add('active');
        });
    });

    // Live Kalkulator (Dynamic Pill Buttons)
    const currPills = document.querySelectorAll('.curr-pill');
    const priceInput = document.getElementById('input-price');
    const rateInput = document.getElementById('input-rate');
    const qtyInput = document.getElementById('input-qty');
    const totalResult = document.getElementById('total-result');

    function calculateTotal() {
        const price = parseFloat(priceInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        const qty = parseFloat(qtyInput.value) || 1;
        const total = price * rate * qty;
        totalResult.textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
    }

    currPills.forEach(pill => {
        pill.addEventListener('click', () => {
            currPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');

            const newRate = pill.getAttribute('data-rate');
            rateInput.value = newRate;
            calculateTotal();
        });
    });

    if (priceInput) {
        priceInput.addEventListener('input', calculateTotal);
        rateInput.addEventListener('input', calculateTotal);
        qtyInput.addEventListener('input', calculateTotal);
        
        // Kalkulasi awal saat halaman dimuat
        calculateTotal();
    }

    // Lightbox Modal Zoom Foto WH
    const modal = document.getElementById('image-modal-lightbox');
    const modalImg = document.getElementById('modal-img-tag');
    const modalCaption = document.getElementById('modal-caption-tag');
    const modalCloseBtn = document.getElementById('modal-close-btn');
    const zoomableItems = document.querySelectorAll('.js-zoomable-img');

    zoomableItems.forEach(item => {
        item.addEventListener('click', () => {
            modalImg.src = item.getAttribute('data-full-img');
            modalCaption.textContent = item.getAttribute('data-caption');
            modal.classList.add('active');
        });
    });

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', () => modal.classList.remove('active'));
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('active'); });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
            modal.classList.remove('active');
        }
    });
});
</script>

<?php wp_footer(); ?>
</body>
</html>