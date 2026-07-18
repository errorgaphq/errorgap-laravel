<?php

return [
    'endpoint' => env('ERRORGAP_ENDPOINT'),
    'project_slug' => env('ERRORGAP_PROJECT_SLUG'),
    'project_id' => env('ERRORGAP_PROJECT_ID'),
    'api_key' => env('ERRORGAP_API_KEY'),
    'environment' => env('ERRORGAP_ENVIRONMENT', env('APP_ENV', 'production')),
    'async' => env('ERRORGAP_ASYNC', true),
    'timeout_seconds' => (int) env('ERRORGAP_TIMEOUT', 5),

    // Capture request timings and Eloquent query spans. Sampling is applied
    // independently from error reporting.
    'apm_enabled' => env('ERRORGAP_APM_ENABLED', false),
    'apm_sample_rate' => (float) env('ERRORGAP_APM_SAMPLE_RATE', 1.0),

    // Hash of substrings (case-insensitive) used to mask sensitive
    // request parameters before they reach Errorgap.
    'filter_keys' => null,

    // Set to false to disable automatic reporting from Laravel's
    // exception handler and queue worker.
    'capture_exceptions' => true,
    'capture_jobs' => true,

    // Include the authenticated user's identifier in error context.
    'include_user' => env('ERRORGAP_INCLUDE_USER', false),
];
