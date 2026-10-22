<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\Pages\MissionControl;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DemoSessionController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);
        $user = User::query()->where('email', config('demo.email'))->firstOrFail();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->to(MissionControl::getUrl(panel: 'admin'));
    }
}
