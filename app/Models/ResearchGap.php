<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $run_id
 * @property string $title
 * @property string $category
 * @property string $description
 * @property string|null $rationale
 * @property string|null $proposed_question
 * @property string|null $suggested_methodology
 * @property array<int|string, mixed>|null $supporting_evidence
 * @property string $review_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read ResearchRun $run
 */
#[Fillable(['project_id', 'run_id', 'title', 'category', 'description', 'rationale', 'proposed_question', 'suggested_methodology', 'supporting_evidence', 'review_status'])]
class ResearchGap extends Model
{
    protected $attributes = [
        'review_status' => 'needs_human_review',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supporting_evidence' => 'array',
        ];
    }

    /** @return BelongsTo<ResearchProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class, 'project_id');
    }

    /** @return BelongsTo<ResearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'run_id');
    }
}
