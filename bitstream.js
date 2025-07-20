document.addEventListener('DOMContentLoaded', function () {
    // Like button
    document.querySelectorAll('.bit-like').forEach(button => {
      const postId     = button.dataset.postId;
      const storageKey = 'bitstream-liked-' + postId;

      if ( localStorage.getItem(storageKey) ) {
        button.classList.add('liked');
      }

      button.addEventListener('click', () => {
        const likeCountSpan = button.querySelector('.bit-like-count');
        const isLiked       = !!localStorage.getItem(storageKey);
        const type          = isLiked ? 'unlike' : 'like';

        const formData = new FormData();
        formData.append('action',  'bitstream_like');
        formData.append('post_id', postId);
        formData.append('type',    type);

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

        const selectButton = document.getElementById('bitstream-select-image');
        const preview      = document.getElementById('bitstream-image-preview');
        let frame;
        if (selectButton) {
            selectButton.addEventListener('click', (e) => {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: 'Select Image',
                    button: { text: 'Use this image' },
                    multiple: false
                });
                frame.on('select', () => {
                    const attachment = frame.state().get('selection').first().toJSON();
                    document.getElementById('bit_image_id').value = attachment.id;
                    if (preview) {
                        preview.innerHTML = '<img src="' + attachment.url + '" alt="" />';
                    }
                });
                frame.open();
            });
        }
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

// Final forced inline style fix for stubborn comment styles (2025-05-15, smaller)
jQuery(document).ready(function($) {
  // 1. Date and Edit links
  $('.bit-comments-list .comment-metadata a, .bit-comments-list .edit-link a, .bit-comments-list .comment-edit-link').attr('style',
    'color: #044389 !important; font-style: normal !important; font-size: 0.8em !important; text-decoration: underline !important; pointer-events: auto !important; cursor: pointer !important;'
  );

  // 2. Name (smaller, inline, normal, green)
  $('.bit-comments-list .comment-author .fn').attr('style',
    'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-right: 0.25em !important; color: #2c6e49 !important;'
  );

  // 3. "says:" - if missing, insert; always style and show inline, small, green
  $('.bit-comments-list .comment-author').each(function() {
    var $author = $(this);
    if ($author.find('.says').length === 0) {
      $author.append(' <span class="says">says:</span>');
    }
    $author.find('.says').attr('style',
      'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-left: 0.2em !important; color: #2c6e49 !important;'
    );
  });

  // 4. Indent all nested replies
  $('.bit-comments-list .children > .comment').css({
    'margin-left': '1.5em',
    'padding-left': '0',
  });

  // 5. Divider above each nested reply except first
  $('.bit-comments-list .children > .comment:not(:first-child) > .comment-body').css({
    'border-top': '2px solid #ccc',
    'margin-top': '0.7em',
    'padding-top': '0.7em'
  });
});


// Final nested reply visual fix for BitStream (2025-05-15)
jQuery(document).ready(function($) {
  // Name (smaller, inline, green)
  $('.bit-comments-list .comment-author .fn').attr('style',
    'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-right: 0.25em !important; color: #2c6e49 !important;'
  );
  // says:
  $('.bit-comments-list .comment-author').each(function() {
    var $author = $(this);
    if ($author.find('.says').length === 0) {
      $author.append(' <span class="says">says:</span>');
    }
    $author.find('.says').attr('style',
      'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-left: 0.2em !important; color: #2c6e49 !important;'
    );
  });

  // Indent nested replies visually, strong orange left border and light background
  $('.bit-comments-list .comment.depth-2, .bit-comments-list .comment.depth-3, .bit-comments-list .comment.depth-4').css({
    'margin-left': '2em',
    'border-left': '2px solid #ff9900',
    'padding-left': '1em',
    'background': '#fafafa'
  });

  // Divider above every nested reply except the first
  $('.bit-comments-list .comment.depth-2:not(:first-child) > .comment-body').css({
    'border-top': '2px solid #ccc',
    'margin-top': '0.7em',
    'padding-top': '0.7em'
  });
});


// Refined nested reply style: green border, no top divider
jQuery(document).ready(function($) {
  // Name (smaller, inline, green)
  $('.bit-comments-list .comment-author .fn').attr('style',
    'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-right: 0.25em !important; color: #2c6e49 !important;'
  );
  // says:
  $('.bit-comments-list .comment-author').each(function() {
    var $author = $(this);
    if ($author.find('.says').length === 0) {
      $author.append(' <span class="says">says:</span>');
    }
    $author.find('.says').attr('style',
      'display: inline !important; font-size: 0.75em !important; font-style: normal !important; font-weight: 400 !important; margin-left: 0.2em !important; color: #2c6e49 !important;'
    );
  });

  // Indent nested replies visually, strong GREEN left border and light background
  $('.bit-comments-list .comment.depth-2, .bit-comments-list .comment.depth-3, .bit-comments-list .comment.depth-4').css({
    'margin-left': '2em',
    'border-left': '2px solid #2c6e49',
    'padding-left': '1em',
    'background': '#fafafa'
  });

  // REMOVE top divider from nested replies
  $('.bit-comments-list .comment.depth-2 > .comment-body, .bit-comments-list .comment.depth-3 > .comment-body, .bit-comments-list .comment.depth-4 > .comment-body').css({
    'border-top': 'none',
    'margin-top': '0',
    'padding-top': '0'
  });
});
