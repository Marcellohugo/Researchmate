<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $status
 * @property string $current_stage
 * @property array<string, mixed>|null $research_plan
 * @property array<string, mixed>|null $workflow_state
 * @property int $retry_count
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read Collection<int, AgentStep> $steps
 * @property-read Collection<int, ResearchGap> $gaps
 * @property-read Collection<int, Claim> $claims
 * @property-read Collection<int, ResearchArtifact> $artifacts
 */
#[Fillable(['project_id', 'status', 'current_stage', 'research_plan', 'workflow_state', 'retry_count', 'started_at', 'finished_at'])]
class ResearchRun extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'current_stage' => 'pending',
        'retry_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'research_plan' => 'array',
            'workflow_state' => 'array',
            'retry_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ResearchProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class, 'project_id');
    }

    /** @return HasMany<AgentStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class, 'run_id');
    }

    /** @return HasMany<ResearchGap, $this> */
    public function gaps(): HasMany
    {
        return $this->hasMany(ResearchGap::class, 'run_id');
    }

    /** @return HasMany<Claim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'run_id');
    }

    /** @return HasMany<ResearchArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ResearchArtifact::class, 'run_id');
    }
}
