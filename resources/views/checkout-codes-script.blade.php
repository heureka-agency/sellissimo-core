<script>
(() => {
    let saving = false;
    const scope = document.querySelector('.checkout-layout');
    scope.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.isComposing || !event.target.matches('.checkout-codes input')) return;
        event.preventDefault();
        const apply = event.target.closest('.checkout-codes').querySelector('[name="code_action"][value="apply"]');
        apply.form.requestSubmit(apply);
    });
    scope.addEventListener('submit', async event => {
        const button = event.submitter;
        if (saving) {
            event.preventDefault();
            return;
        }
        if (button?.name !== 'code_action' || !window.fetch) return;
        event.preventDefault();
        const form = event.target;
        const summary = scope.querySelector('#checkout-summary');
        const status = summary.querySelector('[data-code-status]');
        const codes = summary.querySelector('.checkout-codes');
        const data = new FormData();
        data.set('_token', form.elements._token.value);
        data.set('code_action', button.value);
        for (const input of codes.querySelectorAll('input[name]')) data.set(input.name, input.value);
        if (form.elements.email?.validity.valid) data.set('email', form.elements.email.value);
        saving = true;
        summary.setAttribute('aria-busy', 'true');
        status.textContent = '';
        try {
            const response = await fetch(button.formAction, {method: 'POST', body: data, headers: {Accept: 'application/json'}});
            if (!response.ok) {
                if (response.status === 422) {
                    const {errors} = await response.json();
                    status.textContent = Object.values(errors).flat().join(' ');
                    const failed = codes.querySelector(errors.voucher ? '[name="voucher_code"]' : '[name="coupon_code"]');
                    failed.setAttribute('aria-invalid', 'true');
                    failed.setAttribute('aria-describedby', 'ss-codes-status');
                    failed.focus();
                    return;
                }
                throw new Error();
            }
            const {url} = await response.json();
            const page = await fetch(url, {headers: {Accept: 'text/html'}});
            if (!page.ok) throw new Error();
            const next = new DOMParser().parseFromString(await page.text(), 'text/html');
            const nextSummary = next.querySelector('#checkout-summary');
            if (!nextSummary || next.querySelector('[name="checkout_step"]')?.value !== scope.querySelector('[name="checkout_step"]')?.value) {
                window.location.assign(page.url);
                return;
            }
            const step = form.elements.checkout_step?.value;
            if (step && step !== 'contact' && step !== 'delivery') {
                const nextForm = next.querySelector('#ss-checkout-step');
                if (!nextForm) { window.location.assign(page.url); return; }
                const checked = [...form.querySelectorAll('input:checked')].map(input => [input.name, input.value]);
                const samePolicy = form.elements.policy_version?.value === nextForm.elements.policy_version?.value;
                for (const radio of nextForm.querySelectorAll('input[type=radio],input[type=checkbox]')) {
                    if ((samePolicy || !radio.name.startsWith('consents[')) && checked.some(([name, value]) => radio.name === name && radio.value === value)) {
                        radio.checked = true;
                    }
                }
                for (const field of nextForm.querySelectorAll('input,select')) {
                    if (!field.name.startsWith('checkout_steps[')) continue;
                    const previous = form.elements.namedItem(field.name);
                    if (!previous) continue;
                    if (field.tagName !== 'SELECT' || [...field.options].some(option => option.value === previous.value)) {
                        field.value = previous.value;
                    }
                }
                form.replaceWith(nextForm);
            } else {
                const methods = form.querySelector('.ss-checkout-methods');
                const nextMethods = next.querySelector('.ss-checkout-methods');
                if (methods && nextMethods) {
                    const choice = methods.querySelector(':checked')?.value;
                    for (const radio of nextMethods.querySelectorAll('input')) if (radio.value === choice) radio.checked = true;
                    methods.replaceWith(nextMethods);
                } else if (Boolean(methods) !== Boolean(nextMethods)) {
                    window.location.assign(page.url);
                    return;
                }
                form.dispatchEvent(new Event('sellissimo:codes'));
            }
            summary.replaceWith(nextSummary);
            document.querySelector('.checkout-mobile-total')?.replaceWith(next.querySelector('.checkout-mobile-total'));
            nextSummary.querySelector('.checkout-codes').open = true;
            const message = nextSummary.querySelector('[data-code-status]');
            message.textContent = @js(__('sellissimo::shop.codes_updated'));
            nextSummary.querySelector('.checkout-codes summary').focus({preventScroll: true});
        } catch {
            status.textContent = @js(__('sellissimo::shop.codes_failed'));
        } finally {
            saving = false;
            summary.removeAttribute('aria-busy');
        }
    });
})();
</script>
