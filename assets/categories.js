(function () {
    'use strict';

    var cfg = function () { return window.BlockseeAiseoConfig || {}; };
    var bulkCancelled = false;

    function call(action, params) {
        var c = cfg();
        var body = new URLSearchParams();
        body.set('sessid', c.sessid);
        Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
        var url = c.ajaxUrl + '?action=' + encodeURIComponent(c.controller + '.' + action);
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Bitrix-Csrf-Token': c.sessid },
            body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.status === 'success') return resp.data;
                var err = (resp && resp.errors && resp.errors.length)
                    ? resp.errors.map(function (e) { return e.message; }).join('; ')
                    : 'Ошибка запроса';
                throw new Error(err);
            });
    }

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

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

    function markRowStatus(row) {
        if (!row) return;
        var status = qs('.bsee-item-status', row);
        if (status) {
            status.classList.remove('empty');
            status.classList.add('ok');
            status.textContent = '● Описание есть';
        }
    }

    // --- Selection ---
    function updateSelected() {
        var checks = qsa('.bsee-item-check:checked');
        var count = checks.length;
        var counter = qs('#bsee-selected-count');
        var actions = qs('.bsee-bulk-actions');
        if (counter) counter.textContent = String(count);
        if (actions) actions.style.display = count > 0 ? 'flex' : 'none';
        var selAll = qs('#bsee-select-all');
        var total = qsa('.bsee-item-check').length;
        if (selAll) selAll.checked = count > 0 && count === total;
    }

    function initSelection() {
        qsa('.bsee-item-check').forEach(function (c) { c.addEventListener('change', updateSelected); });
        var selAll = qs('#bsee-select-all');
        if (selAll) {
            selAll.addEventListener('change', function () {
                qsa('.bsee-item-check').forEach(function (c) { c.checked = selAll.checked; });
                updateSelected();
            });
        }
    }

    // --- Single row actions ---
    function initRowActions() {
        qsa('.bsee-generate-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.id;
                var row = qs('tr[data-section-id="' + id + '"]');
                var ta = qs('.bsee-desc-textarea', row);
                setBusy(btn, true, 'Генерирую…');
                call('generate', { id: id })
                    .then(function (data) {
                        if (ta && data.description) {
                            ta.value = data.description;
                            ta.classList.add('bsee-desc-generated');
                            setTimeout(function () { ta.classList.remove('bsee-desc-generated'); }, 2500);
                        }
                    })
                    .catch(function (e) { alert('Ошибка: ' + e.message); })
                    .then(function () { setBusy(btn, false); });
            });
        });

        qsa('.bsee-save-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.id;
                var row = qs('tr[data-section-id="' + id + '"]');
                var ta = qs('.bsee-desc-textarea', row);
                if (!ta || !ta.value.trim()) {
                    alert('Описание пустое — нечего сохранять.');
                    return;
                }
                setBusy(btn, true, 'Сохраняю…');
                call('save', { id: id, description: ta.value })
                    .then(function () {
                        markRowStatus(row);
                        btn.classList.remove('is-busy');
                        btn.disabled = false;
                        btn.textContent = '✓ Сохранено';
                        btn.classList.add('bsee-btn-success');
                        setTimeout(function () {
                            btn.textContent = btn.dataset.origText || 'Сохранить';
                            btn.classList.remove('bsee-btn-success');
                        }, 1800);
                    })
                    .catch(function (e) {
                        setBusy(btn, false);
                        alert('Ошибка сохранения: ' + e.message);
                    });
            });
        });
    }

    // --- Progress ---
    function showProgress(total) {
        bulkCancelled = false;
        var box = qs('#bsee-progress');
        var bar = qs('#bsee-progress-bar');
        var text = qs('#bsee-progress-text');
        var counts = qs('#bsee-progress-counts');
        if (!box) return;
        box.style.display = 'flex';
        if (bar) bar.style.width = '0%';
        if (text) text.textContent = 'Подготовка...';
        if (counts) counts.textContent = '0 / ' + total;
    }
    function updateProgress(done, total, failed) {
        var bar = qs('#bsee-progress-bar');
        var text = qs('#bsee-progress-text');
        var counts = qs('#bsee-progress-counts');
        var pct = total ? Math.round((done / total) * 100) : 0;
        if (bar) bar.style.width = pct + '%';
        if (text) text.textContent = 'Обработка... ' + pct + '%' + (failed ? ' (ошибок: ' + failed + ')' : '');
        if (counts) counts.textContent = done + ' / ' + total;
    }
    function endProgress(done, total, failed) {
        var text = qs('#bsee-progress-text');
        if (text) text.textContent = bulkCancelled
            ? 'Отменено. Успешно: ' + done + ', ошибок: ' + failed
            : 'Готово. Успешно: ' + done + ', ошибок: ' + failed;
    }
    function progressCancelBtn() {
        var b = qs('#bsee-progress-cancel');
        if (b) b.addEventListener('click', function () { bulkCancelled = true; b.disabled = true; });
    }

    function processQueue(ids, doItem) {
        return new Promise(function (resolve) {
            var i = 0, done = 0, failed = 0;
            var total = ids.length;
            showProgress(total);
            function next() {
                if (bulkCancelled || i >= total) { endProgress(done, total, failed); return resolve({ done: done, failed: failed }); }
                var id = ids[i++];
                updateProgress(done, total, failed);
                doItem(id)
                    .then(function () { done++; })
                    .catch(function () { failed++; })
                    .then(function () { updateProgress(done, total, failed); next(); });
            }
            next();
        });
    }

    function reloadAfter(result, delay) {
        if (result && result.done > 0 && !bulkCancelled) {
            var text = qs('#bsee-progress-text');
            if (text) text.textContent = text.textContent + ' — обновляю страницу...';
            setTimeout(function () { window.location.reload(); }, delay || 1500);
        }
    }

    function initBulkGenerate() {
        var btn = qs('#bsee-bulk-generate');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var ids = qsa('.bsee-item-check:checked').map(function (c) { return c.value; });
            if (!ids.length) return alert('Выберите хотя бы одну категорию.');
            btn.disabled = true;
            processQueue(ids, function (id) {
                return call('generateAndSave', { id: id }).then(function () {
                    markRowStatus(qs('tr[data-section-id="' + id + '"]'));
                    var ta = qs('tr[data-section-id="' + id + '"] .bsee-desc-textarea');
                    if (ta) ta.value = '';
                });
            }).then(reloadAfter).then(function () { btn.disabled = false; });
        });
    }

    function initBulkSave() {
        var btn = qs('#bsee-bulk-save');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var rows = qsa('.bsee-item-check:checked').map(function (c) {
                var row = c.closest('tr');
                var ta = qs('.bsee-desc-textarea', row);
                return { id: c.value, ta: ta, original: ta ? ta.dataset.original : '' };
            }).filter(function (r) { return r.ta && r.ta.value.trim() && r.ta.value !== r.original; });
            if (!rows.length) return alert('Нет изменённых описаний для сохранения.');
            btn.disabled = true;
            processQueue(rows.map(function (r) { return r.id; }), function (id) {
                var row = rows.find(function (r) { return r.id === id; });
                return call('save', { id: id, description: row.ta.value }).then(function () {
                    markRowStatus(row.ta.closest('tr'));
                    row.ta.dataset.original = row.ta.value;
                });
            }).then(reloadAfter).then(function () { btn.disabled = false; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSelection();
        initRowActions();
        initBulkGenerate();
        initBulkSave();
        progressCancelBtn();
    });
})();
