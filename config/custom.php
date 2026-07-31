<?php

return [

    /*
     * Registration cap. Read via config() so it survives `artisan
     * config:cache` (env() returns null at request time once cached).
     */
    'max_users' => env('MAX_USERS', 1),

    /*
     * Demo-data seeding flag — same cached-config rule: the seeder runs
     * through artisan, where env() is null once config:cache has run.
     */
    'seed_demo_data' => env('SEED_DEMO_DATA', false),

    // Second switch for production only. The demo set creates a verified
    // administrator whose password is published in the README, so seeding it
    // on a reachable deployment hands that deployment to anyone who reads the
    // docs. A public demo instance is a legitimate thing to run, hence the
    // escape hatch rather than a hard block.
    'allow_demo_data_in_production' => env('ALLOW_DEMO_DATA_IN_PRODUCTION', false),

    /*
     * Seconds a registration waits for the cap's serialization lock before
     * giving up (fail-closed). Tests set 0 to assert the lock is held.
     */
    'registration_lock_seconds' => 5,

];
