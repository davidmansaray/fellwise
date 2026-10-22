<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Auth\Pages\Login;
use Illuminate\Contracts\Support\Htmlable;

final class DemoLogin extends Login
{
    public function getHeading(): string|Htmlable|null
    {
        return config('demo.enabled') ? 'Explore Fellwise' : parent::getHeading();
    }

    public function getView(): string
    {
        return config('demo.enabled') ? 'filament.pages.demo-login' : parent::getView();
    }
}
