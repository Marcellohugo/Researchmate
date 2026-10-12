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
 * @property int $run_id
 * @property string $claim_text
 * @property string $verification_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read ResearchRun $run
 * @property-read Collection<int, ClaimEvidence> $evidence
 */
#[Fillable(['project_id', 'run_id', 'claim_text', 'verification_status'])]
class Claim extends Model
{
    protected $attributes = [
        'verification_status' => 'insufficient_evidence',
    ];

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

    /** @return HasMany<ClaimEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class, 'claim_id');
    }
}
