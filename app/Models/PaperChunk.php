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
 * @property int $document_id
 * @property int $chunk_index
 * @property int|null $page_number
 * @property string|null $section_name
 * @property string $content
 * @property string|null $embedding
 * @property string|null $embedding_model
 * @property int|null $embedding_dimensions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 * @property-read Collection<int, ClaimEvidence> $evidence
 */
#[Fillable(['document_id', 'chunk_index', 'page_number', 'section_name', 'content', 'embedding', 'embedding_model', 'embedding_dimensions'])]
class PaperChunk extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_number' => 'integer',
            'embedding_dimensions' => 'integer',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return HasMany<ClaimEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class, 'chunk_id');
    }
}
