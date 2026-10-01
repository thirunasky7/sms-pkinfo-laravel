# WhatsApp Gateway

WhatsApp is a second channel next to SMS. It reuses the existing users, sub-admin
account model, API keys, queue, webhooks, audit log and dashboard. The SMS API and
SMS device flow are unchanged.

```
API / Dashboard / Automation
        │
   MessageRouter ──► SMS Gateway (unchanged SMS path)
        │
        └──────────► WhatsAppMessageService ──► DeviceConnector   (Android app automates WhatsApp)
                                             └► CloudApiConnector (Meta WhatsApp Cloud API)
```

## Connectors

| Connector | How it sends | Delivery/read receipts | Incoming |
|-----------|--------------|------------------------|----------|
| `device`  | The paired Android gateway app pulls messages (`POST /devices/{id}/whatsapp/sync`) and sends them through the WhatsApp app with an accessibility service. | `sent` only (WhatsApp exposes no receipts to other apps). | Notification listener on the phone. |
| `cloud_api` | Queue job calls the Graph API. Scheduled/retry messages are picked up by `whatsapp:dispatch-due`. | `sent`, `delivered`, `read`, `failed` via Meta webhook. | Meta webhook. |

Device sending automates the consumer/business WhatsApp app. It is opt-in, suited to low
volume, and WhatsApp may restrict numbers that send automated or bulk messages. Use the
Cloud API for business messaging at scale.

## Configuration

All secrets come from the environment (`config/whatsapp.php`):

| Variable | Purpose |
|----------|---------|
| `WHATSAPP_ENABLED` | Kill switch for all WhatsApp sends (API, dashboard, automation). SMS unaffected. |
| `WHATSAPP_REQUIRE_HTTPS` | Reject non-HTTPS WhatsApp API calls (defaults to on in production). |
| `WHATSAPP_MAX_RETRIES` | Automatic retries for retryable failures (backoff 30s, 2m, 10m). |
| `WHATSAPP_DEVICE_OFFLINE_MINUTES` | Device account is marked disconnected after this long without a sync. |
| `WHATSAPP_DEVICE_FAIL_PENDING_MINUTES` | Messages waiting on a disconnected device fail after this long. |
| `WHATSAPP_MEDIA_DISK`, `WHATSAPP_MEDIA_MAX_KB` | Where uploaded media is stored (must be publicly reachable over HTTPS for Meta and the phone). |
| `META_APP_ID`, `META_APP_SECRET` | Meta app credentials. The secret also verifies webhook signatures. |
| `META_EMBEDDED_SIGNUP_CONFIG_ID` | Embedded Signup configuration id (Meta App Dashboard → Facebook Login for Business). |
| `META_WEBHOOK_VERIFY_TOKEN` | Random string you also enter in the Meta webhook configuration. |
| `META_GRAPH_VERSION` | Graph API version, default `v21.0`. |

Plans: `subscription_plans.whatsapp_limit` (`NULL` = unlimited) and
`subscriptions.whatsapp_used` track WhatsApp quota separately from SMS.

## Deployment

1. Pull the branch and install: `composer install --no-dev --optimize-autoloader`.
2. Add the variables above to `.env`, then `php artisan config:cache`.
3. Run migrations (all reversible):
   `php artisan migrate` — adds `whatsapp_accounts`, `whatsapp_templates`,
   `whatsapp_messages`, `automation_rules`, `contact_opt_outs`, and the
   `whatsapp_limit` / `whatsapp_used` columns.
4. `php artisan storage:link` (media on the `public` disk).
5. Use a real queue in production (`QUEUE_CONNECTION=database` or `redis`) and run a worker:
   `php artisan queue:work --tries=1 --max-time=3600` (supervisor/systemd).
   If `database`, run `php artisan queue:table && php artisan migrate` once.
6. Scheduler cron: `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`. It runs
   `whatsapp:dispatch-due` and `whatsapp:monitor-devices` every minute,
   `whatsapp:refresh-tokens` daily and `whatsapp:sync-templates` hourly.
7. Serve over HTTPS. In the Meta App Dashboard → WhatsApp → Configuration set the callback URL to
   `https://YOUR_DOMAIN/api/v1/whatsapp/webhooks/meta`, the verify token to
   `META_WEBHOOK_VERIFY_TOKEN`, and subscribe to `messages` and
   `message_template_status_update`.
8. Ship the updated Android app (pk-sms-mobile) to devices that will use the device connector.

Rollback: `php artisan migrate:rollback --step=5` removes the WhatsApp tables/columns;
setting `WHATSAPP_ENABLED=false` stops sends without a deploy.

## API

Base URL `https://YOUR_DOMAIN/api/v1`. Authenticate with the existing `X-API-Key` and
`X-API-Secret` headers. Keys created with explicit scopes need `whatsapp:send`,
`whatsapp:read` or `whatsapp:templates`; keys without scopes keep full access.
Errors: `{"message": "...", "error_code": "..."}`.

