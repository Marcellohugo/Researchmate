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
 * @property int|null $paper_id
 * @property string $original_filename
 * @property string $storage_path
 * @property string $mime_type
 * @property int $file_size
 * @property string $file_hash
 * @property string $extraction_status
 * @property int|null $page_count
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResearchProject $project
 * @property-read Paper|null $paper
 * @property-read Collection<int, PaperChunk> $chunks
 * @property-read Collection<int, ClaimEvidence> $evidence
 */
#[Fillable(['project_id', 'paper_id', 'original_filename', 'storage_path', 'mime_type', 'file_size', 'file_hash', 'extraction_status', 'page_count', 'error_message'])]
class Document extends Model
{
    protected $attributes = [
        'extraction_status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'page_count' => 'integer',
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

    /** @return HasMany<PaperChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(PaperChunk::class, 'document_id');
    }

    /** @return HasMany<ClaimEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class, 'document_id');
    }
}
