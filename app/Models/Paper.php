<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string|null $doi
 * @property string $title
 * @property string|null $abstract
 * @property array<int, mixed>|null $authors
 * @property int|null $publication_year
 * @property string|null $journal_name
 * @property string|null $source_url
 * @property string|null $openalex_id
 * @property string $metadata_source
 * @property string $metadata_verification_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ResearchProject> $projects
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, PaperAnalysis> $analyses
 * @property-read Collection<int, ClaimEvidence> $evidence
 */
#[Fillable(['doi', 'title', 'abstract', 'authors', 'publication_year', 'journal_name', 'source_url', 'openalex_id', 'metadata_source', 'metadata_verification_status'])]
class Paper extends Model
{
    protected $attributes = [
        'metadata_source' => 'upload',
        'metadata_verification_status' => 'metadata_unverified',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'authors' => 'array',
            'publication_year' => 'integer',
        ];
    }

    /** @return Attribute<string|null, string|null> */
    protected function doi(): Attribute
    {
        return Attribute::make(set: function (?string $value): ?string {
            if ($value === null) {
                return null;
            }

            $normalized = Str::lower(trim(preg_replace('/^(?:https?:\/\/(?:dx\.)?doi\.org\/|doi:\s*)/i', '', trim($value)) ?? $value));

            return $normalized === '' ? null : $normalized;
        });
    }

    /** @return BelongsToMany<ResearchProject, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(ResearchProject::class, 'project_papers', 'paper_id', 'project_id')->withPivot(['id', 'is_selected', 'relevance_score', 'analysis_status'])->withTimestamps();
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'paper_id');
    }

    /** @return HasMany<PaperAnalysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(PaperAnalysis::class, 'paper_id');
    }

    /** @return HasMany<ClaimEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class, 'paper_id');
    }
}
