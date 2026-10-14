<?php

return [
    // Blade views under resources/views/cover-letters, keyed by insurance company claim_type.
    'templates' => [
        'ajk' => ['view' => 'cover-letters.ajk'],
        'credit' => ['view' => 'cover-letters.credit'],
    ],
    'disk' => 'local',
    'directory' => 'insurance-cover-letters',
];
