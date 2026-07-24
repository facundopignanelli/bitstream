(function () {
    // ═══ FULLSCREEN LIGHTBOX CONTROLLER ═══
    let lightboxEl = null;
    let lightboxMediaList = [];
    let lightboxCurrentIndex = 0;

    // DOM handoff state — tracks the timeline video element moved into the lightbox
    let lightboxOriginVideo = null;
    let lightboxOriginParent = null;
    let lightboxOriginNextSibling = null;
    let lightboxOriginStyle = '';

    function initLightbox() {
        if (document.querySelector('.bitstream-lightbox')) {
            return;
        }

        lightboxEl = document.createElement('div');
        lightboxEl.className = 'bitstream-lightbox';
        lightboxEl.setAttribute('aria-hidden', 'true');
        lightboxEl.innerHTML = `
            <button class="bitstream-lightbox-close" aria-label="Close lightbox"><i class="fa-solid fa-xmark"></i></button>
            <button class="bitstream-lightbox-nav bitstream-lightbox-nav-prev" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>
            <div class="bitstream-lightbox-stage"></div>
            <button class="bitstream-lightbox-nav bitstream-lightbox-nav-next" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>
            <div class="bitstream-lightbox-counter"></div>
        `;

        document.body.appendChild(lightboxEl);

        lightboxEl.querySelector('.bitstream-lightbox-close').addEventListener('click', closeLightbox);
        lightboxEl.querySelector('.bitstream-lightbox-nav-prev').addEventListener('click', prevLightboxItem);
        lightboxEl.querySelector('.bitstream-lightbox-nav-next').addEventListener('click', nextLightboxItem);

        lightboxEl.addEventListener('click', (e) => {
            if (e.target === lightboxEl || e.target.classList.contains('bitstream-lightbox-stage')) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (!lightboxEl.classList.contains('is-open')) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') prevLightboxItem();
            if (e.key === 'ArrowRight') nextLightboxItem();
        });
    }

    function openLightbox(mediaList, startIndex) {
        initLightbox();
        lightboxMediaList = mediaList;
        lightboxCurrentIndex = startIndex;

        lightboxEl.style.display = 'flex';
        lightboxEl.offsetHeight; // Force reflow
        lightboxEl.classList.add('is-open');
        lightboxEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        showLightboxItem(lightboxCurrentIndex);
    }
    window.bitstreamOpenLightbox = openLightbox;

    function closeLightbox() {
        if (!lightboxEl) return;
        lightboxEl.classList.remove('is-open');
        lightboxEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        const stage = lightboxEl.querySelector('.bitstream-lightbox-stage');

        if (lightboxOriginVideo) {
            // Pause and return the video node to its original position in the card
            lightboxOriginVideo.pause();
            lightboxOriginVideo.classList.remove('bitstream-lightbox-media');
            lightboxOriginVideo.setAttribute('style', lightboxOriginStyle);
            if (lightboxOriginNextSibling) {
                lightboxOriginParent.insertBefore(lightboxOriginVideo, lightboxOriginNextSibling);
            } else {
                lightboxOriginParent.appendChild(lightboxOriginVideo);
            }
            lightboxOriginVideo = null;
            lightboxOriginParent = null;
            lightboxOriginNextSibling = null;
            lightboxOriginStyle = '';
        } else if (stage) {
            const video = stage.querySelector('video');
            if (video) video.pause();
        }

        setTimeout(() => {
            if (stage && !lightboxOriginVideo) stage.innerHTML = '';
            lightboxEl.style.display = 'none';
        }, 250);
    }

    // Opens the lightbox by physically moving a timeline video node into the stage
    function openLightboxWithVideo(videoEl) {
        initLightbox();

        // Pause every other playing video on the page
        document.querySelectorAll('video').forEach(v => { if (v !== videoEl) v.pause(); });

        // Record origin so we can restore on close
        lightboxOriginVideo = videoEl;
        lightboxOriginParent = videoEl.parentNode;
        lightboxOriginNextSibling = videoEl.nextSibling;
        lightboxOriginStyle = videoEl.getAttribute('style') || '';

        const stage = lightboxEl.querySelector('.bitstream-lightbox-stage');
        stage.innerHTML = '';

        // Apply lightbox media class; clear any card-specific inline sizing so CSS governs
        videoEl.classList.add('bitstream-lightbox-media');
        videoEl.style.removeProperty('width');
        videoEl.style.removeProperty('height');
        videoEl.style.removeProperty('max-height');
        videoEl.style.removeProperty('margin');
        videoEl.style.setProperty('max-width', '100%', 'important');
        videoEl.style.setProperty('border-radius', '4px', 'important');
        stage.appendChild(videoEl);

        const counter = lightboxEl.querySelector('.bitstream-lightbox-counter');
        const prevBtn = lightboxEl.querySelector('.bitstream-lightbox-nav-prev');
        const nextBtn = lightboxEl.querySelector('.bitstream-lightbox-nav-next');
        counter.textContent = '';
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';

        lightboxMediaList = [];
        lightboxCurrentIndex = 0;

        lightboxEl.style.display = 'flex';
        lightboxEl.offsetHeight; // force reflow for transition
        lightboxEl.classList.add('is-open');
        lightboxEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function showLightboxItem(index) {
        if (!lightboxEl || lightboxMediaList.length === 0) return;

        if (index < 0) index = lightboxMediaList.length - 1;
        if (index >= lightboxMediaList.length) index = 0;
        lightboxCurrentIndex = index;

        const media = lightboxMediaList[lightboxCurrentIndex];
        const stage = lightboxEl.querySelector('.bitstream-lightbox-stage');
        const counter = lightboxEl.querySelector('.bitstream-lightbox-counter');
        const prevBtn = lightboxEl.querySelector('.bitstream-lightbox-nav-prev');
        const nextBtn = lightboxEl.querySelector('.bitstream-lightbox-nav-next');

        stage.innerHTML = '';

        if (media.mime.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = media.url;
            img.className = 'bitstream-lightbox-media';
            img.alt = '';
            stage.appendChild(img);
        } else if (media.mime.startsWith('video/')) {
            const video = document.createElement('video');
            video.src = media.url;
            video.className = 'bitstream-lightbox-media';
            video.controls = true;
            video.autoplay = true;
            video.setAttribute('controlsList', 'nodownload');
            video.setAttribute('playsinline', '');

            const resizeVideo = () => {
                if (!video.videoWidth || !video.videoHeight) return;
                const stageEl = video.closest('.bitstream-lightbox-stage');
                if (!stageEl) return;

                const stageWidth = stageEl.clientWidth;
                const stageHeight = stageEl.clientHeight;
                if (!stageWidth || !stageHeight) return;

                const videoRatio = video.videoWidth / video.videoHeight;
                const stageRatio = stageWidth / stageHeight;

                if (videoRatio > stageRatio) {
                    video.style.setProperty('width', '100%', 'important');
                    video.style.setProperty('height', 'auto', 'important');
                } else {
                    video.style.setProperty('width', 'auto', 'important');
                    video.style.setProperty('height', '100%', 'important');
                }
            };

            video.addEventListener('loadedmetadata', resizeVideo);
            window.addEventListener('resize', resizeVideo);

            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.removedNodes.forEach((node) => {
                        if (node === video) {
                            window.removeEventListener('resize', resizeVideo);
                            observer.disconnect();
                        }
                    });
                });
            });
            observer.observe(stage, { childList: true });

            stage.appendChild(video);
        }

        counter.textContent = `${lightboxCurrentIndex + 1} / ${lightboxMediaList.length}`;

        const hasMultiple = lightboxMediaList.length > 1;
        prevBtn.style.display = hasMultiple ? 'flex' : 'none';
        nextBtn.style.display = hasMultiple ? 'flex' : 'none';

        // Parse emojis in counter/stage if custom emoji titles are present
        if (typeof window.parseEmojis === 'function') {
            window.parseEmojis(stage);
        }
    }

    function prevLightboxItem() {
        showLightboxItem(lightboxCurrentIndex - 1);
    }

    function nextLightboxItem() {
        showLightboxItem(lightboxCurrentIndex + 1);
    }

    // Export namespace
    window.BitStream = window.BitStream || {};
    window.BitStream.Lightbox = {
        init: function () {
            document.body.addEventListener('click', (e) => {
                // 1. Timeline Gallery / Single Media item clicks
                let item = e.target.closest('.bitstream-gallery-media, .bitstream-gallery-overlay, .bitstream-gallery-item-has-overlay');
                let isSingleMedia = false;

                if (!item) {
                    const singleMedia = e.target.closest([
                        '.bit-card-content img:not(.emoji)',
                        '.bit-card-content video',
                        '.bit-rebit-preview img:not(.emoji)',
                        '.bit-rebit-preview video',
                        '.bitstream-quoted-preview img:not(.emoji)',
                        '.bitstream-quoted-preview video'
                    ].join(','));

                    if (singleMedia && !singleMedia.closest('.bitstream-gallery') && !singleMedia.closest('.bitstream-media-preview-item')) {
                        item = singleMedia;
                        isSingleMedia = true;
                    }
                }

                if (item) {
                    const gallery = item.closest('.bitstream-gallery');
                    if (gallery) {
                        e.preventDefault();
                        e.stopPropagation();

                        const mediaElements = gallery.querySelectorAll('.bitstream-gallery-media');
                        const mediaList = Array.from(mediaElements).map(el => {
                            return {
                                url: el.src || el.getAttribute('src'),
                                mime: el.dataset.mime || (el.tagName.toLowerCase() === 'video' ? 'video/mp4' : 'image/jpeg')
                            };
                        });

                        let clickIndex = parseInt(item.dataset.index || item.querySelector('[data-index]')?.dataset.index || '0', 10);
                        if (isNaN(clickIndex)) clickIndex = 0;

                        openLightbox(mediaList, clickIndex);
                        return;
                    } else if (isSingleMedia) {
                        if (item.tagName.toLowerCase() === 'video' && item.hasAttribute('controls')) {
                            const rect = item.getBoundingClientRect();
                            const clickY = e.clientY - rect.top;
                            const controlsHeight = 50; // estimate of native control bar height
                            if (clickY > rect.height - controlsHeight) {
                                return;
                            }
                            // Video clicked outside controls — hand it off to the lightbox
                            e.preventDefault();
                            e.stopPropagation();
                            openLightboxWithVideo(item);
                            return;
                        }

                        e.preventDefault();
                        e.stopPropagation();

                        const mediaList = [{
                            url: item.src || item.getAttribute('src') || item.currentSrc,
                            mime: item.dataset.mime || (item.tagName.toLowerCase() === 'video' ? 'video/mp4' : 'image/jpeg')
                        }];

                        openLightbox(mediaList, 0);
                        return;
                    }
                }

                // 2. Composer/Modal Preview item clicks
                const previewItem = e.target.closest('.bitstream-media-preview-item');
                if (previewItem && !e.target.closest('.bitstream-media-preview-remove-item')) {
                    const grid = previewItem.closest('.bitstream-media-preview-grid');
                    if (grid) {
                        e.preventDefault();
                        e.stopPropagation();

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
                        openLightbox(mediaList, clickIndex);
                    }
                }
            });
        },
        open: openLightbox,
        close: closeLightbox,
        openWithVideo: openLightboxWithVideo
    };
})();
