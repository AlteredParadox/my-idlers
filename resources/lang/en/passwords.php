<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Reset Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are the default lines which match reasons
    | that are given by the password broker for a password update attempt
    | has failed, such as for an invalid token or invalid new password.
    |
    */

    'reset' => 'Your password has been reset!',
    'sent' => 'We have emailed your password reset link!',
    'throttled' => 'Please wait before retrying.',
    // Deliberately identical for an unknown email and a bad/expired token.
    // Distinct wording on this UNAUTHENTICATED form answered "does this
    // account exist?" for anyone who asked with a junk token.
    'token' => 'This password reset link is invalid or has expired.',
    'user' => 'This password reset link is invalid or has expired.',

];
