<?php

use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Profile\ProfileServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    ProfileServiceProvider::class,
];
