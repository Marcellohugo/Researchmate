<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ResearchGap;
use App\Models\ResearchProject;
use App\Models\ResearchRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $owned = ResearchProject::query()->where('user_id', $user->id);
        $ids = (clone $owned)->select('id');

        return view('dashboard', [
            'stats' => [
                'Proyek riset' => (clone $owned)->count(),
                'Artikel tersimpan' => DB::table('project_papers')->whereIn('project_id', clone $ids)->count(),
                'Dokumen PDF' => Document::query()->whereIn('project_id', clone $ids)->count(),
                'Kandidat celah riset' => ResearchGap::query()->whereIn('project_id', clone $ids)->count(),
            ],
            'projects' => (clone $owned)->withCount(['documents', 'papers'])->latest('updated_at')->limit(5)->get(),
            'runs' => ResearchRun::query()->whereIn('project_id', clone $ids)->with('project')->latest()->limit(5)->get(),
            'activeCount' => ResearchRun::query()->whereIn('project_id', clone $ids)->whereNotIn('status', ['completed', 'failed', 'cancelled'])->count(),
            'health' => Cache::get('ai.connection_status'),
        ]);
    }
}
