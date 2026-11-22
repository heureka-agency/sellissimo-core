<p class="ss-visually-hidden" role="status" data-catalog-announcement></p>
<script>
(() => {
    const form = document.querySelector('[data-catalog-filters]');
    const results = document.querySelector('[data-catalog-results]');
    if (!form || !results || !window.fetch || !window.AbortController) return;

    const error = document.querySelector('[data-catalog-error]');
    const announcement = document.querySelector('[data-catalog-announcement]');
    const submit = form.querySelector('[type=submit]');
    const details = form.querySelector('details');
    let timer;
    let pending;
    let editGroup = 0;
    let committedEditGroup = null;
    submit.hidden = true;

    function formUrl() {
        const url = new URL(form.action);
        for (const [name, value] of new FormData(form)) {
            if (value.trim()) url.searchParams.append(name, value.trim());
        }
        return url;
    }

    function restoreFields(url) {
        for (const control of form.elements) {
            if (!control.name) continue;
            const value = url.searchParams.get(control.name);
            if (control.type === 'checkbox') control.checked = value === control.value;
            else if (control.type !== 'hidden') control.value = value ?? '';
            else control.value = value ?? control.defaultValue;
        }
        const advanced = [...details.querySelectorAll('input, select')];
        if (advanced.some(field => field.type === 'checkbox' ? field.checked : field.value)) details.open = true;
    }

    function cancel() {
        clearTimeout(timer);
        pending?.abort();
        pending = null;
        results.removeAttribute('aria-busy');
    }

    async function update(url, historyMode, restoreFocus = false) {
        cancel();
        const requestEditGroup = editGroup;
        const request = new AbortController();
        pending = request;
        error.hidden = true;
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Sellissimo-Fragment': 'catalog' },
                signal: request.signal,
            });
            if (response.status === 422) {
                const { errors } = await response.json();
                throw new Error(Object.values(errors).flat().join(' '));
            }
            if (!response.ok) throw new Error();
            const html = await response.text();
            if (request.signal.aborted) return;
            const fragment = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-catalog-results]');
            if (!fragment) throw new Error();
            results.replaceChildren(...fragment.childNodes);
            const current = new URL(response.url || url);
            if (historyMode && current.href !== location.href) {
                const replace = historyMode === 'edit' && requestEditGroup === committedEditGroup;
                history[replace ? 'replaceState' : 'pushState'](history.state, '', current);
                committedEditGroup = historyMode === 'edit' ? requestEditGroup : null;
            }
            // The cart's return link should retain the filters chosen after the initial page load.
            for (const link of document.querySelectorAll('[data-catalog-context]')) {
                const destination = new URL(link.href);
                destination.search = current.search;
                link.href = destination;
            }
            announcement.textContent = results.querySelector('.catalog-count').textContent;
            if (restoreFocus) results.focus({ preventScroll: true });
            submit.hidden = true;
        } catch (exception) {
            if (request.signal.aborted) return;
            error.textContent = exception.message && exception.name === 'Error'
                ? exception.message : @js(__('sellissimo::shop.catalog_update_failed'));
            error.hidden = false;
            submit.hidden = false;
        } finally {
            if (!request.signal.aborted) results.removeAttribute('aria-busy');
        }
    }

    form.addEventListener('input', event => {
        if (!event.target.matches('input:not([type=checkbox]):not([type=hidden])')) return;
        cancel();
        timer = setTimeout(() => update(formUrl(), 'edit'), 300);
    });
    form.addEventListener('change', event => {
        if (!event.target.matches('select, input[type=checkbox]')) return;
        editGroup++;
        update(formUrl(), 'push');
    });
    form.addEventListener('focusout', () => { editGroup++; });
    form.addEventListener('submit', event => {
        if (!submit.hidden) return;
        event.preventDefault();
        editGroup++;
        update(formUrl(), 'push');
    });
    results.addEventListener('click', event => {
        const link = event.target.closest('.active-filters a, .store-pagination a, .ss-empty a');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        editGroup++;
        const url = new URL(link.href);
        restoreFields(url);
        update(url, 'push', true);
    });
    window.addEventListener('popstate', () => {
        editGroup++;
        const url = new URL(location.href);
        restoreFields(url);
        update(url, null);
    });
})();
</script>
