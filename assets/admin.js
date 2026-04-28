(function () {
    'use strict';

    const cfg = () => window.BlockseeAiseoConfig || {};
    let bulkCancelled = false;

    function call(action, params) {
        const c = cfg();
        const body = new URLSearchParams();
        body.set('sessid', c.sessid);
        Object.keys(params || {}).forEach(k => body.set(k, params[k]));
        const url = c.ajaxUrl + '?action=' + encodeURIComponent(c.controller + '.' + action);
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
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

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

    function flash(el, ok, msg) {
        if (!el) return;
        el.classList.remove('bsee-flash-ok', 'bsee-flash-err');
        void el.offsetWidth; // reflow
        el.classList.add(ok ? 'bsee-flash-ok' : 'bsee-flash-err');
        el.textContent = msg || (ok ? '✓' : '!');
        setTimeout(() => { el.classList.remove('bsee-flash-ok', 'bsee-flash-err'); if (el.id !== 'bsee-prompt-status') el.textContent = ''; }, 2500);
    }

    function markRowStatus(row, ok) {
        if (!row) return;
        const status = qs('.bsee-item-status', row);
        if (status) {
            status.classList.remove('empty');
            status.classList.add('ok');
            status.textContent = '● Описание есть';
        }
    }

    // --- Custom prompt save ---
    function initPromptSave() {
        const btn = qs('#bsee-save-prompt');
        const ta = qs('#bsee-custom-prompt');
        const status = qs('#bsee-prompt-status');
        if (!btn || !ta) return;
        btn.addEventListener('click', () => {
            setBusy(btn, true, 'Сохраняю…');
            call('savePrompt', { prompt: ta.value })
                .then(() => flash(status, true, '✓ Промпт сохранён'))
                .catch(e => flash(status, false, 'Ошибка: ' + e.message))
                .finally(() => setBusy(btn, false));
        });
    }

    // --- Selection counter ---
    function updateSelected() {
        const checks = qsa('.bsee-item-check:checked');
        const count = checks.length;
        const counter = qs('#bsee-selected-count');
        const actions = qs('.bsee-bulk-actions');
        if (counter) counter.textContent = String(count);
        if (actions) actions.style.display = count > 0 ? 'flex' : 'none';
        const selAll = qs('#bsee-select-all');
        const total = qsa('.bsee-item-check').length;
        if (selAll) selAll.checked = count > 0 && count === total;
    }

    function initSelection() {
        qsa('.bsee-item-check').forEach(c => c.addEventListener('change', updateSelected));
        const selAll = qs('#bsee-select-all');
        if (selAll) {
            selAll.addEventListener('change', () => {
                qsa('.bsee-item-check').forEach(c => c.checked = selAll.checked);
                updateSelected();
            });
        }
    }

    // --- Single generate: writes straight into the row's textarea ---
    function initRowActions() {
        qsa('.bsee-generate-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const row = qs('tr[data-element-id="' + id + '"]');
                const ta = qs('.bsee-desc-textarea', row);
                setBusy(btn, true, 'Генерирую…');
                call('generate', { id })
                    .then(data => {
                        if (ta && data.description) {
                            ta.value = data.description;
                            ta.classList.add('bsee-desc-generated');
                            setTimeout(() => ta.classList.remove('bsee-desc-generated'), 2500);
                        }
                    })
                    .catch(e => alert('Ошибка: ' + e.message))
                    .finally(() => setBusy(btn, false));
            });
        });

        qsa('.bsee-restore-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const row = qs('tr[data-element-id="' + id + '"]');
                const ta = qs('.bsee-desc-textarea', row);
                if (!confirm('Откатить описание этого товара к предыдущей версии? Текст в редакторе будет заменён на сохранённый оригинал.')) return;
                setBusy(btn, true, 'Откатываю…');
                call('restoreLatest', { id })
                    .then(data => {
                        if (ta && typeof data.description === 'string') {
                            ta.value = data.description;
                            ta.classList.add('bsee-desc-generated');
                            setTimeout(() => ta.classList.remove('bsee-desc-generated'), 2500);
                        }
                        btn.classList.remove('is-busy');
                        btn.disabled = false;
                        btn.textContent = '✓ Откачено';
                        btn.classList.add('bsee-btn-success');
                        setTimeout(() => {
                            btn.textContent = btn.dataset.origText || '↶ Откатить';
                            btn.classList.remove('bsee-btn-success');
                        }, 1800);
                    })
                    .catch(e => {
                        setBusy(btn, false);
                        alert('Ошибка отката: ' + e.message);
                    });
            });
        });

        qsa('.bsee-save-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const row = qs('tr[data-element-id="' + id + '"]');
                const ta = qs('.bsee-desc-textarea', row);
                if (!ta || !ta.value.trim()) {
                    alert('Описание пустое — нечего сохранять.');
                    return;
                }
                setBusy(btn, true, 'Сохраняю…');
                call('save', { id, description: ta.value })
                    .then(() => {
                        markRowStatus(row, true);
                        btn.classList.remove('is-busy');
                        btn.disabled = false;
                        btn.textContent = '✓ Сохранено';
                        btn.classList.add('bsee-btn-success');
                        setTimeout(() => {
                            btn.textContent = btn.dataset.origText || 'Сохранить';
                            btn.classList.remove('bsee-btn-success');
                        }, 1800);
                    })
                    .catch(e => {
                        setBusy(btn, false);
                        alert('Ошибка сохранения: ' + e.message);
                    });
            });
        });
    }

    // --- Progress ---
    function showProgress(total) {
        bulkCancelled = false;
        const box = qs('#bsee-progress');
        const bar = qs('#bsee-progress-bar');
        const text = qs('#bsee-progress-text');
        const counts = qs('#bsee-progress-counts');
        if (!box) return;
        box.style.display = 'flex';
        if (bar) bar.style.width = '0%';
        if (text) text.textContent = 'Подготовка...';
        if (counts) counts.textContent = '0 / ' + total;
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
    const progressCancelBtn = () => {
        const b = qs('#bsee-progress-cancel');
        if (b) b.addEventListener('click', () => { bulkCancelled = true; b.disabled = true; });
    };

    // --- Bulk flows ---
    function processQueue(ids, doItem) {
        return new Promise(resolve => {
            let i = 0, done = 0, failed = 0;
            const total = ids.length;
            showProgress(total);
            function next() {
                if (bulkCancelled || i >= total) { endProgress(done, total, failed); return resolve({ done, failed }); }
                const id = ids[i++];
                updateProgress(done, total, failed);
                doItem(id)
                    .then(() => { done++; })
                    .catch(() => { failed++; })
                    .finally(() => { updateProgress(done, total, failed); next(); });
            }
            next();
        });
    }

    function reloadAfter(result, delay) {
        if (result && result.done > 0 && !bulkCancelled) {
            const text = qs('#bsee-progress-text');
            if (text) text.textContent = text.textContent + ' — обновляю страницу...';
            setTimeout(() => window.location.reload(), delay || 1500);
        }
    }

    function initBulkGenerate() {
        const btn = qs('#bsee-bulk-generate');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const ids = qsa('.bsee-item-check:checked').map(c => c.value);
            if (!ids.length) return alert('Выберите хотя бы один товар.');
            btn.disabled = true;
            processQueue(ids, id =>
                call('generateAndSave', { id }).then(() => {
                    markRowStatus(qs('tr[data-element-id="' + id + '"]'));
                    const ta = qs('tr[data-element-id="' + id + '"] .bsee-desc-textarea');
                    if (ta) ta.value = '';
                })
            ).then(reloadAfter).finally(() => { btn.disabled = false; });
        });
    }

    function initBulkRestore() {
        const btn = qs('#bsee-bulk-restore');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const ids = qsa('.bsee-item-check:checked').map(c => c.value);
            if (!ids.length) return alert('Выберите хотя бы один товар.');
            if (!confirm(`Откатить ${ids.length} ${ids.length === 1 ? 'товар' : (ids.length < 5 ? 'товара' : 'товаров')} к предыдущей версии описания? У товаров без сохранённого бэкапа ничего не изменится.`)) return;
            btn.disabled = true;
            processQueue(ids, id =>
                call('restoreLatest', { id }).then(data => {
                    const row = qs('tr[data-element-id="' + id + '"]');
                    const ta = qs('.bsee-desc-textarea', row);
                    if (ta && data && typeof data.description === 'string') {
                        ta.value = data.description;
                        ta.classList.add('bsee-desc-generated');
                        setTimeout(() => ta.classList.remove('bsee-desc-generated'), 2500);
                    }
                })
            ).then(() => {
                // Не перезагружаем страницу — пользователь видит обновлённые textarea
            }).finally(() => { btn.disabled = false; });
        });
    }

    function initBulkSave() {
        const btn = qs('#bsee-bulk-save');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const rows = qsa('.bsee-item-check:checked').map(c => {
                const row = c.closest('tr');
                const ta = qs('.bsee-desc-textarea', row);
                return { id: c.value, ta, original: ta ? ta.dataset.original : '' };
            }).filter(r => r.ta && r.ta.value.trim() && r.ta.value !== r.original);
            if (!rows.length) return alert('Нет изменённых описаний для сохранения.');
            btn.disabled = true;
            processQueue(rows.map(r => r.id), id => {
                const row = rows.find(r => r.id === id);
                return call('save', { id, description: row.ta.value }).then(() => {
                    markRowStatus(row.ta.closest('tr'), true);
                    row.ta.dataset.original = row.ta.value;
                });
            }).then(reloadAfter).finally(() => { btn.disabled = false; });
        });
    }

    function initScenarioModal() {
        const modal = qs('#bsee-scenario-modal');
        const openBtn = qs('#bsee-open-scenario');
        if (!modal || !openBtn) return;
        openBtn.addEventListener('click', () => {
            // Pre-check section selected in the toolbar (if any), first time only
            const toolbarSection = parseInt(cfg().sectionId || 0, 10);
            if (toolbarSection > 0 && !modal.dataset.prefilled) {
                const target = qs('.bsee-section-check[value="' + toolbarSection + '"]', modal);
                if (target) target.checked = true;
                modal.dataset.prefilled = '1';
            }
            modal.style.display = 'flex';
        });
        qsa('.bsee-modal-close', modal).forEach(b => b.addEventListener('click', () => modal.style.display = 'none'));
        modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

        const selAllSections = qs('#bsee-sections-select-all');
        const clearSections = qs('#bsee-sections-clear');
        if (selAllSections) selAllSections.addEventListener('click', () => qsa('.bsee-section-item:not(.hidden) .bsee-section-check', modal).forEach(c => c.checked = true));
        if (clearSections) clearSections.addEventListener('click', () => qsa('.bsee-section-check', modal).forEach(c => c.checked = false));

        const sectionsSearch = qs('#bsee-sections-search');
        const emptyHint = qs('#bsee-sections-empty');
        if (sectionsSearch) {
            sectionsSearch.addEventListener('input', () => {
                const q = sectionsSearch.value.trim().toLowerCase();
                let visible = 0;
                qsa('.bsee-section-item', modal).forEach(item => {
                    const match = !q || (item.dataset.name || '').includes(q);
                    item.classList.toggle('hidden', !match);
                    if (match) visible++;
                });
                if (emptyHint) emptyHint.style.display = visible === 0 ? 'block' : 'none';
            });
        }

        qsa('.bsee-scenario-option', modal).forEach(opt => {
            opt.addEventListener('click', async () => {
                const scenario = opt.dataset.scenario;
                const scenarioLabel = scenario === 'empty_only' ? 'Заполнить пустые' : 'Перезаписать все';
                modal.style.display = 'none';

                const url = new URL(window.location.href);
                const iblockId = cfg().iblockId || parseInt(url.searchParams.get('IBLOCK_ID') || '0', 10);
                const search = url.searchParams.get('find') || '';
                const sectionIds = qsa('.bsee-section-check:checked', modal).map(c => c.value).join(',');
                const sectionCount = qsa('.bsee-section-check:checked', modal).length;
                const sectionLabel = sectionCount === 0
                    ? 'все категории'
                    : `${sectionCount} ${sectionCount === 1 ? 'категорию' : (sectionCount < 5 ? 'категории' : 'категорий')}`;

                const box = qs('#bsee-progress');
                const text = qs('#bsee-progress-text');
                const counts = qs('#bsee-progress-counts');
                const bar = qs('#bsee-progress-bar');
                if (box) box.style.display = 'flex';
                if (text) text.textContent = 'Считаю товары по фильтру...';

                bulkCancelled = false;
                const cancelBtn = qs('#bsee-progress-cancel');
                if (cancelBtn) cancelBtn.disabled = false;

                const CHUNK = 200;
                let afterId = 0;
                let total = 0;
                let processed = 0, failed = 0;

                try {
                    // First chunk with count
                    const firstChunk = await call('listNextChunk', {
                        iblockId, scenario, search, afterId: 0, limit: CHUNK, includeTotal: 'Y', sectionIds,
                    });
                    total = firstChunk.totalCount || 0;
                    if (total === 0) {
                        if (box) box.style.display = 'none';
                        alert('Нет товаров, подходящих под этот сценарий.');
                        return;
                    }
                    if (!confirm(`Запустить "${scenarioLabel}" для ${total} товаров (${sectionLabel})? Если закроете вкладку, прогресс остановится.`)) {
                        if (box) box.style.display = 'none';
                        return;
                    }
                    if (bar) bar.style.width = '0%';
                    if (counts) counts.textContent = '0 / ' + total;
                    if (text) text.textContent = 'Обработка... 0%';

                    let chunk = firstChunk;
                    while (true) {
                        if (bulkCancelled) break;
                        for (const id of chunk.ids) {
                            if (bulkCancelled) break;
                            try {
                                await call('generateAndSave', { id });
                                processed++;
                                const row = qs('tr[data-element-id="' + id + '"]');
                                if (row) {
                                    markRowStatus(row);
                                    const ta = qs('.bsee-desc-textarea', row);
                                    if (ta) ta.value = '';
                                }
                            } catch (e) {
                                failed++;
                                console.error('[blocksee.aiseo] item ' + id + ': ' + e.message);
                            }
                            const pct = total ? Math.round(((processed + failed) / total) * 100) : 0;
                            if (bar) bar.style.width = pct + '%';
                            if (text) text.textContent = 'Обработка... ' + pct + '%' + (failed ? ' (ошибок: ' + failed + ')' : '');
                            if (counts) counts.textContent = (processed + failed) + ' / ' + total;
                        }
                        afterId = chunk.lastId;
                        if (!chunk.hasMore || bulkCancelled) break;
                        chunk = await call('listNextChunk', {
                            iblockId, scenario, search, afterId, limit: CHUNK, includeTotal: 'N', sectionIds,
                        });
                    }

                    if (text) {
                        text.textContent = bulkCancelled
                            ? `Отменено. Успешно: ${processed}, ошибок: ${failed}`
                            : `Готово. Успешно: ${processed}, ошибок: ${failed}`;
                    }
                    reloadAfter({ done: processed, failed });
                } catch (e) {
                    if (text) text.textContent = 'Ошибка: ' + e.message;
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initPromptSave();
        initSelection();
        initRowActions();
        initBulkGenerate();
        initBulkSave();
        initBulkRestore();
        initScenarioModal();
        progressCancelBtn();
    });
})();
