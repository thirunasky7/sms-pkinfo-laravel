@extends('layouts.app')
@section('title', 'Edit Template')
@section('heading', 'Edit template: '.$template->name.' ('.$template->language.')')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    @if($template->account?->isCloudApi())
        <p style="color:var(--muted);font-size:.85rem">Saving sends the changes to Meta; the template returns to <b>pending</b> until Meta reviews it again.</p>
    @endif
    <form method="POST" action="{{ route('customer.whatsapp.templates.update', $template) }}">
        @csrf @method('PUT')
        @include('admin.customer.whatsapp._template-fields', ['template' => $template])
        <button class="btn" type="submit" style="margin-top:1rem">Save</button>
        <a class="btn btn-secondary" href="{{ route('customer.whatsapp.templates') }}">Cancel</a>
    </form>
</div>
@endsection
