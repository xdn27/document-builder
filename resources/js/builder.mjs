import { paginateDocument } from './paginate-dom.mjs';

/**
 * Jembatan antara panel Livewire dan kanvas. Livewire tidak pernah menyentuh DOM
 * kanvas — ia hanya mengirim HTML baru, dan modul ini yang memasang serta
 * memaginasinya. Pemisahan ini yang membuat paginator bebas mengganti .doc-flow
 * menjadi .doc-pages tanpa mengganggu diffing Livewire.
 *
 * Semua listener dipasang sekali di level document dengan delegasi, sehingga
 * tetap bekerja setelah Livewire mengganti elemen panel.
 */

function highlight(canvas, state) {
    canvas.querySelectorAll('[data-block-id]').forEach((el) => {
        el.classList.toggle('db-selected', el.dataset.blockId === state.selectedId);
    });
}

async function renderInto(doc, canvas, styleEl, state, { html, css }, onRendered) {
    if (typeof css === 'string' && styleEl) {
        styleEl.textContent = css;
    }

    canvas.innerHTML = html;

    const result = await paginateDocument({ document: doc, root: canvas.querySelector('.doc-root') });

    highlight(canvas, state);
    onRendered?.(result);
}

/**
 * Mengembalikan handle, bukan memasang listener sendiri. Nama event dan bentuk
 * transportnya berbeda antara Livewire 2, Livewire 3, dan konsumen non-Blade —
 * jadi kabel itu milik pemanggil (3 baris di Blade), sementara modul ini tetap
 * satu-satunya pemilik logika kanvas.
 */
export function initBuilder({
    document: doc = globalThis.document,
    canvasSelector = '#db-canvas',
    styleSelector = '#db-document-css',
    onSelect,
    onReorder,
    onRendered,
    onInlineEdit,
    onEditingChange,
} = {}) {
    const canvas = doc.querySelector(canvasSelector);
    const styleEl = doc.querySelector(styleSelector);

    // Lokal per pemanggilan, bukan modul-level: dua initBuilder() pada halaman
    // yang sama (mis. Livewire remount) tidak boleh saling mewarisi revision —
    // instance baru yang genuinely mulai dari revision 1 akan ditolak sendiri
    // kalau state-nya dibagi dengan instance sebelumnya yang sudah sampai lebih
    // tinggi.
    const state = {
        selectedId: null,
        rendering: Promise.resolve(),
        revision: 0,
    };

    const handle = {
        applyPreview: () => Promise.resolve(),
        currentRevision: () => state.revision,
    };

    if (!canvas) return handle;

    canvas.addEventListener('click', (event) => {
        const blockEl = event.target.closest('[data-block-id]');

        if (!blockEl || !canvas.contains(blockEl)) return;

        state.selectedId = blockEl.dataset.blockId;
        highlight(canvas, state);
        onSelect?.(state.selectedId);
    });

    handle.applyPreview = (detail = {}) => {
        // Pratinjau yang datang saat sunting inline akan menghancurkan caret:
        // commit dulu supaya tidak ada ketikan yang hilang.
        if (inlineEditing.isEditing()) {
            inlineEditing.commit();
        }

        const revision = detail.revision;

        // Respons yang lebih tua dari yang terakhir dilukis dibuang: tanpa ini,
        // dua perubahan beruntun bisa selesai di luar urutan dan kanvas mundur
        // ke keadaan lama (spec §9.1). Revisi yang sama diterapkan ulang,
        // supaya pengiriman ulang yang disengaja tidak hilang.
        if (typeof revision === 'number') {
            if (revision < state.revision) return state.rendering;
            state.revision = revision;
        }

        if ('selectedId' in detail) {
            state.selectedId = detail.selectedId;
        }

        const activeEditor = doc.querySelector?.('.db-mini-rte [data-rte-editor]');
        const activeSource = doc.querySelector?.('.db-mini-rte [data-rte-source]');
        if (activeEditor && activeSource && doc.activeElement !== activeEditor) {
            activeEditor.innerHTML = activeSource.value;
        }

        // Pembaruan berurutan: paginasi yang sedang berjalan diselesaikan dulu,
        // supaya dua pembaruan beruntun tidak saling menimpa kanvas setengah jadi.
        state.rendering = state.rendering.then(() => renderInto(doc, canvas, styleEl, state, detail, onRendered));

        return state.rendering;
    };

    initOutlineDragging({ document: doc, onReorder });

    const inlineEditing = initInlineEditing({ document: doc, canvas, onInlineEdit, onEditingChange });

    initMiniRte({ document: doc });

    // Kanvas awal sudah dicetak server sebagai .doc-flow; cukup dipaginasi.
    if (canvas.querySelector('.doc-flow')) {
        state.rendering = paginateDocument({ document: doc, root: canvas.querySelector('.doc-root') }).then((result) => {
            highlight(canvas, state);
            onRendered?.(result);
        });
    }

    return handle;
}

