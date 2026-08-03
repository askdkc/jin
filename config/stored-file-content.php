<?php

return [
    'enabled' => (bool) env('STORED_FILE_CONTENT_ENABLED', false),

    'markitdown_path' => env('STORED_FILE_CONTENT_MARKITDOWN_PATH', 'markitdown'),

    'process_timeout' => (int) env('STORED_FILE_CONTENT_PROCESS_TIMEOUT', 120),
    'job_timeout' => (int) env('STORED_FILE_CONTENT_JOB_TIMEOUT', 180),
    'processing_lease_seconds' => (int) env('STORED_FILE_CONTENT_PROCESSING_LEASE_SECONDS', 300),

    'max_source_bytes' => (int) env('STORED_FILE_CONTENT_MAX_SOURCE_BYTES', 50 * 1024 * 1024),
    'max_output_bytes' => (int) env('STORED_FILE_CONTENT_MAX_OUTPUT_BYTES', 16 * 1024 * 1024),
    'max_indexed_bytes' => (int) env('STORED_FILE_CONTENT_MAX_INDEXED_BYTES', 2 * 1024 * 1024),
];
