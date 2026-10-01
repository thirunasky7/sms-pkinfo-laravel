<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AutomationRule;
use App\Models\ContactOptOut;
use App\Models\Device;
use App\Models\Webhook;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\MessageRouter;
use App\Services\WhatsApp\AccountService;
use App\Services\WhatsApp\Meta\CloudOnboardingService;
use App\Services\WhatsApp\TemplateService;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppMessageService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WhatsAppController extends Controller
{
    protected function accountId(): int
    {
        return Auth::user()->ownsAccountId();
    }

    protected function actorId(): int
    {
        return (int) Auth::id();
    }

    /**
     * Dashboard > WhatsApp > Overview: totals and reporting.
     */
    public function overview(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'account' => 'nullable|integer',
        ]);

        $from = $request->from ? Carbon::parse($request->from)->startOfDay() : now()->subDays(29)->startOfDay();
        $to = $request->to ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        $base = fn () => WhatsAppMessage::query()
            ->where('user_id', $this->accountId())
            ->whereBetween('created_at', [$from, $to])
            ->when($request->account, fn ($q, $id) => $q->where('whatsapp_account_id', $id));

        $byStatus = $base()->where('direction', 'outgoing')
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        $outgoing = (int) $byStatus->sum();
        $sent = (int) (($byStatus['sent'] ?? 0) + ($byStatus['delivered'] ?? 0) + ($byStatus['read'] ?? 0));

        $stats = [
            'total' => $base()->count(),
            'outgoing' => $outgoing,
            'sent' => $sent,
            'delivered' => (int) (($byStatus['delivered'] ?? 0) + ($byStatus['read'] ?? 0)),
            'read' => (int) ($byStatus['read'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'pending' => (int) (($byStatus['queued'] ?? 0) + ($byStatus['sending'] ?? 0)),
            'received' => $base()->where('direction', 'incoming')->count(),
            'templates_used' => $base()->where('message_type', 'template')->count(),
        ];

        $byConnector = $base()->select('connector_type', 'direction', DB::raw('count(*) as total'))
            ->groupBy('connector_type', 'direction')->get();

        $apiKeyNames = ApiKey::where('user_id', $this->accountId())->pluck('name', 'id');
        $byApiKey = $base()->where('direction', 'outgoing')
            ->select('api_key_id', DB::raw('count(*) as total'))->groupBy('api_key_id')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->api_key_id ? ($apiKeyNames[$r->api_key_id] ?? 'Key #'.$r->api_key_id) : 'Dashboard / automation', 'total' => $r->total]);

        $byDate = $base()->select(DB::raw('DATE(created_at) as day'), 'direction', DB::raw('count(*) as total'))
            ->groupBy('day', 'direction')->orderBy('day')->get()
            ->groupBy('day')
            ->map(fn ($rows) => [
                'outgoing' => (int) $rows->firstWhere('direction', 'outgoing')?->total,
                'incoming' => (int) $rows->firstWhere('direction', 'incoming')?->total,
            ]);

        $failureReasons = $base()->where('status', 'failed')
            ->select('error_code', DB::raw('count(*) as total'), DB::raw('max(failure_reason) as reason'))
            ->groupBy('error_code')->orderByDesc('total')->limit(10)->get();

        $topTemplates = $base()->whereNotNull('whatsapp_template_id')
            ->select('whatsapp_template_id', DB::raw('count(*) as total'))
            ->groupBy('whatsapp_template_id')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['name' => WhatsAppTemplate::find($r->whatsapp_template_id)?->name ?? '#'.$r->whatsapp_template_id, 'total' => $r->total]);

        return view('admin.customer.whatsapp.overview', [
            'stats' => $stats,
            'byConnector' => $byConnector,
            'byApiKey' => $byApiKey,
            'byDate' => $byDate,
            'failureReasons' => $failureReasons,
            'topTemplates' => $topTemplates,
            'accounts' => WhatsAppAccount::forAccount($this->accountId())->whereNull('revoked_at')->get(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function connection(CloudOnboardingService $onboarding)
    {
        return view('admin.customer.whatsapp.connection', [
            'accounts' => WhatsAppAccount::forAccount($this->accountId())->whereNull('revoked_at')->with('device')->latest()->get(),
            'devices' => Device::where('user_id', $this->accountId())->whereNotIn('status', ['disabled'])->get(),
            'metaConfigured' => $onboarding->isConfigured(),
            'metaAppId' => config('whatsapp.meta.app_id'),
            'metaConfigId' => config('whatsapp.meta.config_id'),
            'graphVersion' => config('whatsapp.meta.graph_version'),
            'webhookUrl' => rtrim(config('app.url'), '/').'/api/v1/whatsapp/webhooks/meta',
        ]);
    }

    public function embeddedSignup(Request $request, CloudOnboardingService $onboarding)
    {
        $data = $request->validate([
            'code' => 'required|string|max:2048',
            'waba_id' => 'required|alpha_dash|max:64',
            'phone_number_id' => 'required|alpha_dash|max:64',
            'business_id' => 'nullable|alpha_dash|max:64',
            'pin' => 'nullable|digits:6',
        ]);

        $account = $onboarding->completeEmbeddedSignup($this->accountId(), $this->actorId(), $data);

        return response()->json(['account' => $account->toPublicArray()]);
    }

    public function manualConnect(Request $request, CloudOnboardingService $onboarding)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'waba_id' => 'required|string|max:64',
            'phone_number_id' => 'required|string|max:64',
            'access_token' => 'required|string|max:1024',
            'pin' => 'nullable|digits:6',
        ]);

        try {
            $onboarding->connectWithCredentials($this->accountId(), $this->actorId(), $data);
        } catch (WhatsAppException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return back()->withErrors(['whatsapp' => $e->getMessage()]);
        }

        return back()->with('success', 'Cloud API number connected.');
    }

    public function setDefault(WhatsAppAccount $account, AccountService $service)
    {
        $this->authorizeAccount($account);
        $service->setDefault($account);

        return back()->with('success', 'Default WhatsApp account updated.');
    }

    public function revoke(WhatsAppAccount $account, AccountService $service)
    {
        $this->authorizeAccount($account);
        $service->revoke($account, $this->actorId());

        return back()->with('success', 'WhatsApp account disconnected and credentials revoked.');
    }

    public function messages(Request $request)
    {
        return $this->messageList($request, 'outgoing', 'admin.customer.whatsapp.messages');
    }

    public function incoming(Request $request)
    {
        return $this->messageList($request, 'incoming', 'admin.customer.whatsapp.incoming');
    }

    public function send(Request $request, MessageRouter $router)
    {
        $data = $request->validate([
            'to' => 'required|string|max:32',
            'body' => 'required|string|max:4096',
            'account_id' => ['nullable', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('user_id', $this->accountId())],
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        try {
            $router->send(MessageRouter::CHANNEL_WHATSAPP, $this->accountId(), $data);
        } catch (WhatsAppException $e) {
            return back()->withErrors(['whatsapp' => $e->getMessage()])->withInput();
        }

        return back()->with('success', ! empty($data['scheduled_at']) ? 'WhatsApp message scheduled.' : 'WhatsApp message queued.');
    }

    public function retry(WhatsAppMessage $message, WhatsAppMessageService $service)
    {
        abort_unless((int) $message->user_id === $this->accountId(), 403);

        try {
            $service->retry($message);
        } catch (WhatsAppException $e) {
            return back()->withErrors(['whatsapp' => $e->getMessage()]);
        }

        return back()->with('success', 'Message re-queued.');
    }

    public function templates()
    {
        return view('admin.customer.whatsapp.templates', [
            'templates' => WhatsAppTemplate::where('user_id', $this->accountId())->with('account')->orderBy('name')->get(),
            'accounts' => WhatsAppAccount::forAccount($this->accountId())->usable()->get(),
        ]);
    }

    public function editTemplate(WhatsAppTemplate $template)
    {
        abort_unless((int) $template->user_id === $this->accountId(), 403);

        return view('admin.customer.whatsapp.template-edit', ['template' => $template]);
    }

    public function storeTemplate(Request $request, TemplateService $service)
    {
        $data = $this->normalizeTemplateInput($request);
        $data = validator($data, array_merge(TemplateService::rules(), [
            'account_id' => ['required', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('user_id', $this->accountId())],
        ]))->validate();

        $account = WhatsAppAccount::forAccount($this->accountId())->usable()->findOrFail($data['account_id']);

        try {
            $service->create($account, $data, $this->actorId());
        } catch (WhatsAppException $e) {
            return back()->withErrors(['whatsapp' => $e->getMessage()])->withInput();
        }

        return redirect()->route('customer.whatsapp.templates')->with('success', $account->isCloudApi()
            ? 'Template submitted to Meta for review.'
            : 'Template saved.');
    }

    public function updateTemplate(Request $request, WhatsAppTemplate $template, TemplateService $service)
    {
        abort_unless((int) $template->user_id === $this->accountId(), 403);

        $data = validator($this->normalizeTemplateInput($request), TemplateService::rules(true))->validate();

        try {
            $service->update($template, $data, $this->actorId());
        } catch (WhatsAppException $e) {
            return back()->withErrors(['whatsapp' => $e->getMessage()])->withInput();
        }

        return redirect()->route('customer.whatsapp.templates')->with('success', 'Template updated.');
    }

    public function destroyTemplate(WhatsAppTemplate $template, TemplateService $service)
    {
        abort_unless((int) $template->user_id === $this->accountId(), 403);

        try {
            $service->delete($template, $this->actorId());
        } catch (WhatsAppException $e) {
            return back()->withErrors(['whatsapp' => $e->getMessage()]);
        }

        return back()->with('success', 'Template deleted.');
    }

    public function syncTemplates(TemplateService $service)
    {
        $synced = 0;
        $errors = [];

        WhatsAppAccount::forAccount($this->accountId())->usable()
            ->where('connector_type', WhatsAppAccount::CONNECTOR_CLOUD_API)
            ->each(function (WhatsAppAccount $account) use ($service, &$synced, &$errors) {
                try {
                    $synced += $service->sync($account)['synced'];
                } catch (WhatsAppException $e) {
                    $errors[] = $account->name.': '.$e->getMessage();
                }
            });

        return back()->with('success', "Synced {$synced} template(s) from Meta.")->withErrors($errors);
    }

    public function rules()
    {
        return view('admin.customer.whatsapp.rules', [
            'rules' => AutomationRule::where('user_id', $this->accountId())->orderBy('priority')->orderBy('id')->get(),
            'accounts' => WhatsAppAccount::forAccount($this->accountId())->usable()->get(),
            'optOuts' => ContactOptOut::where('user_id', $this->accountId())->latest()->limit(50)->get(),
            'actions' => AutomationRule::ACTIONS,
        ]);
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'channel' => ['required', Rule::in(AutomationRule::CHANNELS)],
            'trigger' => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'match_type' => ['required', Rule::in(AutomationRule::MATCH_TYPES)],
            'keywords' => 'nullable|string|max:1000',
            'action' => ['required', Rule::in(array_keys(AutomationRule::ACTIONS))],
            'message' => 'nullable|string|max:1600',
            'to' => 'nullable|string|max:32',
            'url' => 'nullable|url|starts_with:https://|max:2048',
            'account_id' => ['nullable', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('user_id', $this->accountId())],
            'connector' => 'nullable|in:device,cloud_api',
            'scope' => 'nullable|in:whatsapp,sms,all',
            'priority' => 'nullable|integer|min:1|max:1000',
            'stop_processing' => 'nullable|boolean',
        ]);

        if (! in_array($data['trigger'], AutomationRule::ACTIONS[$data['action']], true)) {
            return back()->withErrors(['action' => 'This action cannot be used with the selected trigger.'])->withInput();
        }

        $required = [
            'auto_reply' => ['message'],
            'forward_webhook' => ['url'],
            'forward_whatsapp' => ['to'],
            'forward_sms' => ['to'],
        ][$data['action']] ?? [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return back()->withErrors([$field => "The {$field} field is required for this action."])->withInput();
            }
        }

        if ($data['match_type'] === 'regex') {
            foreach ($this->splitKeywords($data['keywords'] ?? '') as $pattern) {
                if (@preg_match('/'.str_replace('/', '\/', $pattern).'/iu', '') === false) {
                    return back()->withErrors(['keywords' => "Invalid pattern: {$pattern}"])->withInput();
                }
            }
        }

        $rule = AutomationRule::create([
            'user_id' => $this->accountId(),
            'name' => $data['name'],
            'channel' => $data['channel'],
            'trigger' => $data['trigger'],
            'match_type' => $data['trigger'] === 'failed' ? 'any' : $data['match_type'],
            'keywords' => $this->splitKeywords($data['keywords'] ?? ''),
            'action' => $data['action'],
            'action_config' => array_filter([
                'message' => $data['message'] ?? null,
                'to' => $data['to'] ?? null,
                'url' => $data['url'] ?? null,
                'secret' => $data['action'] === 'forward_webhook' ? Str::random(32) : null,
                'account_id' => $data['account_id'] ?? null,
                'connector' => $data['connector'] ?? null,
                'scope' => $data['scope'] ?? null,
            ]),
            'priority' => $data['priority'] ?? 100,
            'stop_processing' => (bool) ($data['stop_processing'] ?? false),
            'is_active' => true,
        ]);

        Audit::log($this->actorId(), 'automation.rule.created', $rule, ['action' => $rule->action]);

        return back()->with('success', $rule->action === 'forward_webhook'
            ? 'Rule created. Signing secret: '.$rule->action_config['secret'].' (copy now)'
            : 'Rule created.');
    }

    public function toggleRule(AutomationRule $rule)
    {
        abort_unless((int) $rule->user_id === $this->accountId(), 403);
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('success', $rule->is_active ? 'Rule enabled.' : 'Rule paused.');
    }

    public function destroyRule(AutomationRule $rule)
    {
        abort_unless((int) $rule->user_id === $this->accountId(), 403);
        Audit::log($this->actorId(), 'automation.rule.deleted', $rule, ['action' => $rule->action]);
        $rule->delete();

        return back()->with('success', 'Rule deleted.');
    }

    public function removeOptOut(ContactOptOut $optOut)
    {
        abort_unless((int) $optOut->user_id === $this->accountId(), 403);
        $optOut->delete();

        return back()->with('success', 'Contact opted back in.');
    }

    public function webhooks()
    {
        return view('admin.customer.whatsapp.webhooks', [
            'webhooks' => Webhook::where('user_id', $this->accountId())
                ->where('event_type', 'like', 'whatsapp.%')->latest()->get(),
            'events' => config('whatsapp.webhook_events'),
        ]);
    }

    public function storeWebhook(Request $request)
    {
        $data = $request->validate([
            'event_type' => ['required', Rule::in(config('whatsapp.webhook_events'))],
            'url' => 'required|url|starts_with:https://|max:2048',
        ]);

        $webhook = Webhook::create([
            'user_id' => $this->accountId(),
            'event_type' => $data['event_type'],
            'url' => $data['url'],
            'secret' => Str::random(32),
            'is_active' => true,
        ]);

        return back()->with('success', 'Webhook saved. Signing secret: '.$webhook->secret.' (copy now)');
    }

    public function destroyWebhook(Webhook $webhook)
    {
        abort_unless((int) $webhook->user_id === $this->accountId() && str_starts_with($webhook->event_type, 'whatsapp.'), 403);
        $webhook->delete();

        return back()->with('success', 'Webhook deleted.');
    }

    public function docs()
    {
        return view('admin.customer.whatsapp.docs', [
            'apiBase' => rtrim(config('app.url'), '/').'/api/v1',
        ]);
    }

    private function messageList(Request $request, string $direction, string $view)
    {
        $request->validate([
            'status' => 'nullable|string|max:32',
            'connector' => 'nullable|in:device,cloud_api',
            'q' => 'nullable|string|max:64',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $messages = WhatsAppMessage::query()
            ->with(['account', 'template'])
            ->where('user_id', $this->accountId())
            ->where('direction', $direction)
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->connector, fn ($q, $v) => $q->where('connector_type', $v))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($q) => $q
                ->where('recipient', 'like', "%{$v}%")
                ->orWhere('sender', 'like', "%{$v}%")
                ->orWhere('message_id', $v)))
            ->when($request->from, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($request->to, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view($view, [
            'messages' => $messages,
            'accounts' => WhatsAppAccount::forAccount($this->accountId())->usable()->get(),
        ]);
    }

    private function normalizeTemplateInput(Request $request): array
    {
        $data = $request->except(['_token', '_method', 'buttons_json', 'example_body']);

        $data['buttons'] = collect($request->input('buttons', []))
            ->filter(fn ($b) => ! empty($b['type']) && (! empty($b['text']) || ! empty($b['value'])))
            ->map(function ($b) {
                $field = ['url' => 'url', 'phone_number' => 'phone_number', 'copy_code' => 'example'][$b['type']] ?? null;
                $button = ['type' => $b['type'], 'text' => $b['text'] ?? null];
                if ($field && ! empty($b['value'])) {
                    $button[$field] = $b['value'];
                }

                return array_filter($button, fn ($v) => $v !== null && $v !== '');
            })
            ->values()->all() ?: null;

        $examples = $this->splitKeywords((string) $request->input('example_body', ''));
        if ($examples) {
            $data['examples'] = ['body' => $examples];
        }

        if (($data['header_type'] ?? 'none') === 'none') {
            unset($data['header_text'], $data['header_media_url']);
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function splitKeywords(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    private function authorizeAccount(WhatsAppAccount $account): void
    {
        abort_unless((int) $account->user_id === $this->accountId() && ! $account->revoked_at, 403);
    }
}
