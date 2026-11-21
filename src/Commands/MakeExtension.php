<?php

namespace Sellissimo\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeExtension extends Command
{
    protected $signature = 'sellissimo:make-extension {kind : shipping, payment, discount or charge} {name : Extension name, such as Local Courier} {--directory= : Parent directory for the package}';
    protected $description = 'Create a local Composer addon with a registered Sellissimo provider and a PHPUnit starter test';

    private const TYPES = [
        'shipping' => ['ShippingMethod', 'shipping', 'ShippingProvider'],
        'payment' => ['PaymentGateway', 'payment', 'PaymentGateway'],
        'discount' => ['DiscountRule', 'discount', 'DiscountRule'],
        'charge' => ['OrderCharge', 'orderCharge', 'OrderChargeProvider'],
    ];

    public function handle(Filesystem $files): int
    {
        $kind = $this->argument('kind');
        $name = trim($this->argument('name'));
        if (!isset(self::TYPES[$kind]) || !preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,59}$/D', $name)) {
            $this->error('Choose shipping, payment, discount or charge and an extension name beginning with a letter.');
            return self::FAILURE;
        }
        [$suffix, $method, $contract] = self::TYPES[$kind];
        $stem = Str::studly($name);
        $handle = Str::snake($stem);
        if ($kind === 'payment' && in_array($handle, ['free', 'pay_on_delivery'], true)) {
            $this->error('Choose a different name. Sellissimo reserves this payment handle.');
            return self::FAILURE;
        }
        $slug = Str::kebab($stem).'-'.$kind;
        $class = $stem.$suffix;
        $namespace = 'StoreExtensions\\'.$class;
        $parent = $this->option('directory') ?: base_path('addons/sellissimo');
        $target = $parent.DIRECTORY_SEPARATOR.$slug;
        if ($files->exists($target)) {
            $this->error("An extension already exists at $target.");
            return self::FAILURE;
        }
        $files->ensureDirectoryExists($parent);
        $temp = $parent.'/.sellissimo-'.Str::uuid();
        try {
            $files->ensureDirectoryExists($temp.'/src');
            $files->ensureDirectoryExists($temp.'/tests');
            $tokens = ['{{namespace}}' => $namespace, '{{class}}' => $class, '{{contract}}' => $contract,
                '{{handle}}' => $handle, '{{method}}' => $method, '{{name}}' => $name];
            foreach (['provider' => 'src/ServiceProvider.php', $kind => "src/$class.php", "$kind-test" => "tests/{$class}Test.php"] as $stub => $destination) {
                $template = $files->get(__DIR__.'/../../resources/stubs/'.$stub.'.stub');
                $files->put("$temp/$destination", strtr($template, $tokens));
            }
            $manifest = ['name' => 'store-extensions/'.$slug, 'description' => $name.' for Sellissimo',
                'type' => 'library', 'require' => ['sellissimo/core' => '^1.0'],
                'require-dev' => ['phpunit/phpunit' => '^11.5 || ^12.0'],
                'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
                'extra' => ['laravel' => ['providers' => [$namespace.'\\ServiceProvider']], 'statamic' => ['name' => $name, 'slug' => $slug]],
                'scripts' => ['test' => 'phpunit tests']];
            $files->put($temp.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
            $files->moveDirectory($temp, $target) || throw new \RuntimeException("Cannot create $target.");
        } finally {
            $files->deleteDirectory($temp);
        }
        $this->info("Created $target. Install store-extensions/$slug from that local Composer path.");
        $setting = ['shipping' => 'shipping', 'payment' => 'payments', 'discount' => 'discount_rules', 'charge' => 'order_charge_providers'][$kind];
        $this->line("Enable '$handle' in sellissimo.$setting when its implementation is ready.");
        return self::SUCCESS;
    }
}
