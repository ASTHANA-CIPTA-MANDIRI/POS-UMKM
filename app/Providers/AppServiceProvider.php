<?php

namespace App\Providers;

use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use App\Models\Transaction;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WAJIB singleton: TenantScope & BelongsToTenant membaca instance yang sama
        // dengan yang diisi middleware ResolveTenantContext. Kalau tidak singleton,
        // setiap app() menghasilkan instance baru tanpa tenant dan filter isolasi
        // data tidak akan pernah aktif.
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alias morph untuk stock_movements.referensi_type, supaya kolom tidak
        // menyimpan nama kelas penuh dan aman saat kelas dipindah namespace.
        Relation::enforceMorphMap([
            'purchase_order' => PurchaseOrder::class,
            'stock_transfer' => StockTransfer::class,
            'transaction' => Transaction::class,
        ]);

        // Mass assignment tidak boleh menembus kolom di luar daftar fillable —
        // penting karena tenant_id sengaja tidak fillable di semua model.
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());
    }
}
