<?php

return [
    'enabled' => (bool) env('DEMO_ENABLED', false),
    'email' => 'ops@fellwise.co.uk',
    'fault_seconds' => 180,
    'replay_limit' => 1000,
];
