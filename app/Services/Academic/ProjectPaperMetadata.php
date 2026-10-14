<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Paper;
use App\Models\ResearchProject;

class ProjectPaperMetadata
{
    /** Return a display/prompt copy. Persist edits only on project_papers. */
    public function apply(Paper $paper, ResearchProject $project): Paper
    {
        $metadata = $project->projectPapers()->where('paper_id', $paper->id)->first()->metadata_overrides ?? [];
        $effective = new Paper;
        $effective->setRawAttributes($paper->getAttributes(), true);
        $effective->setRelations($paper->getRelations());
        $effective->forceFill($metadata);

        return $effective;
    }
}