/**
 * Sunting inline konten blok langsung di kanvas (fase 1: teks sederhana).
 * Double-click region bertanda data-edit-* → contenteditable → blur commit,
 * Escape batal. Properti non-konten tetap disunting di inspektor.
 *
 * Dua hal yang sengaja dijaga:
 * - Preview dijeda selama mengetik: React tidak boleh me-render ulang
 *   (innerHTML diganti = caret musnah). Commit mengirim satu nilai final
 *   lewat onInlineEdit, baru React yang me-render ulang.
 * - Nilai dinormalisasi ke subset yang direnderer (lihat serializeInlineEdit):
 *   contenteditable menghasilkan HTML kotor (div, span, &nbsp;) yang
 *   sanitizer server akan buang — tanpa normalisasi, yang terlihat saat
 *   mengetik berbeda dengan yang tersimpan.
 */
export function initInlineEditing({ document: doc = globalThis.document, canvas, onInlineEdit, onEditingChange } = {}) {
    const idle = { isEditing: () => false, commit: () => {}, cancel: () => {} };

    if (!canvas || typeof canvas.addEventListener !== 'function') {
        return idle;
    }

    let editing = null;

    function setEditing(next) {
        editing = next;
        onEditingChange?.(next !== null);
    }

    function cleanup(el) {
        el.removeAttribute('contenteditable');
        el.removeAttribute('data-editing');
    }

    function describe(el) {
        const blockEl = el.closest?.('[data-block-id]');
        const { editProp, editRow, editCol, editKey } = el.dataset;

        if (!blockEl || !editProp) {
            return null;
        }

        return {
            blockId: blockEl.dataset.blockId,
            prop: editProp,
            row: editRow === undefined ? undefined : Number(editRow),
            col: editCol === undefined ? undefined : Number(editCol),
            key: editKey,
            rich: el.hasAttribute('data-edit-rich'),
        };
    }

    function commit() {
        if (!editing) {
            return;
        }

        const { el, target, original } = editing;
        const value = serializeInlineEdit(el, target.rich);

        cleanup(el);
        setEditing(null);

        if (value !== original) {
            onInlineEdit?.({ ...target, value });
        }
    }

    function cancel() {
        if (!editing) {
            return;
        }

        const { el, originalHtml } = editing;

        cleanup(el);
        el.innerHTML = originalHtml;
        el.blur?.();
        setEditing(null);
    }

    function start(el) {
        const target = describe(el);

        if (!target) {
            return;
        }

        if (editing && editing.el !== el) {
            commit();
        }

        if (editing) {
            return;
        }

        setEditing({ el, target, original: serializeInlineEdit(el, target.rich), originalHtml: el.innerHTML });
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('data-editing', 'true');
        el.focus?.();
    }

    canvas.addEventListener('dblclick', (event) => {
        const el = event.target.closest?.('[data-edit-prop]');

        if (!el || !canvas.contains(el)) {
            return;
        }

        event.preventDefault();
        start(el);
    });

    canvas.addEventListener('focusout', (event) => {
        if (editing && !editing.el.contains(event.relatedTarget)) {
            commit();
        }
    });

    canvas.addEventListener('keydown', (event) => {
        if (editing && (event.key === 'Escape' || event.key === 'Esc')) {
            event.preventDefault();
            cancel();
        }
    });

    // Tempel selalu teks polos: HTML kaya dari clipboard (Word, web) pasti
    // terpotong saat commit — lebih jujur menampilkannya polos sejak awal.
    canvas.addEventListener('paste', (event) => {
        if (!editing) {
            return;
        }

        event.preventDefault();

        const text = (event.clipboardData?.getData('text/plain') || '').replace(/\r\n?/g, '\n');
        const selection = doc.getSelection?.();

        if (!selection || selection.rangeCount === 0) {
            return;
        }

        const range = selection.getRangeAt(0);

        if (!editing.el.contains(range.commonAncestorContainer)) {
            return;
        }

        range.deleteContents();

        text.split('\n').forEach((line, index, lines) => {
            if (index > 0) {
                range.insertNode(doc.createElement('br'));
            }

            const node = doc.createTextNode(line);

            range.insertNode(node);
            range.setStartAfter(node);
            range.collapse(true);
        });

        selection.removeAllRanges();
        selection.addRange(range);
    });

    return { isEditing: () => editing !== null, commit, cancel };
}

