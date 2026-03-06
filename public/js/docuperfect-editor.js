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
    var placementMode = null;   // null, a field type string, or 'zone'
    var isDirty = false;
    var dragState = null;       // { type, fieldId, startX, startY, container, ... }
    var clauseCache = null;
    var placementStrikeType = null;
    var quickFillEl = null;
    var quickFillDebounce = null;

    // Signature zone state (template mode only)
    var signatureZones = JSON.parse(JSON.stringify(C.signatureZones || []));
    var selectedZoneId = null;
    var placementZoneType = null; // 'signature' or 'initial'

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

        // Deselect on click outside fields and zones
        document.addEventListener('mousedown', function (e) {
            if (placementMode || dragState) return;
            if (!e.target.closest('.dp-field') &&
                !e.target.closest('.dp-zone') &&
                !e.target.closest('.dp-inline-toolbar') &&
                !e.target.closest('.dp-options-editor') &&
                !e.target.closest('.dp-strike-opts') &&
                !e.target.closest('.dp-zone-props') &&
                !e.target.closest('.dp-toolbar')) {
                deselectAll();
            }
        });

        // Global drag handlers
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);

        // Fix-position page header and toolbar
        setupFixedBars();
    }

    // ======================================================================
    // LAYOUT
    // ======================================================================
    function buildLayout() {
        // Horizontal toolbar — sticky below the page action bar
        sidebarEl = document.createElement('div');
        sidebarEl.className = 'dp-toolbar';
        buildSidebar();
        editorEl.appendChild(sidebarEl);

        // Body row: optional fields panel (left) + canvas (right)
        var bodyRow = document.createElement('div');
        bodyRow.className = 'dp-body-row';

        // Quick fill sidebar (document mode only)
        if (C.mode === 'document') {
            quickFillEl = document.createElement('div');
            quickFillEl.className = 'dp-quick-fill-sidebar';
            buildQuickFill();
            bodyRow.appendChild(quickFillEl);
        }

        // Canvas area
        canvasEl = document.createElement('div');
        canvasEl.className = 'dp-canvas-area';
        bodyRow.appendChild(canvasEl);

        editorEl.appendChild(bodyRow);
    }

    function buildSidebar() {
        var lbl = document.createElement('span');
        lbl.className = 'dp-toolbar-label';
        lbl.textContent = 'Fields';
        sidebarEl.appendChild(lbl);

        TYPES.forEach(function (t) {
            var btn = document.createElement('button');
            btn.className = 'dp-toolbar-btn';
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

        // Signature Zones section (template mode only)
        if (C.mode === 'template') {
            var sep = document.createElement('div');
            sep.className = 'dp-toolbar-sep';
            sidebarEl.appendChild(sep);

            var zoneLbl = document.createElement('span');
            zoneLbl.className = 'dp-toolbar-label';
            zoneLbl.textContent = 'Sign Zones';
            sidebarEl.appendChild(zoneLbl);

            var ZONE_TYPES = [
                { zoneType: 'signature', label: 'Sig Zone', icon: '\u270D' },
                { zoneType: 'initial',   label: 'Init Zone', icon: 'Iz' }
            ];

            ZONE_TYPES.forEach(function (zt) {
                var btn = document.createElement('button');
                btn.className = 'dp-toolbar-btn';
                btn.dataset.zoneType = zt.zoneType;
                btn.innerHTML = '<span class="dp-btn-icon">' + zt.icon + '</span>' + zt.label;
                btn.addEventListener('click', function () {
                    if (placementMode === 'zone' && placementZoneType === zt.zoneType) {
                        cancelPlacement();
                    } else {
                        startZonePlacement(zt.zoneType);
                    }
                });
                sidebarEl.appendChild(btn);
            });
        }
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
        C.pageImages.forEach(function (_, i) {
            renderFieldsForPage(i);
            renderZonesForPage(i);
        });
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

        // Required badge (red asterisk)
        if (field.required) {
            var reqBadge = document.createElement('div');
            reqBadge.className = 'dp-required-badge';
            reqBadge.textContent = '*';
            el.appendChild(reqBadge);
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

        // Inline toolbar — all types in template mode (required/name/label for all; font controls for text-capable)
        if (field.id === selectedFieldId) {
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

        var inp = document.createElement('textarea');
        inp.className = 'dp-field-input dp-field-textarea';
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

    /** Build inline toolbar for font/size/bold/underline/bg + required/name/label (template mode) */
    function buildInlineToolbar(field) {
        var bar = document.createElement('div');
        bar.className = 'dp-inline-toolbar';
        bar.addEventListener('mousedown', stopProp);

        var style = field.style || {};

        // Font/style controls (text-capable types only)
        if (isTextCapable(field.type)) {
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
        }

        // Template mode: Required / Field Name / Field Label controls (all field types)
        if (C.mode === 'template') {
            // Separator if font controls were shown
            if (isTextCapable(field.type)) {
                var sep = document.createElement('div');
                sep.style.cssText = 'width:1px;height:20px;background:rgba(255,255,255,0.2);margin:0 4px;';
                bar.appendChild(sep);
            }

            // Required checkbox
            var reqWrap = document.createElement('label');
            reqWrap.style.cssText = 'display:flex;align-items:center;gap:3px;color:white;font-size:10px;cursor:pointer;white-space:nowrap;';
            var reqCb = document.createElement('input');
            reqCb.type = 'checkbox';
            reqCb.checked = !!field.required;
            reqCb.style.cssText = 'margin:0;';
            reqCb.addEventListener('change', function () {
                field.required = this.checked;
                isDirty = true;
                renderFieldsForPage(field.pageIndex);
            });
            reqWrap.appendChild(reqCb);
            reqWrap.appendChild(document.createTextNode('Req'));
            bar.appendChild(reqWrap);

            // Field Name input
            var fnInp = document.createElement('input');
            fnInp.type = 'text';
            fnInp.value = field.field_name || '';
            fnInp.placeholder = 'field_name';
            fnInp.style.cssText = 'width:75px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:white;border-radius:4px;padding:2px 4px;font-size:10px;outline:none;';
            fnInp.addEventListener('change', function () {
                field.field_name = this.value || null;
                isDirty = true;
            });
            bar.appendChild(fnInp);

            // Field Label input
            var flInp = document.createElement('input');
            flInp.type = 'text';
            flInp.value = field.field_label || '';
            flInp.placeholder = 'Label';
            flInp.style.cssText = 'width:75px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:white;border-radius:4px;padding:2px 4px;font-size:10px;outline:none;';
            flInp.addEventListener('change', function () {
                field.field_label = this.value || null;
                isDirty = true;
            });
            bar.appendChild(flInp);
        }

        // Named Field dropdown (template mode, text-capable only)
        if (C.mode === 'template' && isTextCapable(field.type)) {
            var nfSel = document.createElement('select');
            nfSel.className = 'dp-named-field-select';

            var defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = 'Link field\u2026';
            nfSel.appendChild(defOpt);

            (C.namedFields || []).forEach(function (nf) {
                var o = document.createElement('option');
                o.value = nf.id;
                o.textContent = nf.name;
                if (field.named_field_id && field.named_field_id == nf.id) o.selected = true;
                nfSel.appendChild(o);
            });

            // "+ Create New" option at bottom
            var createOpt = document.createElement('option');
            createOpt.value = '__create_new__';
            createOpt.textContent = '+ Create New\u2026';
            nfSel.appendChild(createOpt);

            nfSel.addEventListener('change', function () {
                if (this.value === '__create_new__') {
                    this.value = field.named_field_id || '';
                    openCreateNamedFieldModal(field);
                    return;
                }
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
        if (selectedFieldId === id && selectedZoneId === null) return;
        selectedFieldId = id;
        selectedZoneId = null;
        renderAllFields();
    }

    function selectZone(id) {
        if (selectedZoneId === id && selectedFieldId === null) return;
        selectedZoneId = id;
        selectedFieldId = null;
        renderAllFields();
    }

    function deselectAll() {
        if (selectedFieldId === null && selectedZoneId === null) return;
        selectedFieldId = null;
        selectedZoneId = null;
        renderAllFields();
    }

    // ======================================================================
    // PLACEMENT
    // ======================================================================
    function startPlacement(type, strikethroughType) {
        placementMode = type;
        placementStrikeType = strikethroughType || null;
        editorEl.classList.add('dp-placement-active');
        sidebarEl.querySelectorAll('.dp-toolbar-btn').forEach(function (b) {
            var match = b.dataset.type === type;
            if (strikethroughType) match = match && b.dataset.strikeType === strikethroughType;
            b.classList.toggle('active', match);
        });
    }

    function cancelPlacement() {
        placementMode = null;
        placementStrikeType = null;
        placementZoneType = null;
        editorEl.classList.remove('dp-placement-active');
        if (sidebarEl) sidebarEl.querySelectorAll('.dp-toolbar-btn').forEach(function (b) { b.classList.remove('active'); });
    }

    function startZonePlacement(zoneType) {
        placementMode = 'zone';
        placementZoneType = zoneType;
        editorEl.classList.add('dp-placement-active');
        sidebarEl.querySelectorAll('.dp-toolbar-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.zoneType === zoneType);
        });
    }

    function onPlacementStart(e, container, pageIndex) {
        e.preventDefault();
        e.stopPropagation();

        var rect = container.getBoundingClientRect();
        var xP = ((e.clientX - rect.left) / rect.width) * 100;
        var yP = ((e.clientY - rect.top) / rect.height) * 100;

        // Zone placement
        if (placementMode === 'zone') {
            var nz = {
                _id: genId(),
                page_index: pageIndex,
                x_position: xP,
                y_position: yP,
                width: 0,
                height: 0,
                type: placementZoneType || 'signature',
                assigned_parties: ['agent', 'tenant', 'landlord'],
                label: '',
                required: true
            };
            signatureZones.push(nz);
            isDirty = true;

            dragState = {
                type: 'place-zone',
                zoneId: nz._id,
                startX: e.clientX,
                startY: e.clientY,
                container: container,
                originX: xP,
                originY: yP
            };
            return;
        }

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

        // Zone drag types
        if (dragState.type === 'move-zone' || dragState.type === 'resize-zone' || dragState.type === 'place-zone') {
            var zone = findZone(dragState.zoneId);
            if (!zone) return;

            if (dragState.type === 'move-zone') {
                zone.x_position = clamp(dragState.origPosX + dxP, 0, 100 - zone.width);
                zone.y_position = clamp(dragState.origPosY + dyP, 0, 100 - zone.height);
            } else if (dragState.type === 'resize-zone') {
                zone.width  = clamp(dragState.origW + dxP, 5, 100 - zone.x_position);
                zone.height = clamp(dragState.origH + dyP, 3, 100 - zone.y_position);
            } else if (dragState.type === 'place-zone') {
                var znx = Math.min(dragState.originX, dragState.originX + dxP);
                var zny = Math.min(dragState.originY, dragState.originY + dyP);
                zone.x_position = clamp(znx, 0, 100);
                zone.y_position = clamp(zny, 0, 100);
                zone.width  = clamp(Math.abs(dxP), 0, 100 - zone.x_position);
                zone.height = clamp(Math.abs(dyP), 0, 100 - zone.y_position);
            }

            isDirty = true;

            var zel = dragState.container.querySelector('[data-zone-id="' + zone._id + '"]');
            if (zel) {
                zel.style.left   = zone.x_position + '%';
                zel.style.top    = zone.y_position + '%';
                zel.style.width  = zone.width + '%';
                zel.style.height = zone.height + '%';
            }
            return;
        }

        // Field drag types
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

        // Zone drag types
        if (dragState.type === 'place-zone' || dragState.type === 'move-zone' || dragState.type === 'resize-zone') {
            var zone = findZone(dragState.zoneId);
            if (dragState.type === 'place-zone') {
                // Ensure minimum size
                if (zone && (zone.width < 5 || zone.height < 3)) {
                    zone.width  = Math.max(zone.width, 15);
                    zone.height = Math.max(zone.height, 5);
                }
                cancelPlacement();
                selectedZoneId = zone ? zone._id : null;
                selectedFieldId = null;
            }
            dragState = null;
            renderAllFields();
            return;
        }

        // Field drag types
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
    // SIGNATURE ZONES (template mode)
    // ======================================================================
    function renderZonesForPage(pageIndex) {
        if (C.mode !== 'template') return;
        var container = canvasEl.querySelector('[data-page="' + pageIndex + '"]');
        if (!container) return;

        // Remove old zone elements
        container.querySelectorAll('.dp-zone, .dp-zone-props').forEach(function (el) { el.remove(); });

        signatureZones.filter(function (z) { return z.page_index === pageIndex; }).forEach(function (zone) {
            container.appendChild(createZoneElement(zone));
        });
    }

    function createZoneElement(zone) {
        var el = document.createElement('div');
        el.className = 'dp-zone' + (zone._id === selectedZoneId ? ' selected' : '');
        el.dataset.zoneId = zone._id;
        el.dataset.zoneType = zone.type;

        el.style.left   = zone.x_position + '%';
        el.style.top    = zone.y_position + '%';
        el.style.width  = zone.width + '%';
        el.style.height = zone.height + '%';

        // Label inside zone
        var lbl = document.createElement('div');
        lbl.className = 'dp-zone-label';
        var typeLabel = zone.type === 'initial' ? 'Init' : 'Sig';
        var parties = (zone.assigned_parties || []).map(function (p) {
            return p.charAt(0).toUpperCase() + p.slice(1);
        }).join(' + ');
        lbl.textContent = typeLabel + ': ' + (parties || 'No parties');
        el.appendChild(lbl);

        // Click to select
        el.addEventListener('mousedown', function (e) {
            if (placementMode) return;
            if (e.target.closest('.dp-zone-move, .dp-zone-resize, .dp-zone-delete')) return;
            e.stopPropagation();
            selectZone(zone._id);
        });

        // Handles (visible when selected)
        appendZoneHandles(el, zone);

        // Properties panel (visible when selected)
        if (zone._id === selectedZoneId) {
            el.appendChild(buildZonePropertiesPanel(zone));
        }

        return el;
    }

    function appendZoneHandles(el, zone) {
        var mv = document.createElement('div');
        mv.className = 'dp-zone-move';
        mv.addEventListener('mousedown', function (e) { e.stopPropagation(); startZoneDrag(e, zone._id, 'move-zone'); });
        el.appendChild(mv);

        var rs = document.createElement('div');
        rs.className = 'dp-zone-resize';
        rs.addEventListener('mousedown', function (e) { e.stopPropagation(); startZoneDrag(e, zone._id, 'resize-zone'); });
        el.appendChild(rs);

        var del = document.createElement('div');
        del.className = 'dp-zone-delete';
        del.textContent = '\u00D7';
        del.addEventListener('click', function (e) { e.stopPropagation(); deleteZone(zone._id); });
        el.appendChild(del);
    }

    function buildZonePropertiesPanel(zone) {
        var panel = document.createElement('div');
        panel.className = 'dp-zone-props';
        panel.addEventListener('mousedown', stopProp);

        // Type radio: Signature / Initial
        var typeRow = document.createElement('div');
        typeRow.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
        ['signature', 'initial'].forEach(function (t) {
            var lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex;align-items:center;gap:3px;font-size:11px;cursor:pointer;';
            var rb = document.createElement('input');
            rb.type = 'radio'; rb.name = 'ztype_' + zone._id; rb.value = t;
            rb.checked = zone.type === t;
            rb.addEventListener('change', function () {
                zone.type = t; isDirty = true;
                renderZonesForPage(zone.page_index);
            });
            lbl.appendChild(rb);
            lbl.appendChild(document.createTextNode(t === 'signature' ? 'Signature' : 'Initial'));
            typeRow.appendChild(lbl);
        });
        panel.appendChild(typeRow);

        // Party checkboxes
        var partyLbl = document.createElement('div');
        partyLbl.style.cssText = 'font-size:10px;color:#64748b;margin-bottom:3px;';
        partyLbl.textContent = 'Parties:';
        panel.appendChild(partyLbl);

        var partyRow = document.createElement('div');
        partyRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;';
        ['agent', 'tenant', 'landlord', 'witness1', 'witness2'].forEach(function (p) {
            var lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex;align-items:center;gap:2px;font-size:11px;cursor:pointer;';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = (zone.assigned_parties || []).indexOf(p) !== -1;
            cb.addEventListener('change', function () {
                if (!zone.assigned_parties) zone.assigned_parties = [];
                if (this.checked) {
                    if (zone.assigned_parties.indexOf(p) === -1) zone.assigned_parties.push(p);
                } else {
                    zone.assigned_parties = zone.assigned_parties.filter(function (x) { return x !== p; });
                }
                isDirty = true;
                renderZonesForPage(zone.page_index);
            });
            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(p.charAt(0).toUpperCase() + p.slice(1)));
            partyRow.appendChild(lbl);
        });
        panel.appendChild(partyRow);

        // Label input
        var lblRow = document.createElement('div');
        lblRow.style.cssText = 'display:flex;align-items:center;gap:4px;margin-bottom:6px;';
        var lblTxt = document.createElement('span');
        lblTxt.style.cssText = 'font-size:10px;color:#64748b;';
        lblTxt.textContent = 'Label:';
        lblRow.appendChild(lblTxt);
        var lblInp = document.createElement('input');
        lblInp.type = 'text';
        lblInp.value = zone.label || '';
        lblInp.placeholder = 'Optional label';
        lblInp.style.cssText = 'flex:1;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:11px;outline:none;';
        lblInp.addEventListener('change', function () {
            zone.label = this.value || ''; isDirty = true;
        });
        lblRow.appendChild(lblInp);
        panel.appendChild(lblRow);

        // Required toggle
        var reqRow = document.createElement('label');
        reqRow.style.cssText = 'display:flex;align-items:center;gap:4px;font-size:11px;cursor:pointer;';
        var reqCb = document.createElement('input');
        reqCb.type = 'checkbox';
        reqCb.checked = zone.required !== false;
        reqCb.addEventListener('change', function () {
            zone.required = this.checked; isDirty = true;
        });
        reqRow.appendChild(reqCb);
        reqRow.appendChild(document.createTextNode('Required'));
        panel.appendChild(reqRow);

        return panel;
    }

    function startZoneDrag(e, zoneId, dtype) {
        e.preventDefault();
        var zone = findZone(zoneId);
        if (!zone) return;

        var container = canvasEl.querySelector('[data-page="' + zone.page_index + '"]');
        if (!container) return;

        dragState = {
            type: dtype,
            zoneId: zoneId,
            startX: e.clientX,
            startY: e.clientY,
            container: container,
            origPosX: zone.x_position,
            origPosY: zone.y_position,
            origW: zone.width,
            origH: zone.height
        };

        selectZone(zoneId);
    }

    function deleteZone(id) {
        var idx = signatureZones.findIndex(function (z) { return z._id === id; });
        if (idx === -1) return;
        var pg = signatureZones[idx].page_index;
        signatureZones.splice(idx, 1);
        isDirty = true;
        if (selectedZoneId === id) selectedZoneId = null;
        renderZonesForPage(pg);
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

            // Include signature zones
            body.signature_zones = signatureZones.map(function (z) {
                return {
                    id: z.id || null,
                    page_index: z.page_index,
                    x_position: z.x_position,
                    y_position: z.y_position,
                    width: z.width,
                    height: z.height,
                    type: z.type,
                    assigned_parties: z.assigned_parties || [],
                    label: z.label || '',
                    required: z.required !== false
                };
            });
        }

        fetch(C.saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            isDirty = false;
            showToast('Saved successfully', 'success');
            document.dispatchEvent(new CustomEvent('docuperfect:saved'));
        }).catch(function (err) {
            showToast('Save failed: ' + err.message, 'error');
            document.dispatchEvent(new CustomEvent('docuperfect:save-failed'));
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
    // CREATE NAMED FIELD MODAL (template mode — on-the-fly)
    // ======================================================================
    function openCreateNamedFieldModal(targetField) {
        // Remove any existing modal
        var existing = document.querySelector('.dp-nf-modal-overlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.className = 'dp-nf-modal-overlay';

        var modal = document.createElement('div');
        modal.className = 'dp-nf-modal';

        var title = document.createElement('h3');
        title.textContent = 'Create Named Field';
        modal.appendChild(title);

        // Display Label
        var lblGroup = createFormGroup('Display Name', 'text', 'dp-nf-label');
        modal.appendChild(lblGroup.wrap);

        // Auto-generated field name (read-only display)
        var namePreview = document.createElement('div');
        namePreview.className = 'dp-nf-name-preview';
        namePreview.textContent = '';
        modal.appendChild(namePreview);

        // Duplicate warning
        var dupWarn = document.createElement('div');
        dupWarn.className = 'dp-nf-dup-warn';
        dupWarn.style.display = 'none';
        modal.appendChild(dupWarn);

        // Field Type
        var typeGroup = document.createElement('div');
        typeGroup.className = 'dp-nf-form-group';
        var typeLbl = document.createElement('label');
        typeLbl.textContent = 'Type';
        typeGroup.appendChild(typeLbl);
        var typeSel = document.createElement('select');
        typeSel.id = 'dp-nf-type';
        [['text', 'Text'], ['date', 'Date'], ['selection', 'Selection']].forEach(function (t) {
            var o = document.createElement('option');
            o.value = t[0];
            o.textContent = t[1];
            typeSel.appendChild(o);
        });
        typeGroup.appendChild(typeSel);
        modal.appendChild(typeGroup);

        // Options input (shown only for selection type)
        var optGroup = createFormGroup('Options (comma-separated)', 'text', 'dp-nf-options');
        optGroup.wrap.style.display = 'none';
        modal.appendChild(optGroup.wrap);

        typeSel.addEventListener('change', function () {
            optGroup.wrap.style.display = this.value === 'selection' ? '' : 'none';
        });

        // Auto-generate name from label
        lblGroup.input.addEventListener('input', function () {
            var label = this.value;
            var generated = label.toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, '_').substring(0, 50);
            namePreview.textContent = generated ? 'Field name: ' + generated : '';

            // Check for duplicate
            var dup = (C.namedFields || []).find(function (nf) {
                return nf.name.toLowerCase().replace(/\s+/g, '_') === generated || nf.name.toLowerCase() === label.toLowerCase();
            });
            if (dup) {
                dupWarn.style.display = '';
                dupWarn.innerHTML = '';
                var warnText = document.createElement('span');
                warnText.textContent = '"' + dup.name + '" already exists \u2014 ';
                dupWarn.appendChild(warnText);
                var selectBtn = document.createElement('button');
                selectBtn.type = 'button';
                selectBtn.textContent = 'Select Existing';
                selectBtn.className = 'dp-nf-dup-btn';
                selectBtn.addEventListener('click', function () {
                    targetField.named_field_id = dup.id;
                    targetField.named_field_name = dup.name;
                    isDirty = true;
                    renderFieldsForPage(targetField.pageIndex);
                    overlay.remove();
                });
                dupWarn.appendChild(selectBtn);
            } else {
                dupWarn.style.display = 'none';
            }
        });

        // Buttons
        var btnRow = document.createElement('div');
        btnRow.className = 'dp-nf-btn-row';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.className = 'dp-nf-btn dp-nf-btn-cancel';
        cancelBtn.addEventListener('click', function () { overlay.remove(); });
        btnRow.appendChild(cancelBtn);

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.textContent = 'Create & Select';
        saveBtn.className = 'dp-nf-btn dp-nf-btn-save';
        saveBtn.addEventListener('click', function () {
            var label = lblGroup.input.value.trim();
            if (!label) {
                showToast('Name is required', 'error');
                lblGroup.input.focus();
                return;
            }
            saveBtn.disabled = true;
            saveBtn.textContent = 'Creating\u2026';

            var payload = {
                name: label,
                field_type: typeSel.value,
                default_options: typeSel.value === 'selection' ? optGroup.input.value : null,
            };

            fetch('/docuperfect/settings/named-fields', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': C.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            }).then(function (r) {
                if (!r.ok) return r.json().then(function (e) { throw new Error(e.message || JSON.stringify(e.errors || 'Unknown error')); });
                return r.json();
            }).then(function (data) {
                // Add to in-memory list
                C.namedFields = C.namedFields || [];
                C.namedFields.push(data.field);

                // Auto-select in target field
                targetField.named_field_id = data.field.id;
                targetField.named_field_name = data.field.name;
                isDirty = true;
                renderFieldsForPage(targetField.pageIndex);

                overlay.remove();
                showToast('Named field "' + data.field.name + '" created & linked');
            }).catch(function (err) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Create & Select';
                showToast('Error: ' + err.message, 'error');
            });
        });
        btnRow.appendChild(saveBtn);

        modal.appendChild(btnRow);
        overlay.appendChild(modal);

        // Close on overlay click
        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) overlay.remove();
        });

        document.body.appendChild(overlay);
        lblGroup.input.focus();
    }

    function createFormGroup(labelText, inputType, inputId) {
        var wrap = document.createElement('div');
        wrap.className = 'dp-nf-form-group';
        var lbl = document.createElement('label');
        lbl.textContent = labelText;
        wrap.appendChild(lbl);
        var input = document.createElement('input');
        input.type = inputType;
        input.id = inputId;
        wrap.appendChild(input);
        return { wrap: wrap, input: input };
    }

    // ======================================================================
    // TOAST
    // ======================================================================
    function showToast(message, type) {
        // Use global toast system if available
        if (window.showToast) {
            window.showToast(message, type || 'success');
            return;
        }
        // Fallback to DOM-based toast
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

    function findZone(id) { return signatureZones.find(function (z) { return z._id === id; }); }

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
    // FIXED HEADER + TOOLBAR POSITIONING
    // ======================================================================
    function setupFixedBars() {
        var appScroll = document.getElementById('appScroll');
        var sidebar = document.querySelector('aside');
        var phWrap = document.getElementById('dp-page-header');
        if (!phWrap) return;
        var phEl = phWrap.firstElementChild; // the page-header component's outer div
        if (!phEl) return;
        var toolbar = sidebarEl; // the .dp-toolbar element created in buildLayout

        // Insert spacer for page header (replaces its space in document flow)
        var phSpacer = document.createElement('div');
        phSpacer.id = 'dp-ph-spacer';
        phWrap.parentNode.insertBefore(phSpacer, phWrap.nextSibling);

        // Insert spacer for toolbar inside the editor layout
        var tbSpacer = document.createElement('div');
        tbSpacer.id = 'dp-tb-spacer';
        if (toolbar && toolbar.parentNode) {
            toolbar.parentNode.insertBefore(tbSpacer, toolbar.nextSibling);
        }

        function recalc() {
            // Sidebar width: on desktop (>= 1024px) use actual width, on mobile 0
            var sidebarWidth = 0;
            if (window.innerWidth >= 1024 && sidebar) {
                sidebarWidth = sidebar.getBoundingClientRect().width;
            }

            // Top offset: where #appScroll begins relative to viewport
            var scrollTop = appScroll ? appScroll.getBoundingClientRect().top : 0;
            var phHeight = 56; // h-14 = 3.5rem = 56px

            // Fix page header
            phEl.style.position = 'fixed';
            phEl.style.top = scrollTop + 'px';
            phEl.style.left = sidebarWidth + 'px';
            phEl.style.right = '0';
            phEl.style.zIndex = '40';
            phEl.style.background = '#fff';
            phEl.style.borderBottom = '1px solid #e5e7eb';
            phEl.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';

            // Spacer replaces header in flow
            phSpacer.style.height = phHeight + 'px';

            // Fix toolbar
            if (toolbar) {
                var tbHeight = toolbar.offsetHeight || 44;
                toolbar.style.position = 'fixed';
                toolbar.style.top = (scrollTop + phHeight) + 'px';
                toolbar.style.left = sidebarWidth + 'px';
                toolbar.style.right = '0';
                toolbar.style.zIndex = '35';
                toolbar.style.borderRadius = '0';

                // Spacer replaces toolbar in editor flow
                tbSpacer.style.height = tbHeight + 'px';
            }
        }

        recalc();

        // Recalculate on resize
        window.addEventListener('resize', recalc);

        // Watch sidebar for size changes (collapse/expand toggle)
        if (sidebar && window.ResizeObserver) {
            new ResizeObserver(recalc).observe(sidebar);
        }
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
