<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Paper;
use App\Models\ResearchProject;
use App\Services\Documents\EvidenceInvalidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function index(ResearchProject $project): View
    {
        Gate::authorize('view', $project);

        return view('documents.index', ['project' => $project, 'documents' => $project->documents()->with('paper')->latest()->paginate(15)]);
    }

    public function store(Request $request, ResearchProject $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.((int) config('ai.max_pdf_mb', 20) * 1024)]]);
        $file = $request->file('pdf');
        if ($file === null || ! in_array($file->getMimeType(), ['application/pdf', 'application/x-pdf'], true)
            || file_get_contents($file->getPathname(), false, null, 0, 5) !== '%PDF-') {
            throw ValidationException::withMessages(['pdf' => 'Berkas harus berupa PDF yang valid.']);
        }
        $hash = hash_file('sha256', $file->getPathname());
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw ValidationException::withMessages(['pdf' => 'Nama berkas tidak valid atau melebihi 255 karakter.']);
        }
        $path = $file->store('projects/'.$project->id.'/documents', 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['pdf' => 'Berkas tidak dapat disimpan di penyimpanan privat.']);
        }
        try {
            $document = DB::transaction(function () use ($project, $file, $hash, $name, $path): Document {
                $locked = ResearchProject::query()->lockForUpdate()->findOrFail($project->id);
                if ($locked->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                    throw ValidationException::withMessages(['workflow' => 'PDF tidak dapat diubah saat alur riset masih berjalan.']);
                }
                if ($locked->documents()->where('file_hash', $hash)->exists()) {
                    throw ValidationException::withMessages(['pdf' => 'PDF yang sama sudah ada di proyek ini.']);
                }
                if ($locked->papers()->count() >= (int) config('ai.max_papers', 20)) {
                    throw ValidationException::withMessages(['pdf' => 'Batas jumlah artikel proyek telah tercapai.']);
                }
                $paper = Paper::query()->create([
                    'title' => pathinfo($name, PATHINFO_FILENAME) ?: $name, 'authors' => [],
                    'metadata_source' => 'uploaded_filename', 'metadata_verification_status' => 'metadata_unverified',
                ]);
                $locked->papers()->attach($paper->id, ['is_selected' => true, 'analysis_status' => 'pending']);

                return $locked->documents()->create([
                    'paper_id' => $paper->id, 'original_filename' => $name, 'storage_path' => $path,
                    'mime_type' => 'application/pdf', 'file_size' => $file->getSize(), 'file_hash' => $hash,
                    'extraction_status' => 'queued',
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        ProcessDocument::dispatch($document->id)->afterCommit();

        return to_route('projects.documents.index', $project)->with('status', 'PDF tersimpan privat dan masuk antrean indeks. Judul sementara berasal dari nama berkas; metadata belum diverifikasi.');
    }

    public function show(ResearchProject $project, Document $document): StreamedResponse
    {
        $this->authorizeDocument($project, $document, 'view');
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path, $document->original_filename, ['Content-Type' => 'application/pdf']);
    }

    public function destroy(ResearchProject $project, Document $document, EvidenceInvalidationService $evidenceInvalidation): RedirectResponse
    {
        $this->authorizeDocument($project, $document, 'update');
        $path = $document->storage_path;
        DB::transaction(function () use ($project, $document, $path, $evidenceInvalidation): void {
            $locked = ResearchProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($locked->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['workflow' => 'PDF tidak dapat diubah saat alur riset masih berjalan.']);
            }
            $lockedDocument = Document::query()->lockForUpdate()->where('project_id', $project->id)->findOrFail($document->id);
            $evidenceInvalidation->invalidate($lockedDocument);
            $lockedDocument->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($path));
        });

        return to_route('projects.documents.index', $project)->with('status', 'PDF dan indeks teks dihapus dari proyek. Metadata artikel tetap tersimpan.');
    }

    public function reindex(ResearchProject $project, Document $document): RedirectResponse
    {
        $this->authorizeDocument($project, $document, 'update');
        $queued = DB::transaction(function () use ($project, $document): bool {
            $locked = ResearchProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($locked->runs()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['workflow' => 'PDF tidak dapat diubah saat alur riset masih berjalan.']);
            }
            $lockedDocument = Document::query()->lockForUpdate()->where('project_id', $project->id)->findOrFail($document->id);
            if (in_array($lockedDocument->extraction_status, ['processing', 'queued'], true)) {
                return false;
            }
            $lockedDocument->update(['extraction_status' => 'queued', 'error_message' => null]);

            return true;
        });
        if (! $queued) {
            return back()->with('status', 'Dokumen sedang diproses atau sudah ada dalam antrean.');
        }
        ProcessDocument::dispatch($document->id)->afterCommit();

        return to_route('projects.documents.index', $project)->with('status', 'Dokumen masuk antrean indeks ulang.');
    }

    private function authorizeDocument(ResearchProject $project, Document $document, string $ability): void
    {
        Gate::authorize($ability, $project);
        abort_unless($document->project_id === $project->id, 404);
    }
}
