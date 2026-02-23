document.addEventListener('DOMContentLoaded', function() {
    // Check if this is a PWA share target launch
    const urlParams = new URLSearchParams(window.location.search);
    const isShareTarget = urlParams.has('url') || urlParams.has('shared_url');
    
    if (isShareTarget) {
        console.log('BitStream: PWA Share target detected');
        
        // Show a brief notification that content is being shared
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2c6e49;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(44,110,73,0.3);
            z-index: 10000;
            font-size: 14px;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s ease;
        `;
        notification.textContent = 'Content shared to BitStream!';
        document.body.appendChild(notification);
        
        // Animate in
        setTimeout(() => {
            notification.style.opacity = '1';
            notification.style.transform = 'translateY(0)';
        }, 100);
        
        // Remove after 3 seconds
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(-20px)';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
    
    // Continue with the rest of the initialization...
    function initBitstreamPoster() {
        const posterRoot = document.querySelector('.bitstream-poster');
        if (!posterRoot) {
            return;
        }

        const statusEl = posterRoot.querySelector('.bitstream-poster-status');
        const submitNonce = posterRoot.dataset.submitNonce || '';
        const resultRoot = posterRoot.querySelector('.bitstream-poster-result');
        const resultCard = posterRoot.querySelector('.bitstream-poster-result-card');
        const resultEdit = posterRoot.querySelector('.bitstream-poster-action-edit');
        const resultView = posterRoot.querySelector('.bitstream-poster-action-view');
        const resultCopy = posterRoot.querySelector('.bitstream-poster-action-copy');
        let latestPermalink = '';

        function setStatus(message, isError = false) {
            if (!statusEl) {
                return;
            }

            statusEl.textContent = message;
            statusEl.classList.toggle('is-error', isError);
            statusEl.classList.toggle('is-success', !isError && !!message);
        }

        function setPublishedPreview(data) {
            if (!resultRoot || !resultCard) {
                return;
            }

            const renderedHtml = data.rendered_html || '';
            latestPermalink = data.permalink || '';

            resultCard.innerHTML = renderedHtml;
            resultRoot.hidden = false;

            if (resultEdit) {
                resultEdit.href = data.edit_url || '#';
            }
            if (resultView) {
                resultView.href = data.view_url || latestPermalink || '#';
            }
        }

        if (resultCopy) {
            resultCopy.addEventListener('click', () => {
                if (!latestPermalink) {
                    setStatus('No permalink available to copy yet.', true);
                    return;
                }

                navigator.clipboard.writeText(latestPermalink)
                    .then(() => setStatus('Permalink copied.'))
                    .catch(() => setStatus('Could not copy permalink.', true));
            });
        }

        const tabButtons = posterRoot.querySelectorAll('.bitstream-poster-tab');
        const tabPanels = posterRoot.querySelectorAll('.bitstream-poster-panel');

        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const selectedTab = button.dataset.tab;

                tabButtons.forEach(tab => {
                    const isActive = tab === button;
                    tab.classList.toggle('is-active', isActive);
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                tabPanels.forEach(panel => {
                    const isActive = panel.id === 'bitstream-poster-panel-' + selectedTab;
                    panel.classList.toggle('is-active', isActive);
                    panel.hidden = !isActive;
                });

                setStatus('');
            });
        });

        const scheduleToggles = posterRoot.querySelectorAll('[data-schedule-toggle]');
        scheduleToggles.forEach(toggle => {
            const key = toggle.dataset.scheduleToggle;
            const datetimeInput = posterRoot.querySelector('[data-schedule-input="' + key + '"]');

            if (!datetimeInput) {
                return;
            }

            toggle.addEventListener('change', () => {
                const enabled = !!toggle.checked;
                datetimeInput.disabled = !enabled;
                if (!enabled) {
                    datetimeInput.value = '';
                }
            });
        });

        const scheduledFilterButtons = posterRoot.querySelectorAll('.bitstream-scheduled-filter-btn');
        const scheduledRows = posterRoot.querySelectorAll('.bitstream-scheduled-item');
        scheduledFilterButtons.forEach(button => {
            button.addEventListener('click', () => {
                const filter = button.dataset.filter || 'all';

                scheduledFilterButtons.forEach(btn => {
                    btn.classList.toggle('is-active', btn === button);
                });

                scheduledRows.forEach(row => {
                    const type = row.dataset.type || 'bit';
                    const show = (filter === 'all' || filter === type);
                    row.hidden = !show;
                });
            });
        });

        function renderMediaPreview(previewEl, attachment) {
            if (!previewEl) {
                return;
            }

            const dropzone = previewEl.closest('.bitstream-media-dropzone');
            if (dropzone) {
                dropzone.classList.toggle('has-media', !!attachment);
            }

            if (!attachment) {
                previewEl.innerHTML = '';
                return;
            }

            const mimeType = attachment.mime || '';
            const previewUrl = (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) || attachment.url || '';

            if (mimeType.startsWith('image/')) {
                previewEl.innerHTML = '<img src="' + previewUrl + '" alt="">';
                return;
            }

            previewEl.innerHTML = '<p>Selected: ' + (attachment.filename || attachment.title || 'media') + '</p>';
        }

        function bindMediaButtons() {
            const removeButtons = posterRoot.querySelectorAll('.bitstream-media-remove');
            const cropLinks = posterRoot.querySelectorAll('.bitstream-media-crop');
            const dropzones = posterRoot.querySelectorAll('.bitstream-media-dropzone');
            const cropperModal = posterRoot.querySelector('.bitstream-cropper-modal');
            const cropperImage = cropperModal ? cropperModal.querySelector('.bitstream-cropper-image') : null;
            const cropperSelection = cropperModal ? cropperModal.querySelector('.bitstream-cropper-selection') : null;
            const cropperStage = cropperModal ? cropperModal.querySelector('.bitstream-cropper-stage') : null;
            const cropperApply = cropperModal ? cropperModal.querySelector('.bitstream-cropper-apply') : null;
            const cropperSizeLabel = cropperModal ? cropperModal.querySelector('.bitstream-cropper-size') : null;
            const cropperCloseButtons = cropperModal ? cropperModal.querySelectorAll('[data-cropper-close="true"]') : [];
            let cropperState = null;

            function setRemoveVisibility(targetInputId) {
                const input = document.getElementById(targetInputId);
                const removeButton = posterRoot.querySelector('.bitstream-media-remove[data-target-input="' + targetInputId + '"]');
                if (!removeButton) {
                    return;
                }

                const hasValue = input && input.value && input.value.trim() !== '';
                removeButton.classList.toggle('is-hidden', !hasValue);
            }

            function setCropVisibility(targetInputId, mimeType) {
                const cropLink = posterRoot.querySelector('.bitstream-media-crop[data-target-input="' + targetInputId + '"]');
                if (!cropLink) {
                    return;
                }

                const isImage = mimeType && mimeType.startsWith('image/');
                cropLink.classList.toggle('is-hidden', !isImage);
            }

            function closeCropper() {
                if (!cropperModal) {
                    return;
                }
                cropperModal.hidden = true;
                document.body.classList.remove('bitstream-cropper-open');
                if (cropperImage) {
                    cropperImage.src = '';
                }
                if (cropperSelection) {
                    cropperSelection.style.display = 'none';
                }
                if (cropperSizeLabel) {
                    cropperSizeLabel.textContent = 'Size: --';
                }
                cropperState = null;
            }

            function updateSelectionBox(selection) {
                if (!cropperSelection || !selection) {
                    return;
                }
                cropperSelection.style.display = 'block';
                cropperSelection.style.left = selection.x + 'px';
                cropperSelection.style.top = selection.y + 'px';
                cropperSelection.style.width = selection.width + 'px';
                cropperSelection.style.height = selection.height + 'px';

                if (cropperSizeLabel && cropperImage) {
                    const rect = cropperImage.getBoundingClientRect();
                    const scaleX = rect.width ? (cropperImage.naturalWidth / rect.width) : 1;
                    const scaleY = rect.height ? (cropperImage.naturalHeight / rect.height) : 1;
                    const pxW = Math.max(1, Math.round(selection.width * scaleX));
                    const pxH = Math.max(1, Math.round(selection.height * scaleY));
                    cropperSizeLabel.textContent = 'Size: ' + pxW + ' x ' + pxH + ' px';
                }
            }

            function clamp(value, min, max) {
                return Math.max(min, Math.min(max, value));
            }

            function openCropper(targetInputId, targetPreviewId) {
                if (!cropperModal || !cropperImage || !cropperStage) {
                    setStatus('Cropper is unavailable on this page.', true);
                    return;
                }

                const input = document.getElementById(targetInputId);
                const attachmentId = input ? parseInt(input.value || '0', 10) : 0;
                if (!attachmentId || !window.wp || !wp.media) {
                    setStatus('No image selected to crop.', true);
                    return;
                }

                const attachment = wp.media.attachment(attachmentId);
                attachment.fetch().then(() => {
                    const url = attachment.get('url');
                    if (!url) {
                        setStatus('Unable to load image for cropping.', true);
                        return;
                    }

                    cropperState = {
                        targetInputId,
                        targetPreviewId,
                        attachmentId,
                        selection: null,
                        mode: null,
                        handle: null,
                        startX: 0,
                        startY: 0
                    };

                    cropperImage.onload = () => {
                        const rect = cropperImage.getBoundingClientRect();
                        const insetX = rect.width * 0.1;
                        const insetY = rect.height * 0.1;
                        cropperState.selection = {
                            x: insetX,
                            y: insetY,
                            width: Math.max(20, rect.width - insetX * 2),
                            height: Math.max(20, rect.height - insetY * 2)
                        };
                        updateSelectionBox(cropperState.selection);
                    };

                    cropperImage.src = url;
                    cropperModal.hidden = false;
                    document.body.classList.add('bitstream-cropper-open');
                });
            }

            function getPointerPosition(event) {
                const rect = cropperImage.getBoundingClientRect();
                return {
                    x: event.clientX - rect.left,
                    y: event.clientY - rect.top,
                    rect
                };
            }

            function beginSelection(event) {
                if (!cropperState || !cropperImage || !cropperSelection) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                const target = event.target;
                const handle = target && target.dataset ? target.dataset.handle : null;
                const isHandle = !!handle;

                const pos = getPointerPosition(event);
                if (!pos.rect.width || !pos.rect.height) {
                    return;
                }

                cropperState.startX = pos.x;
                cropperState.startY = pos.y;
                cropperState.handle = handle;

                if (isHandle) {
                    cropperState.mode = 'resize';
                } else if (target === cropperSelection) {
                    cropperState.mode = 'move';
                } else {
                    cropperState.mode = 'create';
                    cropperState.selection = {
                        x: pos.x,
                        y: pos.y,
                        width: 0,
                        height: 0
                    };
                }

                updateSelectionBox(cropperState.selection);
            }

            function updateSelection(event) {
                if (!cropperState || !cropperState.mode) {
                    return;
                }

                const pos = getPointerPosition(event);
                const rect = pos.rect;
                const maxX = rect.width;
                const maxY = rect.height;

                if (!cropperState.selection) {
                    cropperState.selection = { x: 0, y: 0, width: 0, height: 0 };
                }

                const selection = cropperState.selection;

                if (cropperState.mode === 'create') {
                    const x1 = clamp(cropperState.startX, 0, maxX);
                    const y1 = clamp(cropperState.startY, 0, maxY);
                    const x2 = clamp(pos.x, 0, maxX);
                    const y2 = clamp(pos.y, 0, maxY);
                    selection.x = Math.min(x1, x2);
                    selection.y = Math.min(y1, y2);
                    selection.width = Math.abs(x2 - x1);
                    selection.height = Math.abs(y2 - y1);
                } else if (cropperState.mode === 'move') {
                    const deltaX = pos.x - cropperState.startX;
                    const deltaY = pos.y - cropperState.startY;
                    selection.x = clamp(selection.x + deltaX, 0, maxX - selection.width);
                    selection.y = clamp(selection.y + deltaY, 0, maxY - selection.height);
                    cropperState.startX = pos.x;
                    cropperState.startY = pos.y;
                } else if (cropperState.mode === 'resize') {
                    const handle = cropperState.handle;
                    let x = selection.x;
                    let y = selection.y;
                    let w = selection.width;
                    let h = selection.height;

                    if (handle.indexOf('n') === 0) {
                        const newY = clamp(pos.y, 0, selection.y + selection.height - 10);
                        h = h + (y - newY);
                        y = newY;
                    }
                    if (handle.indexOf('s') === 0) {
                        h = clamp(pos.y - y, 10, maxY - y);
                    }
                    if (handle.indexOf('w') !== -1) {
                        const newX = clamp(pos.x, 0, selection.x + selection.width - 10);
                        w = w + (x - newX);
                        x = newX;
                    }
                    if (handle.indexOf('e') !== -1) {
                        w = clamp(pos.x - x, 10, maxX - x);
                    }

                    selection.x = clamp(x, 0, maxX - w);
                    selection.y = clamp(y, 0, maxY - h);
                    selection.width = w;
                    selection.height = h;
                }

                updateSelectionBox(selection);
            }

            function endSelection() {
                if (!cropperState) {
                    return;
                }
                cropperState.mode = null;
                cropperState.handle = null;
            }

            function handleMediaSelection(targetInputId, targetPreviewId, attachment) {
                const targetInput = document.getElementById(targetInputId);
                const targetPreview = document.getElementById(targetPreviewId);
                if (!targetInput) {
                    return;
                }

                targetInput.value = attachment.id || '';

                const fallbackImageInput = targetInputId === 'bitstream-rebit-attachment-id'
                    ? document.getElementById('bitstream-rebit-og-image')
                    : null;

                if (fallbackImageInput && attachment.url) {
                    fallbackImageInput.value = attachment.url;
                }

                renderMediaPreview(targetPreview, attachment);
                setRemoveVisibility(targetInputId);
                setCropVisibility(targetInputId, attachment.mime || '');
            }

            function uploadMediaFile(file, targetInputId, targetPreviewId) {
                if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                    setStatus('Media upload is unavailable.', true);
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'bitstream_upload_media');
                formData.append('nonce', bitstream_ajax.media_upload_nonce);
                formData.append('media', file);

                setStatus('Uploading media...');

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Upload failed.');
                        }

                        const media = data.data || {};
                        handleMediaSelection(targetInputId, targetPreviewId, {
                            id: media.id,
                            url: media.url,
                            mime: media.mime,
                            sizes: {
                                medium: { url: media.url }
                            }
                        });

                        setStatus('Media uploaded.');
                    })
                    .catch(error => {
                        setStatus(error.message || 'Upload failed.', true);
                    });
            }

            removeButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const targetInput = document.getElementById(button.dataset.targetInput || '');
                    const targetPreview = document.getElementById(button.dataset.targetPreview || '');

                    if (targetInput) {
                        targetInput.value = '';
                    }
                    renderMediaPreview(targetPreview, null);

                    if (button.dataset.targetInput) {
                        setRemoveVisibility(button.dataset.targetInput);
                        setCropVisibility(button.dataset.targetInput, '');
                    }
                });
            });

            cropLinks.forEach(link => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    if (link.classList.contains('is-hidden')) {
                        return;
                    }

                    const targetInputId = link.dataset.targetInput;
                    const targetPreviewId = link.dataset.targetInput === 'bitstream-bit-attachment-id'
                        ? 'bitstream-bit-media-preview'
                        : 'bitstream-rebit-media-preview';
                    openCropper(targetInputId, targetPreviewId);
                });
            });

            if (cropperStage && cropperSelection) {
                cropperStage.addEventListener('mousedown', beginSelection);
                cropperSelection.addEventListener('mousedown', beginSelection);
                document.addEventListener('mousemove', updateSelection);
                document.addEventListener('mouseup', endSelection);
            }

            if (cropperCloseButtons) {
                cropperCloseButtons.forEach(button => {
                    button.addEventListener('click', closeCropper);
                });
            }

            if (cropperApply) {
                cropperApply.addEventListener('click', () => {
                    if (!cropperState || !cropperState.selection || !cropperImage) {
                        return;
                    }

                    if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_crop_nonce) {
                        setStatus('Cropper is unavailable.', true);
                        return;
                    }

                    const rect = cropperImage.getBoundingClientRect();
                    if (!rect.width || !rect.height) {
                        setStatus('Crop area is invalid.', true);
                        return;
                    }

                    if (cropperState.selection.width < 10 || cropperState.selection.height < 10) {
                        setStatus('Select a larger crop area.', true);
                        return;
                    }

                    const scaleX = cropperImage.naturalWidth / rect.width;
                    const scaleY = cropperImage.naturalHeight / rect.height;
                    const selection = cropperState.selection;

                    const cropX = Math.round(selection.x * scaleX);
                    const cropY = Math.round(selection.y * scaleY);
                    const cropW = Math.round(selection.width * scaleX);
                    const cropH = Math.round(selection.height * scaleY);

                    const payload = new FormData();
                    payload.append('action', 'bitstream_crop_media');
                    payload.append('nonce', bitstream_ajax.media_crop_nonce);
                    payload.append('attachment_id', cropperState.attachmentId);
                    payload.append('crop_x', cropX);
                    payload.append('crop_y', cropY);
                    payload.append('crop_w', cropW);
                    payload.append('crop_h', cropH);

                    setStatus('Cropping image...');

                    fetch(bitstream_ajax.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: payload
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.data || 'Crop failed.');
                            }

                            const media = data.data || {};
                            const cacheKey = media.cache_buster ? (media.cache_buster + '') : '';
                            const url = media.url ? (media.url + (media.url.indexOf('?') === -1 ? '?' : '&') + 't=' + cacheKey) : '';

                            handleMediaSelection(cropperState.targetInputId, cropperState.targetPreviewId, {
                                id: media.id,
                                url: url,
                                mime: media.mime,
                                sizes: { medium: { url: url } }
                            });

                            setStatus('Image cropped.');
                            closeCropper();
                        })
                        .catch(error => {
                            setStatus(error.message || 'Crop failed.', true);
                        });
                });
            }

            dropzones.forEach(zone => {
                const input = zone.querySelector('.bitstream-media-file');
                const targetInputId = zone.dataset.targetInput;
                const targetPreviewId = zone.dataset.targetPreview;

                if (!input) {
                    return;
                }

                zone.addEventListener('click', () => {
                    input.click();
                });

                zone.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    zone.classList.add('is-dragover');
                });

                zone.addEventListener('dragleave', () => {
                    zone.classList.remove('is-dragover');
                });

                zone.addEventListener('drop', (event) => {
                    event.preventDefault();
                    zone.classList.remove('is-dragover');

                    const file = event.dataTransfer.files && event.dataTransfer.files[0];
                    if (!file) {
                        return;
                    }

                    uploadMediaFile(file, targetInputId, targetPreviewId);
                });

                input.addEventListener('change', () => {
                    const file = input.files && input.files[0];
                    if (!file) {
                        return;
                    }

                    uploadMediaFile(file, targetInputId, targetPreviewId);
                    input.value = '';
                });
            });

            const existingMediaInputs = posterRoot.querySelectorAll('input[name="bit_attachment_id"], input[name="rebit_attachment_id"]');
            existingMediaInputs.forEach(input => {
                const value = parseInt(input.value || '0', 10);
                if (!value || !window.wp || !wp.media) {
                    setRemoveVisibility(input.id);
                    setCropVisibility(input.id, '');
                    return;
                }

                const previewSelector = input.name === 'bit_attachment_id'
                    ? '#bitstream-bit-media-preview'
                    : '#bitstream-rebit-media-preview';
                const previewEl = posterRoot.querySelector(previewSelector);
                const attachment = wp.media.attachment(value);
                attachment.fetch().then(() => {
                    renderMediaPreview(previewEl, attachment.toJSON());
                    setRemoveVisibility(input.id);
                    setCropVisibility(input.id, attachment.get('mime'));
                }).catch(() => {});
            });
        }

        function updateRebitPreview(data) {
            const previewCard = posterRoot.querySelector('#bitstream-rebit-og-preview');
            if (!previewCard) {
                return;
            }

            const titleEl = previewCard.querySelector('.bitstream-rebit-preview-title');
            const descEl = previewCard.querySelector('.bitstream-rebit-preview-description');
            const imageEl = previewCard.querySelector('.bitstream-rebit-preview-image');

            if (titleEl) {
                titleEl.textContent = data.title || '';
            }
            if (descEl) {
                descEl.textContent = data.description || '';
            }
            if (imageEl) {
                imageEl.src = data.image || '';
                imageEl.style.display = data.image ? 'block' : 'none';
            }

            const hasPreview = !!(data.title || data.description || data.image);
            previewCard.hidden = !hasPreview;
        }

        const fetchButton = posterRoot.querySelector('.bitstream-fetch-og');
        if (fetchButton) {
            fetchButton.addEventListener('click', () => {
                const urlInput = posterRoot.querySelector('#bitstream-rebit-url');
                const titleInput = posterRoot.querySelector('#bitstream-rebit-og-title');
                const descInput = posterRoot.querySelector('#bitstream-rebit-og-desc');
                const imageInput = posterRoot.querySelector('#bitstream-rebit-og-image');

                const url = (urlInput && urlInput.value) ? urlInput.value.trim() : '';
                if (!url) {
                    setStatus('Please enter a URL first.', true);
                    return;
                }

                if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.og_fetch_nonce) {
                    setStatus('Metadata fetcher is unavailable.', true);
                    return;
                }

                const payload = new FormData();
                payload.append('action', 'bitstream_fetch_og_data');
                payload.append('nonce', bitstream_ajax.og_fetch_nonce);
                payload.append('url', url);
                payload.append('post_id', '0');

                fetchButton.disabled = true;
                setStatus('Fetching metadata...');

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Metadata fetch failed.');
                        }

                        const meta = data.data || {};

                        if (titleInput) {
                            titleInput.value = meta.title || '';
                        }
                        if (descInput) {
                            descInput.value = meta.description || '';
                        }
                        if (imageInput) {
                            imageInput.value = meta.image || imageInput.value || '';
                        }

                        updateRebitPreview(meta);
                        setStatus('Metadata loaded. You can edit it before publishing.');
                    })
                    .catch(error => {
                        setStatus(error.message || 'Could not fetch metadata.', true);
                    })
                    .finally(() => {
                        fetchButton.disabled = false;
                    });
            });

            const sharedUrlInput = posterRoot.querySelector('#bitstream-rebit-url');
            const hasPrefilledUrl = sharedUrlInput && sharedUrlInput.value && sharedUrlInput.value.trim();
            if (hasPrefilledUrl) {
                const titleInput = posterRoot.querySelector('#bitstream-rebit-og-title');
                const descInput = posterRoot.querySelector('#bitstream-rebit-og-desc');
                const hasManualPreview = (titleInput && titleInput.value.trim()) || (descInput && descInput.value.trim());
                if (!hasManualPreview) {
                    fetchButton.click();
                }
            }
        }

        const forms = posterRoot.querySelectorAll('.bitstream-poster-form');
        forms.forEach(form => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();

                if (!window.bitstream_ajax || !bitstream_ajax.ajax_url) {
                    setStatus('Poster submit endpoint is unavailable.', true);
                    return;
                }

                if (!submitNonce) {
                    setStatus('Security token missing. Refresh and try again.', true);
                    return;
                }

                const submitButton = form.querySelector('.bitstream-poster-submit');
                const originalText = submitButton ? submitButton.textContent : '';
                const payload = new FormData(form);
                payload.append('action', 'bitstream_submit_poster');
                payload.append('nonce', submitNonce);
                payload.append('poster_type', form.dataset.posterType || 'bit');

                const posterType = form.dataset.posterType || 'bit';
                const scheduleEnabledInput = form.querySelector('[name="' + posterType + '_schedule_enabled"]');
                const scheduleDatetimeInput = form.querySelector('[name="' + posterType + '_schedule_datetime"]');
                if (scheduleEnabledInput && scheduleEnabledInput.checked && scheduleDatetimeInput && !scheduleDatetimeInput.value) {
                    setStatus('Please choose a date and time for the schedule.', true);
                    return;
                }

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Publishing...';
                }

                setStatus('Publishing...');

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Could not publish.');
                        }

                        const responseData = data.data || {};
                        setStatus(responseData.message || 'Published successfully.');
                        setPublishedPreview(responseData);

                        form.reset();
                        form.querySelectorAll('.bitstream-media-preview').forEach(previewEl => {
                            previewEl.innerHTML = '';
                        });

                        if ((form.dataset.posterType || '') === 'rebit') {
                            updateRebitPreview({});
                        }
                    })
                    .catch(error => {
                        setStatus(error.message || 'Could not publish.', true);
                    })
                    .finally(() => {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.textContent = originalText;
                        }
                    });
            });
        });

        bindMediaButtons();
    }

    initBitstreamPoster();
    
    // Performance optimized like button with debouncing
    document.querySelectorAll('.bit-like').forEach(button => {
      const postId     = button.dataset.postId;
      const storageKey = 'bitstream-liked-' + postId;
      let isProcessing = false;

      if ( localStorage.getItem(storageKey) ) {
        button.classList.add('liked');
      }

      button.addEventListener('click', () => {
        if (isProcessing) return; // Prevent spam clicks
        isProcessing = true;
        
        const likeCountSpan = button.querySelector('.bit-like-count');
        const isLiked       = !!localStorage.getItem(storageKey);
        const type          = isLiked ? 'unlike' : 'like';

        const formData = new FormData();
        formData.append('action',  'bitstream_like');
        formData.append('post_id', postId);
        formData.append('type',    type);
        formData.append('nonce',   bitstream_ajax.like_nonce);

        fetch( bitstream_ajax.ajax_url, {
          method:      'POST',
          credentials: 'same-origin',
          body:        formData
        })
        .then(res => res.json())
        .then(data => {
          if ( data.success ) {
            if ( type === 'like' ) {
              localStorage.setItem(storageKey, true);
              button.classList.add('liked','pulse');
              setTimeout(() => button.classList.remove('pulse'), 300);
            } else {
              localStorage.removeItem(storageKey);
              button.classList.remove('liked');
            }
            if ( likeCountSpan ) {
              likeCountSpan.textContent = data.data.likes;
            }
          }
        })
        .catch(error => console.warn('BitStream like error:', error))
        .finally(() => {
          isProcessing = false;
        });
      });
    });

    // Copy Link button
    document.querySelectorAll('.bit-permalink').forEach(button => {
      button.addEventListener('click', (e) => {
        e.preventDefault();
        const url = button.dataset.url;
        navigator.clipboard.writeText(url);
        const icon = button.querySelector('i');
        if (icon) {
          icon.classList.remove('pulse');
          void icon.offsetWidth;
          icon.classList.add('pulse');
          setTimeout(() => icon.classList.remove('pulse'), 300);
        }
      });
    });

    // Quote button functionality
    document.querySelectorAll('.bit-quote').forEach(button => {
      button.addEventListener('click', (e) => {
        e.preventDefault();
        const postId = button.dataset.postId;
                const basePosterUrl = (window.bitstream_ajax && bitstream_ajax.poster_url) ? bitstream_ajax.poster_url : (window.location.origin + '/bitstream/');
                const quoteUrl = new URL(basePosterUrl, window.location.origin);
                quoteUrl.searchParams.set('poster_tab', 'bit');
                quoteUrl.searchParams.set('quote_post_id', postId);
        
        // Open quote editor in new tab/window
                window.open(quoteUrl.toString(), '_blank');
        
        // Add visual feedback
        const icon = button.querySelector('i');
        if (icon) {
          icon.classList.remove('pulse');
          void icon.offsetWidth;
          icon.classList.add('pulse');
          setTimeout(() => icon.classList.remove('pulse'), 300);
        }
      });
    });

    // Floating BitStream menu functionality
    function initFloatingMenu() {
        const bitstreamToggle = document.querySelector('.bitstream-toggle');
        const bitstreamDropdown = document.querySelector('.bitstream-dropdown');
        
        if (!bitstreamToggle || !bitstreamDropdown) {
            return; // Elements not found
        }
        
        let isOpen = false;
        
        // Function to open dropdown
        function openDropdown() {
            console.log('Opening dropdown'); // Debug log
            isOpen = true;
            bitstreamDropdown.style.opacity = '1';
            bitstreamDropdown.style.visibility = 'visible';
            bitstreamDropdown.style.transform = 'translateY(0)';
            bitstreamDropdown.style.pointerEvents = 'auto';
            bitstreamToggle.style.background = '#1f4d35';
            bitstreamToggle.style.transform = 'scale(1.1)';
        }
        
        // Function to close dropdown
        function closeDropdown() {
            console.log('Closing dropdown'); // Debug log
            isOpen = false;
            bitstreamDropdown.style.opacity = '0';
            bitstreamDropdown.style.visibility = 'hidden';
            bitstreamDropdown.style.transform = 'translateY(10px)';
            bitstreamDropdown.style.pointerEvents = 'none';
            bitstreamToggle.style.background = '#2c6e49';
            bitstreamToggle.style.transform = 'scale(1)';
        }
        
        // Unified event handler for both click and touch
        function handleToggle(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Toggle button activated, isOpen:', isOpen); // Debug log
            
            if (isOpen) {
                closeDropdown();
            } else {
                openDropdown();
            }
        }
        
        // Add both click and touchstart for maximum compatibility
        bitstreamToggle.addEventListener('click', handleToggle);
        bitstreamToggle.addEventListener('touchstart', handleToggle);
        
        // Prevent double-firing on devices that support both
        bitstreamToggle.addEventListener('touchend', (e) => {
            e.preventDefault();
        });
        
        // Add hover effects for desktop only (check if touch device)
        const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        if (!isTouch) {
            bitstreamToggle.addEventListener('mouseenter', () => {
                if (!isOpen) {
                    bitstreamToggle.style.background = '#1f4d35';
                    bitstreamToggle.style.transform = 'scale(1.05)';
                }
            });
            
            bitstreamToggle.addEventListener('mouseleave', () => {
                if (!isOpen) {
                    bitstreamToggle.style.background = '#2c6e49';
                    bitstreamToggle.style.transform = 'scale(1)';
                }
            });
        }
        
        // Add hover effects for dropdown links (desktop only)
        if (!isTouch) {
            // Use event delegation since links might not exist when this runs
            bitstreamDropdown.addEventListener('mouseenter', (e) => {
                if (e.target.classList.contains('bitstream-dropdown-link')) {
                    e.target.style.background = '#f5f5f5';
                }
            });
            bitstreamDropdown.addEventListener('mouseleave', (e) => {
                if (e.target.classList.contains('bitstream-dropdown-link')) {
                    e.target.style.background = 'white';
                }
            });
        }
        
        // Close dropdown when clicking/touching outside
        function handleOutsideClick(e) {
            if (!e.target.closest('.bitstream-menu') && isOpen) {
                closeDropdown();
            }
        }
        
        document.addEventListener('click', handleOutsideClick);
        document.addEventListener('touchstart', handleOutsideClick);
        
        console.log('Floating menu initialized'); // Debug log
    }
    
    // Initialize floating menu (try multiple times if needed)
    initFloatingMenu();
    
    // Also try after a short delay in case elements are loaded later
    setTimeout(initFloatingMenu, 500);
    setTimeout(initFloatingMenu, 1000);

    // Fix responsive embeds (YouTube, etc.)
    function makeEmbedsResponsive() {
        // Find all iframes and make them responsive
        document.querySelectorAll('iframe').forEach(iframe => {
            // Skip if already processed
            if (iframe.dataset.responsive === 'true') return;
            
            // Mark as processed
            iframe.dataset.responsive = 'true';
            
            // Set responsive styles
            iframe.style.maxWidth = '100%';
            iframe.style.height = 'auto';
            
            // For YouTube iframes, set aspect ratio
            if (iframe.src && (iframe.src.includes('youtube.com') || iframe.src.includes('youtu.be'))) {
                iframe.style.aspectRatio = '16/9';
                iframe.style.width = '100%';
            }
            
            // Make parent containers responsive too
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
        
        // Also handle WordPress embed containers directly
        document.querySelectorAll('.wp-embedded-content, .wp-block-embed, .wp-embed').forEach(container => {
            container.style.maxWidth = '100%';
            container.style.width = '100%';
            container.style.overflowX = 'hidden';
        });
    }
    
    // Run on page load
    makeEmbedsResponsive();
    
    // Run when new content is loaded (for infinite scroll)
    const observer = new MutationObserver(() => {
        makeEmbedsResponsive();
        initFloatingMenu(); // Re-init floating menu if new content added
        initCommentToggles(); // Re-init comment toggles for new content
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Comment Toggle button - using event delegation for dynamic content
    function initCommentToggles() {
        document.querySelectorAll('.bit-comment-toggle').forEach(button => {
            // Skip if already initialized
            if (button.dataset.initialized === 'true') return;
            
            button.dataset.initialized = 'true';
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = button.dataset.target;
                const section = document.getElementById(targetId);
                if (section) {
                    // Find the parent bit-card BEFORE toggling to get proper timing
                    const bitCard = section.closest('.bit-card');
                    
                    if (bitCard) {
                        if (!section.classList.contains('open')) {
                            // About to open - immediately boost z-index BEFORE animation starts
                            bitCard.classList.add('comments-open');
                            console.log('Comments opening for card:', bitCard, '- z-index boosted');
                            
                            // Trigger immediate masonry reflow to reorganize layout BEFORE content becomes visible
                            triggerMasonryReflow();
                            
                            // Additional reflow slightly later to ensure proper positioning
                            setTimeout(() => {
                                triggerMasonryReflow();
                            }, 100);
                        }
                    }
                    
                    // Now toggle the comments section
                    section.classList.toggle('open');
                    
                    if (bitCard) {
                        if (!section.classList.contains('open')) {
                            // Comments were closed - remove z-index boost after animation
                            console.log('Comments closed for card:', bitCard);
                            setTimeout(() => {
                                bitCard.classList.remove('comments-open');
                            }, 450); // Slightly longer than CSS transition duration
                        }
                        
                        // Trigger masonry layout recalculation after animation
                        setTimeout(() => {
                            triggerMasonryReflow();
                        }, 400); // Match the CSS transition duration
                    }
                }
            });
        });
    }
    
    // Function to trigger masonry layout recalculation
    function triggerMasonryReflow() {
        // Use the existing initMasonry function to recalculate layout
        setTimeout(() => {
            if (typeof window.initMasonry === 'function') {
                console.log('Triggering masonry reflow with initMasonry');
                window.initMasonry();
            }
        }, 50); // Small delay to ensure DOM has updated
    }
    
    // Initialize comment toggles
    initCommentToggles();

    // Add masonry reflow listener if using a masonry library
    document.addEventListener('masonryReflow', () => {
        console.log('Masonry reflow event received');
        // This event can be caught by masonry initialization code
    });
    // Infinite Scroll & Load More with Masonry Layout
    const feed = document.querySelector('.bitstream-feed');
    if (!feed) return;

    let loading = false;
    const loadMoreButton = document.getElementById('bitstream-load-more');
    const scrollTrigger = document.querySelector('.bitstream-scroll-trigger');
    const isInfiniteScroll = feed.dataset.infiniteScroll === 'true';

    // Masonry layout implementation with improved height detection
    window.initMasonry = function initMasonry() {
        if (window.innerWidth < 768) {
            // Single column on mobile - no masonry needed
            feed.style.height = 'auto';
            const cards = feed.querySelectorAll('.bit-card');
            cards.forEach((card, index) => {
                card.style.position = 'relative';
                card.style.left = '0';
                card.style.top = '0';
                card.style.width = '100%';
                card.style.marginBottom = '1rem';
                card.style.zIndex = 'auto';
            });
            return;
        }

        const cards = Array.from(feed.querySelectorAll('.bit-card'));
        if (cards.length === 0) return;

        const columns = window.innerWidth >= 1024 ? 3 : 2;
        const gap = window.innerWidth >= 1024 ? 20 : 16;
        
        // Calculate column width based on feed width and columns
        const feedWidth = feed.offsetWidth;
        const totalGapWidth = gap * (columns - 1);
        const columnWidth = Math.floor((feedWidth - totalGapWidth) / columns);
        
        const columnHeights = new Array(columns).fill(0);

        cards.forEach((card, index) => {
            // Force a reflow to ensure accurate height measurement
            card.style.width = columnWidth + 'px';
            card.style.position = 'absolute';
            
            // Wait for next frame to get accurate height after width is set
            void card.offsetHeight;
            
            // Get accurate card height including all content
            const cardRect = card.getBoundingClientRect();
            let cardHeight = Math.ceil(cardRect.height);
            
            // If height seems wrong, recalculate with a different method
            if (cardHeight < 50) {
                cardHeight = card.scrollHeight || card.offsetHeight || 200;
            }
            
            // Add extra padding for safety to prevent overlaps
            cardHeight += 2;
            
            // Find the shortest column
            const shortestColumn = columnHeights.indexOf(Math.min(...columnHeights));
            
            // Position the card
            const x = shortestColumn * (columnWidth + gap);
            const y = columnHeights[shortestColumn];
            
            card.style.left = x + 'px';
            card.style.top = y + 'px';
            card.style.zIndex = '2';
            
            // Update column height
            columnHeights[shortestColumn] += cardHeight + gap;
        });

        // Set container height with extra padding to prevent overlap
        const maxHeight = Math.max(...columnHeights);
        feed.style.height = (maxHeight + gap * 2) + 'px';
        feed.style.position = 'relative';
        feed.style.overflow = 'visible';
        
        console.log('Masonry layout calculated:', {
            cards: cards.length,
            columns: columns,
            columnWidth: columnWidth,
            maxHeight: maxHeight
        });
    };

    // Initialize masonry on load
    setTimeout(window.initMasonry, 100);

    // Recalculate layout when images load
    function setupImageLoadHandlers() {
        const images = feed.querySelectorAll('.bit-card img');
        let loadedImages = 0;
        const totalImages = images.length;
        
        if (totalImages === 0) {
            // No images, layout is stable
            return;
        }
        
        images.forEach(img => {
            if (img.complete) {
                // Image already loaded
                loadedImages++;
                if (loadedImages === totalImages) {
                    setTimeout(window.initMasonry, 50);
                }
            } else {
                // Wait for image to load
                img.addEventListener('load', () => {
                    loadedImages++;
                    if (loadedImages === totalImages) {
                        setTimeout(window.initMasonry, 50);
                    }
                });
                img.addEventListener('error', () => {
                    loadedImages++;
                    if (loadedImages === totalImages) {
                        setTimeout(window.initMasonry, 50);
                    }
                });
            }
        });
    }
    
    setupImageLoadHandlers();
    
    // Also recalculate after fonts load
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => {
            setTimeout(window.initMasonry, 100);
        });
    }

    // Watch for content changes within cards (like expanding comments)
    const contentObserver = new MutationObserver((mutations) => {
        let shouldRecalculate = false;
        
        mutations.forEach(mutation => {
            // Check if any cards had significant changes
            if (mutation.type === 'childList' || mutation.type === 'attributes') {
                const card = mutation.target.closest('.bit-card');
                if (card) {
                    shouldRecalculate = true;
                }
            }
        });
        
        if (shouldRecalculate) {
            // Debounce the recalculation
            clearTimeout(window.masonryRecalcTimeout);
            window.masonryRecalcTimeout = setTimeout(() => {
                window.initMasonry();
            }, 150);
        }
    });
    
    // Observe the feed container for changes
    contentObserver.observe(feed, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style']
    });

    // Reinitialize on window resize with debouncing
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            window.initMasonry();
            // Recalculate again after a short delay to catch any async changes
            setTimeout(window.initMasonry, 100);
        }, 250);
    });

    function loadNextPage() {
        const nextPage = parseInt(feed.dataset.page) + 1;
        const maxPage = parseInt(feed.dataset.maxPage);
        if (loading || nextPage > maxPage) return;
        loading = true;
        
        // Update button text if it exists
        if (loadMoreButton) {
            loadMoreButton.textContent = 'Loading…';
        }
        
        const formData = new FormData();
        formData.append('action', 'bitstream_load_more');
        formData.append('page', nextPage);
        formData.append('nonce', bitstream_ajax.load_more_nonce);

        fetch(bitstream_ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const newCards = temp.querySelectorAll('.bit-card');
            
            // Append new cards to feed
            newCards.forEach(card => {
                feed.appendChild(card);
            });
            
            feed.dataset.page = nextPage;
            loading = false;
            
            // Wait for new cards to be rendered in DOM
            requestAnimationFrame(() => {
                // Handle images in new cards
                const newImages = Array.from(newCards).flatMap(card => 
                    Array.from(card.querySelectorAll('img'))
                );
                
                if (newImages.length > 0) {
                    let loadedCount = 0;
                    const checkAllLoaded = () => {
                        loadedCount++;
                        if (loadedCount === newImages.length) {
                            window.initMasonry();
                        }
                    };
                    
                    newImages.forEach(img => {
                        if (img.complete) {
                            checkAllLoaded();
                        } else {
                            img.addEventListener('load', checkAllLoaded);
                            img.addEventListener('error', checkAllLoaded);
                        }
                    });
                } else {
                    // No images, layout immediately
                    window.initMasonry();
                }
                
                // Also recalculate after a delay as a safety net
                setTimeout(window.initMasonry, 200);
            });
            
            // Update button state
            if (loadMoreButton) {
                loadMoreButton.textContent = 'Load More';
                if (nextPage >= maxPage) {
                    loadMoreButton.style.display = 'none';
                }
            }
            
            // Hide scroll trigger if we've reached the end
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

    // Infinite scroll: only trigger on scroll if infinite scroll is enabled
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

    // Load more button: always works when present
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', loadNextPage);
    }
});

// Consolidated comment styling function
function applyCommentStyles() {
    const styles = {
        authorName: 'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-right: 0.25em !important; color: #2c6e49 !important;',
        says: 'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-left: 0.3em !important; color: #2c6e49 !important;',
        dateLinks: 'color: #044389 !important; font-style: normal !important; font-size: 0.8em !important; text-decoration: underline !important; pointer-events: auto !important; cursor: pointer !important;'
    };

    // Apply author name styling
    jQuery('.bit-comments-list .comment-author .fn').attr('style', styles.authorName);

    // Add "says:" if missing and style it
    jQuery('.bit-comments-list .comment-author').each(function() {
        var $author = jQuery(this);
        if ($author.find('.says').length === 0) {
            $author.append('<span class="says">says:</span>');
        }
        $author.find('.says').attr('style', styles.says);
    });

    // Style date and edit links
    jQuery('.bit-comments-list .comment-metadata a, .bit-comments-list .edit-link a, .bit-comments-list .comment-edit-link').attr('style', styles.dateLinks);

    // Style nested replies with green border
    jQuery('.bit-comments-list .comment.depth-2, .bit-comments-list .comment.depth-3, .bit-comments-list .comment.depth-4').css({
        'margin-left': '2em',
        'border-left': '2px solid #2c6e49',
        'padding-left': '1em',
        'background': '#fafafa'
    });

    // Remove top divider from nested replies
    jQuery('.bit-comments-list .comment.depth-2 > .comment-body, .bit-comments-list .comment.depth-3 > .comment-body, .bit-comments-list .comment.depth-4 > .comment-body').css({
        'border-top': 'none',
        'margin-top': '0',
        'padding-top': '0'
    });
}

// Apply comment styles on document ready
jQuery(document).ready(function($) {
    applyCommentStyles();
});
