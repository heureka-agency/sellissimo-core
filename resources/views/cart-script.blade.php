<script>
(() => {
    const cart = document.getElementById('sellissimo-cart');
    if (!cart || !window.fetch || !window.FormData) return;

    const content = cart.querySelector('[data-cart-content]');
    const status = cart.querySelector('[data-cart-status]');
    const retry = cart.querySelector('[data-cart-retry]');
    const pending = new Map();
    const saving = @js(__('sellissimo::shop.saving_cart'));
    const saved = @js(__('sellissimo::shop.cart_updated'));
    const failure = @js(__('sellissimo::shop.cart_update_failed'));
    let timer;
    let running = false;
    let checkoutRequested = false;
    cart.dataset.enhanced = '';

    function refresh(result, change) {
        const drafts = new Map();
        const focus = document.activeElement;
        for (const input of content.querySelectorAll('[data-cart-quantity]')) {
            if (pending.has(input.form.elements.line_key.value) ||
                (input.value !== input.dataset.confirmed && (input.id !== change.inputId || input.value !== change.body.get('quantity')))) {
                drafts.set(input.id, input.value);
            }
        }
        content.innerHTML = result.html;
        for (const [id, value] of drafts) {
            const input = document.getElementById(id);
            if (input) input.value = value;
        }
        for (const count of document.querySelectorAll('[data-cart-count]')) count.textContent = result.cart_label;
        if (focus.id && !focus.isConnected) {
            (document.getElementById(focus.id) || content.querySelector('[data-cart-quantity]') || status).focus({ preventScroll: true });
        }
    }

    async function save() {
        clearTimeout(timer);
        if (running) return;
        running = true;
        retry.hidden = true;
        content.setAttribute('aria-busy', 'true');
        while (pending.size) {
            const [key, change] = pending.entries().next().value;
            pending.delete(key);
            const input = document.getElementById(change.inputId);
            if (input && change.body.get('quantity') === '0') input.disabled = true;
            status.textContent = saving;
            try {
                const response = await fetch(change.url, { method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json' }, body: change.body });
                if (!response.ok) throw new Error();
                const result = await response.json();
                if (typeof result.html !== 'string' || typeof result.cart_label !== 'string') throw new Error();
                refresh(result, change);
                status.textContent = saved;
            } catch {
                if (input) input.disabled = false;
                if (!pending.has(key)) pending.set(key, change);
                status.textContent = failure.replace(':quantity', input?.dataset.confirmed ?? change.confirmed);
                retry.hidden = false;
                checkoutRequested = false;
                break;
            }
        }
        content.removeAttribute('aria-busy');
        running = false;
        if (checkoutRequested) {
            checkoutRequested = false;
            const invalid = content.querySelector('[data-cart-quantity]:invalid');
            if (invalid) invalid.reportValidity();
            else if (content.querySelector('[data-cart-checkout]')) location.assign(content.querySelector('[data-cart-checkout]').href);
        }
    }

    function queue(form, immediate) {
        const key = form.elements.line_key.value;
        const inputId = 'cart-quantity-' + key;
        const input = document.getElementById(inputId);
        clearTimeout(timer);
        if (form.reportValidity()) {
            pending.set(key, { inputId, confirmed: input?.dataset.confirmed ?? '–', url: form.action, body: new FormData(form) });
        } else {
            pending.delete(key);
        }
        if (pending.size) {
            if (immediate) save();
            else timer = setTimeout(save, 350);
        }
    }

    content.addEventListener('input', event => {
        if (event.target.matches('[data-cart-quantity]')) {
            const input = event.target;
            if (input.validity.valid) queue(input.form, false);
            else pending.delete(input.form.elements.line_key.value);
        }
    });
    content.addEventListener('submit', event => {
        if (!event.target.matches('[data-cart-action]')) return;
        event.preventDefault();
        queue(event.target, true);
    });
    content.addEventListener('click', event => {
        if (!event.target.closest('[data-cart-checkout]')) return;
        const invalid = content.querySelector('[data-cart-quantity]:invalid');
        if (invalid) {
            event.preventDefault();
            invalid.reportValidity();
        } else if (pending.size || running) {
            event.preventDefault();
            checkoutRequested = true;
            save();
        }
    });
    retry.addEventListener('click', save);
})();
</script>
