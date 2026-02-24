/**
 * Docuperfect Editor — Phase 2
 *
 * Vanilla JS module for the interactive template/document editor.
 * Expects window.DocuperfectConfig to be set by the Blade view before this script loads.
 *
 * Modes:
 *   - template: Admin/BM places fields on PDF page images
 *   - document: Agent fills field values, toggles strikethroughs, downloads PDF
 */
(function () {
    'use strict';

    var C = window.DocuperfectConfig;
    if (!C) return;

    // ======================================================================
    // STATE
    // ======================================================================
    var fields = JSON.parse(JSON.stringify(C.fields || []));
    var selectedFieldId = null;
    var placementMode = null;   // null or a field type string
    var isDirty = false;
    var dragState = null;       // { type, fieldId, startX, startY, container, ... }
    var clauseCache = null;
    var placementStrikeType = null;
    var quickFillEl = null;
    var quickFillDebounce = null;

    // DOM refs (set in init)
    var editorEl, sidebarEl, canvasEl;

    // ======================================================================
    // CONSTANTS
    // ======================================================================
    var TYPES = [
        { type: 'placeholder',   label: 'Text',      icon: 'Aa' },
        { type: 'strikethrough', label: 'Strike',     icon: '\u2014', strikethroughType: 'horizontal' },
        { type: 'strikethrough', label: 'Diagonal',   icon: '\u2572', strikethroughType: 'diagonal' },
        { type: 'selection',     label: 'Select',     icon: '\u2261' },
        { type: 'initial',       label: 'Initial',    icon: 'In' },
        { type: 'date',          label: 'Date',       icon: 'Dt' },
        { type: 'condition',     label: 'Clause',     icon: '\u00A7' },
        { type: 'signature',     label: 'Sign',       icon: 'Sg' }
    ];

    // ======================================================================
    // INITIALIZATION
    // ======================================================================
    function init() {
        editorEl = document.getElementById('docuperfect-editor');
        if (!editorEl) return;

        // Clear Phase-1 placeholder
        editorEl.innerHTML = '';
        editorEl.className = 'dp-editor-layout';
        editorEl.style.minHeight = '600px';

        // If template mode with no pages, show upload zone
        if (C.mode === 'template' && (!C.pageImages || C.pageImages.length === 0)) {
            editorEl.className = '';
            editorEl.style.minHeight = '';
            renderUploadZone();
            return;
        }

        buildLayout();
        renderPages();
        renderAllFields();

        // Wire header buttons
        var saveBtn = document.getElementById('dpSaveBtn');
        if (saveBtn) saveBtn.addEventListener('click', save);

        var dlBtn = document.getElementById('dpDownloadBtn');
        if (dlBtn) dlBtn.addEventListener('click', downloadPdf);

        // Unsaved-changes guard
        window.addEventListener('beforeunload', function (e) {
            if (isDirty) { e.preventDefault(); e.returnValue = ''; }
        });

        // Deselect on click outside fields
        document.addEventListener('mousedown', function (e) {
            if (placementMode || dragState) return;
            if (!e.target.closest('.dp-field') &&
                !e.target.closest('.dp-inline-toolbar') &&
                !e.target.closest('.dp-options-editor') &&
                !e.target.closest('.dp-strike-opts') &&
                !e.target.closest('.dp-sidebar')) {
                deselectAll();
            }
        });

        // Global drag handlers
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    }

    // ======================================================================
    // LAYOUT
    // ======================================================================
    function buildLayout() {
        // Sidebar — shown in both modes so users can add fields
        sidebarEl = document.createElement('div');
        sidebarEl.className = 'dp-sidebar';
        buildSidebar();
        editorEl.appendChild(sidebarEl);

        // Quick fill sidebar (document mode only)
        if (C.mode === 'document') {
            quickFillEl = document.createElement('div');
            quickFillEl.className = 'dp-quick-fill-sidebar';
            buildQuickFill();
            editorEl.appendChild(quickFillEl);
        }

        // Canvas area
        canvasEl = document.createElement('div');
        canvasEl.className = 'dp-canvas-area';
        editorEl.appendChild(canvasEl);
    }

    function buildSidebar() {
        var lbl = document.createElement('div');
        lbl.className = 'dp-sidebar-label';
        lbl.textContent = 'Fields';
        sidebarEl.appendChild(lbl);

        TYPES.forEach(function (t) {
            var btn = document.createElement('button');
            btn.className = 'dp-sidebar-btn';
            btn.dataset.type = t.type;
            if (t.strikethroughType) btn.dataset.strikeType = t.strikethroughType;
            btn.innerHTML = '<span class="dp-btn-icon">' + t.icon + '</span>' + t.label;
            btn.addEventListener('click', function () {
                if (placementMode === t.type && (!t.strikethroughType || placementStrikeType === t.strikethroughType)) {
                    cancelPlacement();
                } else {
                    startPlacement(t.type, t.strikethroughType);
                }
            });
            sidebarEl.appendChild(btn);
        });
    }

    // ======================================================================
    // PAGE RENDERING
    // ======================================================================
    function renderPages() {
        C.pageImages.forEach(function (url, idx) {
            var container = document.createElement('div');
            container.className = 'dp-page-container';
            container.dataset.page = idx;

            var img = document.createElement('img');
            img.className = 'dp-page-img';
            img.src = url;
            img.draggable = false;
            container.appendChild(img);

            // Page number badge
            var badge = document.createElement('div');
            badge.className = 'dp-page-label';
            badge.textContent = 'Page ' + (idx + 1);
            container.appendChild(badge);

            // Click on page image to place field
            container.addEventListener('mousedown', function (e) {
                if (placementMode && !e.target.closest('.dp-field')) {
                    onPlacementStart(e, container, idx);
                }
            });

            canvasEl.appendChild(container);
        });
    }

    // ======================================================================
    // FIELD RENDERING
    // ======================================================================
    function renderAllFields() {
        C.pageImages.forEach(function (_, i) { renderFieldsForPage(i); });
    }

    function renderFieldsForPage(pageIndex) {
        var container = canvasEl.querySelector('[data-page="' + pageIndex + '"]');
        if (!container) return;

        // Remove old field elements (keep image + page badge)
        container.querySelectorAll('.dp-field, .dp-inline-toolbar, .dp-options-editor, .dp-strike-opts').forEach(function (el) { el.remove(); });

        fields.filter(function (f) { return f.pageIndex === pageIndex; }).forEach(function (field) {
            container.appendChild(createFieldElement(field));
        });
    }

    function createFieldElement(field) {
        var el = document.createElement('div');
        el.className = 'dp-field' + (field.id === selectedFieldId ? ' selected' : '');
        el.dataset.fieldId = field.id;
        el.dataset.type = field.type;

        el.style.left   = field.position.x + '%';
        el.style.top    = field.position.y + '%';
        el.style.width  = field.size.width + '%';
        el.style.height = field.size.height + '%';

        // Named field badge (visible at all times in both modes)
        if (field.named_field_id) {
            var nBadge = document.createElement('div');
            nBadge.className = 'dp-named-badge';
            nBadge.textContent = field.named_field_name || 'Linked';
            el.appendChild(nBadge);
        }

        if (C.mode === 'template') {
            buildTemplateField(field, el);
        } else {
            buildDocumentField(field, el);
        }

        return el;
    }

    // ------------------------------------------------------------------
    // Template-mode field
    // ------------------------------------------------------------------
    function buildTemplateField(field, el) {
        // Type label
        var lbl = document.createElement('div');
        lbl.className = 'dp-field-label';
        lbl.textContent = field.type;
        el.appendChild(lbl);

        // Select on click
        el.addEventListener('mousedown', function (e) {
            if (placementMode) return;
            if (e.target.closest('.dp-move-handle, .dp-resize-handle, .dp-delete-btn')) return;
            e.stopPropagation();
            selectField(field.id);
        });

        // Handles
        appendHandles(el, field.id);

        // Selection-type options editor (when selected)
        if (field.type === 'selection' && field.id === selectedFieldId) {
            el.appendChild(buildOptionsEditor(field));
        }

        // Strikethrough type selector (when selected)
        if (field.type === 'strikethrough' && field.id === selectedFieldId) {
            el.appendChild(buildStrikeOpts(field));
        }

        // Inline toolbar for text-capable fields
        if (field.id === selectedFieldId && isTextCapable(field.type)) {
            el.appendChild(buildInlineToolbar(field));
        }
    }

    // ------------------------------------------------------------------
    // Document-mode field
    // ------------------------------------------------------------------
    function buildDocumentField(field, el) {
        var userAdded = !!field.isUserAdded;

        // User-added fields get handles
        if (userAdded) appendHandles(el, field.id);

        // Type-specific interactive rendering
        switch (field.type) {
            case 'placeholder':   renderPlaceholderInput(field, el); break;
            case 'date':          renderDateInput(field, el);        break;
            case 'selection':     renderSelectionPills(field, el);   break;
            case 'strikethrough': renderStrikeToggle(field, el);     break;
            case 'condition':     renderConditionArea(field, el);    break;
            case 'initial':
            case 'signature':     renderSigLine(field, el);          break;
        }

        // Select on click (for toolbar)
        el.addEventListener('mousedown', function (e) {
            if (e.target.closest('.dp-move-handle, .dp-resize-handle, .dp-delete-btn')) return;
            e.stopPropagation();
            selectField(field.id);
        });

        // Inline toolbar
        if (field.id === selectedFieldId && isTextCapable(field.type)) {
            el.appendChild(buildInlineToolbar(field));
        }
    }

    // === Document input renderers ===

    function renderPlaceholderInput(field, el) {
        el.style.background = 'transparent';
        setBorderForDoc(el, field, 'rgba(59,130,246,0.3)');

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'dp-field-input';
        inp.value = field.value || '';
        inp.placeholder = 'Enter text\u2026';
        applyStyle(inp, field);
        inp.addEventListener('input', function () {
            field.value = this.value; isDirty = true;
            if (field.named_field_id) syncNamedField(field.named_field_id, this.value, this);
        });
        inp.addEventListener('mousedown', stopProp);
        el.appendChild(inp);
    }

    function renderDateInput(field, el) {
        el.style.background = 'transparent';
        setBorderForDoc(el, field, 'rgba(147,51,234,0.3)');

        var inp = document.createElement('input');
        inp.type = 'date';
        inp.className = 'dp-field-input';
        inp.value = field.value || '';
        applyStyle(inp, field);
        inp.addEventListener('change', function () { field.value = this.value; isDirty = true; });
        inp.addEventListener('mousedown', stopProp);
        el.appendChild(inp);
    }

    function renderSelectionPills(field, el) {
        el.style.background = 'transparent';
        setBorderForDoc(el, field, 'rgba(34,197,94,0.3)');

        var wrap = document.createElement('div');
        wrap.className = 'dp-option-pills';

        (field.options || []).forEach(function (opt) {
            var pill = document.createElement('span');
            pill.className = 'dp-option-pill' + (field.selectedValue === opt ? ' selected' : '');
            pill.textContent = opt;
            pill.addEventListener('click', function (e) {
                e.stopPropagation();
                field.selectedValue = opt;
                isDirty = true;
                renderFieldsForPage(field.pageIndex);
            });
            wrap.appendChild(pill);
        });

        el.appendChild(wrap);
    }

    function renderStrikeToggle(field, el) {
        el.style.cursor = 'pointer';

        if (field.active) {
            el.classList.add('doc-active');
            el.classList.remove('doc-inactive');
            // Draw the strike line
            var sType = field.strikethroughType || 'horizontal';
            if (sType === 'horizontal') {
                var line = document.createElement('div');
                line.style.cssText = 'position:absolute;top:50%;left:0;width:100%;height:2px;background:#ef4444;transform:translateY(-50%);pointer-events:none;';
                el.appendChild(line);
            } else {
                var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 100 100');
                svg.setAttribute('preserveAspectRatio', 'none');
                svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;';
                var ln = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                ln.setAttribute('x1', '0'); ln.setAttribute('y1', '100');
                ln.setAttribute('x2', '100'); ln.setAttribute('y2', '0');
                ln.setAttribute('stroke', '#ef4444'); ln.setAttribute('stroke-width', '2');
                ln.setAttribute('vector-effect', 'non-scaling-stroke');
                svg.appendChild(ln);
                el.appendChild(svg);
            }
        } else {
            el.classList.add('doc-inactive');
            el.classList.remove('doc-active');
        }

        el.addEventListener('click', function (e) {
            if (e.target.closest('.dp-move-handle, .dp-resize-handle, .dp-delete-btn')) return;
            field.active = !field.active;
            isDirty = true;
            renderFieldsForPage(field.pageIndex);
        });
    }

    function renderConditionArea(field, el) {
        el.style.background = 'rgba(255,255,255,0.85)';
        setBorderForDoc(el, field, 'rgba(13,148,136,0.3)');

        var ta = document.createElement('textarea');
        ta.className = 'dp-field-input';
        ta.value = field.text || '';
        ta.placeholder = 'Clause text\u2026';
        applyStyle(ta, field);
        ta.style.fontSize = ((field.style && field.style.fontSize) || 10) + 'px';
        ta.addEventListener('input', function () { field.text = this.value; isDirty = true; });
        ta.addEventListener('mousedown', stopProp);
        el.appendChild(ta);
    }

    function renderSigLine(field, el) {
        el.style.background = 'transparent';
        el.style.border = 'none';

        var wrap = document.createElement('div');
        wrap.className = 'dp-sig-field';

        var line = document.createElement('div');
        line.className = 'dp-sig-line';
        wrap.appendChild(line);

        var lbl = document.createElement('div');
        lbl.className = 'dp-sig-label';
        lbl.textContent = field.type === 'signature' ? 'Signature' : 'Initial';
        wrap.appendChild(lbl);

        el.appendChild(wrap);
    }

    // ======================================================================
    // SHARED UI BUILDERS
    // ======================================================================

    /** Append move / resize / delete handles to a field element */
    function appendHandles(el, fieldId) {
        var mv = document.createElement('div');
        mv.className = 'dp-move-handle';
        mv.addEventListener('mousedown', function (e) { e.stopPropagation(); startDrag(e, fieldId, 'move'); });
        el.appendChild(mv);

        var rs = document.createElement('div');
        rs.className = 'dp-resize-handle';
        rs.addEventListener('mousedown', function (e) { e.stopPropagation(); startDrag(e, fieldId, 'resize'); });
        el.appendChild(rs);

        var del = document.createElement('div');
        del.className = 'dp-delete-btn';
        del.textContent = '\u00D7';
        del.addEventListener('click', function (e) { e.stopPropagation(); deleteField(fieldId); });
        el.appendChild(del);
    }

    /** Build inline toolbar for font/size/bold/underline/bg */
    function buildInlineToolbar(field) {
        var bar = document.createElement('div');
        bar.className = 'dp-inline-toolbar';
        bar.addEventListener('mousedown', stopProp);

        var style = field.style || {};

        // Font family
        var sel = document.createElement('select');
        ['Helvetica', 'Times', 'Courier'].forEach(function (f) {
            var o = document.createElement('option');
            o.value = f; o.textContent = f;
            if ((style.fontFamily || 'Helvetica') === f) o.selected = true;
            sel.appendChild(o);
        });
        sel.addEventListener('change', function () { ensureStyle(field).fontFamily = this.value; isDirty = true; renderFieldsForPage(field.pageIndex); });
        bar.appendChild(sel);

        // Font size
        var sz = document.createElement('input');
        sz.type = 'number'; sz.value = style.fontSize || 12; sz.min = 6; sz.max = 48;
        sz.addEventListener('change', function () { ensureStyle(field).fontSize = parseInt(this.value) || 12; isDirty = true; renderFieldsForPage(field.pageIndex); });
        bar.appendChild(sz);

        // Bold
        var bBtn = document.createElement('button');
        bBtn.textContent = 'B'; bBtn.style.fontWeight = 'bold';
        if (style.bold) bBtn.classList.add('active');
        bBtn.addEventListener('click', function () { var s = ensureStyle(field); s.bold = !s.bold; isDirty = true; renderFieldsForPage(field.pageIndex); });
        bar.appendChild(bBtn);

        // Underline
        var uBtn = document.createElement('button');
        uBtn.textContent = 'U'; uBtn.style.textDecoration = 'underline';
        if (style.underline) uBtn.classList.add('active');
        uBtn.addEventListener('click', function () { var s = ensureStyle(field); s.underline = !s.underline; isDirty = true; renderFieldsForPage(field.pageIndex); });
        bar.appendChild(uBtn);

        // Solid background
        var bgBtn = document.createElement('button');
        bgBtn.textContent = 'BG';
        if (style.solidBackground) bgBtn.classList.add('active');
        bgBtn.addEventListener('click', function () { var s = ensureStyle(field); s.solidBackground = !s.solidBackground; isDirty = true; renderFieldsForPage(field.pageIndex); });
        bar.appendChild(bgBtn);

        // Named Field dropdown (template mode only)
        if (C.mode === 'template' && C.namedFields && C.namedFields.length > 0) {
            var nfSel = document.createElement('select');
            nfSel.className = 'dp-named-field-select';

            var defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = 'Link field\u2026';
            nfSel.appendChild(defOpt);

            C.namedFields.forEach(function (nf) {
                var o = document.createElement('option');
                o.value = nf.id;
                o.textContent = nf.name;
                if (field.named_field_id && field.named_field_id == nf.id) o.selected = true;
                nfSel.appendChild(o);
            });

            nfSel.addEventListener('change', function () {
                if (this.value) {
                    var selOpt = this.options[this.selectedIndex];
                    field.named_field_id = parseInt(this.value);
                    field.named_field_name = selOpt.textContent;
                } else {
                    delete field.named_field_id;
                    delete field.named_field_name;
                }
                isDirty = true;
                renderFieldsForPage(field.pageIndex);
            });

            bar.appendChild(nfSel);
        }

        return bar;
    }

    /** Build comma-separated options editor for selection fields */
    function buildOptionsEditor(field) {
        var wrap = document.createElement('div');
        wrap.className = 'dp-options-editor';
        wrap.addEventListener('mousedown', stopProp);

        var lbl = document.createElement('label');
        lbl.textContent = 'Options (comma-separated)';
        wrap.appendChild(lbl);

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.value = (field.options || []).join(', ');
        inp.addEventListener('change', function () {
            field.options = this.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            isDirty = true;
        });
        wrap.appendChild(inp);

        return wrap;
    }

    /** Build strikethrough-type selector (horizontal / diagonal) */
    function buildStrikeOpts(field) {
        var wrap = document.createElement('div');
        wrap.className = 'dp-strike-opts';
        wrap.addEventListener('mousedown', stopProp);

        var strikeLabels = { horizontal: 'Strikethrough', diagonal: 'Diagonal Strike' };
        ['horizontal', 'diagonal'].forEach(function (t) {
            var btn = document.createElement('button');
            btn.className = 'dp-strike-opt' + ((field.strikethroughType || 'horizontal') === t ? ' active' : '');
            btn.textContent = strikeLabels[t];
            btn.addEventListener('click', function () {
                field.strikethroughType = t;
                isDirty = true;
                renderFieldsForPage(field.pageIndex);
            });
            wrap.appendChild(btn);
        });

        return wrap;
    }

    // ======================================================================
    // FIELD SELECTION
    // ======================================================================
    function selectField(id) {
        if (selectedFieldId === id) return;
        selectedFieldId = id;
        renderAllFields();
    }

    function deselectAll() {
        if (selectedFieldId === null) return;
        selectedFieldId = null;
        renderAllFields();
    }

    // ======================================================================
    // PLACEMENT
    // ======================================================================
    function startPlacement(type, strikethroughType) {
        placementMode = type;
        placementStrikeType = strikethroughType || null;
        editorEl.classList.add('dp-placement-active');
        sidebarEl.querySelectorAll('.dp-sidebar-btn').forEach(function (b) {
            var match = b.dataset.type === type;
            if (strikethroughType) match = match && b.dataset.strikeType === strikethroughType;
            b.classList.toggle('active', match);
        });
    }

    function cancelPlacement() {
        placementMode = null;
        placementStrikeType = null;
        editorEl.classList.remove('dp-placement-active');
        if (sidebarEl) sidebarEl.querySelectorAll('.dp-sidebar-btn').forEach(function (b) { b.classList.remove('active'); });
    }

    function onPlacementStart(e, container, pageIndex) {
        e.preventDefault();
        e.stopPropagation();

        var rect = container.getBoundingClientRect();
        var xP = ((e.clientX - rect.left) / rect.width) * 100;
        var yP = ((e.clientY - rect.top) / rect.height) * 100;

        var nf = {
            id: genId(),
            type: placementMode,
            pageIndex: pageIndex,
            position: { x: xP, y: yP },
            size: { width: 0, height: 0 },
            style: { fontSize: 12, fontFamily: 'Helvetica', bold: false, underline: false, solidBackground: false }
        };

        // Type-specific defaults
        if (placementMode === 'strikethrough')  { nf.active = false; nf.strikethroughType = placementStrikeType || 'horizontal'; }
        if (placementMode === 'selection')      { nf.options = ['Option 1', 'Option 2']; nf.selectedValue = null; }
        if (placementMode === 'condition')      { nf.text = ''; }
        if (C.mode === 'document')              { nf.isUserAdded = true; }

        fields.push(nf);
        isDirty = true;

        dragState = {
            type: 'place',
            fieldId: nf.id,
            startX: e.clientX,
            startY: e.clientY,
            container: container,
            originX: xP,
            originY: yP
        };
    }

    // ======================================================================
    // DRAG — MOVE / RESIZE / PLACE
    // ======================================================================
    function startDrag(e, fieldId, dtype) {
        e.preventDefault();
        var field = findField(fieldId);
        if (!field) return;

        var container = canvasEl.querySelector('[data-page="' + field.pageIndex + '"]');
        if (!container) return;

        dragState = {
            type: dtype,
            fieldId: fieldId,
            startX: e.clientX,
            startY: e.clientY,
            container: container,
            origPosX: field.position.x,
            origPosY: field.position.y,
            origW: field.size.width,
            origH: field.size.height
        };

        selectField(fieldId);
    }

    function onMouseMove(e) {
        if (!dragState) return;
        e.preventDefault();

        var rect = dragState.container.getBoundingClientRect();
        var dxP = ((e.clientX - dragState.startX) / rect.width) * 100;
        var dyP = ((e.clientY - dragState.startY) / rect.height) * 100;
        var field = findField(dragState.fieldId);
        if (!field) return;

        if (dragState.type === 'move') {
            field.position.x = clamp(dragState.origPosX + dxP, 0, 100 - field.size.width);
            field.position.y = clamp(dragState.origPosY + dyP, 0, 100 - field.size.height);
        } else if (dragState.type === 'resize') {
            field.size.width  = clamp(dragState.origW + dxP, 2, 100 - field.position.x);
            field.size.height = clamp(dragState.origH + dyP, 1, 100 - field.position.y);
        } else if (dragState.type === 'place') {
            var nx = Math.min(dragState.originX, dragState.originX + dxP);
            var ny = Math.min(dragState.originY, dragState.originY + dyP);
            field.position.x = clamp(nx, 0, 100);
            field.position.y = clamp(ny, 0, 100);
            field.size.width  = clamp(Math.abs(dxP), 0, 100 - field.position.x);
            field.size.height = clamp(Math.abs(dyP), 0, 100 - field.position.y);
        }

        isDirty = true;

        // Live-update DOM for smooth dragging (avoid full re-render)
        var el = dragState.container.querySelector('[data-field-id="' + field.id + '"]');
        if (el) {
            el.style.left   = field.position.x + '%';
            el.style.top    = field.position.y + '%';
            el.style.width  = field.size.width + '%';
            el.style.height = field.size.height + '%';
        }
    }

    function onMouseUp() {
        if (!dragState) return;
        var field = findField(dragState.fieldId);

        if (dragState.type === 'place') {
            // Ensure minimum size
            if (field && (field.size.width < 2 || field.size.height < 1)) {
                field.size.width  = Math.max(field.size.width, 10);
                field.size.height = Math.max(field.size.height, 3);
            }
            cancelPlacement();

            // Show clause modal for condition fields
            if (field && field.type === 'condition') {
                selectedFieldId = field.id;
                showClauseModal(field.id);
            } else {
                selectedFieldId = field ? field.id : null;
            }
        }

        dragState = null;
        renderAllFields();
    }

    // ======================================================================
    // FIELD DELETION
    // ======================================================================
    function deleteField(id) {
        var idx = fields.findIndex(function (f) { return f.id === id; });
        if (idx === -1) return;
        var pg = fields[idx].pageIndex;
        fields.splice(idx, 1);
        isDirty = true;
        if (selectedFieldId === id) selectedFieldId = null;
        renderFieldsForPage(pg);
    }

    // ======================================================================
    // CLAUSE MODAL
    // ======================================================================
    function showClauseModal(fieldId) {
        var overlay = document.createElement('div');
        overlay.className = 'dp-modal-overlay';
        overlay.id = 'dpClauseModal';

        var modal = document.createElement('div');
        modal.className = 'dp-modal';

        var h = document.createElement('h3');
        h.textContent = 'Select a Clause';
        modal.appendChild(h);

        var list = document.createElement('div');
        list.className = 'dp-clause-list';
        list.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;">Loading clauses\u2026</div>';
        modal.appendChild(list);

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'dp-modal-cancel';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', hideClauseModal);
        modal.appendChild(cancelBtn);

        overlay.appendChild(modal);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) hideClauseModal(); });
        document.body.appendChild(overlay);

        loadClauses().then(function (clauses) {
            list.innerHTML = '';
            if (!clauses.length) {
                list.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;">No clauses available.</div>';
                return;
            }
            clauses.forEach(function (clause) {
                var item = document.createElement('div');
                item.className = 'dp-clause-item';

                var nm = document.createElement('strong');
                nm.textContent = clause.name;
                item.appendChild(nm);

                var pr = document.createElement('p');
                pr.textContent = clause.text;
                item.appendChild(pr);

                item.addEventListener('click', function () {
                    var f = findField(fieldId);
                    if (f) { f.text = clause.text; isDirty = true; }
                    hideClauseModal();
                    renderAllFields();
                });
                list.appendChild(item);
            });
        });
    }

    function hideClauseModal() {
        var m = document.getElementById('dpClauseModal');
        if (m) m.remove();
    }

    function loadClauses() {
        if (clauseCache) return Promise.resolve(clauseCache);
        return fetch(C.clauseApiUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { clauseCache = data; return data; })
            .catch(function () { return []; });
    }

    // ======================================================================
    // SAVE
    // ======================================================================
    function save() {
        var btn = document.getElementById('dpSaveBtn');
        if (btn) { btn.textContent = 'Saving\u2026'; btn.disabled = true; }

        var body = { fields: fields };

        if (C.mode === 'template') {
            var nameEl  = document.getElementById('dpTemplateName');
            var typeEl  = document.getElementById('dpTemplateType');
            var globEl  = document.getElementById('dpGlobal');
            var docTypeEl = document.getElementById('dpDocumentType');

            if (nameEl) body.name = nameEl.value;
            if (typeEl) body.template_type = typeEl.value;
            if (globEl) body.is_global = globEl.checked;
            if (docTypeEl) body.document_type_id = docTypeEl.value || null;

            var brCbs = document.querySelectorAll('.dp-branch-cb:checked');
            body.allowed_branches = Array.from(brCbs).map(function (cb) { return parseInt(cb.value); });
        }

        fetch(C.saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            isDirty = false;
            showToast('Saved successfully', 'success');
        }).catch(function (err) {
            showToast('Save failed: ' + err.message, 'error');
        }).finally(function () {
            if (btn) { btn.textContent = 'Save'; btn.disabled = false; }
        });
    }

    // ======================================================================
    // PDF EXPORT (document mode)
    // ======================================================================
    function downloadPdf() {
        var btn = document.getElementById('dpDownloadBtn');
        if (btn) { btn.textContent = 'Generating\u2026'; btn.disabled = true; }

        var jsPDF = window.jspdf && window.jspdf.jsPDF;
        if (!jsPDF) { showToast('jsPDF not loaded', 'error'); return; }

        var pdf = new jsPDF({ orientation: 'p', unit: 'pt', format: 'letter' });
        var W = 612, H = 792;

        var chain = Promise.resolve();

        C.pageImages.forEach(function (url, i) {
            chain = chain.then(function () {
                if (i > 0) pdf.addPage();
                return loadImage(url).then(function (img) {
                    pdf.addImage(img, 'PNG', 0, 0, W, H);

                    fields.filter(function (f) { return f.pageIndex === i; }).forEach(function (f) {
                        var x = (f.position.x / 100) * W;
                        var y = (f.position.y / 100) * H;
                        var w = (f.size.width / 100) * W;
                        var h = (f.size.height / 100) * H;
                        var st = f.style || {};

                        // Solid background
                        if (st.solidBackground) { pdf.setFillColor(255, 255, 255); pdf.rect(x, y, w, h, 'F'); }

                        // Font
                        var fam = (st.fontFamily || 'helvetica').toLowerCase();
                        pdf.setFont(fam, st.bold ? 'bold' : 'normal');
                        pdf.setFontSize(st.fontSize || 12);
                        pdf.setTextColor(0, 0, 0);

                        switch (f.type) {
                            case 'placeholder':
                            case 'date':
                                if (f.value) {
                                    var fs = st.fontSize || 12;
                                    pdf.text(f.value, x + 2, y + fs, { maxWidth: w - 4 });
                                    if (st.underline) {
                                        var met = pdf.getTextDimensions(f.value, { maxWidth: w - 4 });
                                        pdf.setLineWidth(0.5);
                                        pdf.line(x + 2, y + fs + 1, x + 2 + met.w, y + fs + 1);
                                    }
                                }
                                break;
                            case 'signature':
                            case 'initial':
                                pdf.setDrawColor(0, 0, 0); pdf.setLineWidth(1);
                                pdf.line(x, y + h - 5, x + w, y + h - 5);
                                pdf.setFontSize(8);
                                pdf.text(f.type, x, y + h - 7);
                                break;
                            case 'selection':
                                if (f.selectedValue) pdf.text(f.selectedValue, x + 2, y + h / 2, { baseline: 'middle' });
                                break;
                            case 'strikethrough':
                                if (f.active) {
                                    pdf.setDrawColor(0, 0, 0); pdf.setLineWidth(1.5);
                                    if (f.strikethroughType === 'diagonal') {
                                        pdf.line(x, y + h, x + w, y);
                                    } else {
                                        pdf.line(x, y + h / 2, x + w, y + h / 2);
                                    }
                                }
                                break;
                            case 'condition':
                                if (f.text) {
                                    var cfs = st.fontSize || 10;
                                    pdf.setFontSize(cfs);
                                    pdf.text(f.text, x + 2, y + cfs, { maxWidth: w - 4 });
                                }
                                break;
                        }
                    });
                });
            });
        });

        chain.then(function () {
            pdf.save((C.documentName || 'document') + '.pdf');
            showToast('PDF downloaded', 'success');
        }).catch(function (err) {
            showToast('PDF export failed: ' + err.message, 'error');
        }).finally(function () {
            if (btn) { btn.textContent = 'Download PDF'; btn.disabled = false; }
        });
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('Failed to load image')); };
            img.src = url;
        });
    }

    // ======================================================================
    // PDF UPLOAD (template mode — when page_count === 0)
    // ======================================================================
    function renderUploadZone() {
        var zone = document.createElement('div');
        zone.className = 'dp-upload-zone';
        zone.innerHTML =
            '<div class="dp-upload-icon">[PDF]</div>' +
            '<div class="dp-upload-text">Upload a PDF to create template pages</div>' +
            '<div class="dp-upload-hint">Each page will be rendered as an image for field placement.</div>';

        var fi = document.createElement('input');
        fi.type = 'file'; fi.accept = '.pdf'; fi.style.display = 'none';
        fi.addEventListener('change', function () { if (this.files[0]) processPdfUpload(this.files[0]); });
        zone.appendChild(fi);
        zone.addEventListener('click', function () { fi.click(); });

        editorEl.appendChild(zone);
    }

    function processPdfUpload(file) {
        var zone = editorEl.querySelector('.dp-upload-zone');
        if (zone) zone.innerHTML = '<div class="dp-upload-progress">Processing PDF\u2026</div>';

        file.arrayBuffer().then(function (buf) {
            return pdfjsLib.getDocument(new Uint8Array(buf)).promise;
        }).then(function (pdfDoc) {
            var pages = [];
            var processPage = function (num) {
                if (num > pdfDoc.numPages) return Promise.resolve(pages);
                if (zone) {
                    var prog = zone.querySelector('.dp-upload-progress');
                    if (prog) prog.textContent = 'Rendering page ' + num + ' of ' + pdfDoc.numPages + '\u2026';
                }
                return pdfDoc.getPage(num).then(function (page) {
                    var scale = 2.0;
                    var vp = page.getViewport({ scale: scale });
                    var canvas = document.createElement('canvas');
                    var ctx = canvas.getContext('2d');
                    canvas.width = vp.width;
                    canvas.height = vp.height;
                    return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
                        pages.push(canvas.toDataURL('image/png'));
                        return processPage(num + 1);
                    });
                });
            };
            return processPage(1);
        }).then(function (pages) {
            if (zone) {
                var prog = zone.querySelector('.dp-upload-progress');
                if (prog) prog.textContent = 'Uploading ' + pages.length + ' page images\u2026';
            }
            return fetch(C.uploadPagesUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ pages: pages })
            });
        }).then(function (r) {
            if (!r.ok) throw new Error('Upload failed: HTTP ' + r.status);
            window.location.reload();
        }).catch(function (err) {
            showToast('PDF upload failed: ' + err.message, 'error');
            if (zone) {
                zone.innerHTML =
                    '<div class="dp-upload-icon">[PDF]</div>' +
                    '<div class="dp-upload-text">Upload failed. Click to try again.</div>' +
                    '<div class="dp-upload-hint">' + escHtml(err.message) + '</div>';
                var fi2 = document.createElement('input');
                fi2.type = 'file'; fi2.accept = '.pdf'; fi2.style.display = 'none';
                fi2.addEventListener('change', function () { if (this.files[0]) processPdfUpload(this.files[0]); });
                zone.appendChild(fi2);
                zone.addEventListener('click', function () { fi2.click(); });
            }
        });
    }

    // ======================================================================
    // TOAST
    // ======================================================================
    function showToast(message, type) {
        document.querySelectorAll('.dp-toast').forEach(function (t) { t.remove(); });
        var toast = document.createElement('div');
        toast.className = 'dp-toast ' + (type || 'success');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

    // ======================================================================
    // UTILITIES
    // ======================================================================
    function genId() {
        return 'f_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 9);
    }

    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    function findField(id) { return fields.find(function (f) { return f.id === id; }); }

    function isTextCapable(type) { return ['placeholder', 'date', 'condition'].indexOf(type) !== -1; }

    function ensureStyle(field) { if (!field.style) field.style = {}; return field.style; }

    function applyStyle(el, field) {
        var s = field.style || {};
        el.style.fontSize = (s.fontSize || 12) + 'px';
        el.style.fontFamily = s.fontFamily || 'Helvetica';
        el.style.fontWeight = s.bold ? 'bold' : 'normal';
        el.style.textDecoration = s.underline ? 'underline' : 'none';
        if (s.solidBackground) el.style.background = 'white';
    }

    function setBorderForDoc(el, field, fallbackColor) {
        el.style.border = field.id === selectedFieldId ? '2px solid #00b4d8' : '1px dashed ' + fallbackColor;
    }

    function stopProp(e) { e.stopPropagation(); }

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    // ======================================================================
    // QUICK FILL (document mode)
    // ======================================================================
    function buildQuickFill() {
        // Header with toggle
        var header = document.createElement('div');
        header.className = 'dp-quick-fill-header';

        var title = document.createElement('span');
        title.textContent = 'QUICK FILL';
        header.appendChild(title);

        var toggle = document.createElement('button');
        toggle.className = 'dp-quick-fill-toggle';
        toggle.textContent = '\u25C0';
        toggle.addEventListener('click', function () {
            quickFillEl.classList.toggle('collapsed');
            toggle.textContent = quickFillEl.classList.contains('collapsed') ? '\u25B6' : '\u25C0';
        });
        header.appendChild(toggle);
        quickFillEl.appendChild(header);

        // Fields container
        var fieldsContainer = document.createElement('div');
        fieldsContainer.className = 'dp-quick-fill-fields';

        // Build named field map
        var namedFieldMap = {};
        (C.namedFields || []).forEach(function (nf) {
            namedFieldMap[nf.id] = nf;
        });

        // Find unique named_field_ids in current fields
        var usedNamedFieldIds = [];
        fields.forEach(function (f) {
            if (f.named_field_id && usedNamedFieldIds.indexOf(f.named_field_id) === -1) {
                usedNamedFieldIds.push(f.named_field_id);
            }
        });

        if (usedNamedFieldIds.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'dp-quick-fill-empty';
            empty.textContent = 'No named fields in this document.';
            fieldsContainer.appendChild(empty);
        } else {
            usedNamedFieldIds.forEach(function (nfId) {
                var namedField = namedFieldMap[nfId];
                var item = document.createElement('div');
                item.className = 'dp-quick-fill-item';

                var label = document.createElement('label');
                label.textContent = namedField ? namedField.name : 'Field #' + nfId;
                item.appendChild(label);

                var input = document.createElement('input');
                input.type = 'text';
                input.dataset.namedFieldId = nfId;

                // Get current value from first matching field
                var firstMatch = fields.find(function (f) { return f.named_field_id == nfId; });
                input.value = firstMatch ? (firstMatch.value || '') : '';

                input.addEventListener('input', function () {
                    syncNamedField(nfId, this.value, this);
                });

                item.appendChild(input);
                fieldsContainer.appendChild(item);
            });
        }

        quickFillEl.appendChild(fieldsContainer);
    }

    function syncNamedField(namedFieldId, value, sourceInput) {
        // Update all fields with this named_field_id
        fields.forEach(function (f) {
            if (f.named_field_id == namedFieldId) {
                f.value = value;
            }
        });
        isDirty = true;

        // Update canvas inputs directly (skip the source input)
        document.querySelectorAll('.dp-field').forEach(function (fieldEl) {
            var fId = fieldEl.dataset.fieldId;
            var f = findField(fId);
            if (f && f.named_field_id == namedFieldId) {
                var inp = fieldEl.querySelector('.dp-field-input');
                if (inp && inp !== sourceInput) {
                    inp.value = value;
                }
            }
        });

        // Update quick fill sidebar input (skip the source input)
        if (quickFillEl) {
            var qfInput = quickFillEl.querySelector('input[data-named-field-id="' + namedFieldId + '"]');
            if (qfInput && qfInput !== sourceInput) {
                qfInput.value = value;
            }
        }

        // Debounced save to pack instance
        if (C.packInstanceSaveUrl && C.packInstanceId) {
            clearTimeout(quickFillDebounce);
            quickFillDebounce = setTimeout(function () {
                savePackInstanceValue(namedFieldId, value);
            }, 500);
        }
    }

    function savePackInstanceValue(namedFieldId, value) {
        fetch(C.packInstanceSaveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({
                pack_instance_id: C.packInstanceId,
                named_field_id: namedFieldId,
                value: value
            })
        }).catch(function () { /* silent fail for debounced saves */ });
    }

    // ======================================================================
    // BOOT
    // ======================================================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
