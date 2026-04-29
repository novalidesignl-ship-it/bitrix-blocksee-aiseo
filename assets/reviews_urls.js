(function () {
    'use strict';

    const cfg = () => window.BlockseeAiseoUrlsConfig || {};
    let bulkCancelled = false;
    let resolved = []; // [{url, code, id, name, edit_url, status}]

    function call(controllerKey, action, params) {
        const c = cfg();
        const controller = c[controllerKey] || c.resolveController;
        const body = new URLSearchParams();
        body.set('sessid', c.sessid);
        Object.keys(params || {}).forEach(k => body.set(k, params[k]));
        const url = c.ajaxUrl + '?action=' + encodeURIComponent(controller + '.' + action);
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Bitrix-Csrf-Token': c.sessid },
            body,
        })
            .then(r => r.json())
            .then(resp => {
                if (resp && resp.status === 'success') return resp.data;
                const err = (resp && resp.errors && resp.errors.length)
                    ? resp.errors.map(e => e.message).join('; ')
                    : 'Ошибка запроса';
                throw new Error(err);
            });
    }

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setBusy(btn, busy, labelBusy) {
        if (!btn) return;
        if (busy) {
            if (!btn.dataset.origText) btn.dataset.origText = btn.textContent;
            btn.textContent = labelBusy || 'Работаю…';
            btn.disabled = true;
            btn.classList.add('is-busy');
        } else {
            btn.textContent = btn.dataset.origText || btn.textContent;
            btn.disabled = false;
            btn.classList.remove('is-busy');
        }
    }

    function statusBadge(status, extra) {
        const map = {
            'found':       { cls: 'ok',   text: '● Найден' },
            'not_found':   { cls: 'err',  text: '● Не найден' },
            'pending':     { cls: 'wait', text: '◌ В очереди' },
            'processing':  { cls: 'wait', text: '⟳ Генерация...' },
            'success':     { cls: 'ok',   text: '✓ Готово' },
            'error':       { cls: 'err',  text: '✕ Ошибка' },
        };
        const m = map[status] || { cls: '', text: status };
        return '<span class="bsee-item-status ' + m.cls + '">' + m.text + (extra ? ' — ' + escapeHtml(extra) : '') + '</span>';
    }

    function reviewBadge(count) {
        if (typeof count !== 'number') return '';
        if (count === 0) {
            return '<span class="bsee-item-status err" title="Нет отзывов">★ 0</span>';
        }
        return '<span class="bsee-item-status ok" title="Есть отзывы">★ ' + count + '</span>';
    }

    function isFilterActive() {
        const cb = qs('#bsee-filter-no-reviews');
        return !!(cb && cb.checked);
    }

    function visibleIndices() {
        const filter = isFilterActive();
        return resolved
            .map(function (it, idx) {
                if (it.status === 'not_found') return -1;
                if (filter && typeof it.review_count === 'number' && it.review_count > 0) return -1;
                return idx;
            })
            .filter(function (i) { return i >= 0; });
    }

    function getReviewCount() {
        const inp = qs('#bsee-rev-count');
        const v = inp ? parseInt(inp.value, 10) : NaN;
        return (Number.isFinite(v) && v >= 1 && v <= 20) ? v : 3;
    }

    function renderTable() {
        const table = qs('#bsee-urls-table');
        const tbody = qs('#bsee-urls-tbody');
        if (!table || !tbody) return;
        if (!resolved.length) {
            table.style.display = 'none';
            tbody.innerHTML = '';
            return;
        }
        table.style.display = '';
        const filter = isFilterActive();
        let displayedNum = 0;
        const rows = resolved.map((it, idx) => {
            // фильтр «только без отзывов» — скрываем те у кого review_count > 0
            if (filter && typeof it.review_count === 'number' && it.review_count > 0) {
                return '';
            }
            displayedNum++;
            const num = displayedNum;
            const urlCell = '<code style="font-size:11px;word-break:break-all">' + escapeHtml(it.url) + '</code>'
                + (it.code ? '<br><small class="bsee-muted">code: ' + escapeHtml(it.code) + '</small>' : '');
            let prodCell = '';
            if (it.id) {
                const editLink = it.edit_url
                    ? '<a href="' + escapeHtml(it.edit_url) + '" target="_blank">' + escapeHtml(it.name || ('#' + it.id)) + '</a>'
                    : escapeHtml(it.name || ('#' + it.id));
                prodCell = editLink
                    + '<br><small class="bsee-muted">ID: ' + it.id + '</small>'
                    + (typeof it.review_count === 'number' ? ' &nbsp; ' + reviewBadge(it.review_count) : '');
            } else {
                prodCell = '<span class="bsee-muted">—</span>';
            }
            const statusCell = statusBadge(it.status, it.error);
            let actionsCell = '';
            if (it.status === 'found' || it.status === 'success' || it.status === 'error') {
                actionsCell = '<button type="button" class="bsee-btn bsee-btn-small bsee-btn-ghost bsee-row-generate" data-idx="' + idx + '" data-id="' + it.id + '">Сгенерировать</button>';
            }
            return '<tr data-idx="' + idx + '" data-element-id="' + (it.id || '') + '">'
                + '<td>' + num + '</td>'
                + '<td>' + urlCell + '</td>'
                + '<td>' + prodCell + '</td>'
                + '<td>' + statusCell + '</td>'
                + '<td>' + actionsCell + '</td>'
                + '</tr>';
        }).filter(function (r) { return r !== ''; }).join('');
        tbody.innerHTML = rows;

        tbody.querySelectorAll('.bsee-row-generate').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx, 10);
                runOne(idx, btn);
            });
        });

        updateFilterCounter();
    }

    function updateFilterCounter() {
        const counter = qs('#bsee-filter-counter');
        if (!counter) return;
        const found = resolved.filter(function (it) { return it.status === 'found' || it.status === 'success'; });
        const known = found.filter(function (it) { return typeof it.review_count === 'number'; });
        const without = known.filter(function (it) { return it.review_count === 0; }).length;
        if (known.length === 0) {
            counter.textContent = '';
        } else {
            counter.textContent = '(' + without + ' из ' + found.length + ')';
        }
    }

    function setRowStatus(idx, status, error) {
        if (!resolved[idx]) return;
        resolved[idx].status = status;
        if (error !== undefined) resolved[idx].error = error;
        renderTable();
    }

    function showProgress(total) {
        bulkCancelled = false;
        const box = qs('#bsee-progress');
        const bar = qs('#bsee-progress-bar');
        const text = qs('#bsee-progress-text');
        const counts = qs('#bsee-progress-counts');
        const cancel = qs('#bsee-progress-cancel');
        if (!box) return;
        box.style.display = 'flex';
        if (bar) bar.style.width = '0%';
        if (text) text.textContent = 'Подготовка...';
        if (counts) counts.textContent = '0 / ' + total;
        if (cancel) cancel.disabled = false;
    }

    function updateProgress(done, total, failed) {
        const bar = qs('#bsee-progress-bar');
        const text = qs('#bsee-progress-text');
        const counts = qs('#bsee-progress-counts');
        const pct = total ? Math.round((done / total) * 100) : 0;
        if (bar) bar.style.width = pct + '%';
        if (text) text.textContent = 'Обработка... ' + pct + '%' + (failed ? ' (ошибок: ' + failed + ')' : '');
        if (counts) counts.textContent = done + ' / ' + total;
    }

    function endProgress(done, total, failed) {
        const text = qs('#bsee-progress-text');
        if (text) text.textContent = bulkCancelled
            ? `Отменено. Успешно: ${done}, ошибок: ${failed}`
            : `Готово. Успешно: ${done}, ошибок: ${failed}`;
    }

    function initCancelButton() {
        const b = qs('#bsee-progress-cancel');
        if (b) b.addEventListener('click', () => { bulkCancelled = true; b.disabled = true; });
    }

    function initResolve() {
        const btn = qs('#bsee-resolve-urls');
        const ta = qs('#bsee-urls-input');
        const status = qs('#bsee-urls-status');
        const bulkBtn = qs('#bsee-bulk-generate-urls');
        if (!btn || !ta) return;

        btn.addEventListener('click', () => {
            const urls = ta.value;
            if (!urls.trim()) {
                if (status) status.textContent = 'Введите хотя бы одну ссылку.';
                return;
            }
            setBusy(btn, true, 'Ищу...');
            if (status) status.textContent = '';
            if (bulkBtn) bulkBtn.style.display = 'none';

            call('resolveController', 'resolveUrls', { urls })
                .then(data => {
                    resolved = (data && data.items) ? data.items : [];
                    renderTable();
                    const found = resolved.filter(x => x.status === 'found').length;
                    const total = resolved.length;
                    if (status) {
                        status.textContent = `Обработано ссылок: ${total}. Найдено товаров: ${found}.`;
                    }
                    if (bulkBtn) bulkBtn.style.display = found > 0 ? '' : 'none';

                    // Подгружаем количество отзывов для всех найденных товаров,
                    // чтобы UI-фильтр «только без отзывов» мог работать.
                    const filterWrap = qs('#bsee-filter-no-reviews-wrap');
                    if (found === 0) {
                        if (filterWrap) filterWrap.style.display = 'none';
                        return;
                    }
                    const ids = resolved.filter(x => x.status === 'found' && x.id).map(x => x.id).join(',');
                    if (status) status.textContent += ' Считаю отзывы...';
                    return call('generateController', 'getReviewCounts', { ids }).then(d => {
                        const counts = (d && d.counts) ? d.counts : {};
                        resolved.forEach(it => {
                            if (it.id && (it.id in counts || String(it.id) in counts)) {
                                it.review_count = counts[it.id] !== undefined ? counts[it.id] : counts[String(it.id)];
                            }
                        });
                        if (filterWrap) filterWrap.style.display = 'inline-flex';
                        renderTable();
                        if (status) {
                            const without = resolved.filter(x => x.review_count === 0).length;
                            status.textContent = `Обработано ссылок: ${total}. Найдено товаров: ${found}. Без отзывов: ${without}.`;
                        }
                    });
                })
                .catch(e => {
                    if (status) status.textContent = 'Ошибка: ' + e.message;
                })
                .finally(() => setBusy(btn, false));
        });
    }

    function runOne(idx, btn) {
        const it = resolved[idx];
        if (!it || !it.id) return Promise.resolve();
        if (btn) setBusy(btn, true, '...');
        setRowStatus(idx, 'processing', '');
        const count = getReviewCount();
        return call('generateController', 'generateAndSave', { id: it.id, count })
            .then(() => { setRowStatus(idx, 'success', ''); })
            .catch(e => { setRowStatus(idx, 'error', e.message); });
    }

    function initFilterCheckbox() {
        const cb = qs('#bsee-filter-no-reviews');
        if (!cb) return;
        cb.addEventListener('change', () => { renderTable(); });
    }

    function initBulkGenerate() {
        const btn = qs('#bsee-bulk-generate-urls');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            const filter = isFilterActive();
            const indices = resolved
                .map((it, idx) => {
                    if (!((it.status === 'found' || it.status === 'error') && it.id)) return -1;
                    // Если включён фильтр «только без отзывов» — пропускаем те у кого отзывы есть
                    if (filter && typeof it.review_count === 'number' && it.review_count > 0) return -1;
                    return idx;
                })
                .filter(i => i >= 0);
            if (!indices.length) {
                alert('Нет найденных товаров для генерации.');
                return;
            }
            const count = getReviewCount();
            if (!confirm(`Запустить генерацию ${count} отзывов на каждый из ${indices.length} товаров? Если закроете вкладку, прогресс остановится.`)) {
                return;
            }
            btn.disabled = true;
            indices.forEach(i => setRowStatus(i, 'pending', ''));

            const total = indices.length;
            let done = 0, failed = 0;
            showProgress(total);
            updateProgress(0, total, 0);

            for (const idx of indices) {
                if (bulkCancelled) break;
                const it = resolved[idx];
                setRowStatus(idx, 'processing', '');
                try {
                    await call('generateController', 'generateAndSave', { id: it.id, count });
                    setRowStatus(idx, 'success', '');
                    done++;
                } catch (e) {
                    setRowStatus(idx, 'error', e.message);
                    failed++;
                    console.error('[blocksee.aiseo] item ' + it.id + ': ' + e.message);
                }
                updateProgress(done + failed, total, failed);
            }

            endProgress(done, total, failed);
            btn.disabled = false;
        });
    }

    function flash(el, ok, msg) {
        if (!el) return;
        el.classList.remove('bsee-flash-ok', 'bsee-flash-err');
        void el.offsetWidth;
        el.classList.add(ok ? 'bsee-flash-ok' : 'bsee-flash-err');
        el.textContent = msg || (ok ? '✓' : '!');
        setTimeout(() => { el.classList.remove('bsee-flash-ok', 'bsee-flash-err'); el.textContent = ''; }, 2500);
    }

    function initPromptSave() {
        const btn = qs('#bsee-save-prompt');
        const ta = qs('#bsee-custom-prompt');
        const status = qs('#bsee-prompt-status');
        if (!btn || !ta) return;
        btn.addEventListener('click', () => {
            setBusy(btn, true, 'Сохраняю…');
            call('promptController', 'savePrompt', { prompt: ta.value })
                .then(() => flash(status, true, '✓ Промпт сохранён'))
                .catch(e => flash(status, false, 'Ошибка: ' + e.message))
                .finally(() => setBusy(btn, false));
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initPromptSave();
        initResolve();
        initBulkGenerate();
        initCancelButton();
        initFilterCheckbox();
    });
})();
