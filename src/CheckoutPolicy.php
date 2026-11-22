<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Entry;

final class CheckoutPolicy
{
    public static function setting(string $site, string $key, mixed $fallback = null): mixed
    {
        return StoreSettings::forSection($site, 'checkout', $key, config("sellissimo.checkout_policy.$site.$key", $fallback));
    }

    /** @return array{fields: array, version: string, payment_obligation: bool, payment_notice: string, available: bool} */
    public static function forSite(string $site, bool $archive = false): array
    {
        $fields = [];
        $documents = [];
        $available = true;
        foreach (['terms' => 'require_terms', 'privacy' => 'require_privacy', 'newsletter' => 'offer_newsletter'] as $handle => $setting) {
            if (! self::setting($site, $setting, false)) {
                continue;
            }
            $field = ['label' => __("sellissimo::shop.consent_$handle"), 'required' => $handle !== 'newsletter'];
            if ($field['required']) {
                $id = self::setting($site, $handle.'_page');
                $entry = $id ? Entry::find($id)?->in($site) : null;
                if ($entry?->status() === 'published' && $entry->url()) {
                    $source = $entry->values()->except(['updated_by', 'updated_at'])->all();
                    if ($archive) {
                        $documents[$handle] = $source;
                    }
                    $field['document'] = ['id' => $entry->id(), 'url' => $entry->absoluteUrl(), 'title' => $entry->value('title'),
                        'version' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))];
                } else {
                    $available = false;
                }
            }
            $fields[$handle] = $field;
        }

        $version = hash('sha256', json_encode([$site, $fields], JSON_THROW_ON_ERROR));
        foreach ($documents as $key => $source) {
            $fields[$key]['document']['content'] = $source;
        }

        return ['fields' => $fields, 'version' => $version,
            'payment_obligation' => (bool) self::setting($site, 'payment_obligation', false),
            'payment_notice' => (string) self::setting($site, 'payment_notice', ''), 'available' => $available];
    }

    /** Validate inside order placement, after checking for an existing order. */
    public static function snapshot(string $site, array $input): array
    {
        $policy = self::forSite($site, archive: true);
        if (! $policy['fields']) {
            return [];
        }
        if (! $policy['available']) {
            throw ValidationException::withMessages(['policy_version' => __('sellissimo::shop.policy_unavailable')]);
        }
        $rules = [];
        foreach ($policy['fields'] as $key => $field) {
            $rules['consents.'.$key] = $field['required'] ? ['accepted'] : ['sometimes', 'boolean'];
        }
        Validator::make($input, $rules)->validate();
        $needsVersion = collect($policy['fields'])->contains('required', true) || ! empty($input['consents']['newsletter']);
        if ($needsVersion && ! hash_equals($policy['version'], $input['policy_version'] ?? '')) {
            throw ValidationException::withMessages(['policy_version' => __('sellissimo::shop.policy_changed')]);
        }
        $recorded = now()->toIso8601String();
        $snapshot = [];
        foreach ($policy['fields'] as $key => $field) {
            $snapshot[$key] = [...$field, 'accepted' => filter_var($input['consents'][$key] ?? false, FILTER_VALIDATE_BOOL),
                'version' => $policy['version'], 'recorded_at' => $recorded];
        }

        return $snapshot;
    }
}
