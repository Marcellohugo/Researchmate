<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\SenopatiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function index(): View
    {
        return view('research.settings', ['health' => Cache::get('ai.connection_status')]);
    }

    public function check(SenopatiService $ai): RedirectResponse
    {
        $health = $ai->checkConnection();
        Cache::put('ai.connection_status', $health, now()->addMinutes(5));

        return back()->with('status', 'Pemeriksaan koneksi AI selesai. Lihat status dan daftar model di bawah.');
    }
}
