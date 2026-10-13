<?php

return [
    'worker' => [
        'base_url' => env('WORKER_BASE_URL', 'http://127.0.0.1:8100'),
        'token' => env('WORKER_BEARER_TOKEN'),
        // Seconds between polls of a running worker job.
        'poll_delay' => (int) env('WORKER_POLL_DELAY', 15),
    ],

    'orchestrator' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ORCHESTRATOR_MODEL', 'claude-sonnet-5-5'),
    ],

    'demo_token' => env('DEMO_TOKEN'),

    'stop' => [
        'max_iterations' => 3,
        'min_gain' => 0.01,
        'max_leakage_drop_rate' => 0.30,
    ],

    // Train generation for targeted slices: label-check rejects sent to the Claude judge per iteration.
    'judge_cap' => (int) env('JUDGE_CAP', 400),
];
