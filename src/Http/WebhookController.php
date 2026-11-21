<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Sellissimo\Core\Store;
use Sellissimo\Core\Webhooks;
use Sellissimo\Core\WebhookTransport;
use Statamic\Facades\CP\Toast;

class WebhookController
{
    public function __construct(private Store $store) {}

    public function index(Request $request)
    {
        $hooks = $this->store->table('webhooks')->orderBy('name')->get(['id', 'name', 'url', 'enabled', 'topics', 'revision']);
        $hooks->each(function ($hook) {
            $hook->topics = json_decode($hook->topics, true);
            $hook->enabled = (bool) $hook->enabled;
        });
        $site = $request->validate(['site' => 'sometimes|required|string|max:100'])['site'] ?? \Sellissimo\Core\StaffSites::selected();
        \Sellissimo\Core\StaffSites::authorize($site);
        $logs = $this->store->table('deliveries')->orderByDesc('created_at')->orderBy('id')
            ->paginate(30, ['id', 'endpoint', 'status', 'topic', 'created_at', 'attempts', 'http_status', 'error'])->appends(compact('site'));
        $logs->through(fn ($row) => [...(array) $row, 'name' => $hooks->firstWhere('id', $row->endpoint)?->name,
            'retryUrl' => in_array($row->status, ['pending', 'failed'], true) ? cp_route('sellissimo.webhooks.retry', $row->id) : null]);

        return Inertia::render('sellissimo::Webhooks', ['endpoints' => $hooks, 'topics' => Webhooks::TOPICS, 'records' => $logs,
            'settingsUrl' => cp_route('sellissimo.settings.section', ['section' => 'system', 'site' => $site]),
            'secret' => $request->session()->pull('sellissimo.webhook_secret'), 'saveUrl' => cp_route('sellissimo.webhooks.save', $request->has('site') ? compact('site') : [])]);
    }

    public function save(Request $request)
    {
        $site = $request->validate(['site' => 'sometimes|required|string|max:100'])['site'] ?? null;
        if ($site) {
            \Sellissimo\Core\StaffSites::authorize($site);
        }
        $data = $request->validate(['id' => 'nullable|uuid', 'name' => 'required|string|max:100', 'url' => 'required|url:https|max:1500',
            'topics' => 'required|array|min:1|max:'.count(Webhooks::TOPICS), 'topics.*' => ['distinct', Rule::in(Webhooks::TOPICS)],
            'enabled' => 'required|boolean', 'revision' => 'required|integer|min:0', 'rotate' => 'sometimes|boolean']);
        if (! WebhookTransport::host($data['url'])) {
            throw ValidationException::withMessages(['url' => 'Enter an HTTPS endpoint on a public domain.']);
        }
        $values = ['name' => $data['name'], 'enabled' => $data['enabled'], 'topics' => json_encode($data['topics'], JSON_THROW_ON_ERROR)];
        if (empty($data['id']) || $request->boolean('rotate')) {
            $secret = Str::random(48);
            $values['secret'] = Crypt::encryptString($secret);
        }
        if (empty($data['id'])) {
            $this->store->table('webhooks')->insert([...$values, 'id' => (string) Str::uuid(), 'url' => $data['url']]);
        } else {
            $updated = $this->store->table('webhooks')->where('id', $data['id'])->where('url', $data['url'])->where('revision', $data['revision'])
                ->update([...$values, 'revision' => $data['revision'] + 1]);
            abort_unless($updated, 409, 'Reopen this endpoint. Create a new endpoint to change the delivery URL.');
        }
        Toast::success(__('Endpoint saved.'));
        $response = redirect(cp_route('sellissimo.webhooks', $site ? compact('site') : []));

        return isset($secret) ? $response->with('sellissimo.webhook_secret', $secret) : $response;
    }

    public function retry(string $delivery)
    {
        $updated = $this->store->table('deliveries')->where('id', $delivery)->whereIn('status', ['failed', 'pending'])
            ->whereIn('endpoint', $this->store->table('webhooks')->select('id')->where('enabled', true))
            ->update(['status' => 'pending', 'available_at' => now()]);
        abort_unless($updated, 409, 'Enable the endpoint and select a pending or failed delivery.');
        Toast::success(__('Delivery queued.'));

        return back();
    }
}
