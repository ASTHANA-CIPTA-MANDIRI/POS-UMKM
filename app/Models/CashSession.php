<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 1 sesi = 1 shift kasir (buka–tutup laci kas).
 */
#[Fillable([
    'outlet_id', 'staff_id', 'dibuka_pada', 'ditutup_pada', 'modal_awal',
    'kas_akhir_sistem', 'kas_akhir_fisik', 'selisih', 'status', 'catatan',
])]
class CashSession extends Model
{
    use BelongsToTenant, HasUuids;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'modal_awal' => 0,
        'status' => 'terbuka',
    ];

    protected function casts(): array
    {
        return [
            'dibuka_pada' => 'datetime',
            'ditutup_pada' => 'datetime',
            'modal_awal' => 'decimal:2',
            'kas_akhir_sistem' => 'decimal:2',
            'kas_akhir_fisik' => 'decimal:2',
            'selisih' => 'decimal:2',
            'status' => CashSessionStatus::class,
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * Kas yang seharusnya ada di laci menurut sistem: modal awal + uang masuk
     * - uang keluar. Dipakai saat tutup kasir untuk dibandingkan dengan hitungan
     * fisik kasir.
     */
    public function hitungKasSistem(): float
    {
        $masuk = 0.0;
        $keluar = 0.0;

        foreach ($this->movements as $movement) {
            if ($movement->tipe->isInflow()) {
                $masuk += (float) $movement->jumlah;
            } else {
                $keluar += (float) $movement->jumlah;
            }
        }

        return (float) $this->modal_awal + $masuk - $keluar;
    }

    public function isTerbuka(): bool
    {
        return $this->status === CashSessionStatus::Terbuka;
    }
}
