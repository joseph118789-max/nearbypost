<?php

/*
|--------------------------------------------------------------------------
| Auth Configuration
|--------------------------------------------------------------------------
|
| Add this to your config/auth.php file under the 'guards' section.
|
*/

'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    
    'admin' => [
        'driver' => 'session',
        'provider' => 'admins',
    ],
],

/*
|--------------------------------------------------------------------------
| Providers Configuration
|--------------------------------------------------------------------------
|
| Add this to your config/auth.php file under the 'providers' section.
|
*/

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
    
    'admins' => [
        'driver' => 'eloquent',
        'model' => App\Models\Admin::class,
    ],
],
