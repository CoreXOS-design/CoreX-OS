<?php

/**
 * Training script - Properties 3 of 4: add and tag photos. Selectors walked on QA1 as the training agent. Route: none on purpose - the property page's own route belongs to
 * the "Edit a property" video, so this one is found in the library (its url carries ?flow=photos only to keep it apart). Nothing is uploaded: the recording points at the
 * upload area and never picks a file.
 */
return [
    'key' => 'properties-photos',
    'title' => 'Add photos to a property',
    'url' => '/corex/properties?flow=photos',
    'specs' => ['.ai/specs/property-page-redesign.md'],
    'audience' => [],
    'related' => ['properties-load', 'properties-edit', 'properties-status'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to add photos to a property.',
    'steps' => [
        [
            'selector' => '[data-tour="re-properties-list"] a.corex-btn-outline', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the property from your list.',
            'why' => 'Photos belong to one property, so start on its page.',
        ],
        [
            'selector' => '[data-tour="prop-tab-gallery"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press the Gallery tab.',
            'why' => 'All the photos of this house are kept here.',
        ],
        [
            'selector' => '[data-tour="prop-gallery-upload"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Drop your photos here, or press to choose them.',
            'why' => 'Good photos get more enquiries than anything else you do.',
        ],
        [
            'selector' => '[data-tour="prop-gallery"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Your photos appear here as they upload.',
            'why' => 'They go to the portals the next time you refresh the property.',
        ],
        [
            'selector' => '[data-tour="prop-gallery-tag-btn"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Tag each photo with its room.',
            'why' => 'It makes the inspection report and the property description faster.',
        ],
    ],
    'outro' => 'Next, see how to change a property\'s status.',
];
