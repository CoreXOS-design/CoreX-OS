<?php

/*
|--------------------------------------------------------------------------
| E-sign adoption agreement (.ai/specs/esign-signing-modes.md §6)
|--------------------------------------------------------------------------
|
| The wording a signer agrees to when they adopt their signature and initial once (signing modes 2 and 3).
| Platform text, deliberately NOT agency-editable: it is legal wording, and an agency editing it is a risk, not a
| setting. Johan approved both texts as written on 23 Oct 2026.
|
| `version` is bumped on ANY change to either text. Each adoption stores the exact text it was shown plus this
| version, so changing the wording later never rewrites what a signer actually agreed to.
*/

return [

    'version' => 1,

    'wording' => [
        // Johan, 26 Oct 2026: one adoption wording for the one "adopt once" choice. It is the click-to-apply text he approved
        // on 23 Oct; the "Apply to all my places" confirmation carries the other approved sentence (apply_all_notice).
        // The two keys below stay so an adoption recorded before the ruling still resolves; nothing new writes them.
        'adopt_once' => 'By signing here you agree this is your signature and initial and that it will be applied wherever you click to sign or initial on this document.',
        'adopt_then_stamp' => 'By signing here you agree this is your signature and initial and that it will be applied wherever you click to sign or initial on this document.',
        'adopt_and_fill_all' => 'By signing here you agree this is your signature and initial and that it will be applied to every signature and initial place on this document that is yours, without you clicking each one.',
    ],

    // Shown in the "Apply to all my places" confirmation (the approved 23 Oct wording, unchanged).
    'apply_all_notice' => 'By signing here you agree this is your signature and initial and that it will be applied to every signature and initial place on this document that is yours, without you clicking each one.',

    // Largest adopted image accepted (decoded bytes). A drawn pad PNG is a few KB; this is a ceiling, not a target.
    'max_image_bytes' => 1_500_000,
];
