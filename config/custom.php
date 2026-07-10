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

];
