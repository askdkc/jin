<?php

return [
    'max_file_megabytes' => (int) env('UPLOAD_MAX_FILE_MB', 200),
    'max_files_per_request' => (int) env('UPLOAD_MAX_FILES', 20),
    'max_request_megabytes' => (int) env('UPLOAD_MAX_REQUEST_MB', 500),
];