const INLINE_BLOCK_TAGS = new Set([
    'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td',
    'tfoot', 'th', 'thead', 'tr', 'ul',
]);

/**
 * Normalisasi isi contenteditable menjadi nilai schema: blok menjadi <br>,
 * inline asing (span, font, a, ...) dibuka bungkusnya, gambar dibuang, dan
 * untuk teks rich hanya <b><i><u><br> yang dipertahankan — cerminan HtmlSanitizer.
 * Non-rich mengembalikan teks polos satu baris.
 */
export function serializeInlineEdit(root, rich) {
    const parts = [];

    serializeInlineChildren(root, rich, parts);

    let html = parts.join('').replace(/&nbsp;|\u00a0/g, ' ');

    if (!rich) {
        return html
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/<[^>]*>/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    html = html
        .replace(/(<br\s*\/?>)+$/gi, '')
        .replace(/^(<br\s*\/?>)+/gi, '')
        .replace(/(<br\s*\/?>){3,}/gi, '<br><br>');

    return html.trim();
}

function serializeInlineChildren(node, rich, parts) {
    Array.from(node.childNodes || []).forEach((child) => {
        if (child.nodeType === 3) {
            parts.push(child.nodeValue);
            return;
        }

        if (child.nodeType !== 1) {
            return;
        }

        const tag = (child.tagName || '').toLowerCase();

        if (tag === 'br') {
            parts.push('<br>');
            return;
        }

        if (tag === 'img') {
            return;
        }

        if (rich && (tag === 'b' || tag === 'strong')) {
            parts.push('<b>');
            serializeInlineChildren(child, rich, parts);
            parts.push('</b>');
            return;
        }

        if (rich && (tag === 'i' || tag === 'em')) {
            parts.push('<i>');
            serializeInlineChildren(child, rich, parts);
            parts.push('</i>');
            return;
        }

        if (rich && tag === 'u') {
            parts.push('<u>');
            serializeInlineChildren(child, rich, parts);
            parts.push('</u>');
            return;
        }

        if (INLINE_BLOCK_TAGS.has(tag)) {
            if (parts.length > 0 && parts[parts.length - 1] !== '<br>') {
                parts.push('<br>');
            }

            serializeInlineChildren(child, rich, parts);
            parts.push('<br>');
            return;
        }

        serializeInlineChildren(child, rich, parts);
    });
}

/**
 * Menyusun ulang lewat daftar urutan, bukan lewat kanvas: menyeret melintasi
 * batas halaman yang sudah terpaginasi rapuh dan membingungkan.
 */
export function initOutlineDragging({ document: doc = globalThis.document, onReorder } = {}) {
    let dragged = null;

    doc.addEventListener('dragstart', (event) => {
        dragged = event.target.closest?.('[data-outline-id]') ?? null;

        if (dragged) {
            event.dataTransfer.effectAllowed = 'move';
            dragged.classList.add('opacity-50');
        }
    });

    doc.addEventListener('dragover', (event) => {
        if (!dragged) return;

        const target = event.target.closest?.('[data-outline-id]');

        if (!target || target === dragged || target.parentNode !== dragged.parentNode) return;

        event.preventDefault();

        const rect = target.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;

        target.parentNode.insertBefore(dragged, after ? target.nextSibling : target);
    });

    doc.addEventListener('drop', (event) => {
        if (!dragged) return;

        event.preventDefault();
    });

    doc.addEventListener('dragend', () => {
        if (!dragged) return;

        const list = dragged.closest('[data-outline-zone]');
        dragged.classList.remove('opacity-50');

        if (list) {
            const ids = Array.from(list.querySelectorAll('[data-outline-id]')).map((el) => el.dataset.outlineId);
            onReorder?.(list.dataset.outlineZone, ids);
        }

        dragged = null;
    });
}

/**
 * Mini-RTE untuk inspektor (prop rich text seperti Paragraph 'text').
 * Mengubah contenteditable menjadi subset aman (<b>, <i>, <u>, <br>),
 * mensinkronkan ke textarea Livewire via input event, dan menyediakan
 * toolbar pemformatan (Bold, Italic, Underline, Clear Format, Variable, Source toggle).
 */
export function initMiniRte({ document: doc = globalThis.document } = {}) {
    if (!doc || typeof doc.addEventListener !== 'function' || doc._dbMiniRteInitialized) {
        return;
    }

    doc._dbMiniRteInitialized = true;

    let rteDebounceTimer = null;
    let savedRange = null;
    let lastActiveEditor = null;

    function saveSelection() {
        const selection = doc.getSelection?.();
        if (selection && selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            const container = range.commonAncestorContainer;
            const editor = container?.nodeType === 1
                ? container.closest?.('[data-rte-editor]')
                : container?.parentElement?.closest?.('[data-rte-editor]');
            if (editor) {
                savedRange = range.cloneRange?.() || range;
                lastActiveEditor = editor;
            }
        }
    }

    function syncRteToSource(editor, source) {
        const clean = serializeInlineEdit(editor, true);
        if (source.value !== clean) {
            source.value = clean;
            source.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    doc.addEventListener('selectionchange', () => {
        saveSelection();
    });

    // Mencegah tombol toolbar mencuri fokus dari contenteditable
    doc.addEventListener('mousedown', (event) => {
        const btn = event.target?.closest?.('[data-rte-cmd], [data-rte-toggle], [data-rte-insert]');
        if (btn) {
            event.preventDefault();
        }
    });

    // Tombol format: bold, italic, underline, removeFormat
    doc.addEventListener('click', (event) => {
        const btn = event.target?.closest?.('[data-rte-cmd]');
        if (!btn) return;
        event.preventDefault();

        const container = btn.closest?.('.db-mini-rte');
        if (!container) return;

        const editor = container.querySelector?.('[data-rte-editor]');
        const source = container.querySelector?.('[data-rte-source]');
        if (!editor || !source) return;

        if (source.classList?.contains?.('d-none') === false) return;

        editor.focus?.();
        if (savedRange && editor.contains?.(savedRange.commonAncestorContainer)) {
            const selection = doc.getSelection?.();
            if (selection && selection.removeAllRanges && selection.addRange) {
                selection.removeAllRanges();
                selection.addRange(savedRange);
            }
        }

        const cmd = btn.dataset?.rteCmd;
        if (cmd && typeof doc.execCommand === 'function') {
            doc.execCommand(cmd, false, null);
        }

        syncRteToSource(editor, source);
        saveSelection();
    });

    // Tombol toggle tampilan kode HTML (Source) vs Visual WYSIWYG
    doc.addEventListener('click', (event) => {
        const btn = event.target?.closest?.('[data-rte-toggle="source"]');
        if (!btn) return;
        event.preventDefault();

        const container = btn.closest?.('.db-mini-rte');
        if (!container) return;

        const editor = container.querySelector?.('[data-rte-editor]');
        const source = container.querySelector?.('[data-rte-source]');
        if (!editor || !source) return;

        const isShowingSource = source.classList?.contains?.('d-none') === false;

        if (isShowingSource) {
            editor.innerHTML = source.value;
            source.classList?.add?.('d-none');
            editor.classList?.remove?.('d-none');
            btn.classList?.remove?.('active');
            btn.setAttribute?.('title', 'Lihat kode HTML');
            editor.focus?.();
        } else {
            syncRteToSource(editor, source);
            editor.classList?.add?.('d-none');
            source.classList?.remove?.('d-none');
            btn.classList?.add?.('active');
            btn.setAttribute?.('title', 'Kembali ke mode visual');
            source.focus?.();
        }
    });

    // Sisipkan token variabel template
    doc.addEventListener('click', (event) => {
        const item = event.target?.closest?.('[data-rte-insert]');
        if (!item) return;
        event.preventDefault();

        const token = item.dataset?.rteInsert;
        if (!token) return;

        const container = item.closest?.('.db-mini-rte') || lastActiveEditor?.closest?.('.db-mini-rte');
        if (!container) return;

        const editor = container.querySelector?.('[data-rte-editor]');
        const source = container.querySelector?.('[data-rte-source]');
        if (!editor || !source) return;

        const isShowingSource = source.classList?.contains?.('d-none') === false;

        if (isShowingSource) {
            const start = source.selectionStart ?? source.value.length;
            const end = source.selectionEnd ?? source.value.length;
            const val = source.value;
            source.value = val.substring(0, start) + token + val.substring(end);
            source.selectionStart = source.selectionEnd = start + token.length;
            source.focus?.();
            source.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
            editor.focus?.();
            const selection = doc.getSelection?.();
            if (savedRange && editor.contains?.(savedRange.commonAncestorContainer)) {
                if (selection && selection.removeAllRanges && selection.addRange) {
                    selection.removeAllRanges();
                    selection.addRange(savedRange);
                }
            }

            if (selection && selection.rangeCount > 0 && editor.contains?.(selection.getRangeAt(0).commonAncestorContainer)) {
                const range = selection.getRangeAt(0);
                range.deleteContents?.();
                const node = doc.createTextNode ? doc.createTextNode(token) : { nodeType: 3, nodeValue: token };
                range.insertNode?.(node);
                range.setStartAfter?.(node);
                range.collapse?.(true);
                selection.removeAllRanges?.();
                selection.addRange?.(range);
            } else if (doc.createTextNode) {
                editor.appendChild?.(doc.createTextNode(token));
            }

            syncRteToSource(editor, source);
            saveSelection();
        }
    });

    // Input debounce di contenteditable
    doc.addEventListener('input', (event) => {
        const editor = event.target?.closest?.('[data-rte-editor]');
        if (!editor) return;

        const container = editor.closest?.('.db-mini-rte');
        const source = container?.querySelector?.('[data-rte-source]');
        if (!source) return;

        clearTimeout(rteDebounceTimer);
        rteDebounceTimer = setTimeout(() => {
            syncRteToSource(editor, source);
        }, 300);
    });

    // Focusout di contenteditable langsung commit
    doc.addEventListener('focusout', (event) => {
        const editor = event.target?.closest?.('[data-rte-editor]');
        if (!editor) return;

        const container = editor.closest?.('.db-mini-rte');
        const source = container?.querySelector?.('[data-rte-source]');
        if (!source) return;

        clearTimeout(rteDebounceTimer);
        syncRteToSource(editor, source);
    });

    // Paste di contenteditable: tempel plain text dengan baris baru sebagai <br>
    doc.addEventListener('paste', (event) => {
        const editor = event.target?.closest?.('[data-rte-editor]');
        if (!editor) return;

        event.preventDefault();

        const text = (event.clipboardData?.getData?.('text/plain') || '').replace(/\r\n?/g, '\n');
        const selection = doc.getSelection?.();

        if (!selection || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        if (!editor.contains?.(range.commonAncestorContainer)) return;

        range.deleteContents?.();

        text.split('\n').forEach((line, index) => {
            if (index > 0 && doc.createElement) {
                range.insertNode?.(doc.createElement('br'));
            }
            const node = doc.createTextNode ? doc.createTextNode(line) : { nodeType: 3, nodeValue: line };
            range.insertNode?.(node);
            range.setStartAfter?.(node);
            range.collapse?.(true);
        });

        selection.removeAllRanges?.();
        selection.addRange?.(range);

        const container = editor.closest?.('.db-mini-rte');
        const source = container?.querySelector?.('[data-rte-source]');
        if (source) {
            syncRteToSource(editor, source);
        }
    });

    // Keyboard shortcuts (Ctrl+B, Ctrl+I, Ctrl+U)
    doc.addEventListener('keydown', (event) => {
        const editor = event.target?.closest?.('[data-rte-editor]');
        if (!editor) return;

        if (event.ctrlKey || event.metaKey) {
            const key = event.key?.toLowerCase();
            if (key === 'b' || key === 'i' || key === 'u') {
                event.preventDefault();
                const cmd = key === 'b' ? 'bold' : (key === 'i' ? 'italic' : 'underline');
                if (typeof doc.execCommand === 'function') {
                    doc.execCommand(cmd, false, null);
                }
                const container = editor.closest?.('.db-mini-rte');
                const source = container?.querySelector?.('[data-rte-source]');
                if (source) {
                    syncRteToSource(editor, source);
                }
                saveSelection();
            }
        }
    });
}
