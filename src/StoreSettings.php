<?php

namespace Sellissimo\Core;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Validation\ValidationException;

final class StoreSettings
{
    public const GLOBAL = '@store';

    private array $scopes = [];
    private ?Connection $connection = null;
    private ?bool $installed = null;

    public function __construct(private Store $store) {}

    public static function get(string $site, string $key, mixed $fallback = null): mixed
    {
        if (self::locked($site, $key)) {
            return $fallback;
        }
        $found = false;
        $value = $fallback;
        foreach (app(self::class)->sections($site) as $section) {
            if (array_key_exists($key, $section['values'])) {
                if ($found) {
                    throw new \LogicException("The setting '$key' belongs to multiple sections. Read it with StoreSettings::forSection.");
                }
                $value = $section['values'][$key];
                $found = true;
            }
        }
        return $value;
    }

    /** Read the specific section that owns a field, even if another extension uses the same field handle. */
    public static function forSection(string $scope, string $section, string $key, mixed $fallback = null): mixed
    {
        if (self::locked($scope, $key)) {
            return $fallback;
        }
        return self::overrides($scope, $section)[$key] ?? $fallback;
    }

    public static function transactionChanged(ConnectionEvent $event): void
    {
        if ($event->connectionName !== config('sellissimo.connection') || !app()->resolved(self::class)) {
            return;
        }
        $level = $event->connection->transactionLevel();
        if (($event instanceof TransactionBeginning && $level !== 1) || ($event instanceof TransactionCommitted && $level !== 0)) {
            return;
        }
        // Use a fresh snapshot at the start of each transaction and discard rolled-back savepoint values.
        $service = app(self::class);
        $service->scopes = [];
        if ($service->installed === false) {
            $service->installed = null;
        }
    }

    public static function locked(string $scope, string $key): bool
    {
        $locks = config('sellissimo.locked_settings', []);

        return in_array($key, $locks, true) || in_array($key, $locks[$scope] ?? [], true);
    }

    private function sections(string $scope): array
    {
        $db = $this->store->db();
        if ($this->connection !== $db) {
            $this->connection = $db;
            $this->installed = null;
            $this->scopes = [];
        }
        if (isset($this->scopes[$scope])) {
            return $this->scopes[$scope];
        }
        $this->installed ??= $this->store->hasTable('settings');
        if (!$this->installed) {
            return [];
        }
        $sections = [];
        foreach ($this->store->table('settings')->where('scope', $scope)->get() as $row) {
            $sections[$row->section] = ['values' => json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR), 'revision' => (int) $row->revision];
        }
        return $this->scopes[$scope] = $sections;
    }

    public static function overrides(string $scope, string $section): array
    {
        return app(self::class)->sections($scope)[$section]['values'] ?? [];
    }

    public static function revision(string $scope, string $section): int
    {
        return app(self::class)->sections($scope)[$section]['revision'] ?? 0;
    }

    /** A null value removes an override so subsequent changes to the inherited default take effect. */
    public static function save(string $scope, string $section, array $changes, int $revision): void
    {
        foreach (array_keys($changes) as $key) {
            if (self::locked($scope, $key)) {
                throw ValidationException::withMessages([$key => __('This value is managed by your hosting configuration.')]);
            }
        }
        $service = app(self::class);
        $store = $service->store;
        $store->db()->transaction(function () use ($store, $scope, $section, $changes, $revision) {
            $where = compact('scope', 'section');
            $store->table('settings')->insertOrIgnore([...$where, 'settings' => '{}', 'revision' => 0]);
            $row = $store->table('settings')->where($where)->lockForUpdate()->firstOrFail();
            if ((int) $row->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => __('Someone saved changes while you were editing. Reload to review the latest settings.')]);
            }
            $values = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
            $values = array_filter(array_replace($values, $changes), fn ($value) => $value !== null);
            $store->table('settings')->where($where)->update(['settings' => json_encode((object) $values, JSON_THROW_ON_ERROR), 'revision' => $revision + 1]);
            $store->table('settings_history')->insert([...$where, 'revision' => $revision + 1, 'actor' => auth()->id(),
                'fields' => json_encode(array_keys($changes), JSON_THROW_ON_ERROR), 'created_at' => now()]);
        }, 3);
        $service->installed = true;
        $service->scopes = [];
    }
}
