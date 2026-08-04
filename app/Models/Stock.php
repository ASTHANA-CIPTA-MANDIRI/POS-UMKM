<?php

namespace App\Models;

use App\Enums\Satuan;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stok dicatat per outlet. Satu baris mengacu ke product_id ATAU raw_material_id
 * (tepat salah satu).
 */
#[Fillable([
    'outlet_id', 'product_id', 'raw_material_id',
    'jumlah_saat_ini', 'stok_minimum', 'tanggal_kadaluarsa',
    'opname_terakhir_pada', 'perlu_diperiksa',
])]
class Stock extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'jumlah_saat_ini' => 'decimal:3',
            'stok_minimum' => 'decimal:3',
            'tanggal_kadaluarsa' => 'date',
            'opname_terakhir_pada' => 'datetime',
            'perlu_diperiksa' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Nama item apa pun jenisnya, untuk ditampilkan di kartu stok. */
    public function namaItem(): string
    {
        return $this->product?->nama_produk ?? $this->rawMaterial?->nama ?? '-';
    }

    /**
     * Satu-satunya definisi "menipis" di tingkat SQL.
     *
     * Ada tiga tempat yang menanyakan hal yang sama — kartu dasbor, saringan daftar
     * produk, dan nanti layar stok — dan tiga salinan aturan yang sama adalah cara
     * paling mudah menghasilkan dua angka berbeda untuk satu kenyataan: owner melihat
     * "7 barang menipis" di dasbor lalu menemukan 5 baris saat saringannya dibuka.
     *
     * Syarat `stok_minimum > 0` bukan hiasan: tanpa itu SEMUA baris bersaldo nol ikut
     * terhitung menipis (0 <= 0), termasuk barang yang ambangnya belum pernah disetel,
     * dan angkanya jadi tidak berarti.
     *
     * @param  string  $tabel  nama/alias tabel stocks pada query pemanggil
     */
    public static function saringMenipis(mixed $query, string $tabel = 'stocks'): void
    {
        $query->where($tabel.'.stok_minimum', '>', 0)
            ->whereColumn($tabel.'.jumlah_saat_ini', '<=', $tabel.'.stok_minimum');
    }

    /** @param  Builder<Stock>  $query */
    public function scopeMenipis(Builder $query): void
    {
        static::saringMenipis($query, $query->getModel()->getTable());
    }

    /** Alert stok minimum — aturannya sama dengan saringMenipis(), bukan salinan lain. */
    public function isLow(): bool
    {
        return (float) $this->stok_minimum > 0
            && (float) $this->jumlah_saat_ini <= (float) $this->stok_minimum;
    }

    /** Alert produk mendekati kadaluarsa. */
    public function isExpiringSoon(int $hari = 30): bool
    {
        return $this->tanggal_kadaluarsa !== null
            && $this->tanggal_kadaluarsa->isBefore(now()->addDays($hari));
    }

    /**
     * Berapa banyak (dalam satuan dasar) yang kurang untuk mencapai ambang minimum.
     *
     * Nol kalau ambangnya belum disetel: tanpa ambang tidak ada yang bisa disebut
     * "kurang", dan mengembalikan angka positif untuk barang yang tidak dipantau akan
     * mengisi daftar belanja dengan barang yang tidak diminta siapa pun.
     *
     * Stok minus ikut terhitung: minimum 5 dengan saldo −3 berarti kurang 8, karena
     * delapan itulah yang harus masuk supaya catatannya kembali ke ambang.
     */
    public function kekurangan(): float
    {
        $minimum = (float) $this->stok_minimum;

        if ($minimum <= 0) {
            return 0.0;
        }

        return max(0.0, $minimum - (float) $this->jumlah_saat_ini);
    }

    /**
     * Kekurangan yang sama, tapi dinyatakan dalam satuan BELI (mis. dus), karena itulah
     * yang bisa dibawa ke grosir. Warung tidak bisa membeli 2,4 dus — jadi dibulatkan
     * ke ATAS. Membulatkan ke bawah berarti pulang dari pasar tetap kurang barang.
     *
     * null kalau tidak ada yang perlu dibeli ATAU produk ini tidak punya konversi
     * satuan; pemanggilnya lalu menampilkan kekurangan dalam satuan dasar apa adanya.
     *
     * @return array{jumlah: float, satuan: ?Satuan, satuan_dasar: ?Satuan, isi_per_satuan: float}|null
     */
    public function kekuranganDalamSatuanBeli(): ?array
    {
        $kekurangan = $this->kekurangan();

        if ($kekurangan <= 0) {
            return null;
        }

        $produk = $this->product;
        $isi = $produk?->isi_per_satuan !== null ? (float) $produk->isi_per_satuan : null;

        if ($isi === null || $isi <= 0) {
            return null;
        }

        return [
            'jumlah' => $this->bulatkanKemasanKeAtas($kekurangan, $isi),
            'satuan' => $produk->satuan,
            'satuan_dasar' => $produk->satuan_dasar,
            'isi_per_satuan' => $isi,
        ];
    }

    /**
     * Berapa kemasan yang harus dibeli untuk menutup $kekurangan satuan dasar.
     *
     * `ceil($kekurangan / $isi)` mentah TIDAK boleh dipakai di sini. 2,1 kg dengan kemasan
     * 0,7 kg secara matematis tepat 3 kemasan, tapi dalam float biner PHP hasil
     * pembagiannya 3,00000000000000044409 — dan ceil() menaikkannya jadi 4. Blok
     * "Harus belanja" lalu menyuruh pemilik warung membeli satu dus lebih banyak daripada
     * yang benar-benar kurang, tiap kali kombinasi angkanya jatuh di titik yang meleset.
     *
     * Karena itu yang diperiksa bukan pecahan pada hasil bagi, melainkan SISA dalam satuan
     * dasar: ambil bagian bulatnya, lalu naikkan satu HANYA kalau masih ada sisa yang nyata.
     *
     * Ambang "nyata" = 0,0005 satuan dasar, dan angkanya bukan asal pilih: `stocks.jumlah_saat_ini`,
     * `stocks.stok_minimum`, dan `products.isi_per_satuan` semuanya decimal(15,3), jadi
     * jumlah terkecil yang bisa benar-benar tercatat adalah 0,001. Toleransinya diambil
     * setengah dari itu — cukup besar untuk menelan galat representasi float (yang
     * besarnya di orde 1e-16), dan tetap jauh lebih kecil daripada selisih terkecil yang
     * bisa sungguh-sungguh ada di data. Kekurangan yang memang tidak bulat tetap
     * dibulatkan ke ATAS: butuh 3,2 dus berarti beli 4, karena membeli 3 berarti pulang
     * dari grosir tetap kurang barang.
     */
    private function bulatkanKemasanKeAtas(float $kekurangan, float $isi): float
    {
        $utuh = floor($kekurangan / $isi);
        $sisa = $kekurangan - ($utuh * $isi);

        return $sisa > 0.0005 ? $utuh + 1 : $utuh;
    }

    /**
     * Status satu baris stok untuk ditampilkan sebagai lencana.
     *
     * Urutannya penting dan bukan selera: minus lebih dulu daripada menipis. Saldo −3
     * dengan ambang 5 memang "di bawah ambang", tapi menyebutnya "menipis" menyamakan
     * masalah pencatatan (angka mustahil yang perlu diopname) dengan masalah pembelian
     * (barang yang perlu dibeli lagi) — dan yang pertama tidak selesai dengan belanja.
     */
    public function statusStok(): string
    {
        $jumlah = (float) $this->jumlah_saat_ini;

        return match (true) {
            $jumlah < 0 => 'minus',
            $jumlah === 0.0 => 'habis',
            $this->isLow() => 'menipis',
            default => 'aman',
        };
    }
}
