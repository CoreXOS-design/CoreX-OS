<?php

/**
 * Guided-tour definitions — RENTALS: MAINTENANCE AND INSPECTIONS (AT-478 coverage pass, spec §12).
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. Keys are namespaced `rmnt-…`. Every `element` / `until` /
 * `skip_if` selector is a dedicated data-tour="rmnt-…" anchor on the staff screens (the
 * shared collapsible panels on the fault and work-order record pages are reached through
 * their existing #wo-panel-… ids). A removed anchor is caught by
 * tests/Feature/Tours/TourRegistryIntegrityTest.
 *
 * Features covered: Rental Fault Reports & Types, Work Orders, Job Cards, Crews,
 * Parts & Labour Catalogue, Inspections & Inventories.
 *
 *
 * PERMISSION KEYS: every tour carries its page's own gate. Where that key is owned by a DIFFERENT feature in
 * config/corex-features.php (rental_fault_reports.*, rental_work_orders.*, rental_inspections.*,
 * rental_inventories.* belong to Rentals; the crews' rental_catalogue.* to the Catalogue), the tour also names
 * its own `feature`, so the rollout tour report counts it for the feature it is written for.
 *
 * Staff screens only — the public crew, contractor and tenant links are never toured.
 * The guide never types, picks or saves for the agent. Record screens declare `pick_from`
 * so an outside launch goes via the list first. Steps whose anchor is not on screen (a panel
 * that only exists at some stages, a button a role cannot see) are skipped by the engine.
 */

