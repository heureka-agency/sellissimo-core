<?php

namespace Sellissimo\Core\Http;

use Sellissimo\Core\StaffSites;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Sellissimo\Core\AddressBooks;
use Sellissimo\Core\DraftCustomers;
use Sellissimo\Core\Store;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class CustomersController
{
    public function __construct(private Store $store, private AddressBooks $books) {}

    private function site(Request $request): string
    {
        if ($request->routeIs('statamic.cp.*')) {
            return $request->validate(['site' => 'sometimes|required|string|max:100'])['site'] ?? StaffSites::selected();
        }
        $site = $request->validate(['site' => 'sometimes|required|string|max:100'])['site'] ?? Site::current()->handle();
        abort_unless(Site::all()->has($site), 404);

        return $site;
    }

    public function index(Request $request, DraftCustomers $customers)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'view' => 'nullable|in:accounts,guests', 'site' => 'sometimes|required|string|max:100']);
        $site = $filters['site'] ?? StaffSites::selected();
        abort_unless(Site::all()->has($site), 404);
        $queue = [...$filters, 'site' => $site];
        if (($page = Paginator::resolveCurrentPage()) > 1) $queue['page'] = $page;
        $term = trim($filters['q'] ?? '');
        if (($filters['view'] ?? '') === 'guests') {
            $query = app(OrderListing::class)->query(['q' => $term])->whereNull('customer')->where('site', $site)->groupByRaw('lower(email)')
                ->selectRaw('lower(email) as email, max(cast(id as char(36))) as anchor')->orderBy('email');
            $records = $query->paginate(25)->withQueryString()->through(fn ($r) => ['name' => $r->email, 'email' => $r->email, 'url' => cp_route('sellissimo.customer', ['type' => 'guest', 'id' => $r->anchor, 'site' => $site, 'queue' => $queue])]);
        } else {
            $page = Paginator::resolveCurrentPage();
            $accounts = $customers->accounts($site, $term)->skip(($page - 1) * 25)->take(26)->map(fn ($u) => [
                'name' => $u->name(), 'email' => $u->email(), 'url' => cp_route('sellissimo.customer', ['type' => 'account', 'id' => $u->id(), 'site' => $site, 'queue' => $queue]),
            ])->values()->collect();
            $records = (new Paginator($accounts, 25, $page, ['path' => $request->url()]))->withQueryString();
        }

        return Inertia::render('sellissimo::Customers', ['results' => $records, 'sites' => StaffSites::allowed()->map->name(), 'filters' => [...$filters, 'site' => $site], 'listUrl' => cp_route('sellissimo.customers', ['site' => $site])]);
    }

    private function contact(string $type, string $id, string $site): array
    {
        if ($type === 'account') {
            $account = User::find($id);
            abort_unless($account && app(DraftCustomers::class)->eligible($account, $site), 404);

            return ['id' => (string) $account->id(), 'email' => $account->email(), 'name' => $account->name()];
        }
        $order = $this->store->table('orders')->where('site', $site)->whereNull('customer')->where('id', $id)->firstOrFail();

        return ['id' => null, 'email' => $order->email, 'name' => json_decode($order->receipt, true)['address']['name'] ?? $order->email];
    }

    public function show(Request $request, string $type, string $id)
    {
        $site = $this->site($request);
        $contact = $this->contact($type, $id, $site);
        $scope = AddressBooks::scope($site, $contact['id'], $contact['email']);
        $orders = $this->store->table('orders')->where('site', $site)->where('customer', $contact['id'])->when($type === 'guest', fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower($contact['email'])]))->latest('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString()->through(fn ($row) => [...OrderView::summary($row, json_decode($row->receipt, true)), 'url' => $request->user()->can('view sellissimo orders') ? cp_route('sellissimo.order', $row->id) : null]);

        $queue = QueueContext::read($request, 'customers');
        return Inertia::render('sellissimo::Customer', ['contact' => $contact, 'orders' => $orders, 'book' => $this->books->read($scope), 'countries' => StoreCountries::labels(),
            'inviteUrl' => $type === 'guest' ? cp_route('sellissimo.customer.invite', compact('id', 'site')) : null,
            'saveUrl' => cp_route('sellissimo.customer.address', [...compact('type', 'id', 'site'), 'queue' => $queue]), 'listUrl' => cp_route('sellissimo.customers', [...$queue, 'site' => $site])]);
    }

    public function invite(Request $request, string $id)
    {
        $site = $this->site($request);
        $contact = $this->contact('guest', $id, $site);
        $email = mb_strtolower($contact['email']);
        $orders = $this->store->table('orders')->where('site', $site)->whereNull('customer')
            ->whereRaw('lower(email) = ?', [$email])->orderBy('id')->pluck('id');
        $invitation = (string) \Illuminate\Support\Str::uuid();
        $expires = now()->addDay();
        $this->store->table('claim_invitations')->insert(['id' => $invitation, 'site' => $site, 'email' => $email,
            'order_ids' => $orders->toJson(), 'expires_at' => $expires]);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('sellissimo.invitation', $expires, compact('invitation'));
        app(\Sellissimo\Core\StoreMail::class)->send('invitation', $site, $email, $url);
        \Statamic\Facades\CP\Toast::success(__('Order claim invitation sent.'));
        return back();
    }

    public function merchantSave(Request $request, string $type, string $id)
    {
        $site = $this->site($request);
        $contact = $this->contact($type, $id, $site);
        $this->write($request, AddressBooks::scope($site, $contact['id'], $contact['email']));

        return redirect(cp_route('sellissimo.customer', [...compact('type', 'id', 'site'), 'queue' => QueueContext::read($request, 'customers')]));
    }

    public function account(Request $request)
    {
        abort_unless($user = $request->user(), 401);
        $site = $this->site($request);
        PrivateResponse::forSite($site);
        $scope = AddressBooks::scope($site, (string) $user->getAuthIdentifier());
        if ($request->isMethod('post')) {
            $this->write($request, $scope);
            if (! $request->expectsJson()) {
                return redirect(route('sellissimo.addresses', compact('site')));
            }
        }
        $book = $this->books->read($scope);

        return $request->expectsJson() ? response()->json($book) : view('sellissimo::addresses', ['sites' => Site::all()->map->name(), ...compact('site', 'book')]);
    }

    private function write(Request $request, string $scope): void
    {
        $data = $request->validate(['action' => 'required|in:save,delete', 'revision' => 'required|integer|min:0', 'id' => 'required_if:action,delete|nullable|uuid', 'billing' => 'sometimes|boolean', 'shipping' => 'sometimes|boolean']);
        if ($data['action'] === 'save') {
            $data += $request->validate(['label' => 'required|string|max:80', 'address' => 'required|array:'.implode(',', CheckoutInput::ADDRESS_FIELDS),
                'address.country' => ['required', Rule::in(array_keys(StoreCountries::labels()))], 'address.street' => 'required|string|max:250', 'address.city' => 'required|string|max:100',
                'address.name' => 'nullable|string|max:150', 'address.company' => 'nullable|string|max:150', 'address.street2' => 'nullable|string|max:250', 'address.phone' => 'nullable|string|max:50',
                'address.region' => 'nullable|string|max:100', 'address.postal_code' => 'nullable|string|max:32']);
        }
        $this->books->save($scope, $data);
    }
}
