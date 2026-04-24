(function () {
    'use strict';

    const cfg = () => window.BlockseeAiseoReviewsConfig || {};
    let bulkCancelled = false;
    let rowOpInFlight = false;

    function lockRowButtons(exceptBtn) {
        qsa('.bsee-gen-review-btn').forEach(b => {
            if (b === exceptBtn) return;
            b.disabled = true;
            b.classList.add('bsee-blocked');
        });
    }
    function unlockRowButtons() {
        qsa('.bsee-gen-review-btn').forEach(b => {
            if (b.classList.contains('is-busy')) return;
            b.disabled = false;
            b.classList.remove('bsee-blocked');
        });
    }

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

    const qs = (s, c) => (c || document).querySelector(s);
    const qsa = (s, c) => Array.from((c || document).querySelectorAll(s));

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
        void el.offsetWidth;
        el.classList.add(ok ? 'bsee-flash-ok' : 'bsee-flash-err');
        el.textContent = msg || (ok ? '✓' : '!');
        setTimeout(() => { el.classList.remove('bsee-flash-ok', 'bsee-flash-err'); if (el.id !== 'bsee-prompt-status') el.textContent = ''; }, 2500);
    }

    function stars(rating) {
        rating = Math.max(0, Math.min(5, rating | 0));
        return '<span class="bsee-stars">'
            + '★'.repeat(rating)
            + '<span class="bsee-stars-empty">' + '☆'.repeat(5 - rating) + '</span>'
            + '</span>';
    }

    function updateRowCount(row, newCount) {
        const badge = qs('.bsee-review-badge', row);
        if (badge) {
            badge.textContent = newCount > 0 ? String(newCount) : '—';
            badge.classList.toggle('has', newCount > 0);
            badge.classList.toggle('none', newCount === 0);
            badge.dataset.count = String(newCount);
        }
        const preview = qs('.bsee-review-preview', row);
        if (preview && newCount > 0) {
            preview.innerHTML = '<span class="bsee-muted">Нажмите «Посмотреть», чтобы раскрыть</span>';
        }
    }

    // --- Prompt ---
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

    // --- Single row: generate + save 1 review at a time, block other rows while busy ---
    function initRowActions() {
        qsa('.bsee-gen-review-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (rowOpInFlight) return;
                rowOpInFlight = true;
                const id = btn.dataset.id;
                const row = qs('tr[data-element-id="' + id + '"]');
                lockRowButtons(btn);
                setBusy(btn, true, 'Генерирую…');
                call('generateAndSave', { id, count: 1 })
                    .then(data => {
                        updateRowCount(row, data.total || 0);
                        btn.classList.remove('is-busy');
                        btn.disabled = false;
                        btn.textContent = '✓ +' + (data.saved || 1);
                        btn.classList.add('bsee-btn-success');
                        setTimeout(() => {
                            btn.textContent = btn.dataset.origText || '+ 1 отзыв';
                            btn.classList.remove('bsee-btn-success');
                        }, 1600);
                    })
                    .catch(e => {
                        setBusy(btn, false);
                        alert('Ошибка: ' + e.message);
                    })
                    .finally(() => {
                        rowOpInFlight = false;
                        unlockRowButtons();
                    });
            });
        });

        qsa('.bsee-view-reviews-btn').forEach(btn => {
            btn.addEventListener('click', () => openViewer(parseInt(btn.dataset.id, 10)));
        });
    }

    function initials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    function declReviews(n) {
        const mod10 = n % 10, mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) return 'отзыв';
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'отзыва';
        return 'отзывов';
    }

    function openViewer(elementId) {
        const modal = qs('#bsee-reviews-viewer');
        const body = qs('#bsee-reviews-viewer-body');
        const title = qs('#bsee-reviews-viewer-title');
        if (!modal || !body) return;

        const row = qs('tr[data-element-id="' + elementId + '"]');
        const name = row ? (qs('.bsee-item-name', row) || {}).textContent : 'Товар';
        if (title) title.textContent = 'Отзывы: ' + (name || '#' + elementId);

        modal.style.display = 'flex';
        body.innerHTML = '<div class="bsee-muted" style="padding:20px;text-align:center;">Загружаем отзывы...</div>';

        call('list', { id: elementId, limit: 50 })
            .then(data => {
                const items = data.items || [];
                if (!items.length) {
                    body.innerHTML = `
                        <div class="bsee-rev-empty">
                            <div class="bsee-rev-empty-icon">☆</div>
                            <div>Отзывов пока нет</div>
                            <div class="bsee-muted" style="margin-top:6px;">Закройте модалку и нажмите «+ 1 отзыв»</div>
                        </div>`;
                    return;
                }

                const total = data.total || items.length;
                const ratings = items.map(r => r.rating || 0).filter(v => v > 0);
                const avg = ratings.length ? (ratings.reduce((a, b) => a + b, 0) / ratings.length) : 0;
                const summary = `
                    <div class="bsee-rev-summary">
                        <div class="bsee-rev-summary-left">
                            <div class="bsee-rev-summary-count">${total}</div>
                            <div class="bsee-rev-summary-label">${declReviews(total)}</div>
                        </div>
                        ${avg > 0 ? `
                            <div class="bsee-rev-summary-avg">
                                <div class="bsee-rev-summary-avg-value">${avg.toFixed(1)}</div>
                                ${stars(Math.round(avg))}
                            </div>` : ''}
                    </div>`;

                const renderItem = (r) => `
                    <div class="bsee-rev-item" data-id="${r.id}" data-author="${escapeHtml(r.author)}" data-rating="${r.rating || 0}" data-content="${escapeHtml(r.content || '')}">
                        <div class="bsee-rev-view">
                            <div class="bsee-rev-head">
                                <div class="bsee-rev-avatar">${escapeHtml(initials(r.author))}</div>
                                <div class="bsee-rev-head-info">
                                    <div class="bsee-rev-author">
                                        ${escapeHtml(r.author)}
                                        ${r.ai ? '<span class="bsee-rev-ai">AI</span>' : ''}
                                        ${r.approved ? '' : '<span class="bsee-rev-pending">на модерации</span>'}
                                    </div>
                                    <div class="bsee-rev-meta">
                                        ${stars(r.rating)}
                                        <span class="bsee-rev-meta-sep">·</span>
                                        <span class="bsee-rev-date">${escapeHtml(r.date)}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="bsee-rev-content">${escapeHtml(r.content).replace(/\n/g, '<br>')}</div>
                            <div class="bsee-rev-actions">
                                <button type="button" class="bsee-btn bsee-btn-small bsee-rev-edit" data-msg="${r.id}">Редактировать</button>
                                <button type="button" class="bsee-btn bsee-btn-small bsee-btn-ghost bsee-rev-delete" data-msg="${r.id}">Удалить</button>
                            </div>
                        </div>
                    </div>`;

                const list = '<div class="bsee-rev-list">' + items.map(renderItem).join('') + '</div>';
                body.innerHTML = summary + list;

                const attachActions = () => {
                    qsa('.bsee-rev-delete', body).forEach(b => b.addEventListener('click', onDelete));
                    qsa('.bsee-rev-edit', body).forEach(b => b.addEventListener('click', onEdit));
                };

                function onDelete() {
                    if (!confirm('Удалить этот отзыв?')) return;
                    const b = this;
                    const mid = b.dataset.msg;
                    const item = b.closest('.bsee-rev-item');
                    if (item) item.classList.add('bsee-rev-removing');
                    setBusy(b, true, 'Удаляю…');
                    call('delete', { messageId: mid })
                        .then(() => {
                            if (item) item.remove();
                            call('list', { id: elementId, limit: 1 }).then(d => updateRowCount(row, d.total || 0));
                            if (!qs('.bsee-rev-item', body)) {
                                body.innerHTML = `
                                    <div class="bsee-rev-empty">
                                        <div class="bsee-rev-empty-icon">☆</div>
                                        <div>Отзывы удалены</div>
                                    </div>`;
                            }
                        })
                        .catch(e => {
                            if (item) item.classList.remove('bsee-rev-removing');
                            setBusy(b, false);
                            alert('Ошибка: ' + e.message);
                        });
                }

                function onEdit() {
                    const b = this;
                    const item = b.closest('.bsee-rev-item');
                    if (!item || qs('.bsee-rev-edit-form', item)) return;
                    const author = item.dataset.author || '';
                    const rating = parseInt(item.dataset.rating || '5', 10);
                    const content = item.dataset.content || '';
                    const mid = b.dataset.msg;

                    const view = qs('.bsee-rev-view', item);
                    if (view) view.style.display = 'none';

                    const form = document.createElement('div');
                    form.className = 'bsee-rev-edit-form';
                    form.innerHTML = `
                        <div class="bsee-rev-edit-row">
                            <label class="bsee-rev-edit-field">
                                <span>Автор</span>
                                <input type="text" class="bsee-rev-edit-author" value="${escapeHtml(author)}">
                            </label>
                            <label class="bsee-rev-edit-field bsee-rev-edit-rating-wrap">
                                <span>Рейтинг</span>
                                <select class="bsee-rev-edit-rating">
                                    ${[5,4,3,2,1].map(n => {
                                        const word = n === 1 ? 'звезда' : (n < 5 ? 'звезды' : 'звёзд');
                                        return `<option value="${n}" ${n===rating?'selected':''}>${n} ${word}</option>`;
                                    }).join('')}
                                </select>
                            </label>
                        </div>
                        <label class="bsee-rev-edit-field">
                            <span>Текст отзыва</span>
                            <textarea class="bsee-rev-edit-content" rows="5">${escapeHtml(content)}</textarea>
                        </label>
                        <div class="bsee-rev-edit-actions">
                            <button type="button" class="bsee-btn bsee-btn-small bsee-btn-primary bsee-rev-save">Сохранить</button>
                            <button type="button" class="bsee-btn bsee-btn-small bsee-btn-ghost bsee-rev-cancel">Отмена</button>
                            <span class="bsee-rev-edit-status bsee-muted"></span>
                        </div>`;
                    item.appendChild(form);

                    qs('.bsee-rev-cancel', form).addEventListener('click', () => {
                        form.remove();
                        if (view) view.style.display = '';
                    });

                    qs('.bsee-rev-save', form).addEventListener('click', () => {
                        const saveBtn = qs('.bsee-rev-save', form);
                        const status = qs('.bsee-rev-edit-status', form);
                        const newAuthor = qs('.bsee-rev-edit-author', form).value.trim();
                        const newRating = parseInt(qs('.bsee-rev-edit-rating', form).value, 10);
                        const newContent = qs('.bsee-rev-edit-content', form).value.trim();
                        if (!newAuthor || !newContent) {
                            status.textContent = 'Имя и текст обязательны';
                            status.classList.add('bsee-flash-err');
                            return;
                        }
                        status.textContent = '';
                        status.classList.remove('bsee-flash-err');
                        setBusy(saveBtn, true, 'Сохраняю…');
                        call('update', { messageId: mid, author: newAuthor, content: newContent, rating: newRating })
                            .then(() => {
                                // Update dataset & re-render the view block from current data
                                item.dataset.author = newAuthor;
                                item.dataset.rating = String(newRating);
                                item.dataset.content = newContent;
                                const wasAi = !!qs('.bsee-rev-ai', view);
                                const wasPending = !!qs('.bsee-rev-pending', view);
                                const dateText = (qs('.bsee-rev-date', view) || {}).textContent || '';
                                view.innerHTML = `
                                    <div class="bsee-rev-head">
                                        <div class="bsee-rev-avatar">${escapeHtml(initials(newAuthor))}</div>
                                        <div class="bsee-rev-head-info">
                                            <div class="bsee-rev-author">
                                                ${escapeHtml(newAuthor)}
                                                ${wasAi ? '<span class="bsee-rev-ai">AI</span>' : ''}
                                                ${wasPending ? '<span class="bsee-rev-pending">на модерации</span>' : ''}
                                            </div>
                                            <div class="bsee-rev-meta">
                                                ${stars(newRating)}
                                                <span class="bsee-rev-meta-sep">·</span>
                                                <span class="bsee-rev-date">${escapeHtml(dateText)}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="bsee-rev-content">${escapeHtml(newContent).replace(/\n/g, '<br>')}</div>
                                    <div class="bsee-rev-actions">
                                        <button type="button" class="bsee-btn bsee-btn-small bsee-rev-edit" data-msg="${mid}">Редактировать</button>
                                        <button type="button" class="bsee-btn bsee-btn-small bsee-btn-ghost bsee-rev-delete" data-msg="${mid}">Удалить</button>
                                    </div>`;
                                form.remove();
                                view.style.display = '';
                                qs('.bsee-rev-edit', view).addEventListener('click', onEdit);
                                qs('.bsee-rev-delete', view).addEventListener('click', onDelete);
                            })
                            .catch(e => {
                                setBusy(saveBtn, false);
                                status.textContent = 'Ошибка: ' + e.message;
                                status.classList.add('bsee-flash-err');
                            });
                    });
                }

                attachActions();
            })
            .catch(e => { body.innerHTML = '<div class="bsee-flash-err" style="padding:20px;">Ошибка: ' + escapeHtml(e.message) + '</div>'; });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function initViewer() {
        const modal = qs('#bsee-reviews-viewer');
        if (!modal) return;
        qsa('.bsee-modal-close', modal).forEach(b => b.addEventListener('click', () => modal.style.display = 'none'));
        modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });
    }

    // --- Bulk scenario ---
    function initScenarioModal() {
        const modal = qs('#bsee-scenario-modal');
        const openBtn = qs('#bsee-open-scenario');
        if (!modal || !openBtn) return;

        openBtn.addEventListener('click', () => {
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

        const selAll = qs('#bsee-sections-select-all');
        const clear = qs('#bsee-sections-clear');
        if (selAll) selAll.addEventListener('click', () => qsa('.bsee-section-item:not(.hidden) .bsee-section-check', modal).forEach(c => c.checked = true));
        if (clear) clear.addEventListener('click', () => qsa('.bsee-section-check', modal).forEach(c => c.checked = false));

        const search = qs('#bsee-sections-search');
        const emptyHint = qs('#bsee-sections-empty');
        if (search) {
            search.addEventListener('input', () => {
                const q = search.value.trim().toLowerCase();
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
                const scenarioLabel = scenario === 'skip_with_reviews' ? 'Добавить только пустым' : 'Добавить всем';
                modal.style.display = 'none';

                const count = Math.max(1, Math.min(20, parseInt((qs('#bsee-rev-count') || {}).value || cfg().defaultCount || 3, 10)));

                const url = new URL(window.location.href);
                const iblockId = cfg().iblockId || parseInt(url.searchParams.get('IBLOCK_ID') || '0', 10);
                const searchQ = url.searchParams.get('find') || '';
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
                cancelBtn.onclick = () => { bulkCancelled = true; cancelBtn.disabled = true; };

                const CHUNK = 200;
                let total = 0;
                let processed = 0, failed = 0;

                try {
                    const firstChunk = await call('listNextChunk', {
                        iblockId, scenario, search: searchQ, afterId: 0, limit: CHUNK, includeTotal: 'Y', sectionIds,
                    });
                    total = firstChunk.totalCount || 0;
                    if (total === 0) {
                        if (box) box.style.display = 'none';
                        alert('Нет товаров, подходящих под этот сценарий.');
                        return;
                    }
                    if (!confirm(`Запустить "${scenarioLabel}" для ${total} товаров (${sectionLabel}), по ${count} отзыв(ов) на товар?\nВ сумме будет сгенерировано ~${total * count} отзывов. Если закроете вкладку, прогресс остановится.`)) {
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
                                const data = await call('generateAndSave', { id, count });
                                processed++;
                                const row = qs('tr[data-element-id="' + id + '"]');
                                if (row) updateRowCount(row, data.total || 0);
                            } catch (e) {
                                failed++;
                                console.error('[blocksee.aiseo.reviews] item ' + id + ': ' + e.message);
                            }
                            const pct = total ? Math.round(((processed + failed) / total) * 100) : 0;
                            if (bar) bar.style.width = pct + '%';
                            if (text) text.textContent = 'Обработка... ' + pct + '%' + (failed ? ' (ошибок: ' + failed + ')' : '');
                            if (counts) counts.textContent = (processed + failed) + ' / ' + total;
                        }
                        if (!chunk.hasMore || bulkCancelled) break;
                        chunk = await call('listNextChunk', {
                            iblockId, scenario, search: searchQ, afterId: chunk.lastId, limit: CHUNK, includeTotal: 'N', sectionIds,
                        });
                    }

                    if (text) {
                        text.textContent = bulkCancelled
                            ? `Отменено. Успешно: ${processed}, ошибок: ${failed}`
                            : `Готово. Успешно: ${processed}, ошибок: ${failed}`;
                    }
                    if (processed > 0 && !bulkCancelled) {
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } catch (e) {
                    if (text) text.textContent = 'Ошибка: ' + e.message;
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initPromptSave();
        initRowActions();
        initViewer();
        initScenarioModal();
    });
})();