return [

    // ═════════════════════════════════════════════════════════════════════════
    // RENTAL CREWS
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-crews-list' => [
        'key'         => 'rmnt-crews-list',
        'title'       => 'Finding your maintenance crews',
        'description' => 'The list of maintenance crews your job cards hand work to — search, filter by active or archived, then add or edit one.',
        'route'       => 'corex.rental-crews.index',
        'permission'  => 'rental_catalogue.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-crews',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-crews-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Your maintenance crews',
                'body'    => 'A crew is the team that does the repair work on a job card. Crews are people with no CoreX login: you set them up here, then pick one on a job card.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-search"]',
                'section' => 'Finding a crew',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a name, email or number, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of a crew\'s name, email address or phone number and the list narrows to match. Clear the box to see every crew again.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose Active or Archived.'],
                'title'   => 'Active or archived',
                'body'    => 'Active crews can be picked on new job cards. Archived crews are kept for the record, and stay visible on job cards they were already on, but cannot be newly picked.',
            ],
            [
                'element'       => '[data-tour="rmnt-crews-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your search.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list with your search and status choice.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-table"]',
                'section' => 'Reading a crew',
                'title'   => 'One row per crew',
                'body'    => 'Each row shows the crew\'s name, how to reach them and how many members it has. Notes you typed on the crew are shortened to one line. Click Name at the top of the column to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-members-col"]',
                'title'   => 'Members',
                'body'    => 'A crew can be one or more named people, or just a team name with no members at all. Add or remove members on the crew\'s Edit page.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-edit"]',
                'section' => 'Changing a crew',
                'do'      => ['action' => 'click', 'say' => 'Click Edit on a crew.'],
                'title'   => 'Edit a crew',
                'body'    => 'Edit opens the crew to change its details, give it a link for its phone, and manage its members.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-archive"]',
                'title'   => 'Archive a crew',
                'body'    => 'Archive takes a crew out of the pick list without deleting anything. Switch the status filter to Archived to find it again and restore it.',
            ],
            [
                'element' => '[data-tour="rmnt-crews-add"]',
                'section' => 'Adding a crew',
                'do'      => ['action' => 'click', 'say' => 'Click + Add Crew.'],
                'title'   => 'Add a crew',
                'body'    => 'Add Crew opens a short form for a new team. You add its members on the next screen, once it is saved.',
            ],
        ],
    ],

    'rmnt-crews-add' => [
        'key'         => 'rmnt-crews-add',
        'title'       => 'Adding a maintenance crew',
        'description' => 'Create a crew — its name, how to reach it and whether it can be picked on job cards.',
        'route'       => 'corex.rental-crews.create',
        'permission'  => 'rental_catalogue.manage',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-crews',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-crewform"]',
                'section' => 'The crew',
                'title'   => 'One short form',
                'body'    => 'A crew can be a team ("Team 1") or a single person. Only the name is required. Members are added on the next screen.',
            ],
            [
                'element' => '[data-tour="rmnt-crewform-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the crew\'s name.'],
                'title'   => 'Name',
                'body'    => 'Required. This is what you will see when you pick the crew on a job card, for example "Team 1" or a person\'s name.',
            ],
            [
                'element' => '[data-tour="rmnt-crewform-contact"]',
                'section' => 'Reaching them',
                'do'      => ['action' => 'fill', 'say' => 'Type an email address — or press Skip this step.', 'target' => 'input[type="email"]'],
                'title'   => 'Email and contact number',
                'body'    => 'Optional, but needed if you want to send the crew their job link by email or WhatsApp later.',
            ],
            [
                'element' => '[data-tour="rmnt-crewform-notes"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a note for the office — or press Skip this step.'],
                'title'   => 'Notes',
                'body'    => 'Anything useful for the office: the areas they cover, their trade, when they are available.',
            ],
            [
                'element' => '[data-tour="rmnt-crewform-active"]',
                'do'      => ['action' => 'choose', 'say' => 'Leave Active ticked, or untick it.'],
                'title'   => 'Active',
                'body'    => 'Only active crews appear when you assign a job card. Untick it to set a crew up now and switch it on later.',
            ],
            [
                'element' => '[data-tour="rmnt-crewform-save"]',
                'section' => 'Saving',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Add Crew.'],
                'title'   => 'Save the crew',
                'body'    => 'Saving opens the crew\'s page, where you can add members and give the crew a link for its phone.',
            ],
        ],
    ],

    'rmnt-crews-edit' => [
        'key'         => 'rmnt-crews-edit',
        'title'       => 'Editing a crew and its members',
        'description' => 'Change a crew\'s details and add or archive the people in it.',
        'route'       => 'corex.rental-crews.edit',
        'permission'  => 'rental_catalogue.manage',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-crews',
        'pick_from'   => 'corex.rental-crews.index',
        'pick_note'   => 'Open the crew you want to change — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-crewedit-details"]',
                'section' => 'Crew details',
                'title'   => 'The crew\'s details',
                'body'    => 'Change the name, email, contact number or notes here. Nothing changes until you press Save.',
            ],
            [
                'element' => '[data-tour="rmnt-crewedit-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Change the name — or press Skip this step.'],
                'title'   => 'Name',
                'body'    => 'The name shown when this crew is picked on a job card.',
            ],
            [
                'element' => '[data-tour="rmnt-crewedit-active"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick Active — or press Skip this step.'],
                'title'   => 'Active',
                'body'    => 'Untick Active to stop the crew being offered on new job cards. A crew that is switched off also cannot use its crew link.',
            ],
            [
                'element'       => '[data-tour="rmnt-crewedit-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save to keep your changes — or press Skip this step.'],
                'title'         => 'Save',
                'body'          => 'Saves the details above. Members are saved separately, below.',
            ],
            [
                'element' => '[data-tour="rmnt-crewedit-members"]',
                'section' => 'Members',
                'title'   => 'The people in this crew',
                'body'    => 'List who works in this crew, with an optional role and phone number. A crew with no members is still a valid team you can pick.',
            ],
            [
                'element' => '[data-tour="rmnt-crewedit-member-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the new member\'s name.'],
                'title'   => 'Add a member',
                'body'    => 'Type the person\'s name. Role and phone are optional boxes next to it.',
            ],
            [
                'element' => '[data-tour="rmnt-crewedit-member-add"]',
                'do'      => ['action' => 'click', 'say' => 'Click Add member.'],
                'title'   => 'Add member',
                'body'    => 'Adds the person to the list above. Archive a member who leaves; they can be restored from the archived list.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // PARTS & LABOUR CATALOGUE
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-catalogue-list' => [
        'key'         => 'rmnt-catalogue-list',
        'title'       => 'Finding catalogue items',
        'description' => 'Your priced parts and labour — search, filter by type, then add, import or edit items.',
        'route'       => 'corex.rental-catalogue-items.index',
        'permission'  => 'rental_catalogue.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-cat-intro"]',
                'section' => 'Reading the catalogue',
                'title'   => 'Your parts and labour catalogue',
                'body'    => 'These are the labour and parts your maintenance team picks from when pricing a job card. Each item has a code, a description, a type, a unit and a price, so a job card line never has to be typed from scratch.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-search"]',
                'section' => 'Finding an item',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a code or description, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of an item\'s code or description and the list narrows to match.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a type — or press Skip this step.'],
                'title'   => 'Filter by type',
                'body'    => 'Types are your own groupings, for example labour and different kinds of parts. Pick one to see only those items.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose Active or Archived.'],
                'title'   => 'Active or archived',
                'body'    => 'Archived items are kept so old job cards still show their prices, but cannot be picked on new ones.',
            ],
            [
                'element'       => '[data-tour="rmnt-cat-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list with your search, type and status.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-table"]',
                'section' => 'Reading an item',
                'title'   => 'One row per item',
                'body'    => 'Each row shows the code, description, type, unit and the selling price before VAT. If your agency is VAT registered you also see the VAT type and the price including VAT. Click Code or Description to sort. People allowed to see costs also see the cost column.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-edit"]',
                'section' => 'Changing items',
                'do'      => ['action' => 'click', 'say' => 'Click Edit on an item.'],
                'title'   => 'Edit an item',
                'body'    => 'Edit opens the same form used to add an item, filled in, so you can change a price or description.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-archive"]',
                'title'   => 'Archive an item',
                'body'    => 'Archive removes an item from the pick list without deleting it. Choose Archived in the status filter to restore it later.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-types"]',
                'title'   => 'Types and units',
                'body'    => 'The types and units you can choose from are your own lists, kept in Company Settings. Use this button to add or change them.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-import"]',
                'title'   => 'Load a whole price list',
                'body'    => 'Import brings in many items at once from a spreadsheet. You always get a preview before anything is saved.',
            ],
            [
                'element' => '[data-tour="rmnt-cat-add"]',
                'do'      => ['action' => 'click', 'say' => 'Click + Add Item.'],
                'title'   => 'Add one item',
                'body'    => 'Add Item opens the form for a single labour or part.',
            ],
        ],
    ],

    'rmnt-catalogue-item' => [
        'key'         => 'rmnt-catalogue-item',
        'title'       => 'Adding a catalogue item',
        'description' => 'Create or change a labour or part — its type, code, unit, description and pricing.',
        'route'       => 'corex.rental-catalogue-items.create',
        'also_on'     => ['corex.rental-catalogue-items.edit'],
        'permission'  => 'rental_catalogue.manage',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-item-card"]',
                'section' => 'The item',
                'title'   => 'What is this item?',
                'body'    => 'Choose the type and unit, give it a short code and a description. When someone picks it on a job card, all of this fills in for them.',
            ],
            [
                'element' => '[data-tour="rmnt-item-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the item type.'],
                'title'   => 'Type',
                'body'    => 'Required. For example labour or a kind of part. Missing the type you need? Add it under Manage types and units.',
            ],
            [
                'element' => '[data-tour="rmnt-item-code"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a short, unique code.'],
                'title'   => 'Code',
                'body'    => 'Required. A short code your team will recognise. It must be unique among your active items.',
            ],
            [
                'element' => '[data-tour="rmnt-item-unit"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the unit.'],
                'title'   => 'Unit',
                'body'    => 'Required. What the quantity on a job card counts: hours, metres, each, and so on.',
            ],
            [
                'element' => '[data-tour="rmnt-item-active"]',
                'title'   => 'Active',
                'body'    => 'Active items can be picked on job cards. Untick it to keep the item on file without offering it.',
            ],
            [
                'element' => '[data-tour="rmnt-item-desc"]',
                'do'      => ['action' => 'fill', 'say' => 'Type what the item is.'],
                'title'   => 'Description',
                'body'    => 'Required. The wording that appears on the job card line, so write it the way you want the owner or tenant to read it.',
            ],
            [
                'element' => '[data-tour="rmnt-item-pricing"]',
                'section' => 'Pricing',
                'title'   => 'Cost, markup and selling price',
                'body'    => 'Each row shows the amount without VAT, the VAT, and the amount with VAT. Change the cost or the markup and the selling price follows; type the selling price and the markup follows.',
            ],
            [
                'element' => '[data-tour="rmnt-item-cost"]',
                'do'      => ['action' => 'fill', 'say' => 'Type what this item costs you — or press Skip this step.'],
                'title'   => 'Cost',
                'body'    => 'What the item costs your agency, in rand. Only people allowed to see costs get this row. For labour, the cost is the rate for one hour of one person.',
            ],
            [
                'element' => '[data-tour="rmnt-item-markup"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a markup — or press Skip this step.'],
                'title'   => 'Markup',
                'body'    => 'The extra you add on top of cost, either as a percentage or in rand. Switch between the two with the R and % buttons; the figure converts so the selling price does not jump.',
            ],
            [
                'element' => '[data-tour="rmnt-item-selling"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the selling price before VAT.'],
                'title'   => 'Selling price',
                'body'    => 'What a job card charges for one unit. This is the price that lands on quotes and statements.',
            ],
            [
                'element'       => '[data-tour="rmnt-item-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the save button at the bottom.'],
                'title'         => 'Save the item',
                'body'          => 'Saving returns you to the catalogue, where the item is ready to pick on job cards.',
            ],
        ],
    ],

    'rmnt-catalogue-import' => [
        'key'         => 'rmnt-catalogue-import',
        'title'       => 'Importing a price list',
        'description' => 'Load many catalogue items from a spreadsheet — download the template, upload your file and check the preview.',
        'route'       => 'corex.rental-catalogue-items.import.index',
        'permission'  => 'rental_catalogue.manage',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-import-template"]',
                'section' => 'Get the template',
                'title'   => 'Start with the template',
                'body'    => 'Download the template, fill in one row per item, and save it as CSV or XLSX. Using the template means the columns are exactly what CoreX expects.',
            ],
            [
                'element' => '[data-tour="rmnt-import-file"]',
                'section' => 'Upload your file',
                'do'      => ['action' => 'choose', 'say' => 'Choose your filled-in file.', 'target' => 'input[type="file"]'],
                'title'   => 'Your file',
                'body'    => 'CSV or XLSX, up to 50MB. Nothing is saved when you upload; you see a preview first.',
            ],
            [
                'element' => '[data-tour="rmnt-import-dupes"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose what happens to codes that already exist.'],
                'title'   => 'If a code already exists',
                'body'    => 'Update replaces the existing item with the values in your file. Skip leaves the existing item exactly as it is. Pick the one that matches your price list.',
            ],
            [
                'element'       => '[data-tour="rmnt-import-preview"]',
                'section'       => 'Check before saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Preview import.'],
                'title'         => 'Preview',
                'body'          => 'The next screen lists every row and what will happen to it. You confirm there; until then nothing in your catalogue changes.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // RENTAL FAULT REPORTS & TYPES
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-faults-list' => [
        'key'         => 'rmnt-faults-list',
        'title'       => 'Finding fault reports',
        'description' => 'Every fault reported on a rental property and where each one stands — search, filter, then open one or report a new fault.',
        'route'       => 'corex.rental-fault-reports.index',
        'permission'  => 'rental_fault_reports.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-faults',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-fr-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Rental fault reports',
                'body'    => 'A fault report is where every maintenance problem starts: a tenant, the owner or an agent says something is broken. From here it is reviewed, the owner approves the cost if needed, and a work order is raised.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-search"]',
                'section' => 'Finding a fault',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address, title or description, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of the property address, or a word from the title or description, and the list narrows to match.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status.'],
                'title'   => 'Filter by status',
                'body'    => 'Where the fault is in its life: reported, awaiting the owner\'s approval, approved, work order raised, resolved and so on. The tiles above the filters do the same with one click.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-property"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address — or press Skip this step.'],
                'title'   => 'Filter by property',
                'body'    => 'Start typing an address and pick the property from the suggestions. Only properties that have a fault report appear.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Reported from date — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Filter by date reported',
                'body'    => 'Choose a from and to date to see only faults reported in that window.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-open"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick Open only — or press Skip this step.'],
                'title'   => 'Open only',
                'body'    => 'Hides faults that are already resolved or cancelled, so you only see the ones that still need attention.',
            ],
            [
                'element'       => '[data-tour="rmnt-fr-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list. Clear appears beside it once a filter is on.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-table"]',
                'section' => 'Opening a fault',
                'title'   => 'One row per fault',
                'body'    => 'Each row shows the property, the title, the status, the outcome once there is one, and the date reported. An open emergency carries a red Emergency tag. Click a column heading to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-view"]',
                'do'      => ['action' => 'click', 'say' => 'Click View on a fault.'],
                'title'   => 'Open a fault',
                'body'    => 'View opens the fault\'s page, with the next step, the photos and the full history.',
            ],
            [
                'element' => '[data-tour="rmnt-fr-new"]',
                'section' => 'Reporting a fault',
                'do'      => ['action' => 'click', 'say' => 'Click Report a Fault.'],
                'title'   => 'Report a new fault',
                'body'    => 'Use this when someone tells you something is broken. You pick the tenancy and capture the report on the reporter\'s behalf.',
            ],
        ],
    ],

    'rmnt-faults-report' => [
        'key'         => 'rmnt-faults-report',
        'title'       => 'Reporting a fault',
        'description' => 'Log a fault at a rental property — pick the tenancy, say who reported it and how, and describe the problem.',
        'route'       => 'corex.rental-fault-reports.create',
        'permission'  => 'rental_fault_reports.create',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-faults',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-frnew-picker"]',
                'section' => 'Which tenancy?',
                'title'   => 'Start with the tenancy',
                'body'    => 'A fault belongs to a property and, normally, the tenancy living there. Find the tenancy first; the rest of the form then fills in what it already knows.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a tenant\'s name or an address, then press Enter.', 'target' => 'input[type="search"]'],
                'title'   => 'Search tenancies',
                'body'    => 'Type part of a tenant\'s name or the property address. Only current tenancies are listed.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-choices"]',
                'do'      => ['action' => 'click', 'say' => 'Click the right tenancy.', 'target' => 'a'],
                'title'   => 'Choose the tenancy',
                'body'    => 'Click the tenancy the fault is at. The same form then opens with the property and tenancy filled in. For a vacant property, report the fault from the property\'s own page.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-form"]',
                'section' => 'The fault',
                'title'   => 'Capturing the report',
                'body'    => 'You are logging the fault on the reporter\'s behalf, so the form records who told you and how, as well as what is wrong.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-property"]',
                'title'   => 'Property',
                'body'    => 'The property is already set from the tenancy you chose. It cannot be changed here.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-by"]',
                'section' => 'Who reported it',
                'do'      => ['action' => 'choose', 'say' => 'Say who reported the fault.'],
                'title'   => 'Reported by',
                'body'    => 'The tenant, the owner (who instructed you), or you yourself if you noticed it. When it was the tenant or owner, the contact is filled in for you and you can search for someone else if it was not them.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-channel"]',
                'do'      => ['action' => 'choose', 'say' => 'Say how you were told.'],
                'title'   => 'How it was reported',
                'body'    => 'Phone call, WhatsApp, email, in person or other. This is kept as evidence of when and how the fault came in.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-type"]',
                'section' => 'What is wrong',
                'do'      => ['action' => 'choose', 'say' => 'Pick the fault type — or press Skip this step.', 'target' => 'select'],
                'title'   => 'Fault type',
                'body'    => 'Optional. Picking a type shows the first-aid steps for it, for example how to close the main water valve, before you carry on. If nothing fits, choose Other and explain in the description.',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-title"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a short title.'],
                'title'   => 'Title',
                'body'    => 'Required. A few words that say what and where, for example "Geyser burst, upstairs bathroom".',
            ],
            [
                'element' => '[data-tour="rmnt-frnew-desc"]',
                'do'      => ['action' => 'fill', 'say' => 'Describe the problem.'],
                'title'   => 'Description',
                'body'    => 'Required. What is wrong, when it started and anything the reporter said. The owner may read this, so keep it factual.',
            ],
            [
                'element'       => '[data-tour="rmnt-frnew-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Report Fault.'],
                'title'         => 'Save the report',
                'body'          => 'Saving opens the fault\'s page, which shows the next step: reviewing it and, if needed, asking the owner to approve the work.',
            ],
        ],
    ],

    'rmnt-faults-detail' => [
        'key'         => 'rmnt-faults-detail',
        'title'       => 'Working a fault report',
        'description' => 'One fault from report to outcome — where it stands, the one next step, the photos and the history.',
        'route'       => 'corex.rental-fault-reports.show',
        'permission'  => 'rental_fault_reports.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-faults',
        'pick_from'   => 'corex.rental-fault-reports.index',
        'pick_note'   => 'Open the fault you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-frd-title"]',
                'section' => 'The fault',
                'title'   => 'What and where',
                'body'    => 'The fault\'s title, its current status, its type and the property it is at. Click the address to open the property.',
            ],
            [
                'element' => '[data-tour="rmnt-frd-actions"]',
                'title'   => 'Page buttons',
                'body'    => 'Download PDF gives you a printable copy. While the owner has not decided you can cancel the report, and a fault that has not been worked yet can be archived. All fault reports takes you back to the list.',
            ],
            [
                'element' => '[data-tour="rmnt-frd-strip"]',
                'section' => 'Where it stands',
                'title'   => 'Where this fault is',
                'body'    => 'The steps a fault goes through, with a tick on those done and a dot on the one you are at. Use it to see at a glance how far along the fault is.',
            ],
            [
                'element' => '[data-tour="rmnt-frd-next"]',
                'title'   => 'The next step',
                'body'    => 'The left side says what came back last; the right side says what to do next and gives you the button for it. After you save anything, the page returns here so the next step is always in front of you.',
            ],
            [
                'element' => '#wo-panel-ownercopy',
                'section' => 'Telling the owner',
                'title'   => 'What the owner will see',
                'body'    => 'The tenant\'s words stay exactly as reported. This is your reviewed copy for the owner, which you can reword before you send it. It is only there while the fault is open.',
            ],
            [
                'element' => '#wo-panel-outcome',
                'section' => 'Closing it',
                'title'   => 'Outcome',
                'body'    => 'How the fault ended: repaired, repaired in part, not repaired, owner declined or tenant liable. When a work order is completed this is filled in for you, and you only change it if that is wrong.',
            ],
            [
                'element' => '#wo-panel-photos',
                'section' => 'Photos and history',
                'do'      => ['action' => 'click', 'say' => 'Click Photos to open it.', 'target' => 'summary'],
                'title'   => 'Photos',
                'body'    => 'Pictures of the fault. Open one to look closer and mark it up, or upload another photo from your device.',
            ],
            [
                'element' => '#wo-panel-history',
                'title'   => 'History',
                'body'    => 'A dated record of who did what to this fault, oldest first: when it was reported, every change and every approval decision. This is the evidence if anyone asks later.',
            ],
        ],
    ],

    'rmnt-faulttypes-list' => [
        'key'         => 'rmnt-faulttypes-list',
        'title'       => 'Managing fault types',
        'description' => 'The kinds of fault your agency offers, how urgent each is and the first-aid steps shown to the reporter.',
        'route'       => 'corex.rental-fault-types.index',
        'permission'  => 'rental_fault_types.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-ft-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Your fault types',
                'body'    => 'A fault type is a kind of problem, such as a leaking tap or a dead socket, with how urgent it is and what the tenant should do first. People reporting a fault pick from this list.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-search"]',
                'section' => 'Finding a type',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a name or category, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of a fault type\'s name or category.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-category"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a category — or press Skip this step.'],
                'title'   => 'Filter by category',
                'body'    => 'Categories group types by trade, for example plumbing or electrical.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-urgency"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick an urgency — or press Skip this step.'],
                'title'   => 'Filter by urgency',
                'body'    => 'Routine can wait for a normal booking, Urgent should be sorted soon, and Emergency needs action straight away.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose Active or Archived.'],
                'title'   => 'Active or archived',
                'body'    => 'Archived types stay on old reports but are no longer offered for new ones.',
            ],
            [
                'element'       => '[data-tour="rmnt-ft-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list with your search, category, urgency and status.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-table"]',
                'section' => 'Reading a type',
                'title'   => 'One row per type',
                'body'    => 'Each row shows the name, category, urgency, whether it is a CoreX starting type or one you made, and how many documents are attached. Click a heading to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-edit"]',
                'do'      => ['action' => 'click', 'say' => 'Click Edit on a type.'],
                'title'   => 'Edit a type',
                'body'    => 'Edit lets you change the urgency and the first-aid steps, and attach documents.',
            ],
            [
                'element' => '[data-tour="rmnt-ft-add"]',
                'section' => 'Adding a type',
                'do'      => ['action' => 'click', 'say' => 'Click + Add Fault Type.'],
                'title'   => 'Add a fault type',
                'body'    => 'Use this for a problem your agency sees often that is not on the list.',
            ],
        ],
    ],

    'rmnt-faulttypes-add' => [
        'key'         => 'rmnt-faulttypes-add',
        'title'       => 'Adding a fault type',
        'description' => 'Create a fault type with its urgency and the first-aid steps the reporter sees before logging it.',
        'route'       => 'corex.rental-fault-types.create',
        'permission'  => 'rental_fault_types.create',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-ftnew-intro"]',
                'section' => 'The type',
                'title'   => 'A new kind of fault',
                'body'    => 'Give the fault a name and urgency, and tell the reporter what to do first. Everything below is optional except the name and urgency.',
            ],
            [
                'element' => '[data-tour="rmnt-ftnew-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the fault type\'s name.'],
                'title'   => 'Name',
                'body'    => 'Required. How it will read in the list, for example "Blocked drain".',
            ],
            [
                'element' => '[data-tour="rmnt-ftnew-category"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a category — or press Skip this step.'],
                'title'   => 'Category',
                'body'    => 'Optional. A trade or group, such as Plumbing. Types with the same category sit together in lists and filters.',
            ],
            [
                'element' => '[data-tour="rmnt-ftnew-urgency"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the urgency.'],
                'title'   => 'Urgency',
                'body'    => 'Required. Routine, Urgent or Emergency. An open emergency fault is marked with a red Emergency tag on the fault list.',
            ],
            [
                'element' => '[data-tour="rmnt-ftnew-firstaid"]',
                'section' => 'Helping the reporter',
                'do'      => ['action' => 'fill', 'say' => 'Type what the reporter should do first — or press Skip this step.'],
                'title'   => 'First-aid steps',
                'body'    => 'Shown to the tenant before they can log this fault, for example "Switch off the water at the main valve". Writing {{main_water_valve_location}} or {{db_board_location}} fills in the place recorded on the property.',
            ],
            [
                'element' => '[data-tour="rmnt-ftnew-active"]',
                'title'   => 'Active',
                'body'    => 'Active types are shown to tenants. Untick it to keep a type ready but hidden.',
            ],
            [
                'element' => '[data-tour="rmnt-ftnew-docs"]',
                'title'   => 'Attach a document',
                'body'    => 'Optional. An image, a PDF or a link to a video that shows the reporter how to do the first-aid steps.',
            ],
            [
                'element'       => '[data-tour="rmnt-ftnew-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save.'],
                'title'         => 'Save the type',
                'body'          => 'Saving adds it to the list straight away.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // RENTAL WORK ORDERS
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-wo-list' => [
        'key'         => 'rmnt-wo-list',
        'title'       => 'Finding work orders',
        'description' => 'Repairs and maintenance jobs on your rentals — who is doing the work, who pays and where each job stands.',
        'route'       => 'corex.rental-work-orders.index',
        'permission'  => 'rental_work_orders.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-work-orders',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-wo-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Rental work orders',
                'body'    => 'A work order is one repair job: what needs doing, who does it, what it costs and who pays. Faults that need fixing, and jobs you start yourself, end up here.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-search"]',
                'section' => 'Finding a job',
                'do'      => ['action' => 'fill', 'say' => 'Type a property, tenant, supplier or title, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of the property address, a tenant\'s name, a supplier\'s name or a word from the title.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status.'],
                'title'   => 'Filter by status',
                'body'    => 'Reported, ordered, in progress, disputed, completed or cancelled. The tiles above the filters count each one and can be clicked to apply them.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-property"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address — or press Skip this step.'],
                'title'   => 'Filter by property',
                'body'    => 'Start typing an address and pick the property from the suggestions. Only properties that have a work order are offered.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-trade"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a trade — or press Skip this step.'],
                'title'   => 'Filter by trade',
                'body'    => 'See only plumbing, electrical or whichever kind of work you choose.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-paidby"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick who paid — or press Skip this step.'],
                'title'   => 'Filter by who pays',
                'body'    => 'The owner, the tenant, a deposit deduction, or not yet paid. Handy when you are checking what still has to be recovered.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Reported from date — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Filter by date reported',
                'body'    => 'Choose a from and to date to see only jobs reported in that window.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-open"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick Open only — or press Skip this step.'],
                'title'   => 'Open only',
                'body'    => 'Hides completed and cancelled jobs. The other tick boxes after it narrow to overdue jobs, jobs waiting for the owner, and so on.',
            ],
            [
                'element'       => '[data-tour="rmnt-wo-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list. Clear appears beside it once a filter is on.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-table"]',
                'section' => 'Opening a job',
                'title'   => 'One row per job',
                'body'    => 'Each row shows the property, title, status, priority, supplier, who pays and the date reported. Click a column heading to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-view"]',
                'do'      => ['action' => 'click', 'say' => 'Click View on a work order.'],
                'title'   => 'Open a work order',
                'body'    => 'View opens the job, with its next step, contractor, quotes, cost approval and history.',
            ],
            [
                'element' => '[data-tour="rmnt-wo-new"]',
                'section' => 'Starting a job',
                'do'      => ['action' => 'click', 'say' => 'Click New Work Order.'],
                'title'   => 'Log a new work order',
                'body'    => 'Use this when you already know a repair is needed and do not need a fault report first.',
            ],
        ],
    ],

    'rmnt-wo-new' => [
        'key'         => 'rmnt-wo-new',
        'title'       => 'Logging a work order',
        'description' => 'Start a repair job on a rental property and say who will do the work.',
        'route'       => 'corex.rental-work-orders.create',
        'permission'  => 'rental_work_orders.create',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-work-orders',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-wonew-form"]',
                'section' => 'The job',
                'title'   => 'A new repair job',
                'body'    => 'Say where the job is, who reported it and what needs doing. Quotes, the owner\'s approval and the appointment come after it is saved.',
            ],
            [
                'element' => '[data-tour="rmnt-wonew-who"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose who does the work.'],
                'title'   => 'Who does the work?',
                'body'    => 'An outside supplier, or your own maintenance team. This is the first choice because it decides how the rest of the job runs.',
            ],
            [
                'element' => '[data-tour="rmnt-wonew-property"]',
                'section' => 'Where',
                'do'      => ['action' => 'fill', 'say' => 'Type part of the address and pick the property.', 'target' => 'input[type="text"]'],
                'title'   => 'Property',
                'body'    => 'Required. Type at least two letters of the address and pick the property from the list. If you came from a property or lease, it is already filled in.',
            ],
            [
                'element' => '[data-tour="rmnt-wonew-by"]',
                'section' => 'Who reported it',
                'do'      => ['action' => 'choose', 'say' => 'Say who reported the job.'],
                'title'   => 'Reported by',
                'body'    => 'Owner instructed, agent noticed it directly, or the tenant. A tenant\'s fault is normally logged as a fault report first, so only skip that for something trivial.',
            ],
            [
                'element' => '[data-tour="rmnt-wonew-title"]',
                'section' => 'What',
                'do'      => ['action' => 'fill', 'say' => 'Type a short title.'],
                'title'   => 'Title',
                'body'    => 'Required. A few words that say what and where, for example "Geyser burst, upstairs bathroom".',
            ],
            [
                'element' => '[data-tour="rmnt-wonew-desc"]',
                'do'      => ['action' => 'fill', 'say' => 'Describe the work.'],
                'title'   => 'Description',
                'body'    => 'Required. What is wrong and what needs doing. A contractor reads this to understand the job, so be specific.',
            ],
            [
                'element'       => '[data-tour="rmnt-wonew-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Log Work Order.'],
                'title'         => 'Save the work order',
                'body'          => 'Saving opens the job, which shows the next step: appointing a contractor or getting a quote.',
            ],
        ],
    ],

    'rmnt-wo-detail' => [
        'key'         => 'rmnt-wo-detail',
        'title'       => 'Working a work order',
        'description' => 'One repair job from start to finish — the next step, the contractor, the quotes, the cost approval and completion.',
        'route'       => 'corex.rental-work-orders.show',
        'permission'  => 'rental_work_orders.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-work-orders',
        'pick_from'   => 'corex.rental-work-orders.index',
        'pick_note'   => 'Open the work order you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-wod-title"]',
                'section' => 'The job',
                'title'   => 'What and where',
                'body'    => 'The job\'s title, its stage and the property it is at.',
            ],
            [
                'element' => '[data-tour="rmnt-wod-strip"]',
                'section' => 'Where it stands',
                'title'   => 'Where this job is',
                'body'    => 'The stages a job goes through, with a tick on those done and a dot on the one you are at.',
            ],
            [
                'element' => '[data-tour="rmnt-wod-next"]',
                'do'      => ['action' => 'click', 'say' => 'Click the Next button when you are ready to do that step — or press Skip this step.', 'target' => '[data-next-button]'],
                'title'   => 'The next step',
                'body'    => 'The one thing to do next, with its button. When something is waiting on the owner, a supplier or the tenant, this line says so. Work from here and you cannot miss a step.',
            ],
            [
                'element' => '[data-tour="rmnt-wod-job"]',
                'section' => 'The facts',
                'title'   => 'The job',
                'body'    => 'The facts of the job in one place: what is wrong, the tenancy, who reported it and the key dates.',
            ],
            [
                'element' => '[data-tour="rmnt-wod-money"]',
                'title'   => 'The money',
                'body'    => 'The quote, the approved amount and who pays, in one card. Check it before you tell the owner or the contractor to go ahead.',
            ],
            [
                'element' => '#wo-panel-contractor',
                'section' => 'Getting it done',
                'do'      => ['action' => 'click', 'say' => 'Click Contractor to open the panel — or press Skip this step.', 'target' => 'summary'],
                'title'   => 'Contractor',
                'body'    => 'Choose the contractor or ask several for quotes. Open the panel to see who has been told and what they have answered.',
            ],
            [
                'element' => '#wo-panel-quotes',
                'title'   => 'Quotes',
                'body'    => 'Quotes that came back. Compare them, pick the one to put to the owner, and record the owner\'s answer.',
            ],
            [
                'element' => '#wo-panel-cost',
                'title'   => 'Approve the cost',
                'body'    => 'Where the owner\'s approval of the cost is recorded, or an emergency approval when the work cannot wait.',
            ],
            [
                'element' => '#wo-panel-appointment',
                'title'   => 'Appointment',
                'body'    => 'Book the day and time the contractor will be at the property, and who lets them in.',
            ],
            [
                'element' => '#wo-panel-completion',
                'section' => 'Finishing',
                'title'   => 'Completion',
                'body'    => 'When the contractor says the work is done, you check it here, often after the tenant has confirmed it, and close the job.',
            ],
            [
                'element' => '#wo-panel-whopays',
                'title'   => 'Who pays',
                'body'    => 'Rule on whether the owner or the tenant is responsible for the cost, with the evidence behind your decision.',
            ],
            [
                'element' => '#wo-panel-history',
                'section' => 'History',
                'do'      => ['action' => 'click', 'say' => 'Click History to open it.', 'target' => 'summary'],
                'title'   => 'History',
                'body'    => 'A dated record of who did what on this job.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // RENTAL JOB CARDS
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-jc-list' => [
        'key'         => 'rmnt-jc-list',
        'title'       => 'Finding job cards',
        'description' => 'Every maintenance job card with its quote and status — search, filter, then open one.',
        'route'       => 'corex.rental-job-cards.index',
        'permission'  => 'rental_job_cards.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-jc-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Job cards',
                'body'    => 'A job card is the working document for the maintenance job: the tasks, the parts and labour with their prices, the crew, and the sign-off. It is what the crew works from.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-tiles"]',
                'title'   => 'Status tiles',
                'body'    => 'Each tile counts the job cards in one state, from draft to completed, plus the ones that need pricing or are waiting for the owner. Click a tile to see only those cards.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-search"]',
                'section' => 'Finding a job card',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a title, property or tenant, then press Enter.', 'target' => 'input[type="text"]'],
                'title'   => 'Search',
                'body'    => 'Type part of the title, the property address or the tenant\'s name.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-crew"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a crew — or press Skip this step.'],
                'title'   => 'Filter by crew',
                'body'    => 'See only the jobs given to one crew.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-property"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address — or press Skip this step.', 'target' => 'input[type="text"]'],
                'title'   => 'Filter by property',
                'body'    => 'Start typing an address and pick the property from the suggestions.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Due from date — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Filter by due date',
                'body'    => 'Choose a from and to date to see the jobs due in that window. A job past its due date shows as overdue.',
            ],
            [
                'element'       => '[data-tour="rmnt-jc-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list. Tick Show archived first if you want to see archived cards.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-table"]',
                'section' => 'Opening a job card',
                'title'   => 'One row per job card',
                'body'    => 'Each row shows the card number, property, title, tenant, crew, status, due date and the total including VAT. A "Changed since sent" tag means the quote was edited after it went to the owner. Click a column heading to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-open"]',
                'do'      => ['action' => 'click', 'say' => 'Click Open on a job card.'],
                'title'   => 'Open a job card',
                'body'    => 'Open shows the whole card: the tasks and lines, the money, the crew and the sign-off.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-print"]',
                'title'   => 'Print the list',
                'body'    => 'Prints the job cards you are looking at, with your filters applied.',
            ],
            [
                'element' => '[data-tour="rmnt-jc-new"]',
                'section' => 'Starting a job card',
                'title'   => 'New job card',
                'body'    => 'A job card is started from the repair job it belongs to, so this takes you to the right place to begin: the fault or work order it is for.',
            ],
        ],
    ],

    'rmnt-jc-detail' => [
        'key'         => 'rmnt-jc-detail',
        'title'       => 'Working a job card',
        'description' => 'One job card — the job, its tasks and priced lines, the money and approval, the crew and the sign-off.',
        'route'       => 'corex.rental-job-cards.show',
        'permission'  => 'rental_job_cards.view',
        'pick_from'   => 'corex.rental-job-cards.index',
        'pick_note'   => 'Open the job card you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-jcd-title"]',
                'section' => 'The job card',
                'title'   => 'What and where',
                'body'    => 'The job card\'s title, its stage and the property it is at, with a line of the description.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-actions"]',
                'title'   => 'Page buttons',
                'body'    => 'Print job card produces the paper copy for the crew. If the work order exists you can jump to it, and All job cards takes you back to the list.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-strip"]',
                'section' => 'Where it stands',
                'title'   => 'Where this job is',
                'body'    => 'The stages the job goes through, from draft to completed, with a tick on those done.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-next"]',
                'title'   => 'Returned and Next',
                'body'    => 'Left: what came back last, such as the owner\'s answer or the crew\'s report. Right: the one thing to do next, with its button. After you save anything the page returns here.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-job"]',
                'section' => 'The job',
                'title'   => 'The job',
                'body'    => 'The tenant, landlord, access contact, crew, scheduled time and due date in one grid. On a draft card you can edit the title and access details here.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-lines"]',
                'section' => 'The work and prices',
                'title'   => 'Tasks and lines',
                'body'    => 'The work itself: tasks for the crew to tick off, and under them the parts and labour with quantity, cost, selling price and total. Add a line from the catalogue, or let the crew suggest lines you then accept or reject.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-money"]',
                'title'   => 'Money and approval',
                'body'    => 'The price, what the owner has authorised and by whom, and whether the price is inside the owner\'s limit. The button here is the same one as the Next step when approval is what is needed.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-crew"]',
                'section' => 'The crew',
                'title'   => 'Crew',
                'body'    => 'Who the job is given to, whether they have been told, and the tools to assign or change the crew, set the date and mark the job in progress.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-signoff"]',
                'section' => 'Finishing',
                'title'   => 'Sign-off and completion',
                'body'    => 'When the work is done, the crew signs off, you check it, and the tenant confirms. This box only appears once there is something to record.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-photos"]',
                'section' => 'Photos and history',
                'do'      => ['action' => 'click', 'say' => 'Click Photos to open it.', 'target' => 'button'],
                'title'   => 'Photos',
                'body'    => 'Pictures of the fault and of the work. Click the heading to show or hide them.',
            ],
            [
                'element' => '[data-tour="rmnt-jcd-history"]',
                'title'   => 'History',
                'body'    => 'A dated record of who did what on the card. Click to open it.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // RENTAL INSPECTIONS & INVENTORIES
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-insp-list' => [
        'key'         => 'rmnt-insp-list',
        'title'       => 'Finding inspections',
        'description' => 'Every move-in, move-out, interim and routine inspection — when it is scheduled, who attends and what is still open.',
        'route'       => 'corex.rental-inspections.index',
        'permission'  => 'rental_inspections.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-insp-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Rental inspections',
                'body'    => 'An inspection is a recorded look at a rental: room by room, with photos and signatures. They protect you, the owner and the tenant when the deposit is settled.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-tabs"]',
                'title'   => 'All inspections and Due',
                'body'    => 'All inspections is the full record. Due lists the move-in and move-out inspections that are coming up from your leases, plus any interim dates you have loaded.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-search"]',
                'section' => 'Finding an inspection',
                'do'      => ['action' => 'fill', 'say' => 'Type a property, tenant or agent name, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of the property address, a tenant\'s name or the agent\'s name.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status.'],
                'title'   => 'Filter by status',
                'body'    => 'Draft, in progress, awaiting signature, completed or cancelled. The tiles above the filters count each one.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a type — or press Skip this step.'],
                'title'   => 'Filter by type',
                'body'    => 'In-inspection at move-in, Out-inspection at move-out, Interim during the tenancy, or Routine.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Scheduled from date — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Filter by scheduled date',
                'body'    => 'Choose a from and to date to see the inspections booked in that window.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-inspector"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick an inspector — or press Skip this step.'],
                'title'   => 'Filter by inspector',
                'body'    => 'See only the inspections one person is doing.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-discrepancy"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick the box — or press Skip this step.'],
                'title'   => 'Unresolved discrepancies',
                'body'    => 'A discrepancy is a difference between what was recorded at move-in and move-out. Tick this to see the inspections with differences nobody has resolved yet.',
            ],
            [
                'element'       => '[data-tour="rmnt-insp-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list. Clear appears beside it once a filter is on.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-table"]',
                'section' => 'Opening an inspection',
                'title'   => 'One row per inspection',
                'body'    => 'Each row shows the property, tenants, type, status, scheduled date, inspector and whether everyone attended. Click a column heading to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-view"]',
                'do'      => ['action' => 'click', 'say' => 'Click View on an inspection.'],
                'title'   => 'Open an inspection',
                'body'    => 'View opens the record: history, attendance, signatures and the report. Recording the rooms and photos happens on the property\'s own Inspections tab.',
            ],
            [
                'element' => '[data-tour="rmnt-insp-new"]',
                'section' => 'Starting one',
                'do'      => ['action' => 'click', 'say' => 'Click Start Inspection.'],
                'title'   => 'Start or schedule an inspection',
                'body'    => 'Start now to begin recording, or schedule it for later with a date and an inspector.',
            ],
        ],
    ],

    'rmnt-insp-new' => [
        'key'         => 'rmnt-insp-new',
        'title'       => 'Starting or scheduling an inspection',
        'description' => 'Pick the rental and the type of inspection, then start recording now or book it for later.',
        'route'       => 'corex.rental-inspections.create',
        'permission'  => 'rental_inspections.create',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-inspnew-intro"]',
                'section' => 'The inspection',
                'title'   => 'Start now or book for later',
                'body'    => 'Choose the property and the kind of inspection. Then either begin recording straight away, or book it for a day and time so everyone is told.',
            ],
            [
                'element' => '[data-tour="rmnt-inspnew-property"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the property.'],
                'title'   => 'Property',
                'body'    => 'Required. Only rentals with an active lease are listed, because an inspection is attached to a tenancy.',
            ],
            [
                'element' => '[data-tour="rmnt-inspnew-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the type of inspection.'],
                'title'   => 'Type',
                'body'    => 'Required. In (move-in), Out (move-out), Interim or Routine. The meaning of each is written beside it.',
            ],
            [
                'element' => '[data-tour="rmnt-inspnew-schedule"]',
                'section' => 'Booking for later',
                'title'   => 'Schedule for later (optional)',
                'body'    => 'Skip this block to start recording right now. Fill it in to book the inspection; the tenant, the landlord and the inspector are told, and nothing is recorded until someone opens it.',
            ],
            [
                'element' => '[data-tour="rmnt-inspnew-date"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a date — or press Skip this step to start now.', 'target' => 'input[type="date"]'],
                'title'   => 'Date',
                'body'    => 'The day of the inspection. Booking inside your agency\'s usual notice period is allowed, but it is flagged.',
            ],
            [
                'element' => '[data-tour="rmnt-inspnew-inspector"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the inspector — or press Skip this step.', 'target' => 'select'],
                'title'   => 'Inspector',
                'body'    => 'The person who will do the inspection. They are told about the booking.',
            ],
            [
                'element'       => '[data-tour="rmnt-inspnew-start"]',
                'section'       => 'Go',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Start Now to begin recording — or press Skip this step to book it instead.'],
                'title'         => 'Start Now',
                'body'          => 'Begins the inspection immediately and takes you into recording it.',
            ],
            [
                'element'       => '[data-tour="rmnt-inspnew-schedule-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Schedule to book it.'],
                'title'         => 'Schedule',
                'body'          => 'Books the inspection for the date and time you entered and returns you to the list.',
            ],
        ],
    ],

    'rmnt-insp-detail' => [
        'key'         => 'rmnt-insp-detail',
        'title'       => 'Reading an inspection record',
        'description' => 'One inspection — its reports and printouts, signing links, history, attendance and signatures.',
        'route'       => 'corex.rental-inspections.show',
        'permission'  => 'rental_inspections.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'pick_from'   => 'corex.rental-inspections.index',
        'pick_note'   => 'Open the inspection you want to look at — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-inspd-title"]',
                'section' => 'The inspection',
                'title'   => 'What and where',
                'body'    => 'The property, the status and the type of inspection.',
            ],
            [
                'element' => '[data-tour="rmnt-inspd-actions"]',
                'do'      => ['action' => 'click', 'say' => 'Click Print site form to see the blank form — or press Skip this step.', 'target' => '[data-qa="print-site-form"]'],
                'title'   => 'Forms and reports',
                'body'    => 'Print site form is a blank tick-box form to take to the property. Download report gives the finished report as a PDF. While signatures are still outstanding you can print a copy for people to sign by hand. A move-out inspection also offers the comparison with move-in.',
            ],
            [
                'element' => '[data-tour="rmnt-inspd-signing"]',
                'section' => 'Signatures',
                'title'   => 'Signing links',
                'body'    => 'Each person who has to sign can be given their own link, so they sign on their own phone. Only people with permission see this card.',
            ],
            [
                'element' => '[data-tour="rmnt-inspd-attendance"]',
                'section' => 'Who attended',
                'title'   => 'Attendance',
                'body'    => 'Who was invited, who came, and in what capacity. It is recorded on the property\'s Inspections tab and shown here for reference.',
            ],
            [
                'element' => '[data-tour="rmnt-inspd-history"]',
                'section' => 'The record',
                'title'   => 'History',
                'body'    => 'A dated log of everything that happened to the inspection: who scheduled it, started it, changed it or signed it.',
            ],
        ],
    ],

    'rmnt-insp-due' => [
        'key'         => 'rmnt-insp-due',
        'title'       => 'Inspections that are due',
        'description' => 'Move-in and move-out inspections coming up from your leases, plus the interim dates your agency has loaded.',
        'route'       => 'corex.rental-inspections.due',
        'permission'  => 'rental_inspections.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-due-intro"]',
                'section' => 'Reading the list',
                'title'   => 'What is due',
                'body'    => 'CoreX works out the move-in and move-out inspections that are due from your active leases. Interim inspections are not scheduled automatically: you load their dates yourself, and the responsible agent is reminded.',
            ],
            [
                'element' => '[data-tour="rmnt-due-tabs"]',
                'title'   => 'All inspections and Due',
                'body'    => 'Switch between the full list of inspections and this list of what is due.',
            ],
            [
                'element' => '[data-tour="rmnt-due-load"]',
                'section' => 'Loading interim dates',
                'do'      => ['action' => 'click', 'say' => 'Click to open Load interim inspection dates — or press Skip this step.', 'target' => 'summary'],
                'title'   => 'Load interim inspection dates',
                'body'    => 'Pick the tenancy, add as many dates as you need and save. A past date is allowed and simply shows as overdue. Only people who may manage planned dates see this.',
            ],
            [
                'element' => '[data-tour="rmnt-due-search"]',
                'section' => 'Finding one',
                'do'      => ['action' => 'fill', 'say' => 'Type a property, tenant, agent or note, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of the property address, a tenant\'s or agent\'s name, or a word from a note.',
            ],
            [
                'element' => '[data-tour="rmnt-due-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a type — or press Skip this step.'],
                'title'   => 'Filter by type',
                'body'    => 'Move-in, move-out or interim.',
            ],
            [
                'element' => '[data-tour="rmnt-due-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status.'],
                'title'   => 'Filter by status',
                'body'    => 'Open shows everything still to do: overdue, due, upcoming and booked. Pick one state to focus, or All to include finished ones.',
            ],
            [
                'element'       => '[data-tour="rmnt-due-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choices.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the list with your choices.',
            ],
            [
                'element' => '[data-tour="rmnt-due-table"]',
                'section' => 'Acting on it',
                'title'   => 'One row per due inspection',
                'body'    => 'Each row says what is due, for which tenancy, how many days overdue it is and who is responsible. The button on the right starts or schedules the inspection with the property, tenancy and type already filled in.',
            ],
        ],
    ],

    'rmnt-inv-list' => [
        'key'         => 'rmnt-inv-list',
        'title'       => 'Finding inventories',
        'description' => 'Room-by-room counts of what is in a furnished rental, taken at move-in and checked again at move-out.',
        'route'       => 'corex.rental-inventories.index',
        'permission'  => 'rental_inventories.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-inv-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Rental inventories',
                'body'    => 'An inventory lists the contents of a furnished rental room by room at move-in. At move-out it is checked against, so a missing or damaged item is on record.',
            ],
            [
                'element' => '[data-tour="rmnt-inv-filters"]',
                'section' => 'Finding an inventory',
                'do'      => ['action' => 'fill', 'say' => 'Type a property, tenant or agent name, then press Enter.', 'target' => 'input[type="text"]'],
                'title'   => 'Search and filter',
                'body'    => 'Search by property address, tenant or agent name, narrow by status, or by the date it was created. Filters appear once you have at least one inventory.',
            ],
            [
                'element' => '[data-tour="rmnt-inv-table"]',
                'title'   => 'One row per inventory',
                'body'    => 'Each row shows the property, tenants, status, the date created and the number of lines. Click a column heading to sort.',
            ],
            [
                'element' => '[data-tour="rmnt-inv-view"]',
                'do'      => ['action' => 'click', 'say' => 'Click View on an inventory.'],
                'title'   => 'Open an inventory',
                'body'    => 'View opens the inventory, with its rooms, items, photos and the move-out comparison.',
            ],
            [
                'element' => '[data-tour="rmnt-inv-new"]',
                'section' => 'Starting one',
                'do'      => ['action' => 'click', 'say' => 'Click Start Inventory.'],
                'title'   => 'Start an inventory',
                'body'    => 'Begin the inventory for a furnished rental. Show archived lists inventories that were archived.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // ROUND 2 — SIDE PAGES (AT-478 follow-on)
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-faulttypes-edit' => [
        'key'         => 'rmnt-faulttypes-edit',
        'title'       => 'Editing a fault type',
        'description' => 'Change a fault type\'s urgency and first-aid steps, say who normally pays for each cause and attach documents.',
        'route'       => 'corex.rental-fault-types.edit',
        'permission'  => 'rental_fault_types.create',
        'pick_from'   => 'corex.rental-fault-types.index',
        'pick_note'   => 'Open the fault type you want to change with Edit — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-ftedit-intro"]',
                'section' => 'The type',
                'title'   => 'Changing a fault type',
                'body'    => 'Nothing changes until you press Save. The causes and documents further down are saved with their own buttons.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Change the name — or press Skip this step.'],
                'title'   => 'Name',
                'body'    => 'How the type reads in lists and on the tenant\'s screen, for example "Blocked drain".',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-category"]',
                'do'      => ['action' => 'fill', 'say' => 'Change the category — or press Skip this step.'],
                'title'   => 'Category',
                'body'    => 'A trade or group, such as Plumbing. Types with the same category sit together in lists and filters.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-urgency"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the urgency — or press Skip this step.'],
                'title'   => 'Urgency',
                'body'    => 'Routine can wait for a normal booking, Urgent should be sorted soon, and Emergency needs action straight away. An open emergency fault is marked with a red tag on the fault list.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-firstaid"]',
                'section' => 'Helping the reporter',
                'do'      => ['action' => 'fill', 'say' => 'Change the first-aid steps — or press Skip this step.'],
                'title'   => 'First-aid steps',
                'body'    => 'Shown to the tenant before they can log this fault, for example "Switch off the water at the main valve". Writing {{main_water_valve_location}} or {{db_board_location}} fills in the place recorded on the property.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-active"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick Active — or press Skip this step.'],
                'title'   => 'Active',
                'body'    => 'Active types are offered to tenants. Untick it to keep a type but stop offering it for new reports.',
            ],
            [
                'element'       => '[data-tour="rmnt-ftedit-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save to keep your changes.'],
                'title'         => 'Save the type',
                'body'          => 'Saving keeps the changes to the name, urgency and first-aid steps. The causes and documents below save on their own buttons.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-causes"]',
                'section' => 'Who normally pays',
                'title'   => 'Self-checks and causes',
                'body'    => 'Two short lists. Self-checks are things a tenant can look at before reporting, such as a flat battery. Causes are what a contractor might find, such as roots in the pipe. For each one you can note who normally pays if that was the cause: the owner, the tenant or no default. It is only a suggestion; the agent decides on the work order.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-docs"]',
                'section' => 'Documents',
                'title'   => 'Attached documents',
                'body'    => 'Pictures, PDFs and video links that show the reporter how to do the first-aid steps. Download or open one to check it, or remove it if it is out of date.',
            ],
            [
                'element' => '[data-tour="rmnt-ftedit-adddoc"]',
                'do'      => ['action' => 'fill', 'say' => 'Paste a video link or type a caption — or press Skip this step.', 'target' => 'input[name="caption"]'],
                'title'   => 'Add a document',
                'body'    => 'Choose an image or a PDF, or paste a video link, add a caption if you like, then press Add document. It is attached straight away.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // CREW LINK LOG
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-crews-linklog' => [
        'key'         => 'rmnt-crews-linklog',
        'title'       => 'Reading a crew\'s link log',
        'description' => 'Everything that has happened on one crew member\'s standing link — created, sent, revoked, opened and used.',
        'route'       => 'corex.rental-crews.link.events',
        'permission'  => 'rental_job_cards.share',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-crews',
        'pick_from'   => 'corex.rental-crews.index',
        'pick_note'   => 'Open the crew, then its link log — the guide starts once the log opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-linklog-title"]',
                'section' => 'What this log is',
                'title'   => 'The link\'s history',
                'body'    => 'A crew member uses one standing link on their phone to see and work their jobs. This is the full record of that link: what the office did to it, and when the crew opened or used it. It only grows; entries cannot be edited or removed.',
            ],
            [
                'element' => '[data-tour="rmnt-linklog-filter"]',
                'section' => 'Finding an entry',
                'title'   => 'Narrow the log',
                'body'    => 'Show everything, or only one kind of entry, such as links emailed or revoked.',
            ],
            [
                'element' => '[data-tour="rmnt-linklog-event"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a kind of entry — or press Skip this step.'],
                'title'   => 'Show',
                'body'    => 'Link created, regenerated, emailed, sent on WhatsApp or revoked are things the office did. Crew page opened, Job opened and Crew action are things the crew did.',
            ],
            [
                'element'       => '[data-tour="rmnt-linklog-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter to apply your choice.'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter reloads the log with your choice.',
            ],
            [
                'element' => '[data-tour="rmnt-linklog-table"]',
                'section' => 'Reading the log',
                'title'   => 'Newest first',
                'body'    => 'Each row shows when it happened, what happened, the job it concerned and who did it. Office actions show the staff member; crew actions show the device they used. Use it to check whether a link was opened, or to see what happened before you revoke it.',
            ],
            [
                'element' => '[data-tour="rmnt-linklog-jobcol"]',
                'title'   => 'Job',
                'body'    => 'When an entry belongs to a job card, its title is shown, and you can click it to open the card if you have access to job cards.',
            ],
            [
                'element' => '[data-tour="rmnt-linklog-back"]',
                'section' => 'Going back',
                'title'   => 'Back to the crew',
                'body'    => 'Returns to the crew\'s page, where you can create, email or revoke the link.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // CATALOGUE IMPORT PREVIEW
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-catalogue-import-preview' => [
        'key'         => 'rmnt-catalogue-import-preview',
        'title'       => 'Checking an import preview',
        'description' => 'See exactly what an uploaded price list will do to your catalogue before anything is saved.',
        'route'       => 'corex.rental-catalogue-items.import.preview',
        'permission'  => 'rental_catalogue.manage',
        'pick_from'   => 'corex.rental-catalogue-items.import.index',
        'pick_note'   => 'Upload your file here and click Preview import — the guide starts on the preview.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-impprev-title"]',
                'section' => 'The preview',
                'title'   => 'Nothing is saved yet',
                'body'    => 'This is a dry run of your file. Your catalogue only changes when you press Confirm import at the bottom. The preview is kept for a short while; if it expires, upload the file again.',
            ],
            [
                'element' => '[data-tour="rmnt-impprev-counts"]',
                'section' => 'Reading the result',
                'title'   => 'The totals',
                'body'    => 'How many rows were read, how many will be added as new items, how many will update an existing item, how many will be skipped because the code already exists, and how many have errors. Rows with errors are left out.',
            ],
            [
                'element' => '[data-tour="rmnt-impprev-table"]',
                'title'   => 'Row by row',
                'body'    => 'Each row shows the code, description, type, unit, VAT type and price as CoreX understood them. A dash means the value was empty or not recognised. Check the prices before you go on.',
            ],
            [
                'element' => '[data-tour="rmnt-impprev-actioncol"]',
                'title'   => 'What will happen',
                'body'    => 'New adds the item. Will update replaces the existing item with your values. Already exists, skip leaves it as it is. Error says what is wrong with the row, for example a missing code; fix it in your file and upload again.',
            ],
            [
                'element' => '[data-tour="rmnt-impprev-back"]',
                'title'   => 'Something looks wrong?',
                'body'    => 'Upload a different file to start again. Nothing has been changed.',
            ],
            [
                'element'       => '[data-tour="rmnt-impprev-confirm"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Confirm import when you are happy with the preview.'],
                'title'         => 'Confirm the import',
                'body'          => 'This adds and updates the items listed above. The button is greyed out when there is nothing to import.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // INSPECTIONS — MOVE-OUT COMPARISON AND SCAN REVIEW
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-insp-compare' => [
        'key'         => 'rmnt-insp-compare',
        'title'       => 'Comparing move-out with move-in',
        'description' => 'Go through what changed since move-in, room by room, and record your decision on each difference.',
        'route'       => 'corex.rental-inspections.deposit-comparison',
        'permission'  => 'rental_inspections.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'pick_from'   => 'corex.rental-inspections.index',
        'pick_note'   => 'Open a move-out inspection and click its comparison button — the guide starts once the comparison opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-cmp-title"]',
                'section' => 'The comparison',
                'title'   => 'What you are looking at',
                'body'    => 'The property and tenant, with every item of the move-out inspection set beside what was recorded at move-in. It is a record of what was found, for you to review.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-baseline"]',
                'title'   => 'What it is compared with',
                'body'    => 'The completed move-in inspection of the same tenancy, even when that was on an earlier lease term. Interim inspections and fault reports show up on the items they touched, but are never the comparison. If there is no move-in inspection, every item shows as new.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-actions"]',
                'title'   => 'Related pages',
                'body'    => 'Inventory comparison appears when the property has an inventory. The arrow takes you back to the move-out inspection.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-counts"]',
                'section' => 'Reading the differences',
                'title'   => 'Counts by kind of difference',
                'body'    => 'Worse, Different, Same, Better, New item and Not at move-out, with how many items fall in each. Click one to show only those items; click it again to clear.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-facts"]',
                'title'   => 'Keys, remotes and meters',
                'body'    => 'The counts and readings taken at move-in beside those at move-out, with Changed where they differ.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-search"]',
                'section' => 'Finding an item',
                'do'      => ['action' => 'fill', 'say' => 'Type an item, room or word from a note — or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Finds items by name, room or the words in a note.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-room-filter"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a room — or press Skip this step.'],
                'title'   => 'Room',
                'body'    => 'Show one room only.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-difference"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a kind of difference — or press Skip this step.'],
                'title'   => 'Difference',
                'body'    => 'Show only items that got worse, are new, and so on.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-classification"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick one — or press Skip this step.'],
                'title'   => 'Classification',
                'body'    => 'Not classified yet is your to-do list: the differences you have not yet made a decision on.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-order"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick an order — or press Skip this step.'],
                'title'   => 'Order',
                'body'    => 'Walking order follows the property room by room. Worst first puts the most serious differences at the top.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-only"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick Differences only — or press Skip this step.'],
                'title'   => 'Differences only',
                'body'    => 'Hides items that are unchanged so you see only what needs a look.',
            ],
            [
                'element'       => '[data-tour="rmnt-cmp-apply"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Apply to use your choices.'],
                'title'         => 'Apply',
                'body'          => 'Apply reloads the comparison with your search and filters.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-roomhead"]',
                'section' => 'Going through a room',
                'do'      => ['action' => 'click', 'say' => 'Click a room heading to fold it away — or press Skip this step.'],
                'title'   => 'One block per room',
                'body'    => 'The heading shows how many items the room has and how many are worth a look. Click it to hide or show the room.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-row"]',
                'title'   => 'One item',
                'body'    => 'Move-in on the left, move-out on the right, each with its condition, note and photos. Click a photo to see the two sets side by side. A difference tag at the top right says how the item changed.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-classify"]',
                'section' => 'Recording your decision',
                'do'      => ['action' => 'fill', 'say' => 'Type your reasoning for this item — or press Skip this step.', 'target' => 'input[name="note"]'],
                'title'   => 'Your decision',
                'body'    => 'For an item that changed, choose what it is, such as fair wear and tear or charge to the tenant, and give your reasoning (required). If amounts are switched on for your agency, a charge needs its amount. CoreX only records this; the money is settled in your accounting system.',
            ],
            [
                'element'       => '[data-tour="rmnt-cmp-classify"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Record to keep your decision.', 'target' => 'button[type="submit"]'],
                'title'         => 'Record it',
                'body'          => 'Your decision is kept with your name and the date. A later change adds to the record rather than replacing it.',
            ],
            [
                'element' => '[data-tour="rmnt-cmp-note"]',
                'title'   => 'Not a deposit calculation',
                'body'    => 'This page never works out or deducts anything from a deposit. It is a record of what was found, for review.',
            ],
        ],
    ],

    'rmnt-insp-scan-review' => [
        'key'         => 'rmnt-insp-scan-review',
        'title'       => 'Reviewing a scanned site form',
        'description' => 'Check what CoreX read from your ticked paper form, correct anything it got wrong and apply it to the inspection.',
        'route'       => 'corex.rental-inspections.scans.review',
        'permission'  => 'rental_inspections.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'pick_from'   => 'corex.rental-inspections.index',
        'pick_note'   => 'Open the inspection, upload the scanned form and open its review — the guide starts once the review opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-scanr-title"]',
                'section' => 'The review',
                'title'   => 'Nothing is applied yet',
                'body'    => 'CoreX has read the ticks and handwriting on your scanned form and proposes a condition for each item. Nothing reaches the inspection until you confirm every row and press Apply. This heading stays at the top as you scroll.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-original"]',
                'section' => 'Checking against the paper',
                'title'   => 'Open original',
                'body'    => 'Opens the scan itself in a new tab, so you can compare it with what CoreX read.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-status"]',
                'section' => 'What still needs you',
                'title'   => 'Read, to check, not picked up',
                'body'    => 'How many rows were read with confidence, how many CoreX was unsure about and how many it could not find a tick for. Below it, red links name what is stopping Apply, such as a row with no condition or a condition that needs a note. Click one to jump to that row.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-pages"]',
                'title'   => 'What was read from each page',
                'body'    => 'For every page of the scan: read or not, and why. If a page was not read, check that it is the right form, the page is flat and well lit, and then upload it again.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-room"]',
                'section' => 'Going through the items',
                'title'   => 'One block per room',
                'body'    => 'Items are grouped by room, in the order of the form.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-row"]',
                'title'   => 'One item',
                'body'    => 'The item, the page it was on and what is already recorded on screen for it. The tag on the right says how sure CoreX is: Read, Check, or Not picked up.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-state"]',
                'title'   => 'How sure CoreX was',
                'body'    => 'Read means a clear tick. Check means two boxes were ticked or the tick was faint, so look at the paper. Not picked up means you must choose the condition yourself. CoreX never guesses on an unclear row.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-condition"]',
                'do'      => ['action' => 'choose', 'say' => 'Confirm or correct the condition.'],
                'title'   => 'The condition',
                'body'    => 'Choose the condition that is right, whatever CoreX proposed. Every row needs one before you can apply.',
            ],
            [
                'element' => '[data-tour="rmnt-scanr-note"]',
                'do'      => ['action' => 'fill', 'say' => 'Add or correct the note — or press Skip this step.'],
                'title'   => 'Note and photos',
                'body'    => 'Handwriting CoreX could read is filled in and marked Read from scan; edit it if it is wrong, or type it in if CoreX could not read it. Some conditions require a note. Once a condition is chosen, Add photo lets you attach a photo to the item. Notes and photos are saved as you add them.',
            ],
            [
                'element'       => '[data-tour="rmnt-scanr-apply"]',
                'section'       => 'Applying the marks',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Apply proposed marks when every row is confirmed.'],
                'title'         => 'Apply',
                'body'          => 'Applying records your confirmed conditions and notes on the inspection. The button stays off, with the reason beside it, while anything is still missing. A signed or completed inspection is read-only.',
            ],
        ],
    ],

    // ═════════════════════════════════════════════════════════════════════════
    // INVENTORIES — START, RECORD, COMPARISON
    // ═════════════════════════════════════════════════════════════════════════

    'rmnt-inv-start' => [
        'key'         => 'rmnt-inv-start',
        'title'       => 'Starting an inventory',
        'description' => 'Begin the room-by-room inventory of a property.',
        'route'       => 'corex.rental-inventories.create',
        'permission'  => 'rental_inventories.create',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-invnew-title"]',
                'section' => 'Starting an inventory',
                'title'   => 'One choice',
                'body'    => 'An inventory is made once for a property: what is in each room and in what number. Pick the property and CoreX starts it.',
            ],
            [
                'element' => '[data-tour="rmnt-invnew-property"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the property.', 'target' => 'select'],
                'title'   => 'Property',
                'body'    => 'Every property is offered. If it has an active lease the inventory is attached to that tenancy; otherwise, for example a property for sale or between tenants, it is kept on the property itself. A property can have only one open inventory at a time.',
            ],
            [
                'element'       => '[data-tour="rmnt-invnew-start"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Start Inventory.'],
                'title'         => 'Start',
                'body'          => 'This opens the new inventory, where you add its items room by room.',
            ],
        ],
    ],

    'rmnt-inv-detail' => [
        'key'         => 'rmnt-inv-detail',
        'title'       => 'Working an inventory',
        'description' => 'One inventory — what is in each room, who signs it and how to finish and share it.',
        'route'       => 'corex.rental-inventories.show',
        'permission'  => 'rental_inventories.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'pick_from'   => 'corex.rental-inventories.index',
        'pick_note'   => 'Open the inventory you want to work on with View — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-invd-title"]',
                'section' => 'The inventory',
                'title'   => 'What and where',
                'body'    => 'The property, the status and when and by whom it was started.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-actions"]',
                'title'   => 'Page buttons',
                'body'    => 'Print for signature gives a paper copy to be signed by hand while the inventory is open. A completed inventory with a tenancy also offers the move-out comparison. Back to list returns to all inventories.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-report"]',
                'section' => 'Sharing the report',
                'title'   => 'The signed report',
                'body'    => 'Once the inventory is completed: download the report, copy a share link or send it on WhatsApp. Resend report shows exactly who will receive it before you confirm.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-parties"]',
                'section' => 'Recording the contents',
                'title'   => 'Who is involved',
                'body'    => 'The owner, the tenants on the lease and who did the inspection.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-items"]',
                'title'   => 'Items by room',
                'body'    => 'Every room of the property is listed, even an empty one. A room shows its items with quantities, Nothing in this room if it was ticked as empty, or Not checked if nobody has looked yet. Remove takes a line off but keeps it in the record.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-additem"]',
                'do'      => ['action' => 'fill', 'say' => 'Type what the item is, for example Wooden TV table — or press Skip this step.', 'target' => 'input[name="description"]'],
                'title'   => 'Add an item',
                'body'    => 'Type the room, the quantity and a plain description, then press Add item. For a full walk-through with photos, use the capture screen from the inventory list.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-signatures"]',
                'section' => 'Signatures',
                'title'   => 'Who has signed',
                'body'    => 'Everyone who has signed, refused or signed on paper, with the date. A refusal is kept as a refusal and never shown as a signature.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-sign"]',
                'title'   => 'Recording a signature',
                'body'    => 'For each person still to answer: sign on screen, upload a paper signature, send it out for a paper signature, or record that they refused and why. The agent signs last, once everyone else has answered.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-complete"]',
                'section' => 'Finishing',
                'title'   => 'Complete',
                'body'    => 'Closes the inventory once everyone has answered. If any room is still unchecked, Complete names it so you can go and finish it first.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-buyer"]',
                'title'   => 'Buyer acceptance',
                'body'    => 'Only on a property with an accepted offer. Optional: send the buyer a link to accept what comes with the sale. They sign on their own device.',
            ],
            [
                'element' => '[data-tour="rmnt-invd-cancel"]',
                'section' => 'Started by mistake',
                'title'   => 'Cancel this inventory',
                'body'    => 'Use this only if the inventory should not exist. It stays on record as cancelled, and you can start a new one.',
            ],
        ],
    ],

    'rmnt-inv-compare' => [
        'key'         => 'rmnt-inv-compare',
        'title'       => 'Checking an inventory at move-out',
        'description' => 'Compare what is there today with the original move-in inventory, item by item, and complete the move-out check.',
        'route'       => 'corex.rental-inventories.comparison',
        'permission'  => 'rental_inventories.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-inspections',
        'pick_from'   => 'corex.rental-inventories.index',
        'pick_note'   => 'Open a completed inventory and click Move-out comparison — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rmnt-invc-title"]',
                'section' => 'The comparison',
                'title'   => 'Against the original',
                'body'    => 'Every line is compared with the original move-in inventory, not with any later check. Nothing here is a deposit figure; it is what was found, for you to review.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-only"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick Only differences — or press Skip this step.'],
                'title'   => 'Only differences',
                'body'    => 'Hides the lines that have not changed. This is the view to hand a tenant.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-check"]',
                'section' => 'The move-out check',
                'title'   => 'Move-out check',
                'body'    => 'Every item needs a finding before the check can be completed. "Present" counts as a finding. The line says how many are still to record.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-complete"]',
                'title'   => 'Complete the check',
                'body'    => 'Greyed out until every item has a finding. Once it is completed, a sign-off line appears for each person involved.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-signoffs"]',
                'title'   => 'Sign-off',
                'body'    => 'For the agent and each tenant and owner: type their name, choose Signed or Refused to sign, give the reason if they refused, and press Record. Replace corrects an earlier answer.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-rows"]',
                'section' => 'Going through the items',
                'title'   => 'One line per item',
                'body'    => 'Items that have not changed are one grey line reading no change. Everything else, or anything not yet checked, gets a full block, because those are the ones you may still need to act on.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-qty"]',
                'title'   => 'The count',
                'body'    => 'For example "4 at move-in, 2 today". The finding below it says whether the item is present, short, damaged or missing, with who recorded it and when.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-record"]',
                'do'      => ['action' => 'click', 'say' => 'Click Record on an item — or press Skip this step.', 'until' => '[data-tour="rmnt-invc-panel"]'],
                'title'   => 'Record or correct',
                'body'    => 'Opens a small form: choose what you found, how many are there and add a note. Press Save to keep it.',
            ],
            [
                'element' => '[data-tour="rmnt-invc-today"]',
                'title'   => 'Photos',
                'body'    => 'Move-in photos are on the left and today\'s on the right. A line with no photo today is flagged in red, because a claim without a photo is weak. Add photo uploads one at once, and From the move-out inspection reuses a photo already taken of that room.',
            ],
        ],
    ],

];
