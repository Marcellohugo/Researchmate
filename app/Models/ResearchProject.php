<?php

namespace App\Models;

use Database\Factories\ResearchProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $research_question
 * @property string $topic
 * @property string|null $description
 * @property string|null $objectives
 * @property string|null $keywords
 * @property string|null $discipline
 * @property int|null $year_from
 * @property int|null $year_to
 * @property string $language
 * @property string $research_mode
 * @property int $target_papers
 * @property array<string, mixed>|null $settings
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Paper> $papers
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, ResearchRun> $runs
 * @property-read Collection<int, PaperAnalysis> $analyses
 * @property-read Collection<int, ResearchGap> $gaps
 * @property-read Collection<int, ResearchMessage> $messages
 * @property-read Collection<int, ProjectHistory> $history
 * @property-read Collection<int, ResearchArtifact> $artifacts
 * @property-read Collection<int, Claim> $claims
 * @property-read Collection<int, ProjectPaper> $projectPapers
 */
#[Fillable(['user_id', 'title', 'research_question', 'topic', 'description', 'objectives', 'keywords', 'discipline', 'year_from', 'year_to', 'language', 'research_mode', 'target_papers', 'settings', 'status'])]
class ResearchProject extends Model
{
    /** @use HasFactory<ResearchProjectFactory> */
    use HasFactory;

    protected $attributes = [
        'language' => 'id',
        'research_mode' => 'offline',
        'target_papers' => 20,
        'status' => 'draft',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to' => 'integer',
            'target_papers' => 'integer',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<Paper, $this> */
    public function papers(): BelongsToMany
    {
        return $this->belongsToMany(Paper::class, 'project_papers', 'project_id', 'paper_id')->withPivot(['id', 'is_selected', 'relevance_score', 'analysis_status'])->withTimestamps();
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id');
    }

    /** @return HasMany<ResearchRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ResearchRun::class, 'project_id');
    }

    /** @return HasMany<PaperAnalysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(PaperAnalysis::class, 'project_id');
    }

    /** @return HasMany<ResearchGap, $this> */
    public function gaps(): HasMany
    {
        return $this->hasMany(ResearchGap::class, 'project_id');
    }

    /** @return HasMany<ResearchMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ResearchMessage::class, 'project_id');
    }

    /** @return HasMany<ProjectHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ProjectHistory::class, 'project_id');
    }

    /** @return HasMany<ResearchArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ResearchArtifact::class, 'project_id');
    }

    /** @return HasMany<Claim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'project_id');
    }

    /** @return HasMany<ProjectPaper, $this> */
    public function projectPapers(): HasMany
    {
        return $this->hasMany(ProjectPaper::class, 'project_id');
    }
}
