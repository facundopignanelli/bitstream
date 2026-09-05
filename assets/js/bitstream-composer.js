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
                    if (window.BitStream && window.BitStream.Editor) {
                        window.BitStream.Editor.init(textarea);
                    }

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
                    
                    if (window.matchMedia('(max-width: 1023px)').matches && (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || ''))) {
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
                const previewMoodEmoji = form.querySelector('.bitstream-composer-preview-mood-emoji');
                const previewMoodText = form.querySelector('.bitstream-composer-preview-mood-text');

                function setPreviewMoodDisplay(emoji, emotion) {
                    if (!previewMood) return;
                    if (emotion) {
                        if (previewMoodEmoji) previewMoodEmoji.textContent = emoji || '';
                        if (previewMoodText) previewMoodText.innerHTML = `feeling <strong>${emotion}</strong>`;
                        if (typeof window.parseEmojis === 'function') {
                            if (previewMoodEmoji) window.parseEmojis(previewMoodEmoji);
                            if (previewMoodText) window.parseEmojis(previewMoodText);
                        }
                        previewMood.hidden = false;
                    } else {
                        if (previewMoodEmoji) previewMoodEmoji.textContent = '';
                        if (previewMoodText) previewMoodText.textContent = '';
                        previewMood.hidden = true;
                    }
                }
                const editBanner = form.querySelector('#bitstream-composer-edit-banner');
                const editBannerTitle = form.querySelector('#bitstream-composer-edit-title');
                const editCancelBtn = form.querySelector('#bitstream-composer-edit-cancel-btn');
                const previewQuote = form.querySelector('.bitstream-composer-preview-quote');
                const previewQuoteCard = form.querySelector('.bitstream-composer-preview-quote-card');

                // Mood popover/modal elements
                const moodModal = composerRoot.querySelector('.bitstream-composer-popover-mood, .bitstream-composer-modal-mood');
                let customMoods = (window.bitstream_ajax && bitstream_ajax.custom_moods) || [];
                if (typeof customMoods === 'object' && !Array.isArray(customMoods)) {
                    customMoods = Object.values(customMoods);
                }
                let activeEditMoodForm = null;
                const previewDraft = null;
                const previewDraftLabel = null;

                // Carousel scroll listener reference so we can remove it when re-syncing
                let _carouselScrollHandler = null;

                // Save Draft Button
                const composerSaveDraftBtn = null;
                const composerSaveDraftActionBtn = form.querySelector('.bitstream-composer-save-draft-action');
                const scheduleBtn = form.querySelector('[data-composer-popover-trigger="schedule"], [data-composer-modal="schedule"]');

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
                    const hasQuote = previewQuote && !previewQuote.hidden;
                    if (previewArea) previewArea.hidden = !(hasRebit || hasMedia || hasSched || hasDraft || hasMood || hasQuote);
                    if (textarea) textarea.required = !(hasRebit || hasMedia || hasMood || hasQuote);

                    // Carousel dot indicators — only on mobile/tablet (<1024px)
                    if (!previewCarousel || !previewDotsEl) return;

                    // Remove old scroll listener before potentially re-attaching
                    if (_carouselScrollHandler) {
                        previewCarousel.removeEventListener('scroll', _carouselScrollHandler);
                        _carouselScrollHandler = null;
                    }

                    const isMobileCarousel = window.innerWidth < 1024;
                    const activeCards = [];
                    if (hasRebit && previewRebit) activeCards.push({ card: previewRebit, label: 'Rebit' });
                    if (hasMedia && previewMedia) activeCards.push({ card: previewMedia, label: 'Media' });
                    if (hasMood && previewMood) activeCards.push({ card: previewMood, label: 'Mood' });

                    if (!isMobileCarousel || activeCards.length <= 1) {
                        // Desktop or <= 1 card: hide dots, no snap needed
                        previewDotsEl.hidden = true;
                        previewDotsEl.innerHTML = '';
                        return;
                    }

                    // Multiple cards present on mobile/tablet: build dots
                    previewDotsEl.innerHTML = '';
                    previewDotsEl.hidden = false;
                    previewDotsEl.setAttribute('aria-hidden', 'true');

                    activeCards.forEach(function (item, i) {
                        const dot = document.createElement('button');
                        dot.type = 'button';
                        dot.className = 'bitstream-composer-preview-dot' + (i === 0 ? ' is-active' : '');
                        dot.setAttribute('aria-label', 'Go to ' + item.label);
                        dot.addEventListener('click', function () {
                            previewCarousel.scrollTo({ left: item.card.offsetLeft, behavior: 'smooth' });
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
                    if (name === 'composer') {
                        composerRoot.hidden = false;
                        if (typeof window.bitstreamSyncBottomNav === 'function') {
                            window.bitstreamSyncBottomNav();
                        }
                        const modalBody = composerRoot.querySelector('.bitstream-composer-modal-body');
                        if (modalBody) modalBody.scrollTop = 0;
                        const isMobile = window.innerWidth < 1024;
                        setTimeout(() => {
                            if (textarea) textarea.focus({ preventScroll: true });
                        }, isMobile ? 300 : 50);
                        return;
                    }
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
                            renderMoodReactions();
                        }
                    }
                    if (typeof window.bitstreamSyncBottomNav === 'function') {
                        window.bitstreamSyncBottomNav();
                    }
                }
                let stashedDraft = null;

                function enterEditMode(postId, postType = 'bit') {
                    const currentContent = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
                    const hasMedia = hAttachmentId && parseInt(hAttachmentId.value || '0', 10) > 0;
                    const hasMood = hMoodEmotion && hMoodEmotion.value.trim();
                    const hasRebit = hRebitUrl && hRebitUrl.value.trim();
                    const hasQuote = hQuotePostId && parseInt(hQuotePostId.value || '0', 10) > 0;

                    if (!hEditPostId || parseInt(hEditPostId.value || '0', 10) <= 0) {
                        if (currentContent || hasMedia || hasMood || hasRebit || hasQuote) {
                            stashedDraft = {
                                content: currentContent,
                                attachmentId: hAttachmentId ? hAttachmentId.value : '',
                                attachmentIds: hAttachmentIds ? hAttachmentIds.value : '',
                                rebitUrl: hRebitUrl ? hRebitUrl.value : '',
                                rebitOgTitle: hRebitOgTitle ? hRebitOgTitle.value : '',
                                rebitOgDesc: hRebitOgDesc ? hRebitOgDesc.value : '',
                                rebitOgImage: hRebitOgImage ? hRebitOgImage.value : '',
                                moodEmoji: hMoodEmoji ? hMoodEmoji.value : '',
                                moodEmotion: hMoodEmotion ? hMoodEmotion.value : '',
                                quotePostId: hQuotePostId ? hQuotePostId.value : '',
                                previewMediaHtml: previewMediaThumb ? previewMediaThumb.innerHTML : '',
                                previewRebitHtml: previewRebitCard ? previewRebitCard.innerHTML : '',
                                previewQuoteHtml: previewQuoteCard ? previewQuoteCard.innerHTML : '',
                                hasMedia: previewMedia ? !previewMedia.hidden : false,
                                hasRebit: previewRebit ? !previewRebit.hidden : false,
                                hasMood: previewMood ? !previewMood.hidden : false,
                                hasQuote: previewQuote ? !previewQuote.hidden : false
                            };
                        }
                    }

                    if (hEditPostId) hEditPostId.value = String(postId);
                    if (editBanner) editBanner.hidden = false;
                    if (editBannerTitle) editBannerTitle.textContent = postType === 'rebit' ? `Editing Rebit #${postId}` : `Editing Bit #${postId}`;
                    if (submitBtn) submitBtn.textContent = postType === 'rebit' ? 'Update Rebit' : 'Update Bit';
                    if (composerSaveDraftActionBtn) composerSaveDraftActionBtn.style.display = 'none';

                    if (window.innerWidth >= 1024) {
                        composerRoot.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        composerRoot.classList.remove('pulse-highlight');
                        void composerRoot.offsetWidth;
                        composerRoot.classList.add('pulse-highlight');
                        setTimeout(() => composerRoot.classList.remove('pulse-highlight'), 1000);
                    } else {
                        openModal('composer');
                    }
                }

                function exitEditMode() {
                    if (hEditPostId) hEditPostId.value = '0';
                    if (editBanner) editBanner.hidden = true;
                    if (submitBtn) submitBtn.textContent = 'Post Bit';
                    if (composerSaveDraftActionBtn) composerSaveDraftActionBtn.style.display = 'block';
                    if (scheduleBtn) {
                        scheduleBtn.disabled = false;
                        scheduleBtn.classList.remove('is-disabled');
                        scheduleBtn.title = 'Schedule';
                    }

                    if (stashedDraft) {
                        if (textarea) {
                            if (window.BitStream && window.BitStream.Editor) {
                                window.BitStream.Editor.setEditorValue(textarea, stashedDraft.content);
                            } else {
                                textarea.value = stashedDraft.content;
                            }
                        }
                        if (hAttachmentId) hAttachmentId.value = stashedDraft.attachmentId;
                        if (hAttachmentIds) hAttachmentIds.value = stashedDraft.attachmentIds;
                        if (hRebitUrl) hRebitUrl.value = stashedDraft.rebitUrl;
                        if (hRebitOgTitle) hRebitOgTitle.value = stashedDraft.rebitOgTitle;
                        if (hRebitOgDesc) hRebitOgDesc.value = stashedDraft.rebitOgDesc;
                        if (hRebitOgImage) hRebitOgImage.value = stashedDraft.rebitOgImage;
                        if (hMoodEmoji) hMoodEmoji.value = stashedDraft.moodEmoji;
                        if (hMoodEmotion) hMoodEmotion.value = stashedDraft.moodEmotion;
                        if (hQuotePostId) hQuotePostId.value = stashedDraft.quotePostId;

                        if (previewMediaThumb) previewMediaThumb.innerHTML = stashedDraft.previewMediaHtml;
                        if (previewMedia) previewMedia.hidden = !stashedDraft.hasMedia;
                        if (previewRebitCard) previewRebitCard.innerHTML = stashedDraft.previewRebitHtml;
                        if (previewRebit) previewRebit.hidden = !stashedDraft.hasRebit;
                        if (stashedDraft.hasMood && stashedDraft.moodEmotion) {
                            setPreviewMoodDisplay(stashedDraft.moodEmoji, stashedDraft.moodEmotion);
                        } else {
                            setPreviewMoodDisplay('', '');
                        }
                        if (previewQuoteCard) previewQuoteCard.innerHTML = stashedDraft.previewQuoteHtml;
                        if (previewQuote) previewQuote.hidden = !stashedDraft.hasQuote;

                        stashedDraft = null;
                    } else {
                        clearComposer();
                    }
                    syncPreviewArea();
                }

                function loadEditPostData(postId, postType = 'bit') {
                    enterEditMode(postId, postType);
                    setStatus('Loading post data…');

                    const fd = new FormData();
                    fd.append('action', 'bitstream_get_post_edit_data');
                    fd.append('nonce', submitNonce);
                    fd.append('post_id', postId);

                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (!res.success || !res.data) throw new Error(res.data || 'Could not load post data.');
                            const data = res.data;

                            if (textarea) {
                                if (window.BitStream && window.BitStream.Editor) {
                                    window.BitStream.Editor.setEditorValue(textarea, data.content || '');
                                } else {
                                    textarea.value = data.content || '';
                                }
                            }
                            if (hMoodEmoji) hMoodEmoji.value = data.mood_emoji || '';
                            if (hMoodEmotion) hMoodEmotion.value = data.mood_emotion || '';
                            setPreviewMoodDisplay(data.mood_emoji || '', data.mood_emotion || '');

                            if (data.post_type === 'rebit' && data.rebit_url) {
                                if (hRebitUrl) hRebitUrl.value = data.rebit_url;
                                if (hRebitOgTitle) hRebitOgTitle.value = data.og_title || '';
                                if (hRebitOgDesc) hRebitOgDesc.value = data.og_desc || '';
                                if (hRebitOgImage) hRebitOgImage.value = data.og_image || '';
                                if (hRebitAttachmentId) hRebitAttachmentId.value = data.rebit_attachment_id || '0';
                                if (renderRebitLivePreview) renderRebitLivePreview(data.rebit_url);
                            }

                            if (data.quote_post_id > 0 && data.quote_preview_html) {
                                if (hQuotePostId) hQuotePostId.value = String(data.quote_post_id);
                                if (previewQuoteCard) previewQuoteCard.innerHTML = data.quote_preview_html;
                                if (previewQuote) previewQuote.hidden = false;
                            }

                            if (data.attachments && data.attachments.length > 0) {
                                if (hAttachmentIds) hAttachmentIds.value = data.attachment_ids || '';
                                if (hAttachmentId) hAttachmentId.value = String(data.attachment_id || data.attachments[0].id);
                                if (typeof window.updateAttachmentsList === 'function' && previewMediaThumb) {
                                    window.updateAttachmentsList(previewMediaThumb, data.attachments);
                                } else if (previewMedia) {
                                    previewMedia.hidden = false;
                                }
                            }

                            if (data.post_status === 'publish') {
                                if (scheduleBtn) {
                                    scheduleBtn.disabled = true;
                                    scheduleBtn.classList.add('is-disabled');
                                    scheduleBtn.title = 'Scheduling is disabled when editing a published bit';
                                }
                            } else {
                                if (scheduleBtn) {
                                    scheduleBtn.disabled = false;
                                    scheduleBtn.classList.remove('is-disabled');
                                    scheduleBtn.title = 'Schedule';
                                }
                            }

                            setStatus('');
                            syncPreviewArea();
                        })
                        .catch(err => {
                            setStatus(err.message || 'Error loading post.', true);
                        });
                }

                function loadQuoteData(quotePostId) {
                    if (hQuotePostId) hQuotePostId.value = String(quotePostId);
                    setStatus('Loading quote preview…');

                    const fd = new FormData();
                    fd.append('action', 'bitstream_get_quote_preview');
                    fd.append('nonce', submitNonce);
                    fd.append('post_id', quotePostId);
                    fd.append('quote_post_id', quotePostId);

                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (!res.success || !res.data) throw new Error(res.data || 'Could not load quote preview.');
                            const responseData = res.data;

                            if (previewQuoteCard) previewQuoteCard.innerHTML = responseData.quote_preview_html || '';
                            if (previewQuote) previewQuote.hidden = false;
                            setStatus('');
                            syncPreviewArea();

                            if (window.innerWidth >= 1024) {
                                composerRoot.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                if (textarea) textarea.focus();
                            } else {
                                openModal('composer');
                                if (textarea) textarea.focus();
                            }
                        })
                        .catch(err => {
                            setStatus(err.message || 'Error loading quote.', true);
                        });
                }

                if (editCancelBtn) {
                    editCancelBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        exitEditMode();
                    });
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
                    if (textarea) {
                        if (window.BitStream && window.BitStream.Editor) {
                            window.BitStream.Editor.setEditorValue(textarea, '');
                        } else {
                            textarea.value = '';
                        }
                    }
                    if (hScheduleEnabled) hScheduleEnabled.value = '0';
                    if (hScheduleDatetime) hScheduleDatetime.value = '';
                    if (previewSchedule) previewSchedule.hidden = true;
                    if (previewDraft) previewDraft.hidden = true;
                    if (hMoodEmoji) hMoodEmoji.value = '';
                    if (hMoodEmotion) hMoodEmotion.value = '';
                    if (previewMood) previewMood.hidden = true;
                    if (hQuotePostId) hQuotePostId.value = '0';
                    if (previewQuoteCard) previewQuoteCard.innerHTML = '';
                    if (previewQuote) previewQuote.hidden = true;
                    if (editBanner) editBanner.hidden = true;
                    if (scheduleBtn) {
                        scheduleBtn.disabled = false;
                        scheduleBtn.classList.remove('is-disabled');
                        scheduleBtn.title = 'Schedule';
                    }
                    const rebitPopover = composerRoot.querySelector('.bitstream-composer-popover-rebit');
                    if (rebitPopover) {
                        const rInput = rebitPopover.querySelector('#bitstream-composer-rebit-popover-input, .bitstream-rebit-popover-input');
                        if (rInput) rInput.value = '';
                        const rStatus = rebitPopover.querySelector('#bitstream-composer-rebit-popover-status');
                        if (rStatus) { rStatus.hidden = true; rStatus.textContent = ''; }
                    }
                    const inlineRebitMeta = form.querySelector('#bitstream-composer-rebit-inline-meta');
                    if (inlineRebitMeta) inlineRebitMeta.hidden = true;
                    const fileInput = form.querySelector('#bitstream-composer-file-input');
                    if (fileInput) fileInput.value = '';
                    if (typeof closeAllPopovers === 'function') closeAllPopovers();
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
                        const content = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
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
                                    name === 'settings'
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
                    if (!trigger || trigger.hasAttribute('data-composer-modal-close') || trigger.disabled || trigger.classList.contains('is-disabled')) return;

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
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        const editType = btn.dataset.composerEdit;
                        if (editType === 'rebit') {
                            if (typeof openRebitMetaEditor === 'function') {
                                openRebitMetaEditor();
                            }
                        } else if (editType === 'schedule') {
                            const schedBtn = composerRoot.querySelector('[data-composer-popover-trigger="schedule"]');
                            if (typeof togglePopover === 'function') togglePopover('schedule', schedBtn);
                        } else if (editType === 'mood') {
                            activeEditMoodForm = null;
                            const moodBtn = composerRoot.querySelector('[data-composer-popover-trigger="mood"]');
                            if (typeof togglePopover === 'function') togglePopover('mood', moodBtn);
                        } else if (editType === 'media') {
                            const openCropperFn = window.bitstreamOpenCropper || (window.BitStream && window.BitStream.UI && window.BitStream.UI.openCropper);
                            if (openCropperFn) {
                                const attachments = previewMediaThumb && typeof window.getExistingAttachments === 'function' ? window.getExistingAttachments(previewMediaThumb) : [];
                                const targetAttachment = attachments.length > 0 ? attachments[0] : null;
                                const targetId = targetAttachment ? targetAttachment.id : (hAttachmentId ? parseInt(hAttachmentId.value || '0', 10) : 0);
                                const targetUrl = targetAttachment ? (targetAttachment.url || '') : '';

                                openCropperFn('bitstream-composer-attachment-id', 'bitstream-composer-preview-media-thumb', {
                                    attachmentId: targetId,
                                    url: targetUrl,
                                    onComplete: (croppedMedia) => {
                                        if (croppedMedia && croppedMedia.id) {
                                            const updated = attachments.length > 0 ? [croppedMedia, ...attachments.slice(1)] : [croppedMedia];
                                            if (typeof window.updateAttachmentsList === 'function' && previewMediaThumb) {
                                                window.updateAttachmentsList(previewMediaThumb, updated);
                                            }
                                            if (hAttachmentId) hAttachmentId.value = String(croppedMedia.id);
                                            if (hAttachmentIds) hAttachmentIds.value = updated.map(a => a.id).join(',');
                                            syncPreviewArea();
                                            if (typeof setStatus === 'function') setStatus('Image cropped.');
                                        }
                                    }
                                });
                            } else if (typeof setStatus === 'function') {
                                setStatus('Image cropper is unavailable.', true);
                            }
                        } else {
                            openModal(editType);
                        }
                    });
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
                            const inlineRebitMeta = form.querySelector('#bitstream-composer-rebit-inline-meta');
                            if (inlineRebitMeta) inlineRebitMeta.hidden = true;
                            const rInput = composerRoot.querySelector('#bitstream-composer-rebit-popover-input, .bitstream-rebit-popover-input');
                            if (rInput) rInput.value = '';
                            form.dataset.composerType = 'bit';
                            if (textarea) textarea.required = true;
                            if (typeof setStatus === 'function') setStatus('');
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
                        if (type === 'quote') {
                            if (hQuotePostId) hQuotePostId.value = '0';
                            if (previewQuote) previewQuote.hidden = true;
                            if (previewQuoteCard) previewQuoteCard.innerHTML = '';
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
                                    const cleanChar = char.replace(/\uFE0F/g, '');
                                    const entry = Object.values(_emojiData.map).find(e => unifiedToChar(e.unified).replace(/\uFE0F/g, '') === cleanChar);
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

                // Mood Reactions & Emoji Picker Implementation
                const moodEmojiPicker = createBsEmojiPicker();
                const textEmojiPicker = createBsEmojiPicker();

                const PRELOADED_MOODS = [
                    { emoji: '😊', emotion: 'Happy' },
                    { emoji: '😢', emotion: 'Sad' },
                    { emoji: '😴', emotion: 'Tired' },
                    { emoji: '🤩', emotion: 'Excited' },
                    { emoji: '🤪', emotion: 'Silly' },
                    { emoji: '🤔', emotion: 'Pensive' },
                    { emoji: '😠', emotion: 'Angry' },
                    { emoji: '😌', emotion: 'Relieved' },
                    { emoji: '🤯', emotion: 'Mind-blown' }
                ];

                function getAllMoods() {
                    const userCustomMoods = Array.isArray(customMoods) ? customMoods : (typeof customMoods === 'object' && customMoods ? Object.values(customMoods) : []);

                    // If customMoods already contains preloaded items (happens after any reorder/delete/full edit),
                    // use it exclusively so the user's ordering is preserved.
                    const hasPreloaded = userCustomMoods.some(cm =>
                        PRELOADED_MOODS.some(pm => pm.emoji === cm.emoji && pm.emotion.toLowerCase() === cm.emotion.toLowerCase())
                    );
                    if (hasPreloaded) {
                        return userCustomMoods.filter(m => m && m.emotion && m.emoji);
                    }

                    // Otherwise merge: preloaded first, then user-only custom moods
                    const list = [...PRELOADED_MOODS];
                    userCustomMoods.forEach(cm => {
                        if (cm && cm.emotion && cm.emoji) {
                            const exists = list.some(m => m.emotion.toLowerCase() === cm.emotion.toLowerCase() && m.emoji === cm.emoji);
                            if (!exists) list.push(cm);
                        }
                    });
                    return list;
                }

                function applyMoodSelection(emoji, emotion) {
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
                        setPreviewMoodDisplay(emoji, emotion);
                        syncPreviewArea();
                    }
                }

                // ── Mood edit mode state ──────────────────────────────────────────────
                let moodEditMode = false;
                let moodEditingIndex = null; // index in getAllMoods() being edited via creator

                // Undo state
                let undoTimer = null;
                let undoMoodData = null; // { moods: [...snapshot], index: int }

                // Drag state
                let dragSrcIndex = null;

                function getAllMoodsLive() {
                    return getAllMoods(); // already defined above
                }

                function saveMoodsToServer(moodsArray) {
                    if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) return;
                    const fd = new FormData();
                    fd.append('action', 'bitstream_save_custom_moods');
                    fd.append('nonce', submitNonce);
                    fd.append('moods', JSON.stringify(moodsArray));
                    fetch(bitstream_ajax.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    }).catch(err => console.error('BitStream: Error saving moods:', err));
                }

                function showUndoToast(label) {
                    const toast = moodModal ? moodModal.querySelector('#bitstream-mood-undo-toast') : null;
                    const msg = toast ? toast.querySelector('.bitstream-mood-undo-msg') : null;
                    if (!toast) return;
                    if (msg) msg.textContent = `"${label}" removed`;
                    toast.hidden = false;
                    if (undoTimer) clearTimeout(undoTimer);
                    undoTimer = setTimeout(() => {
                        toast.hidden = true;
                        undoMoodData = null;
                    }, 5000);
                }

                function hideUndoToast() {
                    const toast = moodModal ? moodModal.querySelector('#bitstream-mood-undo-toast') : null;
                    if (toast) toast.hidden = true;
                    if (undoTimer) { clearTimeout(undoTimer); undoTimer = null; }
                    undoMoodData = null;
                }

                function renderMoodReactions() {
                    if (!moodModal) return;
                    const reactionsBar = moodModal.querySelector('.bitstream-mood-reactions-bar');
                    const reactionsList = moodModal.querySelector('#bitstream-mood-reactions-list');
                    const creator = moodModal.querySelector('#bitstream-mood-creator');
                    const editBtn = moodModal.querySelector('#bitstream-mood-edit-trigger');
                    const editHint = moodModal.querySelector('#bitstream-mood-edit-hint');

                    if (reactionsBar) {
                        reactionsBar.hidden = false;
                        reactionsBar.style.display = 'flex';
                    }
                    if (creator) {
                        creator.hidden = true;
                        creator.style.display = 'none';
                    }
                    moodEditingIndex = null;

                    if (!reactionsList) return;
                    reactionsList.innerHTML = '';

                    if (moodEditMode) {
                        reactionsList.classList.add('is-edit-mode');
                        if (editBtn) editBtn.classList.add('is-active');
                        if (editHint) editHint.textContent = 'Tap to edit · Drag to reorder';
                    } else {
                        reactionsList.classList.remove('is-edit-mode');
                        if (editBtn) editBtn.classList.remove('is-active');
                        if (editHint) editHint.textContent = '';
                    }

                    const currentEmoji   = hMoodEmoji?.value || '';
                    const currentEmotion = hMoodEmotion?.value || '';
                    const allMoods = getAllMoodsLive();

                    allMoods.forEach((mood, idx) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'bitstream-mood-reaction-btn';
                        btn.dataset.emoji = mood.emoji;
                        btn.dataset.emotion = mood.emotion;
                        btn.dataset.idx = idx;
                        btn.title = mood.emotion;
                        btn.setAttribute('aria-label', mood.emotion);

                        const isSelected = (currentEmotion && currentEmotion.toLowerCase() === mood.emotion.toLowerCase() && currentEmoji === mood.emoji);
                        if (isSelected && !moodEditMode) btn.classList.add('is-active');

                        btn.innerHTML = `<span class="bitstream-mood-reaction-emoji">${mood.emoji}</span>`;

                        // Delete badge
                        const badge = document.createElement('span');
                        badge.className = 'mood-delete-badge';
                        badge.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
                        badge.setAttribute('aria-label', `Remove ${mood.emotion}`);
                        badge.addEventListener('pointerdown', e => e.stopPropagation());
                        badge.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            deleteMood(idx, mood);
                        });
                        btn.appendChild(badge);

                        // Drag support (desktop: HTML5, mobile: touch long-press)
                        if (moodEditMode) {
                            btn.draggable = true;
                            btn.style.cursor = 'grab';

                            btn.addEventListener('dragstart', (e) => {
                                dragSrcIndex = idx;
                                btn.classList.add('is-dragging');
                                e.dataTransfer.effectAllowed = 'move';
                            });
                            btn.addEventListener('dragend', () => {
                                btn.classList.remove('is-dragging');
                                reactionsList.querySelectorAll('.bitstream-mood-reaction-btn').forEach(b => b.classList.remove('is-drag-over'));
                            });
                            btn.addEventListener('dragover', (e) => {
                                e.preventDefault();
                                e.dataTransfer.dropEffect = 'move';
                                reactionsList.querySelectorAll('.bitstream-mood-reaction-btn').forEach(b => b.classList.remove('is-drag-over'));
                                if (idx !== dragSrcIndex) btn.classList.add('is-drag-over');
                            });
                            btn.addEventListener('drop', (e) => {
                                e.preventDefault();
                                btn.classList.remove('is-drag-over');
                                if (dragSrcIndex === null || dragSrcIndex === idx) return;
                                reorderMoods(dragSrcIndex, idx);
                                dragSrcIndex = null;
                            });

                            // Touch & Pointer drag reordering (with haptic feedback)
                            let touchDragTimer = null;
                            let touchDragging = false;
                            let didDrag = false;
                            let startX = 0;
                            let startY = 0;
                            let lastDragOverTarget = null;
                            let isHandlingPointer = false;

                            function triggerHaptic(duration = 55) {
                                try {
                                    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
                                        const res = navigator.vibrate(duration);
                                        if (!res) {
                                            navigator.vibrate([duration]);
                                        }
                                    }
                                } catch (_) {}
                            }

                            function startTouchDrag() {
                                if (touchDragging) return;
                                touchDragging = true;
                                didDrag = true;
                                dragSrcIndex = idx;
                                btn.classList.add('is-dragging');
                                triggerHaptic(60);
                            }

                            function getTargetButton(x, y) {
                                const allBtns = reactionsList.querySelectorAll('.bitstream-mood-reaction-btn');
                                for (let i = 0; i < allBtns.length; i++) {
                                    const b = allBtns[i];
                                    if (b === btn) continue;
                                    const r = b.getBoundingClientRect();
                                    if (x >= r.left - 6 && x <= r.right + 6 && y >= r.top - 50 && y <= r.bottom + 50) {
                                        return b;
                                    }
                                }
                                return null;
                            }

                            function onDragMove(clientX, clientY) {
                                const dist = Math.hypot(clientX - startX, clientY - startY);
                                if (!touchDragging) {
                                    if (dist > 8) {
                                        clearTimeout(touchDragTimer);
                                        startTouchDrag();
                                    } else {
                                        return;
                                    }
                                }

                                const target = getTargetButton(clientX, clientY);
                                if (target) {
                                    if (target !== lastDragOverTarget) {
                                        reactionsList.querySelectorAll('.bitstream-mood-reaction-btn').forEach(b => b.classList.remove('is-drag-over'));
                                        target.classList.add('is-drag-over');
                                        lastDragOverTarget = target;
                                        setTimeout(() => triggerHaptic(55), 0);
                                    }
                                } else {
                                    reactionsList.querySelectorAll('.bitstream-mood-reaction-btn').forEach(b => b.classList.remove('is-drag-over'));
                                    lastDragOverTarget = null;
                                }
                            }

                            function onDragEnd(clientX, clientY) {
                                clearTimeout(touchDragTimer);
                                window.removeEventListener('pointermove', onPointerMove);
                                window.removeEventListener('pointerup', onPointerEnd);
                                window.removeEventListener('pointercancel', onPointerEnd);
                                window.removeEventListener('touchmove', onTouchMove);
                                window.removeEventListener('touchend', onTouchEnd);
                                window.removeEventListener('touchcancel', onTouchEnd);

                                isHandlingPointer = false;
                                const wasDragging = touchDragging;
                                touchDragging = false;
                                btn.classList.remove('is-dragging');
                                reactionsList.querySelectorAll('.bitstream-mood-reaction-btn').forEach(b => b.classList.remove('is-drag-over'));

                                if (wasDragging) {
                                    const target = lastDragOverTarget || getTargetButton(clientX, clientY);
                                    if (target && target !== btn) {
                                        const targetIdx = parseInt(target.dataset.idx, 10);
                                        if (!isNaN(targetIdx) && targetIdx !== idx) {
                                            triggerHaptic(70);
                                            reorderMoods(idx, targetIdx);
                                        }
                                    }
                                    lastDragOverTarget = null;
                                    dragSrcIndex = null;
                                }
                            }

                            function onPointerMove(e) {
                                if (e.pointerType === 'mouse') return;
                                if (e.cancelable) e.preventDefault();
                                onDragMove(e.clientX, e.clientY);
                            }

                            function onPointerEnd(e) {
                                if (e.pointerType === 'mouse') return;
                                onDragEnd(e.clientX, e.clientY);
                            }

                            function onTouchMove(e) {
                                if (e.cancelable) e.preventDefault();
                                const touch = e.touches[0];
                                if (touch) onDragMove(touch.clientX, touch.clientY);
                            }

                            function onTouchEnd(e) {
                                const touch = e.changedTouches ? e.changedTouches[0] : null;
                                onDragEnd(touch ? touch.clientX : startX, touch ? touch.clientY : startY);
                            }

                            btn.addEventListener('pointerdown', (e) => {
                                if (e.pointerType === 'mouse') return;
                                isHandlingPointer = true;
                                touchDragging = false;
                                didDrag = false;
                                lastDragOverTarget = null;
                                startX = e.clientX;
                                startY = e.clientY;

                                window.addEventListener('pointermove', onPointerMove, { passive: false });
                                window.addEventListener('pointerup', onPointerEnd);
                                window.addEventListener('pointercancel', onPointerEnd);

                                clearTimeout(touchDragTimer);
                                touchDragTimer = setTimeout(() => {
                                    startTouchDrag();
                                }, 220);
                            });

                            btn.addEventListener('touchstart', (e) => {
                                if (isHandlingPointer) return;
                                const touch = e.touches[0];
                                if (!touch) return;
                                touchDragging = false;
                                didDrag = false;
                                lastDragOverTarget = null;
                                startX = touch.clientX;
                                startY = touch.clientY;

                                window.addEventListener('touchmove', onTouchMove, { passive: false });
                                window.addEventListener('touchend', onTouchEnd);
                                window.addEventListener('touchcancel', onTouchEnd);

                                clearTimeout(touchDragTimer);
                                touchDragTimer = setTimeout(() => {
                                    startTouchDrag();
                                }, 220);
                            }, { passive: true });
                        }

                        // Click handler: select (normal) or open edit form (edit mode)
                        btn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            if (didDrag) {
                                didDrag = false;
                                return;
                            }
                            if (moodEditMode) {
                                openMoodEditorFor(idx, mood);
                            } else {
                                if (btn.classList.contains('is-active')) {
                                    applyMoodSelection('', '');
                                } else {
                                    applyMoodSelection(mood.emoji, mood.emotion);
                                }
                                if (typeof closeAllPopovers === 'function') closeAllPopovers();
                            }
                        });

                        reactionsList.appendChild(btn);
                    });

                    if (typeof window.parseEmojis === 'function') {
                        window.parseEmojis(reactionsList);
                    }
                }

                function reorderMoods(fromIdx, toIdx) {
                    const all = getAllMoodsLive();
                    if (fromIdx < 0 || toIdx < 0 || fromIdx >= all.length || toIdx >= all.length) return;
                    const moved = all.splice(fromIdx, 1)[0];
                    all.splice(toIdx, 0, moved);

                    // Split back: customMoods now carries everything
                    // Preloaded defaults need to be identified so they can stay in customMoods
                    // The simplest model: treat all moods as customMoods after first reorder
                    customMoods = all;
                    saveMoodsToServer(customMoods);
                    renderMoodReactions();
                }

                function deleteMood(idx, mood) {
                    const all = getAllMoodsLive();
                    // Save snapshot for undo
                    undoMoodData = { moods: all.slice(), label: mood.emotion };
                    // Remove
                    all.splice(idx, 1);
                    customMoods = all;

                    // Clear selection if deleted mood was selected
                    if (hMoodEmoji && hMoodEmotion && hMoodEmoji.value === mood.emoji && hMoodEmotion.value === mood.emotion) {
                        applyMoodSelection('', '');
                    }

                    renderMoodReactions();
                    showUndoToast(mood.emotion);

                    // Fire AJAX after 5s (undo cancels it)
                    if (undoTimer) clearTimeout(undoTimer);
                    undoTimer = setTimeout(() => {
                        saveMoodsToServer(customMoods);
                        undoMoodData = null;
                        const toast = moodModal ? moodModal.querySelector('#bitstream-mood-undo-toast') : null;
                        if (toast) toast.hidden = true;
                    }, 5000);
                }

                // Inline Mood Creator Controls
                const addMoodTrigger = moodModal ? moodModal.querySelector('#bitstream-mood-add-trigger') : null;
                const editMoodTrigger = moodModal ? moodModal.querySelector('#bitstream-mood-edit-trigger') : null;
                const moodCreator = moodModal ? moodModal.querySelector('#bitstream-mood-creator') : null;
                const moodReactionsBar = moodModal ? moodModal.querySelector('.bitstream-mood-reactions-bar') : null;
                const creatorEmojiTrigger = moodModal ? moodModal.querySelector('#bitstream-mood-creator-emoji-trigger') : null;
                const creatorEmojiInput = moodModal ? moodModal.querySelector('#bitstream-mood-custom-emoji') : null;
                const creatorEmotionInput = moodModal ? moodModal.querySelector('#bitstream-mood-custom-emotion') : null;
                const creatorConfirmBtn = moodModal ? moodModal.querySelector('#bitstream-mood-creator-confirm') : null;
                const creatorBackBtn = moodModal ? moodModal.querySelector('#bitstream-mood-creator-back') : null;

                // Undo button
                const undoBtn = moodModal ? moodModal.querySelector('#bitstream-mood-undo-btn') : null;
                if (undoBtn) {
                    undoBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        if (undoMoodData) {
                            customMoods = undoMoodData.moods;
                            hideUndoToast();
                            renderMoodReactions();
                        }
                    });
                }

                // Edit mode toggle
                if (editMoodTrigger) {
                    editMoodTrigger.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        moodEditMode = !moodEditMode;
                        // If exiting edit mode while creator is open, go back to list
                        if (!moodEditMode && moodCreator && !moodCreator.hidden) {
                            moodCreator.hidden = true;
                            moodCreator.style.display = 'none';
                            if (moodReactionsBar) {
                                moodReactionsBar.hidden = false;
                                moodReactionsBar.style.display = 'flex';
                            }
                        }
                        renderMoodReactions();
                    });
                }

                // Open creator pre-filled for editing an existing mood
                function openMoodEditorFor(idx, mood) {
                    moodEditingIndex = idx;
                    if (moodReactionsBar) {
                        moodReactionsBar.hidden = true;
                        moodReactionsBar.style.display = 'none';
                    }
                    if (moodCreator) {
                        moodCreator.hidden = false;
                        moodCreator.style.display = 'flex';
                    }
                    if (creatorEmojiInput) creatorEmojiInput.value = mood.emoji;
                    if (creatorEmojiTrigger) {
                        creatorEmojiTrigger.innerHTML = `<span class="bs-creator-emoji-val">${mood.emoji}</span>`;
                        if (typeof window.parseEmojis === 'function') window.parseEmojis(creatorEmojiTrigger);
                    }
                    if (creatorEmotionInput) {
                        creatorEmotionInput.value = mood.emotion;
                        updateCreatorInputWidth();
                        creatorEmotionInput.focus();
                    }
                }

                function updateCreatorInputWidth() {
                    if (!creatorEmotionInput) return;
                    const len = creatorEmotionInput.value.length;
                    const targetCh = Math.max(14, Math.min(26, len + 3));
                    creatorEmotionInput.style.width = `${targetCh}ch`;
                }

                if (creatorEmotionInput) {
                    creatorEmotionInput.addEventListener('input', updateCreatorInputWidth);
                }

                if (addMoodTrigger) {
                    addMoodTrigger.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        moodEditingIndex = null;
                        if (moodReactionsBar) {
                            moodReactionsBar.hidden = true;
                            moodReactionsBar.style.display = 'none';
                        }
                        if (moodCreator) {
                            moodCreator.hidden = false;
                            moodCreator.style.display = 'flex';
                            if (creatorEmotionInput) {
                                creatorEmotionInput.value = '';
                                creatorEmotionInput.style.width = '14ch';
                                creatorEmotionInput.focus();
                            }
                            if (creatorEmojiInput && creatorEmojiTrigger) {
                                creatorEmojiInput.value = '😊';
                                creatorEmojiTrigger.innerHTML = '<span class="bs-creator-emoji-val">😊</span>';
                                if (typeof window.parseEmojis === 'function') window.parseEmojis(creatorEmojiTrigger);
                            }
                        }
                    });
                }

                if (creatorBackBtn) {
                    creatorBackBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        moodEditingIndex = null;
                        if (moodCreator) {
                            moodCreator.hidden = true;
                            moodCreator.style.display = 'none';
                        }
                        if (moodReactionsBar) {
                            moodReactionsBar.hidden = false;
                            moodReactionsBar.style.display = 'flex';
                        }
                    });
                }

                if (creatorEmojiTrigger && moodEmojiPicker) {
                    creatorEmojiTrigger.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        moodEmojiPicker.open(creatorEmojiTrigger, (emoji) => {
                            if (creatorEmojiInput) creatorEmojiInput.value = emoji;
                            creatorEmojiTrigger.innerHTML = `<span class="bs-creator-emoji-val">${emoji}</span>`;
                            if (typeof window.parseEmojis === 'function') window.parseEmojis(creatorEmojiTrigger);
                        });
                    });
                }

                function handleSaveNewMood() {
                    if (!creatorEmotionInput) return;
                    const emotion = creatorEmotionInput.value.trim();
                    if (!emotion) {
                        creatorEmotionInput.focus();
                        return;
                    }
                    const emoji = (creatorEmojiInput && creatorEmojiInput.value.trim()) || '😊';

                    if (!Array.isArray(customMoods)) customMoods = [];

                    if (moodEditingIndex !== null) {
                        // Editing an existing mood in-place
                        const all = getAllMoodsLive();
                        if (moodEditingIndex >= 0 && moodEditingIndex < all.length) {
                            const oldMood = all[moodEditingIndex];
                            // Update customMoods (which now holds all moods after any prior reorder/delete)
                            customMoods = all;
                            customMoods[moodEditingIndex] = { emoji, emotion };

                            // Update selection if this mood was selected
                            if (hMoodEmoji && hMoodEmotion && hMoodEmoji.value === oldMood.emoji && hMoodEmotion.value === oldMood.emotion) {
                                applyMoodSelection(emoji, emotion);
                            }
                        }
                        moodEditingIndex = null;
                        saveMoodsToServer(customMoods);
                        // Return to reaction bar, stay in edit mode
                        if (moodCreator) {
                            moodCreator.hidden = true;
                            moodCreator.style.display = 'none';
                        }
                        if (moodReactionsBar) {
                            moodReactionsBar.hidden = false;
                            moodReactionsBar.style.display = 'flex';
                        }
                        renderMoodReactions();
                    } else {
                        // Adding a new mood
                        const exists = customMoods.some(m => m.emotion.toLowerCase() === emotion.toLowerCase() && m.emoji === emoji);
                        if (!exists) {
                            customMoods.push({ emoji, emotion });
                            saveMoodsToServer(customMoods);
                        }
                        applyMoodSelection(emoji, emotion);
                        renderMoodReactions();
                        if (typeof closeAllPopovers === 'function') closeAllPopovers();
                    }
                }

                if (creatorConfirmBtn) {
                    creatorConfirmBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        handleSaveNewMood();
                    });
                }

                if (creatorEmotionInput) {
                    creatorEmotionInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            e.stopPropagation();
                            handleSaveNewMood();
                        }
                    });
                }

                function insertEmojiAtCursor(inputEl, emoji) {
                    if (!inputEl) return;
                    if (inputEl.hasAttribute('contenteditable') || inputEl.getAttribute('role') === 'textbox') {
                        if (window.BitStream && window.BitStream.Editor && typeof window.BitStream.Editor.insertAtCaret === 'function') {
                            window.BitStream.Editor.insertAtCaret(inputEl, emoji);
                        } else {
                            inputEl.focus();
                            document.execCommand('insertText', false, emoji);
                        }
                    } else {
                        const startPos = inputEl.selectionStart || 0;
                        const endPos = inputEl.selectionEnd || 0;
                        const text = inputEl.value || '';
                        const before = text.substring(0, startPos);
                        const after = text.substring(endPos, text.length);
                        inputEl.value = before + emoji + after;
                        inputEl.selectionStart = inputEl.selectionEnd = startPos + emoji.length;
                        inputEl.focus();
                        inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                    }
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

                if (previewMood) {
                    const removeBtn = previewMood.querySelector('.bitstream-composer-preview-remove');

                    if (removeBtn) {
                        removeBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            if (hMoodEmoji) hMoodEmoji.value = '';
                            if (hMoodEmotion) hMoodEmotion.value = '';
                            setPreviewMoodDisplay('', '');
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

                            if (textarea) {
                                if (window.BitStream && window.BitStream.Editor) {
                                    window.BitStream.Editor.setEditorValue(textarea, d.content || '');
                                } else {
                                    textarea.value = d.content || '';
                                }
                            }
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
                                setPreviewMoodDisplay(d.mood_emoji || '', d.mood_emotion);
                            } else {
                                if (hMoodEmoji) hMoodEmoji.value = '';
                                if (hMoodEmotion) hMoodEmotion.value = '';
                                setPreviewMoodDisplay('', '');
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

                // ── POPOVER MANAGER ──
                function closeAllPopovers() {
                    composerRoot.querySelectorAll('.bitstream-composer-popover').forEach(p => p.hidden = true);
                    composerRoot.querySelectorAll('[data-composer-popover-trigger]').forEach(btn => btn.classList.remove('is-active'));
                }

                function positionPopover(popover, triggerBtn) {
                    if (!popover || !triggerBtn) return;
                    popover.style.left = '0px';
                    popover.style.right = 'auto';

                    const calc = () => {
                        const anchorRect = triggerBtn.getBoundingClientRect();
                        const popoverRect = popover.getBoundingClientRect();
                        const viewportWidth = window.innerWidth;
                        const padding = 16;

                        let shiftX = 0;
                        const rightEdge = anchorRect.left + popoverRect.width;
                        if (rightEdge > viewportWidth - padding) {
                            shiftX = (viewportWidth - padding) - rightEdge;
                        }

                        if (anchorRect.left + shiftX < padding) {
                            shiftX = padding - anchorRect.left;
                        }

                        popover.style.left = shiftX + 'px';
                        popover.style.right = 'auto';
                    };

                    calc();
                    requestAnimationFrame(calc);
                }

                function togglePopover(popoverName, triggerBtn) {
                    const popover = composerRoot.querySelector('.bitstream-composer-popover-' + popoverName);
                    if (!popover) return;
                    const isHidden = popover.hidden;
                    closeAllPopovers();
                    if (isHidden) {
                        popover.hidden = false;
                        if (triggerBtn) triggerBtn.classList.add('is-active');
                        if (popoverName === 'rebit') {
                            const rInput = popover.querySelector('#bitstream-composer-rebit-popover-input, .bitstream-rebit-popover-input');
                            if (rInput) {
                                if (!rInput.value && hRebitUrl && hRebitUrl.value) {
                                    rInput.value = hRebitUrl.value;
                                }
                                setTimeout(() => {
                                    rInput.focus();
                                    rInput.select();
                                }, 50);
                            }
                        } else if (popoverName === 'mood') {
                            renderMoodReactions();
                        }
                        positionPopover(popover, triggerBtn);
                    }
                }

                window.addEventListener('resize', () => {
                    const openPopover = composerRoot.querySelector('.bitstream-composer-popover:not([hidden])');
                    if (openPopover) {
                        const trigger = openPopover.parentElement.querySelector('[data-composer-popover-trigger]');
                        if (trigger) positionPopover(openPopover, trigger);
                    }
                });

                composerRoot.querySelectorAll('[data-composer-popover-trigger]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        togglePopover(btn.dataset.composerPopoverTrigger, btn);
                    });
                });

                composerRoot.querySelectorAll('[data-popover-close]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        closeAllPopovers();
                    });
                });

                document.addEventListener('click', (e) => {
                    if (!e.target.closest('.bitstream-composer-popover-anchor') && !e.target.closest('.bitstream-composer-popover')) {
                        closeAllPopovers();
                    }
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        closeAllPopovers();
                    }
                });

                // ── REBIT POPOVER & METADATA EDITING ──
                const rebitPopover = composerRoot.querySelector('.bitstream-composer-popover-rebit');
                const rebitPopoverInput = composerRoot.querySelector('#bitstream-composer-rebit-popover-input, .bitstream-rebit-popover-input');
                const rebitPopoverFetchBtn = composerRoot.querySelector('#bitstream-composer-rebit-popover-fetch');
                const rebitPopoverStatus = composerRoot.querySelector('#bitstream-composer-rebit-popover-status');

                const inlineRebitMeta = form.querySelector('#bitstream-composer-rebit-inline-meta');
                const inlineRebitTitle = form.querySelector('#bitstream-composer-rebit-inline-title');
                const inlineRebitDesc = form.querySelector('#bitstream-composer-rebit-inline-desc');
                const inlineRebitImgChange = form.querySelector('#bitstream-composer-rebit-img-change');
                const inlineRebitImgCrop = form.querySelector('#bitstream-composer-rebit-img-crop');
                const inlineRebitImgRemove = form.querySelector('#bitstream-composer-rebit-img-remove');
                const inlineRebitImgPreview = form.querySelector('#bitstream-composer-rebit-img-preview');
                const inlineRebitImgEl = form.querySelector('#bitstream-composer-rebit-img-el');
                const inlineRebitImgEmpty = form.querySelector('#bitstream-composer-rebit-img-empty');
                const inlineRebitMetaSave = form.querySelector('#bitstream-composer-rebit-meta-save');
                const inlineRebitMetaClose = form.querySelector('#bitstream-composer-rebit-meta-close');

                let rebitEditBackup = null;

                function isEmbeddableRebitUrl(url) {
                    if (!url) return false;
                    try {
                        const parsed = new URL(url);
                        const host = parsed.hostname.toLowerCase();
                        if (host.includes('youtube.com') || host.includes('youtu.be') || host.includes('youtube-nocookie.com')) {
                            return true;
                        }
                        if (host.includes('twitter.com') || host.includes('x.com')) {
                            if (parsed.pathname.includes('/status/') || parsed.pathname.includes('/statuses/')) {
                                return true;
                            }
                        }
                        if (host.includes('instagram.com')) {
                            if (parsed.pathname.includes('/p/') || parsed.pathname.includes('/reel/') || parsed.pathname.includes('/reels/') || parsed.pathname.includes('/stories/')) {
                                return true;
                            }
                        }
                    } catch (e) {
                        const low = String(url).toLowerCase();
                        if (low.includes('youtube.com') || low.includes('youtu.be') || low.includes('youtube-nocookie.com') || low.includes('twitter.com/status') || low.includes('x.com/status') || low.includes('instagram.com/p/') || low.includes('instagram.com/reel') || low.includes('instagram.com/stories/')) {
                            return true;
                        }
                    }
                    return false;
                }

                updateRebitEditVisibility = function (url, isEmbeddable = null) {
                    const rebitEditBtn = form.querySelector('.bitstream-composer-preview-rebit .bitstream-composer-preview-edit[data-composer-edit="rebit"]');
                    const embeddable = isEmbeddable !== null ? !!isEmbeddable : isEmbeddableRebitUrl(url);
                    if (rebitEditBtn) {
                        rebitEditBtn.style.display = embeddable ? 'none' : 'inline-flex';
                    }
                    if (embeddable && inlineRebitMeta && !inlineRebitMeta.hidden) {
                        closeRebitMetaEditor(false);
                    }
                };

                function setRebitStatus(msg, isError = false) {
                    if (!rebitPopoverStatus) return;
                    if (!msg) {
                        rebitPopoverStatus.hidden = true;
                        rebitPopoverStatus.textContent = '';
                        rebitPopoverStatus.classList.remove('is-error');
                        return;
                    }
                    rebitPopoverStatus.hidden = false;
                    rebitPopoverStatus.textContent = msg;
                    rebitPopoverStatus.classList.toggle('is-error', !!isError);
                    rebitPopoverStatus.style.color = isError ? '#ef4444' : '#2c6e49';
                }
                const setInlineRebitStatus = setRebitStatus;

                updateModalImagePreview = function () {
                    const imageUrl = hRebitOgImage ? (hRebitOgImage.value || '').trim() : '';
                    const isRemoved = hRebitOgImageRemoved ? hRebitOgImageRemoved.value === '1' : false;
                    const hasImage = !!(imageUrl && !isRemoved);

                    if (inlineRebitImgEl) {
                        if (hasImage) {
                            inlineRebitImgEl.src = imageUrl;
                            inlineRebitImgEl.hidden = false;
                            inlineRebitImgEl.style.display = 'block';
                        } else {
                            inlineRebitImgEl.removeAttribute('src');
                            inlineRebitImgEl.hidden = true;
                            inlineRebitImgEl.style.display = 'none';
                        }
                    }
                    if (inlineRebitImgEmpty) {
                        inlineRebitImgEmpty.hidden = hasImage;
                        inlineRebitImgEmpty.style.display = hasImage ? 'none' : 'flex';
                    }
                    if (inlineRebitImgCrop) {
                        inlineRebitImgCrop.style.display = hasImage ? 'inline-flex' : 'none';
                    }
                    if (inlineRebitImgRemove) {
                        inlineRebitImgRemove.style.display = hasImage ? 'inline-flex' : 'none';
                    }
                    if (inlineRebitImgChange) {
                        inlineRebitImgChange.innerHTML = hasImage
                            ? '<i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Change'
                            : '<i class="fa-solid fa-plus" aria-hidden="true"></i> Add Image';
                    }
                };

                openRebitMetaEditor = function () {
                    if (!inlineRebitMeta) return;
                    const currentUrl = hRebitUrl ? hRebitUrl.value : '';
                    if (isEmbeddableRebitUrl(currentUrl)) {
                        return;
                    }
                    rebitEditBackup = {
                        title: hRebitOgTitle ? hRebitOgTitle.value : '',
                        desc: hRebitOgDesc ? hRebitOgDesc.value : '',
                        image: hRebitOgImage ? hRebitOgImage.value : '',
                        imageRemoved: hRebitOgImageRemoved ? hRebitOgImageRemoved.value : '0',
                        attachmentId: hRebitAttachmentId ? hRebitAttachmentId.value : ''
                    };
                    if (inlineRebitTitle) inlineRebitTitle.value = rebitEditBackup.title;
                    if (inlineRebitDesc) inlineRebitDesc.value = rebitEditBackup.desc;
                    updateModalImagePreview();

                    const rebitHeader = form.querySelector('.bitstream-composer-preview-rebit .bitstream-composer-preview-header');
                    if (rebitHeader) rebitHeader.hidden = true;
                    if (previewRebitCard) previewRebitCard.hidden = true;
                    inlineRebitMeta.hidden = false;
                };

                closeRebitMetaEditor = function (save = false) {
                    if (!inlineRebitMeta) return;
                    if (save) {
                        if (hRebitOgTitle && inlineRebitTitle) hRebitOgTitle.value = inlineRebitTitle.value.trim();
                        if (hRebitOgDesc && inlineRebitDesc) hRebitOgDesc.value = inlineRebitDesc.value.trim();
                        if (hRebitUrl && hRebitUrl.value) {
                            renderRebitLivePreview(hRebitUrl.value);
                        }
                        setStatus('Link preview updated.');
                    } else if (rebitEditBackup) {
                        if (hRebitOgTitle) hRebitOgTitle.value = rebitEditBackup.title;
                        if (hRebitOgDesc) hRebitOgDesc.value = rebitEditBackup.desc;
                        if (hRebitOgImage) hRebitOgImage.value = rebitEditBackup.image;
                        if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = rebitEditBackup.imageRemoved;
                        if (hRebitAttachmentId) hRebitAttachmentId.value = rebitEditBackup.attachmentId;
                        if (inlineRebitTitle) inlineRebitTitle.value = rebitEditBackup.title;
                        if (inlineRebitDesc) inlineRebitDesc.value = rebitEditBackup.desc;
                        updateModalImagePreview();
                        if (hRebitUrl && hRebitUrl.value) {
                            renderRebitLivePreview(hRebitUrl.value);
                        }
                    }
                    rebitEditBackup = null;

                    inlineRebitMeta.hidden = true;
                    const rebitHeader = form.querySelector('.bitstream-composer-preview-rebit .bitstream-composer-preview-header');
                    if (rebitHeader) rebitHeader.hidden = false;
                    if (previewRebitCard) previewRebitCard.hidden = false;
                };

                if (inlineRebitMetaSave) {
                    inlineRebitMetaSave.addEventListener('click', (e) => {
                        e.preventDefault();
                        closeRebitMetaEditor(true);
                    });
                }

                if (inlineRebitMetaClose) {
                    inlineRebitMetaClose.addEventListener('click', (e) => {
                        e.preventDefault();
                        closeRebitMetaEditor(false);
                    });
                }

                function triggerTwitterWidgetsLoad(container) {
                    if (window.twttr && window.twttr.widgets && typeof window.twttr.widgets.load === 'function') {
                        window.twttr.widgets.load(container);
                    } else if (window.twttr && typeof window.twttr.ready === 'function') {
                        window.twttr.ready((twttr) => {
                            if (twttr && twttr.widgets && typeof twttr.widgets.load === 'function') {
                                twttr.widgets.load(container);
                            }
                        });
                    }
                }

                renderRebitLivePreview = function (url) {
                    if (!url || !window.bitstream_ajax || !bitstream_ajax.og_fetch_nonce) return;
                    updateRebitEditVisibility(url);
                    const fd = new FormData();
                    fd.append('action', 'bitstream_render_rebit_preview');
                    fd.append('nonce', bitstream_ajax.og_fetch_nonce);
                    fd.append('rebit_url', url);
                    fd.append('rebit_commentary', textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')) : '');
                    fd.append('rebit_og_title', (inlineRebitTitle && inlineRebitTitle.value) || (hRebitOgTitle ? hRebitOgTitle.value : ''));
                    fd.append('rebit_og_desc', (inlineRebitDesc && inlineRebitDesc.value) || (hRebitOgDesc ? hRebitOgDesc.value : ''));
                    fd.append('rebit_og_image', hRebitOgImage ? hRebitOgImage.value : '');
                    fd.append('rebit_og_image_removed', hRebitOgImageRemoved ? hRebitOgImageRemoved.value : '0');
                    fd.append('rebit_attachment_id', hRebitAttachmentId ? hRebitAttachmentId.value : '');

                    fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.data && previewRebitCard) {
                                previewRebitCard.innerHTML = data.data.rendered_html || '';
                                updateRebitEditVisibility(url, data.data.is_embeddable);
                                triggerTwitterWidgetsLoad(previewRebitCard);
                                if (previewRebit) previewRebit.hidden = false;
                                syncPreviewArea();
                                if (data.data.og && hRebitOgImage && !hRebitOgImage.value) {
                                    hRebitOgImage.value = data.data.og.image || '';
                                }
                                updateModalImagePreview();
                            }
                        })
                        .catch(() => {});
                };

                function fetchAndAttachRebit(url) {
                    if (!url) {
                        setRebitStatus('Enter a URL first.', true);
                        return;
                    }
                    if (!window.bitstream_ajax || !bitstream_ajax.og_fetch_nonce) {
                        setRebitStatus('Metadata fetcher unavailable.', true);
                        return;
                    }
                    if (rebitPopoverFetchBtn) {
                        rebitPopoverFetchBtn.disabled = true;
                        rebitPopoverFetchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Attaching...';
                    }
                    setRebitStatus('Fetching link preview...');

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
                            if (hRebitUrl) hRebitUrl.value = url;
                            if (hRebitOgTitle) hRebitOgTitle.value = meta.title || '';
                            if (hRebitOgDesc) hRebitOgDesc.value = meta.description || '';
                            if (hRebitOgImage) hRebitOgImage.value = meta.image || '';
                            if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                            if (inlineRebitTitle) inlineRebitTitle.value = meta.title || '';
                            if (inlineRebitDesc) inlineRebitDesc.value = meta.description || '';

                            form.dataset.composerType = 'rebit';
                            if (textarea) textarea.required = false;

                            renderRebitLivePreview(url);
                            updateRebitEditVisibility(url, meta.is_embeddable);
                            if (previewRebit) previewRebit.hidden = false;
                            syncPreviewArea();

                            closeAllPopovers();
                            if (rebitPopoverInput) rebitPopoverInput.value = '';
                            setRebitStatus('');
                            setStatus('Rebit attached.');
                        })
                        .catch(err => {
                            if (isEmbeddableRebitUrl(url)) {
                                if (hRebitUrl) hRebitUrl.value = url;
                                form.dataset.composerType = 'rebit';
                                if (textarea) textarea.required = false;
                                renderRebitLivePreview(url);
                                updateRebitEditVisibility(url, true);
                                if (previewRebit) previewRebit.hidden = false;
                                syncPreviewArea();
                                closeAllPopovers();
                                if (rebitPopoverInput) rebitPopoverInput.value = '';
                                setRebitStatus('');
                                setStatus('Rebit attached.');
                            } else {
                                setRebitStatus(err.message || 'Fetch failed.', true);
                            }
                        })
                        .finally(() => {
                            if (rebitPopoverFetchBtn) {
                                rebitPopoverFetchBtn.disabled = false;
                                rebitPopoverFetchBtn.textContent = 'Attach Link';
                            }
                        });
                }

                if (rebitPopoverFetchBtn) {
                    rebitPopoverFetchBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        const url = rebitPopoverInput ? rebitPopoverInput.value.trim() : '';
                        fetchAndAttachRebit(url);
                    });
                }

                if (rebitPopoverInput) {
                    rebitPopoverInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            fetchAndAttachRebit(rebitPopoverInput.value.trim());
                        }
                    });
                }

                let inlineRebitMediaFrame;
                if (inlineRebitImgChange) {
                    inlineRebitImgChange.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (inlineRebitMediaFrame) { inlineRebitMediaFrame.open(); return; }
                        inlineRebitMediaFrame = wp.media({ title: 'Select Preview Image', button: { text: 'Use this image' }, multiple: false });
                        inlineRebitMediaFrame.on('select', () => {
                            const attachment = inlineRebitMediaFrame.state().get('selection').first().toJSON();
                            if (hRebitAttachmentId) hRebitAttachmentId.value = attachment.id;
                            if (hRebitOgImage) hRebitOgImage.value = attachment.url;
                            if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                            updateModalImagePreview();
                        });
                        inlineRebitMediaFrame.open();
                    });
                }

                if (inlineRebitImgCrop) {
                    inlineRebitImgCrop.addEventListener('click', (e) => {
                        e.preventDefault();
                        const attachmentId = hRebitAttachmentId ? parseInt(hRebitAttachmentId.value || '0', 10) : 0;
                        const imageUrl = hRebitOgImage ? (hRebitOgImage.value || '').trim() : '';

                        if (!imageUrl) {
                            setStatus('No image to crop.', true);
                            return;
                        }

                        const openCropperForRebit = (targetAttachmentId, targetUrl) => {
                            const cropperOpener = (window.BitStream && window.BitStream.Cropper && typeof window.BitStream.Cropper.open === 'function')
                                ? window.BitStream.Cropper.open
                                : (typeof window.bitstreamOpenCropper === 'function' ? window.bitstreamOpenCropper : null);

                            if (!cropperOpener) {
                                setStatus('Image cropper is unavailable.', true);
                                return;
                            }

                            cropperOpener('', '', {
                                attachmentId: targetAttachmentId,
                                url: targetUrl,
                                onComplete: (croppedMedia, croppedUrl) => {
                                    if (croppedMedia && croppedMedia.id) {
                                        if (hRebitAttachmentId) hRebitAttachmentId.value = croppedMedia.id;
                                    }
                                    if (hRebitOgImage) {
                                        hRebitOgImage.value = croppedUrl || (croppedMedia && croppedMedia.url) || '';
                                    }
                                    if (hRebitOgImageRemoved) {
                                        hRebitOgImageRemoved.value = '0';
                                    }
                                    updateModalImagePreview();
                                    setStatus('Image cropped.');
                                }
                            });
                        };

                        if (attachmentId > 0) {
                            openCropperForRebit(attachmentId, imageUrl);
                        } else {
                            if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                                setStatus('Image cropper is unavailable.', true);
                                return;
                            }

                            inlineRebitImgCrop.disabled = true;
                            setStatus('Preparing image for crop...');

                            const payload = new FormData();
                            payload.append('action', 'bitstream_prepare_rebit_image_for_crop');
                            payload.append('nonce', bitstream_ajax.media_upload_nonce);
                            payload.append('image_url', imageUrl);

                            fetch(bitstream_ajax.ajax_url, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: payload
                            })
                                .then(r => r.json())
                                .then(data => {
                                    if (!data.success) {
                                        throw new Error(data.data || 'Could not prepare image for crop.');
                                    }
                                    const prepared = data.data || {};
                                    if (hRebitAttachmentId) hRebitAttachmentId.value = prepared.id || '';
                                    if (hRebitOgImage) hRebitOgImage.value = prepared.url || imageUrl;
                                    if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '0';
                                    updateModalImagePreview();
                                    openCropperForRebit(prepared.id || 0, prepared.url || imageUrl);
                                })
                                .catch(err => {
                                    setStatus(err.message || 'Could not prepare image for crop.', true);
                                })
                                .finally(() => {
                                    inlineRebitImgCrop.disabled = false;
                                });
                        }
                    });
                }

                if (inlineRebitImgRemove) {
                    inlineRebitImgRemove.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (hRebitAttachmentId) hRebitAttachmentId.value = '';
                        if (hRebitOgImage) hRebitOgImage.value = '';
                        if (hRebitOgImageRemoved) hRebitOgImageRemoved.value = '1';
                        updateModalImagePreview();
                    });
                }

                // ── MEDIA POPOVER & UPLOAD ──
                const mediaFileInput = form.querySelector('#bitstream-composer-file-input');
                const popoverMediaUploadBtn = form.querySelector('#bitstream-popover-media-upload');
                const popoverMediaLibraryBtn = form.querySelector('#bitstream-popover-media-library');

                if (popoverMediaUploadBtn && mediaFileInput) {
                    popoverMediaUploadBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        closeAllPopovers();
                        mediaFileInput.click();
                    });
                }

                if (mediaFileInput) {
                    mediaFileInput.addEventListener('change', () => {
                        if (mediaFileInput.files && mediaFileInput.files.length > 0) {
                            handlePastedMedia(mediaFileInput.files);
                        }
                        mediaFileInput.value = '';
                    });
                }

                let composerMediaFrame;
                if (popoverMediaLibraryBtn) {
                    popoverMediaLibraryBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        closeAllPopovers();
                        if (composerMediaFrame) {
                            composerMediaFrame.open();
                            return;
                        }
                        composerMediaFrame = wp.media({
                            title: 'Select Media',
                            button: { text: 'Use selected media' },
                            multiple: true,
                            library: { type: ['image', 'video'] }
                        });
                        composerMediaFrame.on('select', () => {
                            const selection = composerMediaFrame.state().get('selection');
                            const attachments = selection.map(att => {
                                const json = att.toJSON();
                                return {
                                    id: json.id,
                                    url: json.url,
                                    type: json.type,
                                    mime: json.mime,
                                    filename: json.filename
                                };
                            });
                            if (attachments.length > 0) {
                                if (previewMediaThumb && typeof window.updateAttachmentsList === 'function') {
                                    window.updateAttachmentsList(previewMediaThumb, attachments);
                                } else {
                                    const attachId = attachments[0].id;
                                    const attachIds = attachments.map(item => item.id).join(',');
                                    if (hAttachmentId) hAttachmentId.value = String(attachId);
                                    if (hAttachmentIds) hAttachmentIds.value = attachIds;
                                }
                                if (previewMedia) previewMedia.hidden = false;
                                syncPreviewArea();
                                setStatus('Media attached.');
                            }
                        });
                        composerMediaFrame.open();
                    });
                }

                // ── DRAG & DROP ON COMPOSER ──
                let dragCounter = 0;
                form.classList.remove('bitstream-composer-dragover');

                function isFileDrag(e) {
                    if (!e.dataTransfer || !e.dataTransfer.types) return false;
                    const types = Array.from(e.dataTransfer.types);
                    return types.includes('Files') || types.includes('public.file-url');
                }

                form.addEventListener('dragenter', (e) => {
                    if (!isFileDrag(e)) return;
                    e.preventDefault();
                    e.stopPropagation();
                    dragCounter++;
                    form.classList.add('bitstream-composer-dragover');
                });

                form.addEventListener('dragover', (e) => {
                    if (!isFileDrag(e)) return;
                    e.preventDefault();
                    e.stopPropagation();
                    e.dataTransfer.dropEffect = 'copy';
                    if (!form.classList.contains('bitstream-composer-dragover')) {
                        form.classList.add('bitstream-composer-dragover');
                    }
                });

                form.addEventListener('dragleave', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (e.relatedTarget && form.contains(e.relatedTarget)) {
                        return;
                    }
                    dragCounter--;
                    if (dragCounter <= 0 || !e.relatedTarget || !form.contains(e.relatedTarget)) {
                        dragCounter = 0;
                        form.classList.remove('bitstream-composer-dragover');
                    }
                });

                function onFilesDropped(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dragCounter = 0;
                    form.classList.remove('bitstream-composer-dragover');

                    const dt = e.dataTransfer;
                    if (!dt) return;

                    let files = dt.files ? Array.from(dt.files) : [];
                    if (files.length === 0 && dt.items && dt.items.length > 0) {
                        for (let i = 0; i < dt.items.length; i++) {
                            if (dt.items[i].kind === 'file') {
                                const f = dt.items[i].getAsFile();
                                if (f) files.push(f);
                            }
                        }
                    }

                    if (files.length > 0) {
                        handlePastedMedia(files);
                    }
                }

                form.addEventListener('drop', onFilesDropped);

                window.addEventListener('dragend', () => {
                    dragCounter = 0;
                    form.classList.remove('bitstream-composer-dragover');
                });

                document.addEventListener('dragleave', (e) => {
                    if (!e.relatedTarget && (e.clientX <= 0 || e.clientY <= 0 || e.clientX >= window.innerWidth || e.clientY >= window.innerHeight)) {
                        dragCounter = 0;
                        form.classList.remove('bitstream-composer-dragover');
                    }
                });

                window.addEventListener('dragover', (e) => {
                    if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
                        e.preventDefault();
                    }
                });

                window.addEventListener('drop', (e) => {
                    if (e.dataTransfer && e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
                        if (!e.target.closest('.bitstream-sidebar-composer-form, .bitstream-composer-drop-overlay')) {
                            e.preventDefault();
                        }
                    }
                });

                // ── PASTE MEDIA HANDLER ──
                function handlePastedMedia(files) {
                    if (!files || files.length === 0) return;

                    const uploadFn = window.uploadMultipleFiles || (window.BitStream && window.BitStream.Media && window.BitStream.Media.uploadMultipleFiles);
                    if (typeof uploadFn !== 'function') {
                        setStatus('Media uploader is unavailable.', true);
                        return;
                    }

                    setStatus('Uploading media...');

                    uploadFn(files, 'bitstream-composer-attachment-id', previewMediaThumb || 'bitstream-composer-preview-media-thumb', {
                        setStatus: (msg, isError) => setStatus(msg, isError)
                    }).then(() => {
                        const attachments = previewMediaThumb ? (typeof window.getExistingAttachments === 'function' ? window.getExistingAttachments(previewMediaThumb) : []) : [];

                        if (attachments.length > 0) {
                            const attachId = attachments[0].id;
                            const attachIds = attachments.map(item => item.id).join(',');
                            if (hAttachmentId) hAttachmentId.value = String(attachId);
                            if (hAttachmentIds) hAttachmentIds.value = attachIds;
                            if (previewMedia) previewMedia.hidden = false;
                            syncPreviewArea();
                            setStatus('Media attached.');
                        }
                    }).catch(err => {
                        console.error('BitStream: Media upload failed:', err);
                        setStatus(err.message || 'Media upload failed.', true);
                    });
                }

                composerRoot.addEventListener('bitstream:paste-media', (e) => {
                    const files = e.detail && e.detail.files;
                    if (files && files.length > 0) {
                        handlePastedMedia(files);
                    }
                });

                composerRoot.addEventListener('paste', (e) => {
                    if (e.defaultPrevented) return;
                    const clipboardData = e.clipboardData || window.clipboardData;
                    if (!clipboardData) return;

                    const mediaFiles = [];
                    if (clipboardData.files && clipboardData.files.length > 0) {
                        for (let i = 0; i < clipboardData.files.length; i++) {
                            const f = clipboardData.files[i];
                            if (f.type && (f.type.startsWith('image/') || f.type.startsWith('video/'))) {
                                mediaFiles.push(f);
                            }
                        }
                    }
                    if (mediaFiles.length === 0 && clipboardData.items && clipboardData.items.length > 0) {
                        for (let i = 0; i < clipboardData.items.length; i++) {
                            const item = clipboardData.items[i];
                            if (item.type && (item.type.startsWith('image/') || item.type.startsWith('video/'))) {
                                const f = item.getAsFile();
                                if (f) mediaFiles.push(f);
                            }
                        }
                    }

                    if (mediaFiles.length > 0) {
                        e.preventDefault();
                        handlePastedMedia(mediaFiles);
                    }
                });

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

                        const content = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
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

                // ── SCHEDULE POPOVER ──
                const schedulePopover = composerRoot.querySelector('.bitstream-composer-popover-schedule');
                if (schedulePopover) {
                    const schedDatetime = schedulePopover.querySelector('.bitstream-composer-schedule-datetime-input');
                    const schedSetBtn = schedulePopover.querySelector('#bitstream-popover-schedule-set');
                    const schedClearBtn = schedulePopover.querySelector('#bitstream-popover-schedule-clear');

                    if (schedSetBtn) {
                        schedSetBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            const dt = schedDatetime ? schedDatetime.value : '';
                            if (!dt) {
                                setStatus('Pick a date and time.', true);
                                return;
                            }
                            if (hScheduleEnabled) hScheduleEnabled.value = '1';
                            if (hScheduleDatetime) hScheduleDatetime.value = dt;
                            if (previewScheduleDate) previewScheduleDate.textContent = new Date(dt).toLocaleString();
                            if (previewSchedule) previewSchedule.hidden = false;
                            if (submitBtn) {
                                const isEdit = hEditPostId && hEditPostId.value !== '0';
                                submitBtn.textContent = isEdit ? 'Update Scheduled Post' : 'Schedule Bit';
                            }
                            syncPreviewArea();
                            closeAllPopovers();
                            setStatus('Schedule set.');
                        });
                    }

                    if (schedClearBtn) {
                        schedClearBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            if (hScheduleEnabled) hScheduleEnabled.value = '0';
                            if (hScheduleDatetime) hScheduleDatetime.value = '';
                            if (schedDatetime) schedDatetime.value = '';
                            if (previewSchedule) previewSchedule.hidden = true;
                            if (submitBtn) {
                                const isEdit = hEditPostId && hEditPostId.value !== '0';
                                submitBtn.textContent = isEdit ? 'Publish Draft' : 'Post Bit';
                            }
                            syncPreviewArea();
                            closeAllPopovers();
                            setStatus('Schedule cleared.');
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
                    const rebitBtn = composerRoot.querySelector('[data-composer-action="rebit"], [data-composer-modal="rebit"]');
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
                if (quotePostIdFromUrl > 0) {
                    setTimeout(() => loadQuoteData(quotePostIdFromUrl), 150);
                }

                const editPostIdFromUrl = parseInt(urlParams.get('edit_post_id') || '0', 10);
                if (editPostIdFromUrl > 0) {
                    setTimeout(() => loadEditPostData(editPostIdFromUrl), 150);
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
                                if (window.BitStream && window.BitStream.Editor) {
                                    window.BitStream.Editor.setEditorValue(textarea, cleanText);
                                } else {
                                    textarea.value = cleanText;
                                }
                                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                            }

                            if (finalUrl) {
                                if (hRebitUrl) {
                                    hRebitUrl.value = finalUrl;
                                }
                                form.dataset.composerType = 'rebit';
                                if (textarea) textarea.required = false;

                                const rebitBtn = composerRoot.querySelector('[data-composer-popover-trigger="rebit"], [data-composer-action="rebit"], [data-composer-modal="rebit"]');
                                if (rebitBtn) {
                                    rebitBtn.click();
                                }

                                const mRebitUrl = composerRoot.querySelector('#bitstream-composer-rebit-popover-input, #bitstream-composer-inline-rebit-input, #bitstream-composer-modal-rebit-url');
                                if (mRebitUrl) {
                                    mRebitUrl.value = finalUrl;
                                }
                                const mRebitFetch = composerRoot.querySelector('#bitstream-composer-rebit-popover-fetch, #bitstream-composer-inline-rebit-fetch-btn, .bitstream-composer-rebit-fetch');
                                if (mRebitFetch) {
                                    mRebitFetch.classList.remove('is-edit-mode');
                                    mRebitFetch.textContent = 'Fetch';
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

                    const content = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
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
                    const content = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
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
                window.BitStream.Composer.openEdit = function (postId, postType) {
                    loadEditPostData(postId, postType);
                };
                window.BitStream.Composer.openQuote = function (postId) {
                    loadQuoteData(postId);
                };
                window.BitStream.Composer.cancelEdit = function () {
                    exitEditMode();
                };

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

                // Mobile visual viewport and keyboard handling
                function syncVisualViewport() {
                    const isMobile = window.innerWidth < 1024;
                    const isOpen = !composerRoot.hidden;
                    if (!isMobile || !isOpen) return;

                    if (window.visualViewport) {
                        const h = Math.round(window.visualViewport.height);
                        const top = Math.round(window.visualViewport.offsetTop || 0);
                        document.documentElement.style.setProperty('--bs-viewport-height', `${h}px`);
                        if (top > 0) {
                            document.documentElement.style.setProperty('--bs-viewport-top', `${top}px`);
                        } else {
                            document.documentElement.style.removeProperty('--bs-viewport-top');
                        }
                    }
                    if (window.scrollY !== 0) {
                        window.scrollTo(0, 0);
                    }
                }

                function onComposerVisibilityChange() {
                    const isMobile = window.innerWidth < 1024;
                    const isOpen = !composerRoot.hidden;
                    document.body.classList.toggle('bitstream-composer-open', !!(isOpen && isMobile));
                    if (typeof window.bitstreamSyncBottomNav === 'function') {
                        window.bitstreamSyncBottomNav();
                    }
                    if (isOpen && isMobile) {
                        const modalBody = composerRoot.querySelector('.bitstream-composer-modal-body');
                        if (modalBody) modalBody.scrollTop = 0;
                        syncVisualViewport();
                    } else {
                        document.documentElement.style.removeProperty('--bs-viewport-height');
                        document.documentElement.style.removeProperty('--bs-viewport-top');
                    }
                }

                if (window.visualViewport) {
                    window.visualViewport.addEventListener('resize', syncVisualViewport);
                    window.visualViewport.addEventListener('scroll', syncVisualViewport);
                }
                window.addEventListener('resize', syncVisualViewport);

                if (textarea) {
                    textarea.addEventListener('focus', () => {
                        const isMobile = window.innerWidth < 1024;
                        if (isMobile) {
                            const modalBody = composerRoot.querySelector('.bitstream-composer-modal-body');
                            if (modalBody && modalBody.scrollTop > 50) {
                                modalBody.scrollTop = 0;
                            }
                        }
                        setTimeout(syncVisualViewport, 50);
                        setTimeout(syncVisualViewport, 300);
                    });
                }

                if (window.MutationObserver) {
                    const composerObserver = new MutationObserver(onComposerVisibilityChange);
                    composerObserver.observe(composerRoot, { attributes: true, attributeFilter: ['hidden'] });
                }
            });
        }
    };
})();
