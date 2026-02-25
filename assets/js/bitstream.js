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
    
    function applyMediaDeterrents(scope) {
        const root = scope || document;
        root.querySelectorAll('video, audio').forEach(mediaEl => {
            mediaEl.setAttribute('controlsList', 'nodownload noplaybackrate');
            mediaEl.setAttribute('disablepictureinpicture', '');
            mediaEl.disablePictureInPicture = true;
        });
        root.querySelectorAll('img').forEach(img => {
            img.addEventListener('dragstart', (event) => event.preventDefault());
        });
    }

    document.addEventListener('contextmenu', (event) => {
        const container = event.target.closest('.bitstream-feed, .bitstream-poster');
        if (!container) {
            return;
        }

        const mediaTarget = event.target.closest('img, video, audio, .mejs-container');
        if (mediaTarget) {
            event.preventDefault();
        }
    });

    function highlightFromQueryParams() {
        const params = new URLSearchParams(window.location.search);
        const highlightBit = parseInt(params.get('highlight_bit') || '0', 10);
        const highlightScheduled = parseInt(params.get('highlight_scheduled') || '0', 10);

        if (highlightBit > 0) {
            const bitCard = document.getElementById('bit-' + highlightBit);
            if (bitCard) {
                bitCard.classList.add('bitstream-highlight-target');
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

    // Continue with the rest of the initialization...
    function initBitstreamPoster() {
        const posterRoot = document.querySelector('.bitstream-poster');
        if (!posterRoot) {
            return;
        }

        const statusEl = posterRoot.querySelector('.bitstream-poster-status');
        const submitNonce = posterRoot.dataset.submitNonce || '';
        let refreshRebitPreview = () => {};
        let refreshRebitEditorImagePreview = () => {};

        function setStatus(message, isError = false) {
            if (!statusEl) {
                return;
            }

            statusEl.textContent = message;
            statusEl.classList.toggle('is-error', isError);
            statusEl.classList.toggle('is-success', !isError && !!message);
        }

        function sanitizeInlinePreviewMarkup(markup) {
            const html = (markup || '').trim();
            if (!html) {
                return '';
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString('<div id="bitstream-inline-preview-root">' + html + '</div>', 'text/html');
            const root = doc.getElementById('bitstream-inline-preview-root');
            if (!root) {
                return html;
            }

            root.querySelectorAll('.bit-card-footer, .bit-comments, form, hr').forEach(node => {
                node.remove();
            });

            return root.innerHTML;
        }

        function setInlinePublishedPreview(form, data) {
            if (!form) {
                return;
            }

            const renderedHtml = sanitizeInlinePreviewMarkup(data.rendered_html || '');
            if (!renderedHtml) {
                return;
            }

            const posterType = form.dataset.posterType || 'bit';
            let previewRoot = null;
            let previewCard = null;

            if (posterType === 'rebit') {
                previewRoot = posterRoot.querySelector('#bitstream-rebit-live-preview');
                previewCard = previewRoot ? previewRoot.querySelector('.bitstream-rebit-live-preview-card') : null;
            } else {
                previewRoot = form.querySelector('.bitstream-bit-live-preview');
                if (!previewRoot) {
                    previewRoot = document.createElement('div');
                    previewRoot.className = 'bitstream-rebit-live-preview bitstream-bit-live-preview';
                    previewRoot.innerHTML = '<p class="bitstream-rebit-live-preview-label"><strong>Preview</strong></p><div class="bitstream-rebit-live-preview-card"></div>';
                    const submitButton = form.querySelector('.bitstream-poster-submit');
                    if (submitButton && submitButton.parentNode) {
                        submitButton.parentNode.insertBefore(previewRoot, submitButton);
                    } else {
                        form.appendChild(previewRoot);
                    }
                }
                previewCard = previewRoot.querySelector('.bitstream-rebit-live-preview-card');
            }

            if (!previewRoot || !previewCard) {
                return;
            }

            previewCard.innerHTML = renderedHtml;
            previewRoot.hidden = false;
            applyMediaDeterrents(previewCard);
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
            const hiddenInput = posterRoot.querySelector('[data-schedule-hidden="' + key + '"]');

            if (!datetimeInput) {
                return;
            }

            toggle.addEventListener('change', () => {
                const enabled = toggle.value === 'later' && toggle.checked;
                datetimeInput.disabled = !enabled;
                if (hiddenInput) {
                    hiddenInput.value = enabled ? '1' : '0';
                }
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
                previewEl.removeAttribute('data-attachment-id');
                previewEl.removeAttribute('data-attachment-url');
                previewEl.removeAttribute('data-attachment-mime');
                return;
            }

            const mimeType = attachment.mime || '';
            const previewUrl = (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) || attachment.url || '';

            if (attachment.id) {
                previewEl.dataset.attachmentId = attachment.id;
            }
            if (previewUrl) {
                previewEl.dataset.attachmentUrl = previewUrl;
            }
            if (mimeType) {
                previewEl.dataset.attachmentMime = mimeType;
            }

            const buildAudioMetaBlock = (item) => {
                const meta = item.audio_meta || item.meta || (item.media_details && item.media_details.meta) || {};
                const title = meta.title || item.title || item.filename || 'Audio';
                const artist = meta.artist || '';
                const album = meta.album || '';

                if (!title && !artist && !album) {
                    return null;
                }

                const wrapper = document.createElement('div');
                wrapper.className = 'bitstream-audio-meta';

                if (title) {
                    const titleEl = document.createElement('div');
                    titleEl.className = 'bitstream-audio-title';
                    titleEl.textContent = title;
                    wrapper.appendChild(titleEl);
                }

                if (artist) {
                    const artistEl = document.createElement('div');
                    artistEl.className = 'bitstream-audio-artist';
                    artistEl.textContent = artist;
                    wrapper.appendChild(artistEl);
                }

                if (album) {
                    const albumEl = document.createElement('div');
                    albumEl.className = 'bitstream-audio-album';
                    albumEl.textContent = album;
                    wrapper.appendChild(albumEl);
                }

                return wrapper;
            };

            if (mimeType.startsWith('image/')) {
                previewEl.innerHTML = '<img src="' + previewUrl + '" alt="">';
                return;
            }

            if (mimeType.startsWith('video/')) {
                const video = document.createElement('video');
                video.src = previewUrl;
                video.controls = true;
                video.setAttribute('controlsList', 'nodownload noplaybackrate');
                video.setAttribute('disablepictureinpicture', '');
                video.disablePictureInPicture = true;
                previewEl.innerHTML = '';
                previewEl.appendChild(video);
                return;
            }

            if (mimeType.startsWith('audio/')) {
                const audio = document.createElement('audio');
                audio.src = previewUrl;
                audio.controls = true;
                audio.setAttribute('controlsList', 'nodownload noplaybackrate');
                const meta = attachment.audio_meta || attachment.meta || (attachment.media_details && attachment.media_details.meta) || {};
                const artwork = meta.artwork || '';
                const embed = document.createElement('div');
                embed.className = 'bitstream-audio-embed';

                if (artwork) {
                    const artworkWrap = document.createElement('div');
                    artworkWrap.className = 'bitstream-audio-artwork-wrap';
                    const img = document.createElement('img');
                    img.className = 'bitstream-audio-artwork';
                    img.src = artwork;
                    img.alt = '';
                    artworkWrap.appendChild(img);
                    embed.appendChild(artworkWrap);
                } else {
                    embed.classList.add('no-artwork');
                }

                const player = document.createElement('div');
                player.className = 'bitstream-audio-player';
                player.appendChild(audio);

                const metaBlock = buildAudioMetaBlock(attachment);
                if (metaBlock) {
                    embed.appendChild(metaBlock);
                }

                embed.appendChild(player);

                previewEl.innerHTML = '';
                previewEl.appendChild(embed);
                applyMediaDeterrents(previewEl);
                return;
            }

            previewEl.innerHTML = '<p>Selected: ' + (attachment.filename || attachment.title || 'media') + '</p>';
        }

        function bindMediaButtons() {
            const removeButtons = posterRoot.querySelectorAll('.bitstream-media-remove');
            const cropLinks = posterRoot.querySelectorAll('.bitstream-media-crop');
            const audioTagLinks = posterRoot.querySelectorAll('.bitstream-media-audio-tags');
            const pasteButtons = posterRoot.querySelectorAll('.bitstream-media-paste');
            const dropzones = posterRoot.querySelectorAll('.bitstream-media-dropzone');
            const cropperModal = posterRoot.querySelector('.bitstream-cropper-modal');
            const cropperImage = cropperModal ? cropperModal.querySelector('.bitstream-cropper-image') : null;
            const cropperSelection = cropperModal ? cropperModal.querySelector('.bitstream-cropper-selection') : null;
            const cropperStage = cropperModal ? cropperModal.querySelector('.bitstream-cropper-stage') : null;
            const cropperApply = cropperModal ? cropperModal.querySelector('.bitstream-cropper-apply') : null;
            const cropperSizeLabel = cropperModal ? cropperModal.querySelector('.bitstream-cropper-size') : null;
            const cropperCloseButtons = cropperModal ? cropperModal.querySelectorAll('[data-cropper-close="true"]') : [];
            const audioTagsModal = posterRoot.querySelector('.bitstream-audio-tags-modal');
            const audioTagsCloseButtons = audioTagsModal ? audioTagsModal.querySelectorAll('[data-audio-tags-close="true"]') : [];
            const audioTagsSelectButton = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-select') : null;
            const audioTagsClearButton = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-clear') : null;
            const audioTagsSaveButton = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-save') : null;
            const audioTagsPreview = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-preview') : null;
            const audioTagInputs = audioTagsModal ? audioTagsModal.querySelectorAll('.bitstream-audio-tags-input') : [];
            const rebitEditorImagePreview = posterRoot.querySelector('.bitstream-rebit-editor-image-preview');
            const rebitEditorImageSelectButton = posterRoot.querySelector('.bitstream-rebit-editor-image-select');
            const rebitEditorImageCropButton = posterRoot.querySelector('.bitstream-rebit-editor-image-crop');
            const rebitEditorImageClearButton = posterRoot.querySelector('.bitstream-rebit-editor-image-clear');
            const rebitOgImageInput = posterRoot.querySelector('#bitstream-rebit-og-image');
            const rebitOgImageRemovedInput = posterRoot.querySelector('#bitstream-rebit-og-image-removed');
            let audioTagsTargetInputId = '';
            let audioTagsTargetPreviewId = '';
            let audioTagsArtworkId = 0;
            let audioTagsArtworkUrl = '';
            let audioTagsArtworkCleared = false;
            let cropperState = null;

            function setRemoveVisibility(targetInputId) {
                const input = document.getElementById(targetInputId);
                const removeButton = posterRoot.querySelector('.bitstream-media-remove[data-target-input="' + targetInputId + '"]');
                if (!removeButton) {
                    return;
                }

                const hasValue = input && parseInt(input.value || '0', 10) > 0;
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

            function setAudioTagVisibility(targetInputId, mimeType) {
                const tagLink = posterRoot.querySelector('.bitstream-media-audio-tags[data-target-input="' + targetInputId + '"]');
                if (!tagLink) {
                    return;
                }

                const isAudio = mimeType && mimeType.startsWith('audio/');
                tagLink.classList.toggle('is-hidden', !isAudio);
            }

            function closeAudioTagsModal() {
                if (!audioTagsModal) {
                    return;
                }
                audioTagsModal.hidden = true;
                audioTagsTargetInputId = '';
                audioTagsTargetPreviewId = '';
                audioTagsArtworkCleared = false;
            }

            function updateAudioArtworkPreview(url) {
                if (!audioTagsPreview) {
                    return;
                }
                if (url) {
                    audioTagsPreview.src = url;
                    audioTagsPreview.hidden = false;
                } else {
                    audioTagsPreview.src = '';
                    audioTagsPreview.hidden = true;
                }
            }

            function fillAudioTagFields(meta, fallbackTitle) {
                const title = meta.title || fallbackTitle || '';
                const artist = meta.artist || '';
                const album = meta.album || '';

                audioTagInputs.forEach(input => {
                    const field = input.dataset.audioTagsField;
                    if (field === 'title') {
                        input.value = title;
                    } else if (field === 'artist') {
                        input.value = artist;
                    } else if (field === 'album') {
                        input.value = album;
                    }
                });

                audioTagsArtworkId = meta.artwork_id ? parseInt(meta.artwork_id, 10) : 0;
                audioTagsArtworkUrl = meta.artwork || '';
                audioTagsArtworkCleared = false;
                updateAudioArtworkPreview(audioTagsArtworkUrl);
            }

            function openAudioTagsModal(targetInputId, targetPreviewId) {
                if (!audioTagsModal) {
                    setStatus('Audio tag editor is unavailable.', true);
                    return;
                }

                const input = document.getElementById(targetInputId);
                const attachmentId = input ? parseInt(input.value || '0', 10) : 0;
                if (!attachmentId) {
                    setStatus('Select an audio file first.', true);
                    return;
                }

                if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.audio_meta_nonce) {
                    setStatus('Audio tag editor is unavailable.', true);
                    return;
                }

                audioTagsTargetInputId = targetInputId;
                audioTagsTargetPreviewId = targetPreviewId;
                audioTagsArtworkCleared = false;
                audioTagsModal.hidden = false;

                const payload = new FormData();
                payload.append('action', 'bitstream_get_audio_meta');
                payload.append('nonce', bitstream_ajax.audio_meta_nonce);
                payload.append('attachment_id', attachmentId);

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Unable to load audio tags.');
                        }
                        const meta = data.data && data.data.meta ? data.data.meta : {};
                        const fallbackTitle = data.data && data.data.title ? data.data.title : '';
                        fillAudioTagFields(meta, fallbackTitle);
                    })
                    .catch(error => {
                        setStatus(error.message || 'Unable to load audio tags.', true);
                    });
            }

            function closeCropper() {
                if (!cropperModal) {
                    return;
                }
                cropperModal.hidden = true;
                cropperModal.classList.remove('is-square-mode');
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
                if (!cropperSelection || !selection || !cropperImage || !cropperStage) {
                    return;
                }

                const stageRect = cropperStage.getBoundingClientRect();
                const imageRect = cropperImage.getBoundingClientRect();
                const offsetX = imageRect.left - stageRect.left;
                const offsetY = imageRect.top - stageRect.top;

                cropperSelection.style.display = 'block';
                cropperSelection.style.left = (offsetX + selection.x) + 'px';
                cropperSelection.style.top = (offsetY + selection.y) + 'px';
                cropperSelection.style.width = selection.width + 'px';
                cropperSelection.style.height = selection.height + 'px';

                if (cropperSizeLabel) {
                    const scaleX = imageRect.width ? (cropperImage.naturalWidth / imageRect.width) : 1;
                    const scaleY = imageRect.height ? (cropperImage.naturalHeight / imageRect.height) : 1;
                    const pxW = Math.max(1, Math.round(selection.width * scaleX));
                    const pxH = Math.max(1, Math.round(selection.height * scaleY));
                    cropperSizeLabel.textContent = 'Size: ' + pxW + ' x ' + pxH + ' px';
                }
            }

            function clamp(value, min, max) {
                return Math.max(min, Math.min(max, value));
            }

            function openCropper(targetInputId, targetPreviewId, options = {}) {
                if (!cropperModal || !cropperImage || !cropperStage) {
                    setStatus('Cropper is unavailable on this page.', true);
                    return;
                }

                const explicitAttachmentId = options && options.attachmentId ? parseInt(options.attachmentId, 10) : 0;
                const input = explicitAttachmentId ? null : document.getElementById(targetInputId);
                const attachmentId = explicitAttachmentId || (input ? parseInt(input.value || '0', 10) : 0);
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
                        enforceSquare: !!options.enforceSquare,
                        onComplete: typeof options.onComplete === 'function' ? options.onComplete : null,
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

                        if (cropperState.enforceSquare) {
                            const availableW = Math.max(20, rect.width - insetX * 2);
                            const availableH = Math.max(20, rect.height - insetY * 2);
                            const size = Math.max(20, Math.min(availableW, availableH));
                            cropperState.selection = {
                                x: (rect.width - size) / 2,
                                y: (rect.height - size) / 2,
                                width: size,
                                height: size
                            };
                        } else {
                            cropperState.selection = {
                                x: insetX,
                                y: insetY,
                                width: Math.max(20, rect.width - insetX * 2),
                                height: Math.max(20, rect.height - insetY * 2)
                            };
                        }

                        updateSelectionBox(cropperState.selection);
                    };

                    cropperImage.src = url;
                    cropperModal.classList.toggle('is-square-mode', !!cropperState.enforceSquare);
                    cropperModal.hidden = false;
                    document.body.classList.add('bitstream-cropper-open');
                });
            }

            function getPointerPosition(event) {
                const point = (event.touches && event.touches[0])
                    || (event.changedTouches && event.changedTouches[0])
                    || event;
                const rect = cropperImage.getBoundingClientRect();
                return {
                    x: point.clientX - rect.left,
                    y: point.clientY - rect.top,
                    rect
                };
            }

            function beginSelection(event) {
                if (!cropperState || !cropperImage || !cropperSelection) {
                    return;
                }

                if (event.cancelable) {
                    event.preventDefault();
                }
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

                if (event.cancelable) {
                    event.preventDefault();
                }

                const pos = getPointerPosition(event);
                const rect = pos.rect;
                const maxX = rect.width;
                const maxY = rect.height;

                if (!cropperState.selection) {
                    cropperState.selection = { x: 0, y: 0, width: 0, height: 0 };
                }

                const selection = cropperState.selection;

                const applySquareResize = (handleName) => {
                    const centerX = selection.x + (selection.width / 2);
                    const centerY = selection.y + (selection.height / 2);
                    let squareHandle = handleName || 'se';

                    if (squareHandle === 'n') {
                        squareHandle = pos.x < centerX ? 'nw' : 'ne';
                    } else if (squareHandle === 's') {
                        squareHandle = pos.x < centerX ? 'sw' : 'se';
                    } else if (squareHandle === 'w') {
                        squareHandle = pos.y < centerY ? 'nw' : 'sw';
                    } else if (squareHandle === 'e') {
                        squareHandle = pos.y < centerY ? 'ne' : 'se';
                    }

                    let anchorX = selection.x;
                    let anchorY = selection.y;
                    let dirX = 1;
                    let dirY = 1;

                    if (squareHandle === 'nw') {
                        anchorX = selection.x + selection.width;
                        anchorY = selection.y + selection.height;
                        dirX = -1;
                        dirY = -1;
                    } else if (squareHandle === 'ne') {
                        anchorX = selection.x;
                        anchorY = selection.y + selection.height;
                        dirX = 1;
                        dirY = -1;
                    } else if (squareHandle === 'sw') {
                        anchorX = selection.x + selection.width;
                        anchorY = selection.y;
                        dirX = -1;
                        dirY = 1;
                    } else {
                        anchorX = selection.x;
                        anchorY = selection.y;
                        dirX = 1;
                        dirY = 1;
                    }

                    const deltaX = Math.abs(pos.x - anchorX);
                    const deltaY = Math.abs(pos.y - anchorY);
                    let side = Math.max(10, Math.max(deltaX, deltaY));

                    const maxSideX = dirX > 0 ? (maxX - anchorX) : anchorX;
                    const maxSideY = dirY > 0 ? (maxY - anchorY) : anchorY;
                    side = Math.min(side, maxSideX, maxSideY);

                    selection.width = side;
                    selection.height = side;
                    selection.x = dirX > 0 ? anchorX : (anchorX - side);
                    selection.y = dirY > 0 ? anchorY : (anchorY - side);
                };

                if (cropperState.mode === 'create') {
                    const x1 = clamp(cropperState.startX, 0, maxX);
                    const y1 = clamp(cropperState.startY, 0, maxY);
                    const x2 = clamp(pos.x, 0, maxX);
                    const y2 = clamp(pos.y, 0, maxY);

                    if (cropperState.enforceSquare) {
                        const signX = x2 >= x1 ? 1 : -1;
                        const signY = y2 >= y1 ? 1 : -1;
                        const maxSideX = signX > 0 ? (maxX - x1) : x1;
                        const maxSideY = signY > 0 ? (maxY - y1) : y1;
                        let side = Math.max(Math.abs(x2 - x1), Math.abs(y2 - y1));
                        side = Math.min(side, maxSideX, maxSideY);

                        selection.width = side;
                        selection.height = side;
                        selection.x = signX > 0 ? x1 : (x1 - side);
                        selection.y = signY > 0 ? y1 : (y1 - side);
                    } else {
                        selection.x = Math.min(x1, x2);
                        selection.y = Math.min(y1, y2);
                        selection.width = Math.abs(x2 - x1);
                        selection.height = Math.abs(y2 - y1);
                    }
                } else if (cropperState.mode === 'move') {
                    const deltaX = pos.x - cropperState.startX;
                    const deltaY = pos.y - cropperState.startY;
                    selection.x = clamp(selection.x + deltaX, 0, maxX - selection.width);
                    selection.y = clamp(selection.y + deltaY, 0, maxY - selection.height);
                    cropperState.startX = pos.x;
                    cropperState.startY = pos.y;
                } else if (cropperState.mode === 'resize') {
                    const handle = cropperState.handle;

                    if (cropperState.enforceSquare) {
                        applySquareResize(handle);
                        updateSelectionBox(selection);
                        return;
                    }

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

                if (targetInputId === 'bitstream-rebit-attachment-id' && rebitOgImageInput) {
                    rebitOgImageInput.value = attachment.url || '';
                }

                renderMediaPreview(targetPreview, attachment);
                setRemoveVisibility(targetInputId);
                setCropVisibility(targetInputId, attachment.mime || '');
                setAudioTagVisibility(targetInputId, attachment.mime || '');

                if (targetInputId === 'bitstream-rebit-attachment-id') {
                    if (rebitOgImageRemovedInput) {
                        rebitOgImageRemovedInput.value = '0';
                    }
                    refreshRebitEditorImagePreview();
                    refreshRebitPreview();
                }
            }

            function uploadMediaFile(file, targetInputId, targetPreviewId) {
                if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                    setStatus('Media upload is unavailable.', true);
                    return;
                }

                const progressContainer = document.querySelector(`[data-progress-bar="${targetInputId}"]`);
                const progressBar = progressContainer ? progressContainer.querySelector('.bitstream-media-progress-bar') : null;
                const progressText = progressContainer ? progressContainer.querySelector('.bitstream-media-progress-text') : null;

                const showProgress = () => {
                    if (!progressContainer) {
                        return;
                    }
                    progressContainer.classList.remove('is-hidden');
                    if (progressBar) progressBar.style.width = '0%';
                    if (progressText) progressText.textContent = 'Uploading...';
                };

                const updateProgress = (percent, text) => {
                    if (progressBar) {
                        progressBar.style.width = percent + '%';
                    }
                    if (progressText && text) {
                        progressText.textContent = text;
                    }
                };

                const hideProgress = () => {
                    if (progressContainer) {
                        progressContainer.classList.add('is-hidden');
                    }
                };

                showProgress();

                const formData = new FormData();
                formData.append('action', 'bitstream_upload_media');
                formData.append('nonce', bitstream_ajax.media_upload_nonce);
                formData.append('media', file);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', bitstream_ajax.ajax_url, true);
                xhr.withCredentials = true;

                xhr.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        const percent = Math.max(1, Math.round((event.loaded / event.total) * 100));
                        updateProgress(percent, 'Uploading... ' + percent + '%');
                    } else {
                        updateProgress(50, 'Uploading...');
                    }
                });

                xhr.onreadystatechange = () => {
                    if (xhr.readyState !== 4) {
                        return;
                    }

                    if (xhr.status < 200 || xhr.status >= 300) {
                        hideProgress();
                        setStatus('Upload failed.', true);
                        return;
                    }

                    let response;
                    try {
                        response = JSON.parse(xhr.responseText || '{}');
                    } catch (error) {
                        hideProgress();
                        setStatus('Upload failed.', true);
                        return;
                    }

                    if (!response.success) {
                        hideProgress();
                        setStatus(response.data || 'Upload failed.', true);
                        return;
                    }

                    updateProgress(100, 'Upload complete!');

                    const media = response.data || {};
                    handleMediaSelection(targetInputId, targetPreviewId, {
                        id: media.id,
                        url: media.url,
                        mime: media.mime,
                        audio_meta: media.audio_meta || null,
                        sizes: {
                            medium: { url: media.url }
                        }
                    });

                    setTimeout(() => {
                        hideProgress();
                    }, 1000);
                };

                xhr.onerror = () => {
                    hideProgress();
                    setStatus('Upload failed.', true);
                };

                xhr.send(formData);
            }

            function uploadClipboardImage(targetInputId, targetPreviewId) {
                if (!navigator.clipboard || typeof navigator.clipboard.read !== 'function') {
                    setStatus('Clipboard paste is unavailable in this browser.', true);
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
                            uploadMediaFile(file, targetInputId, targetPreviewId);
                            setStatus('Uploading pasted image...');
                        });
                    })
                    .catch(error => {
                        setStatus(error.message || 'Clipboard permission denied. Allow clipboard access in browser settings and try again.', true);
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

                    if ((button.dataset.targetInput || '') === 'bitstream-rebit-attachment-id') {
                        if (rebitOgImageRemovedInput) {
                            rebitOgImageRemovedInput.value = '1';
                        }
                        refreshRebitEditorImagePreview();
                        refreshRebitPreview();
                    }

                    if (button.dataset.targetInput) {
                        setRemoveVisibility(button.dataset.targetInput);
                        setCropVisibility(button.dataset.targetInput, '');
                        setAudioTagVisibility(button.dataset.targetInput, '');
                    }
                });
            });

            pasteButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const targetInputId = button.dataset.targetInput || '';
                    const targetPreviewId = button.dataset.targetPreview || '';
                    if (!targetInputId || !targetPreviewId) {
                        setStatus('Paste target is not configured.', true);
                        return;
                    }
                    uploadClipboardImage(targetInputId, targetPreviewId);
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

            audioTagLinks.forEach(link => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    if (link.classList.contains('is-hidden')) {
                        return;
                    }
                    const targetInputId = link.dataset.targetInput;
                    const targetPreviewId = link.dataset.targetPreview || '';
                    openAudioTagsModal(targetInputId, targetPreviewId);
                });
            });

            if (audioTagsCloseButtons) {
                audioTagsCloseButtons.forEach(button => {
                    button.addEventListener('click', closeAudioTagsModal);
                });
            }

            if (audioTagsSelectButton) {
                audioTagsSelectButton.addEventListener('click', () => {
                    if (!window.wp || !wp.media) {
                        setStatus('Media library is unavailable.', true);
                        return;
                    }

                    const frame = wp.media({
                        title: 'Select artwork',
                        button: { text: 'Use artwork' },
                        multiple: false,
                        library: { type: 'image' }
                    });

                    frame.on('select', () => {
                        const selection = frame.state().get('selection').first();
                        if (!selection) {
                            return;
                        }
                        const data = selection.toJSON();
                        const selectedArtworkId = data.id || 0;
                        if (!selectedArtworkId) {
                            return;
                        }

                        openCropper('', '', {
                            attachmentId: selectedArtworkId,
                            enforceSquare: true,
                            onComplete: (croppedMedia, croppedUrl) => {
                                audioTagsArtworkId = croppedMedia && croppedMedia.id ? croppedMedia.id : selectedArtworkId;
                                audioTagsArtworkUrl = croppedUrl || (data.url || '');
                                audioTagsArtworkCleared = false;
                                updateAudioArtworkPreview(audioTagsArtworkUrl);
                            }
                        });
                    });

                    frame.open();
                });
            }

            if (audioTagsClearButton) {
                audioTagsClearButton.addEventListener('click', () => {
                    audioTagsArtworkId = 0;
                    audioTagsArtworkUrl = '';
                    audioTagsArtworkCleared = true;
                    updateAudioArtworkPreview('');
                });
            }

            if (audioTagsSaveButton) {
                audioTagsSaveButton.addEventListener('click', () => {
                    const input = document.getElementById(audioTagsTargetInputId);
                    const attachmentId = input ? parseInt(input.value || '0', 10) : 0;
                    if (!attachmentId) {
                        setStatus('Select an audio file first.', true);
                        return;
                    }

                    if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.audio_meta_nonce) {
                        setStatus('Audio tag editor is unavailable.', true);
                        return;
                    }

                    const payload = new FormData();
                    payload.append('action', 'bitstream_update_audio_meta');
                    payload.append('nonce', bitstream_ajax.audio_meta_nonce);
                    payload.append('attachment_id', attachmentId);

                    audioTagInputs.forEach(inputEl => {
                        const field = inputEl.dataset.audioTagsField;
                        if (field) {
                            payload.append(field, inputEl.value || '');
                        }
                    });

                    payload.append('artwork_id', audioTagsArtworkId || 0);
                    payload.append('artwork_url', audioTagsArtworkUrl || '');
                    payload.append('artwork_clear', audioTagsArtworkCleared ? '1' : '0');

                    fetch(bitstream_ajax.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: payload
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.data || 'Unable to save audio tags.');
                            }

                            const meta = data.data && data.data.meta ? data.data.meta : {};
                            const previewEl = audioTagsTargetPreviewId
                                ? document.getElementById(audioTagsTargetPreviewId)
                                : null;
                            if (previewEl) {
                                const url = previewEl.dataset.attachmentUrl || '';
                                const mime = previewEl.dataset.attachmentMime || 'audio/mpeg';
                                renderMediaPreview(previewEl, {
                                    id: attachmentId,
                                    url: url,
                                    mime: mime,
                                    audio_meta: meta,
                                    sizes: { medium: { url: url } }
                                });
                            }

                            closeAudioTagsModal();
                        })
                        .catch(error => {
                            setStatus(error.message || 'Unable to save audio tags.', true);
                        });
                });
            }

            if (cropperStage && cropperSelection) {
                if (window.PointerEvent) {
                    cropperStage.addEventListener('pointerdown', beginSelection);
                    cropperSelection.addEventListener('pointerdown', beginSelection);
                    document.addEventListener('pointermove', updateSelection);
                    document.addEventListener('pointerup', endSelection);
                    document.addEventListener('pointercancel', endSelection);
                } else {
                    cropperStage.addEventListener('mousedown', beginSelection);
                    cropperSelection.addEventListener('mousedown', beginSelection);
                    document.addEventListener('mousemove', updateSelection);
                    document.addEventListener('mouseup', endSelection);

                    cropperStage.addEventListener('touchstart', beginSelection, { passive: false });
                    cropperSelection.addEventListener('touchstart', beginSelection, { passive: false });
                    document.addEventListener('touchmove', updateSelection, { passive: false });
                    document.addEventListener('touchend', endSelection);
                    document.addEventListener('touchcancel', endSelection);
                }
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
                    let selection = cropperState.selection;

                    if (cropperState.enforceSquare) {
                        const side = Math.max(10, Math.min(selection.width, selection.height));
                        const offsetX = (selection.width - side) / 2;
                        const offsetY = (selection.height - side) / 2;
                        selection = {
                            x: selection.x + offsetX,
                            y: selection.y + offsetY,
                            width: side,
                            height: side
                        };
                    }

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

                            const croppedMedia = {
                                id: media.id,
                                url: url,
                                mime: media.mime,
                                sizes: { medium: { url: url } }
                            };

                            if (cropperState.targetInputId === 'bitstream-rebit-attachment-id') {
                                if (rebitOgImageInput) {
                                    rebitOgImageInput.value = url || '';
                                }
                                if (rebitOgImageRemovedInput) {
                                    rebitOgImageRemovedInput.value = '0';
                                }
                            }

                            if (typeof cropperState.onComplete === 'function') {
                                cropperState.onComplete(croppedMedia, url);
                            } else {
                                handleMediaSelection(cropperState.targetInputId, cropperState.targetPreviewId, croppedMedia);
                            }

                            if (cropperState.targetInputId === 'bitstream-rebit-attachment-id') {
                                refreshRebitEditorImagePreview();
                            }

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

            function getActivePosterMediaTarget() {
                const activePanel = posterRoot.querySelector('.bitstream-poster-panel.is-active');
                if (!activePanel) {
                    return null;
                }

                if (activePanel.id === 'bitstream-poster-panel-rebit') {
                    return {
                        targetInputId: 'bitstream-rebit-attachment-id',
                        targetPreviewId: 'bitstream-rebit-media-preview',
                        label: 'Rebit image'
                    };
                }

                if (activePanel.id === 'bitstream-poster-panel-bit') {
                    return {
                        targetInputId: 'bitstream-bit-attachment-id',
                        targetPreviewId: 'bitstream-bit-media-preview',
                        label: 'Bit media'
                    };
                }

                return null;
            }

            posterRoot.addEventListener('paste', (event) => {
                const clipboard = event.clipboardData;
                if (!clipboard || !clipboard.items || !clipboard.items.length) {
                    return;
                }

                let imageFile = null;
                for (let i = 0; i < clipboard.items.length; i++) {
                    const item = clipboard.items[i];
                    if (item && item.type && item.type.indexOf('image/') === 0) {
                        imageFile = item.getAsFile();
                        if (imageFile) {
                            break;
                        }
                    }
                }

                if (!imageFile) {
                    return;
                }

                const mediaTarget = getActivePosterMediaTarget();
                if (!mediaTarget) {
                    setStatus('Open Bit or Rebit tab to paste an image.', true);
                    return;
                }

                event.preventDefault();
                uploadMediaFile(imageFile, mediaTarget.targetInputId, mediaTarget.targetPreviewId);
                setStatus('Uploading pasted image...');
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
                    setAudioTagVisibility(input.id, attachment.get('mime'));
                }).catch(() => {});
            });

            function setRebitEditorPreviewImage(url) {
                if (!rebitEditorImagePreview) {
                    return;
                }

                if (url) {
                    rebitEditorImagePreview.src = url;
                    rebitEditorImagePreview.hidden = false;
                } else {
                    rebitEditorImagePreview.src = '';
                    rebitEditorImagePreview.hidden = true;
                }
            }

            function updateRebitEditorImagePreview() {
                const input = document.getElementById('bitstream-rebit-attachment-id');
                const attachmentId = input ? parseInt(input.value || '0', 10) : 0;
                const fallbackUrl = rebitOgImageInput ? (rebitOgImageInput.value || '') : '';
                const internalPreview = document.getElementById('bitstream-rebit-media-preview');
                const latestPreviewUrl = internalPreview ? (internalPreview.dataset.attachmentUrl || '') : '';

                if (attachmentId > 0 && window.wp && wp.media) {
                    const attachment = wp.media.attachment(attachmentId);
                    attachment.fetch().then(() => {
                        const data = attachment.toJSON();
                        const previewUrl = latestPreviewUrl || (data.sizes && data.sizes.medium && data.sizes.medium.url) || data.url || fallbackUrl;
                        setRebitEditorPreviewImage(previewUrl || '');
                        if (rebitEditorImageCropButton) {
                            rebitEditorImageCropButton.disabled = false;
                        }
                    }).catch(() => {
                        setRebitEditorPreviewImage(latestPreviewUrl || fallbackUrl);
                        if (rebitEditorImageCropButton) {
                            rebitEditorImageCropButton.disabled = false;
                        }
                    });
                    return;
                }

                setRebitEditorPreviewImage(latestPreviewUrl || fallbackUrl);
                if (rebitEditorImageCropButton) {
                    rebitEditorImageCropButton.disabled = false;
                }
            }

            refreshRebitEditorImagePreview = updateRebitEditorImagePreview;

            if (rebitEditorImageSelectButton) {
                rebitEditorImageSelectButton.addEventListener('click', () => {
                    if (!window.wp || !wp.media) {
                        setStatus('Media library is unavailable.', true);
                        return;
                    }

                    const frame = wp.media({
                        title: 'Select image',
                        button: { text: 'Use image' },
                        multiple: false,
                        library: { type: 'image' }
                    });

                    frame.on('select', () => {
                        const selection = frame.state().get('selection').first();
                        if (!selection) {
                            return;
                        }

                        const data = selection.toJSON();
                        handleMediaSelection('bitstream-rebit-attachment-id', 'bitstream-rebit-media-preview', {
                            id: data.id,
                            url: data.url,
                            mime: data.mime || 'image/jpeg',
                            sizes: data.sizes || { medium: { url: data.url } }
                        });
                        if (rebitOgImageRemovedInput) {
                            rebitOgImageRemovedInput.value = '0';
                        }
                        updateRebitEditorImagePreview();
                    });

                    frame.open();
                });
            }

            if (rebitEditorImageCropButton) {
                rebitEditorImageCropButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    const input = document.getElementById('bitstream-rebit-attachment-id');
                    const attachmentId = input ? parseInt(input.value || '0', 10) : 0;
                    const fetchedImageUrl = rebitOgImageInput ? (rebitOgImageInput.value || '').trim() : '';

                    const openCropForAttachment = () => {
                        openCropper('bitstream-rebit-attachment-id', 'bitstream-rebit-media-preview', {
                            enforceSquare: false,
                            onComplete: (croppedMedia) => {
                                handleMediaSelection('bitstream-rebit-attachment-id', 'bitstream-rebit-media-preview', croppedMedia);
                                if (rebitOgImageRemovedInput) {
                                    rebitOgImageRemovedInput.value = '0';
                                }
                                updateRebitEditorImagePreview();
                            }
                        });
                    };

                    if (!attachmentId) {
                        if (fetchedImageUrl) {
                            if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                                setStatus('Image cropper is unavailable.', true);
                                return;
                            }

                            const payload = new FormData();
                            payload.append('action', 'bitstream_prepare_rebit_image_for_crop');
                            payload.append('nonce', bitstream_ajax.media_upload_nonce);
                            payload.append('image_url', fetchedImageUrl);

                            setStatus('Preparing fetched image for crop...');

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
                                    handleMediaSelection('bitstream-rebit-attachment-id', 'bitstream-rebit-media-preview', {
                                        id: prepared.id,
                                        url: prepared.url,
                                        mime: prepared.mime || 'image/jpeg',
                                        sizes: { medium: { url: prepared.url } }
                                    });

                                    if (rebitOgImageRemovedInput) {
                                        rebitOgImageRemovedInput.value = '0';
                                    }

                                    updateRebitEditorImagePreview();
                                    openCropForAttachment();
                                })
                                .catch(error => {
                                    setStatus(error.message || 'Could not prepare image for crop.', true);
                                });
                            return;
                        }

                        setStatus('Choose an image first.', true);
                        if (rebitEditorImageSelectButton) {
                            rebitEditorImageSelectButton.click();
                        }
                        return;
                    }

                    openCropForAttachment();
                });
            }

            if (rebitEditorImageClearButton) {
                rebitEditorImageClearButton.addEventListener('click', () => {
                    const input = document.getElementById('bitstream-rebit-attachment-id');
                    const preview = document.getElementById('bitstream-rebit-media-preview');
                    if (input) {
                        input.value = '';
                    }
                    if (rebitOgImageInput) {
                        rebitOgImageInput.value = '';
                    }
                    if (rebitOgImageRemovedInput) {
                        rebitOgImageRemovedInput.value = '1';
                    }
                    renderMediaPreview(preview, null);
                    setRemoveVisibility('bitstream-rebit-attachment-id');
                    setCropVisibility('bitstream-rebit-attachment-id', '');
                    setAudioTagVisibility('bitstream-rebit-attachment-id', '');
                    updateRebitEditorImagePreview();
                    refreshRebitPreview();
                });
            }

            updateRebitEditorImagePreview();
        }

        function initRebitPreview() {
            const urlInput = posterRoot.querySelector('#bitstream-rebit-url');
            const fetchButton = posterRoot.querySelector('.bitstream-fetch-og');
            const fetchButtonDefaultLabel = (fetchButton && fetchButton.textContent)
                ? fetchButton.textContent.trim()
                : 'Fetch metadata';
            const editPreviewActions = posterRoot.querySelector('.bitstream-rebit-preview-actions');
            const editButton = posterRoot.querySelector('.bitstream-rebit-edit-preview');
            const refreshButton = posterRoot.querySelector('.bitstream-rebit-refresh-preview');
            const livePreviewRoot = posterRoot.querySelector('#bitstream-rebit-live-preview');
            const livePreviewLoading = livePreviewRoot ? livePreviewRoot.querySelector('.bitstream-rebit-live-preview-loading') : null;
            const livePreviewCard = livePreviewRoot ? livePreviewRoot.querySelector('.bitstream-rebit-live-preview-card') : null;
            let isRenderingLivePreview = false;
            let isFetchingMetadata = false;
            let hasFetchedMetadata = false;
            let hasPendingPreviewRender = false;
            let commentaryPreviewDebounceTimer = null;

            const commentaryInput = posterRoot.querySelector('#bitstream-rebit-commentary');
            const titleHidden = posterRoot.querySelector('#bitstream-rebit-og-title');
            const descHidden = posterRoot.querySelector('#bitstream-rebit-og-desc');
            const imageHidden = posterRoot.querySelector('#bitstream-rebit-og-image');
            const imageRemovedHidden = posterRoot.querySelector('#bitstream-rebit-og-image-removed');
            const attachmentHidden = posterRoot.querySelector('#bitstream-rebit-attachment-id');
            const editPostHidden = posterRoot.querySelector('#bitstream-poster-panel-rebit input[name="edit_post_id"]');
            const isRebitEditMode = !!(editPostHidden && parseInt(editPostHidden.value || '0', 10) > 0);

            const rebitModal = posterRoot.querySelector('.bitstream-rebit-editor-modal');
            const modalCloseButtons = rebitModal ? rebitModal.querySelectorAll('[data-rebit-editor-close="true"]') : [];
            const modalSaveButton = rebitModal ? rebitModal.querySelector('.bitstream-rebit-editor-save') : null;
            const modalSaveDefaultLabel = (modalSaveButton && modalSaveButton.textContent)
                ? modalSaveButton.textContent.trim()
                : 'Save';
            const modalTitle = rebitModal ? rebitModal.querySelector('#bitstream-rebit-modal-og-title') : null;
            const modalDesc = rebitModal ? rebitModal.querySelector('#bitstream-rebit-modal-og-desc') : null;

            function setMetadataReadyState(isReady) {
                hasFetchedMetadata = !!isReady;
                if (editPreviewActions) {
                    editPreviewActions.hidden = !hasFetchedMetadata;
                }
            }

            function clearLivePreview() {
                if (livePreviewCard) {
                    livePreviewCard.innerHTML = '';
                }
                if (livePreviewLoading) {
                    livePreviewLoading.hidden = true;
                }
                if (livePreviewRoot) {
                    livePreviewRoot.hidden = true;
                }
            }

            function setLivePreviewLoadingState(isLoading) {
                isRenderingLivePreview = !!isLoading;
                updateActionButtonsState();
            }

            function setMetadataLoadingState(isLoading) {
                isFetchingMetadata = !!isLoading;
                updateActionButtonsState();
            }

            function updateActionButtonsState() {
                const hasLoading = isRenderingLivePreview || isFetchingMetadata;

                if (editButton) {
                    editButton.disabled = hasLoading;
                    editButton.setAttribute('aria-busy', hasLoading ? 'true' : 'false');
                }

                if (refreshButton) {
                    refreshButton.disabled = hasLoading || !hasFetchedMetadata;
                    refreshButton.setAttribute('aria-busy', isRenderingLivePreview ? 'true' : 'false');
                }

                if (fetchButton) {
                    fetchButton.disabled = hasLoading;
                    fetchButton.setAttribute('aria-busy', isFetchingMetadata ? 'true' : 'false');
                    fetchButton.classList.toggle('is-loading', isFetchingMetadata);
                    fetchButton.textContent = isFetchingMetadata ? 'Fetching metadata' : fetchButtonDefaultLabel;
                }

                if (modalSaveButton) {
                    modalSaveButton.disabled = hasLoading;
                    modalSaveButton.setAttribute('aria-busy', isRenderingLivePreview ? 'true' : 'false');
                    modalSaveButton.classList.toggle('is-loading', isRenderingLivePreview);
                    modalSaveButton.textContent = isRenderingLivePreview ? 'Saving' : modalSaveDefaultLabel;
                }
            }

            function syncModalFromHidden() {
                if (modalTitle && titleHidden) {
                    modalTitle.value = titleHidden.value || '';
                }
                if (modalDesc && descHidden) {
                    modalDesc.value = descHidden.value || '';
                }
                refreshRebitEditorImagePreview();
            }

            function syncHiddenFromModal() {
                if (modalTitle && titleHidden) {
                    titleHidden.value = modalTitle.value || '';
                }
                if (modalDesc && descHidden) {
                    descHidden.value = modalDesc.value || '';
                }
            }

            function closeRebitModal() {
                if (!rebitModal) {
                    return;
                }
                rebitModal.hidden = true;
            }

            function renderLiveRebitPreview() {
                if (isRenderingLivePreview) {
                    hasPendingPreviewRender = true;
                    return;
                }

                const url = (urlInput && urlInput.value) ? urlInput.value.trim() : '';
                if (!url) {
                    setLivePreviewLoadingState(false);
                    clearLivePreview();
                    return;
                }

                if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.og_fetch_nonce) {
                    setLivePreviewLoadingState(false);
                    setStatus('Live preview is unavailable.', true);
                    return;
                }

                const payload = new FormData();
                payload.append('action', 'bitstream_render_rebit_preview');
                payload.append('nonce', bitstream_ajax.og_fetch_nonce);
                payload.append('rebit_url', url);
                payload.append('rebit_commentary', (commentaryInput && commentaryInput.value) ? commentaryInput.value : '');
                payload.append('rebit_og_title', (titleHidden && titleHidden.value) ? titleHidden.value : '');
                payload.append('rebit_og_desc', (descHidden && descHidden.value) ? descHidden.value : '');
                payload.append('rebit_og_image', (imageHidden && imageHidden.value) ? imageHidden.value : '');
                payload.append('rebit_og_image_removed', (imageRemovedHidden && imageRemovedHidden.value) ? imageRemovedHidden.value : '0');
                payload.append('rebit_attachment_id', (attachmentHidden && attachmentHidden.value) ? attachmentHidden.value : '');

                if (livePreviewRoot) {
                    livePreviewRoot.hidden = false;
                }
                if (livePreviewLoading) {
                    livePreviewLoading.hidden = false;
                }
                setLivePreviewLoadingState(true);

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Could not render preview.');
                        }

                        const responseData = data.data || {};
                        if (responseData.og && imageHidden) {
                            imageHidden.value = responseData.og.image || '';
                        }
                        if (livePreviewCard) {
                            livePreviewCard.innerHTML = responseData.rendered_html || '';
                            applyMediaDeterrents(livePreviewCard);
                        }
                        if (livePreviewLoading) {
                            livePreviewLoading.hidden = true;
                        }
                        if (livePreviewRoot) {
                            livePreviewRoot.hidden = false;
                        }
                        setMetadataReadyState(true);
                    })
                    .catch(error => {
                        clearLivePreview();
                        setStatus(error.message || 'Could not render preview.', true);
                    })
                    .finally(() => {
                        setLivePreviewLoadingState(false);
                        if (hasPendingPreviewRender) {
                            hasPendingPreviewRender = false;
                            renderLiveRebitPreview();
                        }
                    });
            }

            function queueLiveRebitPreviewRender(delayMs = 0) {
                if (commentaryPreviewDebounceTimer) {
                    clearTimeout(commentaryPreviewDebounceTimer);
                    commentaryPreviewDebounceTimer = null;
                }

                if (delayMs > 0) {
                    commentaryPreviewDebounceTimer = setTimeout(() => {
                        commentaryPreviewDebounceTimer = null;
                        renderLiveRebitPreview();
                    }, delayMs);
                    return;
                }

                renderLiveRebitPreview();
            }

            refreshRebitPreview = () => queueLiveRebitPreviewRender(0);

            if (editButton) {
                editButton.addEventListener('click', () => {
                    if (isRenderingLivePreview || !hasFetchedMetadata) {
                        return;
                    }
                    syncModalFromHidden();
                    if (rebitModal) {
                        rebitModal.hidden = false;
                    }
                });
            }

            if (refreshButton) {
                refreshButton.addEventListener('click', () => {
                    if (!hasFetchedMetadata) {
                        setStatus('Fetch metadata first.', true);
                        return;
                    }
                    queueLiveRebitPreviewRender(0);
                });
            }

            if (modalCloseButtons && modalCloseButtons.length) {
                modalCloseButtons.forEach(button => {
                    button.addEventListener('click', closeRebitModal);
                });
            }

            if (modalSaveButton) {
                modalSaveButton.addEventListener('click', () => {
                    syncHiddenFromModal();
                    closeRebitModal();
                    queueLiveRebitPreviewRender(0);
                    setStatus('Preview updated.');
                });
            }

            if (fetchButton) {
                fetchButton.addEventListener('click', () => {
                    if (isFetchingMetadata || isRenderingLivePreview) {
                        return;
                    }

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

                    setMetadataLoadingState(true);
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
                            if (titleHidden) {
                                titleHidden.value = meta.title || '';
                            }
                            if (descHidden) {
                                descHidden.value = meta.description || '';
                            }
                            if (imageHidden) {
                                imageHidden.value = meta.image || '';
                            }
                            if (imageRemovedHidden) {
                                imageRemovedHidden.value = '0';
                            }

                            syncModalFromHidden();
                            refreshRebitEditorImagePreview();
                            queueLiveRebitPreviewRender(0);
                            setStatus('Metadata loaded.');
                        })
                        .catch(error => {
                            setStatus(error.message || 'Could not fetch metadata.', true);
                        })
                        .finally(() => {
                            setMetadataLoadingState(false);
                        });
                });

                const hasPrefilledUrl = urlInput && urlInput.value && urlInput.value.trim();
                if (hasPrefilledUrl && !isRebitEditMode) {
                    fetchButton.click();
                }

                if (hasPrefilledUrl && isRebitEditMode) {
                    setMetadataReadyState(true);
                    syncModalFromHidden();
                    refreshRebitEditorImagePreview();
                    queueLiveRebitPreviewRender(0);
                }
            }

            if (urlInput) {
                urlInput.addEventListener('input', () => {
                    clearLivePreview();
                    setMetadataReadyState(false);
                });
            }

            if (commentaryInput) {
                commentaryInput.addEventListener('input', () => {
                    if (hasFetchedMetadata) {
                        queueLiveRebitPreviewRender(550);
                    }
                });
            }

            setMetadataReadyState(false);
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
                const editPostInput = form.querySelector('input[name="edit_post_id"]');
                payload.set('edit_post_id', editPostInput ? (editPostInput.value || '0') : '0');

                const posterType = form.dataset.posterType || 'bit';
                const scheduleEnabledInput = form.querySelector('[name="' + posterType + '_schedule_enabled"]');
                const scheduleDatetimeInput = form.querySelector('[name="' + posterType + '_schedule_datetime"]');
                if (scheduleEnabledInput && scheduleEnabledInput.value === '1' && scheduleDatetimeInput && !scheduleDatetimeInput.value) {
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

                        const createdPostId = parseInt(responseData.post_id || '0', 10);
                        const isScheduled = !!responseData.is_scheduled;

                        if (isScheduled) {
                            const posterBaseUrl = (window.bitstream_ajax && bitstream_ajax.poster_url)
                                ? bitstream_ajax.poster_url
                                : window.location.href;
                            const redirectUrl = new URL(posterBaseUrl, window.location.origin);
                            redirectUrl.searchParams.set('poster_tab', 'scheduled');
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
                        return;

                        form.reset();
                        form.querySelectorAll('.bitstream-media-preview').forEach(previewEl => {
                            previewEl.innerHTML = '';
                        });

                        if ((form.dataset.posterType || '') === 'rebit') {
                            const livePreviewRoot = posterRoot.querySelector('#bitstream-rebit-live-preview');
                            if (livePreviewRoot) {
                                livePreviewRoot.hidden = false;
                            }
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
        initRebitPreview();
    }

    initBitstreamPoster();
    highlightFromQueryParams();
    applyMediaDeterrents(document);
    
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

    // Edit button functionality
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.bit-edit');
        if (!button) {
            return;
        }

        event.preventDefault();

        const postId = parseInt(button.dataset.postId || '0', 10);
        if (!postId) {
            return;
        }

        const postType = (button.dataset.postType === 'rebit') ? 'rebit' : 'bit';
        const basePosterUrl = (window.bitstream_ajax && bitstream_ajax.poster_url)
            ? bitstream_ajax.poster_url
            : (window.location.origin + '/bitstream/');
        const editUrl = new URL(basePosterUrl, window.location.origin);
        editUrl.searchParams.set('poster_tab', postType);
        editUrl.searchParams.set('edit_post_id', String(postId));

        window.location.href = editUrl.toString();

        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.remove('pulse');
            void icon.offsetWidth;
            icon.classList.add('pulse');
            setTimeout(() => icon.classList.remove('pulse'), 300);
        }
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

            const confirmed = window.confirm('Are you sure you want to delete this Bit? This action cannot be undone.');
            if (!confirmed) {
                return;
            }

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
                            }, 350);
                        }, 3000);
                    }
                })
                .catch(error => {
                    alert(error.message || 'Could not delete post.');
                    button.classList.remove('is-active');
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

    // Media Session metadata for PWA lock-screen/live notifications
    let activeMediaElement = null;
    let mediaSessionHandlersBound = false;

    function getSiteName() {
        const ogSiteName = document.querySelector('meta[property="og:site_name"]');
        return (ogSiteName && ogSiteName.content) ? ogSiteName.content : 'BitStream';
    }

    function getCardTextTitle(mediaEl) {
        const card = mediaEl.closest('.bit-card, .bitstream-media-preview, .bitstream-poster');
        if (!card) {
            return '';
        }

        const explicitTitle = card.querySelector('.bitstream-audio-title, .bitstream-rebit-preview-title');
        if (explicitTitle && explicitTitle.textContent) {
            return explicitTitle.textContent.trim();
        }

        const content = card.querySelector('.bit-card-content, textarea, p');
        if (!content || !content.textContent) {
            return '';
        }

        const text = content.textContent.trim().replace(/\s+/g, ' ');
        return text.length > 80 ? (text.slice(0, 77) + '...') : text;
    }

    function buildArtworkList(src) {
        if (!src) {
            return [];
        }

        const sizes = ['96x96', '128x128', '192x192', '256x256', '384x384', '512x512'];
        return sizes.map(size => ({ src, sizes: size, type: 'image/png' }));
    }

    function sanitizeVideoTitleFromBitContent(rawTitle, mediaEl) {
        if (!rawTitle) {
            return '';
        }

        let cleaned = rawTitle;
        const sourceValues = new Set();

        if (mediaEl.currentSrc) {
            sourceValues.add(mediaEl.currentSrc);
        }

        const directSrc = mediaEl.getAttribute('src');
        if (directSrc) {
            sourceValues.add(directSrc);
        }

        mediaEl.querySelectorAll('source[src]').forEach(sourceEl => {
            const sourceSrc = sourceEl.getAttribute('src');
            if (sourceSrc) {
                sourceValues.add(sourceSrc);
            }
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
            const posterSrc = mediaEl.getAttribute('poster') || '';
            const fallbackImage = mediaEl.closest('.bit-card, .bitstream-media-preview, .bitstream-poster')
                ?.querySelector('img')
                ?.getAttribute('src') || '';
            const bitContentTitle = sanitizeVideoTitleFromBitContent(getCardTextTitle(mediaEl), mediaEl);

            const title = mediaEl.getAttribute('title') || bitContentTitle || 'Video';

            return {
                title,
                artist: siteName,
                album: 'BitStream',
                artwork: buildArtworkList(posterSrc || fallbackImage),
            };
        }

        return null;
    }

    function setMediaSessionMetadataFor(mediaEl) {
        if (!('mediaSession' in navigator) || typeof window.MediaMetadata !== 'function' || !mediaEl) {
            return;
        }

        const meta = resolveMediaSessionMeta(mediaEl);
        if (!meta) {
            return;
        }

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

    function initMediaSession(scope = document) {
        if (!scope || !scope.querySelectorAll) {
            return;
        }

        if ('mediaSession' in navigator && !mediaSessionHandlersBound) {
            mediaSessionHandlersBound = true;
            try {
                navigator.mediaSession.setActionHandler('play', () => {
                    if (activeMediaElement && typeof activeMediaElement.play === 'function') {
                        activeMediaElement.play().catch(() => {});
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
    
    // Run on page load
    makeEmbedsResponsive();
    initMediaSession(document);
    
    // Run when new content is loaded (for infinite scroll)
    const observer = new MutationObserver(() => {
        makeEmbedsResponsive();
        initMediaSession(document);
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
                        }
                    }
                    
                    // Now toggle the comments section
                    section.classList.toggle('open');
                    
                    if (bitCard) {
                        if (!section.classList.contains('open')) {
                            // Comments were closed - remove z-index boost after animation
                            setTimeout(() => {
                                bitCard.classList.remove('comments-open');
                            }, 450); // Slightly longer than CSS transition duration
                        }
                    }
                }
            });
        });
    }
    
    // Initialize comment toggles
    initCommentToggles();

    // Infinite Scroll & Load More
    const feed = document.querySelector('.bitstream-feed');
    if (!feed) return;

    let loading = false;
    const loadMoreButton = document.getElementById('bitstream-load-more');
    const scrollTrigger = document.querySelector('.bitstream-scroll-trigger');
    const isInfiniteScroll = feed.dataset.infiniteScroll === 'true';

    const sidebarPanels = document.querySelectorAll('.bitstream-feed-sidebar-panel');
    const archiveYears = document.querySelectorAll('.bitstream-archive-year');

    function syncSidebarPanelState() {
        if (!sidebarPanels.length) {
            return;
        }

        const isDesktop = window.innerWidth >= 1024;

        sidebarPanels.forEach(panel => {
            if (isDesktop) {
                panel.open = true;
                return;
            }

            if (panel.dataset.userToggled !== 'true') {
                panel.open = false;
            }
        });
    }

    function syncArchiveYearState() {
        if (!archiveYears.length) {
            return;
        }

        const isDesktop = window.innerWidth >= 1024;

        archiveYears.forEach(year => {
            if (year.dataset.userToggled === 'true') {
                return;
            }

            if (isDesktop) {
                year.open = year.dataset.defaultOpen === '1';
            } else {
                year.open = false;
            }
        });
    }

    sidebarPanels.forEach(panel => {
        panel.addEventListener('toggle', () => {
            panel.dataset.userToggled = 'true';
        });
    });

    archiveYears.forEach(year => {
        year.addEventListener('toggle', () => {
            year.dataset.userToggled = 'true';
        });
    });

    syncSidebarPanelState();
    syncArchiveYearState();
    window.addEventListener('resize', syncSidebarPanelState);
    window.addEventListener('resize', syncArchiveYearState);

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
        formData.append('filter_type', feed.dataset.filterType || 'all');
        formData.append('filter_month', feed.dataset.filterMonth || '');
        formData.append('filter_search', feed.dataset.filterSearch || '');

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

            initCommentToggles();
            
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
