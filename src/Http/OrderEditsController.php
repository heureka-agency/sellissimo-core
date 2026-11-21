<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Sellissimo\Core\OperationalFields;
use Sellissimo\Core\OrderContact;
use Statamic\Facades\CP\Toast;

class OrderEditsController
{
    public function contact(Request $request, string $order, OrderContact $contacts)
    {
        $input = $request->validate(['email' => 'required|email|max:254|confirmed', 'verified' => 'accepted',
            'reason' => 'required|string|max:250', 'revision' => 'required|integer|min:0']);
        $contacts->correct($order, $input, $request->user());
        Toast::success(__('Guest email updated. A fresh receipt link will go to the corrected address.'));

        return back();
    }

    public function fields(Request $request, string $order, OperationalFields $fields)
    {
        $input = $request->validate(['fields' => 'required|array|min:1|max:100', 'revision' => 'required|integer|min:0']);
        $fields->update($order, (int) $input['revision'], $input['fields'], $request->user());
        Toast::success(__('Order fields saved.'));

        return back();
    }
}
