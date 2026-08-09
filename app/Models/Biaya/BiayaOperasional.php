<?php

namespace App\Models\Biaya;

use App\Enums\PeriodeBiaya;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant\Outlet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu biaya operasional yang berulang: sewa, listrik, gaji, gas.
 *
 * ANGKA PERENCANAAN, bukan transaksi — lihat alasan lengkapnya di migrasinya. Model ini tidak
 * pernah membuat baris kas; ia hanya menjawab "berapa beban warung per hari".
 */
#[Fillable(['outlet_id', 'nama', 'nominal', 'periode', 'mulai', 'selesai', 'catatan'])]
class BiayaOperasional extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'biaya_operasional';

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'periode' => 'bulanan',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'periode' => PeriodeBiaya::class,
            'mulai' => 'date',
            'selesai' => 'date',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** Nominal yang jatuh ke satu hari, apa pun periodenya. */
    public function perHari(): float
    {
        return $this->periode->perHari((float) $this->nominal);
    }

    /**
     * Apakah biaya ini berlaku pada tanggal tertentu.
     *
     * Batasnya INKLUSIF di kedua ujung: sewa yang berlaku 1–31 Agustus memang membebani
     * tanggal 31 juga. Batas eksklusif membuat satu hari hilang tiap periode, dan hilangnya
     * tidak pernah terlihat karena angkanya cuma turun sedikit.
     */
    public function berlakuPada(?Carbon $tanggal = null): bool
    {
        $tanggal = ($tanggal ?? now())->startOfDay();

        return ! $this->mulai->startOfDay()->greaterThan($tanggal)
            && ($this->selesai === null || ! $this->selesai->startOfDay()->lessThan($tanggal));
    }

    /**
     * Hanya yang berlaku pada tanggal tertentu.
     *
     * Disaring DI BASIS DATA, bukan di PHP sesudah diambil: hitungan biaya harian nanti
     * dipakai laporan yang berjalan per hari selama sebulan, dan menyaring 200 baris di PHP
     * tiga puluh kali adalah pekerjaan yang tidak perlu.
     *
     * @param  Builder<self>  $kueri
     */
    public function scopeBerlaku(Builder $kueri, ?Carbon $tanggal = null): void
    {
        $tanggal = ($tanggal ?? now())->toDateString();

        $kueri->whereDate('mulai', '<=', $tanggal)
            ->where(fn (Builder $q) => $q->whereNull('selesai')->orWhereDate('selesai', '>=', $tanggal));
    }
}
