<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $run_id
 * @property string $agent_name
 * @property string $step_name
 * @property string $status
 * @property array<string, mixed>|null $input_payload
 * @property array<string, mixed>|null $output_payload
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchRun $run
 */
#[Fillable(['run_id', 'agent_name', 'step_name', 'status', 'input_payload', 'output_payload', 'error_message', 'duration_ms', 'started_at', 'completed_at'])]
class AgentStep extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ResearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'run_id');
    }
}
