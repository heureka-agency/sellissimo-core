<?php

namespace Sellissimo\Core;

use Illuminate\Support\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;

final class StaffSites
{
    public static function allowed($staff = null): Collection
    {
        $staff ??= User::current();

        return Site::all()->filter(fn ($site) => $staff && $staff->can('view', $site));
    }

    public static function handles(): array
    {
        return self::allowed()->keys()->all();
    }

    public static function selected(): string
    {
        $sites = self::allowed();
        $site = $sites->get(Site::selected()->handle()) ?? $sites->first();
        abort_unless($site, 403);

        return $site->handle();
    }

    public static function authorize(?string $handle): void
    {
        abort_unless($handle && self::allowed()->has($handle), 404);
    }

    public static function storewide(): bool
    {
        $sites = self::allowed();

        return $sites->isNotEmpty() && $sites->count() === Site::all()->count();
    }
}
