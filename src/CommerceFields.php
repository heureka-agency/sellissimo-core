<?php

namespace Sellissimo\Core;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Blueprint;

class CommerceFields
{
    public function __construct(private Store $store) {}

    public function definitions(string $scope, bool $staff = false): array
    {
        $fields = [];
        foreach (Blueprint::find('sellissimo::'.$scope)?->fields()->all() ?? [] as $handle => $field) {
            $internal = $field->visibility() !== 'visible';
            if ($internal && ! $staff) {
                continue;
            }
            $type = $field->type();
            if (! in_array($type, ['text', 'textarea', 'integer', 'date', 'toggle', 'select', 'radio', 'assets'], true)) {
                continue;
            }
            $options = [];
            foreach ($field->get('options', []) as $key => $value) {
                if (is_array($value)) {
                    $options[(string) ($value['key'] ?? $key)] = $value['value'] ?? $value['label'] ?? $key;
                } else {
                    $options[$key] = $value;
                }
            }
            $rules = \Statamic\Fields\Validator::explodeRules($field->get('validate', []));
            $fields[$handle] = ['label' => $field->display(), 'type' => $type, 'options' => $options, 'rules' => $rules,
                'required' => $field->get('required', false) || in_array('required', $rules, true), 'internal' => $internal];
        }

        return $fields;
    }

    public function controls(string $type, bool $staff = false): array
    {
        return array_map(fn ($field) => Arr::except($field, ['rules']), $this->definitions($type, $staff));
    }

    public function upload(string $scope, string $type, string $handle, UploadedFile $file, bool $staff): object
    {
        $field = $this->definitions($type, $staff)[$handle] ?? null;
        abort_unless($field && $field['type'] === 'assets', 422, 'Choose an artwork field configured for this form.');
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp']])->validate();
        $id = (string) Str::uuid();
        $disk = config('sellissimo.download_disk');
        $path = $file->storeAs('order-artwork', $id, $disk);
        throw_unless($path, 'Could not save the artwork.');
        try {
            $this->store->table('field_uploads')->insert(['id' => $id, 'scope' => $scope, 'field' => $type.'.'.$handle,
                'name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 160, ''),
                'path' => $path, 'disk' => $disk, 'created_at' => now()]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
        if ($this->store->db()->transactionLevel() && method_exists($this->store->db(), 'afterRollBack')) {
            $this->store->db()->afterRollBack(fn () => Storage::disk($disk)->delete($path));
        }

        return $this->store->table('field_uploads')->where('id', $id)->firstOrFail();
    }

    public function values(string $type, array $input, ?string $scope, bool $staff, bool $complete, ?array $only = null): array
    {
        $definitions = $this->definitions($type, $staff);
        if ($only !== null) {
            $definitions = Arr::only($definitions, $only);
        }
        if (array_diff_key($input, $definitions)) {
            throw ValidationException::withMessages([$type.'_fields' => 'One or more fields are unavailable. Review the current form.']);
        }
        $rules = [];
        foreach ($definitions as $handle => $field) {
            $value = $input[$handle] ?? null;
            if ($field['type'] === 'assets' && $value instanceof UploadedFile && $scope) {
                $input[$handle] = $this->upload($scope, $type, $handle, $value, $staff)->id;
            }
            $checks = match ($field['type']) {
                'assets' => ['uuid'], 'date' => ['date_format:Y-m-d'], 'integer' => ['integer'], 'toggle' => ['boolean'],
                'select', 'radio' => [Rule::in(array_keys($field['options']))], default => ['string', 'max:4000'],
            };
            if ($field['type'] !== 'assets') {
                $checks = [...$checks, ...array_filter($field['rules'], fn ($rule) => ($complete && (! $field['internal'] || array_key_exists($handle, $input))) || ! str_starts_with((string) $rule, 'required'))];
            }
            $rules[$handle] = [$complete && $field['required'] && (! $field['internal'] || array_key_exists($handle, $input)) ? 'required' : 'nullable', ...$checks];
            if ($field['type'] === 'assets' && filled($input[$handle] ?? null)) {
                $valid = is_string($input[$handle]) && Str::isUuid($input[$handle]) && $scope && $this->store->table('field_uploads')
                    ->where('id', $input[$handle])->where('scope', $scope)->where('field', $type.'.'.$handle)->exists();
                if (! $valid) {
                    throw ValidationException::withMessages([$handle => 'Upload a file for this cart and field.']);
                }
            }
        }
        $values = array_filter(Validator::make($input, $rules)->validate(), fn ($value) => $value !== null && $value !== '');
        ksort($values);

        return $values;
    }

    public function snapshot(string $type, array $values): array
    {
        $fields = $this->definitions($type, true);
        $snapshot = [];
        foreach ($values as $handle => $value) {
            $field = $fields[$handle];
            $file = $field['type'] === 'assets' ? $this->store->table('field_uploads')->where('id', $value)->firstOrFail() : null;
            $snapshot[$handle] = ['label' => $field['label'], 'value' => $file?->name ?? ($field['options'][$value] ?? $value),
                'internal' => $field['internal'], ...($file ? ['file_id' => $file->id] : [])];
        }

        return $snapshot;
    }

    public static function visible(array $fields): array
    {
        return array_map(fn ($field) => Arr::only($field, ['label', 'value']), array_filter($fields, fn ($field) => ! ($field['internal'] ?? false)));
    }

    public function pruneUploads(): void
    {
        $old = $this->store->table('field_uploads')->where('created_at', '<', now()->subDays(max(1, (int) \Sellissimo\Core\OperationalSettings::shared('recovery_retention_days', 'recovery.retention_days', 90))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sellissimo_carts')->where(fn ($q) => $q
                ->whereColumn('sellissimo_carts.id', 'sellissimo_field_uploads.scope')->orWhereColumn('sellissimo_carts.field_scope', 'sellissimo_field_uploads.scope')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sellissimo_drafts')->whereColumn('sellissimo_drafts.id', 'sellissimo_field_uploads.scope'));
        foreach ($old->lazyById(100) as $upload) {
            $placed = $this->store->table('orders')->where('id', $upload->scope)->value('operational_fields');
            if ($placed && in_array($upload->id, array_column(array_filter(json_decode($placed, true)), 'file_id'), true)) {
                continue;
            }
            Storage::disk($upload->disk)->delete($upload->path);
            $this->store->table('field_uploads')->where('id', $upload->id)->delete();
        }
    }

    public static function scope(object $cart): ?string
    {
        return $cart->field_scope ?? $cart->id ?? null;
    }
}
