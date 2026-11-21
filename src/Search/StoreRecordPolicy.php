<?php

namespace Sellissimo\Core\Search;

use Sellissimo\Core\StaffSites;
use Statamic\Facades\Site;

final class StoreRecordPolicy
{
    public function view($staff, StoreRecord $record): bool
    {
        $sites = StaffSites::allowed($staff);
        $selected = $sites->get(Site::selected()->handle()) ?? $sites->first();

        return $selected && (! $record->row->site || $record->row->site === $selected->handle())
            && $staff->can($record->kind === 'order' ? 'view sellissimo orders' : 'manage sellissimo discounts');
    }
}
