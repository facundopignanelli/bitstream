document.addEventListener('DOMContentLoaded', function () {
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
    // Comment Toggle button
document.querySelectorAll('.bit-comment-toggle').forEach(button => {
    button.addEventListener('click', () => {
        const targetId = button.dataset.target;
        const section = document.getElementById(targetId);
        if (section) {
            section.classList.toggle('open');
        }
    });
});

    // Quick Post validation
    const quickForm = document.querySelector('.bitstream-form');
    if (quickForm) {
        const contentField = quickForm.querySelector('textarea[name="bit_content"]');
        const urlField     = quickForm.querySelector('input[name="bit_rebit_url"]');

        function toggleRequired() {
            if (urlField.value.trim()) {
                contentField.removeAttribute('required');
            } else {
                contentField.setAttribute('required', 'required');
            }
        }

        urlField.addEventListener('input', toggleRequired);
        toggleRequired();
    }
    // Infinite Scroll & Load More
    const feed = document.querySelector('.bitstream-feed');
    if (!feed) return;

    let loading = false;
    const loadMoreButton = document.getElementById('bitstream-load-more');

    function loadNextPage() {
        const nextPage = parseInt(feed.dataset.page) + 1;
        const maxPage = parseInt(feed.dataset.maxPage);
        if (loading || nextPage > maxPage) return;
        loading = true;
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
            temp.querySelectorAll('.bit-card').forEach(card => {
                feed.appendChild(card);
            });
            feed.dataset.page = nextPage;
            loading = false;
            if (loadMoreButton) {
                loadMoreButton.textContent = 'Load More';
                if (nextPage >= maxPage) {
                    loadMoreButton.style.display = 'none';
                }
            }
        })
        .catch(() => {
            loading = false;
            if (loadMoreButton) {
                loadMoreButton.textContent = 'Load More';
            }
        });
    }

    // Scroll trigger
    window.addEventListener('scroll', () => {
        if (loading) return;
        const scrollPosition = window.innerHeight + window.scrollY;
        const threshold = document.body.offsetHeight - 300;
        if (scrollPosition >= threshold) {
            loadNextPage();
        }
    });

    // Button trigger
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', loadNextPage);
    }
});

// Consolidated comment styling function
function applyCommentStyles() {
    const styles = {
        authorName: 'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-right: 0.25em !important; color: #2c6e49 !important;',
        says: 'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-left: 0.2em !important; color: #2c6e49 !important;',
        dateLinks: 'color: #044389 !important; font-style: normal !important; font-size: 0.8em !important; text-decoration: underline !important; pointer-events: auto !important; cursor: pointer !important;'
    };

    // Apply author name styling
    $('.bit-comments-list .comment-author .fn').attr('style', styles.authorName);

    // Add "says:" if missing and style it
    $('.bit-comments-list .comment-author').each(function() {
        var $author = $(this);
        if ($author.find('.says').length === 0) {
            $author.append(' <span class="says">says:</span>');
        }
        $author.find('.says').attr('style', styles.says);
    });

    // Style date and edit links
    $('.bit-comments-list .comment-metadata a, .bit-comments-list .edit-link a, .bit-comments-list .comment-edit-link').attr('style', styles.dateLinks);

    // Style nested replies with green border
    $('.bit-comments-list .comment.depth-2, .bit-comments-list .comment.depth-3, .bit-comments-list .comment.depth-4').css({
        'margin-left': '2em',
        'border-left': '2px solid #2c6e49',
        'padding-left': '1em',
        'background': '#fafafa'
    });

    // Remove top divider from nested replies
    $('.bit-comments-list .comment.depth-2 > .comment-body, .bit-comments-list .comment.depth-3 > .comment-body, .bit-comments-list .comment.depth-4 > .comment-body').css({
        'border-top': 'none',
        'margin-top': '0',
        'padding-top': '0'
    });
}

// Apply comment styles on document ready
jQuery(document).ready(function($) {
    applyCommentStyles();

    // Quick Post: Media Library image selector
    let frame;
    $('#bitstream-select-image').on('click', function(e){
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: 'Select or Upload Image',
            button: { text: 'Use this image' },
            multiple: false
        });
        frame.on('select', function(){
            const attachment = frame.state().get('selection').first().toJSON();
            $('#bit_image_id').val(attachment.id);
            $('#bitstream-image-preview').html('<img src="'+attachment.url+'" style="max-width:120px;max-height:120px;border-radius:8px;">');
        });
        frame.open();
    });
});
