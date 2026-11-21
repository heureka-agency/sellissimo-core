<?php

use Illuminate\Database\Migrations\Migration;
use Sellissimo\Core\CargoHistory;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function up(): void
    {
        $records = app(Store::class)->table('historical_imports')->where('source_key', 'like', 'cargo:orders:%');
        foreach ($records->lazyById(100, 'source_key') as $record) {
            app(CargoHistory::class)->upgradeDownloadHistory($record);
        }
    }

    public function down(): void {}
};
