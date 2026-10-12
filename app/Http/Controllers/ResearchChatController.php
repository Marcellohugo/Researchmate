<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AnswerResearchQuestion;
use App\Models\ResearchMessage;
use App\Models\ResearchProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ResearchChatController extends Controller
{
    public function index(ResearchProject $project): View
    {
        Gate::authorize('view', $project);

        return view('chat.index', ['project' => $project, 'messages' => $project->messages()->latest('id')->limit(100)->get()->reverse()]);
    }

    public function store(Request $request, ResearchProject $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $input = $request->validate(['question' => ['required', 'string', 'max:4000']]);
        $answer = DB::transaction(function () use ($project, $input): ResearchMessage {
            $project->messages()->create(['role' => 'user', 'content' => $input['question'], 'status' => 'completed', 'source_references' => []]);

            return $project->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'pending', 'source_references' => []]);
        });
        AnswerResearchQuestion::dispatch($answer->id, $input['question'])->afterCommit();

        return to_route('projects.chat.index', $project)->with('status', 'Pertanyaan masuk antrean. Jawaban akan menyertakan sumber dari PDF proyek.');
    }
}
