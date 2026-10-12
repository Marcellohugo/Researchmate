@php($editing = isset($project))
<form method="POST" action="{{ $editing ? route('projects.update', $project) : route('projects.store') }}" class="rm-panel space-y-6 p-5 sm:p-7">
    @csrf @if($editing) @method('PUT') @endif
    <div class="grid gap-5 sm:grid-cols-2">
        @foreach (['title' => 'Judul riset', 'topic' => 'Topik penelitian', 'discipline' => 'Disiplin ilmu', 'keywords' => 'Kata kunci'] as $field => $label)
            <div class="{{ $field === 'title' ? 'sm:col-span-2' : '' }}"><label for="{{ $field }}" class="rm-label">{{ $label }} {{ in_array($field, ['title', 'topic']) ? '*' : '' }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $editing ? $project->$field : '') }}" class="rm-input" @if(in_array($field, ['title','topic'])) required @endif maxlength="{{ $field === 'keywords' ? 2000 : 255 }}">@error($field)<p class="rm-error">{{ $message }}</p>@enderror</div>
        @endforeach
        @foreach (['research_question' => 'Pertanyaan penelitian', 'description' => 'Deskripsi', 'objectives' => 'Tujuan penelitian'] as $field => $label)
            <div class="sm:col-span-2"><label for="{{ $field }}" class="rm-label">{{ $label }} {{ $field === 'research_question' ? '*' : '' }}</label><textarea id="{{ $field }}" name="{{ $field }}" rows="{{ $field === 'research_question' ? 3 : 4 }}" class="rm-input" maxlength="10000" @if($field === 'research_question') required @endif>{{ old($field, $editing ? $project->$field : '') }}</textarea>@error($field)<p class="rm-error">{{ $message }}</p>@enderror</div>
        @endforeach
        @foreach (['year_from' => 'Tahun publikasi mulai', 'year_to' => 'Tahun publikasi akhir'] as $field => $label)
            <div><label for="{{ $field }}" class="rm-label">{{ $label }}</label><input type="number" id="{{ $field }}" name="{{ $field }}" min="1900" max="{{ date('Y') + 1 }}" value="{{ old($field, $editing ? $project->$field : '') }}" class="rm-input">@error($field)<p class="rm-error">{{ $message }}</p>@enderror</div>
        @endforeach
        <div><label for="language" class="rm-label">Bahasa hasil</label><select id="language" name="language" class="rm-input">@foreach(['id' => 'Bahasa Indonesia', 'en' => 'Bahasa Inggris'] as $value => $label)<option value="{{ $value }}" @selected(old('language', $editing ? $project->language : 'id') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="target_papers" class="rm-label">Target jumlah artikel *</label><input type="number" id="target_papers" name="target_papers" class="rm-input" min="1" max="{{ config('ai.max_papers') }}" value="{{ old('target_papers', $editing ? $project->target_papers : config('ai.max_papers')) }}" required></div>
        <div class="sm:col-span-2"><label for="research_mode" class="rm-label">Mode riset</label><select id="research_mode" name="research_mode" class="rm-input"><option value="offline" @selected(old('research_mode', $editing ? $project->research_mode : 'offline') === 'offline')>{{ 'Dokumen lokal' }} — dokumen PDF lokal</option><option value="online" @selected(old('research_mode', $editing ? $project->research_mode : 'offline') === 'online')>Online — penelusuran metadata OpenAlex</option></select><p class="mt-2 text-xs leading-5 text-muted">{{ 'Inferensi Senopati memerlukan internet dan menerima pertanyaan serta cuplikan sumber. Dokumen lokal tidak memakai OpenAlex/Crossref.' }}</p></div>
    </div>
    <div class="flex flex-wrap gap-3 border-t border-line pt-5"><button class="rm-primary-link">{{ $editing ? 'Simpan perubahan' : 'Buat proyek' }}</button><a href="{{ $editing ? route('projects.show', $project) : route('projects.index') }}" class="rm-secondary-link" wire:navigate>Batal</a></div>
</form>
