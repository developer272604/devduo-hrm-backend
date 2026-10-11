<?php

return [
    'import' => ['max_rows' => 20000, 'max_kb' => 10240],
    'export' => ['ttl_hours' => (int) env('PEOPLE_EXPORT_TTL_HOURS', 24), 'chunk' => 1000],
    'bulk' => ['queue_above' => 100, 'max_selection' => 10000, 'token_ttl_minutes' => 15],
];
