(function () {
    // ═══ TIMELINE & EDIT MODAL STATE ═══
    let openTimelineEditModal = () => false;
    let openTimelineQuoteModal = () => false;
    let _htmlToImageLoaded = false;
    let activeMediaElement = null;
    let mediaSessionHandlersBound = false;
    let loading = false;

    function setStatus(msg, isError = false) {
        const statusEl = document.querySelector('.bitstream-sidebar-composer-status');
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.color = isError ? '#cc0000' : '#2c6e49';
    }

    function showDeleteConfirmation(message, onConfirm) {
        let confirmModal = document.querySelector('.bitstream-composer-modal-delete-confirm');
        if (!confirmModal) {
            confirmModal = document.createElement('div');
            confirmModal.className = 'bitstream-composer-modal bitstream-composer-modal-delete-confirm';
            confirmModal.hidden = true;
            confirmModal.innerHTML = `
                <div class="bitstream-composer-modal-backdrop" data-composer-modal-close="delete-confirm"></div>
                <div class="bitstream-composer-modal-dialog" role="dialog" aria-modal="true" aria-label="Confirm Delete">
                    <header class="bitstream-composer-modal-header">
                        <h3>Confirm Delete</h3>
                        <button type="button" class="bitstream-composer-modal-close" data-composer-modal-close="delete-confirm" aria-label="Close">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>
                    <div class="bitstream-composer-modal-body">
                        <p class="bitstream-delete-confirm-message" style="margin: 0; font-size: 0.95rem; color: #4a5568; line-height: 1.5;"></p>
                    </div>
                    <footer class="bitstream-composer-modal-footer">
                        <button type="button" class="bitstream-composer-modal-cancel" data-composer-modal-close="delete-confirm">Cancel</button>
                        <button type="button" class="bitstream-composer-modal-confirm bitstream-composer-delete-confirm-btn is-delete">Delete</button>
                    </footer>
                </div>
            `;
            document.body.appendChild(confirmModal);

            const closeModalFunc = () => {
                confirmModal.hidden = true;
                document.body.style.overflow = '';
            };

            // Bind close events
            confirmModal.querySelectorAll('[data-composer-modal-close="delete-confirm"]').forEach(el => {
                el.addEventListener('click', closeModalFunc);
            });

            // ESC key support
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !confirmModal.hidden) {
                    closeModalFunc();
                }
            });
        }

        // Set the message
        const msgEl = confirmModal.querySelector('.bitstream-delete-confirm-message');
        if (msgEl) {
            msgEl.textContent = message;
        }

        // Bind confirm button
        const confirmBtn = confirmModal.querySelector('.bitstream-composer-delete-confirm-btn');
        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

        newConfirmBtn.addEventListener('click', () => {
            confirmModal.hidden = true;
            document.body.style.overflow = '';
            onConfirm();
        });

        // Show modal and disable scroll
        confirmModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    window.showDeleteConfirmation = showDeleteConfirmation;

    function showDiscardConfirmation(message, onConfirm, onSaveDraft = null) {
        let confirmModal = document.querySelector('.bitstream-composer-modal-discard-confirm');
        if (!confirmModal) {
            confirmModal = document.createElement('div');
            confirmModal.className = 'bitstream-composer-modal bitstream-composer-modal-discard-confirm';
            confirmModal.hidden = true;
            confirmModal.innerHTML = `
                <div class="bitstream-composer-modal-backdrop" data-composer-modal-close="discard-confirm"></div>
                <div class="bitstream-composer-modal-dialog" role="dialog" aria-modal="true" aria-label="Discard Changes">
                    <header class="bitstream-composer-modal-header">
                        <h3>Discard Changes?</h3>
                        <button type="button" class="bitstream-composer-modal-close" data-composer-modal-close="discard-confirm" aria-label="Close">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>
                    <div class="bitstream-composer-modal-body">
                        <p class="bitstream-discard-confirm-message" style="margin: 0; font-size: 0.95rem; color: #4a5568; line-height: 1.5;"></p>
                    </div>
                    <footer class="bitstream-composer-modal-footer">
                        <button type="button" class="bitstream-composer-modal-cancel" data-composer-modal-close="discard-confirm">Cancel</button>
                        <button type="button" class="bitstream-composer-modal-confirm bitstream-composer-discard-confirm-btn is-discard">Discard</button>
                        <button type="button" class="bitstream-composer-modal-confirm bitstream-composer-save-draft-action" style="display:none; background: #64748b; box-shadow:none;">Save Draft</button>
                    </footer>
                </div>
            `;
            document.body.appendChild(confirmModal);

            const closeModalFunc = () => {
                confirmModal.hidden = true;
                document.body.style.overflow = '';
            };

            // Bind close events
            confirmModal.querySelectorAll('[data-composer-modal-close="discard-confirm"]').forEach(el => {
                el.addEventListener('click', closeModalFunc);
            });

            // ESC key support
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !confirmModal.hidden) {
                    closeModalFunc();
                }
            });
        }

        // Set the message
        const msgEl = confirmModal.querySelector('.bitstream-discard-confirm-message');
        if (msgEl) {
            msgEl.textContent = message;
        }

        // Bind discard button
        const confirmBtn = confirmModal.querySelector('.bitstream-composer-discard-confirm-btn');
        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

        newConfirmBtn.addEventListener('click', () => {
            confirmModal.hidden = true;
            document.body.style.overflow = '';
            onConfirm();
        });

        // Optional save draft action support
        const saveDraftBtn = confirmModal.querySelector('.bitstream-composer-save-draft-action');
        if (onSaveDraft) {
            saveDraftBtn.style.display = 'inline-block';
            const newSaveDraftBtn = saveDraftBtn.cloneNode(true);
            saveDraftBtn.parentNode.replaceChild(newSaveDraftBtn, saveDraftBtn);
            newSaveDraftBtn.addEventListener('click', () => {
                confirmModal.hidden = true;
                document.body.style.overflow = '';
                onSaveDraft();
            });
        } else {
            saveDraftBtn.style.display = 'none';
        }

        // Show modal and disable scroll
        confirmModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    window.showDiscardConfirmation = showDiscardConfirmation;

    function highlightFromQueryParams() {
        const params = new URLSearchParams(window.location.search);
        const highlightBit = parseInt(params.get('highlight_bit') || '0', 10);
        const highlightScheduled = parseInt(params.get('highlight_scheduled') || '0', 10);
        const openComments = parseInt(params.get('open_comments') || '0', 10);

        if (openComments > 0) {
            const targetSection = document.getElementById('comments-' + openComments);
            if (targetSection) {
                const bitCard = targetSection.closest('.bit-card');
                if (bitCard) {
                    bitCard.classList.add('comments-open');
                }
                targetSection.classList.add('open');
                setTimeout(() => {
                    targetSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 150);
            }
        }

        if (highlightBit > 0 && openComments <= 0) {
            const bitCard = document.getElementById('bit-' + highlightBit);
            if (bitCard) {
                bitCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        if (highlightScheduled > 0) {
            const scheduledRow = document.querySelector('.bitstream-scheduled-item[data-post-id="' + highlightScheduled + '"]');
            if (scheduledRow) {
                scheduledRow.classList.add('is-highlighted');
                scheduledRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    function initTimelineEditModal() {
        const modal = document.getElementById('bs-edit-modal');
        if (!modal) {
            return;
        }

        const modalTitle = modal.querySelector('#bs-edit-modal-title');
        const loadingState = modal.querySelector('.bs-edit-modal-loading');
        const loadingMessage = loadingState ? loadingState.querySelector('p') : null;
        const errorState = modal.querySelector('.bs-edit-modal-error');
        const errorMessage = modal.querySelector('.bs-edit-modal-error-msg');
        const bitForm = modal.querySelector('.bs-edit-form-unified') || modal.querySelector('.bs-edit-form-bit') || modal.querySelector('.bs-edit-form');
        const linkMetaModal = modal.querySelector('.bs-edit-link-meta-modal');
        const closeButtons = modal.querySelectorAll('[data-bs-edit-modal-close="true"]');
        const submitNonce = modal.dataset.submitNonce || (window.bitstream_ajax && bitstream_ajax.composer_submit_nonce) || '';
        let ogImageFrame = null;
        let editFormIsDirty = false;
        let isPopulating = false;
        let activeLinkMetaForm = null;
        let clearBitFormRebit = () => {};
        let renderBitFormRebitPreview = () => {};
        let syncEditPreviewArea = () => {};
        let _editCarouselScrollHandler = null;

        syncEditPreviewArea = function() {
            const hasRebit = !bitForm.querySelector('.bs-edit-rebit-preview-container').hidden && bitForm.querySelector('.bs-edit-rebit-url-hidden').value;
            const hasQuote = !bitForm.querySelector('.bs-edit-quote-preview').hidden && parseInt(bitForm.querySelector('.bs-edit-quote-post-id').value || '0', 10) > 0;
            const mediaPreviewContainer = bitForm.querySelector('.bs-edit-media-preview-container');
            const hasMedia = mediaPreviewContainer && !mediaPreviewContainer.hidden && (bitForm.querySelector('.bs-edit-attachment-id').value || bitForm.querySelector('.bs-edit-attachment-ids').value);
            
            const previewArea = bitForm.querySelector('.bs-edit-preview-area');
            const previewCarousel = bitForm.querySelector('.bs-edit-preview-carousel');
            const previewDotsEl = bitForm.querySelector('.bs-edit-preview-dots');
            
            const activeCards = [];
            const activeLabels = [];
            
            if (hasRebit) {
                const card = bitForm.querySelector('.bs-edit-rebit-preview-container');
                if (card) {
                    activeCards.push(card);
                    activeLabels.push('Link');
                }
            }
            if (hasQuote) {
                const card = bitForm.querySelector('.bs-edit-quote-preview');
                if (card) {
                    activeCards.push(card);
                    activeLabels.push('Quote');
                }
            }
            if (hasMedia) {
                if (mediaPreviewContainer) {
                    activeCards.push(mediaPreviewContainer);
                    activeLabels.push('Media');
                }
            }

            const anyCardsPresent = activeCards.length > 0;
            if (previewArea) {
                previewArea.hidden = !anyCardsPresent;
            }

            if (!previewCarousel || !previewDotsEl) return;

            if (_editCarouselScrollHandler) {
                previewCarousel.removeEventListener('scroll', _editCarouselScrollHandler);
                _editCarouselScrollHandler = null;
            }

            const isMobileCarousel = window.innerWidth < 1024;
            const multiplePresent = activeCards.length > 1;

            if (!isMobileCarousel || !multiplePresent) {
                previewDotsEl.hidden = true;
                previewDotsEl.innerHTML = '';
                return;
            }

            previewDotsEl.innerHTML = '';
            previewDotsEl.hidden = false;
            previewDotsEl.setAttribute('aria-hidden', 'true');

            activeCards.forEach(function (card, i) {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'bitstream-composer-preview-dot' + (i === 0 ? ' is-active' : '');
                dot.setAttribute('aria-label', 'Go to ' + activeLabels[i]);
                dot.addEventListener('click', function () {
                    previewCarousel.scrollTo({ left: card.offsetLeft, behavior: 'smooth' });
                });
                previewDotsEl.appendChild(dot);
            });

            const dots = previewDotsEl.querySelectorAll('.bitstream-composer-preview-dot');
            let _rafId = null;
            _editCarouselScrollHandler = function () {
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
            previewCarousel.addEventListener('scroll', _editCarouselScrollHandler, { passive: true });
        };
        window.syncEditPreviewArea = syncEditPreviewArea;

        clearBitFormRebit = function() {
            const fields = getRebitMetaFields(bitForm);
            if (fields.urlInput) fields.urlInput.value = '';
            if (fields.titleInput) fields.titleInput.value = '';
            if (fields.descInput) fields.descInput.value = '';
            if (fields.imageInput) fields.imageInput.value = '';
            if (fields.imageRemovedInput) fields.imageRemovedInput.value = '0';
            if (fields.attachmentInput) fields.attachmentInput.value = '';

            const urlInputModal = modal.querySelector('#bs-edit-rebit-url-input');
            if (urlInputModal) urlInputModal.value = '';
            
            const previewContainer = bitForm.querySelector('.bs-edit-rebit-preview-container');
            if (previewContainer) previewContainer.hidden = true;
            
            const previewCard = bitForm.querySelector('.bs-edit-rebit-preview-card');
            if (previewCard) previewCard.innerHTML = '';

            const toggleBtn = bitForm.querySelector('.bs-edit-rebit-toggle-btn');
            if (toggleBtn) toggleBtn.style.display = '';

            syncEditPreviewArea();
        };

        renderBitFormRebitPreview = function() {
            const urlInput = bitForm.querySelector('.bs-edit-rebit-url-hidden');
            const url = urlInput ? urlInput.value.trim() : '';
            const previewContainer = bitForm.querySelector('.bs-edit-rebit-preview-container');
            const previewCard = bitForm.querySelector('.bs-edit-rebit-preview-card');
            
            if (!url) {
                if (previewContainer) previewContainer.hidden = true;
                if (previewCard) previewCard.innerHTML = '';
                syncEditPreviewArea();
                return;
            }

            if (previewContainer) previewContainer.hidden = false;
            syncEditPreviewArea();

            if (previewCard) {
                previewCard.innerHTML = '<div style="padding: 1rem; text-align: center; color: #94a3b8;"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading preview...</div>';
            }

            const fd = new FormData();
            fd.append('action', 'bitstream_render_rebit_preview');
            fd.append('nonce', bitstream_ajax.og_fetch_nonce);
            fd.append('rebit_url', url);
            
            const titleInput = bitForm.querySelector('.bs-edit-rebit-og-title');
            const descInput = bitForm.querySelector('.bs-edit-rebit-og-desc');
            const imageInput = bitForm.querySelector('.bs-edit-rebit-og-image');
            const imageRemovedInput = bitForm.querySelector('.bs-edit-rebit-og-image-removed');
            const attachmentInput = bitForm.querySelector('.bs-edit-rebit-attachment-id');
            
            fd.append('rebit_og_title', titleInput ? titleInput.value : '');
            fd.append('rebit_og_desc', descInput ? descInput.value : '');
            fd.append('rebit_og_image', imageInput ? imageInput.value : '');
            fd.append('rebit_og_image_removed', imageRemovedInput ? imageRemovedInput.value : '0');
            fd.append('rebit_attachment_id', attachmentInput ? attachmentInput.value : '');

            fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.data || 'Preview failed.');
                    const resp = data.data || {};
                    if (previewCard) {
                        previewCard.innerHTML = resp.rendered_html || '';
                        if (typeof window.applyMediaDeterrents === 'function') {
                            window.applyMediaDeterrents(previewCard);
                        }
                    }
                    syncEditPreviewArea();
                })
                .catch(() => {
                    if (previewCard) {
                        previewCard.innerHTML = '<div style="padding: 1rem; color: #ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Failed to load link preview.</div>';
                    }
                    syncEditPreviewArea();
                });
        };

        function fetchOgMetadataForBitForm(url) {
            if (!url || !window.bitstream_ajax || !bitstream_ajax.og_fetch_nonce) {
                return;
            }
            
            const previewCard = bitForm.querySelector('.bs-edit-rebit-preview-card');
            const previewContainer = bitForm.querySelector('.bs-edit-rebit-preview-container');
            if (previewContainer) previewContainer.hidden = false;
            syncEditPreviewArea();

            if (previewCard) {
                previewCard.innerHTML = '<div style="padding: 1rem; text-align: center; color: #94a3b8;"><i class="fa-solid fa-circle-notch fa-spin"></i> Fetching link preview...</div>';
            }

            const fd = new FormData();
            fd.append('action', 'bitstream_fetch_og_data');
            fd.append('nonce', bitstream_ajax.og_fetch_nonce);
            fd.append('url', url);
            fd.append('post_id', '0');

            fetch(bitstream_ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.data || 'Fetch failed.');
                    const og = data.data || {};
                    const fields = getRebitMetaFields(bitForm);
                    if (fields.titleInput) fields.titleInput.value = og.title || '';
                    if (fields.descInput) fields.descInput.value = og.description || '';
                    if (fields.imageInput) fields.imageInput.value = og.image || '';
                    if (fields.imageRemovedInput) fields.imageRemovedInput.value = og.image ? '0' : '1';
                    if (fields.attachmentInput) fields.attachmentInput.value = '';
                    
                    renderBitFormRebitPreview();
                })
                .catch(() => {
                    if (previewCard) {
                        previewCard.innerHTML = '<div style="padding: 1rem; color: #ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Link preview failed.</div>';
                    }
                    syncEditPreviewArea();
                });
        }

        function getRebitMetaForm() {
            return activeLinkMetaForm || bitForm;
        }

        function getRebitMetaFields(form) {
            return {
                urlInput: bitForm.querySelector('#bs-edit-rebit-url') || bitForm.querySelector('.bs-edit-rebit-url-hidden'),
                attachmentInput: bitForm.querySelector('input[name="rebit_attachment_id"]') || bitForm.querySelector('.bs-edit-rebit-attachment-id'),
                titleInput: bitForm.querySelector('input[name="rebit_og_title"]') || bitForm.querySelector('.bs-edit-rebit-og-title'),
                descInput: bitForm.querySelector('input[name="rebit_og_desc"]') || bitForm.querySelector('.bs-edit-rebit-og-desc'),
                imageInput: bitForm.querySelector('input[name="rebit_og_image"]') || bitForm.querySelector('.bs-edit-og-image'),
                imageRemovedInput: bitForm.querySelector('input[name="rebit_og_image_removed"]') || bitForm.querySelector('.bs-edit-og-image-removed')
            };
        }

        function attemptCloseEditModal() {
            if (editFormIsDirty) {
                showDiscardConfirmation('Are you sure you want to discard your changes?', () => {
                    editFormIsDirty = false;
                    closeLinkMetaModal();
                    modal.hidden = true;
                    document.body.style.overflow = '';
                });
            } else {
                closeLinkMetaModal();
                modal.hidden = true;
                document.body.style.overflow = '';
            }
        }

        function setModalVisible(isVisible) {
            modal.hidden = !isVisible;
            document.body.style.overflow = isVisible ? 'hidden' : '';
        }

        function setLoadingState(isLoading, message) {
            if (loadingState) {
                loadingState.hidden = !isLoading;
            }
            if (loadingMessage) {
                loadingMessage.textContent = message || 'Loading post…';
            }
            if (errorState) {
                errorState.hidden = true;
            }
        }

        function setErrorState(message) {
            if (loadingState) {
                loadingState.hidden = true;
            }
            if (errorState) {
                errorState.hidden = false;
            }
            if (errorMessage) {
                errorMessage.textContent = message || 'Could not load post.';
            }
        }

        function clearFormFeedback() {
            if (loadingState) {
                loadingState.hidden = true;
            }
            if (errorState) {
                errorState.hidden = true;
            }
            if (errorMessage) {
                errorMessage.textContent = '';
            }
        }

        function showForm(form) {
            if (bitForm) {
                bitForm.hidden = form !== bitForm;
            }
        }

        function setAttachmentPreview(form, attachmentId, attachmentUrl, attachmentMime, attachments) {
            if (!form) {
                return;
            }
            if (!isPopulating) {
                editFormIsDirty = true;
            }

            const previewEl = form.querySelector('.bs-edit-media-preview-thumb') || form.querySelector('.bitstream-media-preview') || form.querySelector('.bitstream-composer-preview-media-thumb');
            if (!previewEl) {
                return;
            }

            if (Array.isArray(attachments)) {
                if (typeof window.updateAttachmentsList === 'function') {
                    window.updateAttachmentsList(previewEl, attachments);
                }
            } else if (attachmentId > 0 && attachmentUrl) {
                const singleAtt = {
                    id: attachmentId,
                    url: attachmentUrl,
                    preview_url: attachmentUrl,
                    mime: attachmentMime
                };
                if (typeof window.updateAttachmentsList === 'function') {
                    window.updateAttachmentsList(previewEl, [singleAtt]);
                }
            } else {
                if (typeof window.updateAttachmentsList === 'function') {
                    window.updateAttachmentsList(previewEl, []);
                }
            }
        }

        function setLinkMetaPreview() {
            const metaForm = getRebitMetaForm();
            if (!metaForm || !linkMetaModal) {
                return;
            }

            const fields = getRebitMetaFields(metaForm);
            const urlInput = fields.urlInput;
            const attachmentInput = fields.attachmentInput;
            const titleInput = fields.titleInput;
            const descInput = fields.descInput;
            const imageInput = fields.imageInput;

            const modalUrlInput = linkMetaModal.querySelector('#bs-edit-link-meta-url-input');
            const visibleTitleInput = linkMetaModal.querySelector('#bs-edit-link-meta-title-input');
            const visibleDescInput = linkMetaModal.querySelector('#bs-edit-link-meta-desc-input');
            const previewImage = linkMetaModal.querySelector('.bs-edit-og-preview-img');
            const previewTitle = linkMetaModal.querySelector('.bs-edit-og-preview-title');
            const previewUrl = linkMetaModal.querySelector('.bs-edit-og-preview-url');
            const cropImageButton = linkMetaModal.querySelector('.bs-edit-og-image-crop');

            if (modalUrlInput && urlInput) {
                modalUrlInput.value = urlInput.value || '';
            }
            if (visibleTitleInput && titleInput) {
                visibleTitleInput.value = titleInput.value || '';
            }
            if (visibleDescInput && descInput) {
                visibleDescInput.value = descInput.value || '';
            }

            const imageUrl = imageInput ? (imageInput.value || '') : '';
            if (previewImage) {
                if (imageUrl) {
                    previewImage.src = imageUrl;
                    previewImage.hidden = false;
                } else {
                    previewImage.src = '';
                    previewImage.hidden = true;
                }
            }
            if (previewTitle) {
                previewTitle.textContent = (titleInput && titleInput.value) ? titleInput.value : 'No title yet';
            }
            if (previewUrl) {
                previewUrl.textContent = (urlInput && urlInput.value) ? urlInput.value : '';
            }
            if (cropImageButton) {
                const hasImage = !!imageUrl || (attachmentInput && parseInt(attachmentInput.value || '0', 10) > 0);
                cropImageButton.classList.toggle('is-hidden', !hasImage);
            }
        }

        function syncLinkMetaFields() {
            const metaForm = getRebitMetaForm();
            if (!metaForm || !linkMetaModal) {
                return;
            }

            const fields = getRebitMetaFields(metaForm);
            const urlInput = fields.urlInput;
            const titleInput = fields.titleInput;
            const descInput = fields.descInput;
            const imageInput = fields.imageInput;
            const imageRemovedInput = fields.imageRemovedInput;

            const modalUrlInput = linkMetaModal.querySelector('#bs-edit-link-meta-url-input');
            const visibleTitleInput = linkMetaModal.querySelector('#bs-edit-link-meta-title-input');
            const visibleDescInput = linkMetaModal.querySelector('#bs-edit-link-meta-desc-input');

            if (urlInput && modalUrlInput) {
                urlInput.value = modalUrlInput.value || '';
            }
            if (titleInput && visibleTitleInput) {
                titleInput.value = visibleTitleInput.value || '';
            }
            if (descInput && visibleDescInput) {
                descInput.value = visibleDescInput.value || '';
            }
            if (imageInput && imageRemovedInput) {
                imageRemovedInput.value = imageInput.value ? '0' : '1';
            }

            setLinkMetaPreview();
        }

        function openLinkMetaModal(sourceForm) {
            if (!linkMetaModal) {
                return;
            }

            activeLinkMetaForm = sourceForm || bitForm;
            setLinkMetaPreview();

            const editPostInput = activeLinkMetaForm.querySelector('input[name="edit_post_id"]');
            const editPostId = editPostInput ? parseInt(editPostInput.value || '0', 10) : 0;
            const modalUrlInput = linkMetaModal.querySelector('#bs-edit-link-meta-url-input');
            if (modalUrlInput) {
                if (editPostId === 0) {
                    modalUrlInput.removeAttribute('readonly');
                } else {
                    modalUrlInput.setAttribute('readonly', 'readonly');
                }
            }

            linkMetaModal.hidden = false;
        }

        function closeLinkMetaModal() {
            if (linkMetaModal) {
                linkMetaModal.hidden = true;
            }
            activeLinkMetaForm = null;
        }

        function setQuotePreview(form, quotePostId, quotePreviewHtml) {
            if (!form) {
                return;
            }
            if (!isPopulating) {
                editFormIsDirty = true;
            }

            const quoteInput = form.querySelector('.bs-edit-quote-post-id');
            const quoteWrap = form.querySelector('.bs-edit-quote-preview');
            const quoteCard = form.querySelector('.bs-edit-quote-preview-card');

            if (quoteInput) {
                quoteInput.value = quotePostId > 0 ? String(quotePostId) : '0';
            }

            if (!quoteWrap || !quoteCard) {
                return;
            }

            quoteCard.innerHTML = quotePreviewHtml || '';
            quoteWrap.hidden = !quotePreviewHtml;
            if (quotePreviewHtml) {
                if (typeof window.applyMediaDeterrents === 'function') {
                    window.applyMediaDeterrents(quoteCard);
                }
                parseTimelineCards(quoteCard);
            }
            if (form === bitForm && typeof syncEditPreviewArea === 'function') {
                syncEditPreviewArea();
            }
        }

        function setScheduleState(form, scheduleKey, enabled, datetimeValue) {
            if (!form) {
                return;
            }
            if (!isPopulating) {
                editFormIsDirty = true;
            }

            const modeNow = form.querySelector('input[name="' + scheduleKey + '_schedule_mode"][value="now"]');
            const modeLater = form.querySelector('input[name="' + scheduleKey + '_schedule_mode"][value="later"]');
            const datetimeInput = form.querySelector('input[name="' + scheduleKey + '_schedule_datetime"]');
            const enabledInput = form.querySelector('input[name="' + scheduleKey + '_schedule_enabled"]');

            if (modeNow) {
                modeNow.checked = !enabled;
            }
            if (modeLater) {
                modeLater.checked = !!enabled;
            }
            if (datetimeInput) {
                datetimeInput.disabled = !enabled;
                datetimeInput.value = enabled ? (datetimeValue || '') : '';
            }
            if (enabledInput) {
                enabledInput.value = enabled ? '1' : '0';
            }
        }

        function bindScheduleControls(form) {
            const composerType = form.dataset.composerType || 'bit';
            const radios = form.querySelectorAll('input[name="' + composerType + '_schedule_mode"]');
            const datetimeInput = form.querySelector('input[name="' + composerType + '_schedule_datetime"]');
            const enabledInput = form.querySelector('input[name="' + composerType + '_schedule_enabled"]');

            if (!radios.length || !datetimeInput) {
                return;
            }

            radios.forEach(radio => {
                radio.addEventListener('change', () => {
                    const enabled = radio.value === 'later' && radio.checked;
                    datetimeInput.disabled = !enabled;
                    if (enabledInput) {
                        enabledInput.value = enabled ? '1' : '0';
                    }
                    if (!enabled) {
                        datetimeInput.value = '';
                    }
                });
            });
        }

        function bindMediaControls(form) {
            const dropzone = form.querySelector('.bitstream-media-dropzone');
            const fileInput = form.querySelector('.bitstream-media-file');
            const removeButton = form.querySelector('.bitstream-media-remove');
            const cropButton = form.querySelector('.bitstream-media-crop');
            const libraryButton = form.querySelector('.bitstream-media-library');
            const pasteButton = form.querySelector('.bitstream-media-paste');
            const targetInput = form.querySelector('.bs-edit-attachment-id');
            const previewEl = form.querySelector('.bitstream-media-preview');

            if (!targetInput || !previewEl) {
                return;
            }

            const targetInputId = targetInput.id || 'bs-edit-bit-attachment-id';
            const targetPreviewId = previewEl.id || 'bs-edit-bit-media-preview';

            const uploadFiles = (files) => {
                if (typeof window.uploadMultipleFiles === 'function') {
                    window.uploadMultipleFiles(files, targetInputId, targetPreviewId, {
                        setStatus: (msg, isError) => {
                            if (isError) {
                                setErrorState(msg);
                            } else {
                                clearFormFeedback();
                            }
                        }
                    });
                }
            };

            const pasteImage = () => {
                if (!navigator.clipboard || typeof navigator.clipboard.read !== 'function') {
                    setErrorState('Clipboard paste is unavailable in this browser.');
                    return;
                }

                navigator.clipboard.read()
                    .then(items => {
                        if (!items || !items.length) {
                            throw new Error('Clipboard is empty.');
                        }

                        const imageItem = items.find(item => item.types && item.types.some(type => type.indexOf('image/') === 0));
                        if (!imageItem) {
                            throw new Error('Clipboard does not contain an image.');
                        }

                        const imageType = imageItem.types.find(type => type.indexOf('image/') === 0) || 'image/png';
                        return imageItem.getType(imageType).then(blob => {
                            const extension = imageType.indexOf('jpeg') !== -1 ? 'jpg' : (imageType.split('/')[1] || 'png');
                            const filename = 'pasted-image-' + Date.now() + '.' + extension;
                            const file = new File([blob], filename, { type: imageType });
                            uploadFiles([file]);
                        });
                    })
                    .catch(error => {
                        setErrorState(error.message || 'Clipboard permission denied.');
                    });
            };

            if (dropzone && fileInput) {
                dropzone.addEventListener('click', (event) => {
                    if (event.target === fileInput) return;
                    if (event.target.closest('.bitstream-media-preview-item') ||
                        event.target.closest('.bitstream-media-preview-remove-item')) {
                        return;
                    }
                    fileInput.click();
                });

                dropzone.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    dropzone.classList.add('is-dragover');
                });

                dropzone.addEventListener('dragleave', () => {
                    dropzone.classList.remove('is-dragover');
                });

                dropzone.addEventListener('drop', (event) => {
                    event.preventDefault();
                    dropzone.classList.remove('is-dragover');

                    const files = event.dataTransfer.files;
                    if (files && files.length > 0) {
                        uploadFiles(Array.from(files));
                    }
                });

                fileInput.addEventListener('change', () => {
                    const files = fileInput.files;
                    if (files && files.length > 0) {
                        uploadFiles(Array.from(files));
                    }
                    fileInput.value = '';
                });
            }

            if (removeButton) {
                removeButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    setAttachmentPreview(form, 0, '', '', []);
                });
            }

            if (cropButton) {
                cropButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (cropButton.classList.contains('is-hidden')) return;

                    const openCropperFn = window.bitstreamOpenCropper;
                    if (openCropperFn) {
                        openCropperFn(targetInputId, targetPreviewId, {
                            onComplete: (croppedMedia) => {
                                setAttachmentPreview(form, croppedMedia.id, croppedMedia.url, croppedMedia.mime);
                            }
                        });
                    } else {
                        setErrorState('Image cropper is unavailable.');
                    }
                });
            }

            if (libraryButton) {
                libraryButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (!window.wp || !wp.media) {
                        setErrorState('The media library is unavailable.');
                        return;
                    }

                    const isRebit = targetInputId && (targetInputId.indexOf('rebit') !== -1);

                    const frame = wp.media({
                        title: 'Select media',
                        button: { text: 'Use media' },
                        multiple: !isRebit,
                        library: { type: ['image', 'video'] }
                    });

                    frame.on('select', () => {
                        const selections = frame.state().get('selection').models;
                        const loadedAttachments = [];
                        selections.forEach(selection => {
                            const data = selection.toJSON();
                            const mime = data.mime || data.type || '';
                            if (mime.startsWith('image/') || mime.startsWith('video/')) {
                                loadedAttachments.push({
                                    id: data.id,
                                    url: data.url,
                                    preview_url: data.preview_url || (data.sizes && ((data.sizes.large && data.sizes.large.url) || (data.sizes.medium_large && data.sizes.medium_large.url) || (data.sizes.medium && data.sizes.medium.url))) || data.url,
                                    mime: mime,
                                    filename: data.filename || data.title || ''
                                });
                            }
                        });

                        const previewEl = getPreviewElement(targetPreviewId);
                        const existingAttachments = typeof window.getExistingAttachments === 'function' ? window.getExistingAttachments(previewEl) : [];
                        let finalAttachments = [];
                        if (isRebit) {
                            finalAttachments = loadedAttachments.slice(0, 1);
                        } else {
                            finalAttachments = [...existingAttachments, ...loadedAttachments].slice(0, 10);
                        }

                        if (typeof window.updateAttachmentsList === 'function') {
                            window.updateAttachmentsList(previewEl, finalAttachments);
                        }
                    });

                    frame.open();
                });
            }

            if (pasteButton) {
                pasteButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    pasteImage();
                });
            }

            const textarea = form.querySelector('.bs-edit-textarea');
            if (textarea) {
                textarea.addEventListener('paste', (event) => {
                    const editPostInput = form.querySelector('input[name="edit_post_id"]');
                    const isEditMode = editPostInput && parseInt(editPostInput.value || '0', 10) > 0;
                    if (isEditMode) {
                        return;
                    }
                    const clipboardData = event.clipboardData || window.clipboardData;
                    if (!clipboardData) {
                        return;
                    }

                    const validFiles = [];
                    if (clipboardData.files && clipboardData.files.length > 0) {
                        for (let i = 0; i < clipboardData.files.length; i++) {
                            const f = clipboardData.files[i];
                            if (f.type && (f.type.startsWith('image/') || f.type.startsWith('video/'))) {
                                validFiles.push(f);
                            }
                        }
                    }
                    if (validFiles.length === 0 && clipboardData.items && clipboardData.items.length > 0) {
                        for (let i = 0; i < clipboardData.items.length; i++) {
                            const item = clipboardData.items[i];
                            if (item.type && (item.type.startsWith('image/') || item.type.startsWith('video/'))) {
                                const f = item.getAsFile();
                                if (f) validFiles.push(f);
                            }
                        }
                    }

                    if (validFiles.length > 0) {
                        event.preventDefault();
                        uploadFiles(validFiles);
                    }
                });

                textarea.addEventListener('input', () => {
                    if (window.matchMedia('(max-width: 1023px)').matches) {
                        if (typeof window.bsMobileAutoResize === 'function') {
                            window.bsMobileAutoResize(textarea);
                        }
                    } else {
                        const flexItem = textarea.closest('.bs-edit-field') || textarea;
                        flexItem.style.removeProperty('flex-basis');
                    }
                });
            }
        }

        function bindLinkMetaControls() {
            if (!bitForm || !linkMetaModal || linkMetaModal.dataset.timelineMetaBound === '1') {
                return;
            }

            linkMetaModal.dataset.timelineMetaBound = '1';

            const refetchButton = linkMetaModal.querySelector('.bs-edit-link-meta-refetch');
            const closeButtons = linkMetaModal.querySelectorAll('[data-bs-edit-link-meta-close="true"]');
            const saveButton = linkMetaModal.querySelector('.bs-edit-link-meta-save');
            const modalUrlInput = linkMetaModal.querySelector('#bs-edit-link-meta-url-input');
            const visibleTitleInput = linkMetaModal.querySelector('#bs-edit-link-meta-title-input');
            const visibleDescInput = linkMetaModal.querySelector('#bs-edit-link-meta-desc-input');
            const chooseImageButton = linkMetaModal.querySelector('.bs-edit-og-image-select');
            const cropImageButton = linkMetaModal.querySelector('.bs-edit-og-image-crop');
            const clearImageButton = linkMetaModal.querySelector('.bs-edit-og-image-clear');

            const rebitOpenBtn = bitForm ? bitForm.querySelector('.bs-edit-link-meta-open') : null;
            if (rebitOpenBtn) {
                rebitOpenBtn.addEventListener('click', () => {
                    const fields = getRebitMetaFields(bitForm);
                    if (modalUrlInput && fields.urlInput) {
                        modalUrlInput.value = fields.urlInput.value || '';
                    }
                    openLinkMetaModal(bitForm);
                });
            }

            const bitOpenBtn = bitForm ? bitForm.querySelector('.bs-edit-bit-link-meta-open') : null;
            if (bitOpenBtn) {
                bitOpenBtn.addEventListener('click', () => {
                    const fields = getRebitMetaFields(bitForm);
                    if (modalUrlInput && fields.urlInput) {
                        modalUrlInput.value = fields.urlInput.value || '';
                    }
                    openLinkMetaModal(bitForm);
                });
            }

            closeButtons.forEach(button => {
                button.addEventListener('click', closeLinkMetaModal);
            });

            if (saveButton) {
                saveButton.addEventListener('click', () => {
                    syncLinkMetaFields();
                    closeLinkMetaModal();
                    const activeForm = getRebitMetaForm();
                    if (activeForm === bitForm) {
                        renderBitFormRebitPreview();
                    }
                });
            }

            if (refetchButton) {
                refetchButton.addEventListener('click', () => {
                    const activeForm = getRebitMetaForm();
                    const fields = getRebitMetaFields(activeForm);
                    const targetUrl = (modalUrlInput && modalUrlInput.value ? modalUrlInput.value : (fields.urlInput ? fields.urlInput.value : '')).trim();
                    const editPostInput = activeForm ? activeForm.querySelector('input[name="edit_post_id"]') : null;

                    if (!targetUrl) {
                        setErrorState('Add a link URL first.');
                        return;
                    }

                    if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.og_fetch_nonce) {
                        setErrorState('Metadata fetch is unavailable.');
                        return;
                    }

                    refetchButton.disabled = true;
                    if (!refetchButton.dataset.origHtml) {
                        refetchButton.dataset.origHtml = refetchButton.innerHTML;
                    }
                    refetchButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Fetching...';

                    const payload = new FormData();
                    payload.append('action', 'bitstream_fetch_og_data');
                    payload.append('nonce', bitstream_ajax.og_fetch_nonce);
                    payload.append('url', targetUrl);
                    payload.append('post_id', editPostInput ? (editPostInput.value || '0') : '0');

                    fetch(bitstream_ajax.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: payload
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.data || 'Could not fetch metadata.');
                            }

                            const og = data.data || {};
                            const activeFields = getRebitMetaFields(getRebitMetaForm());

                            if (activeFields.urlInput) {
                                activeFields.urlInput.value = targetUrl;
                            }
                            if (activeFields.titleInput) {
                                activeFields.titleInput.value = og.title || '';
                            }
                            if (activeFields.descInput) {
                                activeFields.descInput.value = og.description || '';
                            }
                            if (activeFields.imageInput) {
                                activeFields.imageInput.value = og.image || '';
                            }
                            if (activeFields.imageRemovedInput) {
                                activeFields.imageRemovedInput.value = og.image ? '0' : '1';
                            }
                            if (activeFields.attachmentInput) {
                                activeFields.attachmentInput.value = '';
                            }

                            setLinkMetaPreview();
                            const currentActiveForm = getRebitMetaForm();
                            if (currentActiveForm === bitForm) {
                                renderBitFormRebitPreview();
                            }
                            clearFormFeedback();
                        })
                        .catch(error => {
                            setErrorState(error.message || 'Could not fetch metadata.');
                        })
                        .finally(() => {
                            if (refetchButton.dataset.origHtml) {
                                refetchButton.innerHTML = refetchButton.dataset.origHtml;
                                delete refetchButton.dataset.origHtml;
                            }
                            refetchButton.disabled = false;
                        });
                });
            }

            if (visibleTitleInput) {
                visibleTitleInput.addEventListener('input', syncLinkMetaFields);
            }

            if (visibleDescInput) {
                visibleDescInput.addEventListener('input', syncLinkMetaFields);
            }

            if (chooseImageButton) {
                chooseImageButton.addEventListener('click', () => {
                    if (!window.wp || !wp.media) {
                        setErrorState('The media library is unavailable.');
                        return;
                    }

                    if (!ogImageFrame) {
                        ogImageFrame = wp.media({
                            title: 'Select link image',
                            button: { text: 'Use this image' },
                            multiple: false,
                            library: { type: 'image' }
                        });

                        ogImageFrame.on('select', () => {
                            const selection = ogImageFrame.state().get('selection').first();
                            if (!selection) {
                                return;
                            }

                            const attachment = selection.toJSON();
                            const activeForm = getRebitMetaForm();
                            const activeFields = getRebitMetaFields(activeForm);

                            if (activeFields.attachmentInput) {
                                activeFields.attachmentInput.value = attachment.id || '';
                            }
                            if (activeFields.imageInput) {
                                activeFields.imageInput.value = attachment.url || '';
                            }
                            if (activeFields.imageRemovedInput) {
                                activeFields.imageRemovedInput.value = '0';
                            }

                            setLinkMetaPreview();
                            if (activeForm === bitForm) {
                                renderBitFormRebitPreview();
                            }
                        });
                    }

                    ogImageFrame.open();
                });
            }

            if (cropImageButton) {
                cropImageButton.addEventListener('click', () => {
                    const activeForm = getRebitMetaForm();
                    const activeFields = getRebitMetaFields(activeForm);
                    const attachmentId = activeFields.attachmentInput ? parseInt(activeFields.attachmentInput.value || '0', 10) : 0;
                    const imageUrl = activeFields.imageInput ? (activeFields.imageInput.value || '').trim() : '';

                    const openCropperForAttachment = (preparedAttachmentId) => {
                        if (!preparedAttachmentId) {
                            setErrorState('Select an image first.');
                            return;
                        }

                        const cropperOpener = (typeof window.bitstreamOpenCropper === 'function')
                            ? window.bitstreamOpenCropper
                            : null;

                        if (window.bitstream_ajax && bitstream_ajax.media_crop_nonce && cropperOpener) {
                            cropperOpener('bitstream-rebit-attachment-id', '', {
                                attachmentId: preparedAttachmentId,
                                onComplete: (croppedMedia, croppedUrl) => {
                                    const currentForm = getRebitMetaForm();
                                    const currentFields = getRebitMetaFields(currentForm);

                                    if (croppedMedia && croppedMedia.id) {
                                        if (currentFields.attachmentInput) {
                                            currentFields.attachmentInput.value = croppedMedia.id;
                                        }
                                    }
                                    if (currentFields.imageInput) {
                                        currentFields.imageInput.value = croppedUrl || (croppedMedia && croppedMedia.url) || '';
                                    }
                                    if (currentFields.imageRemovedInput) {
                                        currentFields.imageRemovedInput.value = '0';
                                    }

                                    setLinkMetaPreview();
                                    if (currentForm === bitForm) {
                                        renderBitFormRebitPreview();
                                    }
                                }
                            });
                        } else {
                            setErrorState('Image cropper is unavailable.');
                        }
                    };

                    if (attachmentId > 0) {
                        openCropperForAttachment(attachmentId);
                        return;
                    }

                    if (!imageUrl) {
                        setErrorState('Select an image first.');
                        return;
                    }

                    if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                        setErrorState('Image cropper is unavailable.');
                        return;
                    }

                    cropImageButton.disabled = true;
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
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.data || 'Could not prepare image for crop.');
                            }

                            const prepared = data.data || {};
                            const currentForm = getRebitMetaForm();
                            const currentFields = getRebitMetaFields(currentForm);

                            if (currentFields.attachmentInput) {
                                currentFields.attachmentInput.value = prepared.id || '';
                            }
                            if (currentFields.imageInput) {
                                currentFields.imageInput.value = prepared.url || imageUrl;
                            }
                            if (currentFields.imageRemovedInput) {
                                currentFields.imageRemovedInput.value = '0';
                            }
                            openCropperForAttachment(prepared.id || 0);
                        })
                        .catch(error => {
                            setErrorState(error.message || 'Could not prepare image for crop.');
                        })
                        .finally(() => {
                            cropImageButton.disabled = false;
                        });
                });
            }

            if (clearImageButton) {
                const clearLinkImage = () => {
                    const activeForm = getRebitMetaForm();
                    const activeFields = getRebitMetaFields(activeForm);

                    if (activeFields.attachmentInput) {
                        activeFields.attachmentInput.value = '';
                    }
                    if (activeFields.imageInput) {
                        activeFields.imageInput.value = '';
                    }
                    if (activeFields.imageRemovedInput) {
                        activeFields.imageRemovedInput.value = '1';
                    }

                    setLinkMetaPreview();
                    if (activeForm === bitForm) {
                        renderBitFormRebitPreview();
                    }
                };

                clearImageButton.addEventListener('click', clearLinkImage);
            }
        }

        function bindFormOnce(form) {
            if (!form || form.dataset.timelineModalBound === '1') {
                return;
            }

            form.dataset.timelineModalBound = '1';
            bindScheduleControls(form);
            bindMediaControls(form);
            if (form === bitForm) {
                bindLinkMetaControls();

                const editMediaToggleBtn = bitForm.querySelector('.bs-edit-media-toggle-btn');
                if (editMediaToggleBtn) {
                    editMediaToggleBtn.addEventListener('click', () => {
                        const editMedia = bitForm.querySelector('.bs-edit-media');
                        if (editMedia) {
                            const mediaField = editMedia.querySelector('.bitstream-media-field');
                            if (editMedia.hidden) {
                                editMedia.hidden = false;
                                if (mediaField) {
                                    mediaField.classList.add('media-uploader-open');
                                }
                            } else {
                                if (mediaField && !mediaField.classList.contains('media-uploader-open')) {
                                    mediaField.classList.add('media-uploader-open');
                                } else {
                                    editMedia.hidden = true;
                                    if (mediaField) {
                                        mediaField.classList.remove('media-uploader-open');
                                    }
                                }
                            }
                        }
                    });
                }

                const editRebitToggleBtn = bitForm.querySelector('.bs-edit-rebit-toggle-btn');
                if (editRebitToggleBtn) {
                    editRebitToggleBtn.addEventListener('click', () => {
                        const fields = getRebitMetaFields(bitForm);
                        const modalUrlInput = linkMetaModal ? linkMetaModal.querySelector('#bs-edit-link-meta-url-input') : null;
                        if (modalUrlInput && fields.urlInput) {
                            modalUrlInput.value = fields.urlInput.value || '';
                        }
                        openLinkMetaModal(bitForm);
                    });
                }
            }

            const removeBtn = bitForm ? bitForm.querySelector('.bs-edit-rebit-remove-btn') : null;
            if (removeBtn) {
                removeBtn.addEventListener('click', () => {
                    clearBitFormRebit();
                });
            }

            const quoteRemoveBtn = form.querySelector('.bs-edit-quote-remove-btn');
            if (quoteRemoveBtn) {
                quoteRemoveBtn.addEventListener('click', () => {
                    setQuotePreview(form, 0, '');
                });
            }

            const editMediaRemoveBtn = form.querySelector('.bs-edit-media-remove-btn');
            if (editMediaRemoveBtn) {
                editMediaRemoveBtn.addEventListener('click', () => {
                    setAttachmentPreview(form, 0, '', '', []);
                });
            }
        }

        function populateEditForm(data, isQuoteMode) {
            if (!bitForm) {
                return;
            }

            bindFormOnce(bitForm);
            showForm(bitForm);

            const editMediaWrap = bitForm.querySelector('.bs-edit-media');

            const isRebit = (data.post_type === 'rebit');
            const postId = parseInt(data.post_id || '0', 10);
            const editPostInput = bitForm.querySelector('input[name="edit_post_id"]');
            const composerTypeInput = bitForm.querySelector('.bs-edit-composer-type');
            const contentInput = bitForm.querySelector('#bs-edit-bit-content');
            const contentLabel = bitForm.querySelector('#bs-edit-content-label');
            const submitButton = bitForm.querySelector('.bs-edit-submit');
            const draftButton = bitForm.querySelector('.bs-edit-save-draft');
            const urlField = bitForm.querySelector('.bs-edit-url-field');
            const urlInput = bitForm.querySelector('#bs-edit-rebit-url');
            
            const attachmentId = parseInt(data.attachment_id || '0', 10);
            const attachmentUrl = data.attachment_url || '';
            const attachmentMime = data.attachment_mime || '';
            const quotePostId = parseInt(data.quote_post_id || (isQuoteMode ? postId : 0), 10);
            const quotePreviewHtml = isQuoteMode ? (data.quote_preview_html || '') : (data.quote_preview_html || '');
            const scheduleEnabled = (data.post_status === 'future' || data.schedule_enabled === '1');
            const scheduleDatetime = data.schedule_datetime || '';

            if (composerTypeInput) {
                composerTypeInput.value = isRebit ? 'rebit' : 'bit';
            }

            if (modalTitle) {
                modalTitle.textContent = isRebit ? 'Edit Rebit' : (isQuoteMode ? 'Quote Bit' : 'Edit Bit');
            }

            if (editPostInput) {
                editPostInput.value = isQuoteMode ? '0' : String(postId || 0);
            }

            if (contentInput) {
                const val = isQuoteMode ? '' : (data.content || '');
                if (window.BitStream && window.BitStream.Editor) {
                    window.BitStream.Editor.init(contentInput);
                    window.BitStream.Editor.setTextContent(contentInput, val);
                } else {
                    contentInput.textContent = val;
                }
                if (window.matchMedia('(max-width: 1023px)').matches) {
                    if (typeof window.bsMobileAutoResize === 'function') {
                        window.bsMobileAutoResize(contentInput);
                    }
                }
            }

            if (contentLabel) {
                contentLabel.textContent = isRebit ? 'Commentary' : 'Content';
            }

            if (urlField) {
                urlField.hidden = true;
            }

            if (urlInput) {
                urlInput.value = isRebit ? (data.rebit_url || '') : '';
            }

            setQuotePreview(bitForm, quotePostId, quotePreviewHtml);

            const quoteRemoveBtn = bitForm.querySelector('.bs-edit-quote-remove-btn');
            if (quoteRemoveBtn) {
                quoteRemoveBtn.style.display = isQuoteMode ? 'none' : '';
            }
            
            const hasAttachments = data.attachments && data.attachments.length > 0;
            if (editMediaWrap) {
                editMediaWrap.hidden = !hasAttachments;
                const mediaField = editMediaWrap.querySelector('.bitstream-media-field');
                if (mediaField) {
                    mediaField.classList.remove('media-uploader-open');
                }
            }
            setAttachmentPreview(bitForm, isQuoteMode ? 0 : attachmentId, isQuoteMode ? '' : attachmentUrl, isQuoteMode ? '' : attachmentMime, isQuoteMode ? [] : (data.attachments || []));
            setScheduleState(bitForm, 'bit', scheduleEnabled && !isQuoteMode, isQuoteMode ? '' : scheduleDatetime);

            // Populate Rebit fields
            const rebitUrl = isQuoteMode ? '' : (data.rebit_url || '');
            const fields = getRebitMetaFields(bitForm);
            if (fields.urlInput) fields.urlInput.value = rebitUrl;
            if (fields.titleInput) fields.titleInput.value = isQuoteMode ? '' : (data.og_title || '');
            if (fields.descInput) fields.descInput.value = isQuoteMode ? '' : (data.og_desc || '');
            if (fields.imageInput) fields.imageInput.value = isQuoteMode ? '' : (data.og_image || '');
            if (fields.imageRemovedInput) fields.imageRemovedInput.value = '0';
            if (fields.attachmentInput) fields.attachmentInput.value = isQuoteMode ? '' : (data.rebit_attachment_id || '');

            if (rebitUrl) {
                const previewContainer = bitForm.querySelector('.bs-edit-rebit-preview-container');
                if (previewContainer) previewContainer.hidden = false;
                renderBitFormRebitPreview();
            } else {
                const previewContainer = bitForm.querySelector('.bs-edit-rebit-preview-container');
                if (previewContainer) previewContainer.hidden = true;
                const previewCard = bitForm.querySelector('.bs-edit-rebit-preview-card');
                if (previewCard) previewCard.innerHTML = '';
                syncEditPreviewArea();
            }

            const rebitRemoveBtn = bitForm.querySelector('.bs-edit-rebit-remove-btn');
            if (rebitRemoveBtn) {
                rebitRemoveBtn.style.display = isRebit ? 'none' : '';
            }

            // Mood integration
            const moodEmojiInput = bitForm.querySelector('.bs-edit-mood-emoji');
            const moodEmotionInput = bitForm.querySelector('.bs-edit-mood-emotion');
            const moodBtn = bitForm.querySelector('.bs-edit-mood-btn');
            const moodLabel = bitForm.querySelector('.bs-edit-mood-label');
            const moodRemove = bitForm.querySelector('.bs-edit-mood-remove');

            const moodEmoji = isQuoteMode ? '' : (data.mood_emoji || '');
            const moodEmotion = isQuoteMode ? '' : (data.mood_emotion || '');

            if (moodEmojiInput) moodEmojiInput.value = moodEmoji;
            if (moodEmotionInput) moodEmotionInput.value = moodEmotion;

            if (moodBtn && moodLabel && moodRemove) {
                if (moodEmotion) {
                    moodLabel.textContent = `${moodEmoji} Feeling ${moodEmotion}`;
                    if (typeof window.parseEmojis === 'function') window.parseEmojis(moodLabel);
                    moodRemove.style.display = 'inline-block';
                } else {
                    moodLabel.textContent = 'Add Mood';
                    moodRemove.style.display = 'none';
                }
            }

            const actionsRow = bitForm.querySelector('.bs-edit-actions-row');
            if (actionsRow) {
                actionsRow.style.display = isQuoteMode ? 'flex' : 'none';
            }

            if (submitButton) {
                submitButton.textContent = isRebit ? 'Update Rebit' : (isQuoteMode ? 'Post Bit' : 'Update Bit');
            }
            if (draftButton) {
                draftButton.textContent = data.post_status === 'draft' && !isQuoteMode ? 'Update Draft' : 'Save to Drafts';
            }

            setLoadingState(false);
            clearFormFeedback();
            setModalVisible(true);
        }

        function submitModalForm(form, saveAsDraft) {
            if (!form || !window.bitstream_ajax || !bitstream_ajax.ajax_url) {
                setErrorState('Poster submit endpoint is unavailable.');
                return;
            }

            if (!submitNonce) {
                setErrorState('Security token missing. Refresh and try again.');
                return;
            }

            const composerTypeInput = form.querySelector('.bs-edit-composer-type');
            const composerType = composerTypeInput ? composerTypeInput.value : (form.dataset.composerType || 'bit');
            const editPostInput = form.querySelector('input[name="edit_post_id"]');
            const submitButton = form.querySelector('.bs-edit-submit');
            const draftButton = form.querySelector('.bs-edit-save-draft');
            const scheduleDatetimeInput = form.querySelector('input[name="' + composerType + '_schedule_datetime"]');
            const scheduleLater = form.querySelector('input[name="' + composerType + '_schedule_mode"][value="later"]');

            if (scheduleLater && scheduleLater.checked && scheduleDatetimeInput && !scheduleDatetimeInput.value) {
                setErrorState('Please choose a date and time for the schedule.');
                return;
            }

            let effectiveType = composerType;
            if (composerType === 'bit') {
                const textarea = form.querySelector('#bs-edit-bit-content');
                const attachmentInput = form.querySelector('.bs-edit-attachment-id');
                const quoteInput = form.querySelector('.bs-edit-quote-post-id');
                const moodInput = form.querySelector('.bs-edit-mood-emotion');
                const rebitUrlInput = form.querySelector('.bs-edit-rebit-url-hidden');
                const content = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
                const hasMedia = attachmentInput && parseInt(attachmentInput.value || '0', 10) > 0;
                const hasQuote = quoteInput && parseInt(quoteInput.value || '0', 10) > 0;
                const hasMood = moodInput && moodInput.value.trim();
                const hasRebit = rebitUrlInput && rebitUrlInput.value.trim();

                if (!saveAsDraft && !content && !hasMedia && !hasQuote && !hasMood && !hasRebit) {
                    setErrorState('Write something, attach media, or add a link.');
                    return;
                }

                if (!saveAsDraft && !hasMedia && !hasQuote && content) {
                    try {
                        const url = new URL(content);
                        if (url.protocol === 'http:' || url.protocol === 'https:') {
                            effectiveType = 'rebit';
                        }
                    } catch {
                        // Keep it as a Bit.
                    }
                }
            } else {
                const urlInput = form.querySelector('#bs-edit-rebit-url');
                const rebitUrl = urlInput ? urlInput.value.trim() : '';
                if (!saveAsDraft && !rebitUrl) {
                    setErrorState('Add a link URL.');
                    return;
                }
            }

            clearFormFeedback();
            const isUpdate = parseInt(editPostInput ? (editPostInput.value || '0') : '0', 10) > 0;
            const actionText = saveAsDraft
                ? (isUpdate ? 'Updating draft...' : 'Saving draft...')
                : (isUpdate ? 'Updating...' : 'Publishing...');

            const activeBtn = saveAsDraft ? draftButton : submitButton;
            if (activeBtn) {
                if (!activeBtn.dataset.origHtml) {
                    activeBtn.dataset.origHtml = activeBtn.innerHTML;
                }
                activeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> ' + actionText;
            }
            if (submitButton) submitButton.disabled = true;
            if (draftButton) draftButton.disabled = true;

            const restoreSubmitButtons = () => {
                if (submitButton) {
                    if (submitButton.dataset.origHtml) {
                        submitButton.innerHTML = submitButton.dataset.origHtml;
                        delete submitButton.dataset.origHtml;
                    }
                    submitButton.disabled = false;
                }
                if (draftButton) {
                    if (draftButton.dataset.origHtml) {
                        draftButton.innerHTML = draftButton.dataset.origHtml;
                        delete draftButton.dataset.origHtml;
                    }
                    draftButton.disabled = false;
                }
            };

            const payload = new FormData(form);
            payload.append('action', 'bitstream_submit_composer');
            payload.append('nonce', submitNonce);
            payload.append('composer_type', effectiveType);
            payload.set('edit_post_id', editPostInput ? (editPostInput.value || '0') : '0');

            if (saveAsDraft) {
                payload.append('save_as_draft', '1');
            }

            if (effectiveType === 'rebit' && composerType === 'bit') {
                const textarea = form.querySelector('#bs-edit-bit-content');
                const content = textarea ? (window.BitStream && window.BitStream.Editor ? window.BitStream.Editor.getEditorValue(textarea) : (textarea.value || '')).trim() : '';
                if (content) {
                    payload.set('rebit_url', content);
                    payload.delete('bit_content');
                }
            }

            fetch(bitstream_ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.data || 'Could not save post.');
                    }

                    const responseData = data.data || {};
                    const createdPostId = parseInt(responseData.post_id || '0', 10);
                    const isScheduled = !!responseData.is_scheduled;

                    editFormIsDirty = false;

                    if (saveAsDraft) {
                        const composerBaseUrl = (window.bitstream_ajax && bitstream_ajax.composer_url)
                            ? bitstream_ajax.composer_url
                            : window.location.href;
                        const redirectUrl = new URL(composerBaseUrl, window.location.origin);
                        redirectUrl.searchParams.set('composer_tab', 'drafts');
                        if (createdPostId > 0) {
                            redirectUrl.searchParams.set('highlight_draft', String(createdPostId));
                        }
                        window.location.href = redirectUrl.toString();
                        return;
                    }

                    if (isScheduled) {
                        const composerBaseUrl = (window.bitstream_ajax && bitstream_ajax.composer_url)
                            ? bitstream_ajax.composer_url
                            : window.location.href;
                        const redirectUrl = new URL(composerBaseUrl, window.location.origin);
                        redirectUrl.searchParams.set('composer_tab', 'scheduled');
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
                .catch(error => {
                    restoreSubmitButtons();
                    setErrorState(error.message || 'Could not save post.');
                });
        }

        openTimelineEditModal = function (postId, postType) {
            const numericPostId = parseInt(postId || '0', 10);
            if (!numericPostId || !window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) {
                return false;
            }

            if (modalTitle) {
                modalTitle.textContent = (postType === 'rebit') ? 'Edit Rebit' : 'Edit Bit';
            }

            setModalVisible(true);
            setLoadingState(true, (postType === 'rebit') ? 'Loading rebit…' : 'Loading bit…');
            clearFormFeedback();
            showForm(null);

            const payload = new FormData();
            payload.append('action', 'bitstream_get_post_edit_data');
            payload.append('nonce', submitNonce);
            payload.append('post_id', String(numericPostId));

            fetch(bitstream_ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.data || 'Could not load post data.');
                    }

                    const responseData = data.data || {};
                    isPopulating = true;
                    populateEditForm(responseData, false);
                    isPopulating = false;
                    editFormIsDirty = false;
                })
                .catch(error => {
                    setErrorState(error.message || 'Could not load post data.');
                });

            return true;
        };
        window.openTimelineEditModal = openTimelineEditModal;

        openTimelineQuoteModal = function (postId) {
            const numericPostId = parseInt(postId || '0', 10);
            if (!numericPostId || !window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) {
                return false;
            }

            if (modalTitle) {
                modalTitle.textContent = 'Quote Bit';
            }

            setModalVisible(true);
            setLoadingState(true, 'Loading quote preview…');
            clearFormFeedback();
            showForm(null);

            const payload = new FormData();
            payload.append('action', 'bitstream_get_quote_preview');
            payload.append('nonce', submitNonce);
            payload.append('post_id', String(numericPostId));

            fetch(bitstream_ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.data || 'Could not load quote preview.');
                    }

                    const responseData = data.data || {};
                    isPopulating = true;
                    populateEditForm({
                        post_id: numericPostId,
                        content: '',
                        quote_post_id: numericPostId,
                        quote_preview_html: responseData.quote_preview_html || '',
                        attachment_id: 0,
                        attachment_url: '',
                        attachment_mime: '',
                        post_status: 'publish',
                        schedule_enabled: '0',
                        schedule_datetime: ''
                    }, true);
                    isPopulating = false;
                    editFormIsDirty = false;
                })
                .catch(error => {
                    setErrorState(error.message || 'Could not load quote preview.');
                });

            return true;
        };
        window.openTimelineQuoteModal = openTimelineQuoteModal;

        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                attemptCloseEditModal();
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                attemptCloseEditModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                attemptCloseEditModal();
            }
        });

        modal.querySelectorAll('.bs-edit-save-draft').forEach(button => {
            button.addEventListener('click', () => {
                const form = button.closest('.bs-edit-form');
                if (form) {
                    submitModalForm(form, true);
                }
            });
        });

        modal.querySelectorAll('.bs-edit-form').forEach(form => {
            bindFormOnce(form);
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitModalForm(form, false);
            });
        });

        // Track dirty fields for the edit modal
        modal.querySelectorAll('textarea, input[type="text"], input[type="url"]').forEach(input => {
            input.addEventListener('input', () => {
                if (!isPopulating) {
                    editFormIsDirty = true;
                }
            });
        });
        modal.querySelectorAll('input[type="radio"], input[type="datetime-local"]').forEach(input => {
            input.addEventListener('change', () => {
                if (!isPopulating) {
                    editFormIsDirty = true;
                }
            });
        });
    }

    function syncLikeButtonState(scope = document) {
        scope.querySelectorAll('.bit-like').forEach(button => {
            const postId = button.dataset.postId;
            if (!postId) {
                return;
            }

            const storageKey = 'bitstream-liked-' + postId;
            button.classList.toggle('liked', !!localStorage.getItem(storageKey));
        });
    }
    window.syncLikeButtonState = syncLikeButtonState;

    // --- html-to-image dynamic loader ---
    function loadHtmlToImage() {
        return new Promise((resolve, reject) => {
            if (_htmlToImageLoaded && window.htmlToImage) { resolve(); return; }
            const s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html-to-image/1.11.11/html-to-image.min.js';
            s.onload = () => { _htmlToImageLoaded = true; resolve(); };
            s.onerror = () => reject(new Error('Failed to load html-to-image'));
            document.head.appendChild(s);
        });
    }

    // --- Capture card as PNG blob ---
    async function captureBitCard(card) {
        await loadHtmlToImage();
        
        // Create a 500px-wide container to render the clone in the active viewport layout without visual shift
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'position: absolute; top: 0; left: 0; width: 500px; height: 0; overflow: hidden; z-index: -9999; pointer-events: none;';
        
        const clone = card.cloneNode(true);
        clone.classList.add('bit-card-capturing');
        clone.style.margin = '0';
        
        // Replace video elements in clone with images of their current frame to support image sharing
        const originalVideos = card.querySelectorAll('video');
        const clonedVideos = clone.querySelectorAll('video');
        originalVideos.forEach((video, index) => {
            const clonedVideo = clonedVideos[index];
            if (!clonedVideo) return;

            let frameDataUrl = '';
            const hasFrame = video.readyState >= 2 && video.videoWidth > 0;
            if (hasFrame) {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    frameDataUrl = canvas.toDataURL('image/png');
                } catch (err) {
                    console.warn('BitStream: Could not draw video frame to canvas (possibly CORS).', err);
                }
            }

            const img = document.createElement('img');
            img.style.cssText = 'display:block;width:100%;height:auto;border-radius:15px;';

            if (frameDataUrl) {
                img.src = frameDataUrl;
            } else if (video.poster) {
                img.src = video.poster;
            } else {
                const w = video.clientWidth || 640;
                const h = video.clientHeight || 360;
                img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
                    `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}"><rect width="100%" height="100%" fill="#1e293b" rx="15"/></svg>`
                );
            }

            const videoReplaceContainer = document.createElement('div');
            videoReplaceContainer.style.cssText = 'position:relative;width:100%;display:block;border-radius:15px;overflow:hidden;';

            const playOverlay = document.createElement('div');
            playOverlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;pointer-events:none;';

            const playCircle = document.createElement('div');
            playCircle.style.cssText = 'width:50px;height:50px;background:rgba(255,255,255,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.25);';
            playCircle.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="var(--wp--preset--color--accent-1,#2c6e49)"><path d="M8 5v14l11-7z"/></svg>`;

            playOverlay.appendChild(playCircle);
            videoReplaceContainer.appendChild(img);
            videoReplaceContainer.appendChild(playOverlay);

            const outerWrapper = clonedVideo.closest('.mejs-container') || clonedVideo.closest('.wp-video') || clonedVideo.parentNode;
            if (outerWrapper && outerWrapper !== clone && outerWrapper.parentNode) {
                outerWrapper.parentNode.replaceChild(videoReplaceContainer, outerWrapper);
            } else if (clonedVideo.parentNode) {
                clonedVideo.parentNode.replaceChild(videoReplaceContainer, clonedVideo);
            }
        });

        // Replace iframe elements in clone with static placeholders
        const clonedIframes = clone.querySelectorAll('iframe');
        clonedIframes.forEach(iframe => {
            const iframeReplaceContainer = document.createElement('div');
            iframeReplaceContainer.style.cssText = 'position:relative;width:100%;height:0;padding-bottom:56.25%;background:#1e293b;border-radius:15px;overflow:hidden;';

            const playOverlay = document.createElement('div');
            playOverlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;';

            const playCircle = document.createElement('div');
            playCircle.style.cssText = 'width:50px;height:50px;background:rgba(255,255,255,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.25);';
            playCircle.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="var(--wp--preset--color--accent-1,#2c6e49)"><path d="M8 5v14l11-7z"/></svg>`;

            playOverlay.appendChild(playCircle);
            iframeReplaceContainer.appendChild(playOverlay);

            const embedWrapper = iframe.closest('.wp-video') || iframe.parentNode;
            if (embedWrapper && embedWrapper !== clone && embedWrapper.parentNode) {
                embedWrapper.parentNode.replaceChild(iframeReplaceContainer, embedWrapper);
            } else if (iframe.parentNode) {
                iframe.parentNode.replaceChild(iframeReplaceContainer, iframe);
            }
        });

        wrapper.appendChild(clone);
        document.body.appendChild(wrapper);
        
        await new Promise(r => setTimeout(r, 60));
        let blob;
        try {
            let fontEmbedCSS = '';
            try {
                fontEmbedCSS = await window.htmlToImage.getFontEmbedCSS(clone);
            } catch (fontErr) {
                console.warn('BitStream: Could not pre-embed some fonts due to CORS/network security rules.', fontErr);
            }

            blob = await window.htmlToImage.toBlob(clone, {
                pixelRatio: 2,
                skipFonts: fontEmbedCSS ? false : true,
                backgroundColor: '#ffffff',
                fontEmbedCSS: fontEmbedCSS || undefined,
            });
        } finally {
            wrapper.remove();
        }
        return blob;
    }

    // --- Fallback download/copy modal for desktop ---
    function showShareImageModal(blob, title, url) {
        const existing = document.getElementById('bitstream-share-image-modal');
        if (existing) existing.remove();

        const imgUrl = URL.createObjectURL(blob);
        const modal = document.createElement('div');
        modal.id = 'bitstream-share-image-modal';
        modal.className = 'bitstream-composer-modal bitstream-composer-modal-share-image';

        modal.innerHTML = `
            <div class="bitstream-composer-modal-backdrop" data-composer-modal-close="share-image"></div>
            <div class="bitstream-composer-modal-dialog" role="dialog" aria-modal="true" aria-label="Share Image">
                <header class="bitstream-composer-modal-header">
                    <h3>Share Image</h3>
                    <button type="button" class="bitstream-composer-modal-close" data-composer-modal-close="share-image" aria-label="Close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="bitstream-composer-modal-body">
                    <div class="bitstream-share-image-preview">
                        <img src="${imgUrl}" alt="Post preview">
                    </div>
                </div>
                <footer class="bitstream-composer-modal-footer">
                    <button type="button" class="bitstream-composer-modal-cancel" data-composer-modal-close="share-image">Cancel</button>
                    <button type="button" id="bitstream-share-copy-link" class="bitstream-composer-modal-confirm" style="background:#64748b;box-shadow:none;">
                        <i class="fa-solid fa-link" style="margin-right:0.4rem;"></i>Copy Link
                    </button>
                    <button type="button" id="bitstream-share-download" class="bitstream-composer-modal-confirm">
                        <i class="fa-solid fa-download" style="margin-right:0.4rem;"></i>Download
                    </button>
                </footer>
            </div>`;

        document.body.appendChild(modal);
        requestAnimationFrame(() => modal.removeAttribute('hidden'));

        modal.querySelector('#bitstream-share-download').addEventListener('click', () => {
            const a = document.createElement('a');
            a.href = imgUrl;
            a.download = `bit-${Date.now()}.png`;
            a.click();
        });

        const copyBtn = modal.querySelector('#bitstream-share-copy-link');
        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(url).then(() => {
                copyBtn.innerHTML = '<i class="fa-solid fa-check" style="margin-right:0.4rem;"></i>Copied!';
                setTimeout(() => {
                    copyBtn.innerHTML = '<i class="fa-solid fa-link" style="margin-right:0.4rem;"></i>Copy Link';
                }, 1800);
            });
        });

        modal.querySelectorAll('[data-composer-modal-close="share-image"]').forEach(el => {
            el.addEventListener('click', () => {
                modal.setAttribute('hidden', '');
                setTimeout(() => { modal.remove(); URL.revokeObjectURL(imgUrl); }, 300);
            });
        });
    }
    window.showShareImageModal = showShareImageModal;

    // --- Share options modal (link vs image choice) ---
    function openShareOptionsModal(card, title, url, shareButton) {
        const existing = document.getElementById('bitstream-share-options-modal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = 'bitstream-share-options-modal';
        modal.className = 'bitstream-composer-modal bitstream-composer-modal-share-options';
        modal.innerHTML = `
            <div class="bitstream-composer-modal-backdrop" data-composer-modal-close="share-options"></div>
            <div class="bitstream-composer-modal-dialog" role="dialog" aria-modal="true" aria-label="Share">
                <header class="bitstream-composer-modal-header">
                    <h3>Share</h3>
                    <button type="button" class="bitstream-composer-modal-close" data-composer-modal-close="share-options" aria-label="Close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="bitstream-composer-modal-body">
                    <div class="bitstream-share-options-list">
                        <button type="button" class="bitstream-share-option-btn" id="bitstream-share-link-btn">
                            <i class="fa-solid fa-link"></i>
                            <div class="bitstream-share-option-details">
                                <span class="bitstream-share-option-title">Share Link</span>
                                <span class="bitstream-share-option-desc">Share or copy the post URL</span>
                            </div>
                        </button>
                        <button type="button" class="bitstream-share-option-btn" id="bitstream-share-image-btn">
                            <i class="fa-solid fa-image"></i>
                            <div class="bitstream-share-option-details">
                                <span class="bitstream-share-option-title">Share as Image</span>
                                <span class="bitstream-share-option-desc">Generate a card image for stories &amp; statuses</span>
                            </div>
                        </button>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(modal);
        requestAnimationFrame(() => modal.removeAttribute('hidden'));

        const closeModal = () => {
            modal.setAttribute('hidden', '');
            setTimeout(() => modal.remove(), 300);
        };

        modal.querySelectorAll('[data-composer-modal-close="share-options"]').forEach(el => {
            el.addEventListener('click', closeModal);
        });

        // Share Link
        modal.querySelector('#bitstream-share-link-btn').addEventListener('click', async () => {
            closeModal();
            if (navigator.share) {
                try { await navigator.share({ title, url }); } catch (_) { /* cancelled */ }
            } else {
                await navigator.clipboard.writeText(url);
                const shareBtn = card.querySelector('.bit-share i');
                if (shareBtn) {
                    const orig = shareBtn.className;
                    shareBtn.className = 'fa-solid fa-check';
                    setTimeout(() => { shareBtn.className = orig; }, 1500);
                }
            }
        });

        // Share as Image
        modal.querySelector('#bitstream-share-image-btn').addEventListener('click', async () => {
            const imgBtn = modal.querySelector('#bitstream-share-image-btn i');
            if (imgBtn) imgBtn.className = 'fa-solid fa-spinner fa-spin';

            const cachedUrl = shareButton.dataset.shareImage;
            let blob;

            if (cachedUrl) {
                try {
                    const resp = await fetch(cachedUrl);
                    if (resp.ok) {
                        blob = await resp.blob();
                    }
                } catch (_) { /* fall through */ }
            }

            if (!blob) {
                try {
                    blob = await captureBitCard(card);
                } catch (err) {
                    closeModal();
                    console.error('BitStream: card capture failed', err);
                    return;
                }

                if (window.bitstream_ajax && window.bitstream_ajax.ajax_url) {
                    const reader = new FileReader();
                    reader.onload = () => {
                        const fd = new FormData();
                        fd.append('action', 'bitstream_save_share_image');
                        fd.append('nonce', bitstream_ajax.save_share_image_nonce);
                        fd.append('post_id', shareButton.dataset.postId || '');
                        fd.append('image_data', reader.result);
                        fetch(bitstream_ajax.ajax_url, { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(json => {
                                if (json.success && json.data && json.data.url) {
                                    shareButton.dataset.shareImage = json.data.url;
                                }
                            })
                            .catch(() => {});
                    };
                    reader.readAsDataURL(blob);
                }
            }

            closeModal();

            try { await navigator.clipboard.writeText(url); } catch (_) { }

            const file = new File([blob], `bitstream-${Date.now()}.png`, { type: 'image/png' });

            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                try {
                    await navigator.share({ files: [file], title, url });
                    return;
                } catch (e) {
                    if (e.name === 'AbortError') return;
                }
            }

            showShareImageModal(blob, title, url);
        });
    }
    window.openShareOptionsModal = openShareOptionsModal;

    // Share and Quote buttons delegated handling
    document.addEventListener('click', (event) => {
        const shareButton = event.target.closest('.bit-share');
        if (shareButton) {
            event.preventDefault();
            const url = shareButton.dataset.url;
            const title = shareButton.dataset.title || '';
            const card = shareButton.closest('.bit-card');
            if (card) {
                openShareOptionsModal(card, title, url, shareButton);
            }
            return;
        }

        const quoteButton = event.target.closest('.bit-quote');
        if (quoteButton) {
            event.preventDefault();
            const postId = quoteButton.dataset.postId;
            if (typeof initTimelineEditModal === 'function') {
                initTimelineEditModal();
            }
            if (postId && typeof openTimelineQuoteModal === 'function' && openTimelineQuoteModal(postId)) {
                const icon = quoteButton.querySelector('i');
                if (icon) {
                    icon.classList.remove('pulse');
                    void icon.offsetWidth;
                    icon.classList.add('pulse');
                    setTimeout(() => icon.classList.remove('pulse'), 300);
                }
                return;
            }

            const basePosterUrl = (window.bitstream_ajax && bitstream_ajax.composer_url)
                ? bitstream_ajax.composer_url
                : (window.location.origin + '/bitstream/');
            const quoteUrl = new URL(basePosterUrl, window.location.origin);
            quoteUrl.searchParams.set('composer_tab', 'bit');
            quoteUrl.searchParams.set('quote_post_id', postId);

            window.location.href = quoteUrl.toString();

            const icon = quoteButton.querySelector('i');
            if (icon) {
                icon.classList.remove('pulse');
                void icon.offsetWidth;
                icon.classList.add('pulse');
                setTimeout(() => icon.classList.remove('pulse'), 300);
            }
        }
    });

    // Edit button functionality
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.bit-edit');
        if (!button) {
            return;
        }

        event.preventDefault();

        const postId = button.dataset.postId;
        const postType = (button.dataset.postType === 'rebit') ? 'rebit' : 'bit';

        if (typeof openTimelineEditModal === 'function' && openTimelineEditModal(postId, postType)) {
            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.remove('pulse');
                void icon.offsetWidth;
                icon.classList.add('pulse');
                setTimeout(() => icon.classList.remove('pulse'), 300);
            }
            return;
        }

        if (typeof initTimelineEditModal === 'function') {
            initTimelineEditModal();
            if (typeof openTimelineEditModal === 'function' && openTimelineEditModal(postId, postType)) {
                const icon = button.querySelector('i');
                if (icon) {
                    icon.classList.remove('pulse');
                    void icon.offsetWidth;
                    icon.classList.add('pulse');
                    setTimeout(() => icon.classList.remove('pulse'), 300);
                }
                return;
            }
        }

        const composer = document.querySelector('.bitstream-composer');
        if (composer && typeof composer.bitstreamLoadPostIntoComposer === 'function') {
            composer.bitstreamLoadPostIntoComposer(postId);
            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.remove('pulse');
                void icon.offsetWidth;
                icon.classList.add('pulse');
                setTimeout(() => icon.classList.remove('pulse'), 300);
            }
            return;
        }

        window.alert('The BitStream editor is unavailable on this page. Refresh and try again.');
    });

    // Delete button functionality (delegated for dynamically loaded cards)
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.bit-delete');
        if (!button) {
            return;
        }

        event.preventDefault();

        const postId = parseInt(button.dataset.postId || '0', 10);
        if (!postId) {
            return;
        }

        if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.delete_post_nonce) {
            alert('Delete endpoint is unavailable.');
            return;
        }

        showDeleteConfirmation('Are you sure you want to delete this Bit? This action cannot be undone.', () => {
            button.classList.add('is-active');

            const payload = new FormData();
            payload.append('action', 'bitstream_delete_post');
            payload.append('post_id', postId);
            payload.append('nonce', bitstream_ajax.delete_post_nonce);

            fetch(bitstream_ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.data || 'Could not delete post.');
                    }

                    const card = button.closest('.bit-card');
                    if (card) {
                        const lockedHeight = card.offsetHeight;
                        card.style.minHeight = lockedHeight + 'px';
                        card.classList.add('bit-card-delete-pending');
                        card.innerHTML = '<div class="bit-delete-toast">Deleted successfully</div>';

                        setTimeout(() => {
                            card.classList.add('bit-card-delete-fade');
                            setTimeout(() => {
                                card.remove();
                                window.location.reload();
                            }, 350);
                        }, 3000);
                    }
                })
                .catch(err => {
                    alert(err.message || 'Could not delete post.');
                    button.classList.remove('is-active');
                });
        });
    });

    function makeEmbedsResponsive() {
        document.querySelectorAll('iframe').forEach(iframe => {
            if (iframe.dataset.responsive === 'true') return;
            iframe.dataset.responsive = 'true';
            iframe.style.maxWidth = '100%';
            iframe.style.height = 'auto';

            if (iframe.src && (iframe.src.includes('youtube.com') || iframe.src.includes('youtu.be'))) {
                iframe.style.aspectRatio = '16/9';
                iframe.style.width = '100%';
            }

            let parent = iframe.parentElement;
            while (parent && parent !== document.body) {
                if (parent.classList.contains('wp-embedded-content') ||
                    parent.classList.contains('wp-block-embed') ||
                    parent.classList.contains('wp-embed') ||
                    parent.classList.contains('bit-content') ||
                    parent.classList.contains('bit-rebit-content')) {
                    parent.style.maxWidth = '100%';
                    parent.style.width = '100%';
                    parent.style.overflowX = 'hidden';
                }
                parent = parent.parentElement;
            }
        });

        document.querySelectorAll('.wp-embedded-content, .wp-block-embed, .wp-embed').forEach(container => {
            container.style.maxWidth = '100%';
            container.style.width = '100%';
            container.style.overflowX = 'hidden';
        });
    }

    function getSiteName() {
        const ogSiteName = document.querySelector('meta[property="og:site_name"]');
        return (ogSiteName && ogSiteName.content) ? ogSiteName.content : 'BitStream';
    }

    function getCardTextTitle(mediaEl) {
        const card = mediaEl.closest('.bit-card, .bitstream-media-preview, .bitstream-composer');
        if (!card) return '';

        const explicitTitle = card.querySelector('.bitstream-audio-title, .bitstream-rebit-preview-title');
        if (explicitTitle && explicitTitle.textContent) {
            return explicitTitle.textContent.trim();
        }

        const content = card.querySelector('.bit-card-content, textarea, p');
        if (!content || !content.textContent) return '';

        const text = content.textContent.trim().replace(/\s+/g, ' ');
        return text.length > 80 ? (text.slice(0, 77) + '...') : text;
    }

    function buildArtworkList(src) {
        if (!src) return [];
        const sizes = ['96x96', '128x128', '192x192', '256x256', '384x384', '512x512'];
        return sizes.map(size => ({ src, sizes: size, type: 'image/png' }));
    }

    function sanitizeVideoTitleFromBitContent(rawTitle, mediaEl) {
        if (!rawTitle) return '';

        let cleaned = rawTitle;
        const sourceValues = new Set();

        if (mediaEl.currentSrc) sourceValues.add(mediaEl.currentSrc);
        const directSrc = mediaEl.getAttribute('src');
        if (directSrc) sourceValues.add(directSrc);

        mediaEl.querySelectorAll('source[src]').forEach(sourceEl => {
            const sourceSrc = sourceEl.getAttribute('src');
            if (sourceSrc) sourceValues.add(sourceSrc);
        });

        sourceValues.forEach(src => {
            cleaned = cleaned.split(src).join(' ');
        });

        cleaned = cleaned.replace(/https?:\/\/\S+/gi, ' ').replace(/\s+/g, ' ').trim();
        return cleaned;
    }

    function resolveMediaSessionMeta(mediaEl) {
        const tagName = (mediaEl.tagName || '').toLowerCase();
        const siteName = getSiteName();

        if (tagName === 'audio') {
            const audioEmbed = mediaEl.closest('.bitstream-audio-embed');
            const titleEl = audioEmbed ? audioEmbed.querySelector('.bitstream-audio-title') : null;
            const artistEl = audioEmbed ? audioEmbed.querySelector('.bitstream-audio-artist') : null;
            const albumEl = audioEmbed ? audioEmbed.querySelector('.bitstream-audio-album') : null;
            const artworkEl = audioEmbed ? audioEmbed.querySelector('.bitstream-audio-artwork') : null;

            const title = (titleEl && titleEl.textContent && titleEl.textContent.trim())
                || mediaEl.getAttribute('title')
                || getCardTextTitle(mediaEl)
                || 'Audio';
            const artist = (artistEl && artistEl.textContent && artistEl.textContent.trim()) || siteName;
            const album = (albumEl && albumEl.textContent && albumEl.textContent.trim()) || 'BitStream';
            const artworkSrc = (artworkEl && artworkEl.src) || '';

            return {
                title,
                artist,
                album,
                artwork: buildArtworkList(artworkSrc),
            };
        }

        if (tagName === 'video') {
            const composerSrc = mediaEl.getAttribute('composer') || '';
            const fallbackImage = mediaEl.closest('.bit-card, .bitstream-media-preview, .bitstream-composer')
                ?.querySelector('img')
                ?.getAttribute('src') || '';
            const bitContentTitle = sanitizeVideoTitleFromBitContent(getCardTextTitle(mediaEl), mediaEl);

            const title = mediaEl.getAttribute('title') || bitContentTitle || 'Video';

            return {
                title,
                artist: siteName,
                album: 'BitStream',
                artwork: buildArtworkList(composerSrc || fallbackImage),
            };
        }

        return null;
    }

    function setMediaSessionMetadataFor(mediaEl) {
        if (!('mediaSession' in navigator) || typeof window.MediaMetadata !== 'function' || !mediaEl) {
            return;
        }

        const meta = resolveMediaSessionMeta(mediaEl);
        if (!meta) return;

        try {
            navigator.mediaSession.metadata = new window.MediaMetadata(meta);
            navigator.mediaSession.playbackState = mediaEl.paused ? 'paused' : 'playing';
        } catch (error) {
            console.warn('BitStream: MediaSession metadata update failed', error);
        }
    }

    function bindMediaSessionForElement(mediaEl) {
        if (!mediaEl || mediaEl.dataset.bitstreamMediaSessionBound === 'true') {
            return;
        }

        mediaEl.dataset.bitstreamMediaSessionBound = 'true';

        mediaEl.addEventListener('play', () => {
            activeMediaElement = mediaEl;
            setMediaSessionMetadataFor(mediaEl);
            if ('mediaSession' in navigator) {
                navigator.mediaSession.playbackState = 'playing';
            }
        });

        mediaEl.addEventListener('pause', () => {
            if (activeMediaElement === mediaEl && 'mediaSession' in navigator) {
                navigator.mediaSession.playbackState = 'paused';
            }
        });

        mediaEl.addEventListener('ended', () => {
            if (activeMediaElement === mediaEl && 'mediaSession' in navigator) {
                navigator.mediaSession.playbackState = 'none';
            }
        });

        mediaEl.addEventListener('loadedmetadata', () => {
            if (activeMediaElement === mediaEl) {
                setMediaSessionMetadataFor(mediaEl);
            }
        });
    }

    function adjustCardMediaDimensions(scope = document) {
        scope.querySelectorAll('.bit-card-content img:not(.emoji):not(.bitstream-gallery-media), .bit-rebit-preview img:not(.emoji):not(.bitstream-gallery-media), .bitstream-quoted-preview img:not(.emoji):not(.bitstream-gallery-media), .bitstream-composer-preview-media-thumb img:not(.emoji):not(.bitstream-gallery-media)').forEach(img => {
            const processImage = () => {
                const width = img.naturalWidth;
                const height = img.naturalHeight;
                if (width && height) {
                    if (height / width > 1.2) {
                        img.classList.add('bit-image-portrait');
                        img.classList.remove('bit-image-landscape');
                    } else {
                        img.classList.add('bit-image-landscape');
                        img.classList.remove('bit-image-portrait');
                    }
                }
            };

            if (img.complete) {
                processImage();
            } else {
                img.addEventListener('load', processImage);
            }
        });
    }

    function initMediaSession(scope = document) {
        if (!scope || !scope.querySelectorAll) return;

        if ('mediaSession' in navigator && !mediaSessionHandlersBound) {
            mediaSessionHandlersBound = true;
            try {
                navigator.mediaSession.setActionHandler('play', () => {
                    if (activeMediaElement && typeof activeMediaElement.play === 'function') {
                        activeMediaElement.play().catch(() => { });
                    }
                });
                navigator.mediaSession.setActionHandler('pause', () => {
                    if (activeMediaElement && typeof activeMediaElement.pause === 'function') {
                        activeMediaElement.pause();
                    }
                });
                navigator.mediaSession.setActionHandler('seekbackward', null);
                navigator.mediaSession.setActionHandler('seekforward', null);
            } catch (error) {
                console.warn('BitStream: MediaSession action handlers not fully supported', error);
            }
        }

        scope.querySelectorAll('audio, video').forEach(bindMediaSessionForElement);
    }

    function parseEmojis(container) {
        if (typeof twemoji === 'undefined' || !container) return;
        if (container.hasAttribute && (container.hasAttribute('contenteditable') || container.getAttribute('role') === 'textbox')) {
            return;
        }

        const editables = Array.from(container.querySelectorAll('[contenteditable], [role="textbox"]'));
        if (editables.length > 0) {
            Array.from(container.children).forEach(child => {
                if (!child.hasAttribute('contenteditable') && child.getAttribute('role') !== 'textbox' && !child.querySelector('[contenteditable]')) {
                    parseEmojis(child);
                } else if (!child.hasAttribute('contenteditable') && child.getAttribute('role') !== 'textbox') {
                    Array.from(child.children).forEach(subChild => {
                        if (!subChild.hasAttribute('contenteditable') && subChild.getAttribute('role') !== 'textbox') {
                            parseEmojis(subChild);
                        }
                    });
                }
            });
            return;
        }

        twemoji.parse(container, {
            folder: 'svg',
            ext: '.svg',
            base: 'https://cdn.jsdelivr.net/gh/jdecked/twemoji@latest/assets/',
            callback: (icon, options) => {
                const cleanIcon = icon.replace(/-fe0f/g, '');
                return ''.concat(options.base, options.size, '/', cleanIcon, options.ext);
            }
        });
        container.querySelectorAll('img.emoji').forEach(img => {
            if (img.dataset.hasErrorListener) return;
            img.dataset.hasErrorListener = 'true';
            img.addEventListener('error', () => {
                const alt = img.getAttribute('alt');
                if (alt) {
                    const span = document.createElement('span');
                    span.className = 'bitstream-native-emoji';
                    span.textContent = alt;
                    img.replaceWith(span);
                }
            });
        });
    }
    window.parseEmojis = parseEmojis;

    function parseTimelineCards(container) {
        const root = container || document;
        const targets = Array.from(root.querySelectorAll('.bit-card:not([data-emoji-parsed])'));
        if (root !== document && root.matches && root.matches('.bit-card:not([data-emoji-parsed])')) {
            targets.push(root);
        }
        targets.forEach(card => {
            card.setAttribute('data-emoji-parsed', 'true');
            parseEmojis(card);
        });
    }
    window.parseTimelineCards = parseTimelineCards;

    function initCommentToggles() {
        document.querySelectorAll('.bit-comment-toggle').forEach(button => {
            if (button.dataset.initialized === 'true') return;
            button.dataset.initialized = 'true';
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = button.dataset.target;
                const section = document.getElementById(targetId);
                if (section) {
                    const bitCard = section.closest('.bit-card');
                    if (bitCard) {
                        if (!section.classList.contains('open')) {
                            bitCard.classList.add('comments-open');
                        }
                    }

                    section.classList.toggle('open');

                    if (bitCard) {
                        if (!section.classList.contains('open')) {
                            setTimeout(() => {
                                bitCard.classList.remove('comments-open');
                            }, 450);
                        }
                    }
                }
            });
        });
    }

    const archiveYears = document.querySelectorAll('.bitstream-archive-year');

    function syncArchiveYearState() {
        if (!archiveYears.length) return;
        const isDesktop = window.innerWidth >= 1024;

        archiveYears.forEach(year => {
            if (year.dataset.userToggled === 'true') return;
            if (isDesktop) {
                year.open = year.dataset.defaultOpen === '1';
            } else {
                year.open = false;
            }
        });
    }

    function loadNextPage() {
        const feed = document.querySelector('.bitstream-feed');
        if (!feed) return;
        const nextPage = parseInt(feed.dataset.page) + 1;
        const maxPage = parseInt(feed.dataset.maxPage);
        const loadMoreButton = document.getElementById('bitstream-load-more');
        const scrollTrigger = document.querySelector('.bitstream-scroll-trigger');

        if (loading || nextPage > maxPage) return;
        loading = true;

        if (loadMoreButton) {
            loadMoreButton.textContent = 'Loading…';
        }

        const formData = new FormData();
        formData.append('action', 'bitstream_load_more');
        formData.append('page', nextPage);
        formData.append('nonce', bitstream_ajax.load_more_nonce);
        formData.append('filter_type', feed.dataset.filterType || 'all');
        formData.append('filter_month', feed.dataset.filterMonth || '');
        formData.append('filter_search', feed.dataset.filterSearch || '');
        formData.append('filter_hashtag', feed.dataset.filterHashtag || '');
        formData.append('filter_emotion', feed.dataset.filterEmotion || '');
        formData.append('highlight_bit', feed.dataset.highlightBit || '0');

        fetch(bitstream_ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(response => response.text())
            .then(html => {
                const temp = document.createElement('div');
                temp.innerHTML = html;
                const newCards = temp.querySelectorAll(':scope > .bit-card');

                newCards.forEach(card => {
                    feed.appendChild(card);
                });

                syncLikeButtonState(feed);

                feed.dataset.page = nextPage;
                loading = false;

                initCommentToggles();
                parseTimelineCards();

                if (loadMoreButton) {
                    loadMoreButton.textContent = 'Load More';
                    if (nextPage >= maxPage) {
                        loadMoreButton.style.display = 'none';
                    }
                }

                if (scrollTrigger && nextPage >= maxPage) {
                    scrollTrigger.style.display = 'none';
                }
            })
            .catch(() => {
                loading = false;
                if (loadMoreButton) {
                    loadMoreButton.textContent = 'Load More';
                }
            });
    }

    function renderComposerMediaPreview(previewEl, attachment) {
        if (!previewEl) return;

        const dropzone = previewEl.closest('.bitstream-media-dropzone');
        if (dropzone) {
            dropzone.classList.toggle('has-media', !!attachment);
        }

        if (!attachment) {
            previewEl.innerHTML = '';
            previewEl.removeAttribute('data-attachment-id');
            previewEl.removeAttribute('data-attachment-url');
            previewEl.removeAttribute('data-attachment-mime');
            return;
        }

        const mimeType = attachment.mime || '';
        const previewUrl = attachment.preview_url || attachment.url || '';

        if (attachment.id) previewEl.dataset.attachmentId = attachment.id;
        if (previewUrl) previewEl.dataset.attachmentUrl = previewUrl;
        if (mimeType) previewEl.dataset.attachmentMime = mimeType;

        if (mimeType.startsWith('image/')) {
            previewEl.innerHTML = '<img src="' + previewUrl + '" alt="">';
            return;
        }

        if (mimeType.startsWith('video/')) {
            previewEl.innerHTML = '<video src="' + previewUrl + '" controls controlsList="nodownload noplaybackrate" disablepictureinpicture></video>';
            return;
        }

        previewEl.innerHTML = '<p>Selected: ' + (attachment.filename || attachment.title || 'media') + '</p>';
    }
    window.renderComposerMediaPreview = renderComposerMediaPreview;

    function applyCommentStyles() {
        const styles = {
            authorName: 'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-right: 0.25em !important; color: #2c6e49 !important;',
            says: 'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-left: 0.3em !important; color: #2c6e49 !important;',
            dateLinks: 'color: #044389 !important; font-style: normal !important; font-size: 0.8em !important; text-decoration: underline !important; pointer-events: auto !important; cursor: pointer !important;'
        };

        jQuery('.bit-comments-list .comment-author .fn').attr('style', styles.authorName);

        jQuery('.bit-comments-list .comment-author').each(function () {
            var $author = jQuery(this);
            if ($author.find('.says').length === 0) {
                $author.append('<span class="says">says:</span>');
            }
            $author.find('.says').attr('style', styles.says);
        });

        jQuery('.bit-comments-list .comment-metadata a, .bit-comments-list .edit-link a, .bit-comments-list .comment-edit-link').attr('style', styles.dateLinks);

        jQuery('.bit-comments-list .comment.depth-2, .bit-comments-list .comment.depth-3, .bit-comments-list .comment.depth-4').css({
            'margin-left': '2em',
            'border-left': '2px solid #2c6e49',
            'padding-left': '1em',
            'background': '#fafafa'
        });

        jQuery('.bit-comments-list .comment.depth-2 > .comment-body, .bit-comments-list .comment.depth-3 > .comment-body, .bit-comments-list .comment.depth-4 > .comment-body').css({
            'border-top': 'none',
            'margin-top': '0',
            'padding-top': '0'
        });
    }

    function bindTimelineEvents() {
        const feed = document.querySelector('.bitstream-feed');
        if (!feed) return;

        const isInfiniteScroll = feed.dataset.infiniteScroll === 'true';
        const scrollTrigger = document.querySelector('.bitstream-scroll-trigger');
        const loadMoreButton = document.getElementById('bitstream-load-more');

        // Infinite scroll observer
        if (isInfiniteScroll && scrollTrigger) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !loading) {
                        loadNextPage();
                    }
                });
            }, {
                rootMargin: '100px'
            });

            observer.observe(scrollTrigger);
        }

        // Load more click
        if (loadMoreButton) {
            loadMoreButton.addEventListener('click', loadNextPage);
        }

        // Delegate timeline card events (likes, timestamp expansions, etc.)
        jQuery(document).ready(function ($) {
            applyCommentStyles();

            document.addEventListener('click', (e) => {
                const timestampEl = e.target.closest('.bit-timestamp');
                if (timestampEl) {
                    e.preventDefault();
                    const fullSpan = timestampEl.querySelector('.bit-timestamp-full');
                    if (fullSpan) {
                        fullSpan.style.display = fullSpan.style.display === 'none' ? 'inline' : 'none';
                    }
                }
            });

            document.querySelectorAll('.bitstream-filter-search').forEach(form => {
                form.addEventListener('submit', (e) => {
                    const input = form.querySelector('input[name="bitstream_search"]');
                    if (!input) return;
                    const value = input.value.trim();
                    if (value.startsWith('#') && value.length > 1) {
                        e.preventDefault();
                        const tag = value.substring(1);
                        const url = new URL(form.action || window.location.href);
                        url.searchParams.delete('bitstream_search');
                        url.searchParams.set('bitstream_hashtag', tag);
                        window.location.href = url.toString();
                    }
                });
            });
        });

        // Click likes
        document.addEventListener('click', (e) => {
            const button = e.target.closest('.bit-like');
            if (!button) return;

            e.preventDefault();
            const postId = button.dataset.postId;
            if (!postId) return;

            if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.like_nonce) {
                console.error('BitStream: Unable to process like request — missing AJAX configuration.');
                return;
            }

            const storageKey = 'bitstream-liked-' + postId;
            const isCurrentlyLiked = button.classList.contains('liked') || !!localStorage.getItem(storageKey);
            const span = button.querySelector('.bit-like-count') || button.querySelector('span');

            button.disabled = true;

            const fd = new FormData();
            fd.append('action', 'bitstream_like');
            fd.append('nonce', bitstream_ajax.like_nonce);
            fd.append('post_id', postId);
            fd.append('type', isCurrentlyLiked ? 'unlike' : 'like');

            fetch(bitstream_ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const newLikedState = !isCurrentlyLiked;
                        if (newLikedState) {
                            localStorage.setItem(storageKey, '1');
                        } else {
                            localStorage.removeItem(storageKey);
                        }
                        button.classList.toggle('liked', newLikedState);
                        if (span && data.data && typeof data.data.likes !== 'undefined') {
                            span.textContent = data.data.likes;
                        }

                        const icon = button.querySelector('i');
                        if (icon) {
                            icon.classList.remove('pulse');
                            void icon.offsetWidth;
                            icon.classList.add('pulse');
                            setTimeout(() => icon.classList.remove('pulse'), 300);
                        }
                    } else {
                        console.error('BitStream like error:', data.data || 'Failed to update like status.');
                    }
                })
                .catch(err => console.error('BitStream like network error:', err))
                .finally(() => {
                    button.disabled = false;
                });
        });
    }

    function initPushNotifications() {
        const subscribeButtons = document.querySelectorAll('.bitstream-push-subscribe-btn');
        const widgetContainers = document.querySelectorAll('.bitstream-push-widget-container');
        const unsupportedDiv = document.getElementById('bitstream-push-device-unsupported');

        if (subscribeButtons.length > 0) {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                if (unsupportedDiv) unsupportedDiv.style.display = 'block';
                subscribeButtons.forEach(btn => { btn.style.display = 'none'; });
                widgetContainers.forEach(container => { container.style.display = 'none'; });
            } else {
                widgetContainers.forEach(container => { container.style.display = 'block'; });

                if (Notification.permission === 'denied') {
                    subscribeButtons.forEach(btn => {
                        updateSubscriptionButton(btn, false, true);
                        btn.disabled = false;
                    });
                }

                let getRegistrationPromise;
                if (navigator.serviceWorker.controller) {
                    getRegistrationPromise = navigator.serviceWorker.getRegistration();
                } else {
                    getRegistrationPromise = navigator.serviceWorker.getRegistration('/bitstream/');
                }

                getRegistrationPromise.then(function (registration) {
                    if (!registration) {
                        const baseHomeUrl = bitstream_ajax.feed_url.replace(/\/bitstream\/?$/, '/');
                        const swUrl = baseHomeUrl + '?bitstream_sw=main';

                        return navigator.serviceWorker.register(swUrl, {
                            scope: '/bitstream/',
                            updateViaCache: 'none'
                        }).then(function (newReg) {
                            return newReg;
                        }).catch(function (err) {
                            return null;
                        });
                    }
                    return registration;
                }).then(function (registration) {
                    if (!registration) {
                        subscribeButtons.forEach(btn => {
                            btn.disabled = false;
                            if (btn.classList.contains('bitstream-filter-link')) {
                                const span = btn.querySelector('span');
                                if (span) span.textContent = 'SW Active Check Failed';
                            } else {
                                btn.textContent = 'Service Worker inactive. Visit homepage feed once.';
                                btn.style.background = '#666';
                            }
                        });
                        return;
                    }

                    registration.pushManager.getSubscription().then(function (subscription) {
                        let isSubscribed = !(subscription === null);
                        subscribeButtons.forEach(btn => {
                            updateSubscriptionButton(btn, isSubscribed);
                            btn.disabled = false;
                        });

                        subscribeButtons.forEach(btn => {
                            btn.addEventListener('click', function (e) {
                                if (btn.tagName.toLowerCase() === 'a') {
                                    e.preventDefault();
                                }

                                subscribeButtons.forEach(b => { b.disabled = true; });

                                if (isSubscribed) {
                                    subscription.unsubscribe().then(function (successful) {
                                        if (successful) {
                                            sendSubscriptionToServer(subscription, 'unsubscribe', function (res) {
                                                if (res.success) {
                                                    isSubscribed = false;
                                                    subscribeButtons.forEach(b => {
                                                        updateSubscriptionButton(b, false);
                                                        b.disabled = false;
                                                    });
                                                } else {
                                                    alert('Failed to remove subscription from server.');
                                                    subscribeButtons.forEach(b => { b.disabled = false; });
                                                }
                                            });
                                        } else {
                                            alert('Failed to unsubscribe from device.');
                                            subscribeButtons.forEach(b => { b.disabled = false; });
                                        }
                                    }).catch(function (err) {
                                        console.error('Error unsubscribing:', err);
                                        subscribeButtons.forEach(b => { b.disabled = false; });
                                    });
                                } else {
                                    if (Notification.permission === 'denied') {
                                        subscribeButtons.forEach(b => {
                                            updateSubscriptionButton(b, false, true);
                                            b.disabled = false;
                                        });
                                        return;
                                    }

                                    const doSubscribe = function () {
                                        const vapidKeyB64 = btn.getAttribute('data-vapid-public');
                                        if (!vapidKeyB64) {
                                            alert('VAPID public key not configured.');
                                            subscribeButtons.forEach(b => { b.disabled = false; });
                                            return;
                                        }

                                        try {
                                            const applicationServerKey = urlBase64ToUint8Array(vapidKeyB64);
                                            registration.pushManager.subscribe({
                                                userVisibleOnly: true,
                                                applicationServerKey: applicationServerKey
                                            }).then(function (newSubscription) {
                                                sendSubscriptionToServer(newSubscription, 'subscribe', function (res) {
                                                    if (res.success) {
                                                        isSubscribed = true;
                                                        subscription = newSubscription;
                                                        subscribeButtons.forEach(b => {
                                                            updateSubscriptionButton(b, true);
                                                            b.disabled = false;
                                                        });
                                                    } else {
                                                        alert('Subscription registration failed on server.');
                                                        newSubscription.unsubscribe();
                                                        subscribeButtons.forEach(b => { b.disabled = false; });
                                                    }
                                                });
                                            }).catch(function (err) {
                                                console.error('Failed to subscribe:', err);
                                                if (err.name === 'NotAllowedError') {
                                                    subscribeButtons.forEach(b => {
                                                        updateSubscriptionButton(b, false, true);
                                                        b.disabled = false;
                                                    });
                                                } else {
                                                    alert('Subscription failed: ' + err.message);
                                                    subscribeButtons.forEach(b => { b.disabled = false; });
                                                }
                                            });
                                        } catch (e) {
                                            console.error('VAPID key parsing error:', e);
                                            subscribeButtons.forEach(b => { b.disabled = false; });
                                        }
                                    };

                                    if (Notification.permission === 'granted') {
                                        doSubscribe();
                                    } else {
                                        Notification.requestPermission().then(function (result) {
                                            if (result === 'granted') {
                                                doSubscribe();
                                            } else {
                                                subscribeButtons.forEach(b => {
                                                    updateSubscriptionButton(b, false, result === 'denied');
                                                    b.disabled = false;
                                                });
                                            }
                                        });
                                    }
                                }
                            });
                        });
                    }).catch(function (err) {
                        console.error('Error getting subscription:', err);
                    });
                });
            }
        }
    }

    function updateSubscriptionButton(btn, isSubscribed, isBlocked) {
        const span = btn.querySelector('span');
        const icon = btn.querySelector('i');

        if (isBlocked) {
            if (btn.classList.contains('bitstream-filter-link')) {
                if (span) span.textContent = 'Notifications Blocked';
                if (icon) icon.className = 'fa-solid fa-bell-slash';
                btn.style.background = '';
                btn.title = 'Notifications are blocked. Go to your browser\'s site settings to re-enable them.';
            } else {
                btn.textContent = 'Notifications Blocked — Enable in Browser Settings';
                btn.style.background = '#e67e22';
                btn.title = 'Tap to learn how to re-enable notifications for this site in your browser settings.';
            }
            return;
        }

        if (btn.classList.contains('bitstream-filter-link')) {
            if (span) span.textContent = isSubscribed ? 'Mute Notifications' : 'Get Notifications';
            if (icon) icon.className = isSubscribed ? 'fa-solid fa-bell-slash' : 'fa-solid fa-bell';
            btn.style.background = '';
            btn.title = '';
        } else {
            if (isSubscribed) {
                btn.textContent = 'Unsubscribe this Device';
                btn.style.background = '#dc3545';
            } else {
                btn.textContent = 'Subscribe this Device';
                btn.style.background = 'var(--wp--preset--color--accent-1, #2c6e49)';
            }
            btn.title = '';
        }
    }

    function sendSubscriptionToServer(subscription, action, callback) {
        const payload = subscription.toJSON();
        payload.action = action;

        fetch(bitstream_ajax.ajax_url + '?action=bitstream_save_push_subscription', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(response => response.json())
            .then(data => callback(data))
            .catch(error => {
                console.error('Error saving subscription:', error);
                callback({ success: false });
            });
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function initBottomNavAndSheets() {
        const navHome    = document.getElementById('bs-nav-home');
        const navSearch  = document.getElementById('bs-nav-search');
        const navCompose = document.getElementById('bs-nav-compose');
        const navDrafts  = document.getElementById('bs-nav-drafts');
        const navMore    = document.getElementById('bs-nav-more');

        const searchScreen  = document.getElementById('bs-search-screen');
        const searchClose   = document.getElementById('bs-search-screen-close');

        const moreSheet     = document.getElementById('bs-more-sheet');
        const moreBackdrop  = document.getElementById('bs-more-backdrop');
        const moreClose     = document.getElementById('bs-more-sheet-close');

        function closeSearch() {
            if (searchScreen) searchScreen.hidden = true;
            syncLikeButtonState(document);
            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
        }

        function openSearch() {
            closeMore();
            if (searchScreen) searchScreen.hidden = false;
            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
        }

        if (searchScreen) {
            const urlParams = new URLSearchParams(window.location.search);
            const activeSearch = urlParams.get('bitstream_search');
            if (activeSearch) {
                const searchInput = searchScreen.querySelector('input[type="search"]');
                if (searchInput) searchInput.value = activeSearch;
            }
        }

        function closeMore() {
            if (moreSheet)    moreSheet.hidden    = true;
            if (moreBackdrop) moreBackdrop.hidden = true;
            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
        }

        window.bitstreamCloseMore = closeMore;
        window.bitstreamCloseSearch = closeSearch;

        function openMore() {
            closeSearch();
            if (moreSheet)    moreSheet.hidden    = false;
            if (moreBackdrop) moreBackdrop.hidden = false;
            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
        }

        if (navHome) {
            const feedUrl = navHome.dataset.feedUrl;
            const isOnFeed = feedUrl && window.location.pathname === new URL(feedUrl, window.location.origin).pathname;

            navHome.addEventListener('click', (e) => {
                const composerEl = document.querySelector('.bitstream-composer');
                if (composerEl && !composerEl.hidden) {
                    const content = composerEl.querySelector('#bitstream-quick-bit-content');
                    const hasRebit = composerEl.querySelector('#bitstream-composer-rebit-url');
                    const hasMedia = composerEl.querySelector('#bitstream-composer-attachment-id');
                    const hasMood = composerEl.querySelector('#bitstream-composer-mood-emotion');
                    const hasQuote = composerEl.querySelector('#bitstream-composer-quote-post-id');
                    
                    const contentVal = content ? content.value.trim() : '';
                    const rebitVal = hasRebit ? hasRebit.value.trim() : '';
                    const mediaVal = hasMedia && parseInt(hasMedia.value || '0', 10) > 0;
                    const moodVal = hasMood ? hasMood.value.trim() : '';
                    const quoteVal = hasQuote && parseInt(hasQuote.value || '0', 10) > 0;
                    
                    if (contentVal || rebitVal || mediaVal || moodVal || quoteVal) {
                        e.preventDefault();
                        showDiscardConfirmation('Are you sure you want to discard your draft?', () => {
                            composerEl.hidden = true;
                            composerEl.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                            const clearFn = window.bitstreamClearComposer;
                            if (clearFn) clearFn();
                            if (isOnFeed) {
                                executeHomeClick();
                            } else if (feedUrl) {
                                window.location.href = feedUrl;
                            }
                        }, () => {
                            const saveBtn = composerEl.querySelector('.bitstream-composer-save-draft-action');
                            if (saveBtn) saveBtn.click();
                        });
                        return;
                    } else {
                        composerEl.hidden = true;
                        composerEl.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                    }
                }

                e.preventDefault();
                if (isOnFeed) {
                    executeHomeClick();
                } else if (feedUrl) {
                    window.location.href = feedUrl;
                }

                function executeHomeClick() {
                    closeSearch();
                    closeMore();

                    if (isOnFeed && feedUrl) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        window.history.pushState({}, document.title, feedUrl);
                        
                        fetch(feedUrl)
                            .then(response => response.text())
                            .then(htmlText => {
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(htmlText, 'text/html');
                                const newFeed = doc.querySelector('.bitstream-feed');
                                const currentFeed = document.querySelector('.bitstream-feed');
                                
                                if (newFeed && currentFeed) {
                                    currentFeed.innerHTML = newFeed.innerHTML;
                                    currentFeed.dataset.page = newFeed.dataset.page || '1';
                                    currentFeed.dataset.maxPage = newFeed.dataset.maxPage || '1';
                                    currentFeed.dataset.filterType = 'all';
                                    currentFeed.dataset.filterMonth = '';
                                    currentFeed.dataset.filterSearch = '';
                                    currentFeed.dataset.filterHashtag = '';
                                    currentFeed.dataset.filterEmotion = '';
                                    currentFeed.removeAttribute('data-highlight-bit');
                                    
                                    syncLikeButtonState(currentFeed);
                                    initCommentToggles();
                 parseTimelineCards();
                                }
                                
                                const newLoadMore = doc.getElementById('bitstream-load-more');
                                const currentLoadMore = document.getElementById('bitstream-load-more');
                                if (currentLoadMore) {
                                    currentLoadMore.style.display = newLoadMore ? '' : 'none';
                                    if (newLoadMore) currentLoadMore.textContent = 'Load More';
                                }
                                
                                const currentScrollTrigger = document.querySelector('.bitstream-scroll-trigger');
                                const newScrollTrigger = doc.querySelector('.bitstream-scroll-trigger');
                                if (currentScrollTrigger) {
                                    currentScrollTrigger.style.display = newScrollTrigger ? '' : 'none';
                                }
                                
                                const searchInputs = document.querySelectorAll('form.bitstream-filter-search input[name="bitstream_search"]');
                                searchInputs.forEach(input => input.value = '');
                                
                                const filterLinks = document.querySelectorAll('.bitstream-filter-link');
                                filterLinks.forEach(link => {
                                    const text = link.textContent.trim().toLowerCase();
                                    const isAll = text === 'all' || text === 'all dates';
                                    link.classList.toggle('is-active', isAll);
                                });
                                
                                if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
                            });
                    } else if (feedUrl) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        setTimeout(() => { window.location.href = feedUrl; }, 300);
                    } else {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
                    }
                }
            });
        }

        if (navSearch) {
            navSearch.addEventListener('click', (e) => {
                const composerEl = document.querySelector('.bitstream-composer');
                if (composerEl && !composerEl.hidden) {
                    const content = composerEl.querySelector('#bitstream-quick-bit-content');
                    const hasRebit = composerEl.querySelector('#bitstream-composer-rebit-url');
                    const hasMedia = composerEl.querySelector('#bitstream-composer-attachment-id');
                    const hasMood = composerEl.querySelector('#bitstream-composer-mood-emotion');
                    const hasQuote = composerEl.querySelector('#bitstream-composer-quote-post-id');
                    
                    const contentVal = content ? content.value.trim() : '';
                    const rebitVal = hasRebit ? hasRebit.value.trim() : '';
                    const mediaVal = hasMedia && parseInt(hasMedia.value || '0', 10) > 0;
                    const moodVal = hasMood ? hasMood.value.trim() : '';
                    const quoteVal = hasQuote && parseInt(hasQuote.value || '0', 10) > 0;
                    
                    if (contentVal || rebitVal || mediaVal || moodVal || quoteVal) {
                        e.preventDefault();
                        showDiscardConfirmation('Are you sure you want to discard your draft?', () => {
                            composerEl.hidden = true;
                            composerEl.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                            const clearFn = window.bitstreamClearComposer;
                            if (clearFn) clearFn();
                            toggleSearch();
                        }, () => {
                            const saveBtn = composerEl.querySelector('.bitstream-composer-save-draft-action');
                            if (saveBtn) saveBtn.click();
                        });
                        return;
                    } else {
                        composerEl.hidden = true;
                        composerEl.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                    }
                }
                toggleSearch();
            });
        }

        function toggleSearch() {
            if (searchScreen && !searchScreen.hidden) {
                closeSearch();
            } else {
                openSearch();
            }
        }

        if (searchClose) searchClose.addEventListener('click', closeSearch);

        if (navCompose) {
            navCompose.addEventListener('click', (e) => {
                e.preventDefault();
                const composerEl = document.querySelector('.bitstream-composer');
                if (composerEl) {
                    if (!composerEl.hidden) {
                        if (typeof window.bitstreamCloseModal === 'function') {
                            window.bitstreamCloseModal('composer');
                        } else {
                            composerEl.hidden = true;
                            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
                        }
                    } else {
                        closeSearch();
                        closeMore();
                        composerEl.hidden = false;
                        if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
                        const textarea = composerEl.querySelector('#bitstream-quick-bit-content');
                        if (textarea) textarea.focus();
                    }
                } else {
                    const composerBaseUrl = (window.bitstream_ajax && bitstream_ajax.composer_url) ? bitstream_ajax.composer_url : window.location.href;
                    const url = new URL(composerBaseUrl, window.location.origin);
                    url.searchParams.set('composer_tab', 'bit');
                    window.location.href = url.toString();
                }
            });
        }

        if (navDrafts) {
            navDrafts.addEventListener('click', (e) => {
                const composerEl = document.querySelector('.bitstream-composer');
                if (composerEl) {
                    const draftsModal = composerEl.querySelector('.bitstream-composer-modal-drafts');
                    if (draftsModal && !draftsModal.hidden) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (typeof window.bitstreamCloseModal === 'function') {
                            window.bitstreamCloseModal('drafts');
                        } else {
                            draftsModal.hidden = true;
                            composerEl.hidden = true;
                            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
                        }
                    }
                }
            });
        }

        if (navMore) {
            navMore.addEventListener('click', (e) => {
                if (moreSheet && !moreSheet.hidden) {
                    closeMore();
                    return;
                }

                const composerEl = document.querySelector('.bitstream-composer');
                if (composerEl && !composerEl.hidden) {
                    const content = composerEl.querySelector('#bitstream-quick-bit-content');
                    const hasRebit = composerEl.querySelector('#bitstream-composer-rebit-url');
                    const hasMedia = composerEl.querySelector('#bitstream-composer-attachment-id');
                    const hasMood = composerEl.querySelector('#bitstream-composer-mood-emotion');
                    const hasQuote = composerEl.querySelector('#bitstream-composer-quote-post-id');
                    
                    const contentVal = content ? content.value.trim() : '';
                    const rebitVal = hasRebit ? hasRebit.value.trim() : '';
                    const mediaVal = hasMedia && parseInt(hasMedia.value || '0', 10) > 0;
                    const moodVal = hasMood ? hasMood.value.trim() : '';
                    const quoteVal = hasQuote && parseInt(hasQuote.value || '0', 10) > 0;
                    
                    if (contentVal || rebitVal || mediaVal || moodVal || quoteVal) {
                        e.preventDefault();
                        showDiscardConfirmation('Are you sure you want to discard your draft?', () => {
                            composerEl.hidden = true;
                            composerEl.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                            const clearFn = window.bitstreamClearComposer;
                            if (clearFn) clearFn();
                            openMore();
                        }, () => {
                            const saveBtn = composerEl.querySelector('.bitstream-composer-save-draft-action');
                            if (saveBtn) saveBtn.click();
                        });
                        return;
                    } else {
                        composerEl.hidden = true;
                        composerEl.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                    }
                }

                openMore();
            });
        }

        if (moreClose) moreClose.addEventListener('click', closeMore);
        if (moreBackdrop) {
            moreBackdrop.addEventListener('click', closeMore);
            moreBackdrop.addEventListener('touchstart', (e) => { e.preventDefault(); closeMore(); }, { passive: false });
            moreBackdrop.addEventListener('touchmove', (e) => { e.preventDefault(); closeMore(); }, { passive: false });
            moreBackdrop.addEventListener('wheel', (e) => { e.preventDefault(); closeMore(); }, { passive: false });
        }

        // Standing About Modal
        const aboutTrigger = document.getElementById('bs-more-about-trigger');
        const aboutModal   = document.getElementById('bs-about-modal');

        function openAboutModal() {
            closeMore();
            if (aboutModal) aboutModal.hidden = false;
            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
        }

        function closeAboutModal() {
            if (aboutModal) aboutModal.hidden = true;
            if (typeof window.bitstreamSyncBottomNav === 'function') window.bitstreamSyncBottomNav();
        }

        if (aboutTrigger) {
            aboutTrigger.addEventListener('click', (e) => {
                e.preventDefault();
                openAboutModal();
            });
        }

        const aboutCloseBtn = document.getElementById('bs-about-modal-close-btn');
        const aboutCancelBtn = document.getElementById('bs-about-modal-cancel-btn');
        const aboutBackdrop = document.getElementById('bs-about-modal-close-backdrop');

        if (aboutCloseBtn)  aboutCloseBtn.addEventListener('click', closeAboutModal);
        if (aboutCancelBtn) aboutCancelBtn.addEventListener('click', closeAboutModal);
        if (aboutBackdrop)  aboutBackdrop.addEventListener('click', closeAboutModal);
    }

    function getHashtags() {
        const allTags = [];
        if (window.bitstream_ajax && bitstream_ajax.hashtags) {
            for (const [tag, count] of Object.entries(bitstream_ajax.hashtags)) {
                allTags.push({ tag, count: parseInt(count, 10) });
            }
        }
        allTags.sort((a, b) => b.count - a.count);
        return allTags;
    }

    function initImageDownloadProtection() {
        document.addEventListener('contextmenu', function (e) {
            const target = e.target;
            if (target && target.tagName === 'IMG') {
                if (target.closest('.bitstream-lightbox') || 
                    target.closest('.bit-card-content') || 
                    target.closest('.bitstream-gallery') ||
                    target.closest('.bitstream-quoted-preview') ||
                    target.closest('.bit-rebit-preview') ||
                    target.closest('.bitstream-media-preview-item')) {
                    e.preventDefault();
                }
            }
        });

        document.addEventListener('dragstart', function (e) {
            const target = e.target;
            if (target && target.tagName === 'IMG') {
                if (target.closest('.bitstream-lightbox') || 
                    target.closest('.bit-card-content') || 
                    target.closest('.bitstream-gallery') ||
                    target.closest('.bitstream-quoted-preview') ||
                    target.closest('.bit-rebit-preview') ||
                    target.closest('.bitstream-media-preview-item')) {
                    e.preventDefault();
                }
            }
        });
    }

    // Quoted bit click navigation
    document.addEventListener('click', (event) => {
        const quotedPreview = event.target.closest('.bitstream-quoted-preview');
        if (quotedPreview) {
            const interactive = event.target.closest('a, button, input, textarea, select, option, audio, video, iframe, [role="button"]');
            if (interactive) return;

            const selection = window.getSelection();
            if (selection && selection.toString().trim() !== '') return;

            const permalink = quotedPreview.dataset.permalink;
            if (permalink) {
                event.preventDefault();
                window.location.href = permalink;
            }
        }
    });

    // Clean up parameters
    function cleanupUrlParams() {
        if (typeof window.history.replaceState !== 'function') return;
        const url = new URL(window.location.href);
        const paramsToRemove = [
            'highlight_draft',
            'highlight_scheduled',
            'open_comments',
            'show_drafts',
            'show_scheduled',
            'show_rebit',
            'focus_composer',
            'quote_post_id',
            'composer_tab',
            'url',
            'shared_url',
            'shared_title',
            'shared_text',
            'share_target',
            'shared_id'
        ];
        let urlChanged = false;
        paramsToRemove.forEach(param => {
            if (url.searchParams.has(param)) {
                url.searchParams.delete(param);
                urlChanged = true;
            }
        });
        if (urlChanged) {
            const newUrl = url.pathname + (url.search ? url.search : '') + url.hash;
            window.history.replaceState({}, document.title, newUrl);
        }
    }

    // Export namespace
    window.BitStream = window.BitStream || {};
    window.BitStream.Timeline = {
        init: function () {
            initTimelineEditModal();
            highlightFromQueryParams();
            if (typeof window.applyMediaDeterrents === 'function') {
                window.applyMediaDeterrents(document);
            }
            initCommentToggles();
            initMediaSession(document);
            makeEmbedsResponsive();
            syncLikeButtonState(document);
            syncArchiveYearState();
            bindTimelineEvents();
            initPushNotifications();
            initBottomNavAndSheets();
            initImageDownloadProtection();
            cleanupUrlParams();
            parseTimelineCards();
        },
        syncEditPreviewArea: function () {
            if (typeof window.syncEditPreviewArea === 'function') {
                window.syncEditPreviewArea();
            }
        },
        showDeleteConfirmation: showDeleteConfirmation,
        showDiscardConfirmation: showDiscardConfirmation,
        initTimelineEditModal: initTimelineEditModal,
        openTimelineQuoteModal: openTimelineQuoteModal,
        getHashtags: getHashtags
    };
})();
