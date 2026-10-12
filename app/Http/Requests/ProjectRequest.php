<?php

namespace App\Http\Requests;

use App\Models\ResearchProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof ResearchProject
            ? ($this->user()?->can('update', $project) ?? false)
            : ($this->user()?->can('create', ResearchProject::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'research_question' => ['required', 'string', 'max:10000'],
            'topic' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'objectives' => ['nullable', 'string', 'max:10000'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'discipline' => ['nullable', 'string', 'max:255'],
            'language' => ['required', Rule::in(['id', 'en'])],
            'research_mode' => ['required', Rule::in(['offline', 'online'])],
            'year_from' => ['nullable', 'integer', 'between:1900,'.date('Y')],
            'year_to' => ['nullable', 'integer', 'between:1900,'.date('Y'), Rule::when($this->filled('year_from'), 'gte:year_from')],
            'target_papers' => ['required', 'integer', 'min:1', 'max:'.config('ai.max_papers', 20)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => 'judul riset',
            'research_question' => 'pertanyaan riset',
            'topic' => 'topik',
            'description' => 'deskripsi',
            'objectives' => 'tujuan riset',
            'keywords' => 'kata kunci',
            'discipline' => 'disiplin ilmu',
            'language' => 'bahasa',
            'research_mode' => 'mode riset',
            'year_from' => 'tahun awal',
            'year_to' => 'tahun akhir',
            'target_papers' => 'target artikel',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'integer' => ':attribute harus berupa bilangan bulat.',
            'between' => ':attribute harus berada antara :min dan :max.',
            'in' => 'Pilihan :attribute tidak valid.',
            'year_to.gte' => 'Tahun akhir tidak boleh lebih kecil dari tahun awal.',
        ];
    }
}
