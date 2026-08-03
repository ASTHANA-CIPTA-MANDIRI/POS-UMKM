<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paket langganan platform. Entitas level platform, jadi tanpa tenant_id.
 */
#[Fillable([
    'nama_paket', 'slug', 'limit_outlet', 'limit_user', 'limit_transaksi_bulanan',
    'harga_bulanan', 'harga_bulanan_device', 'fitur_json', 'is_active', 'urutan',
])]
class Plan extends Model
{
    use HasUuids;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'harga_bulanan' => 0,
        'is_active' => true,
        'urutan' => 0,
    ];

    protected function casts(): array
    {
        return [
            'limit_outlet' => 'integer',
            'limit_user' => 'integer',
            'limit_transaksi_bulanan' => 'integer',
            'harga_bulanan' => 'decimal:2',
            'harga_bulanan_device' => 'decimal:2',
            'fitur_json' => 'array',
            'is_active' => 'boolean',
            'urutan' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** NULL pada kolom limit berarti unlimited (paket Enterprise). */
    public function allowsOutlets(int $jumlah): bool
    {
        return $this->limit_outlet === null || $jumlah <= $this->limit_outlet;
    }

    public function allowsUsers(int $jumlah): bool
    {
        return $this->limit_user === null || $jumlah <= $this->limit_user;
    }

    public function hasFitur(string $fitur): bool
    {
        return in_array($fitur, $this->fitur_json ?? [], true);
    }
}
