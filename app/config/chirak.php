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

    // Public demo without a GPU worker: stored reports and the playground only, no new runs.
    // playground_note says what serves the playground there (e.g. the CPU build of the adapter).
    'readonly' => (bool) env('CHIRAK_READONLY', false),
    'playground_note' => env('CHIRAK_PLAYGROUND_NOTE'),
    'playground_per_minute' => (int) env('CHIRAK_PLAYGROUND_PER_MINUTE', 20),
    // Playground without the demo token (the public demo, for judges). Visitors without the token share one
    // budget per minute on top of the per-address limit: two 2-CPU llama.cpp servers answer about 7 compares a minute.
    'playground_public' => (bool) env('CHIRAK_PLAYGROUND_PUBLIC', false),
    'playground_public_per_minute' => (int) env('CHIRAK_PLAYGROUND_PUBLIC_PER_MINUTE', 6),

    'stop' => [
        'max_iterations' => 3,
        'min_gain' => 0.01,
        'max_leakage_drop_rate' => 0.30,
    ],

    // Train generation for targeted slices: label-check rejects sent to the Claude judge per iteration.
    'judge_cap' => (int) env('JUDGE_CAP', 400),
];
