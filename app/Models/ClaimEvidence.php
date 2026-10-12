<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $claim_id
 * @property int $paper_id
 * @property int|null $document_id
 * @property int|null $chunk_id
 * @property string $evidence_text
 * @property string $support_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Claim $claim
 * @property-read Paper $paper
 * @property-read Document|null $document
 * @property-read PaperChunk|null $chunk
 */
#[Fillable(['claim_id', 'paper_id', 'document_id', 'chunk_id', 'evidence_text', 'support_type'])]
class ClaimEvidence extends Model
{
    protected $table = 'claim_evidence';

    /** @return BelongsTo<Claim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    /** @return BelongsTo<Paper, $this> */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class, 'paper_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return BelongsTo<PaperChunk, $this> */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(PaperChunk::class, 'chunk_id');
    }
}
