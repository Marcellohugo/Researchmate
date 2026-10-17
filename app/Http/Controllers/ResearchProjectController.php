<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Jobs\PruneVectorIndex;
use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ResearchProjectController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ResearchProject::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'section' => ['nullable', 'in:literature.index,documents.index,matrix,gaps,agents,chat.index,reports']]);
        $section = $filters['section'] ?? 'show';
        $search = $filters['search'] ?? '';
        $projects = $user->projects()->withCount(['papers', 'documents', 'runs'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('title', '%'.$search.'%')->orWhereLike('research_question', '%'.$search.'%')))
            ->latest('updated_at')->paginate(12)->withQueryString();

        return view('projects.index', compact('projects', 'filters', 'section'));
    }

    public function create(): View
    {
        Gate::authorize('create', ResearchProject::class);

        return view('projects.create');
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $project = DB::transaction(function () use ($request, $user): ResearchProject {
            $project = $user->projects()->create($request->validated());
            $project->history()->create([
                'user_id' => $user->id,
                'action' => 'created',
                'payload' => $request->validated(),
            ]);

            return $project;
        });

        return to_route('projects.show', $project)->with('status', 'Proyek riset berhasil dibuat.');
    }

    public function show(ResearchProject $project): View
    {
        Gate::authorize('view', $project);
        $project->loadCount(['papers', 'documents', 'runs', 'gaps', 'artifacts'])
            ->load(['runs' => fn ($query) => $query->latest()->limit(10), 'history' => fn ($query) => $query->latest()->limit(20)]);

        return view('projects.show', compact('project'));
    }

    public function edit(ResearchProject $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', compact('project'));
    }

    public function update(ProjectRequest $request, ResearchProject $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        DB::transaction(function () use ($request, $project, $user): void {
            $locked = ResearchProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($locked->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['workflow' => 'Pengaturan proyek tidak dapat diubah saat alur riset masih berjalan.']);
            }
            $previous = $locked->only(array_keys($request->validated()));
            $locked->update($request->validated());
            $locked->history()->create([
                'user_id' => $user->id,
                'action' => 'updated',
                'payload' => ['before' => $previous, 'after' => $request->validated()],
            ]);
        });

        return to_route('projects.show', $project)->with('status', 'Proyek riset berhasil diperbarui.');
    }

    public function destroy(ResearchProject $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        DB::transaction(function () use ($project): void {
            $paths = $project->documents()->pluck('storage_path')->merge($project->artifacts()->pluck('storage_path'))->all();
            $project->delete();
            PruneVectorIndex::dispatch($project->id)->afterCommit();
            DB::afterCommit(function () use ($paths): void {
                foreach ($paths as $path) {
                    if (! Storage::disk('local')->delete($path)) {
                        Log::warning('Berkas proyek yang dihapus belum dapat dibersihkan.', ['storage_path' => $path]);
                    }
                }
            });
        });

        return to_route('projects.index')->with('status', 'Proyek riset berhasil dihapus.');
    }

    public function duplicate(Request $request, ResearchProject $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $copy = DB::transaction(function () use ($project, $user): ResearchProject {
            $copy = $project->replicate()->setRelations([]);
            $copy->title = mb_substr($project->title, 0, 240).' (salinan)';
            $copy->status = 'draft';
            $copy->save();
            $copy->history()->create([
                'user_id' => $user->id,
                'action' => 'duplicated',
                'payload' => ['source_project_id' => $project->id],
            ]);

            return $copy;
        });

        return to_route('projects.show', $copy)->with('status', 'Pengaturan proyek berhasil disalin tanpa dokumen atau hasil riset.');
    }
}
