<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ResearchProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ResearchApiController extends Controller
{
    public function status(ResearchProject $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $run = $project->runs()->latest('id')->first();

        return response()->json([
            'project' => $project->only(['id', 'title', 'status', 'research_mode']),
            'run' => $run?->only(['id', 'status', 'current_stage', 'retry_count', 'started_at', 'finished_at']),
            'steps' => $run?->steps()->orderBy('id')->get(['id', 'agent_name', 'step_name', 'status', 'error_message', 'duration_ms', 'started_at', 'completed_at']) ?? [],
            'documents' => $project->documents()->orderBy('id')->get(['id', 'original_filename', 'extraction_status', 'page_count', 'error_message']),
        ]);
    }
}
