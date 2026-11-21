<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

class Downloads
{
    public function __construct(private Store $store, private Refunds $refunds) {}

    public static function paths($value): array
    {
        $paths = [];
        foreach ((array) $value as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, '\\') || preg_match('~(?:^/|(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1F])~', $path) || str_starts_with($path, 'order-artwork/')) {
                continue;
            }
            $paths[$path] = $path;
        }

        return array_values($paths);
    }

    public function count(string $order, array $line): int
    {
        return (int) ($this->store->table('download_usage')->where('order_id', $order)->where('line_key', $line['key'] ?? $line['sku'])->value('downloads') ?? 0);
    }

    public function serve(object $order, array $line, bool $head)
    {
        abort_unless($this->refunds->downloadable($order, $line), 404);
        $paths = self::paths($line['file'] ?? null);
        abort_unless($paths, 404);
        $disk = Storage::disk(config('sellissimo.download_disk'));
        foreach ($paths as $path) {
            abort_unless($disk->exists($path), 404);
        }
        $pack = count($paths) > 1;
        $filename = $pack ? $line['sku'].'.zip' : basename($paths[0]);
        if ($head) {
            return response()->streamDownload(static function () {}, $filename);
        }
        $archive = null;
        $stream = null;
        try {
            if ($pack) {
                $archive = $this->archive($disk, $paths);
            } else {
                $stream = $disk->readStream($paths[0]);
                abort_unless(is_resource($stream), 404);
            }
            $this->store->db()->transaction(function () use ($order, $line) {
                $current = $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $key = $line['key'] ?? $line['sku'];
                $fresh = LineOptions::find(json_decode($current->receipt, true)['lines'], $key);
                abort_unless($fresh && ($fresh['file'] ?? null) === $line['file'] && $this->refunds->downloadable($current, $fresh), 404);
                $count = $this->count($order->id, $fresh) + 1;
                $this->store->table('download_usage')->upsert([['order_id' => $order->id, 'line_key' => $key,
                    'downloads' => $count]], ['order_id', 'line_key'], ['downloads']);
                app(Webhooks::class)->activity('product.downloaded', hash('sha256', $order->id.'|'.$key.'|'.$count), [
                    'line_key' => $key, 'sku' => $fresh['sku'], 'downloads' => $count,
                ], $current);
            }, 3);
            if ($pack) {
                return response()->download($archive, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
            }

            return response()->streamDownload(static function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, $filename, ['Content-Type' => 'application/octet-stream']);
        } catch (\Throwable $e) {
            if ($archive) {
                unlink($archive);
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $e;
        }
    }

    private function archive($disk, array $paths): string
    {
        $output = tempnam(sys_get_temp_dir(), 'sellissimo-pack-');
        $zip = new ZipArchive;
        $sources = [];
        $names = [];
        try {
            throw_unless($output && $zip->open($output, ZipArchive::OVERWRITE) === true, 'Could not open the download archive.');
            foreach ($paths as $path) {
                $file = tmpfile();
                throw_unless($file, 'Could not prepare a download file.');
                $sources[] = $file;
                $input = $disk->readStream($path);
                abort_unless(is_resource($input), 404);
                try {
                    throw_if(stream_copy_to_stream($input, $file) === false, 'Could not read the download file.');
                } finally {
                    fclose($input);
                }
                fflush($file);
                $base = basename($path);
                $name = $base;
                for ($n = 2; isset($names[mb_strtolower($name)]); $n++) {
                    $name = $n.'-'.$base;
                }
                $names[mb_strtolower($name)] = true;
                throw_unless($zip->addFile(stream_get_meta_data($file)['uri'], $name), 'Could not add a download file.');
            }
            throw_unless($zip->close(), 'Could not finish the download archive.');

            return $output;
        } catch (\Throwable $e) {
            unset($zip);
            if ($output && is_file($output)) {
                unlink($output);
            }
            throw $e;
        } finally {
            foreach ($sources as $file) {
                fclose($file);
            }
        }
    }
}
