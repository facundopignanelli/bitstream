(function () {
    const BITSTREAM_IMAGE_UPLOAD_MAX_DIMENSION = 2200;
    const BITSTREAM_IMAGE_UPLOAD_MAX_BYTES = 2.5 * 1024 * 1024;
    const BITSTREAM_IMAGE_UPLOAD_QUALITY = 0.86;
    const BITSTREAM_CHUNKED_UPLOAD_THRESHOLD = 5 * 1024 * 1024;
    const BITSTREAM_UPLOAD_CHUNK_SIZE = 5 * 1024 * 1024;

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
                // Fall back to image loading
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

    function getExistingAttachmentIds(targetInputId) {
        if (!targetInputId) return [];
        const inputId = targetInputId.endsWith('s') ? targetInputId : targetInputId + 's';
        const input = document.getElementById(inputId) || document.querySelector('.' + inputId) || document.querySelector('[name="' + targetInputId.replace('_id', '_ids') + '"]');
        if (!input || !input.value) return [];
        return input.value.split(',').map(id => parseInt(id, 10)).filter(Boolean);
    }
    window.getExistingAttachmentIds = getExistingAttachmentIds;

    function getPreviewElement(targetPreviewId) {
        if (typeof targetPreviewId === 'string') {
            return document.getElementById(targetPreviewId) || document.querySelector(targetPreviewId) || document.querySelector('.' + targetPreviewId);
        }
        return targetPreviewId;
    }
    window.getPreviewElement = getPreviewElement;

    function getExistingAttachments(previewEl) {
        if (!previewEl || !previewEl.dataset.attachmentsJson) return [];
        try {
            return JSON.parse(previewEl.dataset.attachmentsJson);
        } catch (e) {
            return [];
        }
    }
    window.getExistingAttachments = getExistingAttachments;

    function updateAttachmentsList(previewEl, attachments) {
        if (!previewEl) return;

        const form = previewEl.closest('form, .bitstream-media-field, .bitstream-composer');
        if (!form) return;

        const targetInput = form.querySelector('.bs-edit-attachment-id') || form.querySelector('#bitstream-composer-attachment-id');
        const targetInputs = form.querySelector('.bs-edit-attachment-ids') || form.querySelector('#bitstream-composer-attachment-ids');
        const removeButton = form.querySelector('.bitstream-media-remove') || form.querySelector('.bitstream-composer-preview-remove[data-composer-remove="media"]');
        const cropButton = form.querySelector('.bitstream-media-crop') || form.querySelector('.bitstream-composer-preview-edit[data-composer-edit="media"]');

        if (attachments.length > 10) {
            alert('You can attach up to 10 images or videos.');
            attachments = attachments.slice(0, 10);
        }

        const firstId = attachments.length > 0 ? attachments[0].id : 0;
        const allIds = attachments.map(item => item.id).join(',');

        if (targetInput) {
            targetInput.value = firstId > 0 ? String(firstId) : '';
            targetInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (targetInputs) {
            targetInputs.value = allIds;
            targetInputs.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const dropzone = previewEl.closest('.bitstream-media-dropzone');
        if (dropzone) {
            dropzone.classList.toggle('has-media', attachments.length > 0);
        }

        previewEl.dataset.attachmentsJson = JSON.stringify(attachments);
        renderMultiplePreviews(previewEl, attachments);

        if (removeButton) {
            removeButton.classList.toggle('is-hidden', attachments.length === 0);
        }
        if (cropButton) {
            const isSingleImage = attachments.length === 1 && attachments[0].mime && attachments[0].mime.startsWith('image/');
            cropButton.hidden = !isSingleImage;
            cropButton.style.display = isSingleImage ? '' : 'none';
        }

        const previewMedia = form.querySelector('.bitstream-composer-preview-media');
        const previewArea = form.querySelector('.bitstream-composer-preview-area');
        if (previewMedia && previewArea) {
            previewMedia.hidden = attachments.length === 0;
            const hasVisiblePreviews = Array.from(previewArea.children).some(child => !child.hidden);
            previewArea.hidden = !hasVisiblePreviews;
        }

        if (previewEl.id === 'bs-edit-bit-media-preview' || previewEl.classList.contains('bs-edit-media-preview-thumb')) {
            const editMediaWrap = previewEl.closest('.bs-edit-media');
            if (editMediaWrap) {
                editMediaWrap.hidden = attachments.length === 0;
            }
            if (typeof window.syncEditPreviewArea === 'function') {
                window.syncEditPreviewArea();
            }
        }
    }
    window.updateAttachmentsList = updateAttachmentsList;

    function renderMultiplePreviews(previewEl, attachments) {
        if (!previewEl) return;

        if (!previewEl.dataset.clickBound) {
            previewEl.dataset.clickBound = '1';
            previewEl.addEventListener('click', (e) => {
                const previewItem = e.target.closest('.bitstream-media-preview-item');
                if (previewItem && !e.target.closest('.bitstream-media-preview-remove-item') && !e.target.closest('.bitstream-media-preview-crop-item')) {
                    e.stopPropagation();
                    e.preventDefault();

                    const grid = previewItem.closest('.bitstream-media-preview-grid');
                    if (grid) {
                        const allItems = Array.from(grid.querySelectorAll('.bitstream-media-preview-item'));
                        const mediaItems = allItems.filter(item => item.querySelector('img, video'));
                        if (!mediaItems.includes(previewItem)) return;

                        const mediaList = mediaItems.map(item => {
                            const el = item.querySelector('img, video');
                            return {
                                url: el.src || el.getAttribute('src'),
                                mime: el.tagName.toLowerCase() === 'video' ? 'video/mp4' : 'image/jpeg'
                            };
                        });

                        const clickIndex = mediaItems.indexOf(previewItem);
                        if (typeof window.bitstreamOpenLightbox === 'function') {
                            window.bitstreamOpenLightbox(mediaList, clickIndex);
                        }
                    }
                }
            });
        }

        if (attachments.length === 0) {
            previewEl.innerHTML = '';
            previewEl.classList.remove('has-multiple');
            return;
        }

        previewEl.innerHTML = '';
        previewEl.classList.add('has-multiple');

        const grid = document.createElement('div');
        grid.className = 'bitstream-media-preview-grid';

        attachments.forEach((item, index) => {
            const wrap = document.createElement('div');
            wrap.className = 'bitstream-media-preview-item';
            wrap.dataset.id = item.id;
            wrap.dataset.index = index;

            const url = item.preview_url || (item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url);
            const mime = item.mime || '';

            if (mime.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = url;
                img.alt = '';
                wrap.appendChild(img);

                const cropBtn = document.createElement('button');
                cropBtn.type = 'button';
                cropBtn.className = 'bitstream-media-preview-crop-item';
                cropBtn.title = 'Crop image';
                cropBtn.setAttribute('aria-label', 'Crop image');
                cropBtn.innerHTML = '<i class="fa-solid fa-crop-simple"></i>';
                cropBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    const openCropperFn = window.bitstreamOpenCropper || (window.BitStream && window.BitStream.UI && window.BitStream.UI.openCropper);
                    if (openCropperFn) {
                        const targetInput = previewEl.closest('form') ? (previewEl.closest('form').querySelector('.bs-edit-attachment-id, #bitstream-composer-attachment-id')) : null;
                        const targetInputId = targetInput ? targetInput.id : 'bitstream-composer-attachment-id';
                        openCropperFn(targetInputId, previewEl.id || previewEl, {
                            attachmentId: item.id,
                            url: item.preview_url || item.url,
                            onComplete: (croppedMedia) => {
                                if (croppedMedia && croppedMedia.id) {
                                    const currentAttachments = getExistingAttachments(previewEl);
                                    const updated = currentAttachments.map(att => parseInt(att.id, 10) === parseInt(item.id, 10) ? croppedMedia : att);
                                    updateAttachmentsList(previewEl, updated);
                                }
                            }
                        });
                    }
                });
                wrap.appendChild(cropBtn);
            } else if (mime.startsWith('video/')) {
                const video = document.createElement('video');
                video.src = url;
                video.muted = true;
                video.playsInline = true;
                wrap.appendChild(video);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'bitstream-media-preview-remove-item';
            removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const currentAttachments = getExistingAttachments(previewEl);
                const updated = currentAttachments.filter(att => parseInt(att.id, 10) !== parseInt(item.id, 10));
                updateAttachmentsList(previewEl, updated);
            });

            wrap.appendChild(removeBtn);
            grid.appendChild(wrap);
        });

        previewEl.appendChild(grid);
    }
    window.renderMultiplePreviews = renderMultiplePreviews;

    async function scaleAndCompressImage(file, mimeType) {
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
            formData.append('filename', file.name);
            formData.append('mime_type', mimeType);
            formData.append('chunk_data', chunk);

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
        }

        throw new Error('Upload did not finish.');
    }

    async function uploadMultipleFiles(files, targetInputId, targetPreviewId, options = {}) {
        const setStatusFn = options.setStatus || console.log;
        setStatusFn('', false);

        if (!bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
            setStatusFn('Media upload is unavailable.', true);
            return;
        }

        const isRebit = targetInputId && (targetInputId.indexOf('rebit') !== -1);

        const validFiles = Array.from(files).filter(file => {
            const mimeType = getUploadMimeType(file);
            return mimeType.startsWith('image/') || mimeType.startsWith('video/');
        });

        if (validFiles.length === 0) {
            setStatusFn('Please select images or videos to upload.', true);
            return;
        }

        const previewEl = getPreviewElement(targetPreviewId);
        const existingAttachments = getExistingAttachments(previewEl);

        if (!isRebit && existingAttachments.length + validFiles.length > 10) {
            setStatusFn('You can attach up to 10 images or videos.', true);
            return;
        }

        const attachmentsToSave = isRebit ? [] : [...existingAttachments];

        for (let i = 0; i < validFiles.length; i++) {
            let file = validFiles[i];
            const mimeType = getUploadMimeType(file);
            const indexLabel = validFiles.length > 1 ? ` (${i + 1}/${validFiles.length})` : '';

            setStatusFn(`Uploading image...${indexLabel}`);

            if (mimeType.startsWith('image/') && file.size > BITSTREAM_IMAGE_UPLOAD_MAX_BYTES) {
                file = await scaleAndCompressImage(file, mimeType);
            }

            setStatusFn(`Uploading... 0%${indexLabel}`);

            try {
                const response = await uploadMediaRequest(file, (percent, label) => {
                    setStatusFn(`${label}${indexLabel}`);
                });

                if (response && response.id) {
                    attachmentsToSave.push(response);
                    updateAttachmentsList(previewEl, attachmentsToSave);

                    if (typeof options.onSingleComplete === 'function') {
                        options.onSingleComplete(response, file);
                    }
                }
            } catch (err) {
                console.error('BitStream: media upload error:', err);
                setStatusFn(err.message || 'Upload failed.', true);
                return;
            }
        }

        setStatusFn('');
        if (typeof options.onAllComplete === 'function') {
            options.onAllComplete(attachmentsToSave);
        }
    }
    window.uploadMultipleFiles = uploadMultipleFiles;

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
    window.applyMediaDeterrents = applyMediaDeterrents;

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
    function createUploadId() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'upload-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);
    }

    function sendAjaxFormData(formData, onProgress) {
        return new Promise((resolve, reject) => {
            if (!window.bitstream_ajax || !bitstream_ajax.ajax_url) {
                reject(new Error('AJAX endpoint unavailable.'));
                return;
            }
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
        }

        throw new Error('Upload did not finish.');
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

    async function uploadMultipleFiles(files, targetInputId, targetPreviewId, options = {}) {
        const setStatusFn = options.setStatus || console.log;
        setStatusFn('', false);

        if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_upload_nonce) {
            setStatusFn('Media upload is unavailable.', true);
            alert('Media upload is unavailable.');
            return;
        }

        const isRebit = targetInputId && (targetInputId.indexOf('rebit') !== -1);

        const validFiles = Array.from(files).filter(file => {
            const mimeType = getUploadMimeType(file);
            return mimeType.startsWith('image/') || mimeType.startsWith('video/');
        });

        if (validFiles.length === 0) {
            setStatusFn('Unsupported file format. Only images and videos are allowed.', true);
            alert('Unsupported file format. Only images and videos are allowed.');
            return;
        }

        const existingIds = getExistingAttachmentIds(targetInputId);
        const currentCount = existingIds.length;
        if (!isRebit && currentCount + validFiles.length > 10) {
            setStatusFn('You can attach up to 10 images or videos.', true);
            alert('You can attach up to 10 images or videos.');
            return;
        }

        const progressContainer = document.querySelector(`[data-progress-bar="${targetInputId}"]`) || document.querySelector('.bitstream-media-progress-container');
        const progressBar = progressContainer ? progressContainer.querySelector('.bitstream-media-progress-bar') : null;
        const progressText = progressContainer ? progressContainer.querySelector('.bitstream-media-progress-text') : null;

        const showProgress = () => {
            if (!progressContainer) return;
            progressContainer.classList.remove('is-hidden');
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

        try {
            const loadedAttachments = [];
            const totalFiles = validFiles.length;

            for (let i = 0; i < totalFiles; i++) {
                const file = validFiles[i];
                const fileNum = i + 1;
                const basePercent = Math.round((i / totalFiles) * 100);
                const fileLabel = totalFiles > 1 ? `Uploading file ${fileNum} of ${totalFiles}...` : 'Uploading media...';
                updateProgress(Math.max(4, basePercent), fileLabel);

                const uploadFile = await prepareMediaFileForUpload(file);
                const media = await uploadMediaRequest(uploadFile, (percent, text) => {
                    const overallPercent = Math.min(99, Math.round(((i + (percent / 100)) / totalFiles) * 100));
                    const statusMsg = totalFiles > 1 
                        ? `Uploading file ${fileNum} of ${totalFiles} (${overallPercent}%)...` 
                        : `Uploading... ${overallPercent}%`;
                    updateProgress(overallPercent, statusMsg);
                });

                loadedAttachments.push({
                    id: media.id,
                    url: media.url,
                    preview_url: media.preview_url || media.url,
                    mime: media.mime,
                    filename: file.name
                });
            }

            updateProgress(100, 'Upload complete!');

            const previewEl = getPreviewElement(targetPreviewId);
            const existingAttachments = getExistingAttachments(previewEl);
            let finalAttachments = [];
            if (isRebit) {
                finalAttachments = loadedAttachments.slice(0, 1);
            } else {
                finalAttachments = [...existingAttachments, ...loadedAttachments].slice(0, 10);
            }

            updateAttachmentsList(previewEl, finalAttachments);
            setStatusFn('', false);
            setTimeout(() => {
                hideProgress();
            }, 1000);
        } catch (error) {
            hideProgress();
            setStatusFn(error.message || 'Upload failed.', true);
            alert(error.message || 'Upload failed.');
        }
    }
    window.uploadMultipleFiles = uploadMultipleFiles;

    function openMediaLibrary(targetInputId, targetPreviewId) {
        if (!window.wp || !wp.media) {
            alert('Media library is unavailable on this page.');
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
            const existingAttachments = getExistingAttachments(previewEl);
            let finalAttachments = [];
            if (isRebit) {
                finalAttachments = loadedAttachments.slice(0, 1);
            } else {
                finalAttachments = [...existingAttachments, ...loadedAttachments].slice(0, 10);
            }

            updateAttachmentsList(previewEl, finalAttachments);
        });

        frame.open();
    }
    window.openMediaLibrary = openMediaLibrary;

    // Global Event Listeners for dropzone & library button interaction
    document.addEventListener('click', (event) => {
        const libraryButton = event.target.closest('.bitstream-media-library, .bitstream-media-choose-library');
        if (libraryButton) {
            event.preventDefault();
            const targetInputId = libraryButton.dataset.targetInput || 'bitstream-composer-modal-media-attachment-id';
            const targetPreviewId = libraryButton.dataset.targetPreview || 'bitstream-composer-modal-media-preview';
            openMediaLibrary(targetInputId, targetPreviewId);
            return;
        }

        const dropzone = event.target.closest('.bitstream-media-dropzone');
        if (dropzone) {
            const input = dropzone.querySelector('.bitstream-media-file');
            if (input && event.target !== input) {
                if (event.target.closest('.bitstream-media-preview-item') ||
                    event.target.closest('.bitstream-media-preview-remove-item')) {
                    return;
                }
                event.preventDefault();
                input.click();
            }
        }
    });

    document.addEventListener('change', (event) => {
        if (event.target && event.target.classList && event.target.classList.contains('bitstream-media-file')) {
            const input = event.target;
            const dropzone = input.closest('.bitstream-media-dropzone');
            const targetInputId = input.dataset.targetInput || (dropzone ? dropzone.dataset.targetInput : 'bitstream-composer-modal-media-attachment-id');
            const targetPreviewId = input.dataset.targetPreview || (dropzone ? dropzone.dataset.targetPreview : 'bitstream-composer-modal-media-preview');

            const files = input.files;
            if (files && files.length > 0) {
                uploadMultipleFiles(Array.from(files), targetInputId, targetPreviewId);
            }
            input.value = '';
        }
    });

    document.addEventListener('dragover', (event) => {
        const dropzone = event.target.closest('.bitstream-media-dropzone');
        if (dropzone) {
            event.preventDefault();
            dropzone.classList.add('is-dragover');
        }
    });

    document.addEventListener('dragleave', (event) => {
        const dropzone = event.target.closest('.bitstream-media-dropzone');
        if (dropzone) {
            dropzone.classList.remove('is-dragover');
        }
    });

    document.addEventListener('drop', (event) => {
        const dropzone = event.target.closest('.bitstream-media-dropzone');
        if (dropzone) {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');

            const input = dropzone.querySelector('.bitstream-media-file');
            const targetInputId = dropzone ? dropzone.dataset.targetInput : (input ? input.dataset.targetInput : 'bitstream-composer-modal-media-attachment-id');
            const targetPreviewId = dropzone ? dropzone.dataset.targetPreview : (input ? input.dataset.targetPreview : 'bitstream-composer-modal-media-preview');

            const files = event.dataTransfer.files;
            if (files && files.length > 0) {
                uploadMultipleFiles(Array.from(files), targetInputId, targetPreviewId);
            }
        }
    });

    // Export namespace
    window.BitStream = window.BitStream || {};
    window.BitStream.Media = {
        getExistingAttachmentIds: getExistingAttachmentIds,
        getPreviewElement: getPreviewElement,
        getExistingAttachments: getExistingAttachments,
        updateAttachmentsList: updateAttachmentsList,
        renderMultiplePreviews: renderMultiplePreviews,
        uploadMultipleFiles: uploadMultipleFiles,
        applyMediaDeterrents: applyMediaDeterrents,
        openMediaLibrary: openMediaLibrary,
    };
    window.BitStream.Uploader = {
        init: function () {}
    };
})();
