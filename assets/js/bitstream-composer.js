(function () {
    // Export namespace
    window.BitStream = window.BitStream || {};
    window.BitStream.Composer = {
        init: function () {
            document.querySelectorAll('.bitstream-composer').forEach(composerRoot => {
                const form = composerRoot.querySelector('.bitstream-sidebar-composer-form');
                if (!form) return;

                const statusEl = composerRoot.querySelector('.bitstream-sidebar-composer-status');
                const submitBtn = form.querySelector('.bitstream-composer-submit');
                const textarea = form.querySelector('#bitstream-quick-bit-content');
                const submitNonce = composerRoot.dataset.submitNonce || '';

                // Mobile only: grow textarea as user types (unconditionally bind, check inside)
                if (textarea) {
                    textarea.addEventListener('input', () => {
                        if (window.matchMedia('(max-width: 1023px)').matches) {
                            if (typeof window.bsMobileAutoResize === 'function') {
                                window.bsMobileAutoResize(textarea);
                            }
                        } else {
                            const flexItem = textarea.closest('.bs-textarea-container') || textarea;
                            flexItem.style.removeProperty('flex-basis');
                        }
                    });
                    
                    if (window.matchMedia('(max-width: 1023px)').matches && textarea.value) {
                        if (typeof window.bsMobileAutoResize === 'function') {
                            window.bsMobileAutoResize(textarea);
                        }
                    }
                }

                // Hidden inputs
                const hAttachmentId = form.querySelector('#bitstream-composer-attachment-id');
                const hAttachmentIds = form.querySelector('#bitstream-composer-attachment-ids');
                const modalAttachmentId = composerRoot.querySelector('#bitstream-composer-modal-media-attachment-id');
                const modalAttachmentIds = composerRoot.querySelector('#bitstream-composer-modal-media-attachment-ids');
                if (hAttachmentId && modalAttachmentId && hAttachmentId.value && !modalAttachmentId.value) {
                    modalAttachmentId.value = hAttachmentId.value;
                }
                if (hAttachmentIds && modalAttachmentIds && hAttachmentIds.value && !modalAttachmentIds.value) {
                    modalAttachmentIds.value = hAttachmentIds.value;
                }
                const hRebitUrl = form.querySelector('#bitstream-composer-rebit-url');
                const hRebitOgTitle = form.querySelector('#bitstream-composer-rebit-og-title');
                const hRebitOgDesc = form.querySelector('#bitstream-composer-rebit-og-desc');
                const hRebitOgImage = form.querySelector('#bitstream-composer-rebit-og-image');
                const hRebitOgImageRemoved = form.querySelector('#bitstream-composer-rebit-og-image-removed');
                const hRebitAttachmentId = form.querySelector('#bitstream-composer-rebit-attachment-id');
                const hScheduleEnabled = form.querySelector('#bitstream-composer-schedule-enabled');
                const hScheduleDatetime = form.querySelector('#bitstream-composer-schedule-datetime');
                const hEditPostId = form.querySelector('#bitstream-composer-edit-post-id');
                const hMoodEmoji = form.querySelector('#bitstream-composer-mood-emoji');
                const hMoodEmotion = form.querySelector('#bitstream-composer-mood-emotion');
                const hQuotePostId = form.querySelector('#bitstream-composer-quote-post-id');
                let renderRebitLivePreview, updateModalImagePreview;

                // Preview containers
                const previewArea = form.querySelector('.bitstream-composer-preview-area');
                const previewCarousel = form.querySelector('.bitstream-composer-preview-carousel');
                const previewDotsEl = form.querySelector('.bitstream-composer-preview-dots');
                const previewRebit = form.querySelector('.bitstream-composer-preview-rebit');
                const previewRebitCard = form.querySelector('.bitstream-composer-preview-rebit-card');
                const previewMedia = form.querySelector('.bitstream-composer-preview-media');
                const previewMediaThumb = form.querySelector('.bitstream-composer-preview-media-thumb');
                const previewSchedule = form.querySelector('.bitstream-composer-preview-schedule');
                const previewScheduleDate = form.querySelector('.bitstream-composer-preview-schedule-date');
                const previewMood = form.querySelector('.bitstream-composer-preview-mood');
                const previewMoodText = form.querySelector('.bitstream-composer-preview-mood-text');

                // Mood modal elements
                const moodModal = composerRoot.querySelector('.bitstream-composer-modal-mood');
                let customMoods = (window.bitstream_ajax && bitstream_ajax.custom_moods) || [];
                let activeEditMoodForm = null;
                const previewDraft = null;
                const previewDraftLabel = null;

                // Carousel scroll listener reference so we can remove it when re-syncing
                let _carouselScrollHandler = null;

                // Save Draft Button
                const composerSaveDraftBtn = null;
                const composerSaveDraftActionBtn = form.querySelector('.bitstream-composer-save-draft-action');

                function setStatus(msg, isError = false) {
                    if (!statusEl) return;
                    statusEl.textContent = msg;
                    statusEl.style.color = isError ? '#cc0000' : '#2c6e49';

                    // Locate active open modal
                    const activeModal = document.querySelector('.bitstream-composer-modal:not([hidden]), .bitstream-cropper-modal:not([hidden]), .bitstream-rebit-editor-modal:not([hidden])');
                    if (activeModal) {
                        const bodyEl = activeModal.querySelector('.bitstream-composer-modal-body, .bitstream-cropper-body, .bitstream-rebit-editor-body');
                        if (bodyEl) {
                            let modalStatusEl = bodyEl.querySelector('.bitstream-modal-status');
                            if (!modalStatusEl) {
                                modalStatusEl = document.createElement('div');
                                modalStatusEl.className = 'bitstream-modal-status bitstream-composer-status';
                                modalStatusEl.setAttribute('aria-live', 'polite');
                                bodyEl.insertBefore(modalStatusEl, bodyEl.firstChild);
                            }
                            modalStatusEl.textContent = msg;
                            modalStatusEl.classList.toggle('is-error', isError);
                            modalStatusEl.classList.toggle('is-success', !isError && !!msg);

                            if (!msg) {
                                modalStatusEl.textContent = '';
                                modalStatusEl.className = 'bitstream-modal-status bitstream-composer-status';
                            }
                        }
                    }

                    if (!msg) {
                        document.querySelectorAll('.bitstream-modal-status').forEach(el => {
                            el.textContent = '';
                            el.className = 'bitstream-modal-status bitstream-composer-status';
                        });
                    }
                }

                function syncPreviewArea() {
                    const hasRebit = previewRebit && !previewRebit.hidden;
                    const hasMedia = previewMedia && !previewMedia.hidden;
                    const hasSched = previewSchedule && !previewSchedule.hidden;
                    const hasMood = previewMood && !previewMood.hidden;
                    const hasDraft = previewDraft && !previewDraft.hidden;
                    if (previewArea) previewArea.hidden = !(hasRebit || hasMedia || hasSched || hasDraft || hasMood);
                    if (textarea) textarea.required = !(hasRebit || hasMedia || hasMood);

                    // Carousel dot indicators — only on mobile/tablet (<1024px)
                    if (!previewCarousel || !previewDotsEl) return;

                    // Remove old scroll listener before potentially re-attaching
                    if (_carouselScrollHandler) {
                        previewCarousel.removeEventListener('scroll', _carouselScrollHandler);
                        _carouselScrollHandler = null;
                    }

                    const isMobileCarousel = window.innerWidth < 1024;
                    const bothPresent = hasRebit && hasMedia;

                    if (!isMobileCarousel || !bothPresent) {
                        // Desktop or only one card: hide dots, no snap needed
                        previewDotsEl.hidden = true;
                        previewDotsEl.innerHTML = '';
                        return;
                    }

                    // Both cards present on mobile/tablet: build dots
                    const cards = [previewRebit, previewMedia];
                    const labels = ['Rebit', 'Media'];

                    previewDotsEl.innerHTML = '';
                    previewDotsEl.hidden = false;
                    previewDotsEl.setAttribute('aria-hidden', 'true');

                    cards.forEach(function (card, i) {
                        const dot = document.createElement('button');
                        dot.type = 'button';
                        dot.className = 'bitstream-composer-preview-dot' + (i === 0 ? ' is-active' : '');
                        dot.setAttribute('aria-label', 'Go to ' + labels[i]);
                        dot.addEventListener('click', function () {
                            previewCarousel.scrollTo({ left: card.offsetLeft, behavior: 'smooth' });
                        });
                        previewDotsEl.appendChild(dot);
                    });

                    // Keep active dot in sync as user swipes
                    const dots = previewDotsEl.querySelectorAll('.bitstream-composer-preview-dot');
                    let _rafId = null;
                    _carouselScrollHandler = function () {
                        if (_rafId) return;
                        _rafId = requestAnimationFrame(function () {
                            _rafId = null;
                            const scrollLeft = previewCarousel.scrollLeft;
                            const width = previewCarousel.offsetWidth;
                            const activeIndex = width > 0 ? Math.round(scrollLeft / width) : 0;
                            dots.forEach(function (d, i) {
                                d.classList.toggle('is-active', i === activeIndex);
                            });
                        });
                    };
                    previewCarousel.addEventListener('scroll', _carouselScrollHandler, { passive: true });
                }

                // Modal open/close helpers
                function openModal(name) {
                    setStatus('');
                    const modal = composerRoot.querySelector('.bitstream-composer-modal-' + name);
                    if (modal) {
                        modal.hidden = false;
                        if (activeEditMoodForm) {
                            composerRoot.dataset.fromEditModal = 'true';
                        } else {
                            delete composerRoot.dataset.fromEditModal;
                        }
                        const isMobile = window.innerWidth < 1024;
                        if (isMobile) {
                            composerRoot.hidden = false;
                        }

                        if (name === 'drafts' || name === 'scheduled-list') {
                            if (typeof window.parseEmojis === 'function') {
                                window.parseEmojis(modal);
                            }
                        }

                        if (name === 'settings') {
                            const url = new URL(window.location.href);
                            url.searchParams.set('show_settings', '1');
                            window.history.replaceState({}, '', url.toString());
                        }

                        if (name === 'media') {
                            const mMediaPreview = modal.querySelector('#bitstream-composer-modal-media-preview');
                            if (mMediaPreview && previewMediaThumb) {
                                const currentAttachments = typeof window.getExistingAttachments === 'function' ? window.getExistingAttachments(previewMediaThumb) : [];
                                if (typeof window.updateAttachmentsList === 'function') {
                                    window.updateAttachmentsList(mMediaPreview, currentAttachments);
                                }
                            }
                        }

                        if (name === 'rebit') {
                            const mRebitUrl = modal.querySelector('#bitstream-composer-modal-rebit-url');
                            const mRebitFetch = modal.querySelector('.bitstream-composer-rebit-fetch');
                            const rebitMetaModal = composerRoot.querySelector('.bitstream-composer-modal-rebit-meta');

                            if (hRebitUrl && mRebitUrl) {
                                mRebitUrl.value = hRebitUrl.value || '';
                            }

                            if (hRebitUrl && hRebitUrl.value) {
                                const mRebitTitle = rebitMetaModal ? rebitMetaModal.querySelector('#bitstream-composer-modal-rebit-og-title') : null;
                                const mRebitDesc = rebitMetaModal ? rebitMetaModal.querySelector('#bitstream-composer-modal-rebit-og-desc') : null;

                                if (mRebitTitle && hRebitOgTitle) {
                                    mRebitTitle.value = hRebitOgTitle.value || '';
                                }
                                if (mRebitDesc && hRebitOgDesc) {
                                    mRebitDesc.value = hRebitOgDesc.value || '';
                                }

                                if (mRebitFetch) {
                                    mRebitFetch.classList.add('is-edit-mode');
                                    mRebitFetch.textContent = 'Edit metadata';
                                }

                                if (renderRebitLivePreview) {
                                    renderRebitLivePreview(hRebitUrl.value);
                                }
                            } else {
                                if (mRebitFetch) {
                                    mRebitFetch.classList.remove('is-edit-mode');
                                    mRebitFetch.textContent = 'Fetch metadata';
                                }
                                const mRebitPreviewRoot = modal.querySelector('.bitstream-composer-rebit-live-preview');
                                const mRebitPreviewCard = modal.querySelector('.bitstream-composer-rebit-live-preview-card');
                                if (mRebitPreviewRoot) mRebitPreviewRoot.hidden = true;
                                if (mRebitPreviewCard) mRebitPreviewCard.innerHTML = '';
                            }
                        }

                        if (name === 'rebit-meta') {
                            if (typeof updateModalImagePreview === 'function') {
                                updateModalImagePreview();
                            }
                        }

                        if (name === 'mood') {
                            const currentEmoji = activeEditMoodForm 
                                ? (activeEditMoodForm.querySelector('.bs-edit-mood-emoji').value || '') 
                                : (hMoodEmoji.value || '');
                            const currentEmotion = activeEditMoodForm 
                                ? (activeEditMoodForm.querySelector('.bs-edit-mood-emotion').value || '') 
                                : (hMoodEmotion.value || '');

                            modal.querySelectorAll('.bitstream-mood-btn').forEach(btn => btn.classList.remove('is-active'));
                            
                            const customEmojiInput = modal.querySelector('#bitstream-mood-custom-emoji');
                            const customEmotionInput = modal.querySelector('#bitstream-mood-custom-emotion');
                            setCustomEmojiDisplay('');
                            if (customEmotionInput) customEmotionInput.value = '';

                            let foundPredefined = false;
                            if (currentEmotion) {
                                modal.querySelectorAll('.bitstream-mood-btn').forEach(btn => {
                                    if (btn.dataset.emotion.toLowerCase() === currentEmotion.toLowerCase() && btn.dataset.emoji === currentEmoji) {
                                        btn.classList.add('is-active');
                                        foundPredefined = true;
                                    }
                                });

                                if (!foundPredefined) {
                                    setCustomEmojiDisplay(currentEmoji);
                                    if (customEmotionInput) customEmotionInput.value = currentEmotion;
                                }
                            }

                            renderSavedMoods();
                        }
                    }
                    if (typeof window.bitstreamSyncBottomNav === 'function') {
                        window.bitstreamSyncBottomNav();
                    }
                }
                function clearComposer() {
                    if (form) form.reset();
                    if (typeof window.closeAllBsEmojiPickers === 'function') {
                        window.closeAllBsEmojiPickers();
                    }
                    if (hAttachmentId) hAttachmentId.value = '';
                    if (hAttachmentIds) hAttachmentIds.value = '';
                    if (previewMediaThumb) previewMediaThumb.innerHTML = '';
                    if (previewMedia) previewMedia.hidden = true;
                    if (hRebitUrl) hRebitUrl.value = '';
                    if (hRebitOgTitle) hRebitOgTitle.value = '';
                    if (hRebitOgDesc) hRebitOgDesc.value = '';
                    if (hRebitOgImage) hRebitOgImage.value = '';
                    if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                    if (hRebitAttachmentId) hRebitAttachmentId.value = '';
                    if (previewRebitCard) previewRebitCard.innerHTML = '';
                    if (previewRebit) previewRebit.hidden = true;
                    if (previewArea) previewArea.hidden = true;
                    if (textarea) textarea.value = '';
                    if (hScheduleEnabled) hScheduleEnabled.value = '0';
                    if (hScheduleDatetime) hScheduleDatetime.value = '';
                    if (previewSchedule) previewSchedule.hidden = true;
                    if (previewDraft) previewDraft.hidden = true;
                    if (hMoodEmoji) hMoodEmoji.value = '';
                    if (hMoodEmotion) hMoodEmotion.value = '';
                    if (previewMood) previewMood.hidden = true;
                    activeEditMoodForm = null;
                    if (hEditPostId) hEditPostId.value = '0';
                    form.dataset.composerType = 'bit';
                    if (submitBtn) submitBtn.textContent = 'Post Bit';
                    if (composerSaveDraftActionBtn) {
                        composerSaveDraftActionBtn.style.display = 'block';
                    }
                    setStatus('');
                    syncPreviewArea();
                }

                function closeModal(name, keepPosterOpen = false) {
                    setStatus('');
                    if (typeof window.closeAllBsEmojiPickers === 'function') {
                        window.closeAllBsEmojiPickers();
                    }
                    if (name === 'composer') {
                        const content = textarea ? textarea.value.trim() : '';
                        const hasRebit = hRebitUrl && hRebitUrl.value.trim();
                        const hasMedia = hAttachmentId && parseInt(hAttachmentId.value || '0', 10) > 0;
                        const hasMood = hMoodEmotion && hMoodEmotion.value.trim();
                        const hasQuote = hQuotePostId && parseInt(hQuotePostId.value || '0', 10) > 0;
                        if (content || hasRebit || hasMedia || hasMood || hasQuote) {
                            if (typeof window.showDiscardConfirmation === 'function') {
                                window.showDiscardConfirmation('Are you sure you want to discard your draft?', () => {
                                    composerFormIsDirty = false;
                                    composerRoot.hidden = true;
                                    delete composerRoot.dataset.quickActionSource;
                                    composerRoot.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                                    clearComposer();
                                }, () => {
                                    if (composerSaveDraftActionBtn) {
                                        composerSaveDraftActionBtn.click();
                                    }
                                });
                            }
                            return;
                        }
                        composerRoot.hidden = true;
                        delete composerRoot.dataset.quickActionSource;
                        composerRoot.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                    } else {
                        const modal = composerRoot.querySelector('.bitstream-composer-modal-' + name);
                        if (modal) {
                            const isMobile = window.innerWidth < 1024;
                            const isFromEditModal = composerRoot.dataset.fromEditModal === 'true';

                            if (name === 'mood') {
                                activeEditMoodForm = null;
                            }

                            if (name === 'rebit') {
                                const mRebitUrl = modal.querySelector('#bitstream-composer-modal-rebit-url');
                                const urlVal = mRebitUrl ? mRebitUrl.value.trim() : '';
                                const currentUrl = hRebitUrl ? hRebitUrl.value.trim() : '';
                                if (urlVal && urlVal !== currentUrl) {
                                    if (typeof window.showDiscardConfirmation === 'function') {
                                        window.showDiscardConfirmation('Are you sure you want to discard this ReBit link?', () => {
                                            modal.hidden = true;
                                            const quickActionSource = composerRoot.dataset.quickActionSource || '';
                                            const shouldCloseComposer = isMobile && !keepPosterOpen && quickActionSource === 'new-rebit';
                                            if (shouldCloseComposer) {
                                                composerRoot.hidden = true;
                                                delete composerRoot.dataset.quickActionSource;
                                            }
                                        });
                                    }
                                    return;
                                }
                            }
                            modal.hidden = true;

                            if (isFromEditModal) {
                                if (isMobile) {
                                    composerRoot.hidden = true;
                                }
                                delete composerRoot.dataset.fromEditModal;
                            } else {
                                const quickActionSource = composerRoot.dataset.quickActionSource || '';
                                const shouldCloseComposer = isMobile && !keepPosterOpen && (
                                    name === 'drafts'
                                    || name === 'scheduled-list'
                                    || name === 'settings'
                                    || name === 'about'
                                    || (name === 'rebit' && quickActionSource === 'new-rebit')
                                );
                                if (shouldCloseComposer) {
                                    composerRoot.hidden = true;
                                    delete composerRoot.dataset.quickActionSource;
                                }
                            }

                            if (name === 'settings') {
                                const url = new URL(window.location.href);
                                url.searchParams.delete('show_settings');
                                url.searchParams.delete('settings_tab');
                                window.history.replaceState({}, '', url.toString());
                            }
                        }
                    }
                    if (typeof window.bitstreamSyncBottomNav === 'function') {
                        window.bitstreamSyncBottomNav();
                    }
                }

                // Wire all modal triggers (action buttons, bottom nav items, drawer rows, right rail items)
                document.addEventListener('click', (e) => {
                    const trigger = e.target.closest('[data-composer-modal-trigger], [data-composer-modal]');
                    if (!trigger || trigger.hasAttribute('data-composer-modal-close')) return;

                    const modalName = trigger.dataset.composerModalTrigger || trigger.dataset.composerModal;
                    if (modalName) {
                        e.preventDefault();
                        if (typeof window.bitstreamCloseMore === 'function') {
                            window.bitstreamCloseMore();
                        }
                        if (typeof window.bitstreamCloseSearch === 'function') {
                            window.bitstreamCloseSearch();
                        }
                        openModal(modalName);
                    }
                });

                // Wire all close triggers
                composerRoot.querySelectorAll('[data-composer-modal-close]').forEach(el => {
                    el.addEventListener('click', () => closeModal(el.dataset.composerModalClose));
                });

                // Wire preview edit buttons
                composerRoot.querySelectorAll('.bitstream-composer-preview-edit[data-composer-edit]').forEach(btn => {
                    btn.addEventListener('click', () => openModal(btn.dataset.composerEdit));
                });

                // Wire preview remove buttons
                composerRoot.querySelectorAll('.bitstream-composer-preview-remove[data-composer-remove]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const type = btn.dataset.composerRemove;
                        if (type === 'rebit') {
                            if (hRebitUrl) hRebitUrl.value = '';
                            if (hRebitOgTitle) hRebitOgTitle.value = '';
                            if (hRebitOgDesc) hRebitOgDesc.value = '';
                            if (hRebitOgImage) hRebitOgImage.value = '';
                            if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                            if (hRebitAttachmentId) hRebitAttachmentId.value = '';
                            if (previewRebit) previewRebit.hidden = true;
                            if (previewRebitCard) previewRebitCard.innerHTML = '';
                            form.dataset.composerType = 'bit';
                            if (textarea) textarea.required = true;
                        }
                        if (type === 'media') {
                            if (previewMediaThumb) {
                                if (typeof window.updateAttachmentsList === 'function') {
                                    window.updateAttachmentsList(previewMediaThumb, []);
                                }
                            } else {
                                if (hAttachmentId) hAttachmentId.value = '';
                                if (hAttachmentIds) hAttachmentIds.value = '';
                                if (previewMedia) previewMedia.hidden = true;
                            }
                        }
                        if (type === 'schedule') {
                            if (hScheduleEnabled) hScheduleEnabled.value = '0';
                            if (hScheduleDatetime) hScheduleDatetime.value = '';
                            if (previewSchedule) previewSchedule.hidden = true;
                            if (submitBtn) {
                                const isEdit = hEditPostId && hEditPostId.value !== '0';
                                if (isEdit) {
                                    submitBtn.textContent = 'Publish Draft';
                                } else {
                                    const isRebit = form.dataset.composerType === 'rebit';
                                    submitBtn.textContent = isRebit ? 'Publish Rebit' : 'Post Bit';
                                }
                            }
                        }
                        if (type === 'draft') {
                            if (hEditPostId) hEditPostId.value = '0';
                            if (previewDraft) previewDraft.hidden = true;
                            if (composerSaveDraftBtn) composerSaveDraftBtn.style.display = 'none';
                            if (submitBtn) {
                                const isRebit = form.dataset.composerType === 'rebit';
                                submitBtn.textContent = isRebit ? 'Publish Rebit' : 'Post Bit';
                            }
                        }
                        if (type === 'mood') {
                            if (hMoodEmoji) hMoodEmoji.value = '';
                            if (hMoodEmotion) hMoodEmotion.value = '';
                            if (previewMood) previewMood.hidden = true;
                            activeEditMoodForm = null;
                        }
                        syncPreviewArea();
                    });
                });

                // Emoji Picker Factory Implementation
                (function () {
                    let _emojiData = null;
                    let _emojiDataPromise = null;
                    const _activePickers = [];

                    window.closeAllBsEmojiPickers = function () {
                        _activePickers.forEach(p => p.close());
                    };

                    const CATEGORY_ORDER = [
                        'Smileys & Emotion',
                        'People & Body',
                        'Animals & Nature',
                        'Food & Drink',
                        'Travel & Places',
                        'Activities',
                        'Objects',
                        'Symbols',
                        'Flags',
                    ];

                    const CATEGORY_ICONS = {
                        'Smileys & Emotion':  '1F600',
                        'People & Body':      '1F44B',
                        'Animals & Nature':   '1F436',
                        'Food & Drink':       '1F355',
                        'Travel & Places':    '2708-FE0F',
                        'Activities':         '26BD',
                        'Objects':            '1F4A1',
                        'Symbols':            '267E-FE0F',
                        'Flags':              '1F3C1',
                    };

                    const TONE_COLORS = [
                        { tone: '',      color: '#FFCC22', label: 'Default' },
                        { tone: '1F3FB', color: '#FFDBB4', label: 'Light' },
                        { tone: '1F3FC', color: '#C68642', label: 'Medium-Light' },
                        { tone: '1F3FD', color: '#8D5524', label: 'Medium' },
                        { tone: '1F3FE', color: '#5C3317', label: 'Medium-Dark' },
                        { tone: '1F3FF', color: '#2C1810', label: 'Dark' },
                    ];

                    function unifiedToChar(unified) {
                        return String.fromCodePoint(...unified.split('-').map(cp => parseInt(cp, 16)));
                    }

                    const TWEMOJI_BASE = 'https://cdn.jsdelivr.net/gh/jdecked/twemoji@latest/assets/svg/';

                    function unifiedToTwemojiUrl(unified) {
                        const cleaned = unified.toLowerCase().replace(/-fe0f/g, '');
                        return TWEMOJI_BASE + cleaned + '.svg';
                    }

                    function createEmojiImg(unified, char) {
                        const img = document.createElement('img');
                        img.className = 'emoji';
                        img.draggable = false;
                        img.alt = char;
                        img.style.opacity = '0';
                        img.src = unifiedToTwemojiUrl(unified);
                        img.loading = 'lazy';
                        img.addEventListener('load', () => { img.style.opacity = ''; });
                        img.addEventListener('error', () => {
                            const span = document.createElement('span');
                            span.className = 'bitstream-native-emoji';
                            span.textContent = char;
                            img.replaceWith(span);
                        });
                        return img;
                    }

                    function getBsRecentEmoji() {
                        try { return JSON.parse(localStorage.getItem('bitstream_recent_emoji') || '[]'); }
                        catch { return []; }
                    }

                    function addBsRecentEmoji(emoji) {
                        let arr = getBsRecentEmoji().filter(e => e !== emoji);
                        arr.unshift(emoji);
                        localStorage.setItem('bitstream_recent_emoji', JSON.stringify(arr.slice(0, 24)));
                    }

                    function getBsEmojiTone() {
                        return localStorage.getItem('bitstream_emoji_tone') || '';
                    }

                    function setBsEmojiTone(tone) {
                        localStorage.setItem('bitstream_emoji_tone', tone);
                    }

                    function loadBsEmojiData() {
                        if (_emojiData) return Promise.resolve(_emojiData);
                        if (_emojiDataPromise) return _emojiDataPromise;

                        const primaryUrl = 'https://cdn.jsdelivr.net/npm/emoji-datasource@latest/emoji_pretty.json';
                        const localUrl = (window.bitstream_ajax && bitstream_ajax.plugin_url)
                            ? bitstream_ajax.plugin_url + 'assets/js/emoji_pretty.json'
                            : null;

                        const processRawData = (raw) => {
                            const categories = {};
                            const map = {};
                            raw.sort((a, b) => a.sort_order - b.sort_order);
                            for (const entry of raw) {
                                if (entry.category === 'Component') continue;
                                if (!categories[entry.category]) categories[entry.category] = [];
                                categories[entry.category].push(entry);
                                map[entry.unified] = entry;
                            }
                            _emojiData = { categories, map, all: raw.filter(e => e.category !== 'Component') };
                            return _emojiData;
                        };

                        _emojiDataPromise = fetch(primaryUrl)
                            .then(r => {
                                if (!r.ok) throw new Error('CDN response not ok');
                                return r.json();
                            })
                            .catch(err => {
                                console.warn('Emoji Picker CDN fetch failed, trying local fallback...', err);
                                if (!localUrl) throw err;
                                return fetch(localUrl).then(r => {
                                    if (!r.ok) throw new Error('Local fallback failed');
                                    return r.json();
                                });
                            })
                            .then(processRawData)
                            .catch(err => {
                                _emojiDataPromise = null; // allow retry
                                return Promise.reject(err);
                            });
                        return _emojiDataPromise;
                    }

                    function createBsEmojiPicker() {
                        const tabsHtml = [
                            `<button type="button" class="bs-emoji-tab" data-category="recent" title="Recently used"><i class="fa-solid fa-clock-rotate-left"></i></button>`,
                            ...CATEGORY_ORDER.map(cat =>
                                `<button type="button" class="bs-emoji-tab" data-category="${cat}" title="${cat}"><img class="emoji" draggable="false" alt="${unifiedToChar(CATEGORY_ICONS[cat])}" src="${unifiedToTwemojiUrl(CATEGORY_ICONS[cat])}" loading="lazy"></button>`
                            )
                        ].join('');

                        const tonesHtml = TONE_COLORS.map(t =>
                            `<button type="button" class="bs-tone-swatch" data-tone="${t.tone}" style="background:${t.color};" title="${t.label}"></button>`
                        ).join('');

                        const pickerEl = document.createElement('div');
                        pickerEl.className = 'bs-emoji-picker';
                        pickerEl.setAttribute('hidden', '');
                        pickerEl.setAttribute('role', 'dialog');
                        pickerEl.setAttribute('aria-label', 'Emoji picker');
                        pickerEl.innerHTML = `
                            <div class="bs-emoji-picker-top">
                                <input type="text" class="bs-emoji-picker-search" placeholder="Search emoji\u2026" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                                <button type="button" class="bs-emoji-tone-toggle" title="Skin tone" aria-label="Change skin tone"></button>
                            </div>
                            <div class="bs-emoji-tone-row" hidden>${tonesHtml}</div>
                            <div class="bs-emoji-picker-tabs">${tabsHtml}</div>
                            <div class="bs-emoji-picker-body">
                                <div class="bs-emoji-picker-status bs-emoji-picker-empty" hidden>No emoji found</div>
                                <div class="bs-emoji-picker-status bs-emoji-picker-error" hidden>
                                    <p>Couldn\u2019t load emoji data.</p>
                                    <button type="button" class="bs-emoji-retry-btn">Try again</button>
                                </div>
                                <div class="bs-emoji-picker-status bs-emoji-picker-loading" hidden>
                                    <i class="fa-solid fa-spinner fa-spin"></i> Loading\u2026
                                </div>
                            </div>
                        `;
                        document.body.appendChild(pickerEl);

                        const searchInput = pickerEl.querySelector('.bs-emoji-picker-search');
                        const toneToggle  = pickerEl.querySelector('.bs-emoji-tone-toggle');
                        const toneRow     = pickerEl.querySelector('.bs-emoji-tone-row');
                        const toneSwatches = pickerEl.querySelectorAll('.bs-tone-swatch');
                        const tabEls      = pickerEl.querySelectorAll('.bs-emoji-tab');
                        const emptyEl     = pickerEl.querySelector('.bs-emoji-picker-empty');
                        const errorEl     = pickerEl.querySelector('.bs-emoji-picker-error');
                        const loadingEl   = pickerEl.querySelector('.bs-emoji-picker-loading');
                        const retryBtn    = pickerEl.querySelector('.bs-emoji-retry-btn');
                        const bodyContainer = pickerEl.querySelector('.bs-emoji-picker-body');

                        const categoryGrids = {}; // Cache map: categoryName -> DOMElement

                        const searchGrid = document.createElement('div');
                        searchGrid.className = 'bs-emoji-grid';
                        searchGrid.hidden = true;
                        bodyContainer.appendChild(searchGrid);

                        let activeCategory = 'Smileys & Emotion';
                        let isOpen = false;
                        let _onSelect = null;
                        let _outsideHandler = null;
                        let _searchTimeout = null;

                        // Tab icons are rendered as direct <img> tags, no twemoji parse needed

                        function updateToneToggle() {
                            const tone = getBsEmojiTone();
                            const found = TONE_COLORS.find(t => t.tone === tone) || TONE_COLORS[0];
                            toneToggle.style.background = found.color;
                            toneSwatches.forEach(s => s.classList.toggle('is-active', s.dataset.tone === tone));
                        }
                        updateToneToggle();

                        function clearCachedGrids() {
                            Object.keys(categoryGrids).forEach(key => {
                                categoryGrids[key].remove();
                                delete categoryGrids[key];
                            });
                        }

                        function setState(state) {
                            emptyEl.hidden   = state !== 'empty';
                            errorEl.hidden   = state !== 'error';
                            loadingEl.hidden = state !== 'loading';

                            if (state !== 'grid') {
                                searchGrid.hidden = true;
                                Object.values(categoryGrids).forEach(g => g.hidden = true);
                            }
                        }

                        function createGridElement(entries) {
                            const gridEl = document.createElement('div');
                            gridEl.className = 'bs-emoji-grid';
                            gridEl.hidden = true;

                            entries.forEach(entry => {
                                const char = unifiedToChar(entry.unified);
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'bs-emoji-btn';
                                btn.dataset.unified = entry.unified;
                                btn.dataset.emoji = char;
                                btn.title = entry.short_name.replace(/_/g, ' ');
                                if (entry.skin_variations && Object.keys(entry.skin_variations).length) {
                                    btn.dataset.hasTones = '1';
                                }
                                btn.appendChild(createEmojiImg(entry.unified, char));
                                gridEl.appendChild(btn);
                            });

                            return gridEl;
                        }

                        function renderCategory(category) {
                            if (!_emojiData) return;

                            bodyContainer.scrollTop = 0;
                            searchGrid.hidden = true;
                            Object.keys(categoryGrids).forEach(key => {
                                categoryGrids[key].hidden = true;
                            });

                            if (category === 'recent') {
                                if (categoryGrids.recent) {
                                    categoryGrids.recent.remove();
                                }
                                const recent = getBsRecentEmoji();
                                if (!recent.length) {
                                    setState('empty');
                                    return;
                                }
                                const entries = recent.flatMap(char => {
                                    const entry = Object.values(_emojiData.map).find(e => unifiedToChar(e.unified) === char);
                                    return entry ? [entry] : [];
                                });
                                const recentGrid = createGridElement(entries);
                                bodyContainer.appendChild(recentGrid);
                                categoryGrids.recent = recentGrid;

                                const visibleCount = recentGrid.querySelectorAll('.bs-emoji-btn:not([style*="display: none"])').length;
                                if (visibleCount === 0) {
                                    setState('empty');
                                } else {
                                    recentGrid.hidden = false;
                                    setState('grid');
                                }
                                return;
                            }

                            if (!categoryGrids[category]) {
                                const entries = _emojiData.categories[category] || [];
                                if (!entries.length) {
                                    setState('empty');
                                    return;
                                }
                                const newGrid = createGridElement(entries);
                                bodyContainer.appendChild(newGrid);
                                categoryGrids[category] = newGrid;
                            }

                            const activeGrid = categoryGrids[category];
                            const visibleCount = activeGrid.querySelectorAll('.bs-emoji-btn:not([style*="display: none"])').length;
                            if (visibleCount === 0) {
                                setState('empty');
                            } else {
                                activeGrid.hidden = false;
                                setState('grid');
                            }
                        }

                        function renderSearch(query) {
                            if (!_emojiData) return;

                            bodyContainer.scrollTop = 0;
                            Object.keys(categoryGrids).forEach(key => {
                                categoryGrids[key].hidden = true;
                            });

                            const q = query.toLowerCase().trim();
                            const qUnderscore = q.replace(/\s+/g, '_');
                            const results = _emojiData.all.filter(e => {
                                const name = (e.name || '').toLowerCase();
                                return e.short_names.some(n => n.includes(qUnderscore)) || name.includes(q);
                            });

                            searchGrid.innerHTML = '';
                            results.forEach(entry => {
                                const char = unifiedToChar(entry.unified);
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'bs-emoji-btn';
                                btn.dataset.unified = entry.unified;
                                btn.dataset.emoji = char;
                                btn.title = entry.short_name.replace(/_/g, ' ');
                                if (entry.skin_variations && Object.keys(entry.skin_variations).length) {
                                    btn.dataset.hasTones = '1';
                                }
                                btn.appendChild(createEmojiImg(entry.unified, char));
                                searchGrid.appendChild(btn);
                            });

                            const visibleCount = searchGrid.querySelectorAll('.bs-emoji-btn:not([style*="display: none"])').length;
                            if (visibleCount === 0) {
                                setState('empty');
                            } else {
                                searchGrid.hidden = false;
                                setState('grid');
                            }
                        }

                        function setActiveTab(category) {
                            activeCategory = category;
                            tabEls.forEach(t => t.classList.toggle('is-active', t.dataset.category === category));
                        }

                        function positionPicker(triggerEl) {
                            const rect = triggerEl.getBoundingClientRect();
                            const pickerH = 360;
                            const vp = { w: window.innerWidth, h: window.innerHeight };
                            const margin = 12;
                            const pickerW = Math.min(320, vp.w - (margin * 2));

                            let left = rect.right - pickerW;
                            if (left < margin) left = margin;
                            if (left + pickerW > vp.w - margin) left = vp.w - pickerW - margin;

                            let top = rect.bottom + 6;
                            if (top + pickerH > vp.h - margin) {
                                top = rect.top - pickerH - 6;
                            }
                            if (top < margin) top = margin;

                            pickerEl.style.width = pickerW + 'px';
                            pickerEl.style.borderRadius = '16px';
                            pickerEl.style.top = top + 'px';
                            pickerEl.style.left = left + 'px';
                        }

                        bodyContainer.addEventListener('click', e => {
                            const btn = e.target.closest('.bs-emoji-btn');
                            if (!btn) return;
                            let emoji = btn.dataset.emoji;
                            const tone = getBsEmojiTone();
                            if (btn.dataset.hasTones && tone && _emojiData) {
                                const entry = _emojiData.map[btn.dataset.unified];
                                if (entry && entry.skin_variations && entry.skin_variations[tone]) {
                                    emoji = unifiedToChar(entry.skin_variations[tone].unified);
                                }
                            }
                            addBsRecentEmoji(emoji);
                            if (_onSelect) _onSelect(emoji);
                            close();
                        });

                        tabEls.forEach(tab => {
                            tab.addEventListener('click', () => {
                                searchInput.value = '';
                                setActiveTab(tab.dataset.category);
                                renderCategory(activeCategory);
                            });
                        });

                        searchInput.addEventListener('input', () => {
                            clearTimeout(_searchTimeout);
                            _searchTimeout = setTimeout(() => {
                                const q = searchInput.value.trim();
                                if (!q) {
                                    setActiveTab(activeCategory);
                                    renderCategory(activeCategory);
                                } else {
                                    tabEls.forEach(t => t.classList.remove('is-active'));
                                    renderSearch(q);
                                }
                            }, 180);
                        });

                        toneToggle.addEventListener('click', e => {
                            e.stopPropagation();
                            toneRow.hidden = !toneRow.hidden;
                        });

                        toneSwatches.forEach(swatch => {
                            swatch.addEventListener('click', e => {
                                e.stopPropagation();
                                setBsEmojiTone(swatch.dataset.tone);
                                updateToneToggle();
                                toneRow.hidden = true;
                                clearCachedGrids();
                                const q = searchInput.value.trim();
                                if (q) renderSearch(q); else renderCategory(activeCategory);
                            });
                        });

                        retryBtn.addEventListener('click', () => {
                            setState('loading');
                            loadBsEmojiData()
                                .then(() => renderCategory(activeCategory))
                                .catch(() => setState('error'));
                        });

                        pickerEl.addEventListener('keydown', e => {
                            if (e.key === 'Escape') close();
                        });

                        pickerEl.addEventListener('mousedown', e => e.stopPropagation());

                        function open(triggerEl, onSelectCallback) {
                            if (isOpen) { close(); return; }
                            window.closeAllBsEmojiPickers();
                            _onSelect = onSelectCallback;
                            isOpen = true;
                            pickerEl.removeAttribute('hidden');
                            positionPicker(triggerEl);

                            const initialTab = getBsRecentEmoji().length > 0 ? 'recent' : 'Smileys & Emotion';
                            setActiveTab(initialTab);
                            setState('loading');
                            searchInput.value = '';

                            loadBsEmojiData()
                                .then(() => renderCategory(activeCategory))
                                .catch(() => setState('error'));

                            setTimeout(() => {
                                _outsideHandler = () => close();
                                document.addEventListener('mousedown', _outsideHandler);
                            }, 0);
                        }

                        function close() {
                            if (!isOpen) return;
                            isOpen = false;
                            _onSelect = null;
                            pickerEl.setAttribute('hidden', '');
                            toneRow.hidden = true;
                            if (_outsideHandler) {
                                document.removeEventListener('mousedown', _outsideHandler);
                                _outsideHandler = null;
                            }
                        }

                        const pickerInst = { open, close };
                        _activePickers.push(pickerInst);
                        return pickerInst;
                    }

                    window.createBsEmojiPicker = createBsEmojiPicker;
                })();

                // Mood Modal Implementation
                const moodPredefinedButtons = moodModal ? moodModal.querySelectorAll('.bitstream-mood-btn') : [];
                const customEmojiInput = moodModal ? moodModal.querySelector('#bitstream-mood-custom-emoji') : null;
                const customEmotionInput = moodModal ? moodModal.querySelector('#bitstream-mood-custom-emotion') : null;
                const moodDoneBtn = moodModal ? moodModal.querySelector('.bitstream-composer-mood-done') : null;
                const emojiTriggerBtn = moodModal ? moodModal.querySelector('#bitstream-mood-emoji-trigger') : null;

                const savedMoodsGrid = moodModal ? moodModal.querySelector('.bitstream-saved-moods-grid') : null;
                const manageMoodsBtn = moodModal ? moodModal.querySelector('.bitstream-manage-moods-btn') : null;

                const moodEmojiPicker = createBsEmojiPicker();
                const textEmojiPicker = createBsEmojiPicker();

                function insertEmojiAtCursor(inputEl, emoji) {
                    if (!inputEl) return;
                    const startPos = inputEl.selectionStart;
                    const endPos = inputEl.selectionEnd;
                    const text = inputEl.value;
                    const before = text.substring(0, startPos);
                    const after = text.substring(endPos, text.length);
                    inputEl.value = before + emoji + after;
                    inputEl.selectionStart = inputEl.selectionEnd = startPos + emoji.length;
                    inputEl.focus();
                    inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                }

                // Delegate click handler to document body to handle any present or dynamic .bs-insert-emoji-btn
                const emojiDelegator = (e) => {
                    const btn = e.target.closest('.bs-insert-emoji-btn');
                    if (!btn || !textEmojiPicker) return;
                    e.preventDefault();
                    const targetSelector = btn.getAttribute('data-target-input');
                    const targetInput = document.querySelector(targetSelector);
                    if (targetInput) {
                        textEmojiPicker.open(btn, (emoji) => {
                            insertEmojiAtCursor(targetInput, emoji);
                        });
                    }
                };
                document.body.removeEventListener('click', emojiDelegator);
                document.body.addEventListener('click', emojiDelegator);

                function setCustomEmojiDisplay(emoji) {
                    if (customEmojiInput) customEmojiInput.value = emoji || '';
                    if (!emojiTriggerBtn) return;
                    if (emoji) {
                        emojiTriggerBtn.textContent = emoji;
                        if (typeof window.parseEmojis === 'function') window.parseEmojis(emojiTriggerBtn);
                        emojiTriggerBtn.style.color = 'inherit';
                        emojiTriggerBtn.style.borderColor = 'var(--wp--preset--color--accent-1, #2c6e49)';
                    } else {
                        emojiTriggerBtn.innerHTML = '<i class="fa-solid fa-plus" style="font-size: 1rem;" aria-hidden="true"></i>';
                        emojiTriggerBtn.style.color = '#94a3b8';
                        emojiTriggerBtn.style.borderColor = '';
                    }
                }

                let moodModalParsed = false;
                if (moodModal && !moodModalParsed) {
                    if (typeof window.parseEmojis === 'function') window.parseEmojis(moodModal);
                    moodModalParsed = true;
                }

                function renderSavedMoods() {
                    if (!savedMoodsGrid) return;

                    if (manageMoodsBtn) {
                        if (customMoods.length === 0) {
                            manageMoodsBtn.style.display = 'none';
                        } else {
                            manageMoodsBtn.style.display = 'flex';
                            manageMoodsBtn.innerHTML = '<i class="fa-solid fa-gear"></i> Manage';
                        }
                    }

                    savedMoodsGrid.style.display = 'grid';
                    savedMoodsGrid.innerHTML = '';

                    const currentEmoji = activeEditMoodForm 
                        ? (activeEditMoodForm.querySelector('.bs-edit-mood-emoji').value || '') 
                        : (hMoodEmoji.value || '');
                    const currentEmotion = activeEditMoodForm 
                        ? (activeEditMoodForm.querySelector('.bs-edit-mood-emotion').value || '') 
                        : (hMoodEmotion.value || '');

                    customMoods.forEach(mood => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'bitstream-mood-btn';
                        if (currentEmoji === mood.emoji && currentEmotion.toLowerCase() === mood.emotion.toLowerCase()) {
                            btn.classList.add('is-active', 'is-selected');
                        }
                        btn.dataset.emoji = mood.emoji;
                        btn.dataset.emotion = mood.emotion;
                        btn.innerHTML = `
                            <span class="bitstream-mood-btn-emoji">${mood.emoji}</span>
                            <span class="bitstream-mood-btn-label">${mood.emotion}</span>
                        `;

                        btn.addEventListener('click', (e) => {
                            e.preventDefault();
                            moodModal.querySelectorAll('.bitstream-mood-btn').forEach(b => b.classList.remove('is-active', 'is-selected'));
                            btn.classList.add('is-active', 'is-selected');
                        });

                        savedMoodsGrid.appendChild(btn);
                    });
                    if (typeof window.parseEmojis === 'function') window.parseEmojis(savedMoodsGrid);
                }

                if (manageMoodsBtn) {
                    manageMoodsBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        closeModal('mood');
                        openModal('settings');
                        const moodsTabBtn = composerRoot.querySelector('.bitstream-settings-tab[data-settings-tab="moods"]');
                        if (moodsTabBtn) {
                            moodsTabBtn.click();
                        }
                    });
                }

                function initSettingsCustomMoods() {
                    const settingsList = composerRoot.querySelector('#bitstream-settings-saved-moods-list');
                    const settingsAddBtn = composerRoot.querySelector('#bitstream-settings-add-mood-btn');
                    const settingsSaveBtn = composerRoot.querySelector('#bitstream-settings-save-moods-btn');
                    const settingsEmojiTrigger = composerRoot.querySelector('#bitstream-settings-mood-emoji-trigger');
                    const settingsCustomEmoji = composerRoot.querySelector('#bitstream-settings-mood-custom-emoji');
                    const settingsCustomEmotion = composerRoot.querySelector('#bitstream-settings-mood-custom-emotion');
                    const settingsStatus = composerRoot.querySelector('#bitstream-settings-moods-status');

                    if (!settingsList) return;

                    let settingsCustomMoods = JSON.parse(JSON.stringify(customMoods));
                    let settingsMoodEditsMap = {};

                    function renderSettingsMoods() {
                        settingsList.innerHTML = '';
                        if (settingsCustomMoods.length === 0) {
                            settingsList.innerHTML = '<div style="color: #666; font-size: 0.9rem; text-align: center; padding: 10px 0;">No custom moods saved yet. Add one below!</div>';
                            return;
                        }

                        settingsCustomMoods.forEach((mood, index) => {
                            const row = document.createElement('div');
                            row.className = 'bitstream-mood-manage-item';
                            row.style.cssText = 'display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px;';
                            row.innerHTML = `
                                <div class="bitstream-mood-manage-info" style="display: flex; gap: 8px; align-items: center; flex: 1;">
                                    <span class="bs-settings-mood-emoji-display" data-index="${index}" title="Click to change emoji" style="display: flex; align-items: center; justify-content: center; width: 36px; height: 32px; flex-shrink: 0; border: 1.5px solid #e2e8f0; border-radius: 8px; background: #fff; cursor: pointer; box-sizing: border-box; font-size: 1.1rem;">${mood.emoji}</span>
                                    <input type="text" class="bs-settings-mood-edit-emotion" data-index="${index}" value="${mood.emotion}" style="flex: 1; height: 32px; border: 1.5px solid #e2e8f0; border-radius: 8px; background: #fff; padding: 0 8px; box-sizing: border-box; font-size: 0.85rem;">
                                </div>
                                <div class="bitstream-mood-manage-actions" style="display: flex; gap: 4px;">
                                    <button type="button" class="bitstream-mood-sort-btn bs-settings-mood-up" data-index="${index}" ${index === 0 ? 'disabled' : ''} style="padding: 4px 8px; border: 1px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer;" title="Move Up"><i class="fa-solid fa-arrow-up"></i></button>
                                    <button type="button" class="bitstream-mood-sort-btn bs-settings-mood-down" data-index="${index}" ${index === settingsCustomMoods.length - 1 ? 'disabled' : ''} style="padding: 4px 8px; border: 1px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer;" title="Move Down"><i class="fa-solid fa-arrow-down"></i></button>
                                    <button type="button" class="bitstream-mood-delete-btn bs-settings-mood-delete" data-index="${index}" style="padding: 4px 8px; border: 1px solid #ffccd5; color: #d63638; border-radius: 6px; background: #fff; cursor: pointer;" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                </div>
                            `;
                            settingsList.appendChild(row);

                            const emojiSpan = row.querySelector('.bs-settings-mood-emoji-display');
                            if (typeof window.parseEmojis === 'function') window.parseEmojis(emojiSpan);

                            emojiSpan.addEventListener('click', () => {
                                if (!moodEmojiPicker) return;
                                const idx = parseInt(emojiSpan.dataset.index, 10);
                                const oldEmoji = settingsCustomMoods[idx].emoji;
                                const oldEmotion = settingsCustomMoods[idx].emotion;
                                moodEmojiPicker.open(emojiSpan, (emoji) => {
                                    settingsCustomMoods[idx].emoji = emoji;
                                    const oldKey = `${oldEmoji}|${oldEmotion}`;
                                    if (oldEmotion && (oldEmoji !== emoji)) {
                                        settingsMoodEditsMap[oldKey] = { emoji, emotion: oldEmotion };
                                    }
                                    emojiSpan.textContent = emoji;
                                    if (typeof window.parseEmojis === 'function') window.parseEmojis(emojiSpan);
                                });
                            });
                        });

                        let focusOldEmoji = '';
                        let focusOldEmotion = '';

                        settingsList.querySelectorAll('.bs-settings-mood-edit-emotion').forEach(input => {
                            input.addEventListener('focus', () => {
                                const idx = parseInt(input.dataset.index, 10);
                                focusOldEmoji = settingsCustomMoods[idx].emoji;
                                focusOldEmotion = settingsCustomMoods[idx].emotion;
                            });

                            input.addEventListener('change', () => {
                                const idx = parseInt(input.dataset.index, 10);
                                const oldKey = `${focusOldEmoji}|${focusOldEmotion}`;
                                settingsCustomMoods[idx].emotion = input.value.trim();
                                const newEmoji = settingsCustomMoods[idx].emoji;
                                const newEmotion = settingsCustomMoods[idx].emotion;
                                if (newEmotion && (focusOldEmoji !== newEmoji || focusOldEmotion !== newEmotion)) {
                                    settingsMoodEditsMap[oldKey] = { emoji: newEmoji, emotion: newEmotion };
                                }
                            });
                        });

                        settingsList.querySelectorAll('.bs-settings-mood-up').forEach(btn => {
                            btn.addEventListener('click', (e) => {
                                e.preventDefault();
                                const idx = parseInt(btn.dataset.index, 10);
                                if (idx > 0) {
                                    const temp = settingsCustomMoods[idx];
                                    settingsCustomMoods[idx] = settingsCustomMoods[idx - 1];
                                    settingsCustomMoods[idx - 1] = temp;
                                    renderSettingsMoods();
                                }
                            });
                        });

                        settingsList.querySelectorAll('.bs-settings-mood-down').forEach(btn => {
                            btn.addEventListener('click', (e) => {
                                e.preventDefault();
                                const idx = parseInt(btn.dataset.index, 10);
                                if (idx < settingsCustomMoods.length - 1) {
                                    const temp = settingsCustomMoods[idx];
                                    settingsCustomMoods[idx] = settingsCustomMoods[idx + 1];
                                    settingsCustomMoods[idx + 1] = temp;
                                    renderSettingsMoods();
                                }
                            });
                        });

                        settingsList.querySelectorAll('.bs-settings-mood-delete').forEach(btn => {
                            btn.addEventListener('click', (e) => {
                                e.preventDefault();
                                const idx = parseInt(btn.dataset.index, 10);
                                settingsCustomMoods.splice(idx, 1);
                                renderSettingsMoods();
                            });
                        });
                    }

                    if (settingsEmojiTrigger && moodEmojiPicker) {
                        settingsEmojiTrigger.addEventListener('click', () => {
                            moodEmojiPicker.open(settingsEmojiTrigger, (emoji) => {
                                settingsCustomEmoji.value = emoji;
                                settingsEmojiTrigger.innerHTML = emoji;
                                if (typeof window.parseEmojis === 'function') window.parseEmojis(settingsEmojiTrigger);
                            });
                        });
                    }

                    if (settingsAddBtn) {
                        settingsAddBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            let emoji = settingsCustomEmoji.value.trim();
                            let emotion = settingsCustomEmotion.value.trim();

                            if (!emotion) return;
                            if (!emoji) emoji = '😊';

                            settingsCustomMoods.push({ emoji, emotion });
                            
                            settingsCustomEmoji.value = '';
                            settingsEmojiTrigger.innerHTML = '<i class="fa-solid fa-plus" style="font-size: 1rem;"></i>';
                            settingsCustomEmotion.value = '';

                            renderSettingsMoods();
                        });
                    }

                    if (settingsSaveBtn) {
                        settingsSaveBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) return;

                            settingsStatus.textContent = 'Saving...';
                            settingsStatus.style.color = '#666';

                            const syncPayload = new FormData();
                            syncPayload.append('action', 'bitstream_save_custom_moods');
                            syncPayload.append('nonce', submitNonce);
                            syncPayload.append('moods', JSON.stringify(settingsCustomMoods));
                            syncPayload.append('edits', JSON.stringify(settingsMoodEditsMap));

                            fetch(bitstream_ajax.ajax_url, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: syncPayload
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success && data.data && data.data.custom_moods) {
                                    customMoods = data.data.custom_moods;
                                    settingsCustomMoods = JSON.parse(JSON.stringify(customMoods));
                                    settingsMoodEditsMap = {};
                                    
                                    renderSettingsMoods();
                                    renderSavedMoods();

                                    settingsStatus.textContent = 'Saved successfully! Reloading...';
                                    settingsStatus.style.color = 'var(--wp--preset--color--accent-1, #2c6e49)';
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    settingsStatus.textContent = 'Error: ' + (data.data || 'Failed to save.');
                                    settingsStatus.style.color = '#d63638';
                                }
                            })
                            .catch(err => {
                                console.error('BitStream: Error saving settings moods:', err);
                                settingsStatus.textContent = 'Connection error.';
                                settingsStatus.style.color = '#d63638';
                            });
                        });
                    }

                    renderSettingsMoods();
                }

                initSettingsCustomMoods();

                moodPredefinedButtons.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        moodModal.querySelectorAll('.bitstream-mood-btn').forEach(b => b.classList.remove('is-active'));
                        btn.classList.add('is-active');
                        setCustomEmojiDisplay('');
                        if (customEmotionInput) customEmotionInput.value = '';
                    });
                });

                const clearHighlights = () => {
                    moodModal.querySelectorAll('.bitstream-mood-btn').forEach(b => b.classList.remove('is-active'));
                };

                if (emojiTriggerBtn && moodEmojiPicker) {
                    emojiTriggerBtn.addEventListener('click', () => {
                        clearHighlights();
                        moodEmojiPicker.open(emojiTriggerBtn, (emoji) => {
                            setCustomEmojiDisplay(emoji);
                        });
                    });
                }

                if (customEmotionInput) {
                    customEmotionInput.addEventListener('input', clearHighlights);
                }

                if (moodDoneBtn) {
                    moodDoneBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        let emoji = '';
                        let emotion = '';

                        const activeBtn = moodModal.querySelector('.bitstream-mood-btn.is-active');
                        if (activeBtn) {
                            emoji = activeBtn.dataset.emoji || '';
                            emotion = activeBtn.dataset.emotion || '';
                        } else {
                            emoji = customEmojiInput ? customEmojiInput.value.trim() : '';
                            emotion = customEmotionInput ? customEmotionInput.value.trim() : '';
                        }

                        if (emotion && !emoji) {
                            emoji = '😊';
                        }

                        if (activeEditMoodForm) {
                            const editEmojiInput = activeEditMoodForm.querySelector('.bs-edit-mood-emoji');
                            const editEmotionInput = activeEditMoodForm.querySelector('.bs-edit-mood-emotion');
                            const editMoodLabel = activeEditMoodForm.querySelector('.bs-edit-mood-label');
                            const editMoodRemove = activeEditMoodForm.querySelector('.bs-edit-mood-remove');

                            if (editEmojiInput) editEmojiInput.value = emoji;
                            if (editEmotionInput) editEmotionInput.value = emotion;

                            if (editMoodLabel && editMoodRemove) {
                                if (emotion) {
                                    editMoodLabel.textContent = `${emoji} Feeling ${emotion}`;
                                    if (typeof window.parseEmojis === 'function') window.parseEmojis(editMoodLabel);
                                    editMoodRemove.style.display = 'inline-block';
                                } else {
                                    editMoodLabel.textContent = 'Add Mood';
                                    editMoodRemove.style.display = 'none';
                                }
                            }
                            activeEditMoodForm = null;
                        } else {
                            if (hMoodEmoji) hMoodEmoji.value = emoji;
                            if (hMoodEmotion) hMoodEmotion.value = emotion;

                            if (previewMood && previewMoodText) {
                                if (emotion) {
                                    previewMoodText.textContent = `${emoji} Feeling ${emotion}`;
                                    if (typeof window.parseEmojis === 'function') window.parseEmojis(previewMoodText);
                                    previewMood.hidden = false;
                                } else {
                                    previewMoodText.textContent = '';
                                    previewMood.hidden = true;
                                }
                            }
                            syncPreviewArea();
                        }

                        setCustomEmojiDisplay('');
                        if (customEmotionInput) customEmotionInput.value = '';

                        closeModal('mood');
                    });
                }

                if (previewMood) {
                    const editBtn = previewMood.querySelector('.bitstream-composer-preview-edit');
                    const removeBtn = previewMood.querySelector('.bitstream-composer-preview-remove');

                    if (editBtn) {
                        editBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            activeEditMoodForm = null;
                            openModal('mood');
                        });
                    }

                    if (removeBtn) {
                        removeBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            if (hMoodEmoji) hMoodEmoji.value = '';
                            if (hMoodEmotion) hMoodEmotion.value = '';
                            previewMood.hidden = true;
                            syncPreviewArea();
                        });
                    }
                }

                const wireTimelineEditMoodButtons = (editForm) => {
                    const editMoodBtn = editForm.querySelector('.bs-edit-mood-btn');
                    const editMoodRemove = editForm.querySelector('.bs-edit-mood-remove');

                    if (editMoodBtn) {
                        editMoodBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            activeEditMoodForm = editForm;
                            openModal('mood');
                        });
                    }

                    if (editMoodRemove) {
                        editMoodRemove.addEventListener('click', (e) => {
                            e.preventDefault();
                            const editEmojiInput = editForm.querySelector('.bs-edit-mood-emoji');
                            const editEmotionInput = editForm.querySelector('.bs-edit-mood-emotion');
                            const editMoodLabel = editForm.querySelector('.bs-edit-mood-label');

                            if (editEmojiInput) editEmojiInput.value = '';
                            if (editEmotionInput) editEmotionInput.value = '';
                            if (editMoodLabel) editMoodLabel.textContent = 'Add Mood';
                            editMoodRemove.style.display = 'none';
                        });
                    }
                };

                const editForm = document.querySelector('.bs-edit-form-unified') || document.querySelector('.bs-edit-form-bit') || document.querySelector('.bs-edit-form');
                if (editForm) wireTimelineEditMoodButtons(editForm);

                function loadPostIntoComposer(postId) {
                    composerRoot.hidden = false;
                    if (!window.bitstream_ajax) return;
                    delete composerRoot.dataset.quickActionSource;

                    setStatus('Loading post...');
                    if (submitBtn) submitBtn.disabled = true;

                    const fd = new FormData();
                    fd.append('action', 'bitstream_get_post_data');
                    fd.append('nonce', submitNonce);
                    fd.append('post_id', String(postId));

                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success) throw new Error(data.data || 'Could not load post data.');
                            const d = data.data || {};

                            if (textarea) textarea.value = d.content || '';
                            if (hEditPostId) hEditPostId.value = String(postId);

                            if (d.is_rebit && d.rebit_url) {
                                if (hRebitUrl) hRebitUrl.value = d.rebit_url;
                                if (hRebitOgTitle) hRebitOgTitle.value = d.og_title || '';
                                if (hRebitOgDesc) hRebitOgDesc.value = d.og_desc || '';
                                if (hRebitOgImage) hRebitOgImage.value = d.og_image || '';
                                if (hRebitAttachmentId && d.attachment_id) hRebitAttachmentId.value = String(d.attachment_id);
                                if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                                form.dataset.composerType = 'rebit';
                                if (textarea) textarea.required = false;
                                if (previewRebitCard) previewRebitCard.innerHTML = d.media_preview_html || '<p style="font-size:0.85rem;color:#555;">Rebit: ' + d.rebit_url + '</p>';
                                if (previewRebit) previewRebit.hidden = false;

                                const mRebitUrl = composerRoot.querySelector('#bitstream-composer-modal-rebit-url');
                                const mRebitTitle = composerRoot.querySelector('#bitstream-composer-modal-rebit-og-title');
                                const mRebitDesc = composerRoot.querySelector('#bitstream-composer-modal-rebit-og-desc');
                                const mRebitFetch = composerRoot.querySelector('.bitstream-composer-rebit-fetch');

                                if (mRebitUrl) mRebitUrl.value = d.rebit_url;
                                if (mRebitTitle) mRebitTitle.value = d.og_title || '';
                                if (mRebitDesc) mRebitDesc.value = d.og_desc || '';

                                if (mRebitFetch) {
                                    mRebitFetch.classList.add('is-edit-mode');
                                    mRebitFetch.textContent = 'Edit metadata';
                                }

                                if (typeof renderRebitLivePreview === 'function') {
                                    renderRebitLivePreview(d.rebit_url);
                                }
                            } else {
                                form.dataset.composerType = 'bit';
                                if (hRebitUrl) hRebitUrl.value = '';
                                if (hRebitOgTitle) hRebitOgTitle.value = '';
                                if (hRebitOgDesc) hRebitOgDesc.value = '';
                                if (hRebitOgImage) hRebitOgImage.value = '';
                                if (hRebitAttachmentId) hRebitAttachmentId.value = '';
                                if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                                if (previewRebit) previewRebit.hidden = true;
                                if (previewRebitCard) previewRebitCard.innerHTML = '';
                                if (textarea) textarea.required = true;
                            }

                            const previewEl = previewMediaThumb || form.querySelector('.bitstream-media-preview');
                            const hAttachmentIds = form.querySelector('#bitstream-composer-attachment-ids');
                            if (d.attachment_id && parseInt(d.attachment_id, 10) > 0) {
                                if (hAttachmentId) hAttachmentId.value = String(d.attachment_id);
                                if (hAttachmentIds && d.attachment_ids) hAttachmentIds.value = d.attachment_ids;
                                if (previewEl) {
                                    if (d.attachments && d.attachments.length > 0) {
                                        if (typeof window.updateAttachmentsList === 'function') {
                                            window.updateAttachmentsList(previewEl, d.attachments);
                                        }
                                    } else if (d.attachment_url && d.attachment_mime) {
                                        const singleAtt = {
                                            id: parseInt(d.attachment_id, 10),
                                            url: d.attachment_url,
                                            preview_url: d.attachment_url,
                                            mime: d.attachment_mime
                                        };
                                        if (typeof window.updateAttachmentsList === 'function') {
                                            window.updateAttachmentsList(previewEl, [singleAtt]);
                                        }
                                    } else {
                                        previewEl.innerHTML = d.media_preview_html || '<span>Media attached (ID: ' + d.attachment_id + ')</span>';
                                    }
                                }
                                if (previewMedia) previewMedia.hidden = false;
                            } else {
                                if (hAttachmentId) hAttachmentId.value = '';
                                if (hAttachmentIds) hAttachmentIds.value = '';
                                if (previewEl) {
                                    if (typeof window.updateAttachmentsList === 'function') {
                                        window.updateAttachmentsList(previewEl, []);
                                    }
                                }
                                if (previewMedia) previewMedia.hidden = true;
                            }

                            if (d.mood_emotion) {
                                if (hMoodEmoji) hMoodEmoji.value = d.mood_emoji || '';
                                if (hMoodEmotion) hMoodEmotion.value = d.mood_emotion || '';
                                if (previewMoodText) {
                                    previewMoodText.textContent = `${d.mood_emoji || ''} Feeling ${d.mood_emotion}`.trim();
                                    if (typeof window.parseEmojis === 'function') window.parseEmojis(previewMoodText);
                                }
                                if (previewMood) previewMood.hidden = false;
                            } else {
                                if (hMoodEmoji) hMoodEmoji.value = '';
                                if (hMoodEmotion) hMoodEmotion.value = '';
                                if (previewMood) previewMood.hidden = true;
                            }

                            if (textarea) textarea.required = !(d.is_rebit || (d.attachment_id && parseInt(d.attachment_id, 10) > 0) || d.mood_emotion);

                            if (d.schedule_enabled === '1' && d.schedule_datetime) {
                                if (hScheduleEnabled) hScheduleEnabled.value = '1';
                                if (hScheduleDatetime) hScheduleDatetime.value = d.schedule_datetime;
                                if (previewScheduleDate) previewScheduleDate.textContent = new Date(d.schedule_datetime).toLocaleString();
                                if (previewSchedule) previewSchedule.hidden = false;
                            } else {
                                if (hScheduleEnabled) hScheduleEnabled.value = '0';
                                if (hScheduleDatetime) hScheduleDatetime.value = '';
                                if (previewSchedule) previewSchedule.hidden = true;
                            }

                            const isScheduled = d.schedule_enabled === '1';
                            if (previewDraftLabel) {
                                previewDraftLabel.textContent = isScheduled ? 'Editing Scheduled #' + postId : 'Editing Draft #' + postId;
                            }
                            if (previewDraft) previewDraft.hidden = false;

                            if (submitBtn) {
                                submitBtn.textContent = isScheduled ? 'Update Scheduled Post' : 'Publish Draft';
                            }

                            if (composerSaveDraftBtn) {
                                composerSaveDraftBtn.style.display = isScheduled ? 'none' : 'block';
                            }

                            syncPreviewArea();
                            setStatus('Post loaded.');
                        })
                        .catch(err => setStatus(err.message || 'Could not load post.', true))
                        .finally(() => { if (submitBtn) submitBtn.disabled = false; });
                }
                composerRoot.bitstreamLoadPostIntoComposer = loadPostIntoComposer;

                // ── REBIT MODAL ──
                const rebitModal = composerRoot.querySelector('.bitstream-composer-modal-rebit');
                const rebitMetaModal = composerRoot.querySelector('.bitstream-composer-modal-rebit-meta');
                if (rebitModal && rebitMetaModal) {
                    const mRebitUrl = rebitModal.querySelector('#bitstream-composer-modal-rebit-url');
                    const mRebitFetch = rebitModal.querySelector('.bitstream-composer-rebit-fetch');
                    const mRebitPreviewRoot = rebitModal.querySelector('.bitstream-composer-rebit-live-preview');
                    const mRebitPreviewLoading = rebitModal.querySelector('.bitstream-composer-rebit-live-preview-loading');
                    const mRebitPreviewCard = rebitModal.querySelector('.bitstream-composer-rebit-live-preview-card');
                    const mRebitDone = rebitModal.querySelector('.bitstream-composer-rebit-done');

                    const mRebitTitle = rebitMetaModal.querySelector('#bitstream-composer-modal-rebit-og-title');
                    const mRebitDesc = rebitMetaModal.querySelector('#bitstream-composer-modal-rebit-og-desc');
                    const mRebitImageChange = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-change');
                    const mRebitImageRemove = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-remove');
                    const mRebitMetaDone = rebitMetaModal.querySelector('.bitstream-composer-rebit-meta-done');

                    const mRebitImagePreviewWrapper = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-preview-wrapper');
                    const mRebitImagePreviewEl = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-preview-el');

                    updateModalImagePreview = function () {
                        const imageUrl = hRebitOgImage ? hRebitOgImage.value : '';
                        const isRemoved = hRebitOgImageRemoved ? hRebitOgImageRemoved.value === '1' : false;
                        if (imageUrl && !isRemoved) {
                            if (mRebitImagePreviewEl) mRebitImagePreviewEl.src = imageUrl;
                            if (mRebitImagePreviewWrapper) mRebitImagePreviewWrapper.hidden = false;
                            if (mRebitImageRemove) mRebitImageRemove.hidden = false;
                        } else {
                            if (mRebitImagePreviewEl) mRebitImagePreviewEl.src = '';
                            if (mRebitImagePreviewWrapper) mRebitImagePreviewWrapper.hidden = true;
                            if (mRebitImageRemove) mRebitImageRemove.hidden = true;
                        }
                    };

                    renderRebitLivePreview = function (url) {
                        if (!mRebitPreviewRoot || !window.bitstream_ajax) return;
                        mRebitPreviewRoot.hidden = false;
                        if (mRebitPreviewLoading) mRebitPreviewLoading.hidden = false;

                        const fd = new FormData();
                        fd.append('action', 'bitstream_render_rebit_preview');
                        fd.append('nonce', bitstream_ajax.og_fetch_nonce);
                        fd.append('rebit_url', url);
                        fd.append('rebit_commentary', textarea ? textarea.value : '');
                        fd.append('rebit_og_title', mRebitTitle ? mRebitTitle.value : '');
                        fd.append('rebit_og_desc', mRebitDesc ? mRebitDesc.value : '');
                        fd.append('rebit_og_image', hRebitOgImage ? hRebitOgImage.value : '');
                        fd.append('rebit_og_image_removed', hRebitOgImageRemoved ? hRebitOgImageRemoved.value : '0');
                        fd.append('rebit_attachment_id', hRebitAttachmentId ? hRebitAttachmentId.value : '');

                        fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) throw new Error(data.data || 'Preview failed.');
                                const resp = data.data || {};
                                if (mRebitPreviewCard) mRebitPreviewCard.innerHTML = resp.rendered_html || '';
                                if (previewRebitCard) previewRebitCard.innerHTML = resp.rendered_html || '';
                                if (mRebitPreviewLoading) mRebitPreviewLoading.hidden = true;
                                if (resp.og && hRebitOgImage) {
                                    hRebitOgImage.value = resp.og.image || '';
                                }
                                updateModalImagePreview();
                            })
                            .catch(() => { if (mRebitPreviewLoading) mRebitPreviewLoading.hidden = true; });
                    };

                    let livePreviewDebounce;
                    const triggerLivePreview = () => {
                        const url = mRebitUrl ? mRebitUrl.value.trim() : '';
                        if (url) {
                            clearTimeout(livePreviewDebounce);
                            livePreviewDebounce = setTimeout(() => renderRebitLivePreview(url), 500);
                        }
                    };

                    if (mRebitTitle) mRebitTitle.addEventListener('input', triggerLivePreview);
                    if (mRebitDesc) mRebitDesc.addEventListener('input', triggerLivePreview);

                    let rebitMediaFrame;
                    if (mRebitImageChange) {
                        mRebitImageChange.addEventListener('click', (e) => {
                            e.preventDefault();
                            if (rebitMediaFrame) { rebitMediaFrame.open(); return; }
                            rebitMediaFrame = wp.media({ title: 'Select Preview Image', button: { text: 'Use this image' }, multiple: false });
                            rebitMediaFrame.on('select', () => {
                                const attachment = rebitMediaFrame.state().get('selection').first().toJSON();
                                if (hRebitAttachmentId) hRebitAttachmentId.value = attachment.id;
                                if (hRebitOgImage) hRebitOgImage.value = attachment.url;
                                if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                                updateModalImagePreview();
                                triggerLivePreview();
                            });
                            rebitMediaFrame.open();
                        });
                    }

                    if (mRebitImageRemove) {
                        mRebitImageRemove.addEventListener('click', () => {
                            if (hRebitAttachmentId) hRebitAttachmentId.value = '';
                            if (hRebitOgImage) hRebitOgImage.value = '';
                            if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '1';
                            updateModalImagePreview();
                            triggerLivePreview();
                        });
                    }

                    if (mRebitFetch) {
                        mRebitFetch.addEventListener('click', () => {
                            if (mRebitFetch.classList.contains('is-edit-mode')) {
                                openModal('rebit-meta');
                                return;
                            }
                            const url = mRebitUrl ? mRebitUrl.value.trim() : '';
                            if (!url) { setStatus('Enter a URL first.', true); return; }
                            if (!window.bitstream_ajax || !bitstream_ajax.og_fetch_nonce) { setStatus('Metadata fetcher unavailable.', true); return; }

                            mRebitFetch.disabled = true;
                            mRebitFetch.textContent = 'Fetching...';

                            const fd = new FormData();
                            fd.append('action', 'bitstream_fetch_og_data');
                            fd.append('nonce', bitstream_ajax.og_fetch_nonce);
                            fd.append('url', url);
                            fd.append('post_id', '0');

                            fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                                .then(r => r.json())
                                .then(data => {
                                    if (!data.success) throw new Error(data.data || 'Fetch failed.');
                                    const meta = data.data || {};
                                    if (mRebitTitle) mRebitTitle.value = meta.title || '';
                                    if (mRebitDesc) mRebitDesc.value = meta.description || '';
                                    renderRebitLivePreview(url);
                                    setStatus('Metadata loaded.');
                                    mRebitFetch.classList.add('is-edit-mode');
                                    mRebitFetch.textContent = 'Edit metadata';
                                })
                                .catch(err => setStatus(err.message || 'Fetch failed.', true))
                                .finally(() => {
                                    mRebitFetch.disabled = false;
                                    if (!mRebitFetch.classList.contains('is-edit-mode')) {
                                        mRebitFetch.textContent = 'Fetch metadata';
                                    }
                                });
                        });
                    }

                    if (mRebitUrl) {
                        mRebitUrl.addEventListener('input', () => {
                            mRebitFetch.classList.remove('is-edit-mode');
                            mRebitFetch.textContent = 'Fetch metadata';
                            if (mRebitPreviewRoot) mRebitPreviewRoot.hidden = true;
                            if (mRebitPreviewCard) mRebitPreviewCard.innerHTML = '';
                            if (mRebitTitle) mRebitTitle.value = '';
                            if (mRebitDesc) mRebitDesc.value = '';
                        });
                    }

                    if (mRebitMetaDone) {
                        mRebitMetaDone.addEventListener('click', () => {
                            const url = mRebitUrl ? mRebitUrl.value.trim() : '';
                            renderRebitLivePreview(url);
                            closeModal('rebit-meta');
                        });
                    }

                    if (mRebitDone) {
                        mRebitDone.addEventListener('click', () => {
                            const url = mRebitUrl ? mRebitUrl.value.trim() : '';
                            if (!url) { setStatus('Enter and fetch a URL first.', true); return; }

                            if (hRebitUrl) hRebitUrl.value = url;
                            if (hRebitOgTitle) hRebitOgTitle.value = mRebitTitle ? mRebitTitle.value : '';
                            if (hRebitOgDesc) hRebitOgDesc.value = mRebitDesc ? mRebitDesc.value : '';
                            form.dataset.composerType = 'rebit';
                            if (textarea) textarea.required = false;

                            if (previewRebitCard && mRebitPreviewCard) {
                                previewRebitCard.innerHTML = mRebitPreviewCard.innerHTML || ('<p style="font-size:0.85rem;color:#555;">Rebit: ' + url + '</p>');
                            }
                            if (previewRebit) previewRebit.hidden = false;
                            syncPreviewArea();
                            delete composerRoot.dataset.quickActionSource;
                            closeModal('rebit', true);
                            setStatus('Rebit attached.');
                        });
                    }
                }

                // ── MEDIA MODAL ──
                const mediaModal = composerRoot.querySelector('.bitstream-composer-modal-media');
                if (mediaModal) {
                    const mMediaDone = mediaModal.querySelector('.bitstream-composer-media-done');
                    const mMediaPreview = mediaModal.querySelector('#bitstream-composer-modal-media-preview');

                    if (mMediaDone) {
                        mMediaDone.addEventListener('click', () => {
                            const attachments = typeof window.getExistingAttachments === 'function' ? window.getExistingAttachments(mMediaPreview) : [];
                            if (attachments.length === 0) { setStatus('Upload or select media first.', true); return; }

                            if (previewMediaThumb) {
                                if (typeof window.updateAttachmentsList === 'function') {
                                    window.updateAttachmentsList(previewMediaThumb, attachments);
                                }
                            } else {
                                const attachId = attachments[0].id;
                                const attachIds = attachments.map(item => item.id).join(',');
                                if (hAttachmentId) hAttachmentId.value = String(attachId);
                                if (hAttachmentIds) hAttachmentIds.value = attachIds;
                                if (previewMedia) previewMedia.hidden = false;
                            }

                            syncPreviewArea();
                            closeModal('media');
                            setStatus('Media attached.');
                        });
                    }
                }

                // ── DRAFTS MODAL ──
                const draftsModal = composerRoot.querySelector('.bitstream-composer-modal-drafts');
                if (draftsModal) {
                    if (typeof window.parseEmojis === 'function') {
                        window.parseEmojis(draftsModal);
                    }
                    const draftFilterBtns = draftsModal.querySelectorAll('.bitstream-composer-drafts-filter-btn');
                    const draftItems = draftsModal.querySelectorAll('.bitstream-composer-draft-item');
                    draftFilterBtns.forEach(btn => {
                        btn.addEventListener('click', () => {
                            const filter = btn.dataset.filter || 'all';
                            draftFilterBtns.forEach(b => b.classList.toggle('is-active', b === btn));
                            draftItems.forEach(item => {
                                const type = item.dataset.type || 'bit';
                                item.style.display = (filter === 'all' || filter === type) ? '' : 'none';
                            });
                        });
                    });

                    draftsModal.addEventListener('click', (event) => {
                        const btn = event.target.closest('.bitstream-composer-draft-load');
                        if (btn && draftsModal.contains(btn)) {
                            event.preventDefault();
                            const postId = btn.dataset.postId;
                            if (postId) {
                                loadPostIntoComposer(postId);
                                closeModal('drafts', true);
                            }
                        }
                    });

                    draftsModal.querySelectorAll('.bitstream-composer-draft-delete').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const postId = btn.dataset.postId;
                            if (!postId || !window.bitstream_ajax) return;

                            if (typeof window.showDeleteConfirmation === 'function') {
                                window.showDeleteConfirmation('Delete this draft?', () => {
                                    btn.disabled = true;
                                    const fd = new FormData();
                                    fd.append('action', 'bitstream_delete_post');
                                    fd.append('nonce', bitstream_ajax.delete_post_nonce);
                                    fd.append('post_id', postId);

                                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                                        .then(r => r.json())
                                        .then(data => {
                                            if (!data.success) throw new Error(data.data || 'Delete failed.');
                                            const item = btn.closest('.bitstream-composer-draft-item');
                                            if (item) item.remove();

                                            const list = draftsModal.querySelector('.bitstream-composer-drafts-list');
                                            const remaining = list ? list.querySelectorAll('.bitstream-composer-draft-item') : [];
                                            if (list && remaining.length === 0) {
                                                list.innerHTML = '<p class="bitstream-composer-drafts-empty">No drafts yet.</p>';
                                            }
                                            if (typeof window.updateQuickActionCounter === 'function') {
                                                window.updateQuickActionCounter('drafts');
                                            }
                                            setStatus('Draft deleted.');
                                        })
                                        .catch(err => { setStatus(err.message || 'Delete failed.', true); btn.disabled = false; });
                                });
                            }
                        });
                    });
                }

                // ── SAVE CURRENT BIT TO DRAFTS ACTION BUTTON ──
                if (composerSaveDraftActionBtn) {
                    composerSaveDraftActionBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (!window.bitstream_ajax || !submitNonce) { setStatus('Cannot save draft.', true); return; }

                        const content = textarea ? textarea.value.trim() : '';
                        const hasRebit = hRebitUrl && hRebitUrl.value.trim();
                        const hasMedia = hAttachmentId && parseInt(hAttachmentId.value || '0', 10) > 0;
                        const hasMood = hMoodEmotion && hMoodEmotion.value.trim();
                        const hasQuote = hQuotePostId && parseInt(hQuotePostId.value || '0', 10) > 0;
                        if (!content && !hasRebit && !hasMedia && !hasMood && !hasQuote) { setStatus('Nothing to save.', true); return; }

                        composerSaveDraftActionBtn.disabled = true;
                        const originalHtml = composerSaveDraftActionBtn.innerHTML;
                        composerSaveDraftActionBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>';
                        setStatus('Saving draft...');

                        const fd = new FormData(form);
                        fd.append('action', 'bitstream_submit_composer');
                        fd.append('nonce', submitNonce);
                        fd.append('composer_type', form.dataset.composerType || 'bit');
                        fd.append('save_as_draft', '1');

                        fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) throw new Error(data.data || 'Save failed.');
                                setStatus(data.data?.message || 'Saved as draft.');

                                composerFormIsDirty = false;

                                const feedBaseUrl = (window.bitstream_ajax && bitstream_ajax.feed_url)
                                    ? bitstream_ajax.feed_url
                                    : (window.location.origin + '/bitstream/');
                                const redirectUrl = new URL(feedBaseUrl, window.location.origin);
                                redirectUrl.searchParams.set('show_drafts', '1');
                                const createdPostId = parseInt(data.data?.post_id || '0', 10);
                                if (createdPostId > 0) {
                                    redirectUrl.searchParams.set('highlight_draft', String(createdPostId));
                                }
                                window.location.href = redirectUrl.toString();
                            })
                            .catch(err => {
                                setStatus(err.message || 'Save failed.', true);
                                composerSaveDraftActionBtn.disabled = false;
                                composerSaveDraftActionBtn.innerHTML = originalHtml;
                            });
                    });
                }

                // ── SCHEDULE MODAL ──
                const scheduleModal = composerRoot.querySelector('.bitstream-composer-modal-schedule');
                if (scheduleModal) {
                    const schedRadios = scheduleModal.querySelectorAll('input[name="bitstream_qp_schedule_mode"]');
                    const schedDatetime = scheduleModal.querySelector('.bitstream-composer-schedule-datetime-input');
                    const schedDone = scheduleModal.querySelector('.bitstream-composer-schedule-done');

                    schedRadios.forEach(radio => {
                        radio.addEventListener('change', () => {
                            if (schedDatetime) schedDatetime.disabled = (radio.value !== 'later');
                        });
                    });

                    if (schedDone) {
                        schedDone.addEventListener('click', () => {
                            const mode = scheduleModal.querySelector('input[name="bitstream_qp_schedule_mode"]:checked');
                            if (mode && mode.value === 'later') {
                                const dt = schedDatetime ? schedDatetime.value : '';
                                if (!dt) { setStatus('Pick a date and time.', true); return; }
                                if (hScheduleEnabled) hScheduleEnabled.value = '1';
                                if (hScheduleDatetime) hScheduleDatetime.value = dt;
                                if (previewScheduleDate) previewScheduleDate.textContent = new Date(dt).toLocaleString();
                                if (previewSchedule) previewSchedule.hidden = false;
                                if (submitBtn) {
                                    const isEdit = hEditPostId && hEditPostId.value !== '0';
                                    submitBtn.textContent = isEdit ? 'Update Scheduled Post' : 'Schedule Bit';
                                }
                            } else {
                                if (hScheduleEnabled) hScheduleEnabled.value = '0';
                                if (hScheduleDatetime) hScheduleDatetime.value = '';
                                if (previewSchedule) previewSchedule.hidden = true;
                                if (submitBtn) {
                                    const isEdit = hEditPostId && hEditPostId.value !== '0';
                                    submitBtn.textContent = isEdit ? 'Publish Draft' : 'Post Bit';
                                }
                            }
                            syncPreviewArea();
                            closeModal('schedule');
                        });
                    }
                }

                // ── SCHEDULED LIST MODAL ──
                const scheduledListModal = composerRoot.querySelector('.bitstream-composer-modal-scheduled-list');
                if (scheduledListModal) {
                    if (typeof window.parseEmojis === 'function') {
                        window.parseEmojis(scheduledListModal);
                    }
                    const schedFilterBtns = scheduledListModal.querySelectorAll('.bitstream-composer-scheduled-filter-btn');
                    const schedItems = scheduledListModal.querySelectorAll('.bitstream-composer-scheduled-item');
                    schedFilterBtns.forEach(btn => {
                        btn.addEventListener('click', () => {
                            const filter = btn.dataset.filter || 'all';
                            schedFilterBtns.forEach(b => b.classList.toggle('is-active', b === btn));
                            schedItems.forEach(item => {
                                const type = item.dataset.type || 'bit';
                                item.style.display = (filter === 'all' || filter === type) ? '' : 'none';
                            });
                        });
                    });

                    scheduledListModal.querySelectorAll('.bitstream-composer-scheduled-load').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const postId = btn.dataset.postId;
                            if (postId) {
                                loadPostIntoComposer(postId);
                                closeModal('scheduled-list', true);
                            }
                        });
                    });

                    scheduledListModal.querySelectorAll('.bitstream-composer-scheduled-delete').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const postId = btn.dataset.postId;
                            if (!postId || !window.bitstream_ajax) return;

                            if (typeof window.showDeleteConfirmation === 'function') {
                                window.showDeleteConfirmation('Delete this scheduled post?', () => {
                                    btn.disabled = true;
                                    const fd = new FormData();
                                    fd.append('action', 'bitstream_delete_post');
                                    fd.append('nonce', bitstream_ajax.delete_post_nonce);
                                    fd.append('post_id', postId);

                                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                                        .then(r => r.json())
                                        .then(data => {
                                            if (!data.success) throw new Error(data.data || 'Delete failed.');
                                            const item = btn.closest('.bitstream-composer-scheduled-item');
                                            if (item) item.remove();

                                            const list = scheduledListModal.querySelector('.bitstream-composer-scheduled-list');
                                            const remaining = list ? list.querySelectorAll('.bitstream-composer-scheduled-item') : [];
                                            if (list && remaining.length === 0) {
                                                list.innerHTML = '<p class="bitstream-composer-scheduled-empty">No scheduled Bits or Rebits yet.</p>';
                                            }
                                            if (typeof window.updateQuickActionCounter === 'function') {
                                                window.updateQuickActionCounter('scheduled-list');
                                            }
                                            setStatus('Scheduled post deleted.');
                                        })
                                        .catch(err => { setStatus(err.message || 'Delete failed.', true); btn.disabled = false; });
                                });
                            }
                        });
                    });
                }

                // Check page load URL parameters to open modals or focus composer elements
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('show_drafts') || urlParams.get('composer_tab') === 'drafts') {
                    openModal('drafts');
                }
                if (urlParams.has('show_scheduled') || urlParams.get('composer_tab') === 'scheduled') {
                    openModal('scheduled-list');
                }
                if (urlParams.has('show_settings') || urlParams.get('composer_tab') === 'settings') {
                    openModal('settings');
                }
                if (urlParams.has('show_rebit') || urlParams.get('composer_tab') === 'rebit') {
                    const rebitBtn = composerRoot.querySelector('[data-composer-modal="rebit"]');
                    if (rebitBtn) {
                        setTimeout(() => {
                            const isMobile = window.innerWidth < 1024;
                            if (isMobile) {
                                composerRoot.dataset.quickActionSource = 'new-rebit';
                            }
                            rebitBtn.click();
                            if (!isMobile) {
                                rebitBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 100);
                    }
                }
                if (urlParams.has('focus_composer') || urlParams.get('composer_tab') === 'bit') {
                    const isMobile = window.innerWidth < 1024;
                    if (isMobile) {
                        composerRoot.hidden = false;
                    }
                    if (textarea) {
                        setTimeout(() => {
                            textarea.focus();
                            if (!isMobile) {
                                textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 100);
                    }
                }

                const quotePostIdFromUrl = parseInt(urlParams.get('quote_post_id') || '0', 10);
                if (quotePostIdFromUrl > 0 && typeof window.openTimelineQuoteModal === 'function') {
                    setTimeout(() => window.openTimelineQuoteModal(quotePostIdFromUrl), 150);
                }

                // Handle PWA share target redirection payload
                if (urlParams.has('share_target') && urlParams.has('shared_id')) {
                    const sharedId = urlParams.get('shared_id');
                    const dbRequest = indexedDB.open('bitstream-pwa-share-db', 1);
                    dbRequest.onsuccess = (event) => {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains('shared-payloads')) {
                            return;
                        }
                        const transaction = db.transaction('shared-payloads', 'readonly');
                        const store = transaction.objectStore('shared-payloads');
                        const getRequest = store.get(sharedId);
                        getRequest.onsuccess = () => {
                            const payload = getRequest.result;
                            if (!payload) return;

                            const extractShareUrl = (url, text, title) => {
                                if (url && url.startsWith('http')) return url;
                                if (text && text.startsWith('http')) return text;
                                const allContent = [url, text, title].filter(Boolean).join(' ');
                                const match = allContent.match(/https?:\/\/[^\s]+/);
                                return match ? match[0] : '';
                            };

                            const cleanShareText = (text, finalUrl) => {
                                if (!text) return '';
                                let clean = text;
                                if (finalUrl) {
                                    clean = clean.replace(finalUrl, '');
                                }
                                return clean.replace(/\s+/g, ' ').trim();
                            };

                            const finalUrl = extractShareUrl(payload.url, payload.text, payload.title);
                            const cleanText = cleanShareText(payload.text, finalUrl);

                            const isMobile = window.innerWidth < 1024;
                            if (isMobile) {
                                composerRoot.hidden = false;
                            }

                            if (cleanText && textarea) {
                                textarea.value = cleanText;
                                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                            }

                            if (finalUrl) {
                                if (hRebitUrl) {
                                    hRebitUrl.value = finalUrl;
                                }
                                form.dataset.composerType = 'rebit';
                                if (textarea) textarea.required = false;

                                const rebitBtn = composerRoot.querySelector('[data-composer-modal="rebit"]');
                                if (rebitBtn) {
                                    rebitBtn.click();
                                }

                                const mRebitUrl = composerRoot.querySelector('#bitstream-composer-modal-rebit-url');
                                if (mRebitUrl) {
                                    mRebitUrl.value = finalUrl;
                                }
                                const mRebitFetch = composerRoot.querySelector('.bitstream-composer-rebit-fetch');
                                if (mRebitFetch) {
                                    mRebitFetch.classList.remove('is-edit-mode');
                                    mRebitFetch.textContent = 'Fetch metadata';
                                    setTimeout(() => {
                                        mRebitFetch.click();
                                    }, 100);
                                }

                                if (typeof renderRebitLivePreview === 'function') {
                                    renderRebitLivePreview(finalUrl);
                                }
                                syncPreviewArea();
                            }

                            if (payload.mediaFiles && payload.mediaFiles.length > 0) {
                                if (typeof openModal === 'function') {
                                    openModal('media');
                                }

                                const mediaModal = composerRoot.querySelector('.bitstream-composer-modal-media');
                                const mMediaDone = mediaModal ? mediaModal.querySelector('.bitstream-composer-media-done') : null;

                                if (typeof window.uploadMultipleFiles === 'function') {
                                    window.uploadMultipleFiles(payload.mediaFiles, 'bitstream-composer-modal-media-attachment-id', 'bitstream-composer-modal-media-preview', {
                                        setStatus: (msg, isError) => setStatus(msg, isError)
                                    }).then(() => {
                                        const mediaModal = composerRoot.querySelector('.bitstream-composer-modal-media');
                                        const mMediaPreview = mediaModal ? mediaModal.querySelector('#bitstream-composer-modal-media-preview') : null;
                                        const attachments = mMediaPreview ? (typeof window.getExistingAttachments === 'function' ? window.getExistingAttachments(mMediaPreview) : []) : [];
                                        if (attachments.length > 0 && mMediaDone) {
                                            mMediaDone.click();
                                        }
                                    }).catch(err => {
                                        console.error('BitStream: PWA shared media upload failed:', err);
                                    });
                                }
                            }

                            try {
                                const deleteTx = db.transaction('shared-payloads', 'readwrite');
                                deleteTx.objectStore('shared-payloads').delete(sharedId);
                            } catch (e) {
                                console.warn('BitStream: Failed to delete processed PWA share payload:', e);
                            }
                        };
                    };
                }

                // Auto-save Composer form content
                let composerFormIsDirty = false;
                if (textarea) {
                    textarea.addEventListener('input', () => { composerFormIsDirty = true; });
                }
                window.addEventListener('beforeunload', () => {
                    if (!composerFormIsDirty) return;
                    if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) return;

                    const content = textarea ? textarea.value.trim() : '';
                    const hasRebit = hRebitUrl && hRebitUrl.value.trim();
                    const hasMedia = hAttachmentId && parseInt(hAttachmentId.value || '0', 10) > 0;
                    const hasMood = hMoodEmotion && hMoodEmotion.value.trim();
                    const hasQuote = hQuotePostId && parseInt(hQuotePostId.value || '0', 10) > 0;
                    if (!content && !hasRebit && !hasMedia && !hasMood && !hasQuote) return;

                    const fd = new FormData(form);
                    fd.append('action', 'bitstream_submit_composer');
                    fd.append('nonce', submitNonce);
                    fd.append('composer_type', form.dataset.composerType || 'bit');
                    fd.append('save_as_draft', '1');
                    fd.append('is_auto_draft', '1');

                    navigator.sendBeacon(bitstream_ajax.ajax_url, fd);
                    composerFormIsDirty = false;
                });

                const initialRebitUrl = hRebitUrl ? hRebitUrl.value.trim() : '';
                if (initialRebitUrl) {
                    if (previewRebitCard) {
                        previewRebitCard.innerHTML = '<p style="font-size:0.85rem;color:#555;">Rebit: ' + initialRebitUrl + '</p>';
                    }
                    if (previewRebit) previewRebit.hidden = false;
                    form.dataset.composerType = 'rebit';
                    if (textarea) textarea.required = false;
                    syncPreviewArea();
                }

                const initialAttachmentId = hAttachmentId ? hAttachmentId.value.trim() : '';
                if (initialAttachmentId && initialAttachmentId !== '0') {
                    if (previewMediaThumb) {
                        previewMediaThumb.innerHTML = '<span>Loading preview...</span>';
                    }
                    if (previewMedia) previewMedia.hidden = false;
                    syncPreviewArea();
                }

                const initialEditPostId = hEditPostId ? parseInt(hEditPostId.value || '0', 10) : 0;
                if (initialEditPostId > 0) {
                    loadPostIntoComposer(initialEditPostId);
                }

                // ── FORM SUBMIT ──
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (!window.bitstream_ajax || !submitNonce) { setStatus('Submit unavailable.', true); return; }

                    const composerType = form.dataset.composerType || 'bit';
                    const content = textarea ? textarea.value.trim() : '';
                    const hasMedia = hAttachmentId && parseInt(hAttachmentId.value || '0', 10) > 0;
                    const hasRebit = hRebitUrl && hRebitUrl.value.trim();
                    const hasMood = hMoodEmotion && hMoodEmotion.value.trim();
                    const hasQuote = hQuotePostId && parseInt(hQuotePostId.value || '0', 10) > 0;

                    let effectiveType = composerType;
                    if (composerType === 'bit' && !hasMedia && !hasRebit && content) {
                        try {
                            const u = new URL(content);
                            if (u.protocol === 'http:' || u.protocol === 'https:') effectiveType = 'rebit';
                        } catch { }
                    }

                    if (effectiveType === 'bit' && !content && !hasMedia && !hasMood && !hasQuote) { setStatus('Write something or attach media.', true); return; }

                    setStatus(effectiveType === 'rebit' ? 'Posting ReBit...' : 'Posting...');
                    if (submitBtn) submitBtn.disabled = true;

                    const fd = new FormData(form);
                    fd.append('action', 'bitstream_submit_composer');
                    fd.append('nonce', submitNonce);
                    fd.append('composer_type', effectiveType);

                    if (effectiveType === 'rebit' && !hasRebit && content) {
                        fd.set('rebit_url', content);
                        fd.delete('bit_content');
                    }

                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success) throw new Error(data.data || 'Post failed.');

                            const responseData = data.data || {};
                            setStatus(responseData.message || 'Posted!');

                            composerFormIsDirty = false;

                            const createdPostId = parseInt(responseData.post_id || '0', 10);
                            const isScheduled = !!responseData.is_scheduled;

                            if (isScheduled) {
                                const composerBaseUrl = (window.bitstream_ajax && bitstream_ajax.composer_url)
                                    ? bitstream_ajax.composer_url
                                    : (window.location.origin + '/bitstream/');
                                const redirectUrl = new URL(composerBaseUrl, window.location.origin);
                                redirectUrl.searchParams.set('show_scheduled', '1');
                                if (createdPostId > 0) {
                                    redirectUrl.searchParams.set('highlight_scheduled', String(createdPostId));
                                }
                                window.location.href = redirectUrl.toString();
                                return;
                            }

                            const feedBaseUrl = (window.bitstream_ajax && bitstream_ajax.feed_url)
                                ? bitstream_ajax.feed_url
                                : (window.location.origin + '/bitstream/');
                            const feedUrl = new URL(feedBaseUrl, window.location.origin);
                            if (createdPostId > 0) {
                                feedUrl.searchParams.set('highlight_bit', String(createdPostId));
                            }
                            window.location.href = feedUrl.toString();
                        })
                        .catch(err => {
                            setStatus(err.message || 'Network error.', true);
                            if (submitBtn) submitBtn.disabled = false;
                        });
                });

                window.bitstreamCloseModal = closeModal;
                window.bitstreamOpenModal = openModal;
                window.bitstreamClearComposer = clearComposer;

                // Settings panels tab switcher and Force Update inside composer
                const settingsRoot = composerRoot.querySelector('.bitstream-settings');
                if (settingsRoot) {
                    const settingsTabButtons = settingsRoot.querySelectorAll('.bitstream-settings-tab');

                    settingsTabButtons.forEach(button => {
                        button.addEventListener('click', () => {
                            const selectedTab = button.dataset.settingsTab;

                            if (button.classList.contains('is-active')) {
                                return;
                            }

                            settingsTabButtons.forEach(btn => {
                                btn.classList.remove('is-active');
                                btn.setAttribute('aria-selected', 'false');
                            });
                            button.classList.add('is-active');
                            button.setAttribute('aria-selected', 'true');

                            const panels = settingsRoot.querySelectorAll('.bitstream-settings-panel');
                            panels.forEach(panel => {
                                if (panel.id === `bitstream-settings-panel-${selectedTab}`) {
                                    panel.classList.add('is-active');
                                    panel.hidden = false;
                                } else {
                                    panel.classList.remove('is-active');
                                    panel.hidden = true;
                                }
                            });

                            const url = new URL(window.location.href);
                            url.searchParams.set('settings_tab', selectedTab);
                            url.searchParams.set('show_settings', '1');
                            window.history.replaceState({}, '', url.toString());
                        });
                    });

                    // Force Update PWA
                    const forceUpdateBtn = settingsRoot.querySelector('#bitstream-force-update-btn');
                    if (forceUpdateBtn) {
                        forceUpdateBtn.addEventListener('click', async () => {
                            const originalHtml = forceUpdateBtn.innerHTML;
                            forceUpdateBtn.disabled = true;
                            forceUpdateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Updating...';

                            try {
                                if ('serviceWorker' in navigator) {
                                    const registrations = await navigator.serviceWorker.getRegistrations();
                                    for (const registration of registrations) {
                                        await registration.unregister();
                                    }
                                }

                                if ('caches' in window) {
                                    const cacheNames = await caches.keys();
                                    for (const name of cacheNames) {
                                        await caches.delete(name);
                                    }
                                }

                                forceUpdateBtn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Updated! Reloading...';
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1000);
                            } catch (error) {
                                console.error('BitStream PWA force update failed:', error);
                                forceUpdateBtn.disabled = false;
                                forceUpdateBtn.innerHTML = originalHtml;
                                alert('Update failed: ' + error.message);
                            }
                        });
                    }
                }

                // Copy buttons for RSS feeds
                composerRoot.querySelectorAll('.bitstream-copy-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const text = btn.dataset.copyText;
                        if (!text) return;

                        navigator.clipboard.writeText(text).then(() => {
                            const originalText = btn.textContent;
                            btn.textContent = 'Copied!';
                            setTimeout(() => {
                                btn.textContent = originalText;
                            }, 2000);
                        }).catch(() => {
                            const textarea = document.createElement('textarea');
                            textarea.value = text;
                            textarea.style.position = 'fixed';
                            textarea.style.opacity = '0';
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);

                            const originalText = btn.textContent;
                            btn.textContent = 'Copied!';
                            setTimeout(() => {
                                btn.textContent = originalText;
                            }, 2000);
                        });
                    });
                });
            });
        }
    };
})();
