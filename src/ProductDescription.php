<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Asset;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;

final class ProductDescription
{
    public const ASSET_PREFIX = 'asset::sellissimo_products::';

    private const NODES = ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'image', 'hardBreak', 'horizontalRule', 'text', 'codeBlock'];

    public static function markdown(string $value): string
    {
        return Str::markdown($value, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public static function html(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) && $value !== '' ? self::markdown($value) : '';
        }

        try {
            self::images($value);
        } catch (ValidationException) {
            return e(self::text($value));
        }

        $field = new Field('sellissimo_description', ['type' => 'bard', 'container' => 'sellissimo_products']);

        return (new Augmentor($field->fieldtype()))->convertToHtml(self::resolveImages($value));
    }

    private static function resolveImages(array $nodes): array
    {
        foreach ($nodes as $index => $node) {
            if ($node['type'] === 'image') {
                $asset = Asset::find(Str::after($node['attrs']['src'], 'asset::'));
                if (! $asset?->isImage() || ! $url = $asset->url()) {
                    unset($nodes[$index]);

                    continue;
                }
                $nodes[$index]['attrs']['src'] = $url;
                $nodes[$index]['attrs']['alt'] ??= $asset->get('alt') ?: $asset->title();
            } elseif (isset($node['content'])) {
                $nodes[$index]['content'] = self::resolveImages($node['content']);
            }
        }

        return array_values($nodes);
    }

    public static function text(mixed $value): ?string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : null;
        }

        $text = '';
        foreach ($value as $node) {
            if (! is_array($node)) {
                continue;
            }
            $text .= is_string($node['text'] ?? null) ? $node['text'] : '';
            if (is_array($node['content'] ?? null)) {
                $text .= self::text($node['content']);
            }
            if (in_array($node['type'] ?? null, ['heading', 'paragraph', 'hardBreak'], true)) {
                $text .= "\n";
            }
        }

        return $text;
    }

    /** Validate the Bard document and return its referenced product images.
     *
     * @return list<string>
     */
    public static function images(array $nodes, int $depth = 0): array
    {
        if (! array_is_list($nodes) || $depth > 24) {
            self::invalid();
        }

        $paths = [];
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;
            if (! in_array($type, self::NODES, true) || ! is_array($attrs = $node['attrs'] ?? []) ||
                ! is_array($marks = $node['marks'] ?? []) || ! array_is_list($marks) ||
                ($type === 'text' && ! is_string($node['text'] ?? null)) ||
                ($type === 'heading' && ! in_array($attrs['level'] ?? null, [1, 2, 3, 4, 5, 6], true))) {
                self::invalid();
            }

            if (array_filter($attrs, fn ($value) => ! is_scalar($value) && $value !== null)) {
                self::invalid();
            }
            if ($type === 'image') {
                if (! is_string($src = $attrs['src'] ?? null) || ! str_starts_with($src, self::ASSET_PREFIX)) {
                    self::invalid();
                }
                $paths[] = CatalogImportMedia::path(substr($src, strlen(self::ASSET_PREFIX)));
            }
            foreach ($marks as $mark) {
                if (! in_array($mark['type'] ?? null, ['bold', 'italic', 'link', 'code', 'strike', 'underline', 'subscript', 'superscript'], true) ||
                    ! is_array($markAttrs = $mark['attrs'] ?? []) || array_filter($markAttrs, fn ($value) => ! is_string($value) && $value !== null)) {
                    self::invalid();
                }
                if ($mark['type'] === 'link' && str_starts_with($href = $markAttrs['href'] ?? '', 'statamic://asset::')) {
                    if (! str_starts_with($href, 'statamic://'.self::ASSET_PREFIX)) {
                        self::invalid();
                    }
                    $paths[] = CatalogImportMedia::path(strtok(substr($href, strlen('statamic://'.self::ASSET_PREFIX)), '?#') ?: '');
                }
            }
            if (array_key_exists('content', $node)) {
                if (! is_array($node['content'])) {
                    self::invalid();
                }
                array_push($paths, ...self::images($node['content'], $depth + 1));
            }
        }

        return array_values(array_unique($paths));
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['sellissimo_description' => 'Use a formatted product description with images from the product image container.']);
    }
}
