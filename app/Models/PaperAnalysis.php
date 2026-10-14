<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $paper_id
 * @property int|null $run_id
 * @property array<string, mixed> $structured_analysis
 * @property string $evidence_level
 * @property string $analysis_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read Paper $paper
 * @property-read ResearchRun|null $run
 */
#[Fillable(['project_id', 'paper_id', 'run_id', 'structured_analysis', 'evidence_level', 'analysis_model'])]
class PaperAnalysis extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'structured_analysis' => 'array',
        ];
    }

    /** @return BelongsTo<ResearchProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class, 'project_id');
    }

    /** @return BelongsTo<Paper, $this> */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class, 'paper_id');
    }

    /** @return BelongsTo<ResearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'run_id');
    }
}
