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
        const quoteUrl = bitstream_ajax.admin_url + 'post-new.php?post_type=bit&quoted_bit=' + postId;
        
        // Open quote editor in new tab/window
        window.open(quoteUrl, '_blank');
        
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

    // Masonry layout implementation
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
        const columnWidth = cards[0].offsetWidth;
        const columnHeights = new Array(columns).fill(0);

        cards.forEach((card, index) => {
            // Ensure card dimensions are calculated
            const cardHeight = card.offsetHeight || card.getBoundingClientRect().height;
            
            // Find the shortest column
            const shortestColumn = columnHeights.indexOf(Math.min(...columnHeights));
            
            // Position the card
            const x = shortestColumn * (columnWidth + gap);
            const y = columnHeights[shortestColumn];
            
            card.style.position = 'absolute';
            card.style.left = x + 'px';
            card.style.top = y + 'px';
            card.style.width = columnWidth + 'px';
            card.style.zIndex = '2';
            
            // Update column height
            columnHeights[shortestColumn] += cardHeight + gap;
        });

        // Set container height with some padding to prevent overlap
        const maxHeight = Math.max(...columnHeights);
        feed.style.height = (maxHeight + gap) + 'px';
        feed.style.position = 'relative';
        feed.style.overflow = 'hidden';
    };

    // Initialize masonry on load
    setTimeout(window.initMasonry, 100);

    // Reinitialize on window resize
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(window.initMasonry, 250);
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
            
            // Reinitialize masonry layout with new cards
            setTimeout(window.initMasonry, 100);
            
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