```bash
# Connection status
curl https://YOUR_DOMAIN/api/v1/whatsapp/connection -H "X-API-Key: smk_..." -H "X-API-Secret: ..."

# Send text (idempotent with Idempotency-Key)
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/send \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..." -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-1234" \
  -d '{"to":"+14155550123","body":"Your order shipped"}'

# Send media (upload or HTTPS URL)
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/send -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -F to=+14155550123 -F type=image -F body="Invoice" -F media=@invoice.jpg

# Schedule
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/send -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"to":"+14155550123","body":"Reminder","scheduled_at":"2026-10-01T09:00:00Z"}'

# Send a template
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/template/send -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"to":"+14155550123","template_name":"order_update","language":"en_US","params":{"body":["Sam","#1234"]}}'

# Status / history / number check
curl https://YOUR_DOMAIN/api/v1/whatsapp/message/wam_01j... -H "X-API-Key: smk_..." -H "X-API-Secret: ..."
curl "https://YOUR_DOMAIN/api/v1/whatsapp/messages?direction=incoming&per_page=50" -H "X-API-Key: smk_..." -H "X-API-Secret: ..."
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/check-number -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -H "Content-Type: application/json" -d '{"phone":"+14155550123"}'

# Templates
curl https://YOUR_DOMAIN/api/v1/whatsapp/templates -H "X-API-Key: smk_..." -H "X-API-Secret: ..."
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/templates -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"account_id":3,"name":"order_update","language":"en_US","category":"utility","body":"Hi {{1}}, order {{2}} shipped.","examples":{"body":["Sam","#1234"]}}'
curl -X POST https://YOUR_DOMAIN/api/v1/whatsapp/templates/sync -H "X-API-Key: smk_..." -H "X-API-Secret: ..."
```

Status lifecycle: `queued → sending → sent → delivered → read`, or `failed`. Incoming
messages are `received`. Statuses never move backwards.

### Error codes

`INVALID_NUMBER`, `NOT_ON_WHATSAPP`, `RECIPIENT_OPTED_OUT`, `NO_WHATSAPP_ACCOUNT` (409),
`ACCOUNT_NOT_CONNECTED` (409), `QUOTA_EXCEEDED` (402), `WHATSAPP_DISABLED` (503),
`TEMPLATE_NOT_APPROVED`, `TEMPLATE_PARAM_MISMATCH`, `TEMPLATE_MEDIA_REQUIRED`,
`TEMPLATE_EXAMPLES_REQUIRED`, `TEMPLATE_EXISTS` (409), `OUTSIDE_24H_WINDOW`,
`TOKEN_EXPIRED`, `RATE_LIMITED` (retried), `NETWORK_ERROR` (retried),
`DEVICE_TIMEOUT` (retried), `DEVICE_DISCONNECTED`, `ACCOUNT_REVOKED`,
`PHONE_ALREADY_LINKED` (409), `NOT_FOUND` (404).

### Webhooks

Events: `whatsapp.message.sent`, `.delivered`, `.read`, `.failed`, `.received`. Same
delivery mechanism and headers as SMS webhooks (`X-SMS-Gateway-Event`,
`X-SMS-Gateway-Signature` = HMAC-SHA256 of the raw body with the webhook secret). Body:
`{"event": "...", "message": { ...message fields... }}`.

## Automation rules

Dashboard → WhatsApp → Rules. One engine for both channels, triggered by incoming SMS,
incoming WhatsApp, and permanently failed WhatsApp messages.

| Action | Trigger | Effect |
|--------|---------|--------|
| `auto_reply` | incoming | Reply on the same channel/account. |
| `forward_webhook` | incoming, failed | Signed POST to a CRM URL (`automation.rule_triggered`). |
| `forward_whatsapp` / `forward_sms` | incoming | Forward the text to a number (WhatsApp→SMS routing). |
| `opt_out` / `opt_in` | incoming | Maintain `contact_opt_outs`; WhatsApp sends to opted-out numbers are blocked. |
| `sms_fallback` | failed | Send the failed WhatsApp text as SMS. |
| `route_whatsapp` | failed | Retry from another WhatsApp account (e.g. device → Cloud API). |

Matching: first-word keyword, exact, contains, regex, or any. Rules run by priority and can
stop further processing. Messages created by automation never trigger failure rules again
(no loops), and a failing action never blocks message ingestion.

## Security

- HTTPS enforced on WhatsApp API routes (`https` middleware) and the Meta webhook.
- Meta access tokens use Laravel's `encrypted` cast (APP_KEY), are `$hidden`, and are never
  returned (`token_configured` flag only) or logged. Graph errors log only path/code/trace id.
- Meta webhook verifies `X-Hub-Signature-256` with `hash_equals`; rejects when no secret is configured.
- Per-key rate limits (`api_keys.rate_limit`) on all API-key routes; dashboard credential
  endpoints throttled to 10/min.
- All queries are scoped to the owning account (`ownsAccountId()`); cross-account access returns 403/404.
- Audit log entries for connect, revoke, template and rule changes (secrets redacted).
- Revoking an account clears the token and fails pending messages.
- Input validated everywhere; media uploads restricted by MIME/size; media URLs must be HTTPS.
- Idempotency keys (unique per account) and inbound dedupe on provider message ids.

Known items outside this change: the Android app manifest has `usesCleartextTraffic="true"`
(pre-existing), customer webhook/CRM URLs are not checked against private IP ranges (same as
existing SMS webhooks), and the global API limiter remains 60/min per IP.

## Tests

`php artisan test` — SQLite in-memory. WhatsApp suites live in `tests/Feature/WhatsApp`
and `tests/Feature/SmsRegressionTest.php` guards existing SMS behaviour.
