<?php

$boolean = static function (string $key, bool $default): bool {
    $value = env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    return match ((string) $value) {
        'true', '1' => true,
        'false', '0' => false,
        default => false,
    };
};

$appUrl = rtrim((string) env('APP_URL', 'http://localhost:8000'), '/');

return [
    'enabled' => $boolean('ONLYOFFICE_ENABLED', false),
    'document_server_url' => env('ONLYOFFICE_DOCUMENT_SERVER_URL', 'http://127.0.0.1:8081'),
    'public_url' => env('ONLYOFFICE_PUBLIC_URL', $appUrl.'/onlyoffice'),
    'internal_url' => env('APP_ONLYOFFICE_INTERNAL_URL', 'http://127.0.0.1:8090'),
    'jwt_secret' => env('ONLYOFFICE_JWT_SECRET', ''),
    'instance_id' => env('ONLYOFFICE_INSTANCE_ID', 'jin-production'),
    'download_ttl' => (int) env('ONLYOFFICE_INTERNAL_DOWNLOAD_TTL', 300),
    'healthcheck_timeout' => (int) env('ONLYOFFICE_HEALTHCHECK_TIMEOUT', 3),
    'max_file_size' => (int) env('ONLYOFFICE_MAX_FILE_SIZE_BYTES', 209715200),
    'document_server_max_download_bytes' => (int) env('ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES', 209715200),
    'allow_download' => $boolean('ONLYOFFICE_ALLOW_DOWNLOAD', false),
    'allow_print' => $boolean('ONLYOFFICE_ALLOW_PRINT', false),
    'allow_copy' => $boolean('ONLYOFFICE_ALLOW_COPY', true),
];
