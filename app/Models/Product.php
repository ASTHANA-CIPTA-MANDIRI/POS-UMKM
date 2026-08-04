<?php

namespace App\Models;

use App\Enums\Satuan;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'kategori_id', 'nama_produk', 'sku', 'barcode', 'harga_default', 'harga_beli',
    'satuan', 'satuan_dasar', 'isi_per_satuan', 'gambar_path', 'lacak_stok',
    'pantau_kadaluarsa', 'is_active',
])]
class Product extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Disamakan dengan default di migrasi. */
    protected $attributes = [
        'harga_default' => 0,
        'satuan' => 'pcs',
        'lacak_stok' => true,
        'pantau_kadaluarsa' => false,
        'is_active' => true,
    ];

    /**
     * SKU diisi sendiri kalau dikosongkan.
     *
     * Kenapa otomatis? Karena kalau tidak, kolom ini akan kosong selamanya. Tidak ada
     * pemilik warung yang mau mengarang kode untuk 300 barang, dan begitu kodenya kosong,
     * seluruh fitur yang butuh pegangan selain nama — opname, pembelian, cetak label,
     * pencocokan barang tanpa barcode pabrik di kasir — kehilangan kuncinya. Mengisinya
     * otomatis tidak memakan waktu siapa pun, dan yang punya kode sendiri tetap bisa
     * menimpanya.
     *
     * Hanya saat DIBUAT, tidak pernah saat diubah: kode yang berubah setelah barangnya
     * diganti nama lebih buruk daripada tidak ada kode — nota, label, dan laporan lama
     * jadi menunjuk kode yang tidak ada lagi. Mengosongkannya saat mengubah juga
     * dihormati; itu keputusan pemiliknya, bukan kesalahan yang perlu diperbaiki.
     */
    protected static function booted(): void
    {
        static::creating(function (self $produk): void {
            // Dijalankan setelah BelongsToTenant mengisi tenant_id (listener trait
            // terdaftar lebih dulu), jadi nomor urutnya sudah dihitung per tenant.
            if (blank($produk->sku)) {
                $produk->sku = static::susunSku($produk->nama_produk, $produk->tenant_id);
            }
        });
    }

    /**
     * Menyusun SKU: tiga huruf pertama nama + nomor urut per tenant, mis. NAS-0001.
     *
     * Berhuruf, bukan angka murni: kode ini dibaca dan diketik manusia — di lembar
     * opname, di kolom cari, dan nanti di label rak. "NAS-0001" bisa dikenali sekilas,
     * "0000147" tidak.
     */
    public static function susunSku(?string $nama, ?string $tenantId): string
    {
        $awalan = static::awalanSku($nama);

        /*
         * withoutGlobalScopes() SENGAJA: ia melepas TenantScope (tenant_id sudah
         * disaring manual di bawah) DAN SoftDeletingScope, sehingga nomor milik produk
         * yang sudah dihapus tidak dipakai ulang. Kode yang dipakai dua barang berbeda
         * membuat laporan lama menunjuk barang yang salah.
         */
        $dasar = fn () => static::withoutGlobalScopes()->where('tenant_id', $tenantId);

        // max() atas teks berpadding nol sama dengan max numerik selama nomornya masih
        // empat digit; di atas 9999 urutannya melenceng, dan itu batas yang jauh di luar
        // jangkauan satu warung.
        $terakhir = $dasar()->where('sku', 'like', $awalan.'-%')->max('sku');
        $nomor = $terakhir === null ? 1 : ((int) substr((string) $terakhir, strlen($awalan) + 1)) + 1;

        // Diperiksa satu per satu sampai bebas: nomor terakhir bisa melompat karena kode
        // yang diketik sendiri (mis. "NAS-abc") tidak berbentuk angka.
        do {
            $kode = $awalan.'-'.str_pad((string) $nomor, 4, '0', STR_PAD_LEFT);
            $nomor++;
        } while ($dasar()->where('sku', $kode)->exists());

        return $kode;
    }

    /** Tiga huruf/angka pertama dari nama; nama tanpa huruf sama sekali jatuh ke SKU. */
    private static function awalanSku(?string $nama): string
    {
        $bersih = str($nama ?? '')->ascii()->upper()->replaceMatches('/[^A-Z0-9]/', '')->value();

        return $bersih === '' ? 'SKU' : substr($bersih, 0, 3);
    }

    protected function casts(): array
    {
        return [
            'harga_default' => 'decimal:2',
            'harga_beli' => 'decimal:2',
            'satuan' => Satuan::class,
            'satuan_dasar' => Satuan::class,
            'isi_per_satuan' => 'decimal:3',
            'lacak_stok' => 'boolean',
            'pantau_kadaluarsa' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'kategori_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Bahan baku penyusun menu ini (khusus FnB). */
    public function rawMaterials(): BelongsToMany
    {
        return $this->belongsToMany(RawMaterial::class, 'recipe_items')
            ->withPivot('jumlah_terpakai')
            ->withTimestamps();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Menu berbasis resep mengurangi stok BAHAN BAKU saat terjual, bukan stok
     * produk jadi — jadi produk seperti ini tidak perlu lacak_stok sendiri.
     */
    public function usesRecipe(): bool
    {
        // Pakai koleksi yang sudah di-eager-load kalau tersedia, supaya sinkronisasi
        // batch besar tidak menembakkan satu query exists() per baris item.
        return $this->relationLoaded('recipeItems')
            ? $this->recipeItems->isNotEmpty()
            : $this->recipeItems()->exists();
    }

    /** Harga varian menimpa harga produk induk kalau diisi. */
    public function hargaUntuk(?ProductVariant $variant = null): string
    {
        return (string) ($variant?->harga_override ?? $this->harga_default);
    }

    /**
     * Konversi ke satuan dasar untuk pencatatan stok (mis. 1 dus = 12 pcs).
     * Tanpa konfigurasi konversi, qty dianggap sudah dalam satuan dasar.
     */
    public function keSatuanDasar(float $qty): float
    {
        return $this->isi_per_satuan === null
            ? $qty
            : $qty * (float) $this->isi_per_satuan;
    }

    /**
     * Status stok dari kolom yang IKUT DI-JOIN oleh daftar produk.
     *
     * Daftar produk menempelkan `jumlah_saat_ini` & `stok_minimum` lewat left join, bukan
     * memuat relasi `stocks` — satu kueri untuk 15 baris, bukan enam belas. Tapi aturan
     * "minus / habis / menipis / aman" TIDAK boleh ditulis ulang di sini maupun di Blade:
     * begitu ada dua tempat yang memutuskannya, dasbor dan daftar akan berbeda suatu hari
     * dan itu terbaca sebagai cacat. Jadi nilainya dititipkan ke satu Stock sementara, dan
     * Stock-lah yang memutuskan.
     *
     * null berarti barang ini memang tidak punya angka stok: belum pernah dihitung, atau
     * memang tidak dilacak (jasa, menu berbasis resep). Pemanggilnya wajib menampilkan
     * penanda "—", bukan angka nol — nol adalah pernyataan bahwa barangnya habis.
     */
    public function statusStokTerjoin(): ?string
    {
        if ($this->jumlah_saat_ini === null) {
            return null;
        }

        $sementara = new Stock([
            'jumlah_saat_ini' => $this->jumlah_saat_ini,
            'stok_minimum' => $this->stok_minimum ?? 0,
        ]);

        return $sementara->statusStok();
    }

    public function stokDiOutlet(string $outletId): ?Stock
    {
        return $this->stocks()->where('outlet_id', $outletId)->first();
    }
}
