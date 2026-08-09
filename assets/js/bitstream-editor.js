/**
 * BitStream Micro-Editor Module
 * Powers contenteditable input fields with live #hashtag and URL highlighting,
 * caret-anchored autocomplete popup, custom Undo/Redo stack, and plain-text paste sanitization.
 */
(function () {
    'use strict';

    function escapeHTML(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Extract plain text representation from contenteditable element
    function getTextContent(editor) {
        if (!editor) return '';

        let text = '';
        function walk(node) {
            if (node.nodeType === 3) { // Text node
                text += node.nodeValue;
            } else if (node.nodeType === 1) { // Element
                const tag = node.tagName.toLowerCase();
                if (tag === 'img') {
                    text += node.getAttribute('alt') || node.getAttribute('data-emoji') || '';
                } else if (tag === 'br') {
                    if (!text.endsWith('\n')) {
                        text += '\n';
                    }
                } else if (tag === 'div' || tag === 'p') {
                    if (text.length > 0 && !text.endsWith('\n')) {
                        text += '\n';
                    }
                    node.childNodes.forEach(walk);
                } else {
                    node.childNodes.forEach(walk);
                }
            }
        }
        walk(editor);
        return text;
    }

    // Get current caret character offset inside contenteditable element
    function getCaretOffset(editor) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return 0;

        const range = sel.getRangeAt(0);
        const preCaretRange = range.cloneRange();
        preCaretRange.selectNodeContents(editor);
        preCaretRange.setEnd(range.endContainer, range.endOffset);

        const container = document.createElement('div');
        container.appendChild(preCaretRange.cloneContents());
        return getTextContent(container).length;
    }

    // Restore caret character offset inside contenteditable element
    function setCaretOffset(editor, targetOffset) {
        const sel = window.getSelection();
        if (!sel) return;

        let currentOffset = 0;
        let targetNode = null;
        let targetNodeOffset = 0;

        function walk(node) {
            if (targetNode) return;

            if (node.nodeType === 3) {
                const len = node.nodeValue.length;
                if (currentOffset + len >= targetOffset) {
                    targetNode = node;
                    targetNodeOffset = targetOffset - currentOffset;
                } else {
                    currentOffset += len;
                }
            } else if (node.nodeType === 1) {
                const tag = node.tagName.toLowerCase();
                if (tag === 'br') {
                    if (currentOffset === targetOffset) {
                        targetNode = node.parentNode;
                        targetNodeOffset = Array.from(node.parentNode.childNodes).indexOf(node);
                    } else {
                        currentOffset += 1;
                    }
                } else {
                    for (let child of node.childNodes) {
                        walk(child);
                        if (targetNode) break;
                    }
                }
            }
        }

        walk(editor);

        const range = document.createRange();
        if (targetNode) {
            if (targetNode.nodeType === 3) {
                range.setStart(targetNode, Math.min(targetNodeOffset, targetNode.nodeValue.length));
            } else {
                range.setStart(targetNode, targetNodeOffset);
            }
        } else {
            range.selectNodeContents(editor);
            range.collapse(false);
        }
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
    }

    // Format text into HTML with hashtag and URL highlight spans
    function formatHTML(val) {
        if (!val) return '';

        const hashtagRegex = /(^|[\s(>])(#([A-Za-z][A-Za-z0-9_\u00C0-\u024F]*))/g;
        const urlRegex = /(https?:\/\/[^\s<]+)/gi;

        let result = '';
        let lastIndex = 0;

        const tokens = [];

        let m;
        while ((m = hashtagRegex.exec(val)) !== null) {
            tokens.push({
                type: 'hashtag',
                start: m.index + m[1].length,
                end: m.index + m[0].length,
                text: m[2]
            });
        }

        while ((m = urlRegex.exec(val)) !== null) {
            tokens.push({
                type: 'url',
                start: m.index,
                end: m.index + m[0].length,
                text: m[1]
            });
        }

        tokens.sort((a, b) => a.start - b.start);
        const filteredTokens = [];
        let lastEnd = 0;
        tokens.forEach(tok => {
            if (tok.start >= lastEnd) {
                filteredTokens.push(tok);
                lastEnd = tok.end;
            }
        });

        lastIndex = 0;
        filteredTokens.forEach(tok => {
            if (tok.start > lastIndex) {
                result += escapeHTML(val.slice(lastIndex, tok.start));
            }
            if (tok.type === 'hashtag') {
                result += '<span class="bs-editor-hashtag">' + escapeHTML(tok.text) + '</span>';
            } else if (tok.type === 'url') {
                result += '<span class="bs-editor-url">' + escapeHTML(tok.text) + '</span>';
            }
            lastIndex = tok.end;
        });

        if (lastIndex < val.length) {
            result += escapeHTML(val.slice(lastIndex));
        }

        return result;
    }

    // Set text value into editor element
    function setTextContent(editor, text) {
        if (!editor) return;
        text = text || '';
        editor.innerHTML = formatHTML(text);
        syncHiddenInput(editor);
    }

    // Synchronize editor text content to paired hidden form input field
    function syncHiddenInput(editor) {
        const id = editor.id;
        if (!id) return;
        const hiddenInput = document.getElementById(id + '-value');
        if (hiddenInput) {
            hiddenInput.value = getTextContent(editor);
            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    // Insert text at current caret position
    function insertAtCaret(editor, text) {
        if (!editor) return;
        editor.focus();

        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            range.deleteContents();

            const textNode = document.createTextNode(text);
            range.insertNode(textNode);

            range.setStartAfter(textNode);
            range.setEndAfter(textNode);
            sel.removeAllRanges();
            sel.addRange(range);
        } else {
            editor.innerHTML += escapeHTML(text);
        }

        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Autocomplete popup manager
    let popupEl = null;
    let activeEditor = null;
    let selectedIndex = 0;
    let tagMatches = [];
    let queryStartOffset = 0;

    function closeAutocomplete() {
        if (popupEl && popupEl.parentNode) {
            popupEl.parentNode.removeChild(popupEl);
        }
        popupEl = null;
        activeEditor = null;
        tagMatches = [];
    }

    function checkAutocomplete(editor) {
        const text = getTextContent(editor);
        const caretPos = getCaretOffset(editor);
        const textBeforeCaret = text.substring(0, caretPos);

        const hashIndex = textBeforeCaret.lastIndexOf('#');
        if (hashIndex !== -1) {
            const tagText = textBeforeCaret.substring(hashIndex + 1);
            const charBeforeHash = hashIndex === 0 ? ' ' : textBeforeCaret[hashIndex - 1];

            const isWordBoundary = hashIndex === 0 || /\s/.test(charBeforeHash);
            const hasSpaceInTag = /\s/.test(tagText);
            const isValidTag = /^[A-Za-z0-9_\u00C0-\u024F]*$/u.test(tagText);

            if (isWordBoundary && !hasSpaceInTag && isValidTag) {
                queryStartOffset = hashIndex;
                openAutocomplete(editor, tagText);
                return;
            }
        }
        closeAutocomplete();
    }

    function openAutocomplete(editor, query) {
        activeEditor = editor;

        let allTags = [];
        if (window.BitStream && window.BitStream.Timeline && typeof window.BitStream.Timeline.getHashtags === 'function') {
            allTags = window.BitStream.Timeline.getHashtags();
        } else if (window.bitstream_ajax && bitstream_ajax.hashtags) {
            for (const [tag, count] of Object.entries(bitstream_ajax.hashtags)) {
                allTags.push({ tag, count: parseInt(count, 10) });
            }
            allTags.sort((a, b) => b.count - a.count);
        }

        let filtered = [];
        if (!query) {
            filtered = allTags.slice(0, 5);
        } else {
            const q = query.toLowerCase();
            filtered = allTags.filter(t => t.tag.toLowerCase().includes(q));
        }

        if (filtered.length === 0) {
            closeAutocomplete();
            return;
        }

        tagMatches = filtered;
        if (selectedIndex >= tagMatches.length) {
            selectedIndex = 0;
        }

        renderAutocompletePopup(editor);
    }

    function renderAutocompletePopup(editor) {
        if (!popupEl) {
            popupEl = document.createElement('div');
            popupEl.className = 'bs-editor-autocomplete-popup';

            popupEl.addEventListener('mousedown', (e) => { e.preventDefault(); });
            popupEl.addEventListener('click', (e) => {
                const item = e.target.closest('.bs-editor-autocomplete-item');
                if (item) {
                    selectTag(item.dataset.tag);
                }
            });

            document.body.appendChild(popupEl);
        }

        popupEl.innerHTML = tagMatches.map((m, idx) => `
            <div class="bs-editor-autocomplete-item${idx === selectedIndex ? ' is-active' : ''}" data-tag="${m.tag}">
                <span class="bs-tag-name">#${escapeHTML(m.tag)}</span>
                <span class="bs-tag-count">${m.count}</span>
            </div>
        `).join('');

        // Anchor popup under caret
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            const rect = range.getBoundingClientRect();
            const vpW = window.innerWidth;
            const popupW = 200;

            let top = rect.bottom + window.scrollY + 4;
            let left = rect.left + window.scrollX;

            if (left + popupW > vpW - 12) {
                left = Math.max(12, vpW - popupW - 12);
            }

            if (rect.width !== 0 || rect.height !== 0) {
                popupEl.style.top = top + 'px';
                popupEl.style.left = left + 'px';
            } else {
                const editorRect = editor.getBoundingClientRect();
                let fallbackLeft = editorRect.left + window.scrollX + 16;
                if (fallbackLeft + popupW > vpW - 12) {
                    fallbackLeft = Math.max(12, vpW - popupW - 12);
                }
                popupEl.style.top = (editorRect.bottom + window.scrollY - 30) + 'px';
                popupEl.style.left = fallbackLeft + 'px';
            }
        }
    }

    function selectTag(tag) {
        if (!activeEditor) return;
        const targetEditor = activeEditor;

        const text = getTextContent(targetEditor);
        const caretPos = getCaretOffset(targetEditor);

        const before = text.substring(0, queryStartOffset);
        const after = text.substring(caretPos);
        const insertion = '#' + tag + ' ';

        const newText = before + insertion + after;
        const newCaretPos = queryStartOffset + insertion.length;

        setTextContent(targetEditor, newText);
        setCaretOffset(targetEditor, newCaretPos);

        closeAutocomplete();
        if (targetEditor && typeof targetEditor.focus === 'function') {
            targetEditor.focus();
        }
    }

    // Attach contenteditable controller to element
    function init(editor) {
        if (!editor || editor._bsEditorInitialized) return;

        editor._bsEditorInitialized = true;

        // Undo / Redo Stack Manager
        const undoStack = [];
        const redoStack = [];
        let lastText = getTextContent(editor);
        let saveTimeout = null;

        function saveSnapshot() {
            const currentText = getTextContent(editor);
            if (currentText === lastText) return;

            const caretPos = getCaretOffset(editor);
            undoStack.push({ text: lastText, caretPos: caretPos });
            if (undoStack.length > 100) undoStack.shift();
            redoStack.length = 0;
            lastText = currentText;
        }

        function scheduleSaveSnapshot() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => saveSnapshot(), 300);
        }

        function performUndo() {
            if (undoStack.length === 0) return;
            saveSnapshot();
            const state = undoStack.pop();
            redoStack.push({ text: getTextContent(editor), caretPos: getCaretOffset(editor) });
            lastText = state.text;
            setTextContent(editor, state.text);
            setCaretOffset(editor, state.caretPos);
        }

        function performRedo() {
            if (redoStack.length === 0) return;
            const state = redoStack.pop();
            undoStack.push({ text: getTextContent(editor), caretPos: getCaretOffset(editor) });
            lastText = state.text;
            setTextContent(editor, state.text);
            setCaretOffset(editor, state.caretPos);
        }

        function handleKeyDown(e) {
            // Ctrl+Z / Cmd+Z (Undo) & Ctrl+Y / Cmd+Shift+Z (Redo)
            if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
                if (e.shiftKey) {
                    e.preventDefault();
                    performRedo();
                } else {
                    e.preventDefault();
                    performUndo();
                }
                return;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y')) {
                e.preventDefault();
                performRedo();
                return;
            }

            if (e.key === ' ' || e.key === 'Enter') {
                saveSnapshot();
            } else {
                scheduleSaveSnapshot();
            }

            if (!popupEl || tagMatches.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = (selectedIndex + 1) % tagMatches.length;
                renderAutocompletePopup(editor);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = (selectedIndex - 1 + tagMatches.length) % tagMatches.length;
                renderAutocompletePopup(editor);
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                selectTag(tagMatches[selectedIndex].tag);
            } else if (e.key === 'Escape') {
                closeAutocomplete();
            }
        }

        // 1. Form input sync & live highlight
        let isComposing = false;
        editor.addEventListener('compositionstart', () => { isComposing = true; });
        editor.addEventListener('compositionend', () => {
            isComposing = false;
            triggerHighlight();
        });

        function triggerHighlight() {
            if (isComposing) return;

            const text = getTextContent(editor);
            const caretPos = getCaretOffset(editor);

            editor.innerHTML = formatHTML(text);
            setCaretOffset(editor, caretPos);

            syncHiddenInput(editor);
            checkAutocomplete(editor);

            if (window.matchMedia('(max-width: 1023px)').matches) {
                if (typeof window.bsMobileAutoResize === 'function') {
                    window.bsMobileAutoResize(editor);
                }
            }
        }

        editor.addEventListener('input', triggerHighlight);
        editor.addEventListener('keydown', handleKeyDown);

        editor.addEventListener('blur', () => {
            setTimeout(closeAutocomplete, 150);
            syncHiddenInput(editor);
        });

        // 2. Paste Sanitizer & Image Clipboard Handler
        editor.addEventListener('paste', function (e) {
            const clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData) return;

            const mediaFiles = [];
            if (clipboardData.files && clipboardData.files.length > 0) {
                for (let i = 0; i < clipboardData.files.length; i++) {
                    const f = clipboardData.files[i];
                    if (f.type && (f.type.startsWith('image/') || f.type.startsWith('video/'))) {
                        mediaFiles.push(f);
                    }
                }
            }
            if (mediaFiles.length === 0 && clipboardData.items && clipboardData.items.length > 0) {
                for (let i = 0; i < clipboardData.items.length; i++) {
                    const item = clipboardData.items[i];
                    if (item.type && (item.type.startsWith('image/') || item.type.startsWith('video/'))) {
                        const f = item.getAsFile();
                        if (f) mediaFiles.push(f);
                    }
                }
            }

            if (mediaFiles.length > 0) {
                e.preventDefault();
                const text = clipboardData.getData('text/plain');
                if (text && !text.startsWith('data:image/') && !text.startsWith('blob:')) {
                    insertAtCaret(editor, text);
                }
                const customEvent = new CustomEvent('bitstream:paste-media', {
                    bubbles: true,
                    cancelable: true,
                    detail: { files: mediaFiles, editor: editor }
                });
                editor.dispatchEvent(customEvent);
                return;
            }

            e.preventDefault();
            const text = clipboardData.getData('text/plain');
            insertAtCaret(editor, text);
        });

        // 3. Drop Sanitizer
        editor.addEventListener('drop', function (e) {
            e.preventDefault();
            const text = e.dataTransfer.getData('text/plain');
            if (text) {
                insertAtCaret(editor, text);
            }
        });

        // Clean any residual <img> nodes and format editor DOM
        const initialText = getTextContent(editor);
        editor.innerHTML = formatHTML(initialText);
        syncHiddenInput(editor);
    }

    function getEditorValue(el) {
        if (!el) return '';
        if (el.hasAttribute && (el.hasAttribute('contenteditable') || el.getAttribute('role') === 'textbox')) {
            return getTextContent(el);
        }
        if ('value' in el && typeof el.value === 'string') {
            return el.value;
        }
        return getTextContent(el);
    }

    function setEditorValue(el, val) {
        if (!el) return;
        if (el.hasAttribute && (el.hasAttribute('contenteditable') || el.getAttribute('role') === 'textbox')) {
            setTextContent(el, val || '');
        } else if ('value' in el) {
            el.value = val || '';
        } else {
            setTextContent(el, val || '');
        }
    }

    window.BitStream = window.BitStream || {};
    window.BitStream.Editor = {
        init: init,
        getTextContent: getTextContent,
        setTextContent: setTextContent,
        insertAtCaret: insertAtCaret,
        getEditorValue: getEditorValue,
        setEditorValue: setEditorValue
    };
})();
