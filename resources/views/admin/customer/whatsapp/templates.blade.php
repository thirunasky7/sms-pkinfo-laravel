@extends('layouts.app')
@section('title', 'WhatsApp Templates')
@section('heading', 'WhatsApp Templates')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">Templates</h3>
        <form method="POST" action="{{ route('customer.whatsapp.templates.sync') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Sync from Meta</button>
        </form>
    </div>
    <table style="margin-top:1rem">
        <thead><tr><th>Name</th><th>Language</th><th>Category</th><th>Account</th><th>Status</th><th>Variables</th><th>Used</th><th></th></tr></thead>
        <tbody>
        @forelse($templates as $t)
            <tr>
                <td>{{ $t->name }}<div style="color:var(--muted);font-size:.8rem">{{ \Illuminate\Support\Str::limit($t->body, 70) }}</div></td>
                <td>{{ $t->language }}</td>
                <td>{{ $t->category }}</td>
                <td>{{ $t->account->name ?? '—' }}<div style="color:var(--muted);font-size:.75rem">{{ $t->account?->connector_type }}</div></td>
                <td>
                    <span class="badge {{ $t->status === 'approved' ? 'ok' : (in_array($t->status, ['rejected','disabled','paused']) ? 'bad' : 'warn') }}">{{ $t->status }}</span>
                    @if($t->rejection_reason)<div style="color:var(--muted);font-size:.75rem">{{ $t->rejection_reason }}</div>@endif
                </td>
                <td>{{ $t->bodyVariableCount() }}</td>
                <td>{{ $t->usage_count }}</td>
                <td style="white-space:nowrap">
                    <a class="btn btn-secondary" href="{{ route('customer.whatsapp.templates.edit', $t) }}">Edit</a>
                    <form method="POST" action="{{ route('customer.whatsapp.templates.destroy', $t) }}" style="display:inline"
                          onsubmit="return confirm('Delete this template{{ $t->account?->isCloudApi() ? ' (it will also be deleted on Meta)' : '' }}?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No templates yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h3>New template</h3>
    @if($accounts->isEmpty())
        <p style="color:var(--muted)">Connect a WhatsApp number first.</p>
    @else
        <p style="color:var(--muted);font-size:.85rem">Cloud API templates are submitted to Meta for review; Meta decides approval. Device templates are local presets and usable immediately.</p>
        <form method="POST" action="{{ route('customer.whatsapp.templates.store') }}">
            @csrf
            <div class="grid">
                <div>
                    <label>Account</label>
                    <select name="account_id" required>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->name }} ({{ $a->connector_type }})</option>
                        @endforeach
                    </select>
                </div>
                <div><label>Name (lowercase, digits, underscores)</label><input name="name" required pattern="[a-z0-9_]+" value="{{ old('name') }}"></div>
                <div><label>Language</label><input name="language" required value="{{ old('language', 'en_US') }}"></div>
            </div>
            @include('admin.customer.whatsapp._template-fields')
            <button class="btn" type="submit" style="margin-top:1rem">Create template</button>
        </form>
    @endif
</div>
@endsection
