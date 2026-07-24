(function () {
    let cropperModal = null;
    let cropperImage = null;
    let cropperSelection = null;
    let cropperStage = null;
    let cropperApply = null;
    let cropperSizeLabel = null;
    let cropperCloseButtons = [];
    let cropperState = null;

    function initCropperElements() {
        if (cropperModal) return;
        cropperModal = document.querySelector('.bitstream-cropper-modal');
        if (!cropperModal) return;
        cropperImage = cropperModal.querySelector('.bitstream-cropper-image');
        cropperSelection = cropperModal.querySelector('.bitstream-cropper-selection');
        cropperStage = cropperModal.querySelector('.bitstream-cropper-stage');
        cropperApply = cropperModal.querySelector('.bitstream-cropper-apply');
        cropperSizeLabel = cropperModal.querySelector('.bitstream-cropper-size');
        cropperCloseButtons = cropperModal.querySelectorAll('[data-cropper-close="true"]');
        
        bindCropperEvents();
    }

    function setStatus(msg, isError = false) {
        const statusEl = document.querySelector('.bitstream-sidebar-composer-status');
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.color = isError ? '#cc0000' : '#2c6e49';
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

        const rect = cropperImage.getBoundingClientRect();
        const stageRect = cropperStage.getBoundingClientRect();

        const clampX = (val) => Math.max(0, Math.min(rect.width, val));
        const clampY = (val) => Math.max(0, Math.min(rect.height, val));

        const x = clampX(selection.x);
        const y = clampY(selection.y);
        const w = Math.min(rect.width - x, selection.width);
        const h = Math.min(rect.height - y, selection.height);

        cropperSelection.style.left = (x + (rect.left - stageRect.left)) + 'px';
        cropperSelection.style.top = (y + (rect.top - stageRect.top)) + 'px';
        cropperSelection.style.width = w + 'px';
        cropperSelection.style.height = h + 'px';
        cropperSelection.style.display = 'block';

        if (cropperSizeLabel) {
            const scaleX = cropperImage.naturalWidth / rect.width;
            const scaleY = cropperImage.naturalHeight / rect.height;
            cropperSizeLabel.textContent = 'Size: ' + Math.round(w * scaleX) + ' × ' + Math.round(h * scaleY);
        }
    }

    function openCropper(targetInputId, targetPreviewId, options = {}) {
        initCropperElements();
        setStatus('');
        if (!cropperModal || !cropperImage || !cropperStage) {
            setStatus('Cropper is unavailable on this page.', true);
            return;
        }

        let attachmentId = 0;
        let url = '';
        if (targetInputId) {
            const input = document.getElementById(targetInputId);
            attachmentId = input ? parseInt(input.value || '0', 10) : 0;
        }

        if (options.attachmentId) {
            attachmentId = options.attachmentId;
        }

        if (options.url) {
            url = options.url;
        } else {
            const preview = targetPreviewId ? document.getElementById(targetPreviewId) : null;
            const mediaNode = preview ? preview.querySelector('img, video') : null;
            url = mediaNode ? mediaNode.src : '';
        }

        if (!attachmentId || !url) {
            setStatus('No image loaded to crop.', true);
            return;
        }

        cropperState = {
            targetInputId: targetInputId,
            targetPreviewId: targetPreviewId,
            attachmentId: attachmentId,
            enforceSquare: !!options.enforceSquare,
            onComplete: options.onComplete,
            startX: 0,
            startY: 0,
            mode: null,
            handle: null,
            selection: null
        };

        setStatus('Loading cropper image...');
        cropperImage.src = url;

        cropperImage.onload = () => {
            setStatus('');
            const rect = cropperImage.getBoundingClientRect();

            // Set initial square selection in the center
            const side = Math.min(rect.width, rect.height) * 0.8;
            cropperState.selection = {
                x: (rect.width - side) / 2,
                y: (rect.height - side) / 2,
                width: side,
                height: side
            };

            updateSelectionBox(cropperState.selection);
            cropperModal.classList.toggle('is-square-mode', !!cropperState.enforceSquare);
            cropperModal.hidden = false;
            document.body.classList.add('bitstream-cropper-open');
        };

        cropperImage.onerror = () => {
            setStatus('Failed to load image for cropping.', true);
            closeCropper();
        };
    }
    window.bitstreamOpenCropper = openCropper;

    function getRelativePos(e) {
        if (!cropperImage) return { x: 0, y: 0 };
        const rect = cropperImage.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return {
            x: clientX - rect.left,
            y: clientY - rect.top
        };
    }

    function beginSelection(e) {
        if (!cropperState || !cropperImage || !cropperSelection) {
            return;
        }

        const target = e.target;
        const handle = target.dataset.handle;
        const pos = getRelativePos(e);

        cropperState.startX = pos.x;
        cropperState.startY = pos.y;
        cropperState.handle = handle;

        if (handle) {
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

        const moveHandler = (evt) => {
            if (evt.cancelable) evt.preventDefault();
            const p = getRelativePos(evt);
            const rect = cropperImage.getBoundingClientRect();
            const maxX = rect.width;
            const maxY = rect.height;

            const clamp = (val, min, max) => Math.max(min, Math.min(max, val));

            if (cropperState.mode === 'create') {
                const x1 = clamp(cropperState.startX, 0, maxX);
                const y1 = clamp(cropperState.startY, 0, maxY);
                const x2 = clamp(p.x, 0, maxX);
                const y2 = clamp(p.y, 0, maxY);

                if (cropperState.enforceSquare) {
                    const w = x2 - x1;
                    const h = y2 - y1;
                    const side = Math.min(Math.abs(w), Math.abs(h));
                    cropperState.selection = {
                        x: w < 0 ? x1 - side : x1,
                        y: h < 0 ? y1 - side : y1,
                        width: side,
                        height: side
                    };
                } else {
                    cropperState.selection = {
                        x: Math.min(x1, x2),
                        y: Math.min(y1, y2),
                        width: Math.abs(x2 - x1),
                        height: Math.abs(y2 - y1)
                    };
                }
            } else if (cropperState.mode === 'move') {
                const deltaX = p.x - cropperState.startX;
                const deltaY = p.y - cropperState.startY;

                const selection = cropperState.selection;
                selection.x = clamp(selection.x + deltaX, 0, maxX - selection.width);
                selection.y = clamp(selection.y + deltaY, 0, maxY - selection.height);

                cropperState.startX = p.x;
                cropperState.startY = p.y;
            } else if (cropperState.mode === 'resize') {
                const handleName = cropperState.handle;
                const selection = cropperState.selection;

                if (cropperState.enforceSquare) {
                    const deltaX = p.x - cropperState.startX;
                    const deltaY = p.y - cropperState.startY;
                    const sideChange = Math.abs(deltaX) > Math.abs(deltaY) ? deltaX : deltaY;

                    if (handleName === 'br') {
                        const side = clamp(Math.min(selection.width + sideChange, selection.height + sideChange), 10, Math.min(maxX - selection.x, maxY - selection.y));
                        selection.width = side;
                        selection.height = side;
                    }
                    // Square resize only handles 'br' drag for simplicity in square mode
                } else {
                    if (handleName.includes('e')) {
                        selection.width = clamp(p.x - selection.x, 10, maxX - selection.x);
                    }
                    if (handleName.includes('s')) {
                        selection.height = clamp(p.y - selection.y, 10, maxY - selection.y);
                    }
                    if (handleName.includes('w')) {
                        const originalRight = selection.x + selection.width;
                        selection.x = clamp(p.x, 0, originalRight - 10);
                        selection.width = originalRight - selection.x;
                    }
                    if (handleName.includes('n')) {
                        const originalBottom = selection.y + selection.height;
                        selection.y = clamp(p.y, 0, originalBottom - 10);
                        selection.height = originalBottom - selection.y;
                    }
                }
                cropperState.startX = p.x;
                cropperState.startY = p.y;
            }

            updateSelectionBox(cropperState.selection);
        };

        const upHandler = () => {
            if (!cropperState) return;
            cropperState.mode = null;
            cropperState.handle = null;
            document.removeEventListener('pointermove', moveHandler);
            document.removeEventListener('pointerup', upHandler);
            document.removeEventListener('mousemove', moveHandler);
            document.removeEventListener('mouseup', upHandler);
            document.removeEventListener('touchmove', moveHandler);
            document.removeEventListener('touchend', upHandler);
        };

        if (e.pointerId) {
            document.addEventListener('pointermove', moveHandler);
            document.addEventListener('pointerup', upHandler);
        } else {
            document.addEventListener('mousemove', moveHandler);
            document.addEventListener('mouseup', upHandler);
            document.addEventListener('touchmove', moveHandler, { passive: false });
            document.addEventListener('touchend', upHandler);
        }
    }

    function applyCrop() {
        if (!cropperState || !cropperState.selection || !cropperImage) {
            return;
        }

        if (!window.bitstream_ajax || !bitstream_ajax.ajax_url || !bitstream_ajax.media_crop_nonce) {
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

                if (!cropperState) {
                    return;
                }

                if (cropperState.targetInputId === 'bitstream-rebit-attachment-id') {
                    const rebitOgImageInput = document.querySelector('#bitstream-rebit-og-image');
                    const rebitOgImageRemovedInput = document.querySelector('#bitstream-rebit-og-image-removed');
                    if (rebitOgImageInput) {
                        rebitOgImageInput.value = url || '';
                    }
                    if (rebitOgImageRemovedInput) {
                        rebitOgImageRemovedInput.value = '0';
                    }
                }

                if (typeof cropperState.onComplete === 'function') {
                    cropperState.onComplete(croppedMedia, url);
                } else if (typeof window.handleMediaSelection === 'function') {
                    window.handleMediaSelection(cropperState.targetInputId, cropperState.targetPreviewId, croppedMedia);
                }

                if (cropperState.targetInputId === 'bitstream-rebit-attachment-id') {
                    if (typeof window.refreshRebitEditorImagePreview === 'function') {
                        window.refreshRebitEditorImagePreview();
                    }
                }

                setStatus('Image cropped.');
                closeCropper();
            })
            .catch(error => {
                setStatus(error.message || 'Crop failed.', true);
            });
    }

    function bindCropperEvents() {
        if (cropperStage && cropperSelection) {
            if (window.PointerEvent) {
                cropperStage.addEventListener('pointerdown', beginSelection);
                cropperSelection.addEventListener('pointerdown', beginSelection);
            } else {
                cropperStage.addEventListener('mousedown', beginSelection);
                cropperSelection.addEventListener('mousedown', beginSelection);
                cropperStage.addEventListener('touchstart', beginSelection, { passive: false });
                cropperSelection.addEventListener('touchstart', beginSelection, { passive: false });
            }
        }

        if (cropperCloseButtons) {
            cropperCloseButtons.forEach(button => {
                button.addEventListener('click', closeCropper);
            });
        }

        if (cropperApply) {
            cropperApply.addEventListener('click', applyCrop);
        }
    }

    // Export namespace
    window.BitStream = window.BitStream || {};
    window.BitStream.Cropper = {
        init: function () {
            initCropperElements();
        },
        open: openCropper,
        close: closeCropper
    };
})();
