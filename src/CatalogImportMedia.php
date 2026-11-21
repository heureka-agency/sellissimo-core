<?php

namespace Sellissimo\Core;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use ZipArchive;

class CatalogImportMedia
{
    private const MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif'];

    public static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public static function path(string $path): string
    {
        if (strlen($path) > 220 || ! preg_match('~^(?:[\pL\pN_-][\pL\pN_ .-]*/)*[\pL\pN_-][\pL\pN_ .-]*\.(?:jpe?g|png|gif|webp|avif)$~iuD', $path)
            || str_contains($path, '..') || str_contains($path, '::') || preg_match('~(?:^|/)[^/]+\.(?:php[0-9]*|phtml|phar|cgi|pl)\.~i', $path)) {
            self::invalid('Use a relative product image path with an accepted image extension.');
        }

        return $path;
    }

    public static function storage()
    {
        return Storage::disk(config('sellissimo.import_disk', 'local'));
    }

    /** Read archive members individually. No archive paths are ever extracted to the filesystem. */
    public function unpack(UploadedFile $file, string $job): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            if ($file->getSize() > 16 * 1024 * 1024) {
                self::invalid('A catalog CSV may contain up to 16 MiB.');
            }

            return [[$file->get()], []];
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            self::invalid('Choose a valid ZIP archive.');
        }
        try {
            if ($zip->numFiles > 3000) {
                self::invalid('The archive contains too many members.');
            }
            $csv = $images = $seen = [];
            $total = $csvBytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $member = $zip->statIndex($i);
                $name = $member['name'];
                if (str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true) || str_contains($name, "\0")) {
                    self::invalid('Use relative archive paths without parent directories.');
                }
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                if ($os === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    self::invalid('The archive contains a symbolic link.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (isset($seen[$name])) {
                    self::invalid('The archive contains a duplicate filename.');
                }
                $seen[$name] = true;
                $total += $member['size'];
                if ($total > 100 * 1024 * 1024 || ($member['encryption_method'] ?? 0) !== 0) {
                    self::invalid('Choose an unencrypted archive with up to 100 MiB of expanded data.');
                }
                if (preg_match('~^[\w.-]+\.csv$~iD', $name)) {
                    $csvBytes += $member['size'];
                    if ($csvBytes > 16 * 1024 * 1024) {
                        self::invalid('Catalog CSVs in a batch may contain up to 16 MiB.');
                    }
                    $bytes = $zip->getFromIndex($i);
                    if ($bytes === false || strlen($bytes) !== $member['size']) {
                        self::invalid('A catalog CSV could not be read in full.');
                    }
                    $csv[$name] = $bytes;

                    continue;
                }
                if (! str_starts_with($name, 'images/') || $member['size'] > 10 * 1024 * 1024) {
                    self::invalid('Place catalog CSVs at the archive root and images up to 10 MiB under images/.');
                }
                $path = self::path(substr($name, 7));
                $bytes = $zip->getFromIndex($i);
                $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
                if (! $info || $info['mime'] !== self::MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] || $info[0] * $info[1] > 50000000) {
                    self::invalid("The image format or dimensions are invalid for $path.");
                }
                // Dimensions alone can be present in a truncated file. Verify the pixel data using the site's driver.
                $driver = match (config('statamic.assets.image_manipulation.driver', 'gd')) {
                    'gd' => \Intervention\Image\Drivers\Gd\Driver::class,
                    'imagick' => \Intervention\Image\Drivers\Imagick\Driver::class,
                    default => config('statamic.assets.image_manipulation.driver'),
                };
                try {
                    $manager = new \Intervention\Image\ImageManager(new $driver);
                    if (method_exists($manager, 'decodeBinary')) {
                        $manager->decodeBinary($bytes);
                    } else {
                        $manager->read($bytes);
                    }
                } catch (\Throwable $error) {
                    self::invalid("The store's image driver could not decode $path.");
                }
                $hash = hash('sha256', $bytes);
                $staged = "sellissimo-imports/$job/$hash";
                if (! self::storage()->put($staged, $bytes, 'private')) {
                    throw new \RuntimeException('Could not stage product image.');
                }
                $images[$path] = compact('hash', 'staged');
            }
            if (! $csv) {
                self::invalid('Add a catalog CSV to the root of the ZIP.');
            }
            uksort($csv, 'strnatcasecmp');

            return [array_values($csv), $images];
        } finally {
            $zip->close();
        }
    }

    public function term(string $taxonomyHandle, string $slug, string $site, $user, bool $apply = false): bool
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 150) {
            self::invalid('Use lowercase term handles separated by hyphens.');
        }
        $taxonomy = Taxonomy::findByHandle($taxonomyHandle);
        if (! $taxonomy || ! $taxonomy->sites()->contains($site)) {
            self::invalid("The $taxonomyHandle taxonomy is unavailable on this site.");
        }
        if (Term::find($taxonomyHandle.'::'.$slug)) {
            return false;
        }
        if (! $user->can('create', [TermContract::class, $taxonomy, Site::get($site)])) {
            self::invalid("Permission to create $taxonomyHandle terms is required for $slug.");
        }
        if ($apply) {
            $term = Term::make($slug)->taxonomy($taxonomy);
            $term->in($site)->set('title', Str::headline($slug));
            if ($term->save() === false) {
                self::invalid("The site declined to save term $slug.");
            }
        }

        return true;
    }

    public function image(string $path, ?array $staged, $user, bool $apply = false): bool
    {
        self::path($path);
        $container = AssetContainer::find('sellissimo_products');
        if (! $container || ! $user->can('view', $container)) {
            self::invalid('Permission to browse the product image container is required.');
        }
        // Other processes can write product images between preview and apply. Check the disk itself.
        if ($container->disk()->filesystem()->exists($path)) {
            if (! $container->makeAsset($path)->isImage()) {
                self::invalid("$path is not a product image.");
            }
            if ($staged) {
                $stream = $container->disk()->filesystem()->readStream($path);
                $hash = hash_init('sha256');
                try {
                    hash_update_stream($hash, $stream);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($staged['hash'], hash_final($hash))) {
                    self::invalid("An image with different contents already exists at $path.");
                }
            }

            return false;
        }
        if (! $staged || ! $user->can('store', [AssetContract::class, $container])) {
            self::invalid("Supply images/$path in the ZIP and ensure you have upload permission.");
        }
        if ($apply) {
            $input = self::storage()->readStream($staged['staged']);
            if (! $input) {
                self::invalid('A staged image is missing. Upload the archive again.');
            }
            try {
                if (! $container->disk()->filesystem()->put($path, $input)) {
                    throw new \RuntimeException("Could not write product image $path.");
                }
            } finally {
                fclose($input);
            }
            try {
                if ($container->makeAsset($path)->save() === false) {
                    self::invalid("The site declined to save image $path.");
                }
            } catch (\Throwable $error) {
                $container->disk()->filesystem()->delete($path);
                throw $error;
            }
        }

        return true;
    }
}
