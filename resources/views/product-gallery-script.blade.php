<script>
(() => {
    const gallery = document.currentScript.closest('[data-product-gallery]');
    const links = [...gallery.querySelectorAll('[data-gallery-image]')];
    if (gallery.hasAttribute('data-hover-zoom')) {
        for (const link of links) {
            const followPointer = event => {
                if (event.pointerType === 'touch') return;
                const bounds = link.getBoundingClientRect();
                link.style.setProperty('--zoom-x', ((event.clientX - bounds.left) / bounds.width * 100) + '%');
                link.style.setProperty('--zoom-y', ((event.clientY - bounds.top) / bounds.height * 100) + '%');
                link.setAttribute('data-hovering', '');
            };
            link.addEventListener('pointerenter', followPointer);
            link.addEventListener('pointermove', followPointer);
            link.addEventListener('pointerleave', () => link.removeAttribute('data-hovering'));
            link.addEventListener('pointercancel', () => link.removeAttribute('data-hovering'));
        }
    }

    const dialog = gallery.querySelector('[data-gallery-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const stage = dialog.querySelector('[data-gallery-stage]');
    const image = dialog.querySelector('[data-gallery-current]');
    const count = dialog.querySelector('[data-gallery-count]');
    const zoom = dialog.querySelector('[data-gallery-zoom]');
    let current = 0;
    let opener;
    let drag;

    function setZoom(enabled) {
        stage.toggleAttribute('data-zoomed', enabled);
        zoom.setAttribute('aria-pressed', String(enabled));
        stage.scrollLeft = (stage.scrollWidth - stage.clientWidth) / 2;
        stage.scrollTop = (stage.scrollHeight - stage.clientHeight) / 2;
    }

    function showImage(index) {
        current = (index + links.length) % links.length;
        const link = links[current];
        image.alt = link.querySelector('img').alt;
        image.src = link.href;
        if (count) count.textContent = (current + 1) + ' / ' + links.length;
        setZoom(false);
    }

    links.forEach((link, index) => {
        link.setAttribute('aria-haspopup', 'dialog');
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            opener = link;
            link.removeAttribute('data-hovering');
            showImage(index);
            dialog.showModal();
        });
    });

    dialog.querySelector('[data-gallery-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => opener?.focus({ preventScroll: true }));
    zoom.addEventListener('click', () => setZoom(!stage.hasAttribute('data-zoomed')));
    if (count) {
        dialog.querySelector('[data-gallery-previous]').addEventListener('click', () => showImage(current - 1));
        dialog.querySelector('[data-gallery-next]').addEventListener('click', () => showImage(current + 1));
        dialog.addEventListener('keydown', event => {
            if (stage.hasAttribute('data-zoomed') || event.altKey || event.metaKey || event.ctrlKey) return;
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
            event.preventDefault();
            showImage(current + (event.key === 'ArrowRight' ? 1 : -1));
        });
    }

    // Touch panning uses native scrolling. A mouse can drag the enlarged image.
    stage.addEventListener('pointerdown', event => {
        if (event.pointerType !== 'mouse' || event.button !== 0 || !stage.hasAttribute('data-zoomed')) return;
        drag = { x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop };
        stage.setPointerCapture(event.pointerId);
    });
    stage.addEventListener('pointermove', event => {
        if (!drag || !stage.hasPointerCapture(event.pointerId)) return;
        stage.scrollLeft = drag.left + drag.x - event.clientX;
        stage.scrollTop = drag.top + drag.y - event.clientY;
    });
    stage.addEventListener('lostpointercapture', () => { drag = null; });
})();
</script>
