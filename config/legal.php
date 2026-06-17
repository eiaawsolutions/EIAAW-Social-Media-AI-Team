<?php

/**
 * Single source of truth for the legal-document version and per-document
 * metadata.
 *
 * HOW VERSIONING WORKS
 * --------------------
 * `version` is the ONE value the acceptance gate compares against. When a user
 * accepts, we stamp this value onto users.legal_accepted_version. To force
 * EVERY user to re-accept (e.g. after a material change to the Terms), bump
 * `version` here and update the relevant `documents.*.updated` dates — on their
 * next panel visit, anyone whose stored version no longer matches is redirected
 * to /agency/legal-acceptance.
 *
 * The `documents.*.updated` dates are also what the legal blade pages render as
 * "Last updated <date>" — they read from this config, so the gate and the
 * on-page date can never drift apart.
 *
 * Keep `version` a dated string (matches the "Last updated <date>" idiom and
 * the document_version column).
 */
return [

    // Bump this to re-prompt every user whose stored acceptance differs.
    'version' => '2026-06-17',

    // Every document a user agrees to when they tick the acceptance box, in the
    // order they should be presented. `route` is the named route the on-page /
    // gate links resolve to; `updated` is the human date shown on the page.
    'documents' => [
        'terms' => [
            'name' => 'Terms of Service',
            'route' => 'legal.terms',
            'updated' => '17 June 2026',
        ],
        'acceptable_use' => [
            'name' => 'Acceptable Use Policy',
            'route' => 'legal.acceptable-use',
            'updated' => '17 June 2026',
        ],
        'ai_disclaimer' => [
            'name' => 'AI Content Disclaimer',
            'route' => 'legal.ai-disclaimer',
            'updated' => '17 June 2026',
        ],
        'privacy' => [
            'name' => 'Privacy Policy',
            'route' => 'legal.privacy',
            'updated' => '17 June 2026',
        ],
        'dpa' => [
            'name' => 'Data Processing Addendum',
            'route' => 'legal.dpa',
            'updated' => '17 June 2026',
        ],
    ],

    // Shown ONLY on re-acceptance (a user who previously accepted an older
    // version) so first-time users aren't handed a scary diff. Update this
    // sentence whenever you bump `version` so returning users know what changed.
    'change_note' => 'We have expanded our legal terms with a dedicated Acceptable Use Policy, an AI Content Disclaimer, and a Data Processing Addendum, and strengthened our liability, indemnity, intellectual-property, and privacy terms. Please review and accept the updated documents to continue.',

];
