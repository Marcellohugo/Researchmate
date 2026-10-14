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
 * @property float|null $relevance_score
 * @property bool $is_selected
 * @property string $analysis_status
 * @property array<string, mixed>|null $metadata_overrides
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read Paper $paper
 */
#[Fillable(['project_id', 'paper_id', 'relevance_score', 'is_selected', 'analysis_status', 'metadata_overrides'])]
class ProjectPaper extends Model
{
    protected $attributes = [
        'is_selected' => false,
        'analysis_status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relevance_score' => 'float',
            'is_selected' => 'boolean',
            'metadata_overrides' => 'array',
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
}
