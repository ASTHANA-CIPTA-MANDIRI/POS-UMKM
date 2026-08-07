<?php

namespace App\Models\Pelanggan;

use App\Enums\CreditStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Kasir\Transaction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['nama', 'no_hp', 'email', 'tanggal_lahir', 'poin'])]
class Customer extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'poin' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function creditLedgers(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }

    /** Total kasbon yang belum lunas — dipakai di rekap utang pelanggan. */
    public function totalUtang(): float
    {
        return (float) $this->creditLedgers()
            ->where('status', CreditStatus::BelumLunas)
            ->sum(DB::raw('jumlah_utang - jumlah_dibayar'));
    }
}
