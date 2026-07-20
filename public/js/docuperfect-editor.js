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

    // Copy-paste state (template mode)
    var copiedField = null;
    var lastClickPos = null; // { pageIndex, x, y }

    // System field placement state (template mode)
    var pendingSystemField = null; // { id, name, sourceType, sourceContactType }

    // Field group placement state (template mode)
    var pendingFieldGroup = null; // { id, name, layout, fields: [{named_field_id, label, pillar}] }
    var fieldGroupsCache = null;  // loaded from /docuperfect/field-groups/json

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
        { type: 'tick',          label: 'Tick',       icon: '\u2611' },
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

        // Fetch field groups for template mode
        if (C.mode === 'template') {
            fetchFieldGroups();
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

        // AT-220 — session armour. (1) Heartbeat keeps the CSRF token + session
        // fresh so an open editor never expires. (2) Debounced autosave persists
        // work server-side so nothing is stranded in the tab if it is lost.
        if (window.CoreXSessionGuard) {
            window.CoreXSessionGuard.startHeartbeat(function (t) { C.csrfToken = t; });
        }
        setInterval(function () {
            if (isDirty && !document.hidden) { save({ silent: true }); }
        }, 30000);

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

        // Keyboard shortcuts (Ctrl+C copy, Ctrl+V paste, template mode)
        document.addEventListener('keydown', function (e) {
            if (C.mode !== 'template') return;
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
            if ((e.ctrlKey || e.metaKey) && e.key === 'c' && selectedFieldId) {
                e.preventDefault();
                copySelectedField();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'v' && copiedField && lastClickPos) {
                e.preventDefault();
                pasteField(lastClickPos.pageIndex, lastClickPos.x, lastClickPos.y);
            }
        });

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

        // System fields panel (template mode only)
        if (C.mode === 'template' && C.systemFields) {
            var sfPanel = document.createElement('div');
            sfPanel.className = 'dp-system-fields-panel';
            buildSystemFieldsPanel(sfPanel);
            bodyRow.appendChild(sfPanel);
        }

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

            // Click on page image to place field / track last click position
            container.addEventListener('mousedown', function (e) {
                if (!e.target.closest('.dp-field') && !e.target.closest('.dp-zone')) {
                    var r = container.getBoundingClientRect();
                    lastClickPos = {
                        pageIndex: idx,
                        x: ((e.clientX - r.left) / r.width) * 100,
                        y: ((e.clientY - r.top) / r.height) * 100
                    };
                }
                if (placementMode && !e.target.closest('.dp-field')) {
                    onPlacementStart(e, container, idx);
                }
            });

            // Drag-and-drop: page container is a drop target
            container.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                container.classList.add('dp-drop-target');
            });
            container.addEventListener('dragleave', function (e) {
                // Only remove if leaving the container itself (not entering a child)
                if (!container.contains(e.relatedTarget)) {
                    container.classList.remove('dp-drop-target');
                }
            });
            container.addEventListener('drop', function (e) {
                e.preventDefault();
                container.classList.remove('dp-drop-target');
                var raw = e.dataTransfer.getData('application/json');
                if (!raw) return;
                try {
                    var data = JSON.parse(raw);
                } catch (err) { return; }

                var rect = container.getBoundingClientRect();
                var xP = ((e.clientX - rect.left) / rect.width) * 100;
                var yP = ((e.clientY - rect.top) / rect.height) * 100;

                if (data._dropType === 'zone') {
                    createZoneAtPosition({
                        pageIndex: idx,
                        x: xP,
                        y: yP,
                        zoneType: data.zoneType
                    });
                } else {
                    createFieldAtPosition({
                        type: data.type || 'placeholder',
                        pageIndex: idx,
                        x: xP,
                        y: yP,
                        named_field_id: data.named_field_id || null,
                        named_field_name: data.named_field_name || '',
                        assignedTo: data.assignedTo || 'creator',
                        strikethroughType: data.strikethroughType || null
                    });
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
        // Backward compat: migrate old selection+renderMode:"tick" → type "tick"
        if (field.type === 'selection' && field.renderMode === 'tick') {
            field.type = 'tick';
            delete field.renderMode;
        }
        // Clean up renderMode from selection fields (no longer used)
        if (field.type === 'selection' && field.renderMode) {
            delete field.renderMode;
        }

        var el = document.createElement('div');
        var cls = 'dp-field' + (field.id === selectedFieldId ? ' selected' : '');
        if (field.named_field_id && isSystemField(field.named_field_id)) cls += ' dp-system-field';
        el.className = cls;
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

        // Overlap warning: check if this field overlaps another field with a different assignedTo
        if (C.mode === 'template') {
            var pageFields = fields.filter(function (f) { return f.pageIndex === field.pageIndex && f.id !== field.id; });
            var hasOverlap = pageFields.some(function (other) {
                if ((other.assignedTo || 'creator') === (field.assignedTo || 'creator')) return false;
                var ox = other.position.x, oy = other.position.y, ow = other.size.width, oh = other.size.height;
                var fx = field.position.x, fy = field.position.y, fw = field.size.width, fh = field.size.height;
                var overlapX = Math.max(0, Math.min(fx + fw, ox + ow) - Math.max(fx, ox));
                var overlapY = Math.max(0, Math.min(fy + fh, oy + oh) - Math.max(fy, oy));
                return (overlapX * overlapY) / (fw * fh) > 0.3;
            });
            if (hasOverlap) {
                el.style.outline = '2px dashed #ef4444';
                el.style.outlineOffset = '1px';
                var warnBadge = document.createElement('div');
                warnBadge.style.cssText = 'position:absolute;top:-8px;right:-8px;width:16px;height:16px;background:#ef4444;color:white;border-radius:50%;font-size:10px;font-weight:bold;display:flex;align-items:center;justify-content:center;z-index:20;';
                warnBadge.textContent = '!';
                warnBadge.title = 'Overlaps a field assigned to a different signer';
                el.appendChild(warnBadge);
            }
        }

        return el;
    }

    // ------------------------------------------------------------------
    // Template-mode field
    // ------------------------------------------------------------------
    function buildTemplateField(field, el) {
        // Backward compat: default assignedTo to 'creator'
        if (!field.assignedTo) field.assignedTo = 'creator';

        // Type label
        var lbl = document.createElement('div');
        lbl.className = 'dp-field-label';
        lbl.textContent = field.type;
        el.appendChild(lbl);

        // Show assignedTo badge if not creator
        if (field.assignedTo !== 'creator') {
            var atBadge = document.createElement('div');
            atBadge.className = 'dp-assigned-badge';
            var atLabels = { agent: 'Agent', tenant: 'Tenant', landlord: 'Landlord', buyer: 'Buyer', seller: 'Seller', lessor: 'Landlord', lessee: 'Tenant' };
            atBadge.textContent = atLabels[field.assignedTo] || field.assignedTo;
            el.appendChild(atBadge);
        }

        // Select on click
        el.addEventListener('mousedown', function (e) {
            if (placementMode) return;
            if (e.target.closest('.dp-move-handle, .dp-resize-handle, .dp-delete-btn')) return;
            e.stopPropagation();
            selectField(field.id);
        });

        // Handles
        appendHandles(el, field.id);

        // Tick-type zone preview (column dividers + labels)
        if (field.type === 'tick') {
            var opts = field.options || [];
            if (opts.length > 1) {
                for (var zi = 0; zi < opts.length; zi++) {
                    // Faint label centered in each zone
                    var zLbl = document.createElement('div');
                    var zoneW = 100 / opts.length;
                    zLbl.style.cssText = 'position:absolute;top:0;height:100%;display:flex;align-items:center;justify-content:center;font-size:9px;color:rgba(0,0,0,0.5);font-weight:500;pointer-events:none;overflow:hidden;white-space:nowrap;';
                    zLbl.style.left = (zi * zoneW) + '%';
                    zLbl.style.width = zoneW + '%';
                    zLbl.textContent = opts[zi];
                    el.appendChild(zLbl);
                    // Divider line (skip first)
                    if (zi > 0) {
                        var zDiv = document.createElement('div');
                        zDiv.style.cssText = 'position:absolute;top:0;height:100%;width:1px;background:rgba(0,0,0,0.2);pointer-events:none;';
                        zDiv.style.left = (zi * zoneW) + '%';
                        el.appendChild(zDiv);
                    }
                }
            }
        }

        // Options editor for selection and tick types (when selected)
        if ((field.type === 'selection' || field.type === 'tick') && field.id === selectedFieldId) {
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
        // Backward compat: default assignedTo to 'creator'
        if (!field.assignedTo) field.assignedTo = 'creator';

        var userAdded = !!field.isUserAdded;

        // If field is assigned to a signer (not creator), show greyed-out placeholder
        if (field.assignedTo !== 'creator') {
            el.style.background = 'rgba(148,163,184,0.15)';
            el.style.border = '1px dashed rgba(148,163,184,0.5)';
            el.style.pointerEvents = 'none';
            el.style.cursor = 'default';
            var signerLabel = document.createElement('div');
            signerLabel.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:10px;color:#64748b;font-style:italic;text-align:center;padding:2px;line-height:1.2;';
            var signerLabels = { agent: 'Agent', tenant: 'Tenant', landlord: 'Landlord', buyer: 'Buyer', seller: 'Seller', lessor: 'Landlord', lessee: 'Tenant' };
            signerLabel.textContent = (signerLabels[field.assignedTo] || field.assignedTo) + ' will complete';
            el.appendChild(signerLabel);
            return;
        }

        // User-added fields get handles
        if (userAdded) appendHandles(el, field.id);

        // Type-specific interactive rendering
        switch (field.type) {
            case 'placeholder':   renderPlaceholderInput(field, el); break;
            case 'date':          renderDateInput(field, el);        break;
            case 'selection':     renderSelectionPills(field, el);   break;
            case 'tick':          renderTickPills(field, el);       break;
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
        selectFieldOnFocus(inp, field);
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
        selectFieldOnFocus(inp, field);
        el.appendChild(inp);
    }

    function renderSelectionPills(field, el) {
        var hasSolidBg = !!field.solidBg;
        var opts = field.options || [];
        var optCount = opts.length || 1;

        el.style.background = 'transparent';
        setBorderForDoc(el, field, 'rgba(34,197,94,0.3)');

        if (field.selectedValue) {
            var selIdx = opts.indexOf(field.selectedValue);
            if (selIdx === -1) selIdx = 0;
            var sectionW = 100 / optCount;

            if (hasSolidBg) {
                var bgRect = document.createElement('div');
                bgRect.style.cssText = 'position:absolute;top:0;height:100%;background:white;pointer-events:none;z-index:1;';
                bgRect.style.left = (selIdx * sectionW) + '%';
                bgRect.style.width = sectionW + '%';
                el.appendChild(bgRect);
            }

            var textOverlay = document.createElement('div');
            textOverlay.style.cssText = 'position:absolute;top:0;height:100%;display:flex;align-items:center;justify-content:center;color:#000;font-weight:600;pointer-events:none;z-index:2;overflow:hidden;white-space:nowrap;';
            textOverlay.style.left = (selIdx * sectionW) + '%';
            textOverlay.style.width = sectionW + '%';
            textOverlay.style.fontSize = (el.offsetHeight ? Math.round(el.offsetHeight * 0.65) + 'px' : '0.9em');
            textOverlay.textContent = field.selectedValue;
            el.appendChild(textOverlay);
        } else if (hasSolidBg) {
            el.style.background = 'white';
        }

        // Option pills for interaction
        var wrap = document.createElement('div');
        wrap.className = 'dp-option-pills';

        opts.forEach(function (opt) {
            var pill = document.createElement('span');
            pill.className = 'dp-option-pill' + (field.selectedValue === opt ? ' selected' : '');
            pill.textContent = opt;
            pill.addEventListener('click', function (e) {
                e.stopPropagation();
                field.selectedValue = (field.selectedValue === opt) ? null : opt;
                isDirty = true;
                renderFieldsForPage(field.pageIndex);
            });
            wrap.appendChild(pill);
        });

        el.appendChild(wrap);
    }

    function renderTickPills(field, el) {
        var hasSolidBg = field.solidBg !== false;
        var opts = field.options || [];
        var optCount = opts.length || 1;

        el.style.background = 'transparent';
        setBorderForDoc(el, field, 'rgba(34,197,94,0.3)');

        if (field.selectedValue) {
            var selIdx = opts.indexOf(field.selectedValue);
            if (selIdx === -1) selIdx = 0;
            var sectionW = 100 / optCount;

            if (hasSolidBg) {
                var bgRect = document.createElement('div');
                bgRect.style.cssText = 'position:absolute;top:0;height:100%;background:white;pointer-events:none;z-index:1;';
                bgRect.style.left = (selIdx * sectionW) + '%';
                bgRect.style.width = sectionW + '%';
                el.appendChild(bgRect);
            }

            var tickOverlay = document.createElement('div');
            tickOverlay.style.cssText = 'position:absolute;top:0;height:100%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#000;pointer-events:none;z-index:2;';
            tickOverlay.style.left = (selIdx * sectionW) + '%';
            tickOverlay.style.width = sectionW + '%';
            tickOverlay.style.fontSize = (el.offsetHeight ? Math.round(el.offsetHeight * 0.85) + 'px' : '1.3em');
            tickOverlay.textContent = 'X';
            el.appendChild(tickOverlay);
        }

        // Option pills for interaction (hidden until hover)
        var wrap = document.createElement('div');
        wrap.className = 'dp-option-pills dp-tick-pills-overlay';

        opts.forEach(function (opt) {
            var pill = document.createElement('span');
            pill.className = 'dp-option-pill' + (field.selectedValue === opt ? ' selected' : '');
            pill.textContent = opt;
            pill.addEventListener('click', function (e) {
                e.stopPropagation();
                field.selectedValue = (field.selectedValue === opt) ? null : opt;
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
        selectFieldOnFocus(ta, field);
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

        // Duplicate button (template mode only)
        if (C.mode === 'template') {
            var dup = document.createElement('div');
            dup.className = 'dp-dup-btn';
            dup.textContent = '\u29C9';
            dup.title = 'Duplicate field';
            dup.addEventListener('mousedown', function (e) {
                e.stopPropagation();
                selectField(fieldId);
                duplicateSelectedField();
            });
            el.appendChild(dup);
        }

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

        // Dynamic positioning: show below field if too close to top, above otherwise
        if (field.position && field.position.y < 12) {
            bar.style.top = 'auto';
            bar.style.bottom = '-46px';
        } else {
            bar.style.top = '-42px';
            bar.style.bottom = 'auto';
        }

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

            // Assigned To dropdown — who completes this field
            var atSep = document.createElement('div');
            atSep.style.cssText = 'width:1px;height:20px;background:rgba(255,255,255,0.2);margin:0 4px;';
            bar.appendChild(atSep);

            var atLbl = document.createElement('span');
            atLbl.textContent = 'Assigned:';
            atLbl.style.cssText = 'color:white;font-size:10px;white-space:nowrap;';
            bar.appendChild(atLbl);

            var atSel = document.createElement('select');
            atSel.style.cssText = 'background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:white;border-radius:4px;padding:2px 4px;font-size:10px;outline:none;';
            var atOptions = [
                { value: 'creator', label: 'Document Creator' },
                { value: 'agent', label: 'Agent' },
                { value: 'tenant', label: 'Tenant / Lessee' },
                { value: 'landlord', label: 'Landlord / Lessor' },
                { value: 'buyer', label: 'Buyer' },
                { value: 'seller', label: 'Seller' }
            ];
            atOptions.forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.label;
                o.style.color = '#000';
                if ((field.assignedTo || 'creator') === opt.value) o.selected = true;
                atSel.appendChild(o);
            });
            atSel.addEventListener('change', function () {
                field.assignedTo = this.value;
                isDirty = true;
                renderFieldsForPage(field.pageIndex);
            });
            bar.appendChild(atSel);
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

            // Show auto-fill source info for system-linked fields
            if (field.named_field_id && isSystemField(field.named_field_id)) {
                var sourceInfo = document.createElement('span');
                sourceInfo.style.cssText = 'font-size:10px;color:#60a5fa;white-space:nowrap;padding:0 4px;';
                sourceInfo.textContent = '\uD83D\uDD17 Auto-fills: ' + (field.named_field_name || 'System');
                bar.appendChild(sourceInfo);
            }
        }

        return bar;
    }

    /** Build comma-separated options editor for selection and tick fields */
    function buildOptionsEditor(field) {
        var wrap = document.createElement('div');
        wrap.className = 'dp-options-editor';
        wrap.style.cssText = 'min-width:240px;padding:12px;';
        wrap.addEventListener('mousedown', stopProp);

        // --- Options input ---
        var lbl = document.createElement('label');
        lbl.textContent = 'Options (comma-separated)';
        lbl.style.cssText = 'display:block;font-size:12px;color:#64748b;margin-bottom:4px;';
        wrap.appendChild(lbl);

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.value = (field.options || []).join(', ');
        inp.style.cssText = 'width:100%;border:1px solid #e2e8f0;border-radius:4px;padding:6px 8px;font-size:12px;box-sizing:border-box;outline:none;';
        inp.addEventListener('change', function () {
            field.options = this.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            isDirty = true;
        });
        inp.addEventListener('focus', function () { this.style.borderColor = '#00b4d8'; });
        inp.addEventListener('blur', function () { this.style.borderColor = '#e2e8f0'; });
        wrap.appendChild(inp);

        // --- Solid background checkbox ---
        var bgLbl = document.createElement('label');
        bgLbl.style.cssText = 'display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;color:#334155;margin-top:8px;';
        var bgCb = document.createElement('input');
        bgCb.type = 'checkbox';
        bgCb.checked = field.type === 'tick' ? (field.solidBg !== false) : !!field.solidBg;
        bgCb.addEventListener('change', function () {
            field.solidBg = this.checked;
            isDirty = true;
        });
        bgLbl.appendChild(bgCb);
        bgLbl.appendChild(document.createTextNode('Solid background'));
        wrap.appendChild(bgLbl);

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
        // AT-312 — renderAllFields rebuilds the field DOM, so restore the cursor to
        // the just-selected field's input; clicking a (template) field then shows the
        // font toolbar AND keeps the cursor so you can type without a second click.
        if (C.mode === 'document') {
            var inpEl = editorEl.querySelector('.dp-field.selected .dp-field-input');
            if (inpEl) { inpEl.focus(); try { var L = (inpEl.value || '').length; inpEl.setSelectionRange(L, L); } catch (e) {} }
        }
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
        pendingSystemField = null;
        pendingFieldGroup = null;
        editorEl.classList.remove('dp-placement-active');
        if (sidebarEl) sidebarEl.querySelectorAll('.dp-toolbar-btn').forEach(function (b) { b.classList.remove('active'); });
        // Deactivate system fields panel items
        var activeItems = document.querySelectorAll('.dp-sf-item.active');
        activeItems.forEach(function (el) { el.classList.remove('active'); });
    }

    function startZonePlacement(zoneType) {
        placementMode = 'zone';
        placementZoneType = zoneType;
        editorEl.classList.add('dp-placement-active');
        sidebarEl.querySelectorAll('.dp-toolbar-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.zoneType === zoneType);
        });
    }

    /**
     * Create a field at a specific position (used by both click-to-place and drag-and-drop).
     * @param {object} config - { type, pageIndex, x, y, named_field_id, named_field_name, assignedTo, strikethroughType }
     */
    function createFieldAtPosition(config) {
        var nf = {
            id: genId(),
            type: config.type || 'placeholder',
            pageIndex: config.pageIndex,
            position: { x: config.x, y: config.y },
            size: { width: config.width || 15, height: config.height || 2.5 },
            style: { fontSize: 12, fontFamily: 'Helvetica', bold: false, underline: false, solidBackground: false }
        };

        nf.assignedTo = config.assignedTo || 'creator';
        if (nf.type === 'strikethrough')  { nf.active = false; nf.strikethroughType = config.strikethroughType || 'horizontal'; }
        if (nf.type === 'selection')      { nf.options = ['Option 1', 'Option 2']; nf.selectedValue = null; nf.solidBg = false; }
        if (nf.type === 'tick')           { nf.options = ['Yes', 'No', 'N/A']; nf.selectedValue = null; nf.solidBg = true; }
        if (nf.type === 'condition')      { nf.text = ''; }

        if (config.named_field_id) {
            nf.named_field_id = config.named_field_id;
            nf.named_field_name = config.named_field_name || '';
        }

        fields.push(nf);
        isDirty = true;
        selectedFieldId = nf.id;
        renderAllFields();
        return nf;
    }

    /**
     * Create a signature zone at a specific position (used by drag-and-drop).
     */
    function createZoneAtPosition(config) {
        var nz = {
            _id: genId(),
            page_index: config.pageIndex,
            x_position: config.x,
            y_position: config.y,
            width: config.width || 15,
            height: config.height || 4,
            type: config.zoneType || 'signature',
            assigned_parties: ['agent', 'tenant', 'landlord'],
            label: '',
            required: true
        };
        signatureZones.push(nz);
        isDirty = true;
        selectedZoneId = nz._id;
        renderAllFields();
        return nz;
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

        // Field Group placement — draw bounding box, split into rows on mouseup
        if (placementMode === 'group' && pendingFieldGroup) {
            // Create a temporary preview element
            var preview = document.createElement('div');
            preview.className = 'dp-group-preview';
            preview.style.position = 'absolute';
            preview.style.left = xP + '%';
            preview.style.top = yP + '%';
            preview.style.width = '0%';
            preview.style.height = '0%';
            preview.style.border = '2px dashed #14b8a6';
            preview.style.background = 'rgba(20,184,166,0.08)';
            preview.style.pointerEvents = 'none';
            preview.style.zIndex = '9999';
            preview.style.borderRadius = '4px';
            container.appendChild(preview);

            dragState = {
                type: 'place-group',
                group: pendingFieldGroup,
                startX: e.clientX,
                startY: e.clientY,
                container: container,
                pageIndex: pageIndex,
                originX: xP,
                originY: yP,
                previewEl: preview
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
        nf.assignedTo = 'creator'; // Default: document creator fills this field
        if (placementMode === 'strikethrough')  { nf.active = false; nf.strikethroughType = placementStrikeType || 'horizontal'; }
        if (placementMode === 'selection')      { nf.options = ['Option 1', 'Option 2']; nf.selectedValue = null; nf.solidBg = false; }
        if (placementMode === 'tick')           { nf.options = ['Yes', 'No', 'N/A']; nf.selectedValue = null; nf.solidBg = true; }
        if (placementMode === 'condition')      { nf.text = ''; }
        if (C.mode === 'document')              { nf.isUserAdded = true; }

        // Apply system field data if placing from the System Fields panel
        if (pendingSystemField) {
            nf.named_field_id = pendingSystemField.id;
            nf.named_field_name = pendingSystemField.name;
            nf.assignedTo = pendingSystemField.assignedTo || 'creator';
            pendingSystemField = null;
            // Deactivate the active item in the panel
            var activeItems = document.querySelectorAll('.dp-sf-item.active');
            activeItems.forEach(function (el) { el.classList.remove('active'); });
        }

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

        // Field Group placement preview
        if (dragState.type === 'place-group') {
            var gnx = Math.min(dragState.originX, dragState.originX + dxP);
            var gny = Math.min(dragState.originY, dragState.originY + dyP);
            var gw = Math.abs(dxP);
            var gh = Math.abs(dyP);
            if (dragState.previewEl) {
                dragState.previewEl.style.left   = clamp(gnx, 0, 100) + '%';
                dragState.previewEl.style.top    = clamp(gny, 0, 100) + '%';
                dragState.previewEl.style.width  = clamp(gw, 0, 100 - gnx) + '%';
                dragState.previewEl.style.height = clamp(gh, 0, 100 - gny) + '%';
            }
            return;
        }

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

        // Field Group placement — split bounding box into rows and create fields
        if (dragState.type === 'place-group') {
            var rect = dragState.container.getBoundingClientRect();
            var dxP = ((event.clientX - dragState.startX) / rect.width) * 100;
            var dyP = ((event.clientY - dragState.startY) / rect.height) * 100;

            var boxX = clamp(Math.min(dragState.originX, dragState.originX + dxP), 0, 100);
            var boxY = clamp(Math.min(dragState.originY, dragState.originY + dyP), 0, 100);
            var boxW = clamp(Math.abs(dxP), 0, 100 - boxX);
            var boxH = clamp(Math.abs(dyP), 0, 100 - boxY);

            // Remove preview element
            if (dragState.previewEl && dragState.previewEl.parentNode) {
                dragState.previewEl.parentNode.removeChild(dragState.previewEl);
            }

            var grp = dragState.group;
            var fieldCount = grp.fields.length;

            // Enforce minimum size based on layout direction
            if (grp.layout === 'horizontal') {
                if (boxW < (fieldCount * 5)) boxW = fieldCount * 5;
                if (boxH < 2.5) boxH = 2.5;
            } else {
                if (boxW < 5) boxW = 15;
                if (boxH < (fieldCount * 1.5)) boxH = fieldCount * 2.5;
            }

            var firstCreated = null;

            // Determine assignedTo based on pillar
            var pillarAssignMap = {
                property: 'creator',
                contact_lessor: 'lessor',
                contact_lessee: 'lessee',
                contact_seller: 'seller',
                contact_buyer: 'buyer',
                agent: 'creator',
                computed: 'creator',
                static: 'creator',
                manual: 'creator'
            };

            if (grp.layout === 'horizontal') {
                // Divide the drawn box into equal columns
                var colWidth = boxW / fieldCount;
                for (var i = 0; i < fieldCount; i++) {
                    var gf = grp.fields[i];
                    var created = createFieldAtPosition({
                        type: 'placeholder',
                        pageIndex: dragState.pageIndex,
                        x: boxX + (i * colWidth),
                        y: boxY,
                        width: colWidth,
                        height: boxH,
                        named_field_id: gf.named_field_id,
                        named_field_name: gf.label || '',
                        assignedTo: pillarAssignMap[gf.source_group] || 'creator'
                    });
                    if (i === 0) firstCreated = created;
                }
            } else {
                // Vertical — existing logic (default)
                var rowHeight = boxH / fieldCount;
                for (var i = 0; i < fieldCount; i++) {
                    var gf = grp.fields[i];
                    var created = createFieldAtPosition({
                        type: 'placeholder',
                        pageIndex: dragState.pageIndex,
                        x: boxX,
                        y: boxY + (i * rowHeight),
                        width: boxW,
                        height: rowHeight,
                        named_field_id: gf.named_field_id,
                        named_field_name: gf.label || '',
                        assignedTo: pillarAssignMap[gf.source_group] || 'creator'
                    });
                    if (i === 0) firstCreated = created;
                }
            }

            cancelPlacement();
            pendingFieldGroup = null;
            if (firstCreated) selectedFieldId = firstCreated.id;
            dragState = null;
            renderAllFields();
            return;
        }

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
    function save(opts) {
        // The Save button passes a MouseEvent; autosave passes { silent: true }.
        if (opts && typeof opts.preventDefault === 'function') opts = null;
        var silent = !!(opts && opts.silent);

        var btn = document.getElementById('dpSaveBtn');
        if (btn && !silent) { btn.textContent = 'Saving\u2026'; btn.disabled = true; }

        var body = { fields: fields };

        if (C.mode === 'template') {
            var nameEl  = document.getElementById('dpTemplateName');
            var typeEl  = document.getElementById('dpTemplateType');
            var globEl  = document.getElementById('dpGlobal');
            var docTypeEl = document.getElementById('dpDocumentType');

            var catEl   = document.getElementById('dpCategory');

            if (nameEl) body.name = nameEl.value;
            if (typeEl) body.template_type = typeEl.value;
            if (catEl) body.category = catEl.value || null;
            if (globEl) body.is_global = globEl.checked;
            var esignEl = document.getElementById('dpEsign');
            if (esignEl) body.is_esign = esignEl.checked;
            var partyModeEl = document.querySelector('input[name="party_mode"]:checked');
            if (partyModeEl) body.party_mode = partyModeEl.value;
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

        // AT-220 — route the save through the session guard: a 419 is silently
        // recovered (fresh token + one resubmit); a genuinely-dead session shows
        // the plain-language "connection lost" banner, never an HTTP status code.
        var doRequest = function (token) {
            return fetch(C.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token || C.csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify(body),
                credentials: 'same-origin'
            });
        };

        var finish = function () { if (btn && !silent) { btn.textContent = 'Save'; btn.disabled = false; } };

        if (!window.CoreXSessionGuard) {
            // Fallback (guard script missing) — still never print a raw code.
            doRequest(C.csrfToken).then(function (r) {
                if (!r.ok) throw new Error('save');
                isDirty = false;
                if (!silent) showToast('Saved successfully', 'success');
                document.dispatchEvent(new CustomEvent('docuperfect:saved'));
            }).catch(function () {
                if (!silent) showToast('Could not save — please try again in a moment.', 'error');
                document.dispatchEvent(new CustomEvent('docuperfect:save-failed'));
            }).finally(finish);
            return;
        }

        window.CoreXSessionGuard.guardedSubmit(doRequest, {
            getToken: function () { return C.csrfToken; },
            setToken: function (t) { C.csrfToken = t; },
            silent: silent,
            onRetry: function () { save(); }
        }).then(function (r) {
            if (!r.ok) throw new Error('save-failed');
            isDirty = false;
            if (!silent) showToast('Saved successfully', 'success');
            document.dispatchEvent(new CustomEvent('docuperfect:saved'));
        }).catch(function (err) {
            if (err && err.sessionDead) {
                // Guard already showed the banner — work is safe in the tab.
                document.dispatchEvent(new CustomEvent('docuperfect:save-failed'));
                return;
            }
            if (!silent) showToast('Could not save — please try again in a moment.', 'error');
            document.dispatchEvent(new CustomEvent('docuperfect:save-failed'));
        }).finally(finish);
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
                        // Backward compat: migrate old renderMode:"tick" → type "tick"
                        if (f.type === 'selection' && f.renderMode === 'tick') { f.type = 'tick'; delete f.renderMode; }
                        if (f.type === 'selection' && f.renderMode) { delete f.renderMode; }

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
                                var selOpts = f.options || [];
                                var selCount = selOpts.length || 1;
                                if (f.selectedValue) {
                                    var selIdx = selOpts.indexOf(f.selectedValue);
                                    if (selIdx === -1) selIdx = 0;
                                    var secW = w / selCount;
                                    var secX = x + selIdx * secW;
                                    if (f.solidBg) {
                                        pdf.setFillColor(255, 255, 255);
                                        pdf.rect(secX, y, secW, h, 'F');
                                    }
                                    var txtFs = Math.round(h * 0.6);
                                    pdf.setFontSize(txtFs > 4 ? txtFs : 8);
                                    pdf.text(f.selectedValue, secX + secW / 2, y + h / 2, { align: 'center', baseline: 'middle' });
                                } else if (f.solidBg) {
                                    pdf.setFillColor(255, 255, 255);
                                    pdf.rect(x, y, w, h, 'F');
                                }
                                break;
                            case 'tick':
                                var tickOpts = f.options || [];
                                var tickCount = tickOpts.length || 1;
                                if (f.selectedValue) {
                                    var tIdx = tickOpts.indexOf(f.selectedValue);
                                    if (tIdx === -1) tIdx = 0;
                                    var tSecW = w / tickCount;
                                    var tSecX = x + tIdx * tSecW;
                                    if (f.solidBg !== false) {
                                        pdf.setFillColor(255, 255, 255);
                                        pdf.rect(tSecX, y, tSecW, h, 'F');
                                    }
                                    var tickFs = Math.round(h * 0.8);
                                    pdf.setFontSize(tickFs > 4 ? tickFs : 10);
                                    pdf.setFont('helvetica', 'bold');
                                    pdf.text('X', tSecX + tSecW / 2, y + h / 2, { align: 'center', baseline: 'middle' });
                                    pdf.setFont('helvetica', 'normal');
                                }
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
        // AT-207: the page-raster routes (docuperfect.page.image / documents.pageImage)
        // are auth-gated. The old approach set img.crossOrigin='anonymous', whose
        // credentials mode is "same-origin" — so on a cross-origin URL (the edit page
        // is used on corex.hfcoastal.co.za while route()/APP_URL emits corexos.co.za)
        // the browser dropped the session cookie, the auth middleware 302'd to /login,
        // and the <img> failed to decode HTML → "Failed to load image".
        //
        // Fix (origin-agnostic, no CORS needed, untainted for jsPDF): rebase the URL to
        // the CURRENT origin (same-origin path) so the fetch carries the session the
        // agent is actually logged in with, fetch credentialed, then hand jsPDF a
        // same-origin data URL. Every failure mode surfaces a diagnostic message.
        var fetchUrl = url;
        try {
            var u = new URL(url, window.location.href);
            fetchUrl = u.pathname + u.search; // same-origin relative → current host + cookie
        } catch (e) { /* non-absolute URL: fetch as-is */ }

        return fetch(fetchUrl, { credentials: 'include' }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('page image HTTP ' + resp.status + ' (' + fetchUrl + ')');
            }
            if (resp.redirected) {
                // followed a redirect (e.g. → /login when the session has expired)
                var to = fetchUrl;
                try { to = new URL(resp.url).pathname; } catch (e2) {}
                throw new Error('not authenticated (redirected to ' + to + ') — please reload and sign in');
            }
            var ct = resp.headers.get('content-type') || '';
            if (ct.indexOf('image') === -1) {
                throw new Error('unexpected response "' + (ct || 'unknown') + '" for page image (' + fetchUrl + ')');
            }
            return resp.blob();
        }, function () {
            // fetch rejected outright = network failure / CORS block
            throw new Error('network error loading page image (' + fetchUrl + ')');
        }).then(function (blob) {
            return new Promise(function (resolve, reject) {
                var reader = new FileReader();
                reader.onload = function () {
                    var img = new Image();
                    img.onload = function () { resolve(img); };
                    img.onerror = function () { reject(new Error('could not decode page image (' + fetchUrl + ')')); };
                    img.src = reader.result; // data: URL — same-origin, never taints the jsPDF canvas
                };
                reader.onerror = function () { reject(new Error('could not read page image data (' + fetchUrl + ')')); };
                reader.readAsDataURL(blob);
            });
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
        [['text', 'Text'], ['date', 'Date'], ['selection', 'Selection'], ['tick', 'Tick']].forEach(function (t) {
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
            optGroup.wrap.style.display = (this.value === 'selection' || this.value === 'tick') ? '' : 'none';
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
                default_options: (typeSel.value === 'selection' || typeSel.value === 'tick') ? optGroup.input.value : null,
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

    // AT-312 — select a field when its value input gains focus. Template-defined
    // fields (e.g. "Lessor Name") have no move/resize handles to click, and the
    // input stops mousedown propagation to the field's select handler, so without
    // this they can never become selectedField and the per-field font/format
    // toolbar never appears. Focusing the input (clicking in to type) now selects
    // the field exactly like a manually-added text field does.
    function selectFieldOnFocus(inp, field) {
        inp.addEventListener('focus', function () {
            if (selectedFieldId !== field.id) selectField(field.id);
        });
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    // ======================================================================
    // SYSTEM FIELDS PANEL (template mode)
    // ======================================================================

    // Cache of system field IDs for quick lookup
    var _systemFieldIds = null;
    function isSystemField(namedFieldId) {
        if (!C.systemFields) return false;
        if (!_systemFieldIds) {
            _systemFieldIds = {};
            Object.keys(C.systemFields).forEach(function (groupKey) {
                (C.systemFields[groupKey] || []).forEach(function (sf) {
                    _systemFieldIds[sf.id] = true;
                });
            });
        }
        return !!_systemFieldIds[namedFieldId];
    }

    function buildSystemFieldsPanel(panel) {
        // Header
        var header = document.createElement('div');
        header.className = 'dp-sf-header';
        var headerLabel = document.createElement('span');
        headerLabel.textContent = 'System Fields';
        header.appendChild(headerLabel);
        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'dp-sf-toggle';
        toggleBtn.innerHTML = '\u00AB';
        toggleBtn.addEventListener('click', function () {
            panel.classList.toggle('collapsed');
            toggleBtn.innerHTML = panel.classList.contains('collapsed') ? '\u00BB' : '\u00AB';
        });
        header.appendChild(toggleBtn);
        panel.appendChild(header);

        var content = document.createElement('div');
        content.className = 'dp-sf-content';

        // ── Field Groups section (above System Fields) ──
        var fgLabel = document.createElement('div');
        fgLabel.className = 'dp-sf-section-label';
        fgLabel.textContent = 'Field Groups';
        content.appendChild(fgLabel);

        var fgContainer = document.createElement('div');
        fgContainer.className = 'dp-fg-container';
        fgContainer.id = 'dp-field-groups-list';
        var fgLoading = document.createElement('div');
        fgLoading.className = 'dp-sf-item';
        fgLoading.style.opacity = '0.5';
        fgLoading.style.fontStyle = 'italic';
        fgLoading.textContent = 'Loading groups...';
        fgContainer.appendChild(fgLoading);
        content.appendChild(fgContainer);

        var fgDivider = document.createElement('div');
        fgDivider.className = 'dp-sf-divider';
        content.appendChild(fgDivider);

        // Group display config
        var groupConfig = {
            property:        { label: 'Property',  icon: '\uD83C\uDFE0' },
            contact_lessor:  { label: 'Lessor',     icon: '\uD83D\uDC64' },
            contact_lessee:  { label: 'Lessee',     icon: '\uD83D\uDC64' },
            contact_seller:  { label: 'Seller',     icon: '\uD83D\uDC64' },
            contact_buyer:   { label: 'Buyer',      icon: '\uD83D\uDC64' },
            agent:           { label: 'Agent',      icon: '\uD83D\uDC68\u200D\uD83D\uDCBC' },
            computed:        { label: 'Computed',    icon: '\uD83D\uDDA9' },
            static:          { label: 'Static',     icon: '\uD83D\uDCCC' }
        };

        // assignedTo mapping based on source group
        var assignedToMap = {
            property:        'creator',
            contact_lessor:  'lessor',
            contact_lessee:  'lessee',
            contact_seller:  'seller',
            contact_buyer:   'buyer',
            agent:           'creator',
            computed:        'creator',
            static:          'creator'
        };

        // Build groups from server data
        var groupOrder = ['property', 'contact_lessor', 'contact_lessee', 'contact_seller', 'contact_buyer', 'agent', 'computed', 'static'];
        groupOrder.forEach(function (groupKey) {
            var items = C.systemFields[groupKey];
            if (!items || items.length === 0) return;

            var cfg = groupConfig[groupKey] || { label: groupKey, icon: '\uD83D\uDCCB' };
            var group = document.createElement('div');
            group.className = 'dp-sf-group';

            var groupHeader = document.createElement('div');
            groupHeader.className = 'dp-sf-group-header';
            groupHeader.innerHTML = '<span class="dp-sf-group-arrow">\u25BE</span>' +
                '<span class="dp-sf-group-icon">' + cfg.icon + '</span>' +
                '<span>' + cfg.label + '</span>';
            groupHeader.addEventListener('click', function () {
                group.classList.toggle('collapsed');
            });
            group.appendChild(groupHeader);

            var itemsContainer = document.createElement('div');
            itemsContainer.className = 'dp-sf-group-items';

            items.forEach(function (sf) {
                var item = document.createElement('div');
                item.className = 'dp-sf-item';
                item.dataset.source = groupKey;
                item.dataset.fieldId = sf.id;
                item.draggable = true;
                item.innerHTML = '<span class="dp-sf-item-dot"></span>' + escHtml(sf.name);
                item.title = 'Drag onto page or click to place: ' + sf.name;

                // Drag-and-drop
                item.addEventListener('dragstart', function (e) {
                    e.dataTransfer.setData('application/json', JSON.stringify({
                        _dropType: 'field',
                        type: 'placeholder',
                        named_field_id: parseInt(sf.id),
                        named_field_name: sf.name,
                        assignedTo: assignedToMap[groupKey] || 'creator'
                    }));
                    e.dataTransfer.effectAllowed = 'copy';
                    item.classList.add('dp-sf-dragging');
                });
                item.addEventListener('dragend', function () {
                    item.classList.remove('dp-sf-dragging');
                });

                // Click-to-place (fallback)
                item.addEventListener('click', function () {
                    var wasActive = item.classList.contains('active');

                    // Deactivate all items
                    panel.querySelectorAll('.dp-sf-item.active').forEach(function (el) {
                        el.classList.remove('active');
                    });

                    if (wasActive) {
                        // Toggle off
                        pendingSystemField = null;
                        cancelPlacement();
                    } else {
                        // Activate this item and enter placement mode
                        item.classList.add('active');
                        pendingSystemField = {
                            id: parseInt(sf.id),
                            name: sf.name,
                            sourceType: sf.source_type,
                            sourceContactType: sf.source_contact_type,
                            assignedTo: assignedToMap[groupKey] || 'creator'
                        };
                        startPlacement('placeholder');
                    }
                });

                itemsContainer.appendChild(item);
            });

            group.appendChild(itemsContainer);
            content.appendChild(group);
        });

        // Divider before ad-hoc section
        var divider = document.createElement('div');
        divider.className = 'dp-sf-divider';
        content.appendChild(divider);

        var adhocLabel = document.createElement('div');
        adhocLabel.className = 'dp-sf-section-label';
        adhocLabel.textContent = 'Ad Hoc Fields';
        content.appendChild(adhocLabel);

        // Ad-hoc field type items (same types as toolbar)
        TYPES.forEach(function (t) {
            var item = document.createElement('div');
            item.className = 'dp-sf-item';
            item.dataset.source = 'adhoc';
            item.draggable = true;
            item.innerHTML = '<span class="dp-sf-item-dot" style="background:#64748b"></span>' +
                '<span style="font-weight:600;margin-right:4px">' + t.icon + '</span> ' + t.label;

            // Drag-and-drop
            item.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('application/json', JSON.stringify({
                    _dropType: 'field',
                    type: t.type,
                    assignedTo: 'creator',
                    strikethroughType: t.strikethroughType || null
                }));
                e.dataTransfer.effectAllowed = 'copy';
                item.classList.add('dp-sf-dragging');
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('dp-sf-dragging');
            });

            // Click-to-place (fallback)
            item.addEventListener('click', function () {
                pendingSystemField = null;
                panel.querySelectorAll('.dp-sf-item.active').forEach(function (el) { el.classList.remove('active'); });
                if (placementMode === t.type && (!t.strikethroughType || placementStrikeType === t.strikethroughType)) {
                    cancelPlacement();
                } else {
                    startPlacement(t.type, t.strikethroughType);
                }
            });
            content.appendChild(item);
        });

        // Divider before sign zones
        var divider2 = document.createElement('div');
        divider2.className = 'dp-sf-divider';
        content.appendChild(divider2);

        var zoneLabel = document.createElement('div');
        zoneLabel.className = 'dp-sf-section-label';
        zoneLabel.textContent = 'Sign Zones';
        content.appendChild(zoneLabel);

        var ZONE_ITEMS = [
            { zoneType: 'signature', label: 'Signature Zone', icon: '\u270D' },
            { zoneType: 'initial',   label: 'Initials Zone',  icon: 'Iz' }
        ];
        ZONE_ITEMS.forEach(function (zt) {
            var item = document.createElement('div');
            item.className = 'dp-sf-item';
            item.dataset.source = 'zone';
            item.draggable = true;
            item.innerHTML = '<span class="dp-sf-item-dot" style="background:#f59e0b"></span>' +
                '<span style="font-weight:600;margin-right:4px">' + zt.icon + '</span> ' + zt.label;

            // Drag-and-drop
            item.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('application/json', JSON.stringify({
                    _dropType: 'zone',
                    zoneType: zt.zoneType
                }));
                e.dataTransfer.effectAllowed = 'copy';
                item.classList.add('dp-sf-dragging');
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('dp-sf-dragging');
            });

            // Click-to-place (fallback)
            item.addEventListener('click', function () {
                pendingSystemField = null;
                panel.querySelectorAll('.dp-sf-item.active').forEach(function (el) { el.classList.remove('active'); });
                if (placementMode === 'zone' && placementZoneType === zt.zoneType) {
                    cancelPlacement();
                } else {
                    startZonePlacement(zt.zoneType);
                }
            });
            content.appendChild(item);
        });

        panel.appendChild(content);
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
    // COPY / PASTE / DUPLICATE (template mode)
    // ======================================================================
    function copySelectedField() {
        var field = findField(selectedFieldId);
        if (!field) return;
        copiedField = JSON.parse(JSON.stringify(field));
        showToast('Field copied', 'success');
    }

    function pasteField(pageIndex, xPercent, yPercent) {
        if (!copiedField) return;
        var nf = JSON.parse(JSON.stringify(copiedField));
        nf.id = genId();
        nf.pageIndex = pageIndex;
        nf.position = { x: xPercent, y: yPercent };
        nf.named_field_id = null;
        nf.named_field_name = null;
        fields.push(nf);
        isDirty = true;
        selectedFieldId = nf.id;
        renderFieldsForPage(pageIndex);
        showToast('Field pasted', 'success');
    }

    function duplicateSelectedField() {
        var field = findField(selectedFieldId);
        if (!field) return;
        var nf = JSON.parse(JSON.stringify(field));
        nf.id = genId();
        nf.position = { x: field.position.x + 2, y: field.position.y + 2 };
        nf.named_field_id = null;
        nf.named_field_name = null;
        fields.push(nf);
        isDirty = true;
        selectedFieldId = nf.id;
        renderFieldsForPage(field.pageIndex);
        showToast('Field duplicated', 'success');
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
    // ======================================================================
    // FIELD GROUPS (template mode)
    // ======================================================================

    function fetchFieldGroups() {
        fetch('/docuperfect/field-groups/json', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': C.csrfToken }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            fieldGroupsCache = data || [];
            renderFieldGroupsList();
        })
        .catch(function () {
            fieldGroupsCache = [];
            renderFieldGroupsList();
        });
    }

    function renderFieldGroupsList() {
        var container = document.getElementById('dp-field-groups-list');
        if (!container) return;
        container.innerHTML = '';

        if (!fieldGroupsCache || fieldGroupsCache.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'dp-sf-item';
            empty.style.opacity = '0.5';
            empty.style.fontStyle = 'italic';
            empty.textContent = 'No field groups defined.';
            container.appendChild(empty);
            return;
        }

        // Pillar-based dot colors
        var pillarColors = {
            property: '#10b981',
            contact_lessor: '#3b82f6',
            contact_lessee: '#8b5cf6',
            contact_seller: '#f59e0b',
            contact_buyer: '#ef4444',
            agent: '#06b6d4',
            computed: '#64748b',
            static: '#94a3b8',
            manual: '#94a3b8'
        };

        fieldGroupsCache.forEach(function (fg) {
            var item = document.createElement('div');
            item.className = 'dp-sf-item dp-fg-chip';
            item.dataset.groupId = fg.id;
            item.innerHTML = '<span class="dp-sf-item-dot" style="background:#14b8a6"></span>' +
                escHtml(fg.name) +
                ' <span style="opacity:0.5;font-size:10px">(' + fg.fields.length + ')</span>';
            item.title = 'Click to place: ' + fg.name + ' (' + fg.fields.length + ' fields)';

            // Click-to-place: enter group placement mode
            item.addEventListener('click', function () {
                var wasActive = item.classList.contains('active');

                // Deactivate all items
                document.querySelectorAll('.dp-sf-item.active').forEach(function (el) {
                    el.classList.remove('active');
                });

                if (wasActive) {
                    pendingFieldGroup = null;
                    cancelPlacement();
                } else {
                    item.classList.add('active');
                    pendingFieldGroup = fg;
                    pendingSystemField = null;
                    startPlacement('group');
                }
            });

            container.appendChild(item);
        });
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
