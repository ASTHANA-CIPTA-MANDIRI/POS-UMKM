<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cash_session_id', 'tipe', 'jumlah', 'catatan', 'oleh_user_id', 'transaction_id'])]
class CashMovement extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'tipe' => CashMovementType::class,
            'jumlah' => 'decimal:2',
        ];
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function olehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh_user_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
