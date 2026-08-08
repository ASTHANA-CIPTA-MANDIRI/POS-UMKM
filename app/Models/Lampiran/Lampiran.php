<?php

namespace App\Models\Lampiran;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * Satu berkas bukti yang menempel ke sebuah dokumen (nota belanja hari ini; kasbon dan
 * gaji menyusul).
 *
 * `tenant_id` TIDAK fillable — diisi trait BelongsToTenant. Kalau ia bisa diisi dari
 * muatan, lampiran bisa ditulis atas nama tenant lain dan tidak ada satu pun gerbang di
 * atasnya yang akan menolak.
 */
#[Fillable(['lampirable_type', 'lampirable_id', 'path', 'nama_asli', 'mime', 'ukuran', 'urutan', 'diunggah_oleh'])]
class Lampiran extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'lampiran';

    /** Disk privat: tidak disajikan web server, hanya lewat rute berpenjaga. */
    public const DISK = 'lampiran';

    protected function casts(): array
    {
        return [
            'ukuran' => 'integer',
            'urutan' => 'integer',
        ];
    }

    public function lampirable(): MorphTo
    {
        return $this->morphTo();
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }

    /**
     * Berkasnya benar-benar ada di disk?
     *
     * Baris boleh tertinggal tanpa berkas (salinan basis data dibawa ke mesin lain, berkas
     * terhapus di luar aplikasi). Layar memeriksa ini supaya yang muncul kotak kosong yang
     * jujur, bukan gambar rusak.
     */
    public function ada(): bool
    {
        return Storage::disk(self::DISK)->exists($this->path);
    }

    public function gambar(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function pdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /** Ukuran yang bisa dibaca orang; dipakai di kartu dokumen PDF. */
    public function ukuranTerbaca(): string
    {
        $kb = $this->ukuran / 1024;

        return $kb >= 1024
            ? number_format($kb / 1024, 1, ',', '.').' MB'
            : number_format(max(1, $kb), 0, ',', '.').' KB';
    }

    /**
     * Nama untuk ditampilkan; nama asli boleh kosong (mis. hasil potret kamera lama).
     *
     * Dipotong di TENGAH, bukan di ujung: "invoice-grosir-agustus-2026.pdf" yang terpotong
     * jadi "invoice-grosir-agu…" kehilangan justru bagian yang membedakannya dari invoice
     * bulan lain.
     */
    public function namaTampil(int $maks = 34): string
    {
        $nama = $this->nama_asli !== null && $this->nama_asli !== ''
            ? $this->nama_asli
            : 'lampiran-'.substr((string) $this->getKey(), 0, 8);

        if (mb_strlen($nama) <= $maks) {
            return $nama;
        }

        $depan = (int) floor(($maks - 1) / 2);

        return mb_substr($nama, 0, $depan).'…'.mb_substr($nama, -($maks - 1 - $depan));
    }
}
