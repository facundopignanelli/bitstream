document.addEventListener('DOMContentLoaded', function () {
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

    const BITSTREAM_IMAGE_UPLOAD_MAX_DIMENSION = 2200;
    const BITSTREAM_IMAGE_UPLOAD_MAX_BYTES = 2.5 * 1024 * 1024;
    const BITSTREAM_IMAGE_UPLOAD_QUALITY = 0.86;
    const BITSTREAM_CHUNKED_UPLOAD_THRESHOLD = 256 * 1024;
    const BITSTREAM_UPLOAD_CHUNK_SIZE = 512 * 1024;

    function getFileExtension(filename) {
        const match = String(filename || '').toLowerCase().match(/\.([a-z0-9]+)$/);
        return match ? match[1] : '';
    }

    function getUploadMimeType(file) {
        if (file && file.type) {
            return file.type;
        }

        const extension = getFileExtension(file && file.name);
        if (['jpg', 'jpeg'].includes(extension)) return 'image/jpeg';
        if (extension === 'png') return 'image/png';
        if (extension === 'gif') return 'image/gif';
        if (extension === 'webp') return 'image/webp';
        if (extension === 'heic') return 'image/heic';
        if (extension === 'heif') return 'image/heif';
        if (extension === 'mp4') return 'video/mp4';
        if (extension === 'mov') return 'video/quicktime';
        if (extension === 'webm') return 'video/webm';

        return '';
    }

    function canvasToBlob(canvas, mimeType, quality) {
        return new Promise(resolve => {
            canvas.toBlob(blob => resolve(blob), mimeType, quality);
        });
    }

    async function loadImageFile(file) {
        if (window.createImageBitmap) {
            try {
                return await createImageBitmap(file, { imageOrientation: 'from-image' });
            } catch (error) {
                // Fall back to an HTMLImageElement decode below.
            }
        }

        return new Promise((resolve, reject) => {
            const image = new Image();
            const objectUrl = URL.createObjectURL(file);
            image.onload = () => {
                URL.revokeObjectURL(objectUrl);
                resolve(image);
            };
            image.onerror = () => {
                URL.revokeObjectURL(objectUrl);
                reject(new Error('Could not read image.'));
            };
            image.src = objectUrl;
        });
    }

    async function prepareMediaFileForUpload(file) {
        const mimeType = getUploadMimeType(file);
        if (!mimeType.startsWith('image/') || mimeType === 'image/gif') {
            return file;
        }

        if (file.size <= BITSTREAM_IMAGE_UPLOAD_MAX_BYTES && !['image/heic', 'image/heif'].includes(mimeType)) {
            return file;
        }

        try {
            const image = await loadImageFile(file);
            const sourceWidth = image.naturalWidth || image.width;
            const sourceHeight = image.naturalHeight || image.height;
            const scale = Math.min(1, BITSTREAM_IMAGE_UPLOAD_MAX_DIMENSION / Math.max(sourceWidth, sourceHeight));
            const targetWidth = Math.max(1, Math.round(sourceWidth * scale));
            const targetHeight = Math.max(1, Math.round(sourceHeight * scale));

            const canvas = document.createElement('canvas');
            canvas.width = targetWidth;
            canvas.height = targetHeight;

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return file;
            }

            ctx.drawImage(image, 0, 0, targetWidth, targetHeight);
            if (typeof image.close === 'function') {
                image.close();
            }

            const outputType = mimeType === 'image/png' && file.size <= (6 * 1024 * 1024) ? 'image/png' : 'image/jpeg';
            const blob = await canvasToBlob(canvas, outputType, outputType === 'image/jpeg' ? BITSTREAM_IMAGE_UPLOAD_QUALITY : undefined);
            if (!blob || blob.size <= 0 || blob.size >= file.size) {
                return file;
            }

            const baseName = String(file.name || 'mobile-upload').replace(/\.[^.]+$/, '');
            const extension = outputType === 'image/png' ? 'png' : 'jpg';
            return new File([blob], baseName + '.' + extension, {
                type: outputType,
                lastModified: Date.now()
            });
        } catch (error) {
            return file;
        }
    }

    function sendAjaxFormData(formData, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', bitstream_ajax.ajax_url, true);
            xhr.withCredentials = true;

            if (typeof onProgress === 'function') {
                xhr.upload.addEventListener('progress', onProgress);
            }

            xhr.onreadystatechange = () => {
                if (xhr.readyState !== 4) {
                    return;
                }

                if (xhr.status < 200 || xhr.status >= 300) {
                    reject(new Error('Upload failed.'));
                    return;
                }

                try {
                    const response = JSON.parse(xhr.responseText || '{}');
                    if (!response.success) {
                        reject(new Error(response.data || 'Upload failed.'));
                        return;
                    }
                    resolve(response.data || {});
                } catch (error) {
                    reject(new Error('Upload failed.'));
                }
            };

            xhr.onerror = () => reject(new Error('Upload failed.'));
            xhr.send(formData);
        });
    }

    function createUploadId() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }

        return 'upload-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    async function uploadMediaRequest(file, updateProgress) {
        if (file.size <= BITSTREAM_CHUNKED_UPLOAD_THRESHOLD) {
            const formData = new FormData();
            formData.append('action', 'bitstream_upload_media');
            formData.append('nonce', bitstream_ajax.media_upload_nonce);
            formData.append('media', file);

            return sendAjaxFormData(formData, event => {
                if (!event.lengthComputable || typeof updateProgress !== 'function') {
                    return;
                }
                const percent = Math.max(1, Math.round((event.loaded / event.total) * 100));
                updateProgress(percent, 'Uploading... ' + percent + '%');
            });
        }

        const totalChunks = Math.ceil(file.size / BITSTREAM_UPLOAD_CHUNK_SIZE);
        const uploadId = createUploadId();
        const mimeType = getUploadMimeType(file);

        for (let index = 0; index < totalChunks; index++) {
            const start = index * BITSTREAM_UPLOAD_CHUNK_SIZE;
            const end = Math.min(file.size, start + BITSTREAM_UPLOAD_CHUNK_SIZE);
            const chunk = file.slice(start, end);
            const formData = new FormData();
            formData.append('action', 'bitstream_upload_media_chunk');
            formData.append('nonce', bitstream_ajax.media_upload_nonce);
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', String(index));
            formData.append('total_chunks', String(totalChunks));
            formData.append('filename', file.name || 'bitstream-upload');
            formData.append('mime', mimeType);
            formData.append('chunk', chunk, (file.name || 'bitstream-upload') + '.part');

            const chunkBasePercent = Math.round((index / totalChunks) * 100);
            const response = await sendAjaxFormData(formData, event => {
                if (!event.lengthComputable || typeof updateProgress !== 'function') {
                    return;
                }
                const chunkPercent = event.loaded / event.total;
                const overallPercent = Math.max(1, Math.min(99, Math.round(((index + chunkPercent) / totalChunks) * 100)));
                updateProgress(overallPercent, 'Uploading... ' + overallPercent + '%');
            });

            if (response && !response.partial) {
                if (typeof updateProgress === 'function') {
                    updateProgress(100, 'Upload complete!');
                }
                return response;
            }

            if (typeof updateProgress === 'function') {
                updateProgress(Math.max(1, chunkBasePercent), 'Uploading... ' + Math.max(1, chunkBasePercent) + '%');
            }
        }

        throw new Error('Upload did not finish.');
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
        const container = event.target.closest('.bitstream-feed, .bitstream-composer');
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
        const openComments = parseInt(params.get('open_comments') || '0', 10);

        if (highlightBit > 0) {
            const bitCard = document.getElementById('bit-' + highlightBit);
            if (bitCard) {
                bitCard.classList.add('bitstream-highlight-target');
            }
        }

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
        const previewUrl = attachment.preview_url || (attachment.sizes && ((attachment.sizes.large && attachment.sizes.large.url) || (attachment.sizes.medium_large && attachment.sizes.medium_large.url) || (attachment.sizes.medium && attachment.sizes.medium.url))) || attachment.url || '';

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

    function renderComposerMediaPreview(previewEl, attachment) {
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
        const previewUrl = attachment.preview_url || attachment.url || '';

        if (attachment.id) {
            previewEl.dataset.attachmentId = attachment.id;
        }
        if (previewUrl) {
            previewEl.dataset.attachmentUrl = previewUrl;
        }
        if (mimeType) {
            previewEl.dataset.attachmentMime = mimeType;
        }

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

    // Continue with the rest of the initialization...
    function initBitstreamPoster() {
        const composerRoot = document.querySelector('.bitstream-composer');
        if (!composerRoot) {
            return;
        }

        const statusEl = composerRoot.querySelector('.bitstream-composer-status');
        const submitNonce = composerRoot.dataset.submitNonce || '';
        let refreshRebitPreview = () => { };
        let refreshRebitEditorImagePreview = () => { };

        function setStatus(message, isError = false) {
            if (!statusEl) {
                return;
            }

            statusEl.textContent = message;
            statusEl.classList.toggle('is-error', isError);
            statusEl.classList.toggle('is-success', !isError && !!message);

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
                    modalStatusEl.textContent = message;
                    modalStatusEl.classList.toggle('is-error', isError);
                    modalStatusEl.classList.toggle('is-success', !isError && !!message);

                    if (!message) {
                        modalStatusEl.textContent = '';
                        modalStatusEl.className = 'bitstream-modal-status bitstream-composer-status';
                    }
                }
            }

            if (!message) {
                document.querySelectorAll('.bitstream-modal-status').forEach(el => {
                    el.textContent = '';
                    el.className = 'bitstream-modal-status bitstream-composer-status';
                });
            }
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

            const composerType = form.dataset.composerType || 'bit';
            let previewRoot = null;
            let previewCard = null;

            if (composerType === 'rebit') {
                previewRoot = composerRoot.querySelector('#bitstream-rebit-live-preview');
                previewCard = previewRoot ? previewRoot.querySelector('.bitstream-rebit-live-preview-card') : null;
            } else {
                previewRoot = form.querySelector('.bitstream-bit-live-preview');
                if (!previewRoot) {
                    previewRoot = document.createElement('div');
                    previewRoot.className = 'bitstream-rebit-live-preview bitstream-bit-live-preview';
                    previewRoot.innerHTML = '<p class="bitstream-rebit-live-preview-label"><strong>Preview</strong></p><div class="bitstream-rebit-live-preview-card"></div>';
                    const submitButton = form.querySelector('.bitstream-composer-submit');
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

        const tabButtons = composerRoot.querySelectorAll('.bitstream-composer-tab');
        const tabPanels = composerRoot.querySelectorAll('.bitstream-composer-panel');

        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const selectedTab = button.dataset.tab;

                // Skip if already on this tab
                if (button.classList.contains('is-active')) {
                    return;
                }

                // Navigate to the tab via URL so the page reloads with
                // fresh server-rendered content (up-to-date drafts, scheduled, etc.)
                const url = new URL(window.location.href);
                url.searchParams.set('composer_tab', selectedTab);
                // Strip any stale highlight params when switching tabs
                url.searchParams.delete('highlight_draft');
                url.searchParams.delete('highlight_scheduled');
                window.location.href = url.toString();
            });
        });

        const scheduleToggles = composerRoot.querySelectorAll('[data-schedule-toggle]');
        scheduleToggles.forEach(toggle => {
            const key = toggle.dataset.scheduleToggle;
            const datetimeInput = composerRoot.querySelector('[data-schedule-input="' + key + '"]');
            const hiddenInput = composerRoot.querySelector('[data-schedule-hidden="' + key + '"]');

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

        // Helper: update "no results" placeholder for a list container + its rows
        function updateFilterEmpty(listEl, rows, filterLabel) {
            const visibleCount = Array.from(rows).filter(r => !r.classList.contains('is-filtered-out')).length;
            let emptyEl = listEl.querySelector('.bitstream-filter-empty');
            if (visibleCount === 0) {
                if (!emptyEl) {
                    emptyEl = document.createElement('p');
                    emptyEl.className = 'bitstream-filter-empty';
                    listEl.appendChild(emptyEl);
                }
                emptyEl.textContent = 'No ' + filterLabel + ' found.';
            } else if (emptyEl) {
                emptyEl.remove();
            }
        }

        // Scheduled filter — scoped to the scheduled panel only

        const scheduledPanel = composerRoot.querySelector('#bitstream-composer-panel-scheduled');
        if (scheduledPanel) {
            const scheduledFilterButtons = scheduledPanel.querySelectorAll('.bitstream-scheduled-filter-btn');
            const scheduledList = scheduledPanel.querySelector('.bitstream-scheduled-list');
            const scheduledRows = scheduledPanel.querySelectorAll('.bitstream-scheduled-item');
            scheduledFilterButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const filter = button.dataset.filter || 'all';

                    scheduledFilterButtons.forEach(btn => {
                        btn.classList.toggle('is-active', btn === button);
                    });

                    scheduledRows.forEach(row => {
                        const type = row.dataset.type || 'bit';
                        row.classList.toggle('is-filtered-out', !(filter === 'all' || filter === type));
                    });

                    if (scheduledList) {
                        const label = filter === 'all' ? 'scheduled posts' : 'scheduled ' + filter + 's';
                        updateFilterEmpty(scheduledList, scheduledRows, label);
                    }
                });
            });
        }

        // Drafts filter — scoped to the drafts panel only
        const draftsPanel = composerRoot.querySelector('#bitstream-composer-panel-drafts');
        if (draftsPanel) {
            const draftsFilterButtons = draftsPanel.querySelectorAll('.bitstream-drafts-filter-btn');
            const draftsList = draftsPanel.querySelector('.bitstream-drafts-list');
            const draftsRows = draftsPanel.querySelectorAll('.bitstream-draft-item');
            draftsFilterButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const filter = button.dataset.filter || 'all';

                    draftsFilterButtons.forEach(btn => {
                        btn.classList.toggle('is-active', btn === button);
                    });

                    draftsRows.forEach(row => {
                        const type = row.dataset.type || 'bit';
                        row.classList.toggle('is-filtered-out', !(filter === 'all' || filter === type));
                    });

                    if (draftsList) {
                        const label = filter === 'all' ? 'drafts' : 'draft ' + filter + 's';
                        updateFilterEmpty(draftsList, draftsRows, label);
                    }
                });
            });
        }

        composerRoot.addEventListener('click', (event) => {
            const deleteButton = event.target.closest('.bitstream-scheduled-delete');
            if (!deleteButton) {
                return;
            }

            event.preventDefault();

            const postId = parseInt(deleteButton.dataset.postId || '0', 10);
            if (!postId) {
                return;
            }

            if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.delete_post_nonce) {
                setStatus('Delete endpoint is unavailable.', true);
                return;
            }

            const isDraft = !!deleteButton.closest('.bitstream-drafts-list');
            const confirmMsg = isDraft
                ? 'Delete this draft?'
                : 'Delete this scheduled Bit before publication?';

            showDeleteConfirmation(confirmMsg, () => {
                deleteButton.disabled = true;
                setStatus(isDraft ? 'Deleting draft...' : 'Deleting scheduled Bit...');

                const payload = new FormData();
                payload.append('action', 'bitstream_delete_post');
                payload.append('post_id', String(postId));
                payload.append('nonce', bitstream_ajax.delete_post_nonce);

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Could not delete.');
                        }

                        const row = deleteButton.closest('.bitstream-scheduled-item');
                        if (row) {
                            row.remove();
                        }

                        // Check both scheduled and drafts lists for empty state
                        const parentList = isDraft
                            ? composerRoot.querySelector('.bitstream-drafts-list')
                            : composerRoot.querySelector('.bitstream-scheduled-list');
                        const remainingRows = parentList ? parentList.querySelectorAll('.bitstream-scheduled-item') : [];
                        if (parentList && remainingRows.length === 0) {
                            parentList.innerHTML = isDraft
                                ? '<p>No draft Bits or Rebits yet.</p>'
                                : '<p>No scheduled Bits or Rebits yet.</p>';
                        }

                        setStatus(isDraft ? 'Draft deleted.' : 'Scheduled Bit deleted.');
                    })
                    .catch(error => {
                        setStatus(error.message || 'Could not delete.', true);
                        deleteButton.disabled = false;
                    });
            });
        });

        function bindMediaButtons() {
            const removeButtons = composerRoot.querySelectorAll('.bitstream-media-remove');
            const cropLinks = composerRoot.querySelectorAll('.bitstream-media-crop');
            const audioTagLinks = composerRoot.querySelectorAll('.bitstream-media-audio-tags');
            const pasteButtons = composerRoot.querySelectorAll('.bitstream-media-paste');
            const libraryButtons = composerRoot.querySelectorAll('.bitstream-media-library');
            const dropzones = composerRoot.querySelectorAll('.bitstream-media-dropzone');
            const cropperModal = composerRoot.querySelector('.bitstream-cropper-modal');
            const cropperImage = cropperModal ? cropperModal.querySelector('.bitstream-cropper-image') : null;
            const cropperSelection = cropperModal ? cropperModal.querySelector('.bitstream-cropper-selection') : null;
            const cropperStage = cropperModal ? cropperModal.querySelector('.bitstream-cropper-stage') : null;
            const cropperApply = cropperModal ? cropperModal.querySelector('.bitstream-cropper-apply') : null;
            const cropperSizeLabel = cropperModal ? cropperModal.querySelector('.bitstream-cropper-size') : null;
            const cropperCloseButtons = cropperModal ? cropperModal.querySelectorAll('[data-cropper-close="true"]') : [];
            const audioTagsModal = composerRoot.querySelector('.bitstream-audio-tags-modal');
            const audioTagsCloseButtons = audioTagsModal ? audioTagsModal.querySelectorAll('[data-audio-tags-close="true"]') : [];
            const audioTagsSelectButton = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-select') : null;
            const audioTagsClearButton = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-clear') : null;
            const audioTagsSaveButton = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-save') : null;
            const audioTagsPreview = audioTagsModal ? audioTagsModal.querySelector('.bitstream-audio-tags-preview') : null;
            const audioTagInputs = audioTagsModal ? audioTagsModal.querySelectorAll('.bitstream-audio-tags-input') : [];
            const rebitEditorImagePreview = composerRoot.querySelector('.bitstream-rebit-editor-image-preview');
            const rebitEditorImageSelectButton = composerRoot.querySelector('.bitstream-rebit-editor-image-select');
            const rebitEditorImageCropButton = composerRoot.querySelector('.bitstream-rebit-editor-image-crop');
            const rebitEditorImageClearButton = composerRoot.querySelector('.bitstream-rebit-editor-image-clear');
            const rebitOgImageInput = composerRoot.querySelector('#bitstream-rebit-og-image');
            const rebitOgImageRemovedInput = composerRoot.querySelector('#bitstream-rebit-og-image-removed');
            let audioTagsTargetInputId = '';
            let audioTagsTargetPreviewId = '';
            let audioTagsArtworkId = 0;
            let audioTagsArtworkUrl = '';
            let audioTagsArtworkCleared = false;
            let cropperState = null;

            function setRemoveVisibility(targetInputId) {
                const input = document.getElementById(targetInputId);
                const removeButton = composerRoot.querySelector('.bitstream-media-remove[data-target-input="' + targetInputId + '"]');
                if (!removeButton) {
                    return;
                }

                const hasValue = input && parseInt(input.value || '0', 10) > 0;
                removeButton.classList.toggle('is-hidden', !hasValue);
            }

            function setCropVisibility(targetInputId, mimeType) {
                const cropLink = composerRoot.querySelector('.bitstream-media-crop[data-target-input="' + targetInputId + '"]');
                if (!cropLink) {
                    return;
                }

                const isImage = mimeType && mimeType.startsWith('image/');
                cropLink.classList.toggle('is-hidden', !isImage);
            }

            function setAudioTagVisibility(targetInputId, mimeType) {
                const tagLink = composerRoot.querySelector('.bitstream-media-audio-tags[data-target-input="' + targetInputId + '"]');
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
                setStatus('');
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
                setStatus('');
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

            // Allow other UI modules (e.g. timeline edit modal) to reuse the shared cropper.
            window.bitstreamOpenCropper = openCropper;

            function openMediaLibrary(targetInputId, targetPreviewId) {
                if (!window.wp || !wp.media) {
                    setStatus('Media library is unavailable.', true);
                    return;
                }

                const frame = wp.media({
                    title: 'Select media',
                    button: { text: 'Use media' },
                    multiple: false,
                    library: { type: ['image', 'video'] }
                });

                frame.on('select', () => {
                    const selection = frame.state().get('selection').first();
                    if (!selection) {
                        return;
                    }

                    const data = selection.toJSON();
                    const mime = data.mime || data.type || '';
                    if (!mime.startsWith('image/') && !mime.startsWith('video/')) {
                        setStatus('Only image and video files are supported.', true);
                        return;
                    }

                    handleMediaSelection(targetInputId, targetPreviewId, {
                        id: data.id,
                        url: data.url,
                        preview_url: data.preview_url || (data.sizes && ((data.sizes.large && data.sizes.large.url) || (data.sizes.medium_large && data.sizes.medium_large.url) || (data.sizes.medium && data.sizes.medium.url))) || data.url,
                        mime: mime,
                        filename: data.filename || data.title || '',
                        title: data.title || '',
                        sizes: data.sizes || { medium: { url: data.preview_url || (data.sizes && ((data.sizes.large && data.sizes.large.url) || (data.sizes.medium_large && data.sizes.medium_large.url) || (data.sizes.medium && data.sizes.medium.url))) || data.url } }
                    });
                });

                frame.open();
            }

            libraryButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const targetInputId = button.dataset.targetInput || '';
                    const targetPreviewId = button.dataset.targetPreview || '';
                    if (!targetInputId || !targetPreviewId) {
                        setStatus('Media target is not configured.', true);
                        return;
                    }

                    openMediaLibrary(targetInputId, targetPreviewId);
                });
            });

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
                setStatus('');
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

            async function uploadMediaFile(file, targetInputId, targetPreviewId) {
                setStatus('');
                if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                    setStatus('Media upload is unavailable.', true);
                    return;
                }

                const mimeType = getUploadMimeType(file);
                if (!mimeType.startsWith('image/') && !mimeType.startsWith('video/')) {
                    setStatus('Unsupported file format. Only images and videos are allowed.', true);
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
                updateProgress(5, mimeType.startsWith('image/') ? 'Preparing image...' : 'Preparing upload...');

                const uploadFile = await prepareMediaFileForUpload(file);
                updateProgress(8, uploadFile.size > BITSTREAM_CHUNKED_UPLOAD_THRESHOLD ? 'Uploading in chunks...' : 'Uploading...');

                uploadMediaRequest(uploadFile, updateProgress)
                    .then(media => {
                    handleMediaSelection(targetInputId, targetPreviewId, {
                        id: media.id,
                        url: media.url,
                        preview_url: media.preview_url || media.url,
                        mime: media.mime,
                        audio_meta: media.audio_meta || null,
                        sizes: {
                            medium: { url: media.preview_url || media.url }
                        }
                    });

                    setTimeout(() => {
                        hideProgress();
                    }, 1000);
                    })
                    .catch(error => {
                        hideProgress();
                        setStatus(error.message || 'Upload failed.', true);
                    });
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
                    const targetPreviewId = link.dataset.targetPreview || (link.dataset.targetInput === 'bitstream-bit-attachment-id'
                        ? 'bitstream-bit-media-preview'
                        : 'bitstream-rebit-media-preview');
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
                const activePanel = composerRoot.querySelector('.bitstream-composer-panel.is-active');
                if (!activePanel) {
                    return null;
                }

                if (activePanel.id === 'bitstream-composer-panel-rebit') {
                    return {
                        targetInputId: 'bitstream-rebit-attachment-id',
                        targetPreviewId: 'bitstream-rebit-media-preview',
                        label: 'Rebit image'
                    };
                }

                if (activePanel.id === 'bitstream-composer-panel-bit') {
                    return {
                        targetInputId: 'bitstream-bit-attachment-id',
                        targetPreviewId: 'bitstream-bit-media-preview',
                        label: 'Bit media'
                    };
                }

                return null;
            }

            composerRoot.addEventListener('paste', (event) => {
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

            function fetchAttachmentData(attachmentId) {
                if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                    return Promise.reject(new Error('AJAX details unavailable'));
                }

                const fd = new FormData();
                fd.append('action', 'bitstream_get_attachment_data');
                fd.append('nonce', bitstream_ajax.media_upload_nonce);
                fd.append('attachment_id', String(attachmentId));

                return fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.data || 'Failed to fetch attachment.');
                    }
                    return data.data;
                });
            }

            const previewMediaThumb = composerRoot.querySelector('.bitstream-composer-preview-media-thumb');
            const existingMediaInputs = composerRoot.querySelectorAll('input[name="bit_attachment_id"], input[name="rebit_attachment_id"]');
            existingMediaInputs.forEach(input => {
                const value = parseInt(input.value || '0', 10);
                if (!value) {
                    setRemoveVisibility(input.id);
                    setCropVisibility(input.id, '');
                    return;
                }

                let previewEl = null;
                if (input.closest('.bitstream-media-field')) {
                    previewEl = input.closest('.bitstream-media-field').querySelector('.bitstream-media-preview');
                } else if (input.id === 'bitstream-composer-attachment-id') {
                    previewEl = previewMediaThumb;
                }

                if (!previewEl) {
                    const previewSelector = input.name === 'bit_attachment_id'
                        ? '#bitstream-bit-media-preview'
                        : '#bitstream-rebit-media-preview';
                    previewEl = composerRoot.querySelector(previewSelector);
                }

                if (!previewEl) return;

                fetchAttachmentData(value)
                    .then(data => {
                        if (previewEl === previewMediaThumb) {
                            renderComposerMediaPreview(previewMediaThumb, data);
                        } else {
                            renderMediaPreview(previewEl, data);
                        }
                        setRemoveVisibility(input.id);
                        setCropVisibility(input.id, data.mime);
                        setAudioTagVisibility(input.id, data.mime);
                    })
                    .catch(() => {
                        if (previewEl === previewMediaThumb) {
                            previewEl.innerHTML = '<span>Media attached (ID: ' + value + ')</span>';
                        }
                    });
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
                        const previewUrl = latestPreviewUrl || (data.sizes && ((data.sizes.large && data.sizes.large.url) || (data.sizes.medium_large && data.sizes.medium_large.url) || (data.sizes.medium && data.sizes.medium.url))) || data.url || fallbackUrl;
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
            const urlInput = composerRoot.querySelector('#bitstream-rebit-url');
            const fetchButton = composerRoot.querySelector('.bitstream-fetch-og:not(.bitstream-composer-rebit-fetch)');
            if (!urlInput || !fetchButton) {
                return;
            }
            const fetchButtonDefaultLabel = (fetchButton && fetchButton.textContent)
                ? fetchButton.textContent.trim()
                : 'Fetch metadata';
            const editPreviewActions = composerRoot.querySelector('.bitstream-rebit-preview-actions');
            const editButton = composerRoot.querySelector('.bitstream-rebit-edit-preview');
            const refreshButton = composerRoot.querySelector('.bitstream-rebit-refresh-preview');
            const livePreviewRoot = composerRoot.querySelector('#bitstream-rebit-live-preview');
            const livePreviewLoading = livePreviewRoot ? livePreviewRoot.querySelector('.bitstream-rebit-live-preview-loading') : null;
            const livePreviewCard = livePreviewRoot ? livePreviewRoot.querySelector('.bitstream-rebit-live-preview-card') : null;
            let isRenderingLivePreview = false;
            let isFetchingMetadata = false;
            let hasFetchedMetadata = false;
            let hasPendingPreviewRender = false;
            let commentaryPreviewDebounceTimer = null;

            const commentaryInput = composerRoot.querySelector('#bitstream-rebit-commentary');
            const titleHidden = composerRoot.querySelector('#bitstream-rebit-og-title');
            const descHidden = composerRoot.querySelector('#bitstream-rebit-og-desc');
            const imageHidden = composerRoot.querySelector('#bitstream-rebit-og-image');
            const imageRemovedHidden = composerRoot.querySelector('#bitstream-rebit-og-image-removed');
            const attachmentHidden = composerRoot.querySelector('#bitstream-rebit-attachment-id');
            const editPostHidden = composerRoot.querySelector('#bitstream-composer-panel-rebit input[name="edit_post_id"]');
            const isRebitEditMode = !!(editPostHidden && parseInt(editPostHidden.value || '0', 10) > 0);

            const rebitModal = composerRoot.querySelector('.bitstream-rebit-editor-modal');
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
                setStatus('');
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
                        if (responseData.og) {
                            if (titleHidden) {
                                titleHidden.value = responseData.og.title || '';
                            }
                            if (descHidden) {
                                descHidden.value = responseData.og.description || '';
                            }
                            if (imageHidden) {
                                imageHidden.value = responseData.og.image || '';
                            }
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
                    setStatus('');
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

        // "Save to Drafts" button handler
        composerRoot.querySelectorAll('.bitstream-composer-save-draft').forEach(draftButton => {
            draftButton.addEventListener('click', () => {
                const form = draftButton.closest('.bitstream-composer-form');
                if (!form) {
                    return;
                }

                if (!window.bitstream_ajax || !bitstream_ajax.ajax_url) {
                    setStatus('Poster submit endpoint is unavailable.', true);
                    return;
                }

                if (!submitNonce) {
                    setStatus('Security token missing. Refresh and try again.', true);
                    return;
                }

                const originalText = draftButton.textContent;
                const payload = new FormData(form);
                payload.append('action', 'bitstream_submit_composer');
                payload.append('nonce', submitNonce);
                payload.append('composer_type', form.dataset.composerType || 'bit');
                payload.append('save_as_draft', '1');
                const editPostInput = form.querySelector('input[name="edit_post_id"]');
                payload.set('edit_post_id', editPostInput ? (editPostInput.value || '0') : '0');

                // Prevent beforeunload auto-save from also firing during redirect
                formIsDirty = false;

                draftButton.disabled = true;
                draftButton.textContent = 'Saving...';
                setStatus('Saving draft...');

                fetch(bitstream_ajax.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.data || 'Could not save draft.');
                        }

                        const responseData = data.data || {};
                        setStatus(responseData.message || 'Saved as draft.');

                        const createdPostId = parseInt(responseData.post_id || '0', 10);

                        // Redirect to drafts tab to show the saved draft
                        const composerBaseUrl = (window.bitstream_ajax && bitstream_ajax.composer_url)
                            ? bitstream_ajax.composer_url
                            : window.location.href;
                        const redirectUrl = new URL(composerBaseUrl, window.location.origin);
                        redirectUrl.searchParams.set('composer_tab', 'drafts');
                        if (createdPostId > 0) {
                            redirectUrl.searchParams.set('highlight_draft', String(createdPostId));
                        }
                        window.location.href = redirectUrl.toString();
                    })
                    .catch(error => {
                        setStatus(error.message || 'Could not save draft.', true);
                        draftButton.disabled = false;
                        draftButton.textContent = originalText;
                    });
            });
        });

        // Track form dirty state for auto-save on tab close
        let formIsDirty = false;
        let autoSaveDraftId = 0;
        composerRoot.querySelectorAll('.bitstream-composer-form textarea, .bitstream-composer-form input[type="text"], .bitstream-composer-form input[type="url"]').forEach(input => {
            input.addEventListener('input', () => {
                formIsDirty = true;
            });
        });

        // Auto-save draft on tab/browser close
        window.addEventListener('beforeunload', (event) => {
            if (!formIsDirty) {
                return;
            }

            if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) {
                return;
            }

            // Determine active form
            const activePanel = composerRoot.querySelector('.bitstream-composer-panel.is-active');
            if (!activePanel) {
                return;
            }

            const form = activePanel.querySelector('.bitstream-composer-form');
            if (!form) {
                return;
            }

            // Check if there's any content to save
            const composerType = form.dataset.composerType || 'bit';
            let hasContent = false;

            if (composerType === 'bit') {
                const textArea = form.querySelector('#bitstream-bit-content');
                const attachmentInput = form.querySelector('#bitstream-bit-attachment-id');
                hasContent = (textArea && textArea.value.trim()) || (attachmentInput && parseInt(attachmentInput.value || '0', 10) > 0);
            } else if (composerType === 'rebit') {
                const urlInput = form.querySelector('#bitstream-rebit-url');
                hasContent = urlInput && urlInput.value.trim();
            }

            if (!hasContent) {
                return;
            }

            // Use sendBeacon for reliable delivery during page unload
            const payload = new FormData(form);
            payload.append('action', 'bitstream_submit_composer');
            payload.append('nonce', submitNonce);
            payload.append('composer_type', composerType);
            payload.append('save_as_draft', '1');
            payload.append('is_auto_draft', '1');
            const editPostInput = form.querySelector('input[name="edit_post_id"]');
            payload.set('edit_post_id', editPostInput ? (editPostInput.value || '0') : '0');

            navigator.sendBeacon(bitstream_ajax.ajax_url, payload);
            formIsDirty = false;
        });

        const forms = composerRoot.querySelectorAll('.bitstream-composer-form');
        function isUrlOnly(text) {
            const trimmed = (text || '').trim();
            try {
                const url = new URL(trimmed);
                return url.protocol === 'http:' || url.protocol === 'https:';
            } catch {
                return false;
            }
        }

        forms.forEach(form => {
            if (form.closest('.bitstream-composer')) {
                return;
            }

            form.addEventListener('submit', (event) => {
                event.preventDefault();

                // Prevent auto-save from firing when publishing
                formIsDirty = false;

                const hAttachmentId = form.querySelector('#bitstream-composer-attachment-id') || form.querySelector('#bitstream-bit-attachment-id');
                const hRebitUrl = form.querySelector('#bitstream-composer-rebit-url') || form.querySelector('#bitstream-rebit-url');
                const hRebitOgTitle = form.querySelector('#bitstream-composer-rebit-og-title') || form.querySelector('#bitstream-rebit-og-title');
                const hRebitOgDesc = form.querySelector('#bitstream-composer-rebit-og-desc') || form.querySelector('#bitstream-rebit-og-desc');
                const hRebitOgImage = form.querySelector('#bitstream-composer-rebit-og-image') || form.querySelector('#bitstream-rebit-og-image');
                const hRebitOgImageRemoved = form.querySelector('#bitstream-composer-rebit-og-image-removed') || form.querySelector('#bitstream-rebit-og-image-removed');
                const hRebitAttachmentId = form.querySelector('#bitstream-composer-rebit-attachment-id') || form.querySelector('#bitstream-rebit-attachment-id');
                const textarea = form.querySelector('#bitstream-quick-bit-content') || form.querySelector('#bitstream-bit-content');
                const submitBtn = form.querySelector('.bitstream-composer-submit');
                
                const previewArea = form.querySelector('.bitstream-composer-preview-area') || form.querySelector('.bitstream-media-field');
                const previewRebit = form.querySelector('.bitstream-composer-preview-rebit') || form.querySelector('#bitstream-rebit-preview');
                const previewRebitCard = form.querySelector('.bitstream-composer-preview-rebit-card') || form.querySelector('.bit-card');
                const previewMedia = form.querySelector('.bitstream-composer-preview-media') || form.querySelector('.bitstream-media-preview');
                const previewMediaThumb = form.querySelector('.bitstream-composer-preview-media-thumb');

                if (!window.bitstream_ajax || !bitstream_ajax.ajax_url) {
                    setStatus('Poster submit endpoint is unavailable.', true);
                    return;
                }

                if (!submitNonce) {
                    setStatus('Security token missing. Refresh and try again.', true);
                    return;
                }

                const submitButton = form.querySelector('.bitstream-composer-submit');
                const originalText = submitButton ? submitButton.textContent : '';
                const payload = new FormData(form);
                payload.append('action', 'bitstream_submit_composer');
                payload.append('nonce', submitNonce);

                const composerType = form.dataset.composerType || 'bit';
                payload.append('composer_type', composerType);
                const editPostInput = form.querySelector('input[name="edit_post_id"]');
                payload.set('edit_post_id', editPostInput ? (editPostInput.value || '0') : '0');

                const scheduleEnabledInput = form.querySelector('[name="' + composerType + '_schedule_enabled"]');
                const scheduleDatetimeInput = form.querySelector('[name="' + composerType + '_schedule_datetime"]');
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

                        if (hAttachmentId) hAttachmentId.value = '';
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
                        form.dataset.composerType = 'bit';
                        if (submitBtn) submitBtn.textContent = 'Post Bit';

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
                        return;
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

    let openTimelineEditModal = () => false;
    let openTimelineQuoteModal = () => false;

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
        const bitForm = modal.querySelector('.bs-edit-form-bit');
        const rebitForm = modal.querySelector('.bs-edit-form-rebit');
        const linkMetaModal = modal.querySelector('.bs-edit-link-meta-modal');
        const closeButtons = modal.querySelectorAll('[data-bs-edit-modal-close="true"]');
        const submitNonce = modal.dataset.submitNonce || (window.bitstream_ajax && bitstream_ajax.composer_submit_nonce) || '';
        let mediaFrame = null;
        let activeMediaForm = null;
        let ogImageFrame = null;

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
            if (rebitForm) {
                rebitForm.hidden = form !== rebitForm;
            }
        }

        function setAttachmentPreview(form, attachmentId, attachmentUrl, attachmentMime) {
            if (!form) {
                return;
            }

            const targetInput = form.querySelector('.bs-edit-attachment-id');
            if (targetInput) {
                targetInput.value = attachmentId > 0 ? String(attachmentId) : '';
            }

            const previewEl = form.querySelector('.bitstream-media-preview');
            if (!previewEl) {
                return;
            }

            const removeButton = form.querySelector('.bitstream-media-remove');
            const cropButton = form.querySelector('.bitstream-media-crop');

            if (attachmentId > 0 && attachmentUrl) {
                const attachment = {
                    id: attachmentId,
                    url: attachmentUrl,
                    preview_url: attachmentUrl,
                    mime: attachmentMime
                };
                renderMediaPreview(previewEl, attachment);
                if (removeButton) {
                    removeButton.classList.remove('is-hidden');
                }
                if (cropButton) {
                    const isImage = attachmentMime && attachmentMime.startsWith('image/');
                    cropButton.classList.toggle('is-hidden', !isImage);
                }
            } else {
                renderMediaPreview(previewEl, null);
                if (removeButton) {
                    removeButton.classList.add('is-hidden');
                }
                if (cropButton) {
                    cropButton.classList.add('is-hidden');
                }
            }
        }

        function setLinkMetaPreview() {
            if (!rebitForm || !linkMetaModal) {
                return;
            }

            const urlInput = rebitForm.querySelector('#bs-edit-rebit-url');
            const attachmentInput = rebitForm.querySelector('input[name="rebit_attachment_id"]');
            const titleInput = rebitForm.querySelector('input[name="rebit_og_title"]');
            const descInput = rebitForm.querySelector('textarea[name="rebit_og_desc"]');
            const imageInput = rebitForm.querySelector('input[name="rebit_og_image"]');
            const imageRemovedInput = rebitForm.querySelector('input[name="rebit_og_image_removed"]');

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
            if (!rebitForm || !linkMetaModal) {
                return;
            }

            const titleInput = rebitForm.querySelector('input[name="rebit_og_title"]');
            const descInput = rebitForm.querySelector('textarea[name="rebit_og_desc"]');
            const imageInput = rebitForm.querySelector('input[name="rebit_og_image"]');
            const imageRemovedInput = rebitForm.querySelector('input[name="rebit_og_image_removed"]');

            const visibleTitleInput = linkMetaModal.querySelector('#bs-edit-link-meta-title-input');
            const visibleDescInput = linkMetaModal.querySelector('#bs-edit-link-meta-desc-input');

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

        function openLinkMetaModal() {
            if (!linkMetaModal || !rebitForm) {
                return;
            }

            setLinkMetaPreview();
            linkMetaModal.hidden = false;
        }

        function closeLinkMetaModal() {
            if (linkMetaModal) {
                linkMetaModal.hidden = true;
            }
        }

        function setQuotePreview(form, quotePostId, quotePreviewHtml) {
            if (!form) {
                return;
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
                applyMediaDeterrents(quoteCard);
            }
        }

        function setScheduleState(form, scheduleKey, enabled, datetimeValue) {
            if (!form) {
                return;
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

            // Helper to upload media file via AJAX
            const uploadFile = async (file) => {
                if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
                    setErrorState('Media upload is unavailable.');
                    return;
                }

                const mimeType = getUploadMimeType(file);
                if (!mimeType.startsWith('image/') && !mimeType.startsWith('video/')) {
                    setErrorState('Unsupported file format. Only images and videos are allowed.');
                    return;
                }

                const progressContainer = form.querySelector(`[data-progress-bar="${targetInputId}"]`);
                const progressBar = progressContainer ? progressContainer.querySelector('.bitstream-media-progress-bar') : null;
                const progressText = progressContainer ? progressContainer.querySelector('.bitstream-media-progress-text') : null;

                const showProgress = () => {
                    if (progressContainer) progressContainer.classList.remove('is-hidden');
                    if (progressBar) progressBar.style.width = '0%';
                    if (progressText) progressText.textContent = 'Uploading...';
                };

                const updateProgress = (percent, text) => {
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressText && text) progressText.textContent = text;
                };

                const hideProgress = () => {
                    if (progressContainer) progressContainer.classList.add('is-hidden');
                };

                showProgress();
                updateProgress(5, mimeType.startsWith('image/') ? 'Preparing image...' : 'Preparing upload...');

                const preparedFile = await prepareMediaFileForUpload(file);
                updateProgress(8, preparedFile.size > BITSTREAM_CHUNKED_UPLOAD_THRESHOLD ? 'Uploading in chunks...' : 'Uploading...');

                uploadMediaRequest(preparedFile, updateProgress)
                    .then(media => {
                    setAttachmentPreview(form, media.id, media.url, media.mime);

                    setTimeout(() => {
                        hideProgress();
                    }, 1000);
                    })
                    .catch(error => {
                        hideProgress();
                        setErrorState(error.message || 'Upload failed.');
                    });
            };

            // Clipboard paste
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
                            uploadFile(file);
                        });
                    })
                    .catch(error => {
                        setErrorState(error.message || 'Clipboard permission denied.');
                    });
            };

            // Drag and drop events
            if (dropzone && fileInput) {
                dropzone.addEventListener('click', (event) => {
                    if (event.target !== fileInput && !event.target.closest('.bitstream-media-preview')) {
                        fileInput.click();
                    }
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

                    const file = event.dataTransfer.files && event.dataTransfer.files[0];
                    if (file) {
                        uploadFile(file);
                    }
                });

                fileInput.addEventListener('change', () => {
                    const file = fileInput.files && fileInput.files[0];
                    if (file) {
                        uploadFile(file);
                    }
                    fileInput.value = '';
                });
            }

            // Remove button
            if (removeButton) {
                removeButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    setAttachmentPreview(form, 0, '', '');
                });
            }

            // Crop button
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

            // Media library button
            if (libraryButton) {
                libraryButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (!window.wp || !wp.media) {
                        setErrorState('The media library is unavailable.');
                        return;
                    }

                    const frame = wp.media({
                        title: 'Select media',
                        button: { text: 'Use media' },
                        multiple: false,
                        library: { type: ['image', 'video'] }
                    });

                    frame.on('select', () => {
                        const selection = frame.state().get('selection').first();
                        if (!selection) return;

                        const data = selection.toJSON();
                        const mime = data.mime || data.type || '';
                        if (!mime.startsWith('image/') && !mime.startsWith('video/')) {
                            setErrorState('Only image and video files are supported.');
                            return;
                        }

                        setAttachmentPreview(form, data.id, data.url, mime);
                    });

                    frame.open();
                });
            }

            // Paste button
            if (pasteButton) {
                pasteButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    pasteImage();
                });
            }

            // Textarea paste event
            const textarea = form.querySelector('.bs-edit-textarea');
            if (textarea) {
                textarea.addEventListener('paste', (event) => {
                    const clipboardData = event.clipboardData || window.clipboardData;
                    if (!clipboardData || !clipboardData.files || !clipboardData.files.length) {
                        return;
                    }

                    const file = clipboardData.files[0];
                    if (file && (file.type.startsWith('image/') || file.type.startsWith('video/'))) {
                        event.preventDefault();
                        uploadFile(file);
                    }
                });
            }
        }

        function bindLinkMetaControls() {
            if (!rebitForm || !linkMetaModal || linkMetaModal.dataset.timelineMetaBound === '1') {
                return;
            }

            linkMetaModal.dataset.timelineMetaBound = '1';

            const openButton = rebitForm.querySelector('.bs-edit-link-meta-open');
            const refetchButton = linkMetaModal.querySelector('.bs-edit-link-meta-refetch');
            const closeButtons = linkMetaModal.querySelectorAll('[data-bs-edit-link-meta-close="true"]');
            const saveButton = linkMetaModal.querySelector('.bs-edit-link-meta-save');
            const modalUrlInput = linkMetaModal.querySelector('#bs-edit-link-meta-url-input');
            const visibleTitleInput = linkMetaModal.querySelector('#bs-edit-link-meta-title-input');
            const visibleDescInput = linkMetaModal.querySelector('#bs-edit-link-meta-desc-input');
            const chooseImageButton = linkMetaModal.querySelector('.bs-edit-og-image-select');
            const cropImageButton = linkMetaModal.querySelector('.bs-edit-og-image-crop');
            const clearImageButton = linkMetaModal.querySelector('.bs-edit-og-image-clear');
            const imageInput = rebitForm.querySelector('input[name="rebit_og_image"]');
            const imageRemovedInput = rebitForm.querySelector('input[name="rebit_og_image_removed"]');
            const attachmentInput = rebitForm.querySelector('input[name="rebit_attachment_id"]');
            const mainUrlInput = rebitForm.querySelector('#bs-edit-rebit-url');

            if (openButton) {
                openButton.addEventListener('click', () => {
                    if (modalUrlInput && mainUrlInput) {
                        modalUrlInput.value = mainUrlInput.value || '';
                    }
                    openLinkMetaModal();
                });
            }

            closeButtons.forEach(button => {
                button.addEventListener('click', closeLinkMetaModal);
            });

            if (saveButton) {
                saveButton.addEventListener('click', () => {
                    syncLinkMetaFields();
                    closeLinkMetaModal();
                });
            }

            if (refetchButton) {
                refetchButton.addEventListener('click', () => {
                    const targetUrl = (mainUrlInput && mainUrlInput.value ? mainUrlInput.value : (modalUrlInput ? modalUrlInput.value : '')).trim();
                    const editPostInput = rebitForm.querySelector('input[name="edit_post_id"]');

                    if (!targetUrl) {
                        setErrorState('Add a link URL first.');
                        return;
                    }

                    if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.og_fetch_nonce) {
                        setErrorState('Metadata fetch is unavailable.');
                        return;
                    }

                    refetchButton.disabled = true;
                    setStatus('Fetching metadata...');

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
                            const titleInput = rebitForm.querySelector('input[name="rebit_og_title"]');
                            const descInput = rebitForm.querySelector('textarea[name="rebit_og_desc"]');

                            if (titleInput) {
                                titleInput.value = og.title || '';
                            }
                            if (descInput) {
                                descInput.value = og.description || '';
                            }
                            if (imageInput) {
                                imageInput.value = og.image || '';
                            }
                            if (imageRemovedInput) {
                                imageRemovedInput.value = og.image ? '0' : '1';
                            }
                            if (attachmentInput) {
                                attachmentInput.value = '';
                            }

                            setLinkMetaPreview();
                            setStatus(og.cached ? 'Metadata refreshed from cache.' : 'Metadata refreshed.');
                        })
                        .catch(error => {
                            setErrorState(error.message || 'Could not fetch metadata.');
                        })
                        .finally(() => {
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
                            const attachmentMime = attachment.mime || attachment.type || '';
                            setAttachmentPreview(rebitForm, attachment.id || 0, attachment.url || '', attachmentMime);
                            if (imageInput) {
                                imageInput.value = attachment.url || '';
                            }
                            if (imageRemovedInput) {
                                imageRemovedInput.value = '0';
                            }
                            setLinkMetaPreview();
                        });
                    }

                    ogImageFrame.open();
                });
            }

            if (cropImageButton) {
                cropImageButton.addEventListener('click', () => {
                    const attachmentId = attachmentInput ? parseInt(attachmentInput.value || '0', 10) : 0;
                    const imageUrl = imageInput ? (imageInput.value || '').trim() : '';

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
                                    if (croppedMedia && croppedMedia.id) {
                                        setAttachmentPreview(rebitForm, croppedMedia.id, croppedUrl || croppedMedia.url || '', croppedMedia.mime || 'image/jpeg');
                                    }
                                    if (imageInput) {
                                        imageInput.value = croppedUrl || (croppedMedia && croppedMedia.url) || '';
                                    }
                                    if (imageRemovedInput) {
                                        imageRemovedInput.value = '0';
                                    }
                                    setLinkMetaPreview();
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
                            setAttachmentPreview(rebitForm, prepared.id || 0, prepared.url || imageUrl, prepared.mime || 'image/jpeg');
                            if (imageInput) {
                                imageInput.value = prepared.url || imageUrl;
                            }
                            if (imageRemovedInput) {
                                imageRemovedInput.value = '0';
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
                    setAttachmentPreview(rebitForm, 0, '', '');
                    if (imageInput) {
                        imageInput.value = '';
                    }
                    if (imageRemovedInput) {
                        imageRemovedInput.value = '1';
                    }
                    setLinkMetaPreview();
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
            if (form === rebitForm) {
                bindLinkMetaControls();
            }
        }

        function populateBitForm(data, isQuoteMode) {
            if (!bitForm) {
                return;
            }

            bindFormOnce(bitForm);
            showForm(bitForm);

            const postId = parseInt(data.post_id || '0', 10);
            const editPostInput = bitForm.querySelector('input[name="edit_post_id"]');
            const contentInput = bitForm.querySelector('#bs-edit-bit-content');
            const submitButton = bitForm.querySelector('.bs-edit-submit');
            const draftButton = bitForm.querySelector('.bs-edit-save-draft');
            const attachmentId = parseInt(data.attachment_id || '0', 10);
            const attachmentUrl = data.attachment_url || '';
            const attachmentMime = data.attachment_mime || '';
            const quotePostId = parseInt(data.quote_post_id || (isQuoteMode ? postId : 0), 10);
            const quotePreviewHtml = isQuoteMode ? (data.quote_preview_html || '') : (data.quote_preview_html || '');
            const scheduleEnabled = (data.post_status === 'future' || data.schedule_enabled === '1');
            const scheduleDatetime = data.schedule_datetime || '';

            if (modalTitle) {
                modalTitle.textContent = isQuoteMode ? 'Quote Bit' : 'Edit Bit';
            }

            if (editPostInput) {
                editPostInput.value = isQuoteMode ? '0' : String(postId || 0);
            }

            if (contentInput) {
                contentInput.value = isQuoteMode ? '' : (data.content || '');
            }

            setQuotePreview(bitForm, quotePostId, quotePreviewHtml);
            setAttachmentPreview(bitForm, isQuoteMode ? 0 : attachmentId, isQuoteMode ? '' : attachmentUrl, isQuoteMode ? '' : attachmentMime);
            setScheduleState(bitForm, 'bit', scheduleEnabled && !isQuoteMode, isQuoteMode ? '' : scheduleDatetime);

            if (submitButton) {
                submitButton.textContent = isQuoteMode ? 'Post Bit' : 'Update Bit';
            }
            if (draftButton) {
                draftButton.textContent = data.post_status === 'draft' && !isQuoteMode ? 'Update Draft' : 'Save to Drafts';
            }

            setLoadingState(false);
            clearFormFeedback();
            setModalVisible(true);
        }

        function populateRebitForm(data) {
            if (!rebitForm) {
                return;
            }

            bindFormOnce(rebitForm);
            showForm(rebitForm);

            const postId = parseInt(data.post_id || '0', 10);
            const editPostInput = rebitForm.querySelector('input[name="edit_post_id"]');
            const urlInput = rebitForm.querySelector('#bs-edit-rebit-url');
            const commentaryInput = rebitForm.querySelector('#bs-edit-rebit-commentary');
            const ogTitleInput = rebitForm.querySelector('.bs-edit-og-title');
            const ogDescInput = rebitForm.querySelector('.bs-edit-og-desc');
            const ogImageInput = rebitForm.querySelector('.bs-edit-og-image');
            const ogImageRemovedInput = rebitForm.querySelector('.bs-edit-og-image-removed');
            const submitButton = rebitForm.querySelector('.bs-edit-submit');
            const draftButton = rebitForm.querySelector('.bs-edit-save-draft');
            const attachmentId = parseInt(data.attachment_id || '0', 10);
            const attachmentUrl = data.attachment_url || '';
            const attachmentMime = data.attachment_mime || '';
            const scheduleEnabled = (data.post_status === 'future' || data.schedule_enabled === '1');
            const scheduleDatetime = data.schedule_datetime || '';

            if (modalTitle) {
                modalTitle.textContent = 'Edit Rebit';
            }

            if (editPostInput) {
                editPostInput.value = String(postId || 0);
            }
            if (urlInput) {
                urlInput.value = data.rebit_url || '';
            }
            if (commentaryInput) {
                commentaryInput.value = data.content || '';
            }
            if (ogTitleInput) {
                ogTitleInput.value = data.og_title || '';
            }
            if (ogDescInput) {
                ogDescInput.value = data.og_desc || '';
            }
            if (ogImageInput) {
                ogImageInput.value = data.og_image || '';
            }
            if (ogImageRemovedInput) {
                ogImageRemovedInput.value = '0';
            }

            setAttachmentPreview(rebitForm, attachmentId, attachmentUrl, attachmentMime);
            setLinkMetaPreview();

            if (submitButton) {
                submitButton.textContent = 'Update Rebit';
            }
            if (draftButton) {
                draftButton.textContent = data.post_status === 'draft' ? 'Update Draft' : 'Save to Drafts';
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

            const composerType = form.dataset.composerType || 'bit';
            const editPostInput = form.querySelector('input[name="edit_post_id"]');
            const submitButton = form.querySelector('.bs-edit-submit');
            const draftButton = form.querySelector('.bs-edit-save-draft');
            const scheduleEnabledInput = form.querySelector('input[name="' + composerType + '_schedule_enabled"]');
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
                const content = textarea ? textarea.value.trim() : '';
                const hasMedia = attachmentInput && parseInt(attachmentInput.value || '0', 10) > 0;
                const hasQuote = quoteInput && parseInt(quoteInput.value || '0', 10) > 0;

                if (!saveAsDraft && !content && !hasMedia && !hasQuote) {
                    setErrorState('Write something or attach media.');
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

            if (submitButton) {
                submitButton.disabled = true;
            }
            if (draftButton) {
                draftButton.disabled = true;
            }
            setLoadingState(true, saveAsDraft ? 'Saving draft...' : 'Publishing...');

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
                const content = textarea ? textarea.value.trim() : '';
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
                    setErrorState(error.message || 'Could not save post.');
                    setLoadingState(false);
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                    if (draftButton) {
                        draftButton.disabled = false;
                    }
                });
        }

        openTimelineEditModal = function (postId, postType) {
            const numericPostId = parseInt(postId || '0', 10);
            if (!numericPostId || !window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) {
                return false;
            }

            setModalVisible(true);
            setLoadingState(true, 'Loading post…');
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
                    if (responseData.post_type === 'rebit' || postType === 'rebit') {
                        populateRebitForm(responseData);
                    } else {
                        populateBitForm(responseData, false);
                    }
                })
                .catch(error => {
                    setErrorState(error.message || 'Could not load post data.');
                });

            return true;
        };

        openTimelineQuoteModal = function (postId) {
            const numericPostId = parseInt(postId || '0', 10);
            if (!numericPostId || !window.bitstream_ajax || !bitstream_ajax.ajax_url || !submitNonce) {
                return false;
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
                    populateBitForm({
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
                })
                .catch(error => {
                    setErrorState(error.message || 'Could not load quote preview.');
                });

            return true;
        };

        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                closeLinkMetaModal();
                modal.hidden = true;
                document.body.style.overflow = '';
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeLinkMetaModal();
                modal.hidden = true;
                document.body.style.overflow = '';
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeLinkMetaModal();
                modal.hidden = true;
                document.body.style.overflow = '';
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
    }

    initTimelineEditModal();
    initBitstreamPoster();
    highlightFromQueryParams();
    applyMediaDeterrents(document);

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

    syncLikeButtonState();

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.bit-like');
        if (!button) {
            return;
        }

        event.preventDefault();

        if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.like_nonce) {
            console.warn('BitStream like error: AJAX config unavailable');
            return;
        }

        const postId = button.dataset.postId;
        if (!postId || button.dataset.likeProcessing === '1') {
            return;
        }

        button.dataset.likeProcessing = '1';

        const storageKey = 'bitstream-liked-' + postId;
        const likeCountSpan = button.querySelector('.bit-like-count');
        const isLiked = !!localStorage.getItem(storageKey);
        const type = isLiked ? 'unlike' : 'like';

        const formData = new FormData();
        formData.append('action', 'bitstream_like');
        formData.append('post_id', postId);
        formData.append('type', type);
        formData.append('nonce', bitstream_ajax.like_nonce);

        fetch(bitstream_ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    throw new Error((data && data.data) || 'Like request failed.');
                }

                if (type === 'like') {
                    localStorage.setItem(storageKey, true);
                    button.classList.add('liked', 'pulse');
                    setTimeout(() => button.classList.remove('pulse'), 300);
                } else {
                    localStorage.removeItem(storageKey);
                    button.classList.remove('liked');
                }

                if (likeCountSpan && data.data && typeof data.data.likes !== 'undefined') {
                    likeCountSpan.textContent = data.data.likes;
                }
            })
            .catch(error => {
                console.warn('BitStream like error:', error);
                syncLikeButtonState(document);
            })
            .finally(() => {
                button.dataset.likeProcessing = '0';
            });
    });

    // Copy Link and Quote buttons use delegated handling so dynamically loaded cards work too.
    document.addEventListener('click', (event) => {
        const permalinkButton = event.target.closest('.bit-permalink');
        if (permalinkButton) {
            event.preventDefault();
            const url = permalinkButton.dataset.url;
            navigator.clipboard.writeText(url);
            const icon = permalinkButton.querySelector('i');
            if (icon) {
                icon.classList.remove('pulse');
                void icon.offsetWidth;
                icon.classList.add('pulse');
                setTimeout(() => icon.classList.remove('pulse'), 300);
            }
            return;
        }

        const quoteButton = event.target.closest('.bit-quote');
        if (quoteButton) {
            event.preventDefault();
            const postId = quoteButton.dataset.postId;
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
                            }, 350);
                        }, 3000);
                    }
                })
                .catch(error => {
                    alert(error.message || 'Could not delete post.');
                    button.classList.remove('is-active');
                });
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
        const card = mediaEl.closest('.bit-card, .bitstream-media-preview, .bitstream-composer');
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

        if (isDesktop) {
            // Remove inline styles to let desktop CSS take over
            sidebarPanels.forEach(panel => {
                panel.style.display = '';
            });
            // Reset active mobile tabs state
            const tabsNav = document.querySelector('.bitstream-mobile-tabs-nav');
            if (tabsNav) {
                tabsNav.querySelectorAll('button').forEach(b => b.classList.remove('is-active'));
            }
        }
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
        formData.append('filter_hashtag', feed.dataset.filterHashtag || '');

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

                syncLikeButtonState(feed);

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
    jQuery('.bit-comments-list .comment-author').each(function () {
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
jQuery(document).ready(function ($) {
    applyCommentStyles();

    // Hashtag-aware search: redirect #tag searches to hashtag filter
    document.querySelectorAll('.bitstream-filter-search').forEach(form => {
        form.addEventListener('submit', (e) => {
            const input = form.querySelector('input[name="bitstream_search"]');
            if (!input) return;
            const value = input.value.trim();
            if (value.startsWith('#') && value.length > 1) {
                e.preventDefault();
                const tag = value.substring(1);
                const url = new URL(form.action || window.location.href);
                // Clear regular search param, set hashtag param
                url.searchParams.delete('bitstream_search');
                url.searchParams.set('bitstream_hashtag', tag);
                window.location.href = url.toString();
            }
        });
    });

    // ── Composer (modal-based) ────────────────────────────────────
    // Intercept clicks on quick action triggers
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-composer-modal-trigger]');
        if (!trigger) return;

        const modalName = trigger.dataset.composerModalTrigger;
        const composer = document.querySelector('.bitstream-composer');
        const feedBaseUrl = (window.bitstream_ajax && bitstream_ajax.feed_url)
            ? bitstream_ajax.feed_url
            : (window.location.origin + '/bitstream/');
        const feedUrl = new URL(feedBaseUrl, window.location.origin);

        if (composer) {
            e.preventDefault();
            const isMobile = window.innerWidth < 1024;
            if (isMobile) {
                composer.hidden = false;
            }
            composer.dataset.quickActionSource = modalName;

            if (modalName === 'new-bit') {
                const textarea = composer.querySelector('#bitstream-quick-bit-content');
                if (textarea) {
                    textarea.focus();
                    if (!isMobile) {
                        textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            } else if (modalName === 'new-rebit') {
                const rebitBtn = composer.querySelector('[data-composer-modal="rebit"]');
                if (rebitBtn) {
                    rebitBtn.click();
                    if (!isMobile) {
                        rebitBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            } else {
                // Close any open modals first
                composer.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
                const modal = composer.querySelector('.bitstream-composer-modal-' + modalName);
                if (modal) {
                    modal.hidden = false;
                    const statusEl = composer.querySelector('.bitstream-sidebar-composer-status');
                    if (statusEl) statusEl.textContent = '';
                }
            }
        } else {
            e.preventDefault();
            if (modalName === 'new-bit') {
                feedUrl.searchParams.set('composer_tab', 'bit');
            } else if (modalName === 'new-rebit') {
                feedUrl.searchParams.set('composer_tab', 'rebit');
            } else if (modalName === 'drafts') {
                feedUrl.searchParams.set('show_drafts', '1');
            } else if (modalName === 'scheduled-list') {
                feedUrl.searchParams.set('show_scheduled', '1');
            }
            window.location.href = feedUrl.toString();
        }
    });

    document.querySelectorAll('.bitstream-composer').forEach(composerRoot => {
        const form = composerRoot.querySelector('.bitstream-sidebar-composer-form');
        if (!form) return;

        const statusEl = composerRoot.querySelector('.bitstream-sidebar-composer-status');
        const submitBtn = form.querySelector('.bitstream-composer-submit');
        const textarea = form.querySelector('#bitstream-quick-bit-content');
        const submitNonce = composerRoot.dataset.submitNonce || '';

        // Hidden inputs
        const hAttachmentId = form.querySelector('#bitstream-composer-attachment-id');
        const modalAttachmentId = composerRoot.querySelector('#bitstream-composer-modal-media-attachment-id');
        if (hAttachmentId && modalAttachmentId && hAttachmentId.value && !modalAttachmentId.value) {
            modalAttachmentId.value = hAttachmentId.value;
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
        let renderRebitLivePreview, updateModalImagePreview;

        // Preview containers
        const previewArea = form.querySelector('.bitstream-composer-preview-area');
        const previewRebit = form.querySelector('.bitstream-composer-preview-rebit');
        const previewRebitCard = form.querySelector('.bitstream-composer-preview-rebit-card');
        const previewMedia = form.querySelector('.bitstream-composer-preview-media');
        const previewMediaThumb = form.querySelector('.bitstream-composer-preview-media-thumb');
        const previewSchedule = form.querySelector('.bitstream-composer-preview-schedule');
        const previewScheduleDate = form.querySelector('.bitstream-composer-preview-schedule-date');
        const previewDraft = null;
        const previewDraftLabel = null;

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
            const hasDraft = previewDraft && !previewDraft.hidden;
            if (previewArea) previewArea.hidden = !(hasRebit || hasMedia || hasSched || hasDraft);
            if (textarea) textarea.required = !(hasRebit || hasMedia);
        }

        // Modal open/close helpers
        function openModal(name) {
            setStatus('');
            const modal = composerRoot.querySelector('.bitstream-composer-modal-' + name);
            if (modal) {
                modal.hidden = false;
                const isMobile = window.innerWidth < 1024;
                if (isMobile) {
                    composerRoot.hidden = false;
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
            }
        }
        function closeModal(name, keepPosterOpen = false) {
            setStatus('');
            if (name === 'composer') {
                composerRoot.hidden = true;
                delete composerRoot.dataset.quickActionSource;
                // Also close any open sub-modals
                composerRoot.querySelectorAll('.bitstream-composer-modal').forEach(m => m.hidden = true);
            } else {
                const modal = composerRoot.querySelector('.bitstream-composer-modal-' + name);
                if (modal) {
                    modal.hidden = true;
                    const isMobile = window.innerWidth < 1024;
                    const quickActionSource = composerRoot.dataset.quickActionSource || '';
                    const shouldCloseComposer = isMobile && !keepPosterOpen && (
                        name === 'drafts'
                        || name === 'scheduled-list'
                        || (name === 'rebit' && quickActionSource === 'new-rebit')
                    );
                    if (shouldCloseComposer) {
                        composerRoot.hidden = true;
                        delete composerRoot.dataset.quickActionSource;
                    }
                }
            }
        }

        // Wire action buttons
        composerRoot.querySelectorAll('.bitstream-composer-action-btn[data-composer-modal]').forEach(btn => {
            btn.addEventListener('click', () => openModal(btn.dataset.composerModal));
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
                    if (hAttachmentId) hAttachmentId.value = '';
                    if (previewMedia) previewMedia.hidden = true;
                    if (previewMediaThumb) previewMediaThumb.innerHTML = '';
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
                syncPreviewArea();
            });
        });

        // DRY Helper: Load draft or scheduled post into composer
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

                        // Prefill Rebit Modal fields
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

                    if (d.attachment_id && parseInt(d.attachment_id, 10) > 0) {
                        if (!d.is_rebit) {
                            if (hAttachmentId) hAttachmentId.value = String(d.attachment_id);
                            if (previewMediaThumb) {
                                if (d.attachment_url && d.attachment_mime) {
                                    renderComposerMediaPreview(previewMediaThumb, {
                                        id: parseInt(d.attachment_id, 10) || 0,
                                        url: d.attachment_url,
                                        preview_url: d.attachment_url,
                                        mime: d.attachment_mime
                                    });
                                } else {
                                    previewMediaThumb.innerHTML = d.media_preview_html || '<span>Media attached (ID: ' + d.attachment_id + ')</span>';
                                }
                            }
                            if (previewMedia) previewMedia.hidden = false;
                        }
                    }

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

            // Metadata edit modal controls (now in rebitMetaModal)
            const mRebitTitle = rebitMetaModal.querySelector('#bitstream-composer-modal-rebit-og-title');
            const mRebitDesc = rebitMetaModal.querySelector('#bitstream-composer-modal-rebit-og-desc');
            const mRebitImageChange = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-change');
            const mRebitImageRemove = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-remove');
            const mRebitMetaDone = rebitMetaModal.querySelector('.bitstream-composer-rebit-meta-done');

            const mRebitImagePreviewWrapper = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-preview-wrapper');
            const mRebitImagePreviewEl = rebitMetaModal.querySelector('.bitstream-composer-rebit-image-preview-el');

            updateModalImagePreview = function() {
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

            renderRebitLivePreview = function(url) {
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
            const mMediaAttInput = mediaModal.querySelector('#bitstream-composer-modal-media-attachment-id');
            const mMediaPreview = mediaModal.querySelector('#bitstream-composer-modal-media-preview');

            if (mMediaDone) {
                mMediaDone.addEventListener('click', () => {
                    const attachId = mMediaAttInput ? mMediaAttInput.value : '';
                    if (!attachId || attachId === '0') { setStatus('Upload or select media first.', true); return; }

                    if (hAttachmentId) hAttachmentId.value = attachId;

                    if (previewMediaThumb && mMediaPreview) {
                        previewMediaThumb.innerHTML = '';
                        const attachment = {
                            id: parseInt(attachId, 10) || 0,
                            url: mMediaPreview.dataset.attachmentUrl || '',
                            preview_url: mMediaPreview.dataset.attachmentUrl || '',
                            mime: mMediaPreview.dataset.attachmentMime || ''
                        };
                        if (attachment.url) {
                            renderComposerMediaPreview(previewMediaThumb, attachment);
                        } else {
                            const previewImg = mMediaPreview.querySelector('img');
                            if (previewImg) {
                                const img = document.createElement('img');
                                img.src = previewImg.src;
                                img.alt = '';
                                previewMediaThumb.appendChild(img);
                            }
                            const label = document.createElement('span');
                            label.textContent = 'Media attached';
                            previewMediaThumb.appendChild(label);
                        }
                    }
                    if (previewMedia) previewMedia.hidden = false;
                    syncPreviewArea();
                    closeModal('media');
                    setStatus('Media attached.');
                });
            }
        }

        // ── DRAFTS MODAL ──
        const draftsModal = composerRoot.querySelector('.bitstream-composer-modal-drafts');
        if (draftsModal) {
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

                    showDeleteConfirmation('Delete this draft?', () => {
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
                                
                                // Check if empty
                                const list = draftsModal.querySelector('.bitstream-composer-drafts-list');
                                const remaining = list ? list.querySelectorAll('.bitstream-composer-draft-item') : [];
                                if (list && remaining.length === 0) {
                                    list.innerHTML = '<p class="bitstream-composer-drafts-empty">No drafts yet.</p>';
                                }
                                setStatus('Draft deleted.');
                            })
                            .catch(err => { setStatus(err.message || 'Delete failed.', true); btn.disabled = false; });
                    });
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
                if (!content && !hasRebit && !hasMedia) { setStatus('Nothing to save.', true); return; }

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

                    showDeleteConfirmation('Delete this scheduled post?', () => {
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
                                
                                // Check if empty
                                const list = scheduledListModal.querySelector('.bitstream-composer-scheduled-list');
                                const remaining = list ? list.querySelectorAll('.bitstream-composer-scheduled-item') : [];
                                if (list && remaining.length === 0) {
                                    list.innerHTML = '<p class="bitstream-composer-scheduled-empty">No scheduled Bits or Rebits yet.</p>';
                                }
                                setStatus('Scheduled post deleted.');
                            })
                            .catch(err => { setStatus(err.message || 'Delete failed.', true); btn.disabled = false; });
                    });
                });
            });
        }

        // ── COMPOSER SAVE DRAFT BUTTON ──
        if (composerSaveDraftBtn) {
            composerSaveDraftBtn.addEventListener('click', () => {
                if (!window.bitstream_ajax || !submitNonce) { setStatus('Cannot save draft.', true); return; }

                const content = textarea ? textarea.value.trim() : '';
                const hasRebit = hRebitUrl && hRebitUrl.value.trim();
                if (!content && !hasRebit) { setStatus('Nothing to save.', true); return; }

                composerSaveDraftBtn.disabled = true;
                composerSaveDraftBtn.textContent = 'Saving...';
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
                        composerSaveDraftBtn.disabled = false;
                        composerSaveDraftBtn.textContent = 'Save Draft';
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

        // Open quote modal when ?quote_post_id=N is in the URL
        const quotePostIdFromUrl = parseInt(urlParams.get('quote_post_id') || '0', 10);
        if (quotePostIdFromUrl > 0 && typeof openTimelineQuoteModal === 'function') {
            setTimeout(() => openTimelineQuoteModal(quotePostIdFromUrl), 150);
        }

        // Auto-save Composer form content when the user navigates away (mirrors composer shortcode beforeunload)
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
            if (!content && !hasRebit && !hasMedia) return;

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

            let effectiveType = composerType;
            if (composerType === 'bit' && !hasMedia && !hasRebit && content) {
                try {
                    const u = new URL(content);
                    if (u.protocol === 'http:' || u.protocol === 'https:') effectiveType = 'rebit';
                } catch {}
            }

            if (effectiveType === 'bit' && !content && !hasMedia) { setStatus('Write something or attach media.', true); return; }

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
    });

    // Handle standard WP comment form submission via AJAX (also handles moved forms for nested replies)
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('#respond form, .bit-comment-form form');
        if (!form || !form.action || !form.action.includes('wp-comments-post.php')) return;

        e.preventDefault();

        const submitBtn = form.querySelector('input[type="submit"], button[type="submit"]');
        const originalBtnText = submitBtn ? (submitBtn.value || submitBtn.textContent) : 'Post Comment';

        if (submitBtn) {
            submitBtn.disabled = true;
            if (submitBtn.tagName === 'INPUT') submitBtn.value = 'Posting...';
            else submitBtn.textContent = 'Posting...';
        }

        const formData = new FormData(form);
        const postId = form.querySelector('input[name="comment_post_ID"]').value;

        fetch(form.action, {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (response.ok) {
                    // Set flag to reopen this specific comment section after reload
                    sessionStorage.setItem('bitstream_open_comments', 'comments-' + postId);
                    window.location.reload();
                } else {
                    alert('Error posting comment. Please try again.');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        if (submitBtn.tagName === 'INPUT') submitBtn.value = originalBtnText;
                        else submitBtn.textContent = originalBtnText;
                    }
                }
            })
            .catch(error => {
                console.error('Comment submission error:', error);
                alert('Network error. Please try again.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    if (submitBtn.tagName === 'INPUT') submitBtn.value = originalBtnText;
                    else submitBtn.textContent = originalBtnText;
                }
            });
    });

    // Re-open comments section if returning from an AJAX reload
    const openCommentsId = sessionStorage.getItem('bitstream_open_comments');
    if (openCommentsId) {
        const targetSection = document.getElementById(openCommentsId);
        if (targetSection) {
            const bitCard = targetSection.closest('.bit-card');
            if (bitCard) {
                bitCard.classList.add('comments-open');
            }
            targetSection.classList.add('open');
            // Scroll to the section smoothly
            setTimeout(() => {
                targetSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 500);
        }
        sessionStorage.removeItem('bitstream_open_comments');
    }

    const previewGrid = document.querySelector('.bitstream-preview-grid[data-preview-auto-fill="true"]');
    if (previewGrid && window.bitstream_ajax && bitstream_ajax.ajax_url && bitstream_ajax.load_more_nonce) {
        (async () => {
            const maxPage = parseInt(previewGrid.dataset.previewMaxPage || '1', 10);
            let currentPage = parseInt(previewGrid.dataset.previewPage || '1', 10);
            let loadedCount = parseInt(previewGrid.dataset.previewLoadedCount || previewGrid.querySelectorAll('.bit-card').length || '0', 10);
            const maxPosts = parseInt(previewGrid.dataset.previewMaxPosts || '0', 10);
            let loadingPreview = false;
            const targetHeight = Math.max(window.innerHeight * 1.35, window.innerHeight + 240);

            const loadPreviewPage = async () => {
                if (loadingPreview || currentPage >= maxPage) {
                    return false;
                }

                if (maxPosts > 0 && loadedCount >= maxPosts) {
                    return false;
                }

                loadingPreview = true;
                const nextPage = currentPage + 1;
                const formData = new FormData();
                formData.append('action', 'bitstream_load_more');
                formData.append('page', String(nextPage));
                formData.append('nonce', bitstream_ajax.load_more_nonce);
                formData.append('preview_mode', '1');

                try {
                    const response = await fetch(bitstream_ajax.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    });

                    const html = await response.text();
                    const temp = document.createElement('div');
                    temp.innerHTML = html;
                    let newCards = Array.from(temp.querySelectorAll('.bit-card'));

                    if (maxPosts > 0) {
                        const remaining = maxPosts - loadedCount;
                        if (remaining <= 0) {
                            currentPage = maxPage;
                            previewGrid.dataset.previewPage = String(currentPage);
                            return false;
                        }
                        if (newCards.length > remaining) {
                            newCards = newCards.slice(0, remaining);
                        }
                    }

                    if (!newCards.length) {
                        currentPage = maxPage;
                        previewGrid.dataset.previewPage = String(currentPage);
                        return false;
                    }

                    newCards.forEach(card => previewGrid.appendChild(card));
                    loadedCount += newCards.length;
                    previewGrid.dataset.previewLoadedCount = String(loadedCount);
                    syncLikeButtonState(previewGrid);
                    currentPage = nextPage;
                    previewGrid.dataset.previewPage = String(currentPage);
                    initCommentToggles();
                    return true;
                } catch (error) {
                    console.warn('BitStream preview auto-fill failed:', error);
                    return false;
                } finally {
                    loadingPreview = false;
                }
            };

            while (previewGrid.scrollHeight < targetHeight && currentPage < maxPage) {
                if (maxPosts > 0 && loadedCount >= maxPosts) {
                    break;
                }
                const loaded = await loadPreviewPage();
                if (!loaded) {
                    break;
                }
            }
        })();
    }

    // ── Settings Tab Switching ──────────────────────────────────────────
    const settingsRoot = document.querySelector('.bitstream-settings');
    if (settingsRoot) {
        const settingsTabButtons = settingsRoot.querySelectorAll('.bitstream-settings-tab');

        settingsTabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const selectedTab = button.dataset.settingsTab;

                if (button.classList.contains('is-active')) {
                    return;
                }

                // Navigate via URL so the page reloads with fresh data
                const url = new URL(window.location.href);
                url.searchParams.set('settings_tab', selectedTab);
                window.location.href = url.toString();
            });
        });
    }

    // ── Copy-to-Clipboard for Settings RSS Feeds ────────────────────────
    document.querySelectorAll('.bitstream-copy-btn').forEach(btn => {
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
                // Fallback
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
