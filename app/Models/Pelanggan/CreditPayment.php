<?php

namespace App\Models\Pelanggan;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu setoran pelunasan kasbon.
 *
 * Barisnya HANYA boleh lahir lewat App\Actions\Kasbon\CatatPelunasanAction, dan alasannya
 * bukan kerapian: `credit_ledgers.jumlah_dibayar` adalah angka turunan yang disimpan, dan
 * aksi itulah satu-satunya tempat yang menghitungnya ulang. Membuat baris di sini langsung
 * lewat CreditPayment::create() menghasilkan setoran yang tercatat di riwayat tapi TIDAK
 * mengurangi utang di layar mana pun — pelanggan yang sudah membayar tetap tertagih.
 */
#[Fillable([
    'credit_ledger_id', 'diterima_oleh', 'jumlah', 'dibayar_pada', 'metode', 'catatan',
])]
class CreditPayment extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:2',
            'dibayar_pada' => 'datetime',
        ];
    }

    public function creditLedger(): BelongsTo
    {
        return $this->belongsTo(CreditLedger::class);
    }

    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh');
    }

    /**
     * Tanggal setor dalam bahasa Indonesia.
     *
     * `translatedFormat` dengan locale yang dipaksa 'id': APP_LOCALE aplikasi ini 'en', jadi
     * tanpa itu bulannya tercetak "August" di layar yang seluruhnya berbahasa Indonesia.
     * Pola yang sama dipakai di seluruh accessor tanggal proyek ini.
     */
    public function getDibayarPadaFormattedAttribute(): string
    {
        return $this->dibayar_pada->locale('id')->translatedFormat('j M Y');
    }
}
