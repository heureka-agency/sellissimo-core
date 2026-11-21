<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Sellissimo\Core\SendReceiptLink;
use Statamic\Facades\Site;

class ReceiptRecoveryController
{
    private function site(Request $request): string
    {
        $site = $request->validate(['site' => ['sometimes', 'string', Rule::in(Site::all()->keys()->all())]])['site'] ?? Site::current()->handle();
        PrivateResponse::forSite($site);

        return $site;
    }

    public function form(Request $request)
    {
        return view('sellissimo::recover-receipt', ['site' => $this->site($request)]);
    }

    public function send(Request $request)
    {
        $site = $this->site($request);
        $input = $request->validate(['number' => 'required|string|max:32', 'email' => 'required|email|max:254']);
        $number = strtoupper(ltrim(trim($input['number']), '#'));
        $email = mb_strtolower(trim($input['email']));
        SendReceiptLink::dispatchAfterResponse($site, $email, $number);

        return $request->expectsJson()
            ? response()->json(['message' => __('sellissimo::shop.recover_notice')])
            : redirect()->route('sellissimo.receipt.recover', ['site' => $site])->with('receipt_recovery_notice', true);
    }
}
