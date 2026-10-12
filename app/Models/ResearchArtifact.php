<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $run_id
 * @property string|null $title
 * @property string $artifact_type
 * @property string $storage_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read ResearchRun|null $run
 */
#[Fillable(['project_id', 'run_id', 'title', 'artifact_type', 'storage_path'])]
class ResearchArtifact extends Model
{
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
