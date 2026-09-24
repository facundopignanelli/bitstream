/**
 * BitStream Settings - ReBit Mappings Controller
 *
 * Provides real-time AJAX mapping administration, live search filtering,
 * 1-click preset additions, in-place edit drawer, curated icon popover,
 * and 5-second undo toast for deleted mappings.
 *
 * @package BitStream
 */

(function () {
    'use strict';

    window.BitStream = window.BitStream || {};

    var Settings = {
        container: null,
        searchInput: null,
        searchClearBtn: null,
        drawer: null,
        drawerForm: null,
        drawerTitle: null,
        drawerClose: null,
        drawerCancel: null,
        drawerSaveBtn: null,
        drawerSaveText: null,
        inputDomain: null,
        inputLabel: null,
        inputIcon: null,
        modeInput: null,
        origDomainInput: null,
        iconPreviewMini: null,
        previewIcon: null,
        previewLabel: null,
        previewDomain: null,
        iconModal: null,
        iconBackdrop: null,
        iconClose: null,
        iconCancel: null,
        iconSearch: null,
        iconSearchClear: null,
        iconGrid: null,
        iconBody: null,
        iconStatus: null,
        iconLibrary: { brands: [], solid: [], regular: [] },
        iconsLoaded: false,
        iconsLoading: false,
        activeIconCategory: 'all',
        activeIconSearch: '',
        renderedIconCount: 0,
        currentFilteredIcons: [],
        chunkSize: 120,
        presetsShelf: null,
        presetsPills: null,
        listContainer: null,
        countBadge: null,
        noSearchResults: null,
        undoToast: null,
        undoText: null,
        undoBtn: null,
        undoTimeout: null,
        pendingDeletedRow: null,
        pendingDeletedData: null,

        // Fallback icon list in case network fetch fails
        getFallbackIcons: function () {
            return {
                brands: [
                    'fab fa-twitter', 'fab fa-x-twitter', 'fab fa-youtube', 'fab fa-github', 'fab fa-linkedin',
                    'fab fa-facebook', 'fab fa-instagram', 'fab fa-tiktok', 'fab fa-reddit', 'fab fa-spotify',
                    'fab fa-twitch', 'fab fa-discord', 'fab fa-medium', 'fab fa-dev', 'fab fa-hacker-news',
                    'fab fa-stack-overflow', 'fab fa-wikipedia-w', 'fab fa-pinterest', 'fab fa-threads',
                    'fab fa-mastodon', 'fab fa-bluesky', 'fab fa-telegram', 'fab fa-whatsapp', 'fab fa-soundcloud',
                    'fab fa-vimeo-v', 'fab fa-patreon', 'fab fa-steam', 'fab fa-gitlab', 'fab fa-codepen',
                    'fab fa-dribbble', 'fab fa-behance', 'fab fa-apple', 'fab fa-google', 'fab fa-amazon',
                    'fab fa-slack', 'fab fa-dropbox', 'fab fa-trello', 'fab fa-figma', 'fab fa-wordpress'
                ],
                solid: [
                    'fas fa-link', 'fas fa-globe', 'fas fa-video', 'fas fa-play', 'fas fa-film',
                    'fas fa-music', 'fas fa-headphones', 'fas fa-camera', 'fas fa-image', 'fas fa-newspaper',
                    'fas fa-book-open', 'fas fa-file-lines', 'fas fa-code', 'fas fa-bookmark', 'fas fa-share-nodes',
                    'fas fa-fire', 'fas fa-bolt', 'fas fa-star', 'fas fa-heart', 'fas fa-cart-shopping',
                    'fas fa-shield-halved', 'fas fa-tv', 'fas fa-radio', 'fas fa-microphone', 'fas fa-podcast',
                    'fas fa-rss', 'fas fa-comments', 'fas fa-bell', 'fas fa-envelope', 'fas fa-location-dot',
                    'fas fa-circle-play', 'fas fa-magnifying-glass', 'fas fa-circle-check', 'fas fa-cloud'
                ],
                regular: [
                    'far fa-bookmark', 'far fa-heart', 'far fa-star', 'far fa-comment', 'far fa-comments',
                    'far fa-newspaper', 'far fa-image', 'far fa-file-lines', 'far fa-bell', 'far fa-envelope',
                    'far fa-circle-play', 'far fa-compass', 'far fa-clock', 'far fa-calendar', 'far fa-eye'
                ]
            };
        },

        init: function () {
            this.container = document.querySelector('.bitstream-rebit-manager');
            if (!this.container) {
                return;
            }

            // Cache DOM references
            this.searchInput = document.getElementById('bitstream-rebit-search');
            this.searchClearBtn = document.getElementById('bitstream-rebit-search-clear');
            this.drawer = document.getElementById('bitstream-rebit-drawer');
            this.drawerForm = document.getElementById('bitstream-rebit-drawer-form');
            this.drawerTitle = document.getElementById('bitstream-rebit-drawer-title');
            this.drawerClose = document.getElementById('bitstream-rebit-drawer-close');
            this.drawerCancel = document.getElementById('bitstream-rebit-drawer-cancel');
            this.drawerSaveBtn = document.getElementById('bitstream-rebit-drawer-save');
            this.drawerSaveText = document.getElementById('bitstream-rebit-drawer-save-text');
            this.inputDomain = document.getElementById('bitstream-rebit-input-domain');
            this.inputLabel = document.getElementById('bitstream-rebit-input-label');
            this.inputIcon = document.getElementById('bitstream-rebit-input-icon');
            this.modeInput = document.getElementById('bitstream-rebit-mode');
            this.origDomainInput = document.getElementById('bitstream-rebit-orig-domain');
            this.iconPreviewMini = document.getElementById('bitstream-rebit-icon-preview-mini');
            this.previewIcon = document.getElementById('bitstream-rebit-preview-icon');
            this.previewLabel = document.getElementById('bitstream-rebit-preview-label');
            this.previewDomain = document.getElementById('bitstream-rebit-preview-domain');

            // Icon Picker Modal DOM
            this.iconModal = document.getElementById('bitstream-rebit-icon-modal');
            this.iconBackdrop = document.getElementById('bitstream-rebit-icon-backdrop');
            this.iconClose = document.getElementById('bitstream-rebit-icon-close');
            this.iconCancel = document.getElementById('bitstream-rebit-icon-cancel');
            this.iconSearch = document.getElementById('bitstream-rebit-icon-search');
            this.iconSearchClear = document.getElementById('bitstream-rebit-icon-search-clear');
            this.iconGrid = document.getElementById('bitstream-rebit-icon-grid');
            this.iconBody = document.getElementById('bitstream-rebit-icon-body');
            this.iconStatus = document.getElementById('bitstream-icon-picker-status');

            this.drawerPresets = document.getElementById('bitstream-rebit-drawer-presets');
            this.presetsPills = document.getElementById('bitstream-rebit-drawer-presets-pills') || document.getElementById('bitstream-rebit-presets-pills');
            this.listContainer = document.getElementById('bitstream-rebit-list');
            this.countBadge = document.getElementById('bitstream-rebit-count-badge');
            this.noSearchResults = document.getElementById('bitstream-rebit-no-search-results');
            this.undoToast = document.getElementById('bitstream-rebit-undo-toast');
            this.undoText = document.getElementById('bitstream-rebit-undo-text');
            this.undoBtn = document.getElementById('bitstream-rebit-undo-btn');

            this.bindEvents();
            this.updatePresetPillsMappedStatus();
        },

        getNonce: function () {
            if (window.bitstream_ajax && window.bitstream_ajax.rebit_mappings_nonce) {
                return window.bitstream_ajax.rebit_mappings_nonce;
            }
            return this.container ? (this.container.getAttribute('data-nonce') || '') : '';
        },

        getAjaxUrl: function () {
            if (window.bitstream_ajax && window.bitstream_ajax.ajax_url) {
                return window.bitstream_ajax.ajax_url;
            }
            return '/wp-admin/admin-ajax.php';
        },

        normalizeDomain: function (domain) {
            if (!domain) return '';
            var d = domain.trim().toLowerCase();
            d = d.replace(/^[a-z]+:\/\//i, '');
            d = d.replace(/[/?#].*$/, '');
            return d.trim();
        },

        bindEvents: function () {
            var self = this;

            // Search filter
            if (this.searchInput) {
                this.searchInput.addEventListener('input', function () {
                    self.filterMappings(this.value);
                });
            }

            if (this.searchClearBtn) {
                this.searchClearBtn.addEventListener('click', function () {
                    if (self.searchInput) {
                        self.searchInput.value = '';
                        self.filterMappings('');
                        self.searchInput.focus();
                    }
                });
            }

            // Top Add button
            var btnAdd = document.getElementById('bitstream-rebit-btn-add');
            if (btnAdd) {
                btnAdd.addEventListener('click', function () {
                    self.openDrawer('add');
                });
            }

            // Top Reset Defaults button
            var btnReset = document.getElementById('bitstream-rebit-btn-reset');
            if (btnReset) {
                btnReset.addEventListener('click', function () {
                    self.resetToDefaults();
                });
            }

            // Empty state import button
            var emptyImport = document.getElementById('bitstream-rebit-btn-empty-import');
            if (emptyImport) {
                emptyImport.addEventListener('click', function () {
                    self.resetToDefaults();
                });
            }

            // Drawer close / cancel buttons
            if (this.drawerClose) {
                this.drawerClose.addEventListener('click', function () {
                    self.closeDrawer();
                });
            }
            if (this.drawerCancel) {
                this.drawerCancel.addEventListener('click', function () {
                    self.closeDrawer();
                });
            }

            // Drawer form submit
            if (this.drawerForm) {
                this.drawerForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    self.saveMapping();
                });
            }

            // Live preview updates on input typing
            var updatePreview = function () {
                self.updateLivePreview();
            };
            if (this.inputDomain) this.inputDomain.addEventListener('input', updatePreview);
            if (this.inputLabel) this.inputLabel.addEventListener('input', updatePreview);
            if (this.inputIcon) this.inputIcon.addEventListener('input', updatePreview);

            // Icon picker toggle button
            // Icon picker open button
            var btnIconPick = document.getElementById('bitstream-rebit-btn-icon-pick');
            if (btnIconPick) {
                btnIconPick.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.openIconPicker();
                });
            }

            // Icon picker modal close buttons
            if (this.iconClose) {
                this.iconClose.addEventListener('click', function (e) {
                    e.preventDefault();
                    self.closeIconPicker();
                });
            }
            if (this.iconCancel) {
                this.iconCancel.addEventListener('click', function (e) {
                    e.preventDefault();
                    self.closeIconPicker();
                });
            }
            if (this.iconBackdrop) {
                this.iconBackdrop.addEventListener('click', function (e) {
                    e.preventDefault();
                    self.closeIconPicker();
                });
            }

            // Close icon picker on Escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && self.iconModal && !self.iconModal.hidden) {
                    self.closeIconPicker();
                }
            });

            // Icon picker category tabs
            var iconCatBtns = document.querySelectorAll('.bitstream-icon-category');
            iconCatBtns.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    iconCatBtns.forEach(function (b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    self.activeIconCategory = btn.getAttribute('data-category') || 'all';
                    self.filterAndRenderIcons();
                });
            });

            // Icon picker search
            if (this.iconSearch) {
                this.iconSearch.addEventListener('input', function () {
                    self.activeIconSearch = this.value.trim().toLowerCase();
                    if (self.iconSearchClear) {
                        self.iconSearchClear.hidden = !self.activeIconSearch;
                    }
                    self.filterAndRenderIcons();
                });
            }

            // Icon picker clear search
            if (this.iconSearchClear) {
                this.iconSearchClear.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (self.iconSearch) {
                        self.iconSearch.value = '';
                        self.activeIconSearch = '';
                        this.hidden = true;
                        self.filterAndRenderIcons();
                        self.iconSearch.focus();
                    }
                });
            }

            // Infinite scroll on icon grid container
            if (this.iconBody) {
                this.iconBody.addEventListener('scroll', function () {
                    if (this.scrollTop + this.clientHeight >= this.scrollHeight - 60) {
                        self.renderNextChunk();
                    }
                });
            }

            // Icon selection delegation
            if (this.iconGrid) {
                this.iconGrid.addEventListener('click', function (e) {
                    var opt = e.target.closest('.bitstream-icon-option');
                    if (opt) {
                        e.preventDefault();
                        var cls = opt.getAttribute('data-class');
                        if (cls && self.inputIcon) {
                            self.inputIcon.value = cls;
                            self.updateLivePreview();
                            self.closeIconPicker();
                        }
                    }
                });
            }

            // Presets delegation inside drawer (1-Click Auto-Fill)
            if (this.presetsPills) {
                this.presetsPills.addEventListener('click', function (e) {
                    var pill = e.target.closest('.bitstream-preset-pill');
                    if (pill) {
                        var domain = pill.getAttribute('data-domain') || '';
                        var label = pill.getAttribute('data-label') || '';
                        var icon = pill.getAttribute('data-icon') || '';
                        self.selectPreset(pill, domain, label, icon);
                    }
                });
            }

            // Mappings list delegation (Edit / Delete)
            if (this.listContainer) {
                this.listContainer.addEventListener('click', function (e) {
                    var editBtn = e.target.closest('.bitstream-rebit-action-edit');
                    if (editBtn) {
                        var row = editBtn.closest('.bitstream-rebit-item');
                        if (row) {
                            self.openDrawer('edit', {
                                domain: row.getAttribute('data-domain'),
                                label: row.getAttribute('data-label'),
                                icon: row.getAttribute('data-icon')
                            });
                        }
                        return;
                    }

                    var deleteBtn = e.target.closest('.bitstream-rebit-action-delete');
                    if (deleteBtn) {
                        var delRow = deleteBtn.closest('.bitstream-rebit-item');
                        if (delRow) {
                            self.stageDelete(delRow);
                        }
                    }
                });
            }

            // Undo toast action
            if (this.undoBtn) {
                this.undoBtn.addEventListener('click', function () {
                    self.cancelPendingDelete();
                });
            }
        },

        filterMappings: function (query) {
            var q = (query || '').toLowerCase().trim();
            if (this.searchClearBtn) {
                this.searchClearBtn.hidden = !q;
            }

            var items = this.listContainer ? this.listContainer.querySelectorAll('.bitstream-rebit-item') : [];
            var matchCount = 0;

            items.forEach(function (item) {
                var domain = (item.getAttribute('data-domain') || '').toLowerCase();
                var label = (item.getAttribute('data-label') || '').toLowerCase();

                if (!q || domain.indexOf(q) !== -1 || label.indexOf(q) !== -1) {
                    item.hidden = false;
                    matchCount++;
                } else {
                    item.hidden = true;
                }
            });

            if (this.noSearchResults) {
                this.noSearchResults.hidden = (items.length === 0 || matchCount > 0);
            }
        },

        openDrawer: function (mode, data) {
            if (!this.drawer) return;

            this.closeIconPicker();
            this.modeInput.value = mode;

            // Clear active preset pill highlight
            if (this.presetsPills) {
                var allPills = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
                allPills.forEach(function (p) {
                    p.classList.remove('is-active');
                });
            }

            if (mode === 'edit' && data) {
                this.drawerTitle.textContent = 'Edit Mapping';
                this.drawerSaveText.textContent = 'Update Mapping';
                this.origDomainInput.value = data.domain || '';
                this.inputDomain.value = data.domain || '';
                this.inputLabel.value = data.label || '';
                this.inputIcon.value = data.icon || 'fa-solid fa-link';
                this.highlightPresetPill(data.domain);
            } else {
                this.drawerTitle.textContent = 'Add New Mapping';
                this.drawerSaveText.textContent = 'Save Mapping';
                this.origDomainInput.value = '';
                this.inputDomain.value = '';
                this.inputLabel.value = '';
                this.inputIcon.value = 'fa-solid fa-link';
            }

            this.updateLivePreview();
            this.drawer.hidden = false;
            this.drawer.classList.add('is-open');

            // Scroll drawer into view smoothly
            this.drawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            setTimeout(() => {
                if (this.inputDomain) {
                    this.inputDomain.focus();
                }
            }, 100);
        },

        closeDrawer: function () {
            if (!this.drawer) return;
            this.drawer.classList.remove('is-open');
            this.drawer.hidden = true;
            this.closeIconPicker();
            if (this.presetsPills) {
                var allPills = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
                allPills.forEach(function (p) {
                    p.classList.remove('is-active');
                });
            }
        },

        selectPreset: function (pill, domain, label, icon) {
            if (this.presetsPills) {
                var allPills = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
                allPills.forEach(function (p) {
                    p.classList.remove('is-active');
                });
            }
            if (pill) {
                pill.classList.add('is-active');
            }

            if (this.inputDomain) {
                this.inputDomain.value = domain || '';
            }
            if (this.inputLabel) {
                this.inputLabel.value = label || '';
            }
            if (this.inputIcon) {
                this.inputIcon.value = icon || 'fa-solid fa-link';
            }

            var norm = this.normalizeDomain(domain);
            var isMapped = this.isDomainMapped(norm);

            if (isMapped) {
                if (this.modeInput) this.modeInput.value = 'edit';
                if (this.origDomainInput) this.origDomainInput.value = norm;
                if (this.drawerTitle) this.drawerTitle.textContent = 'Edit Mapping';
                if (this.drawerSaveText) this.drawerSaveText.textContent = 'Update Mapping';
            } else {
                if (this.modeInput) this.modeInput.value = 'add';
                if (this.origDomainInput) this.origDomainInput.value = '';
                if (this.drawerTitle) this.drawerTitle.textContent = 'Add New Mapping';
                if (this.drawerSaveText) this.drawerSaveText.textContent = 'Save Mapping';
            }

            this.updateLivePreview();

            // Tactile feedback pulse on live preview badge
            var badge = document.getElementById('bitstream-rebit-live-preview');
            if (badge) {
                badge.classList.remove('is-highlighted');
                void badge.offsetWidth;
                badge.classList.add('is-highlighted');
                setTimeout(function () { badge.classList.remove('is-highlighted'); }, 600);
            }
        },

        highlightPresetPill: function (domain) {
            if (!this.presetsPills || !domain) return;
            var norm = this.normalizeDomain(domain);
            var pills = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
            pills.forEach(function (pill) {
                var pDomain = pill.getAttribute('data-domain') || '';
                if (pDomain && pDomain.toLowerCase() === norm) {
                    pill.classList.add('is-active');
                } else {
                    pill.classList.remove('is-active');
                }
            });
        },

        isDomainMapped: function (domain) {
            if (!this.listContainer || !domain) return false;
            var norm = this.normalizeDomain(domain);
            var items = this.listContainer.querySelectorAll('.bitstream-rebit-item');
            for (var i = 0; i < items.length; i++) {
                var d = this.normalizeDomain(items[i].getAttribute('data-domain'));
                if (d === norm) return true;
            }
            return false;
        },

        updatePresetPillsMappedStatus: function () {
            if (!this.presetsPills) return;
            var self = this;
            var pills = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
            pills.forEach(function (pill) {
                var domain = pill.getAttribute('data-domain') || '';
                var isMapped = self.isDomainMapped(domain);
                pill.setAttribute('data-mapped', isMapped ? '1' : '0');
                if (isMapped) {
                    pill.classList.add('is-mapped');
                    if (!pill.querySelector('.bitstream-preset-pill-status')) {
                        var status = document.createElement('span');
                        status.className = 'bitstream-preset-pill-status';
                        status.title = 'Already in active mappings';
                        status.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i>';
                        pill.appendChild(status);
                    }
                } else {
                    pill.classList.remove('is-mapped');
                    var status = pill.querySelector('.bitstream-preset-pill-status');
                    if (status) {
                        status.remove();
                    }
                }
            });
        },

        updateLivePreview: function () {
            var rawDomain = this.inputDomain ? this.inputDomain.value : '';
            var normDomain = this.normalizeDomain(rawDomain) || 'example.com';
            var label = (this.inputLabel && this.inputLabel.value.trim()) ? this.inputLabel.value.trim() : 'shared a link';
            var icon = (this.inputIcon && this.inputIcon.value.trim()) ? this.inputIcon.value.trim() : 'fa-solid fa-link';

            if (this.previewDomain) this.previewDomain.textContent = normDomain;
            if (this.previewLabel) this.previewLabel.textContent = label;
            if (this.previewIcon) this.previewIcon.className = icon;
            if (this.iconPreviewMini) {
                this.iconPreviewMini.innerHTML = '<i class="' + this.escapeAttr(icon) + '" aria-hidden="true"></i>';
            }
        },

        openIconPicker: function () {
            if (!this.iconModal) return;
            this.iconModal.hidden = false;
            this.iconModal.classList.add('is-open');
            document.body.style.overflow = 'hidden';

            if (this.iconSearch) {
                this.iconSearch.value = '';
                this.activeIconSearch = '';
                if (this.iconSearchClear) this.iconSearchClear.hidden = true;
            }

            var activeTab = document.querySelector('.bitstream-icon-category.is-active');
            this.activeIconCategory = activeTab ? (activeTab.getAttribute('data-category') || 'all') : 'all';

            var self = this;
            if (!this.iconsLoaded) {
                this.loadFontAwesomeIcons().then(function () {
                    self.filterAndRenderIcons();
                    if (self.iconSearch) self.iconSearch.focus();
                });
            } else {
                this.filterAndRenderIcons();
                setTimeout(function () {
                    if (self.iconSearch) self.iconSearch.focus();
                }, 50);
            }
        },

        closeIconPicker: function () {
            if (!this.iconModal) return;
            this.iconModal.hidden = true;
            this.iconModal.classList.remove('is-open');
            document.body.style.overflow = '';
        },

        loadFontAwesomeIcons: function () {
            var self = this;
            if (this.iconsLoaded) return Promise.resolve();

            if (this.iconGrid) {
                this.iconGrid.innerHTML =
                    '<div class="bitstream-icon-loading">' +
                    '<div class="bitstream-icon-spinner"></div>' +
                    '<span>Loading Font Awesome icons…</span>' +
                    '</div>';
            }

            var jsonUrl = '';
            if (this.container && this.container.getAttribute('data-json-url')) {
                jsonUrl = this.container.getAttribute('data-json-url');
            } else if (window.bitstream_ajax && window.bitstream_ajax.plugin_url) {
                jsonUrl = window.bitstream_ajax.plugin_url + 'assets/json/fontawesome6_free.json';
            } else {
                jsonUrl = '/wp-content/plugins/bitstream/assets/json/fontawesome6_free.json';
            }

            return fetch(jsonUrl)
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function (data) {
                    self.iconLibrary = {
                        brands: Array.isArray(data.brands) ? data.brands : [],
                        solid: Array.isArray(data.solid) ? data.solid : [],
                        regular: Array.isArray(data.regular) ? data.regular : []
                    };
                    self.iconsLoaded = true;
                    self.updateCategoryCounts();
                })
                .catch(function (err) {
                    console.warn('Could not load Font Awesome JSON, using fallback icon set:', err);
                    self.iconLibrary = self.getFallbackIcons();
                    self.iconsLoaded = true;
                    self.updateCategoryCounts();
                });
        },

        updateCategoryCounts: function () {
            var brandsLen = (this.iconLibrary && this.iconLibrary.brands) ? this.iconLibrary.brands.length : 0;
            var solidLen = (this.iconLibrary && this.iconLibrary.solid) ? this.iconLibrary.solid.length : 0;
            var regularLen = (this.iconLibrary && this.iconLibrary.regular) ? this.iconLibrary.regular.length : 0;
            var total = brandsLen + solidLen + regularLen;

            var countAll = document.getElementById('bitstream-cat-count-all');
            var countBrands = document.getElementById('bitstream-cat-count-brands');
            var countSolid = document.getElementById('bitstream-cat-count-solid');
            var countRegular = document.getElementById('bitstream-cat-count-regular');

            if (countAll) countAll.textContent = '(' + total.toLocaleString() + ')';
            if (countBrands) countBrands.textContent = '(' + brandsLen.toLocaleString() + ')';
            if (countSolid) countSolid.textContent = '(' + solidLen.toLocaleString() + ')';
            if (countRegular) countRegular.textContent = '(' + regularLen.toLocaleString() + ')';
        },

        filterAndRenderIcons: function () {
            if (!this.iconsLoaded || !this.iconGrid) return;

            var cat = this.activeIconCategory || 'all';
            var q = (this.activeIconSearch || '').toLowerCase().trim();

            var sourceList = [];
            if (cat === 'all') {
                sourceList = [].concat(this.iconLibrary.brands || [], this.iconLibrary.solid || [], this.iconLibrary.regular || []);
            } else if (this.iconLibrary[cat]) {
                sourceList = this.iconLibrary[cat];
            }

            if (!q) {
                this.currentFilteredIcons = sourceList;
            } else {
                this.currentFilteredIcons = sourceList.filter(function (cls) {
                    return cls.toLowerCase().indexOf(q) !== -1;
                });
            }

            this.renderedIconCount = 0;
            this.iconGrid.innerHTML = '';
            if (this.iconBody) this.iconBody.scrollTop = 0;

            if (this.currentFilteredIcons.length === 0) {
                this.iconGrid.innerHTML =
                    '<div class="bitstream-icon-empty">' +
                    '<i class="fa-solid fa-face-meh" style="font-size: 2rem; color: #94a3b8;"></i>' +
                    '<span>No icons found matching "' + this.escapeHtml(q) + '". You can type any Font Awesome class directly in the input.</span>' +
                    '</div>';
                if (this.iconStatus) this.iconStatus.textContent = '0 icons found';
                return;
            }

            this.renderNextChunk();
        },

        renderNextChunk: function () {
            if (!this.iconGrid || !this.currentFilteredIcons || this.renderedIconCount >= this.currentFilteredIcons.length) {
                return;
            }

            var nextIndex = Math.min(this.renderedIconCount + this.chunkSize, this.currentFilteredIcons.length);
            var chunk = this.currentFilteredIcons.slice(this.renderedIconCount, nextIndex);
            var currentVal = this.inputIcon ? this.inputIcon.value.trim() : '';

            var html = '';
            var self = this;
            for (var i = 0; i < chunk.length; i++) {
                var cls = chunk[i];
                var name = cls.replace(/^(fa[bsr]|fa-(brands|solid|regular))\s+fa-/, '').replace(/-/g, ' ');
                var isSelected = (cls === currentVal) ? ' is-selected' : '';
                html +=
                    '<button type="button" class="bitstream-icon-option' + isSelected + '" data-class="' + self.escapeAttr(cls) + '" title="' + self.escapeAttr(cls) + '">' +
                    '<i class="' + self.escapeAttr(cls) + '" aria-hidden="true"></i>' +
                    '<small>' + self.escapeHtml(name) + '</small>' +
                    '</button>';
            }

            this.iconGrid.insertAdjacentHTML('beforeend', html);
            this.renderedIconCount = nextIndex;

            if (this.iconStatus) {
                this.iconStatus.textContent = 'Showing ' + this.renderedIconCount.toLocaleString() + ' of ' + this.currentFilteredIcons.length.toLocaleString() + ' icons';
            }
        },

        saveMapping: function () {
            var self = this;
            var mode = this.modeInput ? this.modeInput.value : 'add';
            var rawDomain = this.inputDomain ? this.inputDomain.value : '';
            var domain = this.normalizeDomain(rawDomain);
            var label = this.inputLabel ? this.inputLabel.value.trim() : '';
            var icon = this.inputIcon ? this.inputIcon.value.trim() : '';
            var origDomain = this.origDomainInput ? this.origDomainInput.value.trim() : '';

            if (!domain) {
                alert('Please enter a valid domain name (e.g. example.com).');
                if (this.inputDomain) this.inputDomain.focus();
                return;
            }

            if (!label) {
                alert('Please enter an action label (e.g. shared a link).');
                if (this.inputLabel) this.inputLabel.focus();
                return;
            }

            if (!icon) {
                alert('Please choose or enter an icon class.');
                if (this.inputIcon) this.inputIcon.focus();
                return;
            }

            // Disable button during AJAX
            if (this.drawerSaveBtn) {
                this.drawerSaveBtn.disabled = true;
                if (this.drawerSaveText) this.drawerSaveText.textContent = 'Saving…';
            }

            var formData = new FormData();
            formData.append('action', 'bitstream_save_rebit_mapping');
            formData.append('nonce', this.getNonce());
            formData.append('mode', mode);
            formData.append('domain', domain);
            formData.append('label', label);
            formData.append('icon', icon);
            formData.append('original_domain', origDomain);

            fetch(this.getAjaxUrl(), {
                method: 'POST',
                body: formData
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (self.drawerSaveBtn) {
                        self.drawerSaveBtn.disabled = false;
                        if (self.drawerSaveText) {
                            self.drawerSaveText.textContent = (mode === 'edit' ? 'Update Mapping' : 'Save Mapping');
                        }
                    }

                    if (!data || !data.success) {
                        alert(data && data.data && data.data.message ? data.data.message : 'Error saving mapping.');
                        return;
                    }

                    var mapping = data.data.mapping;
                    if (mode === 'edit') {
                        self.updateRowInList(origDomain, mapping);
                    } else {
                        self.appendRowToList(mapping, true);
                    }
                    self.updatePresetPillsMappedStatus();

                    self.closeDrawer();
                    self.showToast(data.data.message || 'Mapping saved successfully!');
                })
                .catch(function (err) {
                    console.error('Error saving mapping:', err);
                    if (self.drawerSaveBtn) {
                        self.drawerSaveBtn.disabled = false;
                        if (self.drawerSaveText) {
                            self.drawerSaveText.textContent = (mode === 'edit' ? 'Update Mapping' : 'Save Mapping');
                        }
                    }
                    alert('Network error while saving mapping.');
                });
        },

        addPreset: function (presetKey, pillElement) {
            var self = this;
            if (pillElement) {
                pillElement.disabled = true;
                pillElement.classList.add('is-loading');
            }

            var formData = new FormData();
            formData.append('action', 'bitstream_add_rebit_preset');
            formData.append('nonce', this.getNonce());
            formData.append('preset_key', presetKey);

            fetch(this.getAjaxUrl(), {
                method: 'POST',
                body: formData
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        if (pillElement) {
                            pillElement.disabled = false;
                            pillElement.classList.remove('is-loading');
                        }
                        alert(data && data.data && data.data.message ? data.data.message : 'Error adding preset.');
                        return;
                    }

                    // Animate removing pill from shelf
                    if (pillElement) {
                        pillElement.style.opacity = '0';
                        pillElement.style.transform = 'scale(0.8)';
                        setTimeout(function () {
                            pillElement.remove();
                            self.checkPresetsShelf();
                        }, 200);
                    }

                    var mapping = data.data.mapping;
                    self.appendRowToList(mapping, true);
                    self.showToast(data.data.message || 'Preset added successfully!');
                })
                .catch(function (err) {
                    console.error('Error adding preset:', err);
                    if (pillElement) {
                        pillElement.disabled = false;
                        pillElement.classList.remove('is-loading');
                    }
                    alert('Network error while adding preset.');
                });
        },

        stageDelete: function (row) {
            var self = this;

            // If another delete is pending, commit it immediately first
            if (this.pendingDeletedRow && this.pendingDeletedData) {
                this.commitDelete(this.pendingDeletedData.domain);
            }

            var domain = row.getAttribute('data-domain');
            var label = row.getAttribute('data-label');
            var icon = row.getAttribute('data-icon');

            this.pendingDeletedRow = row;
            this.pendingDeletedData = { domain: domain, label: label, icon: icon };

            // Hide the row visually with animation
            row.style.transition = 'all 0.25s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-16px)';
            setTimeout(function () {
                row.hidden = true;
                self.updateCount();
                self.updatePresetPillsMappedStatus();
            }, 250);

            // Show 5-second Undo toast
            this.showUndoToast('Removed ' + domain + ' mapping');

            // Timer to commit permanently
            clearTimeout(this.undoTimeout);
            this.undoTimeout = setTimeout(function () {
                self.commitDelete(domain);
            }, 5000);
        },

        cancelPendingDelete: function () {
            clearTimeout(this.undoTimeout);
            this.hideUndoToast();

            if (this.pendingDeletedRow) {
                this.pendingDeletedRow.hidden = false;
                var row = this.pendingDeletedRow;
                setTimeout(function () {
                    row.style.opacity = '1';
                    row.style.transform = 'translateX(0)';
                }, 10);
                this.updateCount();
                this.updatePresetPillsMappedStatus();
            }

            this.pendingDeletedRow = null;
            this.pendingDeletedData = null;
        },

        commitDelete: function (domain) {
            var self = this;
            clearTimeout(this.undoTimeout);
            this.hideUndoToast();

            var dataToDelete = this.pendingDeletedData;
            var rowToDelete = this.pendingDeletedRow;

            this.pendingDeletedRow = null;
            this.pendingDeletedData = null;

            if (rowToDelete) {
                rowToDelete.remove();
                this.updateCount();
                this.updatePresetPillsMappedStatus();
            }

            var formData = new FormData();
            formData.append('action', 'bitstream_delete_rebit_mapping');
            formData.append('nonce', this.getNonce());
            formData.append('domain', domain);

            fetch(this.getAjaxUrl(), {
                method: 'POST',
                body: formData
            })
                .then(function (res) { return res.json(); })
                .then(function (resData) {
                    if (!resData || !resData.success) {
                        console.warn('Failed to delete mapping on server:', resData);
                    }
                })
                .catch(function (err) {
                    console.error('Error committing delete mapping:', err);
                });
        },

        resetToDefaults: function () {
            if (!confirm('Are you sure you want to reset all ReBit mappings to factory defaults? Any custom domains will be replaced.')) {
                return;
            }

            var self = this;
            var formData = new FormData();
            formData.append('action', 'bitstream_reset_rebit_mappings');
            formData.append('nonce', this.getNonce());

            fetch(this.getAjaxUrl(), {
                method: 'POST',
                body: formData
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        alert(data && data.data && data.data.message ? data.data.message : 'Error resetting mappings.');
                        return;
                    }

                    // Rebuild rows from server list
                    if (self.listContainer) {
                        self.listContainer.innerHTML = '';
                        var mappings = data.data.mappings || [];
                        mappings.forEach(function (m) {
                            self.appendRowToList(m, false);
                        });
                        self.updateCount();
                        self.updatePresetPillsMappedStatus();
                    }

                    self.showToast('ReBit mappings reset to factory defaults.');
                })
                .catch(function (err) {
                    console.error('Error resetting mappings:', err);
                    alert('Network error while resetting mappings.');
                });
        },

        appendRowToList: function (mapping, highlight) {
            if (!this.listContainer) return;

            // Remove empty placeholder if present
            var empty = document.getElementById('bitstream-rebit-empty');
            if (empty) empty.remove();

            var row = document.createElement('div');
            row.className = 'bitstream-rebit-item';
            row.setAttribute('data-domain', mapping.domain);
            row.setAttribute('data-label', mapping.label);
            row.setAttribute('data-icon', mapping.icon);

            row.innerHTML =
                '<div class="bitstream-rebit-item-badge">' +
                '<i class="' + this.escapeAttr(mapping.icon) + '" aria-hidden="true"></i>' +
                '</div>' +
                '<div class="bitstream-rebit-item-details">' +
                '<span class="bitstream-rebit-item-label">' + this.escapeHtml(mapping.label) + '</span>' +
                '<span class="bitstream-rebit-item-domain">' + this.escapeHtml(mapping.domain) + '</span>' +
                '</div>' +
                '<div class="bitstream-rebit-item-actions">' +
                '<button type="button" class="bitstream-rebit-action-btn bitstream-rebit-action-edit" title="Edit mapping" aria-label="Edit">' +
                '<i class="fa-solid fa-pencil" aria-hidden="true"></i>' +
                '</button>' +
                '<button type="button" class="bitstream-rebit-action-btn bitstream-rebit-action-delete" title="Delete mapping" aria-label="Delete">' +
                '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>' +
                '</button>' +
                '</div>';

            if (highlight) {
                row.style.opacity = '0';
                row.style.transform = 'translateY(10px)';
                this.listContainer.insertBefore(row, this.listContainer.firstChild);
                setTimeout(function () {
                    row.style.transition = 'all 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                    row.classList.add('is-highlighted');
                    setTimeout(function () { row.classList.remove('is-highlighted'); }, 1200);
                }, 20);
            } else {
                this.listContainer.appendChild(row);
            }

            this.updateCount();
        },

        updateRowInList: function (origDomain, newMapping) {
            if (!this.listContainer) return;
            var rows = this.listContainer.querySelectorAll('.bitstream-rebit-item');
            var targetRow = null;

            rows.forEach(function (r) {
                if (r.getAttribute('data-domain') === origDomain) {
                    targetRow = r;
                }
            });

            if (targetRow) {
                targetRow.setAttribute('data-domain', newMapping.domain);
                targetRow.setAttribute('data-label', newMapping.label);
                targetRow.setAttribute('data-icon', newMapping.icon);

                var badge = targetRow.querySelector('.bitstream-rebit-item-badge i');
                var label = targetRow.querySelector('.bitstream-rebit-item-label');
                var domain = targetRow.querySelector('.bitstream-rebit-item-domain');

                if (badge) badge.className = newMapping.icon;
                if (label) label.textContent = newMapping.label;
                if (domain) domain.textContent = newMapping.domain;

                targetRow.classList.add('is-highlighted');
                setTimeout(function () { targetRow.classList.remove('is-highlighted'); }, 1200);
            }
        },

        removePresetPillByDomain: function (domain) {
            if (!this.presetsPills) return;
            var pills = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
            var norm = this.normalizeDomain(domain);
            pills.forEach(function (pill) {
                var pDomain = pill.getAttribute('data-domain');
                if (pDomain && pDomain.toLowerCase() === norm) {
                    pill.remove();
                }
            });
            this.checkPresetsShelf();
        },

        checkPresetsShelf: function () {
            if (!this.presetsShelf || !this.presetsPills) return;
            var remaining = this.presetsPills.querySelectorAll('.bitstream-preset-pill');
            this.presetsShelf.hidden = (remaining.length === 0);
        },

        updateCount: function () {
            var items = this.listContainer ? this.listContainer.querySelectorAll('.bitstream-rebit-item:not([hidden])') : [];
            var totalItems = this.listContainer ? this.listContainer.querySelectorAll('.bitstream-rebit-item') : [];
            if (this.countBadge) {
                this.countBadge.textContent = totalItems.length;
            }

            if (totalItems.length === 0 && !document.getElementById('bitstream-rebit-empty')) {
                var empty = document.createElement('div');
                empty.className = 'bitstream-rebit-empty';
                empty.id = 'bitstream-rebit-empty';
                empty.innerHTML =
                    '<div class="bitstream-rebit-empty-icon"><i class="fa-solid fa-sitemap" aria-hidden="true"></i></div>' +
                    '<h4>No mappings configured yet</h4>' +
                    '<p>Add your favorite sites or import defaults to show branded icons when sharing links.</p>' +
                    '<button type="button" class="bitstream-rebit-btn-import-defaults" id="bitstream-rebit-btn-empty-import">' +
                    '<i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>' +
                    '<span>Import Default Mappings</span>' +
                    '</button>';
                this.listContainer.appendChild(empty);

                var emptyBtn = document.getElementById('bitstream-rebit-btn-empty-import');
                var self = this;
                if (emptyBtn) {
                    emptyBtn.addEventListener('click', function () {
                        self.resetToDefaults();
                    });
                }
            }
        },

        showUndoToast: function (text) {
            if (!this.undoToast) return;
            if (this.undoText) this.undoText.textContent = text;
            this.undoToast.hidden = false;
            this.undoToast.classList.add('is-visible');
        },

        hideUndoToast: function () {
            if (!this.undoToast) return;
            this.undoToast.classList.remove('is-visible');
            this.undoToast.hidden = true;
        },

        showToast: function (message) {
            var toast = document.createElement('div');
            toast.className = 'bitstream-rebit-status-toast';
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(function () {
                toast.classList.add('is-visible');
            }, 10);

            setTimeout(function () {
                toast.classList.remove('is-visible');
                setTimeout(function () { toast.remove(); }, 300);
            }, 3000);
        },

        escapeHtml: function (str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        escapeAttr: function (str) {
            return this.escapeHtml(str);
        }
    };

    window.BitStream.Settings = Settings;
})();
