<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'outlet_id', 'supplier_id', 'nomor_po', 'tanggal', 'total',
    'diskon', 'ongkos_kirim',
    'status', 'catatan', 'bukti_path', 'dibuat_oleh', 'diterima_pada',
])]
class PurchaseOrder extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'total' => 0,
        'diskon' => 0,
        'ongkos_kirim' => 0,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'total' => 'decimal:2',
            'diskon' => 'decimal:2',
            'ongkos_kirim' => 'decimal:2',
            'status' => DocumentStatus::class,
            'diterima_pada' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** Mutasi stok yang dihasilkan saat PO diterima. */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'referensi');
    }

    public function hitungTotal(): string
    {
        return (string) $this->items()->sum('subtotal');
    }

    /* ── Bukti belanja (foto kwitansi/struk) ─────────────────────────────── */

    /**
     * Apakah foto buktinya benar-benar ada di disk.
     *
     * Path di database bisa menggantung: berkasnya terhapus di luar aplikasi, disk dipindah,
     * atau salinan database dibawa ke mesin lain tanpa berkasnya. Tanpa pemeriksaan ini
     * layar menampilkan ikon gambar rusak — dan ikon rusak tidak menjelaskan apa pun selain
     * "ada yang salah". Pola yang sama dengan Produk::punyaGambar().
     */
    public function punyaBukti(): bool
    {
        $path = (string) $this->bukti_path;

        return $path !== '' && Storage::disk('public')->exists($path);
    }

    /**
     * URL foto bukti; null kalau tidak ada buktinya atau berkasnya hilang.
     *
     * asset(), dan itu BUKAN pilihan gaya: pembangun URL milik Storage selalu menyusun
     * alamat dari APP_URL, jadi tablet yang membuka aplikasi lewat alamat LAN
     * (192.168.x.x:8000) akan mencari gambarnya di host yang tidak melayani apa pun —
     * tanpa satu pun pesan galat, jadi gejalanya cuma "fotonya tidak muncul".
     * PembelianBuktiTest menjaga ini dari sisi sumber kode.
     */
    public function urlBukti(): ?string
    {
        if (! $this->punyaBukti()) {
            return null;
        }

        return asset('storage/'.$this->bukti_path);
    }

    /**
     * Nota dibatalkan = buktinya TERKUNCI: boleh dilihat, tidak boleh diganti/dihapus.
     *
     * Nota batal biasanya berarti barangnya dikembalikan ke grosir, dan struk itu justru
     * satu-satunya bukti pengembaliannya. Yang benar-benar menahannya ada di
     * SimpanBuktiBelanjaAction — ini hanya supaya layar bisa mengatakannya lebih dulu.
     */
    public function buktiTerkunci(): bool
    {
        return $this->status === DocumentStatus::Dibatalkan;
    }
}
