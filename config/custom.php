<?php

return [

    /*
     * Registration cap. Read via config() so it survives `artisan
     * config:cache` (env() returns null at request time once cached).
     */
    'max_users' => env('MAX_USERS', 1),

];
