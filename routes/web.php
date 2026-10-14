<?php

use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\LiteratureController;
use App\Http\Controllers\ResearchApiController;
use App\Http\Controllers\ResearchChatController;
use App\Http\Controllers\ResearchProjectController;
use App\Http\Controllers\ResearchResultsController;
use App\Http\Controllers\ResearchWorkflowController;
use App\Models\ResearchProject;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('api/projects/{project}/status', [ResearchApiController::class, 'status'])->middleware('throttle:60,1')->name('api.projects.status');
    Route::resource('projects', ResearchProjectController::class);
    Route::post('projects/{project}/duplicate', [ResearchProjectController::class, 'duplicate'])->name('projects.duplicate');
    Route::get('settings/ai', [AiSettingsController::class, 'index'])->name('ai.settings');
    Route::post('settings/ai/check', [AiSettingsController::class, 'check'])->middleware('throttle:10,1')->name('ai.check');
    Route::prefix('projects/{project}')->name('projects.')->scopeBindings()->group(function () {
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:20,1')->name('documents.store');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        Route::post('documents/{document}/reindex', [DocumentController::class, 'reindex'])->name('documents.reindex');
        Route::get('literature', [LiteratureController::class, 'index'])->name('literature.index');
        Route::post('literature/search', [LiteratureController::class, 'search'])->middleware('throttle:10,1')->name('literature.search');
        Route::get('literature/references', [LiteratureController::class, 'references'])->name('literature.references');
        Route::get('literature/{paper}/edit', [LiteratureController::class, 'edit'])->name('literature.edit');
        Route::put('literature/{paper}', [LiteratureController::class, 'update'])->name('literature.update');
        Route::post('literature/select', [ResearchWorkflowController::class, 'select'])->name('literature.select');
        Route::get('matrix', [ResearchResultsController::class, 'matrix'])->name('matrix');
        Route::get('gaps', [ResearchResultsController::class, 'gaps'])->name('gaps');
        Route::get('reports', [ResearchResultsController::class, 'reports'])->name('reports');
        Route::post('reports', [ResearchResultsController::class, 'export'])->name('reports.export');
        Route::get('reports/{artifact}', [ResearchResultsController::class, 'download'])->name('reports.download');
        Route::get('evidence/{chunk}', [ResearchResultsController::class, 'evidence'])->withoutScopedBindings()->name('evidence');
        Route::get('agents', function (ResearchProject $project) {
            Gate::authorize('view', $project);

            return view('research.agents', compact('project'));
        })->name('agents');
        Route::get('chat', [ResearchChatController::class, 'index'])->name('chat.index');
        Route::post('chat', [ResearchChatController::class, 'store'])->middleware('throttle:10,1')->name('chat.store');
        foreach (['start', 'resume', 'approve', 'cancel'] as $action) {
            Route::post('workflow/'.$action, [ResearchWorkflowController::class, $action])->name('workflow.'.$action);
        }
    });
});

require __DIR__.'/settings.php';
