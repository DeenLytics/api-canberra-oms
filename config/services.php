<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * LocationService reads config('services.google_maps.api_key') to turn a
     * GPS fix into an area name. There was no google_maps entry here at all, so
     * that call returned null no matter what the environment held — every point
     * has been stored with area = null, buildAreaActivities() has always
     * produced an empty array, and the area timeline in the admin's location
     * report has never had anything to show.
     *
     * This must be a SERVER key, not the NEXT_PUBLIC_GOOGLE_MAPS_API_KEY the
     * admin panel ships to the browser: that one belongs in a referrer
     * restriction, which a server-side call can never satisfy. Restrict this
     * one to the VPS's IP and to the Geocoding API.
     */
    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_SERVER_KEY'),
    ],

];
