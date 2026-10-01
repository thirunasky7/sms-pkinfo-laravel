@php($t = $template ?? null)
<div class="grid">
    <div>
        <label>Category</label>
        <select name="category" required>
            @foreach(\App\Models\WhatsAppTemplate::CATEGORIES as $c)
                <option value="{{ $c }}" @selected(old('category', $t?->category) === $c)>{{ ucfirst($c) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label>Header</label>
        <select name="header_type">
            @foreach(\App\Models\WhatsAppTemplate::HEADER_TYPES as $h)
                <option value="{{ $h }}" @selected(old('header_type', $t?->header_type ?? 'none') === $h)>{{ ucfirst($h) }}</option>
            @endforeach
        </select>
    </div>
</div>
<label>Header text (for text headers, max 60, may contain one @{{1}})</label>
<input name="header_text" maxlength="60" value="{{ old('header_text', $t?->header_text) }}">
<label>Header media URL (image/video/document headers, HTTPS)</label>
<input name="header_media_url" value="{{ old('header_media_url', $t?->header_media_url) }}" placeholder="https://...">
<label>Body (use @{{1}}, @{{2}} … for variables)</label>
<textarea name="body" rows="4" maxlength="1024" required>{{ old('body', $t?->body) }}</textarea>
<label>Example values for body variables, comma separated (required by Meta for Cloud API templates)</label>
<input name="example_body" value="{{ old('example_body', implode(', ', $t?->examples['body'] ?? [])) }}" placeholder="Sam, #1234">
<label>Footer (optional)</label>
<input name="footer" maxlength="60" value="{{ old('footer', $t?->footer) }}">
<details>
    <summary style="cursor:pointer;color:var(--muted)">Buttons (optional)</summary>
    @for($i = 0; $i < 3; $i++)
        @php($b = old('buttons.'.$i, $t?->buttons[$i] ?? []))
        <div class="grid" style="margin-top:.5rem">
            <div>
                <label>Type</label>
                <select name="buttons[{{ $i }}][type]">
                    <option value="">—</option>
                    @foreach(['quick_reply' => 'Quick reply', 'url' => 'URL', 'phone_number' => 'Phone', 'copy_code' => 'Copy code'] as $v => $l)
                        <option value="{{ $v }}" @selected(($b['type'] ?? '') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Text</label><input name="buttons[{{ $i }}][text]" maxlength="25" value="{{ $b['text'] ?? '' }}"></div>
            <div><label>URL / phone / example code</label><input name="buttons[{{ $i }}][value]" value="{{ $b['value'] ?? $b['url'] ?? $b['phone_number'] ?? $b['example'] ?? '' }}"></div>
        </div>
    @endfor
</details>
