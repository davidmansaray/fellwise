<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\MessagingServiceProvider;

return [
    AppServiceProvider::class,
    MessagingServiceProvider::class,
    AdminPanelProvider::class,
];
