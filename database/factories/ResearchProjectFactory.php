<?php

namespace Database\Factories;

use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResearchProject> */
class ResearchProjectFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Pengaruh AI generatif terhadap berpikir kritis',
            'research_question' => 'Bagaimana AI generatif memengaruhi berpikir kritis mahasiswa?',
            'topic' => 'AI dalam pendidikan',
            'language' => 'id',
            'research_mode' => 'offline',
            'target_papers' => 20,
            'status' => 'draft',
        ];
    }
}
