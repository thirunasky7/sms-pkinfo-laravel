<div class="card" style="display:flex;flex-wrap:wrap;gap:.5rem;padding:.75rem">
    @foreach([
        'overview' => 'Overview',
        'connection' => 'Connection',
        'messages' => 'Messages',
        'incoming' => 'Incoming',
        'templates' => 'Templates',
        'rules' => 'Rules',
        'webhooks' => 'Webhooks',
        'docs' => 'API Documentation',
    ] as $route => $label)
        <a href="{{ route('customer.whatsapp.'.$route) }}"
           class="btn {{ request()->routeIs('customer.whatsapp.'.$route.'*') ? '' : 'btn-secondary' }}">{{ $label }}</a>
    @endforeach
</div>
