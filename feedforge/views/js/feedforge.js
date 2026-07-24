/**
 * Feed Forge - Vanilla JavaScript
 * AJAX calls, tabs, toasts, interactions
 */

'use strict';

const FeedForge = (function () {
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------
    const config = {
        apiBaseUrl: '',  // Derived from current page URL
        modulePath: '',
        adminToken: '', // Admin CSRF token from URL
        adminTokenParam: '_token', // PS9: '_token', PS8: 'token'
        translations: {},
    };

    /**
     * Translation helper - looks up key in translations dictionary.
     * Supports parameter substitution: t('key', {'%param%': value})
     */
    function t(key, params) {
        let str = config.translations[key] || key;
        if (params) {
            for (const [k, v] of Object.entries(params)) {
                str = str.replace(k, v);
            }
        }
        return str;
    }

    // -------------------------------------------------------------------------
    // Toast notifications
    // -------------------------------------------------------------------------
    const toast = {
        container: null,

        init() {
            this.container = document.createElement('div');
            this.container.className = 'ff-toast-container';
            document.body.appendChild(this.container);
        },

        show(message, type = 'success', duration = 4000) {
            const el = document.createElement('div');
            el.className = `ff-toast ff-toast--${type}`;
            el.innerHTML = `
                <span class="ff-toast-message">${this.escape(message)}</span>
                <button class="ff-toast-close" onclick="this.parentElement.remove()">&times;</button>
            `;
            this.container.appendChild(el);

            if (duration > 0) {
                setTimeout(() => el.remove(), duration);
            }
        },

        success(msg) { this.show(msg, 'success'); },
        error(msg) { this.show(msg, 'error', 6000); },
        warning(msg) { this.show(msg, 'warning', 5000); },

        escape(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    };

    // -------------------------------------------------------------------------
    // API client
    // -------------------------------------------------------------------------
    const api = {
        async get(endpoint, params = {}) {
            const url = new URL(config.apiBaseUrl + endpoint, window.location.origin);
            if (config.adminToken) {
                url.searchParams.set(config.adminTokenParam, config.adminToken);
            }
            Object.entries(params).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== '') {
                    url.searchParams.set(k, String(v));
                }
            });

            let response;
            try {
                response = await fetch(url.toString(), {
                    method: 'GET',
                    headers: this.headers(),
                });
            } catch {
                throw new Error(t('error.server_connection'));
            }

            return this.handleResponse(response);
        },

        async post(endpoint, data = {}) {
            const url = new URL(config.apiBaseUrl + endpoint, window.location.origin);
            if (config.adminToken) {
                url.searchParams.set(config.adminTokenParam, config.adminToken);
            }

            let response;
            try {
                response = await fetch(url.toString(), {
                    method: 'POST',
                    headers: {
                        ...this.headers(),
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data),
                });
            } catch {
                throw new Error(t('error.server_connection'));
            }

            return this.handleResponse(response);
        },

        headers() {
            return {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        async handleResponse(response) {
            let data;
            const contentType = response.headers.get('content-type') || '';

            if (!contentType.includes('application/json')) {
                const text = await response.text();
                console.error('[FeedForge] Non-JSON response:', response.status, response.url, text.substring(0, 500));
                throw new Error(`${t('error.server_html_response')} (HTTP ${response.status}, url: ${response.url})`);
            }

            try {
                data = await response.json();
            } catch {
                throw new Error(`${t('error.json_parse')} (HTTP ${response.status})`);
            }

            if (!response.ok) {
                throw new Error(data.error || data.message || `HTTP ${response.status}`);
            }

            return data;
        }
    };

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------
    const tabs = {
        init() {
            document.querySelectorAll('[data-ff-tabs]').forEach(tabGroup => {
                const buttons = tabGroup.querySelectorAll('[data-ff-tab]');
                const panels = tabGroup.parentElement.querySelectorAll('[data-ff-panel]');

                buttons.forEach(btn => {
                    btn.addEventListener('click', () => {
                        const target = btn.dataset.ffTab;

                        buttons.forEach(b => b.classList.remove('ff-tab--active'));
                        panels.forEach(p => p.classList.remove('ff-tab-panel--active'));

                        btn.classList.add('ff-tab--active');
                        const panel = tabGroup.parentElement.querySelector(`[data-ff-panel="${target}"]`);
                        if (panel) panel.classList.add('ff-tab-panel--active');
                    });
                });
            });
        }
    };

    // -------------------------------------------------------------------------
    // Confirm dialogs
    // -------------------------------------------------------------------------
    const confirm = {
        show(message, onConfirm) {
            if (window.confirm(message)) {
                onConfirm();
            }
        }
    };

    // -------------------------------------------------------------------------
    // Loading states
    // -------------------------------------------------------------------------
    const loading = {
        show(element) {
            element.classList.add('ff-loading-overlay');
            element.setAttribute('data-loading', 'true');
        },

        hide(element) {
            element.classList.remove('ff-loading-overlay');
            element.removeAttribute('data-loading');
        },

        button(btn, isLoading) {
            if (isLoading) {
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent;
                btn.innerHTML = '<span class="ff-spinner"></span>';
            } else {
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || btn.textContent;
            }
        }
    };

    // -------------------------------------------------------------------------
    // Pagination helper
    // -------------------------------------------------------------------------
    const pagination = {
        render(container, currentPage, totalPages, onPageChange) {
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = `<div class="ff-pagination">`;
            html += `<span class="ff-pagination-info">Strona ${currentPage} z ${totalPages}</span>`;
            html += `<div class="ff-pagination-buttons">`;

            if (currentPage > 1) {
                html += `<button class="ff-pagination-btn" data-page="${currentPage - 1}">&laquo;</button>`;
            }

            const start = Math.max(1, currentPage - 2);
            const end = Math.min(totalPages, currentPage + 2);

            for (let i = start; i <= end; i++) {
                const active = i === currentPage ? ' ff-pagination-btn--active' : '';
                html += `<button class="ff-pagination-btn${active}" data-page="${i}">${i}</button>`;
            }

            if (currentPage < totalPages) {
                html += `<button class="ff-pagination-btn" data-page="${currentPage + 1}">&raquo;</button>`;
            }

            html += `</div></div>`;
            container.innerHTML = html;

            container.querySelectorAll('[data-page]').forEach(btn => {
                btn.addEventListener('click', () => onPageChange(parseInt(btn.dataset.page)));
            });
        }
    };

    // -------------------------------------------------------------------------
    // Format helpers
    // -------------------------------------------------------------------------
    const format = {
        number(n) {
            return new Intl.NumberFormat().format(n);
        },

        percent(n, decimals = 2) {
            return new Intl.NumberFormat(undefined, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }).format(n) + '%';
        },

        date(dateStr) {
            if (!dateStr) return '\u2014';
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }).format(new Date(dateStr));
        },

        timeAgo(dateStr) {
            if (!dateStr) return '\u2014';
            const seconds = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);

            if (seconds < 60) return t('common.time_just_now');
            if (seconds < 3600) return `${Math.floor(seconds / 60)} ${t('common.time_minutes_ago')}`;
            if (seconds < 86400) return `${Math.floor(seconds / 3600)} ${t('common.time_hours_ago')}`;
            return `${Math.floor(seconds / 86400)} ${t('common.time_days_ago')}`;
        },

        statusBadge(status) {
            const map = {
                approved: { class: 'success', label: t('status.approved') },
                pending: { class: 'warning', label: t('status.pending') },
                disapproved: { class: 'danger', label: t('status.disapproved') },
                expiring: { class: 'warning', label: t('status.expiring') },
                unknown: { class: 'neutral', label: t('status.unknown') },
                completed: { class: 'success', label: t('status.completed') },
                running: { class: 'info', label: t('status.running') },
                failed: { class: 'danger', label: t('status.failed') },
                processing: { class: 'info', label: t('status.processing') },
            };
            const s = map[status] || map.unknown;
            return `<span class="ff-badge ff-badge--${s.class}">${s.label}</span>`;
        },

        actionBadge(action) {
            const map = {
                insert: { class: 'info', label: t('action.insert') },
                update: { class: 'warning', label: t('action.update') },
                delete: { class: 'danger', label: t('action.delete') },
            };
            const a = map[action] || { class: 'neutral', label: action };
            return `<span class="ff-badge ff-badge--${a.class}">${a.label}</span>`;
        }
    };

    // -------------------------------------------------------------------------
    // Debounce helper
    // -------------------------------------------------------------------------
    function debounce(fn, delay) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // -------------------------------------------------------------------------
    // Page: Dashboard
    // -------------------------------------------------------------------------
    const pageDashboard = {
        async load() {
            try {
                const res = await api.get('/api/dashboard/stats');
                const d = res.data;

                this.renderConnection(d.connection);
                this.renderStats(d.stats);
                this.renderIssueCounts(d.issueCounts);
                this.renderIssues(d.criticalIssues);
                this.renderLastSync(d.lastSync);
                this.renderQueueHealth(d.queueHealth);
                this.loadAccountStatus();
            } catch (e) {
                toast.error(t('dashboard.load_error') + e.message);
            }
        },

        renderConnection(conn) {
            const el = document.getElementById('ff-connection-status');
            if (!el) return;

            if (conn.connected) {
                el.className = 'ff-connection ff-connection--connected ff-mb-lg';
                el.querySelector('.ff-dot').className = 'ff-dot ff-dot--success';
                el.querySelector('.ff-connection-detail').textContent =
                    `${conn.email} \u00B7 Merchant ID: ${conn.merchantId}`;
                const btn = el.querySelector('.ff-btn');
                if (btn && btn.tagName === 'A') {
                    btn.textContent = t('dashboard.connected');
                    btn.classList.add('ff-btn--success');
                    btn.classList.remove('ff-btn--ghost');
                    btn.removeAttribute('href');
                }
            } else {
                el.className = 'ff-connection ff-connection--disconnected ff-mb-lg';
                el.querySelector('.ff-dot').className = 'ff-dot ff-dot--neutral';
            }
        },

        renderStats(stats) {
            const setText = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = format.number(val);
            };
            setText('ff-stat-total', stats.total || 0);
            setText('ff-stat-approved', stats.approved || 0);
            setText('ff-stat-pending', stats.pending || 0);
            setText('ff-stat-disapproved', stats.disapproved || 0);
            setText('ff-stat-not-synced', stats.unknown || 0);
        },

        renderIssues(issues) {
            const emptyEl = document.getElementById('ff-issues-empty');
            const listEl = document.getElementById('ff-issues-list');
            if (!emptyEl || !listEl) return;

            if (!issues || issues.length === 0) {
                emptyEl.classList.remove('ff-hidden');
                listEl.classList.add('ff-hidden');
                return;
            }

            emptyEl.classList.add('ff-hidden');
            listEl.classList.remove('ff-hidden');

            listEl.innerHTML = issues.map(issue => `
                <div class="ff-flex-between ff-mb-sm" style="padding: 8px 0; border-bottom: 1px solid var(--ff-border);">
                    <div>
                        <span class="ff-badge ff-badge--${issue.severity === 'critical' ? 'danger' : 'warning'}">${toast.escape(issue.severity || 'warning')}</span>
                        <span class="ff-text-sm ff-ml-sm">${toast.escape(issue.message || issue.issue_code || '')}</span>
                    </div>
                    <span class="ff-text-sm ff-text-muted">${format.number(issue.count || issue.affected_count || 1)} ${t('dashboard.products_count')}</span>
                </div>
            `).join('');
        },

        renderLastSync(sync) {
            const emptyEl = document.getElementById('ff-sync-empty');
            const infoEl = document.getElementById('ff-sync-info');
            if (!emptyEl || !infoEl) return;

            if (!sync) {
                emptyEl.classList.remove('ff-hidden');
                infoEl.classList.add('ff-hidden');
                return;
            }

            emptyEl.classList.add('ff-hidden');
            infoEl.classList.remove('ff-hidden');

            infoEl.innerHTML = `
                <div class="ff-flex-between ff-mb-sm">
                    <span class="ff-text-sm ff-text-muted">Typ</span>
                    <span class="ff-text-sm">${toast.escape(sync.sync_type || 'full')}</span>
                </div>
                <div class="ff-flex-between ff-mb-sm">
                    <span class="ff-text-sm ff-text-muted">${t('dashboard.sync_started')}</span>
                    <span class="ff-text-sm">${format.date(sync.started_at)}</span>
                </div>
                <div class="ff-flex-between ff-mb-sm">
                    <span class="ff-text-sm ff-text-muted">Status</span>
                    ${format.statusBadge(sync.status || 'completed')}
                </div>
                <div class="ff-flex-between ff-mb-sm">
                    <span class="ff-text-sm ff-text-muted">Przetworzono</span>
                    <span class="ff-text-sm">${format.number(sync.products_processed || 0)}</span>
                </div>
                ${(sync.products_failed || 0) > 0 ? `
                <div class="ff-flex-between">
                    <span class="ff-text-sm ff-text-muted">${t('dashboard.sync_errors')}</span>
                    <span class="ff-text-sm" style="color: var(--ff-danger);">${format.number(sync.products_failed)}</span>
                </div>` : ''}
            `;
        },

        renderQueueHealth(queue) {
            const setText = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = format.number(val);
            };
            setText('ff-queue-pending', queue.pending || 0);
            setText('ff-queue-processing', queue.processing || 0);
            setText('ff-queue-failed', queue.failed || 0);
        },

        renderIssueCounts(counts) {
            const el = document.getElementById('ff-issue-counts');
            if (!el || !counts) return;

            const parts = [];
            const critical = parseInt(counts.critical || 0);
            const warning = parseInt(counts.warning || 0);
            const info = parseInt(counts.info || 0);

            if (critical > 0) parts.push(`<span class="ff-badge ff-badge--danger">${critical} kryt.</span>`);
            if (warning > 0) parts.push(`<span class="ff-badge ff-badge--warning">${warning} ostrz.</span>`);
            if (info > 0) parts.push(`<span class="ff-badge ff-badge--info">${info} info</span>`);

            el.innerHTML = parts.join(' ');
        },

        async refreshStatuses() {
            const btn = document.getElementById('ff-refresh-statuses');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/status/sync');
                toast.success(res.message || t('status.refresh_success'));
                await this.load();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        async triggerSync(type) {
            const btn = document.getElementById('ff-sync-now');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/dashboard/sync', { type });
                toast.success(res.message || t('products.sync_complete'));
                this.load();
            } catch (e) {
                toast.error(t('products.sync_error') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        async loadAccountStatus() {
            const refreshBtn = document.getElementById('ff-refresh-account-status');
            if (refreshBtn) loading.button(refreshBtn, true);
            try {
                const res = await api.get('/api/account-status');
                this.renderAccountStatus(res.data);
            } catch (e) {
                console.warn('[FeedForge] Account status error:', e.message);
            } finally {
                if (refreshBtn) loading.button(refreshBtn, false);
            }
        },

        renderAccountStatus(data) {
            const card = document.getElementById('ff-account-health');
            if (!card) return;

            if (data.error) return; // not connected or API error - keep hidden

            card.classList.remove('ff-hidden');

            const claimedEl = document.getElementById('ff-website-claimed');
            if (claimedEl) {
                claimedEl.innerHTML = data.websiteClaimed
                    ? '<span class="ff-badge ff-badge--success">Tak</span>'
                    : '<span class="ff-badge ff-badge--danger">Nie</span>';
            }

            const issuesList = document.getElementById('ff-account-issues-list');
            const noIssues = document.getElementById('ff-account-no-issues');
            if (!issuesList || !noIssues) return;

            if (!data.issues || data.issues.length === 0) {
                issuesList.innerHTML = '';
                noIssues.classList.remove('ff-hidden');
                return;
            }

            noIssues.classList.add('ff-hidden');
            issuesList.innerHTML = data.issues.map(issue => {
                const severity = issue.severity === 'critical' ? 'danger' : 'warning';
                const docLink = issue.documentation
                    ? ` <a href="${toast.escape(issue.documentation)}" target="_blank" class="ff-link ff-text-xs">Pomoc</a>`
                    : '';
                return `<div style="padding: 8px 0; border-bottom: 1px solid var(--ff-border);">
                    <div class="ff-flex-between ff-mb-xs">
                        <span class="ff-badge ff-badge--${severity}">${toast.escape(issue.severity)}</span>
                        ${issue.country ? '<span class="ff-text-xs ff-text-muted">' + toast.escape(issue.country) + '</span>' : ''}
                    </div>
                    <p class="ff-text-sm">${toast.escape(issue.title)}${docLink}</p>
                    ${issue.detail ? '<p class="ff-text-xs ff-text-muted">' + toast.escape(issue.detail) + '</p>' : ''}
                </div>`;
            }).join('');
        }
    };

    // -------------------------------------------------------------------------
    // Page: Products
    // -------------------------------------------------------------------------
    const pageProducts = {
        currentPage: 1,
        selectedIds: new Set(),

        async load(page) {
            if (page) this.currentPage = page;
            const tbody = document.getElementById('ff-products-tbody');
            if (!tbody) return;

            // Hard reset: any load (filter/search/pagination) drops prior selections.
            // Keeps the bulk-bar count in sync with what is actually visible.
            this.selectedIds.clear();
            const allCb = document.getElementById('ff-select-all');
            if (allCb) allCb.checked = false;
            this.updateBulkBar();

            tbody.innerHTML = '<tr><td colspan="8"><div class="ff-empty"><div class="ff-spinner"></div></div></td></tr>';

            try {
                const params = {
                    page: this.currentPage,
                    per_page: 50,
                    status: document.getElementById('ff-products-status-filter')?.value || '',
                    search: document.getElementById('ff-products-search')?.value || '',
                };

                const res = await api.get('/api/products', params);
                const d = res.data;

                if (!d.products || d.products.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="8"><div class="ff-empty">
                        <p class="ff-empty-title">${t('products.empty_title')}</p>
                        <p class="ff-empty-text ff-text-muted">${t('products.empty_description')}</p>
                    </div></td></tr>`;
                    return;
                }

                tbody.innerHTML = d.products.map(p => {
                    const pid = p.id_product || 0;
                    const checked = this.selectedIds.has(pid) ? ' checked' : '';
                    return `<tr>
                        <td><input type="checkbox" class="ff-product-cb" data-id="${pid}"${checked}></td>
                        <td>${pid}</td>
                        <td><a href="${config.apiBaseUrl}/products/${pid}?${config.adminTokenParam}=${encodeURIComponent(config.adminToken)}" class="ff-link">${toast.escape(p.ps_name || '')}</a></td>
                        <td class="ff-text-muted">${toast.escape(p.ps_reference || '\u2014')}</td>
                        <td>${format.statusBadge(p.gmc_status || 'unknown')}</td>
                        <td class="ff-text-sm ff-text-muted">${format.timeAgo(p.last_sync_at)}</td>
                        <td>${p.last_error ? '<span class="ff-badge ff-badge--danger" title="' + toast.escape(p.last_error) + '">!</span>' : '\u2014'}</td>
                        <td class="ff-table-actions">
                            <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.products.syncOne(${pid})">Sync</button>
                        </td>
                    </tr>`;
                }).join('');

                const pagEl = document.getElementById('ff-products-pagination');
                if (pagEl) {
                    pagination.render(pagEl, d.page, d.total_pages, p => this.load(p));
                }

                this.bindCheckboxes();
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="8"><div class="ff-empty">
                    <p class="ff-empty-title">${t('products.load_error_title')}</p>
                    <p class="ff-empty-text ff-text-muted">${toast.escape(e.message)}</p>
                </div></td></tr>`;
            }
        },

        bindCheckboxes() {
            document.querySelectorAll('.ff-product-cb').forEach(cb => {
                cb.addEventListener('change', () => {
                    const id = parseInt(cb.dataset.id);
                    if (cb.checked) this.selectedIds.add(id);
                    else this.selectedIds.delete(id);
                    this.updateBulkBar();
                });
            });
        },

        bindSelectAllOnce() {
            const allCb = document.getElementById('ff-select-all');
            if (!allCb || allCb.dataset.bound === '1') return;
            allCb.dataset.bound = '1';

            allCb.addEventListener('change', () => {
                document.querySelectorAll('.ff-product-cb').forEach(cb => {
                    cb.checked = allCb.checked;
                    const id = parseInt(cb.dataset.id);
                    if (allCb.checked) this.selectedIds.add(id);
                    else this.selectedIds.delete(id);
                });
                this.updateBulkBar();
            });
        },

        updateBulkBar() {
            const bar = document.getElementById('ff-bulk-bar');
            const count = document.getElementById('ff-bulk-count');
            const syncBtn = document.getElementById('ff-products-sync-selected');

            if (this.selectedIds.size > 0) {
                if (bar) bar.classList.remove('ff-hidden');
                if (count) count.textContent = `${this.selectedIds.size} zaznaczono`;
                if (syncBtn) syncBtn.disabled = false;
            } else {
                if (bar) bar.classList.add('ff-hidden');
                if (syncBtn) syncBtn.disabled = true;
            }
        },

        async syncOne(productId) {
            try {
                const res = await api.post('/api/products/sync', { productIds: [productId], type: 'manual' });
                toast.success(res.message || t('products.product_synced'));
                this.load();
            } catch (e) {
                toast.error(t('products.sync_error') + e.message);
            }
        },

        _setBulkBusy(busy) {
            ['ff-bulk-sync', 'ff-bulk-remove', 'ff-bulk-cancel'].forEach(id => {
                const btn = document.getElementById(id);
                if (btn) btn.disabled = busy;
            });
            const syncBtn = document.getElementById('ff-bulk-sync');
            if (syncBtn) {
                if (busy) {
                    syncBtn.dataset.originalText = syncBtn.textContent;
                    syncBtn.innerHTML = '<span class="ff-spinner"></span> ' + (t('common.working') || '...');
                } else if (syncBtn.dataset.originalText) {
                    syncBtn.textContent = syncBtn.dataset.originalText;
                }
            }
        },

        async syncSelected() {
            if (this.selectedIds.size === 0) return;
            this._setBulkBusy(true);
            const ids = Array.from(this.selectedIds);
            try {
                const res = await api.post('/api/products/sync', {
                    productIds: ids,
                    type: 'manual',
                });
                toast.success(res.message || t('products.sync_complete'));
                this.clearSelection();
                this.load();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            } finally {
                this._setBulkBusy(false);
            }
        },

        async removeSelected() {
            if (this.selectedIds.size === 0) return;
            confirm.show(t('products.confirm_remove_selected', {'%count%': this.selectedIds.size}), async () => {
                this._setBulkBusy(true);
                const ids = Array.from(this.selectedIds);
                try {
                    const res = await api.post('/api/products/remove', {
                        productIds: ids,
                    });
                    toast.success(res.message || t('products.delete_queued'));
                    this.clearSelection();
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                } finally {
                    this._setBulkBusy(false);
                }
            });
        },

        clearSelection() {
            this.selectedIds.clear();
            document.querySelectorAll('.ff-product-cb').forEach(cb => cb.checked = false);
            const allCb = document.getElementById('ff-select-all');
            if (allCb) allCb.checked = false;
            this.updateBulkBar();
        }
    };

    // -------------------------------------------------------------------------
    // Page: Product Detail
    // -------------------------------------------------------------------------
    const pageProductDetail = {
        productId: null,

        async load() {
            const resyncBtn = document.getElementById('ff-resync-product');
            this.productId = resyncBtn ? parseInt(resyncBtn.dataset.productId) : null;
            if (!this.productId) return;

            try {
                const res = await api.get(`/api/products/${this.productId}`);
                const d = res.data;

                this.renderGmcData(d.gmcData);
                this.renderStatus(d.product);
                this.renderErrors(d.issues);
                this.renderHistory(d.product);
            } catch (e) {
                toast.error(t('products.load_error') + e.message);
            }
        },

        renderGmcData(gmcData) {
            const el = document.getElementById('ff-product-gmc-data');
            if (!el) return;

            if (!gmcData) {
                el.innerHTML = '<div class="ff-empty"><p class="ff-empty-title">' + t('products.no_gmc_data') + '</p><p class="ff-empty-text ff-text-muted">' + t('products.not_mapped') + '</p></div>';
                return;
            }

            const fields = [
                ['offerId', 'Offer ID'],
                ['title', t('products.gmc_field_title')],
                ['description', t('products.gmc_field_description')],
                ['link', t('products.gmc_field_link')],
                ['imageLink', t('products.gmc_field_image')],
                ['price', t('products.gmc_field_price')],
                ['salePrice', t('products.gmc_field_sale_price')],
                ['availability', t('products.gmc_field_availability')],
                ['condition', t('products.gmc_field_condition')],
                ['brand', t('products.gmc_field_brand')],
                ['gtin', t('products.gmc_field_gtin')],
                ['mpn', t('products.gmc_field_mpn')],
                ['googleProductCategory', t('products.gmc_field_category')],
                ['productType', t('products.gmc_field_type')],
                ['color', t('products.gmc_field_color')],
                ['size', t('products.gmc_field_size')],
                ['gender', t('products.gmc_field_gender')],
                ['ageGroup', t('products.gmc_field_age_group')],
                ['material', t('products.gmc_field_material')],
                ['shippingWeight', t('products.gmc_field_shipping_weight')],
            ];

            let html = '<div class="ff-data-list">';
            fields.forEach(([key, label]) => {
                const val = gmcData[key];
                if (val !== null && val !== undefined && val !== '') {
                    const displayVal = typeof val === 'object' ? JSON.stringify(val) : String(val);
                    html += `
                        <div class="ff-flex-between ff-mb-sm" style="padding: 6px 0; border-bottom: 1px solid var(--ff-border);">
                            <span class="ff-text-sm ff-text-muted" style="min-width: 160px;">${label}</span>
                            <span class="ff-text-sm" style="word-break: break-all; text-align: right;">${toast.escape(displayVal)}</span>
                        </div>`;
                }
            });
            html += '</div>';
            el.innerHTML = html;
        },

        renderStatus(product) {
            const el = document.getElementById('ff-product-status');
            if (!el || !product) {
                if (el) el.innerHTML = '<div class="ff-empty"><p class="ff-empty-text ff-text-muted">' + t('products.no_status_info') + '</p></div>';
                return;
            }

            el.innerHTML = `
                <div class="ff-flex-between ff-mb-sm" style="padding: 8px 0;">
                    <span class="ff-text-sm ff-text-muted">Status GMC</span>
                    ${format.statusBadge(product.gmc_status || 'unknown')}
                </div>
                <div class="ff-flex-between ff-mb-sm" style="padding: 8px 0;">
                    <span class="ff-text-sm ff-text-muted">GMC ID</span>
                    <span class="ff-text-sm ff-font-mono">${toast.escape(product.gmc_id || '\u2014')}</span>
                </div>
                <div class="ff-flex-between ff-mb-sm" style="padding: 8px 0;">
                    <span class="ff-text-sm ff-text-muted">Content Hash</span>
                    <span class="ff-text-sm ff-font-mono">${toast.escape((product.content_hash || '').substring(0, 12))}...</span>
                </div>
                <div class="ff-flex-between" style="padding: 8px 0;">
                    <span class="ff-text-sm ff-text-muted">Ostatni sync</span>
                    <span class="ff-text-sm">${format.date(product.last_sync_at)}</span>
                </div>
            `;
        },

        renderErrors(issues) {
            const el = document.getElementById('ff-product-errors');
            if (!el) return;

            if (!issues || issues.length === 0) {
                el.innerHTML = '<div class="ff-empty"><p class="ff-empty-title">' + t('products.no_errors_title') + '</p><p class="ff-empty-text ff-text-muted">' + t('products.no_errors_description') + '</p></div>';
                return;
            }

            el.innerHTML = issues.map(issue => `
                <div class="ff-alert ff-alert--${issue.severity === 'critical' ? 'danger' : issue.severity === 'warning' ? 'warning' : 'info'} ff-mb-sm">
                    <span>${issue.severity === 'critical' ? '\u26A0' : '\u2139'}</span>
                    <div>
                        <strong>${toast.escape(issue.issue_code || '')}</strong>
                        <p class="ff-text-sm ff-mt-xs">${toast.escape(issue.message || '')}</p>
                        ${issue.detail ? `<p class="ff-text-sm ff-text-muted">${toast.escape(issue.detail)}</p>` : ''}
                    </div>
                </div>
            `).join('');
        },

        renderHistory(product) {
            const el = document.getElementById('ff-product-history');
            if (!el || !product) {
                if (el) el.innerHTML = '<div class="ff-empty"><p class="ff-empty-text ff-text-muted">' + t('products.no_history') + '</p></div>';
                return;
            }

            el.innerHTML = `
                <div class="ff-text-sm ff-text-muted">
                    <p>${t('products.last_sync')} ${format.date(product.last_sync_at)}</p>
                    ${product.last_error ? `<p class="ff-mt-sm" style="color: var(--ff-danger);">${t('products.last_error')} ${toast.escape(product.last_error)}</p>` : ''}
                    <p class="ff-mt-sm ff-text-muted">${t('products.history_help')}</p>
                </div>
            `;
        },

        async resync() {
            if (!this.productId) return;
            const btn = document.getElementById('ff-resync-product');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/products/sync', {
                    productIds: [this.productId],
                    type: 'manual',
                });
                toast.success(res.message || t('products.product_synced'));
                this.load();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        }
    };

    // -------------------------------------------------------------------------
    // Page: Queue
    // -------------------------------------------------------------------------
    const pageQueue = {
        currentPage: 1,
        autoRefreshTimer: null,

        async load(page) {
            if (page) this.currentPage = page;
            const tbody = document.getElementById('ff-queue-tbody');
            if (!tbody) return;

            tbody.innerHTML = '<tr><td colspan="8"><div class="ff-empty"><div class="ff-spinner"></div></div></td></tr>';

            try {
                const params = {
                    page: this.currentPage,
                    per_page: 50,
                    status: document.getElementById('ff-queue-status-filter')?.value || '',
                    action: document.getElementById('ff-queue-action-filter')?.value || '',
                };

                const res = await api.get('/api/queue', params);
                const d = res.data;

                this.renderStats(d.stats);
                this.renderHealthBar(d.stats);
                this.renderItems(tbody, d.items);
                this.scheduleAutoRefresh(d.stats);

                const pagEl = document.getElementById('ff-queue-pagination');
                if (pagEl) {
                    pagination.render(pagEl, d.page, d.total_pages || 1, p => this.load(p));
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="8"><div class="ff-empty">
                    <p class="ff-empty-title">${t('common.error')}</p>
                    <p class="ff-empty-text ff-text-muted">${toast.escape(e.message)}</p>
                </div></td></tr>`;
            }
        },

        renderStats(stats) {
            if (!stats) return;
            const total = (stats.pending || 0) + (stats.processing || 0) + (stats.failed || 0);

            const setText = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = format.number(val);
            };

            setText('ff-queue-stat-total', total);
            setText('ff-queue-stat-pending', stats.pending || 0);
            setText('ff-queue-stat-processing', stats.processing || 0);
            setText('ff-queue-stat-failed', stats.failed || 0);

            const failedAlert = document.getElementById('ff-queue-failed-alert');
            const failedCount = document.getElementById('ff-queue-failed-count');
            if (failedAlert && failedCount) {
                if ((stats.failed || 0) > 0) {
                    failedAlert.classList.remove('ff-hidden');
                    failedCount.textContent = stats.failed;
                } else {
                    failedAlert.classList.add('ff-hidden');
                }
            }
        },

        renderHealthBar(stats) {
            if (!stats) return;
            const pending = stats.pending || 0;
            const processing = stats.processing || 0;
            const failed = stats.failed || 0;
            const total = pending + processing + failed;

            const setWidth = (id, count) => {
                const el = document.getElementById(id);
                if (el) el.style.width = total > 0 ? ((count / total) * 100) + '%' : '0%';
            };

            setWidth('ff-health-pending', pending);
            setWidth('ff-health-processing', processing);
            setWidth('ff-health-failed', failed);

            const legendEl = document.getElementById('ff-queue-health-legend');
            if (legendEl) {
                if (total === 0) {
                    legendEl.innerHTML = '<span class="ff-text-muted">' + t('queue.empty_short') + '</span>';
                } else {
                    legendEl.innerHTML = `
                        <span class="ff-legend--pending">${t('queue.legend_pending', {'%count%': pending})}</span>
                        <span class="ff-legend--processing">${t('queue.legend_processing', {'%count%': processing})}</span>
                        <span class="ff-legend--failed">${t('queue.legend_failed', {'%count%': failed})}</span>
                    `;
                }
            }

            const card = document.getElementById('ff-queue-health-bar-card');
            if (card) card.classList.toggle('ff-hidden', total === 0);
        },

        scheduleAutoRefresh(stats) {
            if (this.autoRefreshTimer) {
                clearTimeout(this.autoRefreshTimer);
                this.autoRefreshTimer = null;
            }

            const processing = stats?.processing || 0;
            if (processing > 0) {
                this.autoRefreshTimer = setTimeout(() => this.load(), 10000);
            }
        },

        renderItems(tbody, items) {
            if (!items || items.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8"><div class="ff-empty">
                    <p class="ff-empty-title">${t('queue.empty_title')}</p>
                    <p class="ff-empty-text ff-text-muted">${t('queue.empty_description')}</p>
                </div></td></tr>`;
                return;
            }

            tbody.innerHTML = items.map(item => {
                const isFailed = item.status === 'failed';
                const queueId = item.id_feedforge_sync_queue || 0;
                let actionsHtml = '\u2014';

                if (isFailed) {
                    actionsHtml = `
                        <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.queue.retryItem(${queueId})"
                            title="${toast.escape(item.last_error || '')}">\u21BB ${t('queue.retry')}</button>
                    `;
                } else if (item.last_error) {
                    actionsHtml = `<span class="ff-text-sm ff-text-muted" title="${toast.escape(item.last_error)}">${t('common.details')}</span>`;
                }

                return `<tr>
                    <td>${item.id_product || '\u2014'}</td>
                    <td class="ff-text-sm">${toast.escape(item.ps_name || t('queue.product_fallback', {'%id%': item.id_product || '?'}))}</td>
                    <td>${format.actionBadge(item.action || 'update')}</td>
                    <td class="ff-text-sm">${item.priority || 5}</td>
                    <td class="ff-text-sm">${item.retries || 0}${item.max_retries ? '/' + item.max_retries : ''}</td>
                    <td class="ff-text-sm ff-text-muted">${format.timeAgo(item.scheduled_at || item.date_add)}</td>
                    <td>${format.statusBadge(item.status || 'pending')}</td>
                    <td class="ff-table-actions">${actionsHtml}</td>
                </tr>`;
            }).join('');
        },

        async retryItem(queueId) {
            try {
                await api.post('/api/queue/retry', { id: queueId });
                toast.success(t('queue.retry_success'));
                this.load();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            }
        },

        async retryAll() {
            const btn = document.getElementById('ff-queue-retry-all');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/queue/retry');
                toast.success(res.message || t('queue.retry_all_success'));
                this.load();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        async clearFailed() {
            confirm.show(t('queue.clear_failed_confirm'), async () => {
                const btn = document.getElementById('ff-queue-clear-failed');
                if (btn) loading.button(btn, true);

                try {
                    const res = await api.post('/api/queue/clear', { type: 'failed' });
                    toast.success(res.message || t('queue.clear_success'));
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                } finally {
                    if (btn) loading.button(btn, false);
                }
            });
        },

        destroy() {
            if (this.autoRefreshTimer) {
                clearTimeout(this.autoRefreshTimer);
                this.autoRefreshTimer = null;
            }
        }
    };

    // -------------------------------------------------------------------------
    // Page: Reports
    // -------------------------------------------------------------------------
    const pageReports = {
        chart: null,
        countriesPopulated: false,

        async load() {
            try {
                const period = document.getElementById('ff-reports-period')?.value || '30d';
                const country = document.getElementById('ff-reports-country')?.value || '';

                const res = await api.get('/api/reports', { period, country });
                const d = res.data;

                this.renderSummary(d.summary);
                this.renderTrends(d.trends);
                this.renderChart(d.timeSeries);
                this.renderByProduct(d.byProduct);
                this.renderByCountry(d.byCountry);
                this.populateCountryDropdown(d.byCountry);
            } catch (e) {
                toast.error(t('reports.load_error') + e.message);
            }
        },

        renderSummary(summary) {
            if (!summary) return;

            const setText = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val;
            };

            setText('ff-report-impressions', format.number(summary.impressions || 0));
            setText('ff-report-clicks', format.number(summary.clicks || 0));
            setText('ff-report-ctr', format.percent(summary.ctr || 0));
        },

        renderTrends(trends) {
            if (!trends) return;

            const renderOne = (id, trend) => {
                const el = document.getElementById(id);
                if (!el || !trend) return;

                const dir = trend.direction || 'flat';
                const val = trend.value || 0;

                if (dir === 'flat' && val === 0) {
                    el.innerHTML = '<span class="ff-text-sm ff-text-muted">\u2014</span>';
                    return;
                }

                const arrow = dir === 'up' ? '\u2191' : dir === 'down' ? '\u2193' : '';
                const color = dir === 'up' ? 'var(--ff-success)' : dir === 'down' ? 'var(--ff-danger)' : 'var(--ff-muted)';

                el.innerHTML = `<span class="ff-text-sm" style="color: ${color}; font-weight: 500;">${arrow} ${val}%</span>`;
            };

            renderOne('ff-report-impressions-trend', trends.impressions);
            renderOne('ff-report-clicks-trend', trends.clicks);
            renderOne('ff-report-ctr-trend', trends.ctr);
        },

        populateCountryDropdown(countries) {
            if (this.countriesPopulated || !countries || countries.length === 0) return;

            const sel = document.getElementById('ff-reports-country');
            if (!sel) return;

            const current = sel.value;
            countries.forEach(c => {
                const code = c.country || '';
                if (code && !sel.querySelector(`option[value="${code}"]`)) {
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = code;
                    sel.appendChild(opt);
                }
            });

            if (current) sel.value = current;
            this.countriesPopulated = true;
        },

        async refreshData() {
            const period = document.getElementById('ff-reports-period')?.value || '30d';
            const days = { '7d': 7, '14d': 14, '30d': 30, '90d': 90 }[period] || 30;

            const btn = document.getElementById('ff-reports-refresh');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/reports/refresh', { days });
                toast.success(res.message || t('reports.data_refreshed'));
                this.countriesPopulated = false;
                await this.load();
            } catch (e) {
                toast.error(t('reports.refresh_error') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        formatDateLabel(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('-');
            if (parts.length === 3) {
                return `${parts[2]}.${parts[1]}`;
            }
            return dateStr;
        },

        renderChart(timeSeries) {
            const canvas = document.getElementById('ff-performance-chart');
            const emptyEl = document.getElementById('ff-chart-empty');
            if (!canvas) return;

            if (!timeSeries || timeSeries.length === 0) {
                canvas.style.display = 'none';
                if (emptyEl) emptyEl.classList.remove('ff-hidden');
                return;
            }

            canvas.style.display = 'block';
            if (emptyEl) emptyEl.classList.add('ff-hidden');

            const labels = timeSeries.map(d => this.formatDateLabel(d.report_date || d.date || ''));
            const impressions = timeSeries.map(d => d.impressions || 0);
            const clicks = timeSeries.map(d => d.clicks || 0);

            if (this.chart) {
                this.chart.destroy();
            }

            if (typeof Chart === 'undefined') return;

            const self = this;
            this.chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: t('reports.chart_impressions'),
                            data: impressions,
                            borderColor: '#635BFF',
                            backgroundColor: 'rgba(99, 91, 255, 0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            yAxisID: 'y',
                        },
                        {
                            label: t('reports.chart_clicks'),
                            data: clicks,
                            borderColor: '#0A2540',
                            backgroundColor: 'rgba(10, 37, 64, 0.05)',
                            fill: false,
                            tension: 0.3,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { font: { family: 'Inter, sans-serif', size: 12 }, usePointStyle: true },
                        },
                        tooltip: {
                            backgroundColor: '#0A2540',
                            titleFont: { family: 'Inter, sans-serif', size: 13 },
                            bodyFont: { family: 'Inter, sans-serif', size: 12 },
                            padding: 10,
                            cornerRadius: 6,
                            callbacks: {
                                label(ctx) {
                                    const val = format.number(ctx.parsed.y);
                                    return ` ${ctx.dataset.label}: ${val}`;
                                }
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { family: 'Inter, sans-serif', size: 11 },
                                maxRotation: 0,
                                autoSkipPadding: 12,
                            },
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: { display: true, text: t('reports.chart_impressions'), font: { family: 'Inter, sans-serif', size: 11 } },
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: {
                                font: { family: 'Inter, sans-serif', size: 11 },
                                callback(val) { return format.number(val); },
                            },
                            beginAtZero: true,
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: { display: true, text: t('reports.chart_clicks'), font: { family: 'Inter, sans-serif', size: 11 } },
                            grid: { drawOnChartArea: false },
                            ticks: {
                                font: { family: 'Inter, sans-serif', size: 11 },
                                callback(val) { return format.number(val); },
                            },
                            beginAtZero: true,
                        },
                    },
                },
            });
        },

        renderByProduct(products) {
            const tbody = document.getElementById('ff-report-by-product');
            if (!tbody) return;

            if (!products || products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="ff-empty"><p class="ff-empty-text ff-text-muted">Brak danych</p></div></td></tr>';
                return;
            }

            tbody.innerHTML = products.map(p => `
                <tr>
                    <td class="ff-text-sm">${toast.escape(p.offer_id || p.title || 'Produkt')}</td>
                    <td class="ff-table-numeric">${format.number(p.impressions || 0)}</td>
                    <td class="ff-table-numeric">${format.number(p.clicks || 0)}</td>
                    <td class="ff-table-numeric">${format.percent(p.ctr || 0)}</td>
                </tr>
            `).join('');
        },

        renderByCountry(countries) {
            const tbody = document.getElementById('ff-report-by-country');
            if (!tbody) return;

            if (!countries || countries.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="ff-empty"><p class="ff-empty-text ff-text-muted">Brak danych</p></div></td></tr>';
                return;
            }

            tbody.innerHTML = countries.map(c => `
                <tr>
                    <td class="ff-text-sm">${toast.escape(c.country || '\u2014')}</td>
                    <td class="ff-table-numeric">${format.number(c.impressions || 0)}</td>
                    <td class="ff-table-numeric">${format.number(c.clicks || 0)}</td>
                    <td class="ff-table-numeric">${format.percent(c.ctr || 0)}</td>
                </tr>
            `).join('');
        }
    };

    // -------------------------------------------------------------------------
    // Modal system
    // -------------------------------------------------------------------------
    const modal = {
        open(containerId, title, bodyHtml, footerHtml) {
            const container = document.getElementById(containerId);
            if (!container) return;

            container.className = 'ff-modal-backdrop';
            container.innerHTML = `
                <div class="ff-modal">
                    <div class="ff-modal-header">
                        <h3 class="ff-modal-title">${title}</h3>
                        <button class="ff-modal-close" data-ff-close>&times;</button>
                    </div>
                    <div class="ff-modal-body">${bodyHtml}</div>
                    <div class="ff-modal-footer">${footerHtml}</div>
                </div>
            `;

            container.querySelector('[data-ff-close]').addEventListener('click', () => this.close(containerId));
            container.addEventListener('click', (e) => {
                if (e.target === container) this.close(containerId);
            });
        },

        close(containerId) {
            const container = document.getElementById(containerId);
            if (container) {
                container.className = 'ff-hidden';
                container.innerHTML = '';
            }
        }
    };

    // -------------------------------------------------------------------------
    // Taxonomy autocomplete
    // -------------------------------------------------------------------------
    const taxonomyAC = {
        _timer: null,

        init(inputId, hiddenPathId, hiddenIdId, lang) {
            const input = document.getElementById(inputId);
            if (!input) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'ff-autocomplete';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);

            let dropdown = null;
            let activeIdx = -1;

            const showDropdown = () => {
                if (!dropdown) {
                    dropdown = document.createElement('div');
                    dropdown.className = 'ff-autocomplete-dropdown';
                    wrapper.appendChild(dropdown);
                }
                dropdown.style.display = 'block';
            };

            const hideDropdown = () => {
                if (dropdown) dropdown.style.display = 'none';
                activeIdx = -1;
            };

            const selectItem = (item) => {
                input.value = item.path || item.name || '';
                const pathEl = document.getElementById(hiddenPathId);
                const idEl = document.getElementById(hiddenIdId);
                if (pathEl) pathEl.value = item.path || item.name || '';
                if (idEl) idEl.value = item.id || item.taxonomy_id || '';
                hideDropdown();
            };

            const search = debounce(async (query) => {
                if (query.length < 2) { hideDropdown(); return; }

                showDropdown();
                dropdown.innerHTML = '<div class="ff-autocomplete-spinner"><span class="ff-spinner"></span></div>';

                try {
                    const res = await api.get('/api/taxonomy/search', { q: query, lang: lang || 'en' });
                    const results = res.data?.results || [];

                    if (results.length === 0) {
                        dropdown.innerHTML = '<div class="ff-autocomplete-empty">' + t('rules.autocomplete_no_results') + '</div>';
                        return;
                    }

                    dropdown.innerHTML = results.map((r, i) => {
                        const path = r.path || r.name || '';
                        const id = r.id || r.taxonomy_id || '';
                        const escapedQ = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        const highlighted = path.replace(
                            new RegExp(`(${escapedQ})`, 'gi'),
                            '<mark>$1</mark>'
                        );
                        const idHighlighted = String(id).replace(
                            new RegExp(`(${escapedQ})`, 'gi'),
                            '<mark>$1</mark>'
                        );
                        return `<div class="ff-autocomplete-item" data-idx="${i}"><span class="ff-text-muted ff-text-sm" style="margin-right:8px;">${idHighlighted}</span>${highlighted}</div>`;
                    }).join('');

                    dropdown.querySelectorAll('.ff-autocomplete-item').forEach((el, i) => {
                        el.addEventListener('click', () => selectItem(results[i]));
                        el.addEventListener('mouseenter', () => {
                            dropdown.querySelectorAll('.ff-autocomplete-item--active').forEach(a => a.classList.remove('ff-autocomplete-item--active'));
                            el.classList.add('ff-autocomplete-item--active');
                            activeIdx = i;
                        });
                    });

                    activeIdx = -1;
                } catch (e) {
                    dropdown.innerHTML = '<div class="ff-autocomplete-empty">' + t('rules.search_error') + '</div>';
                }
            }, 300);

            input.addEventListener('input', () => search(input.value.trim()));

            input.addEventListener('keydown', (e) => {
                if (!dropdown || dropdown.style.display === 'none') return;
                const items = dropdown.querySelectorAll('.ff-autocomplete-item');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = Math.min(activeIdx + 1, items.length - 1);
                    items.forEach((el, i) => el.classList.toggle('ff-autocomplete-item--active', i === activeIdx));
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = Math.max(activeIdx - 1, 0);
                    items.forEach((el, i) => el.classList.toggle('ff-autocomplete-item--active', i === activeIdx));
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0 && items[activeIdx]) items[activeIdx].click();
                } else if (e.key === 'Escape') {
                    hideDropdown();
                }
            });

            document.addEventListener('click', (e) => {
                if (!wrapper.contains(e.target)) hideDropdown();
            });
        }
    };

    // -------------------------------------------------------------------------
    // Condition / Action builder definitions
    // -------------------------------------------------------------------------
    // ── Operator label lookup (lazy) ──
    function getOperatorLabel(op) {
        const labels = {
            eq: t('rules.operator_eq'),
            neq: t('rules.operator_neq'),
            gt: t('rules.operator_gt'),
            gte: t('rules.operator_gte'),
            lt: t('rules.operator_lt'),
            lte: t('rules.operator_lte'),
            contains: t('rules.operator_contains'),
            starts_with: t('rules.operator_starts_with'),
            empty: t('rules.operator_empty'),
            not_empty: t('rules.operator_not_empty'),
            in: t('rules.operator_in'),
            not_in: t('rules.operator_not_in'),
        };
        return labels[op] || op;
    }

    // ── Field definitions with per-field operators ──
    // Each field explicitly lists ONLY the operators that make real-world sense.
    // valueType: 'number' | 'text' | 'select' | 'boolean'
    function getRuleFields() {
        return [
            // --- Numeric: compare amounts ---
            { value: 'price', label: t('rules.field_price'),
              ops: ['eq','neq','gt','gte','lt','lte'], valueType: 'number' },
            { value: 'quantity', label: t('rules.field_quantity'),
              ops: ['eq','neq','gt','gte','lt','lte'], valueType: 'number' },
            { value: 'weight', label: t('rules.field_weight'),
              ops: ['eq','neq','gt','gte','lt','lte'], valueType: 'number' },

            // --- IDs: match specific or list (no empty — always set in PS) ---
            { value: 'id_category_default', label: t('rules.field_category_id'),
              ops: ['eq','neq','in','not_in'], valueType: 'number' },
            { value: 'id_product', label: t('rules.field_product_id'),
              ops: ['eq','neq','in','not_in'], valueType: 'number' },

            // --- Text: can be empty, searchable ---
            { value: 'brand', label: t('rules.field_brand'),
              ops: ['eq','neq','contains','empty','not_empty'], valueType: 'text' },
            { value: 'ean13', label: t('rules.field_ean'),
              ops: ['eq','neq','starts_with','empty','not_empty'], valueType: 'text' },
            { value: 'reference', label: t('rules.field_reference'),
              ops: ['eq','neq','contains','starts_with','empty','not_empty'], valueType: 'text' },

            // --- Selects: fixed set of values → dropdown ---
            { value: 'condition', label: t('rules.field_condition'),
              ops: ['eq','neq'], valueType: 'select', options: [
                { value: 'new', label: t('rules.condition_new') },
                { value: 'used', label: t('rules.condition_used') },
                { value: 'refurbished', label: t('rules.condition_refurbished') },
            ]},
            { value: 'visibility', label: t('rules.field_visibility'),
              ops: ['eq','neq'], valueType: 'select', options: [
                { value: 'both', label: t('rules.visibility_both') },
                { value: 'catalog', label: t('rules.visibility_catalog') },
                { value: 'search', label: t('rules.visibility_search') },
                { value: 'none', label: t('rules.visibility_none') },
            ]},

            // --- Code: short fixed-format string ---
            { value: 'country_code', label: t('rules.field_country'),
              ops: ['eq','neq'], valueType: 'text' },

            // --- Boolean: yes/no only ---
            { value: 'has_combination', label: t('rules.field_combinations'),
              ops: ['eq'], valueType: 'boolean' },
        ];
    }

    /** Get field definition from field value. */
    function getFieldDef(fieldValue) {
        return getRuleFields().find(f => f.value === fieldValue) || { ops: ['eq'], valueType: 'text' };
    }

    /** Return operator objects [{value, label}] allowed for a field. */
    function getOperatorsForField(fieldValue) {
        const def = getFieldDef(fieldValue);
        return (def.ops || ['eq']).map(op => ({ value: op, label: getOperatorLabel(op) }));
    }

    /** Render the value input/select for a condition row. */
    function renderConditionValue(fieldValue, operator, currentValue) {
        if (['empty', 'not_empty'].includes(operator)) return '';

        const def = getFieldDef(fieldValue);

        // Select → dropdown with predefined options
        if (def.valueType === 'select' && def.options) {
            const opts = def.options.map(o =>
                `<option value="${o.value}"${o.value === currentValue ? ' selected' : ''}>${o.label}</option>`
            ).join('');
            return `<select class="ff-select" data-role="value">${opts}</select>`;
        }

        // Boolean → Tak/Nie
        if (def.valueType === 'boolean') {
            return `<select class="ff-select" data-role="value">
                <option value="1"${currentValue === '1' || currentValue === 'true' ? ' selected' : ''}>${t('common.yes')}</option>
                <option value="0"${currentValue === '0' || currentValue === 'false' || !currentValue ? ' selected' : ''}>${t('common.no')}</option>
            </select>`;
        }

        // Number (also for IDs with eq/neq)
        if (def.valueType === 'number' && !['in', 'not_in'].includes(operator)) {
            return `<input class="ff-input" data-role="value" type="number" step="any" value="${toast.escape(String(currentValue ?? ''))}" placeholder="${t('rules.value_placeholder')}">`;
        }

        // in/not_in → text with list placeholder
        if (['in', 'not_in'].includes(operator)) {
            return `<input class="ff-input" data-role="value" value="${toast.escape(String(currentValue ?? ''))}" placeholder="${t('rules.value_list_placeholder')}">`;
        }

        // Default text
        return `<input class="ff-input" data-role="value" value="${toast.escape(String(currentValue ?? ''))}" placeholder="${t('rules.value_placeholder')}">`;
    }

    function getPricingActions() {
        return [
            { value: 'min_price', label: t('rules.action_min_price') },
            { value: 'markup_percent', label: t('rules.action_markup_percent') },
            { value: 'markup_fixed', label: t('rules.action_markup_fixed') },
            { value: 'round_up', label: t('rules.action_round_up') },
            { value: 'round_99', label: t('rules.action_round_99') },
        ];
    }

    // -------------------------------------------------------------------------
    // Condition builder helpers
    // -------------------------------------------------------------------------
    function renderConditionRows(conditions) {
        return (conditions || []).map((c, i) => renderConditionRow(c, i)).join('');
    }

    function renderConditionRow(c, idx) {
        const currentField = c.field || getRuleFields()[0]?.value;
        const fieldOpts = getRuleFields().map(f =>
            `<option value="${f.value}"${f.value === currentField ? ' selected' : ''}>${f.label}</option>`
        ).join('');

        const filteredOps = getOperatorsForField(currentField);
        const opOpts = filteredOps.map(o =>
            `<option value="${o.value}"${o.value === c.operator ? ' selected' : ''}>${o.label}</option>`
        ).join('');

        const valueHtml = renderConditionValue(currentField, c.operator, String(c.value ?? ''));

        return `<div class="ff-condition-row" data-cidx="${idx}">
            <select class="ff-select" data-role="field">${fieldOpts}</select>
            <select class="ff-select" data-role="operator">${opOpts}</select>
            ${valueHtml}
            <button class="ff-condition-remove" data-role="remove" title="${t('common.delete')}">&times;</button>
        </div>`;
    }

    function collectConditions(container) {
        const rows = container.querySelectorAll('.ff-condition-row');
        return Array.from(rows).map(row => {
            const field = row.querySelector('[data-role="field"]').value;
            const operator = row.querySelector('[data-role="operator"]').value;
            const valueInput = row.querySelector('[data-role="value"]');
            return { field, operator, value: valueInput ? valueInput.value : '' };
        });
    }

    function renderActionFields(type, actions) {
        actions = actions || {};

        if (type === 'exclusion') {
            return `<div class="ff-alert ff-alert--info"><span>\u2139</span><span>${t('rules.exclusion_info')}</span></div>`;
        }

        if (type === 'pricing') {
            const actionOpts = getPricingActions().map(a =>
                `<option value="${a.value}"${a.value === actions.action ? ' selected' : ''}>${a.label}</option>`
            ).join('');
            return `
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.pricing_action_label')}</label>
                    <select class="ff-select" id="ff-rule-action-type">${actionOpts}</select>
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('common.value')}</label>
                    <input class="ff-input" id="ff-rule-action-value" type="number" step="0.01" value="${actions.value || ''}" placeholder="np. 10">
                    <p class="ff-help-text">${t('rules.pricing_help')}</p>
                </div>`;
        }

        if (type === 'custom_label') {
            const idxOpts = [0,1,2,3,4].map(i =>
                `<option value="${i}"${i === (actions.label_index ?? 0) ? ' selected' : ''}>Custom Label ${i}</option>`
            ).join('');
            return `
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.label_number')}</label>
                    <select class="ff-select" id="ff-rule-label-index">${idxOpts}</select>
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.label_value')}</label>
                    <input class="ff-input" id="ff-rule-label-value" value="${toast.escape(actions.value || '')}" placeholder="${t('rules.label_value_placeholder')}">
                    <p class="ff-help-text">${t('rules.label_help')}</p>
                </div>`;
        }

        if (type === 'identifier') {
            const idFields = [
                { value: 'gtin', label: 'GTIN (EAN/UPC)' },
                { value: 'mpn', label: 'MPN' },
                { value: 'brand', label: t('rules.id_field_brand') },
                { value: 'identifier_exists', label: t('rules.id_field_identifier_exists') },
            ];
            const fieldOpts = idFields.map(f =>
                `<option value="${f.value}"${f.value === actions.field ? ' selected' : ''}>${f.label}</option>`
            ).join('');

            const isIdExists = (actions.field === 'identifier_exists');

            const sourceOpts = ['fixed', 'context'].map(s =>
                `<option value="${s}"${s === actions.source ? ' selected' : ''}>${s === 'fixed' ? t('rules.source_fixed') : t('rules.source_product_field')}</option>`
            ).join('');

            const boolVal = actions.value === '0' || actions.value === 'false' || actions.value === '' ? '0' : '1';

            return `
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.identifier_target_field')}</label>
                    <select class="ff-select" id="ff-rule-id-field">${fieldOpts}</select>
                </div>
                <div id="ff-id-source-group" class="ff-form-group" style="${isIdExists ? 'display:none' : ''}">
                    <label class="ff-label">${t('rules.identifier_source')}</label>
                    <select class="ff-select" id="ff-rule-id-source">${sourceOpts}</select>
                </div>
                <div id="ff-id-value-group" class="ff-form-group" style="${isIdExists ? 'display:none' : ''}">
                    <label class="ff-label">${t('common.value')}</label>
                    <input class="ff-input" id="ff-rule-id-value" value="${toast.escape(actions.value || '')}" placeholder="${t('common.value')}">
                    <p class="ff-help-text">${t('rules.identifier_help')}</p>
                </div>
                <div id="ff-id-bool-group" class="ff-form-group" style="${isIdExists ? '' : 'display:none'}">
                    <label class="ff-label">${t('common.value')}</label>
                    <select class="ff-select" id="ff-rule-id-bool">
                        <option value="0"${boolVal === '0' ? ' selected' : ''}>${t('common.no')}</option>
                        <option value="1"${boolVal === '1' ? ' selected' : ''}>${t('common.yes')}</option>
                    </select>
                    <p class="ff-help-text">${t('rules.id_exists_help')}</p>
                </div>`;
        }

        return '';
    }

    function collectActions(type) {
        if (type === 'exclusion') return {};

        if (type === 'pricing') {
            return {
                action: document.getElementById('ff-rule-action-type')?.value || '',
                value: parseFloat(document.getElementById('ff-rule-action-value')?.value || '0'),
            };
        }

        if (type === 'custom_label') {
            return {
                label_index: parseInt(document.getElementById('ff-rule-label-index')?.value || '0'),
                value: document.getElementById('ff-rule-label-value')?.value || '',
            };
        }

        if (type === 'identifier') {
            const field = document.getElementById('ff-rule-id-field')?.value || '';
            if (field === 'identifier_exists') {
                return {
                    field,
                    source: 'fixed',
                    value: document.getElementById('ff-rule-id-bool')?.value || '0',
                };
            }
            return {
                field,
                source: document.getElementById('ff-rule-id-source')?.value || 'fixed',
                value: document.getElementById('ff-rule-id-value')?.value || '',
            };
        }

        return {};
    }

    // -------------------------------------------------------------------------
    // Page: Rules
    // -------------------------------------------------------------------------
    const pageRules = {
        allRules: {},
        categories: [],

        async load() {
            await Promise.all([
                this.loadCategories(),
                this.loadRules('exclusion', 'ff-exclusion-rules'),
                this.loadRules('pricing', 'ff-pricing-rules'),
                this.loadRules('custom_label', 'ff-label-rules'),
                this.loadRules('identifier', 'ff-identifier-rules'),
            ]);
        },

        async loadCategories() {
            const tbody = document.getElementById('ff-categories-tbody');
            if (!tbody) return;

            try {
                const res = await api.get('/api/categories');
                this.categories = res.data?.categories || [];

                if (this.categories.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="5"><div class="ff-empty">
                        <p class="ff-empty-title">${t('rules.no_category_maps')}</p>
                        <p class="ff-empty-text ff-text-muted">${t('rules.add_category_map_help')}</p>
                    </div></td></tr>`;
                    return;
                }

                tbody.innerHTML = this.categories.map(cat => `
                    <tr>
                        <td>${toast.escape(cat.ps_category_name || t('rules.category_hash') + cat.id_category)}</td>
                        <td class="ff-text-sm">${toast.escape(cat.google_taxonomy_path || '\u2014')}</td>
                        <td class="ff-text-sm ff-text-muted">\u2014</td>
                        <td>${cat.active == 1 ? '<span class="ff-badge ff-badge--success">' + t('common.yes') + '</span>' : '<span class="ff-badge ff-badge--neutral">' + t('common.no') + '</span>'}</td>
                        <td class="ff-table-actions">
                            <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.rules.editCategory(${cat.id_feedforge_category_map || 0})">${t('common.edit')}</button>
                            <button class="ff-btn ff-btn--ghost ff-btn--sm" style="color: var(--ff-danger);" onclick="FeedForge.pages.rules.deleteCategory(${cat.id_feedforge_category_map || 0})">${t('common.delete')}</button>
                        </td>
                    </tr>
                `).join('');
            } catch (e) {
                toast.error(t('rules.load_categories_error') + e.message);
            }
        },

        async loadRules(type, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            try {
                const res = await api.get('/api/rules', { type });
                const rules = res.data?.rules || [];
                this.allRules[type] = rules;

                if (rules.length === 0) return; // Keep default empty state

                // Preserve any info alerts at the top
                const alerts = container.querySelectorAll('.ff-alert');
                const alertsHtml = Array.from(alerts).map(a => a.outerHTML).join('');

                container.innerHTML = alertsHtml + rules.map(rule => this.renderRuleCard(rule)).join('');
            } catch (e) {
                // Silent fail - keep default empty
            }
        },

        renderRuleCard(rule) {
            const condCount = (rule.conditions || []).length;
            const typeLabels = { exclusion: t('rules.type_exclusion'), pricing: t('rules.type_pricing'), custom_label: t('rules.type_custom_label'), identifier: t('rules.type_identifier') };
            const typeLabel = typeLabels[rule.type] || rule.type;

            let actionSummary = '';
            const acts = rule.actions || {};
            if (rule.type === 'pricing' && acts.action) {
                const pa = getPricingActions().find(a => a.value === acts.action);
                actionSummary = ` \u00B7 ${pa ? pa.label : acts.action}: ${acts.value || ''}`;
            } else if (rule.type === 'custom_label') {
                actionSummary = ` \u00B7 Label ${acts.label_index ?? 0}: ${acts.value || ''}`;
            } else if (rule.type === 'identifier') {
                actionSummary = ` \u00B7 ${(acts.field || '').toUpperCase()}: ${acts.value || ''}`;
            }

            return `<div class="ff-rule-card ff-mb-sm" style="padding: 12px; border: 1px solid var(--ff-border); border-radius: 8px;">
                <div class="ff-flex-between">
                    <div>
                        <strong class="ff-text-sm">${toast.escape(rule.name || t('rules.rule_hash') + rule.id_feedforge_rule)}</strong>
                        <span class="ff-badge ff-badge--${rule.active == 1 ? 'success' : 'neutral'} ff-ml-sm">${rule.active == 1 ? t('common.active') : t('common.inactive')}</span>
                    </div>
                    <div>
                        <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.rules.editRule(${rule.id_feedforge_rule}, '${rule.type}')">${t('common.edit')}</button>
                        <button class="ff-btn ff-btn--ghost ff-btn--sm" style="color: var(--ff-danger);" onclick="FeedForge.pages.rules.deleteRule(${rule.id_feedforge_rule})">${t('common.delete')}</button>
                    </div>
                </div>
                <div class="ff-text-sm ff-text-muted ff-mt-xs">
                    ${condCount} ${t('rules.conditions_plural')} \u00B7 ${t('rules.priority')} ${rule.priority || 0}${actionSummary}
                </div>
            </div>`;
        },

        // --- Category mapping modal ---

        openCategoryModal(existingCat) {
            const isEdit = !!existingCat;
            const cat = existingCat || {};

            // For add mode we allow a comma-separated list of category IDs so the user can
            // map several PS categories to the same Google category in one go.
            const idsHelp = isEdit
                ? (cat.ps_category_name || '')
                : (t('rules.category_id_multi_help') || 'Tip: enter several IDs separated by commas, e.g. 3, 5, 12');

            const body = `
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.category_ps_id_label')}</label>
                    <input class="ff-input" id="ff-cat-ps-id" type="text" inputmode="numeric" value="${cat.id_category || ''}" placeholder="${isEdit ? t('rules.category_id_placeholder') : (t('rules.category_id_multi_placeholder') || 'e.g. 3, 5, 12')}" ${isEdit ? 'readonly' : ''}>
                    <p class="ff-help-text">${toast.escape(idsHelp)}</p>
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.category_google_label')}</label>
                    <input class="ff-input" id="ff-cat-taxonomy-search" value="${toast.escape(cat.google_taxonomy_path || '')}" placeholder="${t('rules.category_search_placeholder')}">
                    <input type="hidden" id="ff-cat-taxonomy-path" value="${toast.escape(cat.google_taxonomy_path || '')}">
                    <input type="hidden" id="ff-cat-taxonomy-id" value="${cat.google_taxonomy_id || ''}">
                </div>
                <div class="ff-form-group">
                    <div class="ff-flex-between">
                        <label class="ff-label" style="margin:0;">${t('common.active')}</label>
                        <label class="ff-switch">
                            <input type="checkbox" id="ff-cat-active" ${(cat.active == 1 || !isEdit) ? 'checked' : ''}>
                            <span class="ff-switch-slider"></span>
                        </label>
                    </div>
                </div>
            `;

            const footer = `
                <button class="ff-btn ff-btn--secondary" onclick="FeedForge.modal.close('ff-modal-category')">${t('common.cancel')}</button>
                <button class="ff-btn ff-btn--primary" id="ff-cat-save">${isEdit ? t('common.save') : t('rules.add_category_map')}</button>
            `;

            modal.open('ff-modal-category', isEdit ? t('rules.edit_category_map') : t('rules.new_category_map'), body, footer);

            // Init taxonomy autocomplete
            taxonomyAC.init('ff-cat-taxonomy-search', 'ff-cat-taxonomy-path', 'ff-cat-taxonomy-id', 'pl');

            // Save handler — supports a single ID (edit mode) or a comma-separated list (add mode).
            document.getElementById('ff-cat-save').addEventListener('click', async () => {
                const rawIds = document.getElementById('ff-cat-ps-id').value;
                const taxonomyPath = document.getElementById('ff-cat-taxonomy-path').value;
                const taxonomyId = document.getElementById('ff-cat-taxonomy-id').value;
                const active = document.getElementById('ff-cat-active').checked ? 1 : 0;

                // Parse the IDs field: accept "3", "3,5,12", "3, 5, 12" — same logic for edit and add.
                const ids = rawIds
                    .split(',')
                    .map(s => s.trim())
                    .filter(s => s.length > 0)
                    .map(s => parseInt(s, 10))
                    .filter(n => !isNaN(n) && n > 0);

                if (ids.length === 0) { toast.error(t('rules.category_id_required')); return; }
                if (!taxonomyPath) { toast.error(t('rules.category_google_required')); return; }

                try {
                    let saved = 0;
                    let failed = [];
                    for (const idCategory of ids) {
                        try {
                            await api.post('/api/categories/save', {
                                id: isEdit ? (cat.id_feedforge_category_map || undefined) : undefined,
                                id_category: idCategory,
                                google_taxonomy_id: parseInt(taxonomyId) || 0,
                                google_taxonomy_path: taxonomyPath,
                                active,
                            });
                            saved++;
                        } catch (e) {
                            failed.push(`#${idCategory}: ${e.message}`);
                        }
                    }

                    if (saved > 0 && failed.length === 0) {
                        toast.success(saved === 1
                            ? t('rules.category_map_saved')
                            : (t('rules.category_map_saved_multi') || `Zapisano ${saved} mapowań`).replace('%count%', saved)
                        );
                    } else if (saved > 0) {
                        toast.success(`Zapisano ${saved}, błędy: ${failed.length}`);
                        failed.forEach(msg => toast.error(msg));
                    } else {
                        failed.forEach(msg => toast.error(msg));
                    }

                    modal.close('ff-modal-category');
                    this.loadCategories();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        },

        editCategory(id) {
            const cat = this.categories.find(c => (c.id_feedforge_category_map || 0) == id);
            if (cat) this.openCategoryModal(cat);
        },

        deleteCategory(id) {
            confirm.show(t('rules.delete_category_confirm'), () => {
                api.post('/api/categories/save', { id, active: 0 })
                    .then(() => { toast.success(t('rules.category_map_deleted')); this.loadCategories(); })
                    .catch(e => toast.error(t('common.error_prefix') + e.message));
            });
        },

        // --- Rule editor modal ---

        openRuleModal(type, existingRule) {
            const isEdit = !!existingRule;
            const rule = existingRule || { type, conditions: [], actions: {}, active: 1, priority: 0, name: '' };
            const typeLabels = { exclusion: t('rules.type_exclusion_desc'), pricing: t('rules.type_pricing_desc'), custom_label: t('rules.type_custom_label_desc'), identifier: t('rules.type_identifier_desc') };

            const body = `
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.rule_name_label')}</label>
                    <input class="ff-input" id="ff-rule-name" value="${toast.escape(rule.name || '')}" placeholder="${t('rules.rule_name_placeholder')}">
                </div>
                <div class="ff-form-group">
                    <div class="ff-flex-between">
                        <label class="ff-label" style="margin:0;">${t('common.active')}</label>
                        <label class="ff-switch">
                            <input type="checkbox" id="ff-rule-active" ${rule.active == 1 ? 'checked' : ''}>
                            <span class="ff-switch-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('rules.priority')}</label>
                    <input class="ff-input" id="ff-rule-priority" type="number" value="${rule.priority || 0}" min="0" max="100" style="width:100px;">
                    <p class="ff-help-text">${t('rules.priority_help')}</p>
                </div>

                <p class="ff-section-label">${t('rules.conditions_section')}</p>
                <div id="ff-rule-conditions">${renderConditionRows(rule.conditions)}</div>
                <button class="ff-add-condition" id="ff-rule-add-condition">+ ${t('rules.add_condition')}</button>

                <p class="ff-section-label">${t('rules.action_section')}</p>
                <div class="ff-action-config" id="ff-rule-actions">${renderActionFields(type, rule.actions)}</div>
            `;

            const footer = `
                <button class="ff-btn ff-btn--secondary" onclick="FeedForge.modal.close('ff-modal-rule')">${t('common.cancel')}</button>
                <button class="ff-btn ff-btn--primary" id="ff-rule-save">${isEdit ? t('common.save') : t('rules.create_rule')}</button>
            `;

            modal.open('ff-modal-rule', isEdit ? t('rules.edit_rule') : t('rules.new_rule') + ' ' + (typeLabels[type] || ''), body, footer);

            // Bind add condition
            document.getElementById('ff-rule-add-condition').addEventListener('click', () => {
                const container = document.getElementById('ff-rule-conditions');
                const idx = container.querySelectorAll('.ff-condition-row').length;
                const div = document.createElement('div');
                div.innerHTML = renderConditionRow({ field: 'price', operator: 'gt', value: '' }, idx);
                container.appendChild(div.firstElementChild);
                this.bindConditionEvents(container);
            });

            this.bindConditionEvents(document.getElementById('ff-rule-conditions'));

            // Toggle identifier_exists UI: show Yes/No select instead of source+value
            const idFieldSel = document.getElementById('ff-rule-id-field');
            if (idFieldSel) {
                idFieldSel.addEventListener('change', () => {
                    const isIdExists = idFieldSel.value === 'identifier_exists';
                    const srcGroup = document.getElementById('ff-id-source-group');
                    const valGroup = document.getElementById('ff-id-value-group');
                    const boolGroup = document.getElementById('ff-id-bool-group');
                    if (srcGroup) srcGroup.style.display = isIdExists ? 'none' : '';
                    if (valGroup) valGroup.style.display = isIdExists ? 'none' : '';
                    if (boolGroup) boolGroup.style.display = isIdExists ? '' : 'none';
                });
            }

            // Save handler
            document.getElementById('ff-rule-save').addEventListener('click', async () => {
                const name = document.getElementById('ff-rule-name').value.trim();
                const active = document.getElementById('ff-rule-active').checked ? 1 : 0;
                const priority = parseInt(document.getElementById('ff-rule-priority').value) || 0;
                const conditions = collectConditions(document.getElementById('ff-rule-conditions'));
                const actions = collectActions(type);

                if (!name) { toast.error(t('rules.name_required')); return; }

                try {
                    await api.post('/api/rules/save', {
                        id: isEdit ? (rule.id_feedforge_rule || undefined) : undefined,
                        type,
                        name,
                        active,
                        priority,
                        conditions,
                        actions,
                    });
                    toast.success(t('rules.rule_saved'));
                    modal.close('ff-modal-rule');
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        },

        bindConditionEvents(container) {
            const self = this;
            container.querySelectorAll('[data-role="remove"]').forEach(btn => {
                btn.onclick = () => { btn.closest('.ff-condition-row').remove(); };
            });

            // Helper: rebuild value element in a row
            function rebuildValue(row) {
                const fieldVal = row.querySelector('[data-role="field"]').value;
                const opVal = row.querySelector('[data-role="operator"]').value;
                const oldValEl = row.querySelector('[data-role="value"]');
                const oldValue = oldValEl ? oldValEl.value : '';
                if (oldValEl) oldValEl.remove();

                const valueHtml = renderConditionValue(fieldVal, opVal, oldValue);
                if (valueHtml) {
                    const removeBtn = row.querySelector('[data-role="remove"]');
                    const tmp = document.createElement('div');
                    tmp.innerHTML = valueHtml;
                    removeBtn.before(tmp.firstElementChild);
                }
            }

            // When field changes → rebuild operators + value
            container.querySelectorAll('[data-role="field"]').forEach(fieldSel => {
                fieldSel.onchange = () => {
                    const row = fieldSel.closest('.ff-condition-row');
                    const opSel = row.querySelector('[data-role="operator"]');
                    const ops = getOperatorsForField(fieldSel.value);
                    const prevOp = opSel.value;
                    opSel.innerHTML = ops.map(o =>
                        `<option value="${o.value}"${o.value === prevOp ? ' selected' : ''}>${o.label}</option>`
                    ).join('');
                    if (!ops.find(o => o.value === prevOp)) {
                        opSel.value = ops[0]?.value || 'eq';
                    }
                    rebuildValue(row);
                };
            });

            // When operator changes → rebuild value (type may change, or hide for empty/not_empty)
            container.querySelectorAll('[data-role="operator"]').forEach(sel => {
                sel.onchange = () => {
                    rebuildValue(sel.closest('.ff-condition-row'));
                };
            });
        },

        editRule(id, type) {
            const rules = this.allRules[type] || [];
            const rule = rules.find(r => r.id_feedforge_rule == id);
            if (rule) this.openRuleModal(type || rule.type, rule);
        },

        async deleteRule(id) {
            confirm.show(t('rules.delete_rule_confirm'), async () => {
                try {
                    await api.post('/api/rules/delete', { id });
                    toast.success(t('rules.rule_deleted'));
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        }
    };

    // -------------------------------------------------------------------------
    // Page: Configuration
    // -------------------------------------------------------------------------
    const pageConfig = {
        async load() {
            try {
                const [configRes, feedsRes, attrRes] = await Promise.all([
                    api.get('/api/config'),
                    api.get('/api/feeds'),
                    api.get('/api/attribute-maps'),
                ]);

                this.renderConnection(configRes.data?.connection);
                this.populateConfig(configRes.data?.config);
                this.renderFeeds(feedsRes.data?.feeds || []);
                this.renderAttributeMaps(attrRes.data?.attributeMaps || []);
                // DataSources require an active connection — load lazily after the rest renders.
                if (configRes.data?.connection?.connected) {
                    this.loadDataSources();
                }
            } catch (e) {
                toast.error(t('config.load_error') + e.message);
            }
        },

        async loadFeeds() {
            try {
                const res = await api.get('/api/feeds');
                this.renderFeeds(res.data?.feeds || []);
            } catch (e) {
                toast.error(t('config.load_error') + e.message);
            }
        },

        renderConnection(conn) {
            const disconnectedEl = document.getElementById('ff-oauth-disconnected');
            const connectedEl = document.getElementById('ff-oauth-connected');
            if (!disconnectedEl || !connectedEl) return;

            if (conn?.connected) {
                disconnectedEl.classList.add('ff-hidden');
                connectedEl.classList.remove('ff-hidden');

                const emailEl = document.getElementById('ff-oauth-email');
                const merchantEl = document.getElementById('ff-oauth-merchant-id');
                if (emailEl) emailEl.textContent = conn.email || '';
                if (merchantEl) merchantEl.textContent = conn.merchantId
                    ? (conn.merchantId + (conn.merchantName ? ' (' + conn.merchantName + ')' : ''))
                    : (t('config.no_merchant_id') || 'not set');

                // If we're connected but no Merchant ID set yet, show the input field.
                const selector = document.getElementById('ff-merchant-selector');
                const changeRow = document.getElementById('ff-merchant-change-row');
                const registerRow = document.getElementById('ff-register-gcp-row');
                const registeredRow = document.getElementById('ff-gcp-registered-row');

                if (!conn.merchantId) {
                    if (selector) selector.classList.remove('ff-hidden');
                    if (changeRow) changeRow.classList.add('ff-hidden');
                    if (registerRow) registerRow.classList.add('ff-hidden');
                    if (registeredRow) registeredRow.classList.add('ff-hidden');
                } else {
                    if (selector) selector.classList.add('ff-hidden');
                    if (changeRow) changeRow.classList.remove('ff-hidden');

                    if (conn.gcpRegistered) {
                        // Already registered — show steady-state status, hide the form.
                        if (registerRow) registerRow.classList.add('ff-hidden');
                        if (registeredRow) {
                            registeredRow.classList.remove('ff-hidden');
                            const emailEl = document.getElementById('ff-gcp-registered-email');
                            if (emailEl) emailEl.textContent = conn.email || '\u2014';
                        }
                    } else {
                        // Not yet registered — show the form so user can complete it.
                        if (registerRow) registerRow.classList.remove('ff-hidden');
                        if (registeredRow) registeredRow.classList.add('ff-hidden');
                        const regEmailInput = document.getElementById('ff-register-gcp-email');
                        if (regEmailInput && !regEmailInput.value && conn.email) {
                            regEmailInput.value = conn.email;
                        }
                    }
                }
            } else {
                disconnectedEl.classList.remove('ff-hidden');
                connectedEl.classList.add('ff-hidden');
            }
        },

        async registerGcpDeveloper() {
            const input = document.getElementById('ff-register-gcp-email');
            const trimmed = (input?.value || '').trim();
            if (trimmed === '') {
                toast.error(t('config.register_gcp_email_required') || 'Email is required');
                if (input) input.focus();
                return;
            }

            const btn = document.getElementById('ff-register-gcp');
            if (btn) loading.button(btn, true);
            try {
                const res = await api.post('/api/google-accounts/register-developer', { developerEmail: trimmed });
                if (res.success) {
                    toast.success(res.message || 'GCP project registered');
                    // Reload the connection block so the registered status row appears.
                    await this.load();
                } else {
                    toast.error(res.message || 'Registration failed');
                }
            } catch (e) {
                toast.error(e.message || 'Registration failed');
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        async saveMerchantId(merchantId) {
            const id = (merchantId || '').toString().trim();
            if (!id || !/^\d+$/.test(id)) {
                toast.error(t('config.merchant_invalid') || 'Merchant ID must be numeric');
                return;
            }

            try {
                const res = await api.post('/api/google-accounts/select', { merchantId: id });
                if (res.success) {
                    toast.success(res.message || (t('config.merchant_saved') || 'Merchant ID saved'));
                    // Reload the connection block so the new ID shows up.
                    await this.load();
                } else {
                    toast.error(res.message || (t('config.merchant_save_error') || 'Could not save Merchant ID'));
                }
            } catch (e) {
                toast.error(e.message || 'Error');
            }
        },

        populateConfig(cfg) {
            if (!cfg) return;

            const setVal = (id, val) => {
                const el = document.getElementById(id);
                if (!el) return;
                if (el.type === 'checkbox') el.checked = !!val;
                else el.value = val ?? '';
            };

            setVal('ff-config-client-id', cfg.google_client_id);
            setVal('ff-config-client-secret', cfg.google_client_secret);
            setVal('ff-config-batch-size', cfg.batch_size);
            setVal('ff-config-max-retries', cfg.max_retries);
            setVal('ff-config-sync-interval', cfg.sync_interval);
            setVal('ff-config-max-execution', cfg.max_execution_time);
            setVal('ff-config-delta-sync', cfg.delta_sync_enabled);
            setVal('ff-config-auto-remove', cfg.auto_remove_deleted);
            setVal('ff-config-debug-logging', cfg.debug_logging);

            // Update credentials status indicator
            const statusEl = document.getElementById('ff-credentials-status');
            if (statusEl) {
                statusEl.textContent = cfg.google_client_id ? '✓ ' + t('config.data_saved_indicator') : '';
                statusEl.style.color = 'var(--ff-success)';
            }

            // Store cron token
            this._cronToken = cfg.cron_token || '';
        },

        async saveConfig() {
            const btns = document.querySelectorAll('.ff-save-config-btn');
            btns.forEach(b => loading.button(b, true));

            const getVal = (id) => {
                const el = document.getElementById(id);
                if (!el) return undefined;
                if (el.type === 'checkbox') return el.checked ? '1' : '0';
                return el.value;
            };

            try {
                await api.post('/api/config/save', {
                    google_client_id: getVal('ff-config-client-id'),
                    google_client_secret: getVal('ff-config-client-secret'),
                    batch_size: getVal('ff-config-batch-size'),
                    max_retries: getVal('ff-config-max-retries'),
                    sync_interval: getVal('ff-config-sync-interval'),
                    max_execution_time: getVal('ff-config-max-execution'),
                    delta_sync_enabled: getVal('ff-config-delta-sync'),
                    auto_remove_deleted: getVal('ff-config-auto-remove'),
                    debug_logging: getVal('ff-config-debug-logging'),
                });
                toast.success(t('config.settings_saved'));
            } catch (e) {
                toast.error(t('config.save_error') + e.message);
            } finally {
                btns.forEach(b => loading.button(b, false));
            }
        },

        async saveCredentials() {
            const btn = document.getElementById('ff-save-credentials');
            if (btn) loading.button(btn, true);

            const clientId = document.getElementById('ff-config-client-id')?.value || '';
            const clientSecret = document.getElementById('ff-config-client-secret')?.value || '';

            if (!clientId.trim()) {
                toast.error(t('config.client_id_required'));
                if (btn) loading.button(btn, false);
                return;
            }

            try {
                await api.post('/api/config/save', {
                    google_client_id: clientId,
                    google_client_secret: clientSecret,
                });

                const statusEl = document.getElementById('ff-credentials-status');
                if (statusEl) {
                    statusEl.textContent = '\u2713 ' + t('config.data_saved_indicator');
                    statusEl.style.color = 'var(--ff-success)';
                }

                toast.success(t('config.api_data_saved'));
            } catch (e) {
                toast.error(t('config.save_error') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        async disconnect() {
            confirm.show(t('config.disconnect_confirm'), async () => {
                try {
                    await api.post('/oauth/disconnect');
                    toast.success(t('config.account_disconnected'));
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        },

        renderFeeds(feeds) {
            const tbody = document.getElementById('ff-feeds-tbody');
            if (!tbody) return;

            if (feeds.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7"><div class="ff-empty">
                    <p class="ff-empty-text ff-text-muted">${t('config.no_feeds')}</p>
                </div></td></tr>`;
                return;
            }

            tbody.innerHTML = feeds.map(f => {
                const editUrl = config.apiBaseUrl + '/feeds/edit/' + (f.id_feedforge_feed_config || 0) + '?' + config.adminTokenParam + '=' + encodeURIComponent(config.adminToken);
                const dataSourceCell = f.data_source_id
                    ? '<span class="ff-badge ff-badge--success">' + t('common.yes') + '</span>'
                    : '<span class="ff-badge ff-badge--warning">' + t('config.ds_missing') + '</span>';
                return `<tr>
                    <td>${toast.escape(f.country_code || '\u2014')}</td>
                    <td>${toast.escape(f.language_code || '\u2014')}</td>
                    <td>${toast.escape(f.currency_code || '\u2014')}</td>
                    <td class="ff-text-sm ff-text-muted">${toast.escape(f.offer_id_prefix || '\u2014')}</td>
                    <td>${dataSourceCell}</td>
                    <td>${f.active == 1 ? '<span class="ff-badge ff-badge--success">' + t('common.yes') + '</span>' : '<span class="ff-badge ff-badge--neutral">' + t('common.no') + '</span>'}</td>
                    <td class="ff-table-actions">
                        <a class="ff-btn ff-btn--ghost ff-btn--sm" href="${editUrl}">${t('common.edit')}</a>
                        <button class="ff-btn ff-btn--ghost ff-btn--sm" style="color: var(--ff-danger);" onclick="FeedForge.pages.configuration.deleteFeed(${f.id_feedforge_feed_config || 0})">${t('common.delete')}</button>
                    </td>
                </tr>`;
            }).join('');

            this._feeds = feeds;
        },

        async loadDataSources() {
            const tbody = document.getElementById('ff-data-sources-tbody');
            if (!tbody) return;

            try {
                const res = await api.get('/api/data-sources');
                if (!res.success) {
                    tbody.innerHTML = `<tr><td colspan="4"><div class="ff-empty">
                        <p class="ff-empty-text ff-text-muted">${toast.escape(res.message || t('config.ds_load_error'))}</p>
                    </div></td></tr>`;
                    return;
                }

                const dataSources = res.data?.dataSources || [];
                if (dataSources.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="4"><div class="ff-empty">
                        <p class="ff-empty-text ff-text-muted">${t('config.ds_none')}</p>
                    </div></td></tr>`;
                    return;
                }

                tbody.innerHTML = dataSources.map(ds => `<tr>
                    <td>${toast.escape(ds.displayName || '\u2014')}</td>
                    <td><code>${toast.escape(ds.feedLabel || '\u2014')}</code></td>
                    <td><code>${toast.escape(ds.contentLanguage || '\u2014')}</code></td>
                    <td>${ds.isBound
                        ? '<span class="ff-badge ff-badge--success">' + t('common.yes') + '</span>'
                        : '<span class="ff-badge ff-badge--neutral">' + t('common.no') + '</span>'}</td>
                </tr>`).join('');
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="4"><div class="ff-empty">
                    <p class="ff-empty-text ff-text-muted">${toast.escape(e.message || t('config.ds_load_error'))}</p>
                </div></td></tr>`;
            }
        },

        async provisionDataSources() {
            const btn = document.getElementById('ff-data-sources-provision');
            if (btn) loading.button(btn, true);
            try {
                const res = await api.post('/api/data-sources/provision', {});
                if (res.success) {
                    toast.success(res.message || t('config.ds_provisioned'));
                    if ((res.data?.errors || []).length > 0) {
                        res.data.errors.forEach(err => toast.error(err));
                    }
                    await this.loadDataSources();
                    await this.loadFeeds();
                } else {
                    toast.error(res.message || t('config.ds_provision_error'));
                }
            } catch (e) {
                toast.error(e.message || t('config.ds_provision_error'));
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        renderAttributeMaps(maps) {
            const tbody = document.getElementById('ff-attribute-maps-tbody');
            if (!tbody) return;

            if (maps.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4"><div class="ff-empty">
                    <p class="ff-empty-text ff-text-muted">${t('config.no_attribute_maps')}</p>
                </div></td></tr>`;
                return;
            }

            tbody.innerHTML = maps.map(m => `
                <tr>
                    <td class="ff-text-sm"><code>${toast.escape(m.gmc_field || '')}</code></td>
                    <td class="ff-text-sm">${toast.escape(m.ps_source_type || '')} \u2192 ${toast.escape(m.ps_source_name || 'ID:' + (m.ps_source_id || ''))}</td>
                    <td>${m.active == 1 ? '<span class="ff-badge ff-badge--success">' + t('common.yes') + '</span>' : '<span class="ff-badge ff-badge--neutral">' + t('common.no') + '</span>'}</td>
                    <td class="ff-table-actions">
                        <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.configuration.editAttributeMap(${m.id_feedforge_attribute_map || 0})">${t('common.edit')}</button>
                        <button class="ff-btn ff-btn--ghost ff-btn--sm" style="color: var(--ff-danger);" onclick="FeedForge.pages.configuration.deleteAttributeMap(${m.id_feedforge_attribute_map || 0})">${t('common.delete')}</button>
                    </td>
                </tr>
            `).join('');

            this._attributeMaps = maps;
        },

        showCronToken() {
            const tokenEl = document.getElementById('ff-cron-token');
            const displayEl = document.getElementById('ff-cron-token-display');
            if (tokenEl) tokenEl.textContent = this._cronToken || '***';
            if (displayEl) displayEl.textContent = this._cronToken || '***';
        },

        openAttrMapModal(existingMap) {
            const isEdit = !!existingMap;
            const m = existingMap || {};

            const gmcFields = ['color', 'size', 'gender', 'ageGroup', 'material', 'pattern', 'sizeType', 'sizeSystem', 'itemGroupId', 'customLabel0', 'customLabel1', 'customLabel2', 'customLabel3', 'customLabel4'];
            const gmcOpts = gmcFields.map(f =>
                `<option value="${f}"${f === m.gmc_field ? ' selected' : ''}>${f}</option>`
            ).join('');

            const body = `
                <div class="ff-form-group">
                    <label class="ff-label">${t('config.gmc_field_label')}</label>
                    <select class="ff-select" id="ff-attr-gmc-field">${gmcOpts}</select>
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('config.attribute_source_type')}</label>
                    <select class="ff-select" id="ff-attr-source-type">
                        <option value="feature"${m.ps_source_type === 'feature' ? ' selected' : ''}>${t('config.source_type_feature')}</option>
                        <option value="attribute"${m.ps_source_type === 'attribute' ? ' selected' : ''}>${t('config.source_type_attribute')}</option>
                        <option value="field"${m.ps_source_type === 'field' ? ' selected' : ''}>${t('config.source_type_field')}</option>
                    </select>
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('config.attribute_source_id')}</label>
                    <input class="ff-input" id="ff-attr-source-id" type="number" value="${m.ps_source_id || ''}" placeholder="${t('config.attribute_source_id_placeholder')}">
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('config.attribute_source_name')}</label>
                    <input class="ff-input" id="ff-attr-source-name" value="${toast.escape(m.ps_source_name || '')}" placeholder="${t('config.attribute_source_name_placeholder')}">
                </div>
                <div class="ff-form-group">
                    <label class="ff-label">${t('config.attribute_transform')}</label>
                    <input class="ff-input" id="ff-attr-transform" value="${toast.escape(m.transform || '')}" placeholder="${t('config.attribute_transform_placeholder')}">
                    <p class="ff-help-text">${t('config.transform_options')}</p>
                </div>
                <div class="ff-form-group">
                    <div class="ff-flex-between">
                        <label class="ff-label" style="margin:0;">${t('common.active')}</label>
                        <label class="ff-switch">
                            <input type="checkbox" id="ff-attr-active" ${(m.active == 1 || !isEdit) ? 'checked' : ''}>
                            <span class="ff-switch-slider"></span>
                        </label>
                    </div>
                </div>
            `;

            const footer = `
                <button class="ff-btn ff-btn--secondary" onclick="FeedForge.modal.close('ff-modal-attr-map')">${t('common.cancel')}</button>
                <button class="ff-btn ff-btn--primary" id="ff-attr-save">${isEdit ? t('common.save') : t('config.add_attribute_map')}</button>
            `;

            modal.open('ff-modal-attr-map', isEdit ? t('config.edit_attribute_map') : t('config.new_attribute_map'), body, footer);

            document.getElementById('ff-attr-save').addEventListener('click', async () => {
                const gmcField = document.getElementById('ff-attr-gmc-field').value;
                const sourceType = document.getElementById('ff-attr-source-type').value;
                const sourceId = parseInt(document.getElementById('ff-attr-source-id').value) || 0;

                if (!gmcField) { toast.error(t('config.gmc_field_required')); return; }

                try {
                    await api.post('/api/attribute-maps/save', {
                        id: isEdit ? (m.id_feedforge_attribute_map || undefined) : undefined,
                        gmc_field: gmcField,
                        ps_source_type: sourceType,
                        ps_source_id: sourceId,
                        ps_source_name: document.getElementById('ff-attr-source-name').value,
                        transform: document.getElementById('ff-attr-transform').value || null,
                        active: document.getElementById('ff-attr-active').checked ? 1 : 0,
                    });
                    toast.success(t('config.attribute_map_saved'));
                    modal.close('ff-modal-attr-map');
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        },

        editAttributeMap(id) {
            const map = (this._attributeMaps || []).find(m => m.id_feedforge_attribute_map == id);
            this.openAttrMapModal(map || null);
        },

        deleteFeed(id) {
            confirm.show(t('config.delete_feed_confirm'), async () => {
                try {
                    await api.post('/api/feeds/delete', { id });
                    toast.success(t('config.feed_deleted'));
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        },

        deleteAttributeMap(id) {
            confirm.show(t('config.delete_attribute_map_confirm'), async () => {
                try {
                    await api.post('/api/attribute-maps/save', { id, active: 0 });
                    toast.success(t('config.attribute_map_deleted'));
                    this.load();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        },

        // Shipping settings
        async loadShippingPreview() {
            const loadingEl = document.getElementById('ff-shipping-loading');
            const emptyEl = document.getElementById('ff-shipping-empty');
            const previewEl = document.getElementById('ff-shipping-preview');
            const skippedEl = document.getElementById('ff-shipping-skipped');
            if (!loadingEl) return;

            loadingEl.classList.remove('ff-hidden');
            if (emptyEl) emptyEl.classList.add('ff-hidden');
            if (previewEl) previewEl.classList.add('ff-hidden');
            if (skippedEl) skippedEl.classList.add('ff-hidden');

            try {
                const res = await api.get('/api/shipping/preview');
                const d = res.data;

                loadingEl.classList.add('ff-hidden');

                if (!d.services || d.services.length === 0) {
                    if (emptyEl) emptyEl.classList.remove('ff-hidden');
                    return;
                }

                if (previewEl) previewEl.classList.remove('ff-hidden');

                const summaryEl = document.getElementById('ff-shipping-summary');
                if (summaryEl) {
                    summaryEl.innerHTML = `
                        <span class="ff-text-sm ff-text-muted">${d.summary.carriers} ${t('config.shipping_carriers')}, ${d.summary.countries} ${t('config.shipping_countries')}</span>
                        <span class="ff-badge ff-badge--info">${d.services.length} ${t('config.shipping_services')}</span>
                    `;
                }

                const tbody = document.getElementById('ff-shipping-tbody');
                if (tbody) {
                    tbody.innerHTML = d.services.map(svc => {
                        const rateType = svc.isFree ? t('config.shipping_free') :
                            (svc.rateGroups || []).map(g => g.name).join(', ') || '\u2014';
                        return `<tr>
                            <td>${toast.escape(svc.name)}</td>
                            <td><span class="ff-badge ff-badge--neutral">${toast.escape(svc.deliveryCountry)}</span></td>
                            <td class="ff-text-sm">${toast.escape(rateType)}</td>
                            <td>${svc.isFree ? '<span class="ff-badge ff-badge--success">' + t('common.yes') + '</span>' : t('common.no')}</td>
                            <td class="ff-text-sm ff-text-muted">${toast.escape(svc.deliveryTimeLabel || '\u2014')}</td>
                        </tr>`;
                    }).join('');
                }

                if (d.summary.skipped && d.summary.skipped.length > 0 && skippedEl) {
                    skippedEl.classList.remove('ff-hidden');
                    const listEl = document.getElementById('ff-shipping-skipped-list');
                    if (listEl) {
                        listEl.innerHTML = d.summary.skipped.map(s =>
                            `<p class="ff-text-xs ff-text-muted">\u2022 ${toast.escape(s.name)}: ${toast.escape(s.reason)}</p>`
                        ).join('');
                    }
                }

                this.loadShippingCurrent();
            } catch (e) {
                loadingEl.classList.add('ff-hidden');
                if (emptyEl) {
                    emptyEl.classList.remove('ff-hidden');
                    emptyEl.querySelector('.ff-empty-title').textContent = t('common.error');
                    emptyEl.querySelector('.ff-empty-text').textContent = e.message;
                }
            }
        },

        async loadShippingCurrent() {
            const googleEl = document.getElementById('ff-shipping-google');
            const infoEl = document.getElementById('ff-shipping-google-info');
            if (!googleEl || !infoEl) return;

            try {
                const res = await api.get('/api/shipping/current');
                if (!res.data || !res.data.services || res.data.services.length === 0) {
                    infoEl.innerHTML = '<p class="ff-text-sm ff-text-muted">' + t('config.shipping_no_google_settings') + '</p>';
                } else {
                    infoEl.innerHTML = `<p class="ff-text-sm ff-text-muted">${res.data.services.length} ${t('config.shipping_google_configured')}</p>`;
                }
                googleEl.classList.remove('ff-hidden');
            } catch (_) {
                // Not connected or error - silently ignore
            }
        },

        async pushShipping() {
            const btn = document.getElementById('ff-shipping-push');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/shipping/push');
                if (res.success) {
                    toast.success(res.message || t('config.sent_to_google'));
                } else {
                    toast.error(res.message || t('common.error'));
                }
                this.loadShippingPreview();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        // Promotions
        async loadPromotions() {
            const loadingEl = document.getElementById('ff-promotions-loading');
            const emptyEl = document.getElementById('ff-promotions-empty');
            const tableEl = document.getElementById('ff-promotions-table');
            if (!loadingEl) return;

            loadingEl.classList.remove('ff-hidden');
            if (emptyEl) emptyEl.classList.add('ff-hidden');
            if (tableEl) tableEl.classList.add('ff-hidden');

            try {
                const res = await api.get('/api/promotions');
                const promos = res.data?.promotions || [];

                loadingEl.classList.add('ff-hidden');

                if (promos.length === 0) {
                    if (emptyEl) emptyEl.classList.remove('ff-hidden');
                    return;
                }

                if (tableEl) tableEl.classList.remove('ff-hidden');

                const tbody = document.getElementById('ff-promotions-tbody');
                if (tbody) {
                    tbody.innerHTML = promos.map(p => {
                        const id = p.id_feedforge_promotion;
                        const statusClass = {
                            'active': 'success', 'pending': 'warning',
                            'rejected': 'danger', 'ended': 'neutral', 'unknown': 'neutral'
                        }[p.gmc_status] || 'neutral';

                        return `<tr>
                            <td>${toast.escape(p.promotion_title)}</td>
                            <td><span class="ff-badge ff-badge--neutral">${toast.escape(p.coupon_value_type)}</span></td>
                            <td>${p.discount_value ? toast.escape(String(p.discount_value)) + (p.coupon_value_type === 'PERCENT_OFF' ? '%' : ' ' + (p.discount_currency || '')) : '\u2014'}</td>
                            <td class="ff-text-sm">${toast.escape(p.coupon_code || '\u2014')}</td>
                            <td><span class="ff-badge ff-badge--${statusClass}">${toast.escape(p.gmc_status)}</span></td>
                            <td class="ff-text-sm ff-text-muted">${format.timeAgo(p.last_sync_at)}</td>
                            <td class="ff-table-actions">
                                <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.configuration.syncPromotion(${id})">Sync</button>
                                <button class="ff-btn ff-btn--ghost ff-btn--sm" onclick="FeedForge.pages.configuration.deletePromotion(${id})" style="color: var(--ff-danger);">${t('common.delete')}</button>
                            </td>
                        </tr>`;
                    }).join('');
                }
            } catch (e) {
                loadingEl.classList.add('ff-hidden');
                if (emptyEl) {
                    emptyEl.classList.remove('ff-hidden');
                    const title = emptyEl.querySelector('.ff-empty-title');
                    const text = emptyEl.querySelector('.ff-empty-text');
                    if (title) title.textContent = t('common.error');
                    if (text) text.textContent = e.message;
                }
            }
        },

        async scanCartRules() {
            const btn = document.getElementById('ff-promotions-scan');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.get('/api/promotions/scan');
                const rules = res.data?.cartRules || [];

                const resultsEl = document.getElementById('ff-promotions-scan-results');
                const tbody = document.getElementById('ff-promotions-scan-tbody');

                if (!resultsEl || !tbody) return;

                if (rules.length === 0) {
                    toast.info(t('config.no_cart_rules_found'));
                    resultsEl.classList.add('ff-hidden');
                    return;
                }

                resultsEl.classList.remove('ff-hidden');
                tbody.innerHTML = rules.map(r => {
                    const dateFrom = r.date_from ? format.date(r.date_from) : '\u2014';
                    const dateTo = r.date_to ? format.date(r.date_to) : '\u2014';
                    return `<tr>
                        <td>${toast.escape(r.name)}</td>
                        <td><span class="ff-badge ff-badge--neutral">${toast.escape(r.suggested_value_type)}</span></td>
                        <td class="ff-text-sm">${toast.escape(r.code || t('config.no_code'))}</td>
                        <td class="ff-text-sm ff-text-muted">${dateFrom} \u2013 ${dateTo}</td>
                        <td class="ff-table-actions">
                            <button class="ff-btn ff-btn--primary ff-btn--sm" onclick="FeedForge.pages.configuration.mapCartRule(${r.id_cart_rule})">${t('config.map_btn')}</button>
                        </td>
                    </tr>`;
                }).join('');
            } catch (e) {
                toast.error(t('config.scan_error') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        async mapCartRule(cartRuleId) {
            try {
                await api.post('/api/promotions/save', { id_cart_rule: cartRuleId });
                toast.success(t('config.promotion_mapped'));
                this.loadPromotions();
                // Hide scan results
                const resultsEl = document.getElementById('ff-promotions-scan-results');
                if (resultsEl) resultsEl.classList.add('ff-hidden');
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            }
        },

        async syncPromotion(id) {
            try {
                const res = await api.post('/api/promotions/sync', { id });
                if (res.success) {
                    toast.success(res.message || t('config.sent_to_google'));
                } else {
                    toast.error(res.message || t('common.error'));
                }
                this.loadPromotions();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            }
        },

        async syncAllPromotions() {
            const btn = document.getElementById('ff-promotions-sync-all');
            if (btn) loading.button(btn, true);

            try {
                const res = await api.post('/api/promotions/sync', {});
                if (res.success !== undefined) {
                    toast.success(t('config.sync_result', { success: res.success || 0, failed: res.failed || 0 }));
                } else {
                    toast.success(res.message || t('products.sync_complete'));
                }
                this.loadPromotions();
            } catch (e) {
                toast.error(t('common.error_prefix') + e.message);
            } finally {
                if (btn) loading.button(btn, false);
            }
        },

        deletePromotion(id) {
            confirm.show(t('config.delete_promotion_confirm'), async () => {
                try {
                    await api.post('/api/promotions/delete', { id });
                    toast.success(t('config.promotion_deleted'));
                    this.loadPromotions();
                } catch (e) {
                    toast.error(t('common.error_prefix') + e.message);
                }
            });
        }
    };

    // -------------------------------------------------------------------------
    // Feed Edit page
    // -------------------------------------------------------------------------
    const pageFeedEdit = {
        _feedId: 0,
        _feedData: null,
        _psData: {},
        _countTimer: null,
        _categoryLoadedParents: new Set(),

        async load() {
            this._feedId = parseInt(document.getElementById('ff-feed-id')?.value || '0') || 0;

            // Build back-to-config URL
            const configUrl = config.apiBaseUrl + '/config?' + config.adminTokenParam + '=' + encodeURIComponent(config.adminToken);
            document.querySelectorAll('#ff-back-to-config, #ff-back-to-config-bottom').forEach(el => {
                el.href = configUrl;
            });

            // Load PS reference data in parallel
            const [mfr, sup, tags, features] = await Promise.all([
                api.get('/api/ps/manufacturers').catch(() => ({ data: { manufacturers: [] } })),
                api.get('/api/ps/suppliers').catch(() => ({ data: { suppliers: [] } })),
                api.get('/api/ps/tags').catch(() => ({ data: { tags: [] } })),
                api.get('/api/ps/features').catch(() => ({ data: { features: [] } })),
            ]);

            this._psData.manufacturers = mfr.data?.manufacturers || [];
            this._psData.suppliers = sup.data?.suppliers || [];
            this._psData.tags = tags.data?.tags || [];
            this._psData.features = features.data?.features || [];

            // Render checkbox lists
            this.renderCheckboxList('ff-filter-manufacturers', this._psData.manufacturers, 'id_manufacturer', 'name');
            this.renderCheckboxList('ff-filter-suppliers', this._psData.suppliers, 'id_supplier', 'name');
            this.initTagPicker();

            // Load category tree (root level)
            await this.loadCategoryChildren(0, document.getElementById('ff-filter-categories'));

            // If editing existing feed, populate form
            if (this._feedId > 0) {
                await this.loadExistingFeed();
            }

            // Initial count
            this.updateCount();

            // Attach change listeners for live count
            this.attachFilterListeners();

            // Init vertical tab switching
            this.initFilterTabs();
        },

        initFilterTabs() {
            document.querySelectorAll('.ff-vtab[data-tab]').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.ff-vtab').forEach(t => t.classList.remove('ff-vtab--active'));
                    document.querySelectorAll('.ff-vtab-panel').forEach(p => p.classList.remove('ff-vtab-panel--active'));
                    tab.classList.add('ff-vtab--active');
                    const panel = document.querySelector('.ff-vtab-panel[data-panel="' + tab.dataset.tab + '"]');
                    if (panel) panel.classList.add('ff-vtab-panel--active');
                });
            });
        },

        updateFilterHints() {
            const filters = this.collectFilters();
            const h = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text || '';
            };

            const catCount = filters.categories?.length || 0;
            h('ff-hint-categories', catCount ? (filters.categories_mode === 'exclude' ? '\u2212' + catCount : catCount) : '');
            const mfrCount = filters.manufacturers?.length || 0;
            h('ff-hint-manufacturers', mfrCount ? (filters.manufacturers_mode === 'exclude' ? '\u2212' + mfrCount : mfrCount) : '');
            const supCount = filters.suppliers?.length || 0;
            h('ff-hint-suppliers', supCount ? (filters.suppliers_mode === 'exclude' ? '\u2212' + supCount : supCount) : '');
            const tagIncCount = filters.tags_include?.length || 0;
            const tagExcCount = filters.tags_exclude?.length || 0;
            const tagTotal = tagIncCount + tagExcCount;
            h('ff-hint-tags', tagTotal ? (tagExcCount > 0 && tagIncCount === 0 ? '\u2212' + tagExcCount : tagTotal) : '');
            const featCount = filters.features?.length || 0;
            h('ff-hint-features', featCount ? (filters.features_mode === 'exclude' ? '\u2212' + featCount : featCount) : '');
            h('ff-hint-excluded', filters.excluded_products?.length || '');

            // Price ranges
            const priceCount = filters.price_ranges?.length || 0;
            h('ff-hint-price', priceCount ? (filters.price_mode === 'exclude' ? '\u2212' + priceCount : priceCount) : '');

            // Stock
            const stockCount = (filters.skip_out_of_stock ? 1 : 0) + (filters.min_stock > 0 ? 1 : 0);
            h('ff-hint-stock', stockCount || '');

            // Requirements
            const reqCount = (filters.require_ean ? 1 : 0) + (filters.require_image ? 1 : 0);
            h('ff-hint-requirements', reqCount || '');

            // Condition (only if non-default / filter active)
            h('ff-hint-condition', filters.condition?.length || '');

            // Visibility
            h('ff-hint-visibility', filters.visibility?.length || '');
        },

        async loadExistingFeed() {
            try {
                const res = await api.get('/api/feeds');
                const feeds = res.data?.feeds || [];
                const feed = feeds.find(f => f.id_feedforge_feed_config == this._feedId);
                if (!feed) {
                    toast.error(t('feed_edit.feed_not_found'));
                    return;
                }
                this._feedData = feed;

                // Populate basic fields
                document.getElementById('ff-feed-country').value = feed.country_code || '';
                document.getElementById('ff-feed-lang').value = feed.language_code || '';
                document.getElementById('ff-feed-currency').value = feed.currency_code || '';
                document.getElementById('ff-feed-desc-source').value = feed.description_source || 'description_short';
                document.getElementById('ff-feed-title-tpl').value = feed.title_template || '{name}';
                const offerPrefixInput = document.getElementById('ff-feed-offer-prefix');
                if (offerPrefixInput) offerPrefixInput.value = feed.offer_id_prefix || '';
                document.getElementById('ff-feed-tax').checked = feed.include_tax == 1;
                document.getElementById('ff-feed-active').checked = feed.active == 1;

                // Populate filters
                let filters = {};
                if (feed.filters) {
                    filters = typeof feed.filters === 'string' ? JSON.parse(feed.filters) : feed.filters;
                }
                this.applyFiltersToUI(filters);
            } catch (e) {
                toast.error(t('feed_edit.load_error') + e.message);
            }
        },

        applyFiltersToUI(filters) {
            if (!filters || typeof filters !== 'object') return;

            // Categories mode
            if (filters.categories_mode === 'exclude') {
                const modeSelect = document.getElementById('ff-filter-categories-mode');
                if (modeSelect) modeSelect.value = 'exclude';
            }

            // Categories
            if (filters.categories?.length) {
                filters.categories.forEach(id => {
                    const cb = document.querySelector('#ff-filter-categories input[data-id="' + id + '"]');
                    if (cb) cb.checked = true;
                });
            }

            // Manufacturers
            if (filters.manufacturers_mode === 'exclude') {
                const mfrModeSelect = document.getElementById('ff-filter-manufacturers-mode');
                if (mfrModeSelect) mfrModeSelect.value = 'exclude';
            }
            if (filters.manufacturers?.length) {
                filters.manufacturers.forEach(id => {
                    const cb = document.querySelector('#ff-filter-manufacturers input[data-id="' + id + '"]');
                    if (cb) cb.checked = true;
                });
            }

            // Price ranges
            if (filters.price_mode === 'exclude') {
                const priceModeSelect = document.getElementById('ff-filter-price-mode');
                if (priceModeSelect) priceModeSelect.value = 'exclude';
            }
            if (filters.price_ranges?.length) {
                filters.price_ranges.forEach(r => this.addPriceRangeRow(r.min ?? null, r.max ?? null));
            }

            // Stock
            if (filters.skip_out_of_stock) {
                document.getElementById('ff-filter-skip-out-of-stock').checked = true;
                const minStockGroup = document.getElementById('ff-min-stock-group');
                if (minStockGroup) minStockGroup.classList.remove('ff-hidden');
            }
            if (filters.min_stock > 0) {
                document.getElementById('ff-filter-min-stock').value = filters.min_stock;
            }

            // Require EAN / image
            if (filters.require_ean) document.getElementById('ff-filter-require-ean').checked = true;
            if (filters.require_image) document.getElementById('ff-filter-require-image').checked = true;

            // Condition
            if (filters.condition?.length) {
                document.querySelectorAll('#ff-filter-condition input').forEach(cb => {
                    cb.checked = filters.condition.includes(cb.value);
                });
            }

            // Visibility
            if (filters.visibility?.length) {
                document.querySelectorAll('#ff-filter-visibility input').forEach(cb => {
                    cb.checked = filters.visibility.includes(cb.value);
                });
            }

            // Suppliers
            if (filters.suppliers_mode === 'exclude') {
                const supModeSelect = document.getElementById('ff-filter-suppliers-mode');
                if (supModeSelect) supModeSelect.value = 'exclude';
            }
            if (filters.suppliers?.length) {
                filters.suppliers.forEach(id => {
                    const cb = document.querySelector('#ff-filter-suppliers input[data-id="' + id + '"]');
                    if (cb) cb.checked = true;
                });
            }

            // Tags (include + exclude chips)
            const tagLookup = {};
            (this._psData.tags || []).forEach(t => { tagLookup[t.id_tag] = t.name; });
            if (filters.tags_include?.length) {
                filters.tags_include.forEach(id => {
                    const name = tagLookup[id] || 'Tag #' + id;
                    this.addTagChip(id, name, 'include');
                });
            }
            if (filters.tags_exclude?.length) {
                filters.tags_exclude.forEach(id => {
                    const name = tagLookup[id] || 'Tag #' + id;
                    this.addTagChip(id, name, 'exclude');
                });
            }

            // Features
            if (filters.features_mode === 'exclude') {
                const featModeSelect = document.getElementById('ff-filter-features-mode');
                if (featModeSelect) featModeSelect.value = 'exclude';
            }
            if (filters.features?.length) {
                filters.features.forEach(f => this.addFeatureRow(f.id_feature, f.id_feature_value));
            }

            // Excluded products (restore as chips)
            if (filters.excluded_products?.length) {
                filters.excluded_products.forEach(id => {
                    this._addExcludedChip(id, t('feed_edit.product_hash') + id);
                    // Fetch real name asynchronously
                    api.get('/api/ps/product-lookup', { id }).then(res => {
                        if (res.success) {
                            const chip = document.querySelector('#ff-excluded-chips .ff-excluded-chip[data-id="' + id + '"] .ff-excluded-chip-name');
                            if (chip) chip.textContent = res.data.name;
                        }
                    }).catch(() => {});
                });
            }
        },

        renderCheckboxList(containerId, items, idKey, nameKey) {
            const container = document.getElementById(containerId);
            if (!container) return;
            if (items.length === 0) {
                container.innerHTML = '<span class="ff-text-sm ff-text-muted">' + t('feed_edit.no_items') + '</span>';
                return;
            }
            container.innerHTML = items.map(item =>
                '<label class="ff-checkbox-label">' +
                    '<input type="checkbox" data-id="' + item[idKey] + '"> ' +
                    toast.escape(item[nameKey]) +
                '</label>'
            ).join('');
        },

        async loadCategoryChildren(parentId, container) {
            if (this._categoryLoadedParents.has(parentId)) return;
            this._categoryLoadedParents.add(parentId);

            try {
                const res = await api.get('/api/ps/categories', { parent_id: parentId });
                const cats = res.data?.categories || [];
                if (cats.length === 0 && parentId === 0) {
                    container.innerHTML = '<span class="ff-text-sm ff-text-muted">' + t('feed_edit.no_categories') + '</span>';
                    return;
                }

                let html = '';
                for (const cat of cats) {
                    const hasChildren = parseInt(cat.children_count) > 0;
                    html += '<div class="ff-tree-node" data-cat-id="' + cat.id_category + '">' +
                        '<span class="ff-tree-toggle">' + (hasChildren ? '\u25B6' : '&nbsp;') + '</span>' +
                        '<label class="ff-tree-label">' +
                            '<input type="checkbox" data-id="' + cat.id_category + '"> ' +
                            toast.escape(cat.name) +
                            ' <span class="ff-tree-count">(' + (cat.product_count || 0) + ')</span>' +
                        '</label>' +
                        (hasChildren ? '<div class="ff-tree-children ff-hidden"></div>' : '') +
                    '</div>';
                }

                if (parentId === 0) {
                    container.innerHTML = html;
                } else {
                    const childContainer = container.querySelector('.ff-tree-node[data-cat-id="' + parentId + '"] > .ff-tree-children');
                    if (childContainer) {
                        childContainer.innerHTML = html;
                        childContainer.classList.remove('ff-hidden');
                    }
                }

                // Attach toggle handlers
                const toggles = container.querySelectorAll('.ff-tree-toggle');
                toggles.forEach(toggle => {
                    if (toggle._ffBound) return;
                    toggle._ffBound = true;
                    toggle.addEventListener('click', async (e) => {
                        const node = e.target.closest('.ff-tree-node');
                        const catId = parseInt(node.dataset.catId);
                        const children = node.querySelector('.ff-tree-children');
                        if (!children) return;

                        if (children.classList.contains('ff-hidden')) {
                            toggle.textContent = '\u23F3';
                            await this.loadCategoryChildren(catId, container);
                            children.classList.remove('ff-hidden');
                            toggle.textContent = '\u25BC';
                        } else {
                            children.classList.add('ff-hidden');
                            toggle.textContent = '\u25B6';
                        }
                    });
                });

                // Attach checkbox change for live count
                container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    if (cb._ffBound) return;
                    cb._ffBound = true;
                    cb.addEventListener('change', () => this.scheduleCount());
                });
            } catch (e) {
                if (parentId === 0) {
                    container.innerHTML = '<span class="ff-text-sm" style="color: var(--ff-danger-text);">' + t('feed_edit.categories_load_error') + '</span>';
                }
            }
        },

        initTagPicker() {
            document.querySelectorAll('[data-tag-mode]').forEach(input => {
                const mode = input.dataset.tagMode;
                const wrap = input.closest('.ff-tag-search-wrap');
                const dropdown = wrap.querySelector('.ff-tag-dropdown');

                let debounceTimer;
                input.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => this._showTagDropdown(input, dropdown, mode), 150);
                });
                input.addEventListener('focus', () => {
                    if (input.value.trim().length > 0) this._showTagDropdown(input, dropdown, mode);
                });
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const active = dropdown.querySelector('.ff-tag-dropdown-item--active') || dropdown.querySelector('.ff-tag-dropdown-item');
                        if (active) {
                            this.addTagChip(parseInt(active.dataset.id), active.dataset.name, mode);
                            input.value = '';
                            dropdown.classList.add('ff-hidden');
                            this.scheduleCount();
                        }
                    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        const items = [...dropdown.querySelectorAll('.ff-tag-dropdown-item')];
                        if (!items.length) return;
                        const cur = dropdown.querySelector('.ff-tag-dropdown-item--active');
                        let idx = cur ? items.indexOf(cur) : -1;
                        if (cur) cur.classList.remove('ff-tag-dropdown-item--active');
                        idx = e.key === 'ArrowDown' ? Math.min(idx + 1, items.length - 1) : Math.max(idx - 1, 0);
                        items[idx].classList.add('ff-tag-dropdown-item--active');
                        items[idx].scrollIntoView({ block: 'nearest' });
                    } else if (e.key === 'Escape') {
                        dropdown.classList.add('ff-hidden');
                    }
                });
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.ff-tag-search-wrap')) {
                    document.querySelectorAll('.ff-tag-dropdown').forEach(d => d.classList.add('ff-hidden'));
                }
            });
        },

        _showTagDropdown(input, dropdown, mode) {
            const query = input.value.trim().toLowerCase();
            if (query.length === 0) { dropdown.classList.add('ff-hidden'); return; }

            const allTags = this._psData.tags || [];
            const usedIds = new Set();
            document.querySelectorAll('#ff-tags-include .ff-tag-chip, #ff-tags-exclude .ff-tag-chip').forEach(c => usedIds.add(parseInt(c.dataset.id)));

            const matches = allTags.filter(t => !usedIds.has(parseInt(t.id_tag)) && t.name.toLowerCase().includes(query)).slice(0, 15);

            if (!matches.length) {
                dropdown.innerHTML = '<div class="ff-tag-dropdown-empty">' + t('feed_edit.no_results') + '</div>';
            } else {
                dropdown.innerHTML = matches.map(t =>
                    '<div class="ff-tag-dropdown-item" data-id="' + t.id_tag + '" data-name="' + toast.escape(t.name) + '">' + toast.escape(t.name) + '</div>'
                ).join('');

                dropdown.querySelectorAll('.ff-tag-dropdown-item').forEach(item => {
                    item.addEventListener('click', () => {
                        this.addTagChip(parseInt(item.dataset.id), item.dataset.name, mode);
                        input.value = '';
                        dropdown.classList.add('ff-hidden');
                        this.scheduleCount();
                    });
                });
            }
            dropdown.classList.remove('ff-hidden');
        },

        addTagChip(id, name, mode) {
            const containerId = mode === 'exclude' ? 'ff-tags-exclude' : 'ff-tags-include';
            const container = document.getElementById(containerId);
            if (!container) return;

            // Avoid duplicates
            if (container.querySelector('.ff-tag-chip[data-id="' + id + '"]')) return;

            const chip = document.createElement('span');
            chip.className = 'ff-tag-chip ff-tag-chip--' + mode;
            chip.dataset.id = id;
            chip.innerHTML = toast.escape(name) + '<span class="ff-tag-chip-remove" title="' + t('common.delete') + '">\u00D7</span>';
            chip.querySelector('.ff-tag-chip-remove').addEventListener('click', () => {
                chip.remove();
                this.scheduleCount();
            });
            container.appendChild(chip);
        },

        async addExcludedProduct() {
            const input = document.getElementById('ff-excluded-id-input');
            const status = document.getElementById('ff-excluded-status');
            const btn = document.getElementById('ff-excluded-add-btn');
            if (!input) return;

            const id = parseInt(input.value);
            if (!id || id <= 0) {
                status.textContent = t('feed_edit.invalid_id');
                status.classList.remove('ff-hidden');
                return;
            }

            // Check for duplicate
            if (document.querySelector('#ff-excluded-chips .ff-excluded-chip[data-id="' + id + '"]')) {
                status.textContent = t('feed_edit.product_already_exists', {'%id%': id});
                status.classList.remove('ff-hidden');
                return;
            }

            btn.disabled = true;
            status.textContent = t('feed_edit.checking');
            status.classList.remove('ff-hidden');

            try {
                const res = await api.get('/api/ps/product-lookup', { id });
                if (res.success) {
                    this._addExcludedChip(id, res.data.name);
                    input.value = '';
                    status.classList.add('ff-hidden');
                    this.scheduleCount();
                } else {
                    status.textContent = res.message || t('feed_edit.product_not_found');
                }
            } catch (e) {
                status.textContent = t('common.error_prefix') + e.message;
            } finally {
                btn.disabled = false;
                input.focus();
            }
        },

        _addExcludedChip(id, name) {
            const container = document.getElementById('ff-excluded-chips');
            if (!container) return;
            if (container.querySelector('.ff-excluded-chip[data-id="' + id + '"]')) return;

            const chip = document.createElement('span');
            chip.className = 'ff-excluded-chip';
            chip.dataset.id = id;
            chip.innerHTML =
                '<span class="ff-excluded-chip-id">#' + id + '</span>' +
                '<span class="ff-excluded-chip-name">' + toast.escape(name) + '</span>' +
                '<span class="ff-tag-chip-remove" title="' + t('common.delete') + '">\u00D7</span>';
            chip.querySelector('.ff-tag-chip-remove').addEventListener('click', () => {
                chip.remove();
                this.scheduleCount();
            });
            container.appendChild(chip);
        },

        addFeatureRow(featureId, featureValueId) {
            const container = document.getElementById('ff-filter-features');
            const addBtn = container.querySelector('#ff-add-feature-filter');

            const row = document.createElement('div');
            row.className = 'ff-feature-row';

            const featureOpts = '<option value="">' + t('feed_edit.select_feature') + '</option>' +
                this._psData.features.map(f =>
                    '<option value="' + f.id_feature + '"' + (f.id_feature == featureId ? ' selected' : '') + '>' +
                    toast.escape(f.name) + '</option>'
                ).join('');

            row.innerHTML =
                '<select class="ff-select ff-feature-select">' + featureOpts + '</select>' +
                '<select class="ff-select ff-feature-value-select"><option value="">' + t('feed_edit.select_value') + '</option></select>' +
                '<button class="ff-btn ff-btn--ghost ff-btn--sm" style="color: var(--ff-danger-text);">\u00D7</button>';

            container.insertBefore(row, addBtn);

            const featureSelect = row.querySelector('.ff-feature-select');
            const valueSelect = row.querySelector('.ff-feature-value-select');
            let _fvid = featureValueId;

            const loadValues = async () => {
                const fid = featureSelect.value;
                if (!fid) {
                    valueSelect.innerHTML = '<option value="">' + t('feed_edit.select_value') + '</option>';
                    return;
                }
                try {
                    const res = await api.get('/api/ps/features', { id_feature: fid });
                    const values = res.data?.values || [];
                    valueSelect.innerHTML = '<option value="">' + t('feed_edit.select_value') + '</option>' +
                        values.map(v =>
                            '<option value="' + v.id_feature_value + '"' + (v.id_feature_value == _fvid ? ' selected' : '') + '>' +
                            toast.escape(v.value) + '</option>'
                        ).join('');
                    _fvid = null;
                } catch (e) { /* ignore */ }
            };

            featureSelect.addEventListener('change', () => { _fvid = null; loadValues(); this.scheduleCount(); });
            valueSelect.addEventListener('change', () => this.scheduleCount());
            row.querySelector('.ff-btn').addEventListener('click', () => { row.remove(); this.scheduleCount(); });

            if (featureId) loadValues();
        },

        addPriceRangeRow(min, max) {
            const container = document.getElementById('ff-filter-price-ranges');
            const addBtn = container.querySelector('#ff-add-price-range');

            const row = document.createElement('div');
            row.className = 'ff-price-range-row';
            row.innerHTML =
                '<input type="number" class="ff-input" placeholder="Min" step="0.01" min="0" data-role="min"' + (min != null ? ' value="' + min + '"' : '') + '>' +
                '<span class="ff-text-muted">\u2013</span>' +
                '<input type="number" class="ff-input" placeholder="Max" step="0.01" min="0" data-role="max"' + (max != null ? ' value="' + max + '"' : '') + '>' +
                '<button class="ff-btn ff-btn--ghost ff-btn--sm" style="color: var(--ff-danger-text);">\u00D7</button>';

            container.insertBefore(row, addBtn);

            let timer;
            row.querySelectorAll('.ff-input').forEach(inp => {
                inp.addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => this.scheduleCount(), 300);
                });
            });
            row.querySelector('.ff-btn').addEventListener('click', () => { row.remove(); this.scheduleCount(); });
        },

        collectFilters() {
            const filters = {};

            // Categories
            const catIds = [];
            document.querySelectorAll('#ff-filter-categories input[type="checkbox"]:checked').forEach(cb => {
                const id = parseInt(cb.dataset.id);
                if (id > 0) catIds.push(id);
            });
            if (catIds.length) {
                filters.categories = catIds;
                const catMode = document.getElementById('ff-filter-categories-mode')?.value;
                if (catMode === 'exclude') filters.categories_mode = 'exclude';
            }

            // Manufacturers
            const mfrIds = [];
            document.querySelectorAll('#ff-filter-manufacturers input[type="checkbox"]:checked').forEach(cb => {
                const id = parseInt(cb.dataset.id);
                if (id > 0) mfrIds.push(id);
            });
            if (mfrIds.length) {
                filters.manufacturers = mfrIds;
                const mfrMode = document.getElementById('ff-filter-manufacturers-mode')?.value;
                if (mfrMode === 'exclude') filters.manufacturers_mode = 'exclude';
            }

            // Price ranges
            const priceRanges = [];
            document.querySelectorAll('.ff-price-range-row').forEach(row => {
                const minVal = row.querySelector('[data-role="min"]')?.value;
                const maxVal = row.querySelector('[data-role="max"]')?.value;
                if ((minVal !== '' && minVal != null) || (maxVal !== '' && maxVal != null)) {
                    const range = {};
                    if (minVal !== '' && minVal != null) range.min = parseFloat(minVal);
                    if (maxVal !== '' && maxVal != null) range.max = parseFloat(maxVal);
                    priceRanges.push(range);
                }
            });
            if (priceRanges.length) {
                filters.price_ranges = priceRanges;
                const priceMode = document.getElementById('ff-filter-price-mode')?.value;
                if (priceMode === 'exclude') filters.price_mode = 'exclude';
            }

            // Stock
            if (document.getElementById('ff-filter-skip-out-of-stock')?.checked) {
                filters.skip_out_of_stock = true;
                const minStock = document.getElementById('ff-filter-min-stock')?.value;
                if (minStock !== '' && minStock != null && parseInt(minStock) > 0) filters.min_stock = parseInt(minStock);
            }

            // Require EAN / image
            if (document.getElementById('ff-filter-require-ean')?.checked) filters.require_ean = true;
            if (document.getElementById('ff-filter-require-image')?.checked) filters.require_image = true;

            // Condition (only store if not all selected)
            const conditions = [];
            document.querySelectorAll('#ff-filter-condition input:checked').forEach(cb => conditions.push(cb.value));
            if (conditions.length > 0 && conditions.length < 3) filters.condition = conditions;

            // Visibility (only store if not all selected)
            const vis = [];
            document.querySelectorAll('#ff-filter-visibility input:checked').forEach(cb => vis.push(cb.value));
            if (vis.length > 0 && vis.length < 4) filters.visibility = vis;

            // Features
            const features = [];
            document.querySelectorAll('.ff-feature-row').forEach(row => {
                const fid = row.querySelector('.ff-feature-select')?.value;
                const fvid = row.querySelector('.ff-feature-value-select')?.value;
                if (fid && fvid) {
                    features.push({ id_feature: parseInt(fid), id_feature_value: parseInt(fvid) });
                }
            });
            if (features.length) {
                filters.features = features;
                const featMode = document.getElementById('ff-filter-features-mode')?.value;
                if (featMode === 'exclude') filters.features_mode = 'exclude';
            }

            // Suppliers
            const supIds = [];
            document.querySelectorAll('#ff-filter-suppliers input[type="checkbox"]:checked').forEach(cb => {
                const id = parseInt(cb.dataset.id);
                if (id > 0) supIds.push(id);
            });
            if (supIds.length) {
                filters.suppliers = supIds;
                const supMode = document.getElementById('ff-filter-suppliers-mode')?.value;
                if (supMode === 'exclude') filters.suppliers_mode = 'exclude';
            }

            // Tags (include + exclude)
            const tagsInclude = [];
            document.querySelectorAll('#ff-tags-include .ff-tag-chip').forEach(c => {
                const id = parseInt(c.dataset.id);
                if (id > 0) tagsInclude.push(id);
            });
            if (tagsInclude.length) filters.tags_include = tagsInclude;
            const tagsExclude = [];
            document.querySelectorAll('#ff-tags-exclude .ff-tag-chip').forEach(c => {
                const id = parseInt(c.dataset.id);
                if (id > 0) tagsExclude.push(id);
            });
            if (tagsExclude.length) filters.tags_exclude = tagsExclude;

            // Excluded products (from chips)
            const excludedChips = [];
            document.querySelectorAll('#ff-excluded-chips .ff-excluded-chip').forEach(c => {
                const id = parseInt(c.dataset.id);
                if (id > 0) excludedChips.push(id);
            });
            if (excludedChips.length) filters.excluded_products = excludedChips;

            return filters;
        },

        attachFilterListeners() {
            const filtersCard = document.getElementById('ff-filters-card');
            if (filtersCard) {
                filtersCard.addEventListener('change', () => this.scheduleCount());
            }

            // Debounced input listeners for text/number fields
            ['ff-filter-min-stock'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    let timer;
                    el.addEventListener('input', () => {
                        clearTimeout(timer);
                        timer = setTimeout(() => this.scheduleCount(), 300);
                    });
                }
            });

            // Excluded products picker
            const exclInput = document.getElementById('ff-excluded-id-input');
            const exclBtn = document.getElementById('ff-excluded-add-btn');
            if (exclInput && exclBtn) {
                const doAdd = () => this.addExcludedProduct();
                exclBtn.addEventListener('click', doAdd);
                exclInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') { e.preventDefault(); doAdd(); }
                });
            }

            // Add feature filter button
            document.getElementById('ff-add-feature-filter')?.addEventListener('click', () => {
                this.addFeatureRow(null, null);
            });

            // Add price range button
            document.getElementById('ff-add-price-range')?.addEventListener('click', () => {
                this.addPriceRangeRow(null, null);
            });

            // Stock: show/hide min stock field based on switch
            const skipOos = document.getElementById('ff-filter-skip-out-of-stock');
            const minStockGroup = document.getElementById('ff-min-stock-group');
            if (skipOos && minStockGroup) {
                skipOos.addEventListener('change', () => {
                    minStockGroup.classList.toggle('ff-hidden', !skipOos.checked);
                    if (!skipOos.checked) {
                        document.getElementById('ff-filter-min-stock').value = '';
                    }
                    this.scheduleCount();
                });
            }
        },

        scheduleCount() {
            clearTimeout(this._countTimer);
            this._countTimer = setTimeout(() => this.updateCount(), 300);
        },

        async updateCount() {
            const spinner = document.getElementById('ff-counter-spinner');
            const text = document.getElementById('ff-counter-text');
            const textBottom = document.getElementById('ff-counter-text-bottom');
            const badge = document.getElementById('ff-filter-badge');

            if (spinner) spinner.classList.remove('ff-hidden');

            try {
                const filters = this.collectFilters();
                const res = await api.post('/api/feeds/count', { filters });
                const matched = res.data?.matched ?? 0;
                const total = res.data?.total ?? 0;

                if (text) {
                    text.innerHTML = '<span class="ff-counter-highlight">' + format.number(matched) + '</span> ' + t('feed_edit.products_of') + ' ' + format.number(total) + ' ' + t('common.products');
                }
                if (textBottom) {
                    textBottom.textContent = format.number(matched) + ' ' + t('feed_edit.products_of') + ' ' + format.number(total) + ' ' + t('common.products');
                }

                // Update filter badge + tab hints
                const filterCount = Object.keys(filters).length;
                if (badge) {
                    badge.textContent = filterCount > 0 ? (filterCount + ' ' + t('feed_edit.active_filters')) : t('feed_edit.no_filters');
                    badge.className = filterCount > 0 ? 'ff-badge ff-badge--success' : 'ff-badge ff-badge--neutral';
                }
                this.updateFilterHints();

            } catch (e) {
                if (text) text.textContent = t('feed_edit.count_error');
            } finally {
                if (spinner) spinner.classList.add('ff-hidden');
            }
        },

        async save() {
            const country = document.getElementById('ff-feed-country')?.value.trim().toUpperCase();
            const lang = document.getElementById('ff-feed-lang')?.value.trim().toLowerCase();
            const currency = document.getElementById('ff-feed-currency')?.value.trim().toUpperCase();

            if (!country || !lang || !currency) {
                toast.error(t('feed_edit.fill_required_fields'));
                return;
            }

            const saveBtn = document.getElementById('ff-feed-save-btn');
            const saveBtnBottom = document.getElementById('ff-feed-save-btn-bottom');
            if (saveBtn) loading.button(saveBtn, true);
            if (saveBtnBottom) loading.button(saveBtnBottom, true);

            try {
                const payload = {
                    id: this._feedId > 0 ? this._feedId : undefined,
                    country_code: country,
                    language_code: lang,
                    currency_code: currency,
                    title_template: document.getElementById('ff-feed-title-tpl')?.value || '{name}',
                    description_source: document.getElementById('ff-feed-desc-source')?.value || 'description_short',
                    offer_id_prefix: (document.getElementById('ff-feed-offer-prefix')?.value || '').trim(),
                    include_tax: document.getElementById('ff-feed-tax')?.checked ? 1 : 0,
                    active: document.getElementById('ff-feed-active')?.checked ? 1 : 0,
                    filters: this.collectFilters(),
                };

                const res = await api.post('/api/feeds/save', payload);
                toast.success(t('feed_edit.feed_saved'));

                // If new feed, update URL to edit mode
                if (!this._feedId && res.data?.id) {
                    this._feedId = res.data.id;
                    document.getElementById('ff-feed-id').value = this._feedId;
                    const newUrl = config.apiBaseUrl + '/feeds/edit/' + this._feedId + '?' + config.adminTokenParam + '=' + encodeURIComponent(config.adminToken);
                    window.history.replaceState({}, '', newUrl);
                }
            } catch (e) {
                toast.error(t('feed_edit.save_error') + e.message);
            } finally {
                if (saveBtn) loading.button(saveBtn, false);
                if (saveBtnBottom) loading.button(saveBtnBottom, false);
            }
        }
    };

    // -------------------------------------------------------------------------
    // Page-specific initializers
    // -------------------------------------------------------------------------
    const pages = {
        dashboard() {
            pageDashboard.load();

            const refreshBtn = document.getElementById('ff-refresh-stats');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => pageDashboard.load());
            }

            const syncBtn = document.getElementById('ff-sync-now');
            if (syncBtn) {
                syncBtn.addEventListener('click', () => pageDashboard.triggerSync('delta'));
            }

            const refreshStatusesBtn = document.getElementById('ff-refresh-statuses');
            if (refreshStatusesBtn) {
                refreshStatusesBtn.addEventListener('click', () => pageDashboard.refreshStatuses());
            }

            const refreshAccountBtn = document.getElementById('ff-refresh-account-status');
            if (refreshAccountBtn) {
                refreshAccountBtn.addEventListener('click', () => pageDashboard.loadAccountStatus());
            }

            // Expose triggerSync for quick action buttons
            pages.dashboard.triggerSync = (type) => pageDashboard.triggerSync(type);
        },

        products() {
            pageProducts.bindSelectAllOnce();
            pageProducts.load(1);

            const reloadAndClear = () => {
                pageProducts.clearSelection();
                pageProducts.load(1);
            };

            const searchInput = document.getElementById('ff-products-search');
            if (searchInput) {
                searchInput.addEventListener('input', debounce(reloadAndClear, 400));
            }

            const statusFilter = document.getElementById('ff-products-status-filter');
            if (statusFilter) {
                statusFilter.addEventListener('change', reloadAndClear);
            }

            const syncFilter = document.getElementById('ff-products-sync-filter');
            if (syncFilter) {
                syncFilter.addEventListener('change', reloadAndClear);
            }

            const clearBtn = document.getElementById('ff-products-clear-filters');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    if (searchInput) searchInput.value = '';
                    if (statusFilter) statusFilter.value = '';
                    if (syncFilter) syncFilter.value = '';
                    reloadAndClear();
                });
            }

            const syncSelectedBtn = document.getElementById('ff-products-sync-selected');
            if (syncSelectedBtn) {
                syncSelectedBtn.addEventListener('click', () => pageProducts.syncSelected());
            }

            // Bulk action bar buttons (replaces inline onclick handlers
            // which sometimes failed under CSP / when the IIFE binding
            // wasn't yet a window property at click time).
            const bulkSync = document.getElementById('ff-bulk-sync');
            if (bulkSync) bulkSync.addEventListener('click', () => pageProducts.syncSelected());

            const bulkRemove = document.getElementById('ff-bulk-remove');
            if (bulkRemove) bulkRemove.addEventListener('click', () => pageProducts.removeSelected());

            const bulkCancel = document.getElementById('ff-bulk-cancel');
            if (bulkCancel) bulkCancel.addEventListener('click', () => pageProducts.clearSelection());

            // Still expose for the per-row "Sync" link rendered inline
            pages.products.syncOne = (id) => pageProducts.syncOne(id);
        },

        product_detail() {
            pageProductDetail.load();

            // Back to products link
            const backBtn = document.getElementById('ff-back-to-products');
            if (backBtn) {
                backBtn.href = config.apiBaseUrl + '/products?' + config.adminTokenParam + '=' + encodeURIComponent(config.adminToken);
            }

            const resyncBtn = document.getElementById('ff-resync-product');
            if (resyncBtn) {
                resyncBtn.addEventListener('click', () => pageProductDetail.resync());
            }
        },

        queue() {
            pageQueue.load(1);

            const statusFilter = document.getElementById('ff-queue-status-filter');
            if (statusFilter) {
                statusFilter.addEventListener('change', () => pageQueue.load(1));
            }

            const actionFilter = document.getElementById('ff-queue-action-filter');
            if (actionFilter) {
                actionFilter.addEventListener('change', () => pageQueue.load(1));
            }

            const retryBtn = document.getElementById('ff-queue-retry-all');
            if (retryBtn) {
                retryBtn.addEventListener('click', () => pageQueue.retryAll());
            }

            const clearBtn = document.getElementById('ff-queue-clear-failed');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => pageQueue.clearFailed());
            }

            // Expose for inline onclick
            pages.queue.retryItem = (id) => pageQueue.retryItem(id);
        },

        reports() {
            pageReports.load();

            const periodFilter = document.getElementById('ff-reports-period');
            if (periodFilter) {
                periodFilter.addEventListener('change', () => pageReports.load());
            }

            const countryFilter = document.getElementById('ff-reports-country');
            if (countryFilter) {
                countryFilter.addEventListener('change', () => pageReports.load());
            }

            const refreshBtn = document.getElementById('ff-reports-refresh');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => pageReports.refreshData());
            }
        },

        rules() {
            pageRules.load();

            // Expose for inline onclick
            pages.rules.editCategory = (id) => pageRules.editCategory(id);
            pages.rules.deleteCategory = (id) => pageRules.deleteCategory(id);
            pages.rules.editRule = (id, type) => pageRules.editRule(id, type);
            pages.rules.deleteRule = (id) => pageRules.deleteRule(id);

            // Add category mapping
            const addCategoryBtn = document.getElementById('ff-add-category-map');
            if (addCategoryBtn) {
                addCategoryBtn.addEventListener('click', () => pageRules.openCategoryModal(null));
            }

            // Import Google taxonomy (one-time, ~5000 categories)
            const importTaxonomyBtn = document.getElementById('ff-import-taxonomy');
            if (importTaxonomyBtn) {
                importTaxonomyBtn.addEventListener('click', async () => {
                    if (!window.confirm(t('rules.import_taxonomy_confirm') || 'Download ~5000 Google product categories? Takes a few seconds.')) {
                        return;
                    }
                    loading.button(importTaxonomyBtn, true);
                    try {
                        const res = await api.post('/api/taxonomy/import', { lang: 'pl' });
                        if (res.success) {
                            toast.success(res.message || 'Categories imported');
                        } else {
                            toast.error(res.message || 'Import failed');
                        }
                    } catch (e) {
                        toast.error(e.message || 'Import failed');
                    } finally {
                        loading.button(importTaxonomyBtn, false);
                    }
                });
            }

            // Add rule - main button opens type picker
            const addRuleBtn = document.getElementById('ff-add-rule');
            if (addRuleBtn) {
                addRuleBtn.addEventListener('click', () => {
                    // Determine type from active tab
                    const activeTab = document.querySelector('.ff-tab--active');
                    const tabMap = {
                        'exclusions': 'exclusion',
                        'pricing': 'pricing',
                        'labels': 'custom_label',
                        'identifiers': 'identifier',
                    };
                    const tabKey = activeTab?.dataset?.ffTab || '';
                    const type = tabMap[tabKey] || 'exclusion';
                    pageRules.openRuleModal(type, null);
                });
            }

            // Per-tab add rule buttons
            document.querySelectorAll('[data-add-rule-type]').forEach(btn => {
                btn.addEventListener('click', () => {
                    pageRules.openRuleModal(btn.dataset.addRuleType, null);
                });
            });
        },

        configuration() {
            pageConfig.load();

            document.querySelectorAll('.ff-save-config-btn').forEach(btn => {
                btn.addEventListener('click', () => pageConfig.saveConfig());
            });

            const saveCredsBtn = document.getElementById('ff-save-credentials');
            if (saveCredsBtn) {
                saveCredsBtn.addEventListener('click', () => pageConfig.saveCredentials());
            }

            const disconnectBtn = document.getElementById('ff-oauth-disconnect');
            if (disconnectBtn) {
                disconnectBtn.addEventListener('click', () => pageConfig.disconnect());
            }

            const showTokenBtn = document.getElementById('ff-show-cron-token');
            if (showTokenBtn) {
                showTokenBtn.addEventListener('click', () => pageConfig.showCronToken());
            }

            const addFeedLink = document.getElementById('ff-add-feed-link');
            if (addFeedLink) {
                addFeedLink.href = config.apiBaseUrl + '/feeds/new?' + config.adminTokenParam + '=' + encodeURIComponent(config.adminToken);
            }

            const dataSourcesProvisionBtn = document.getElementById('ff-data-sources-provision');
            if (dataSourcesProvisionBtn) {
                dataSourcesProvisionBtn.addEventListener('click', () => pageConfig.provisionDataSources());
            }

            // Merchant Center ID input
            const merchantSaveBtn = document.getElementById('ff-merchant-save');
            if (merchantSaveBtn) {
                merchantSaveBtn.addEventListener('click', () => {
                    const input = document.getElementById('ff-merchant-manual');
                    pageConfig.saveMerchantId(input ? input.value : '');
                });
            }
            const merchantInput = document.getElementById('ff-merchant-manual');
            if (merchantInput) {
                // Allow submitting with Enter key
                merchantInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        pageConfig.saveMerchantId(merchantInput.value);
                    }
                });
            }
            const merchantChangeBtn = document.getElementById('ff-merchant-change');
            if (merchantChangeBtn) {
                merchantChangeBtn.addEventListener('click', () => {
                    const sel = document.getElementById('ff-merchant-selector');
                    const row = document.getElementById('ff-merchant-change-row');
                    if (sel) sel.classList.remove('ff-hidden');
                    if (row) row.classList.add('ff-hidden');
                });
            }
            const registerGcpBtn = document.getElementById('ff-register-gcp');
            if (registerGcpBtn) {
                registerGcpBtn.addEventListener('click', () => pageConfig.registerGcpDeveloper());
            }
            const reregisterBtn = document.getElementById('ff-gcp-reregister');
            if (reregisterBtn) {
                reregisterBtn.addEventListener('click', () => {
                    // Show the form again so the user can re-enter / change the email.
                    const formRow = document.getElementById('ff-register-gcp-row');
                    const statusRow = document.getElementById('ff-gcp-registered-row');
                    if (formRow) formRow.classList.remove('ff-hidden');
                    if (statusRow) statusRow.classList.add('ff-hidden');
                });
            }

            const addAttrMapBtn = document.getElementById('ff-add-attr-map');
            if (addAttrMapBtn) {
                addAttrMapBtn.addEventListener('click', () => pageConfig.openAttrMapModal(null));
            }

            // Shipping buttons
            const shippingRefreshBtn = document.getElementById('ff-shipping-refresh');
            if (shippingRefreshBtn) {
                shippingRefreshBtn.addEventListener('click', () => pageConfig.loadShippingPreview());
            }

            const shippingPushBtn = document.getElementById('ff-shipping-push');
            if (shippingPushBtn) {
                shippingPushBtn.addEventListener('click', () => pageConfig.pushShipping());
            }

            // Load shipping preview
            pageConfig.loadShippingPreview();

            // Promotions buttons
            const promoScanBtn = document.getElementById('ff-promotions-scan');
            if (promoScanBtn) {
                promoScanBtn.addEventListener('click', () => pageConfig.scanCartRules());
            }

            const promoSyncAllBtn = document.getElementById('ff-promotions-sync-all');
            if (promoSyncAllBtn) {
                promoSyncAllBtn.addEventListener('click', () => pageConfig.syncAllPromotions());
            }

            // Load promotions
            pageConfig.loadPromotions();

            // Expose for inline onclick
            pages.configuration.deleteFeed = (id) => pageConfig.deleteFeed(id);
            pages.configuration.editAttributeMap = (id) => pageConfig.editAttributeMap(id);
            pages.configuration.deleteAttributeMap = (id) => pageConfig.deleteAttributeMap(id);
            pages.configuration.syncPromotion = (id) => pageConfig.syncPromotion(id);
            pages.configuration.deletePromotion = (id) => pageConfig.deletePromotion(id);
            pages.configuration.mapCartRule = (id) => pageConfig.mapCartRule(id);
        },

        feed_edit() {
            pageFeedEdit.load();

            const saveBtn = document.getElementById('ff-feed-save-btn');
            if (saveBtn) saveBtn.addEventListener('click', () => pageFeedEdit.save());

            const saveBtnBottom = document.getElementById('ff-feed-save-btn-bottom');
            if (saveBtnBottom) saveBtnBottom.addEventListener('click', () => pageFeedEdit.save());
        },

        support() {
            // Load diagnostics from existing endpoints
            const diagEl = document.getElementById('ff-support-diagnostics');
            if (!diagEl) return;

            (async () => {
                try {
                    const [dashRes, configRes] = await Promise.all([
                        api.get('/api/support/stats').catch(() => null),
                        api.get('/api/support/config').catch(() => null),
                    ]);

                    const conn = dashRes?.data?.connection;
                    const stats = dashRes?.data?.stats;
                    const queue = dashRes?.data?.queueHealth;
                    const issues = dashRes?.data?.issueCounts;
                    const cfg = configRes?.data?.config;

                    const connected = conn?.connected;
                    const row = (label, val, status) => {
                        const color = status === 'ok' ? 'var(--ff-success)' : status === 'warn' ? '#F5A623' : status === 'error' ? 'var(--ff-danger)' : 'inherit';
                        return `<div class="ff-flex-between ff-mb-sm" style="padding: 4px 0; border-bottom: 1px solid var(--ff-border-light);">
                            <span class="ff-text-sm ff-text-muted">${label}</span>
                            <span class="ff-text-sm" style="color: ${color};">${toast.escape(String(val))}</span>
                        </div>`;
                    };

                    let html = '';
                    html += row(t('support.gmc_connection'), connected ? '\u2705 ' + t('support.connected') : '\u274C ' + t('support.not_connected'), connected ? 'ok' : 'error');
                    if (connected) {
                        html += row(t('support.email'), conn.email || '\u2014');
                        html += row(t('support.merchant_id'), conn.merchantId || '\u2014');
                    }
                    html += row(t('common.products'), stats ? `${format.number(stats.total || 0)} (\u2705 ${format.number(stats.approved || 0)}, \u26A0 ${format.number(stats.pending || 0)}, \u274C ${format.number(stats.disapproved || 0)})` : '\u2014');

                    if (queue) {
                        const qTotal = (queue.pending || 0) + (queue.processing || 0) + (queue.failed || 0);
                        html += row(t('common.queue'), `${format.number(qTotal)} ${t('common.items')}`, (queue.failed || 0) > 0 ? 'warn' : 'ok');
                    }

                    if (issues) {
                        const issueTotal = (issues.critical || 0) + (issues.warning || 0) + (issues.info || 0);
                        html += row(t('support.issues'), issueTotal > 0 ? `${issues.critical || 0} kryt., ${issues.warning || 0} ostrz., ${issues.info || 0} info` : t('common.none'), (issues.critical || 0) > 0 ? 'error' : issueTotal > 0 ? 'warn' : 'ok');
                    }

                    html += row(t('support.delta_sync'), cfg?.delta_sync_enabled ? t('common.enabled') : t('common.disabled'));
                    html += row('Batch size', cfg?.batch_size || '100');
                    html += row(t('support.sync_interval'), cfg?.sync_interval ? (cfg.sync_interval === '0' ? t('common.manual') : t('common.every_hours', {'%h%': cfg.sync_interval})) : '\u2014');

                    diagEl.innerHTML = html;
                } catch (e) {
                    diagEl.innerHTML = '<p class="ff-text-sm ff-text-muted">' + t('support.diagnostics_load_error') + '</p>';
                }
            })();
        }
    };

    // -------------------------------------------------------------------------
    // Initialization
    // -------------------------------------------------------------------------
    function init() {
        if (window._ffInitDone) return;
        const wrap = document.querySelector('.ff-wrap');
        if (!wrap) return;
        window._ffInitDone = true;

        // Derive API base URL from current page URL
        // Page URL: /admin-dev/modules/feedforge/config → base: /admin-dev/modules/feedforge
        const pathMatch = window.location.pathname.match(/^(.*\/modules\/feedforge)/);
        config.apiBaseUrl = pathMatch ? pathMatch[1] : '';
        config.modulePath = wrap.dataset.modulePath || '';

        // Parse JS translations dictionary from <script type="application/json"> tag
        try {
            const transEl = document.getElementById('ff-translations');
            config.translations = transEl ? JSON.parse(transEl.textContent) : {};
        } catch (e) {
            config.translations = {};
        }
        // Extract admin CSRF token from URL (PS9: ?_token=..., PS8: ?token=...)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('_token')) {
            config.adminToken = urlParams.get('_token');
            config.adminTokenParam = '_token';
        } else if (urlParams.get('token')) {
            config.adminToken = urlParams.get('token');
            config.adminTokenParam = 'token';
        }

        toast.init();
        tabs.init();

        // Auto-init page-specific JS
        const page = wrap.dataset.page;
        if (page && typeof pages[page] === 'function') {
            pages[page]();
        }
    }

    // -------------------------------------------------------------------------
    // Wait for DOM
    // -------------------------------------------------------------------------
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------
    return {
        api,
        toast,
        loading,
        pagination,
        format,
        confirm,
        config,
        pages,
        modal,
    };
})();
