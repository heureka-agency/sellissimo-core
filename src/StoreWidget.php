<?php

namespace Sellissimo\Core;

use Statamic\Facades\User;
use Statamic\Widgets\Widget;

abstract class StoreWidget extends Widget
{
    abstract protected function data(Store $store): array;

    public function html()
    {
        if (! User::current()?->can(static::PERMISSION) || StaffSites::allowed()->isEmpty()) {
            return '';
        }

        $data = $this->data(app(Store::class));
        $sites = StaffSites::allowed();
        $data['scope'] = \Statamic\Facades\Site::all()->count() > 1
            ? (isset($data['site']) ? $sites->get($data['site'])->name() : ($sites->count() === 1 ? $sites->first()->name() : __('All accessible sites')))
            : null;

        return view('sellissimo::widget', $data);
    }
}
