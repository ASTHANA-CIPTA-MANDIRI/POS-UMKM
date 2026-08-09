<?php

namespace App\Actions\Biaya;

use App\Models\Biaya\BiayaOperasional;
use Illuminate\Support\Carbon;

/**
 * Beban operasional warung per HARI — satu rumus, satu tempat.
 *
 * KENAPA SATU PINTU, alasan yang sama dengan HitungHppAction: angka ini akan dibaca margin
 * bersih, saran harga jual, dan titik impas. Rumus yang disalin ke tiap layar cepat atau
 * lambat menjawab berbeda, dan yang diperdebatkan adalah apakah warungnya untung.
 *
 * OUTLET. Biaya bercabang (sewa, listrik) hanya membebani cabangnya; biaya seluruh warung
 * (gaji pemilik, internet) membebani semuanya. Saat dihitung UNTUK satu cabang, keduanya
 * ikut — kalau tidak, cabang yang sewanya mahal terlihat sama beratnya dengan cabang yang
 * menumpang. Saat dihitung tanpa cabang, semuanya ikut satu kali.
 *
 * YANG SENGAJA TIDAK DILAKUKAN: membagi biaya seluruh warung ke tiap cabang secara rata.
 * Terdengar adil dan menyesatkan — pembagiannya butuh dasar (omzet? jumlah karyawan?) yang
 * belum diputuskan pemilik, dan pembagi yang salah membuat satu cabang terlihat rugi karena
 * menanggung beban cabang lain. Sampai dasarnya diputuskan, angka per cabang adalah
 * "biaya cabang ini DITAMBAH biaya bersama", dan layarnya wajib mengatakan itu.
 */
class HitungBiayaHarianAction
{
    /**
     * @param  ?string  $outletId  null = seluruh warung
     * @return array{
     *     perHari: float,
     *     perBulan: float,
     *     rincian: array<int, array{nama: string, periode: string, nominal: float, perHari: float, cabang: ?string}>,
     * }
     */
    public function untuk(?string $outletId = null, ?Carbon $tanggal = null): array
    {
        $baris = BiayaOperasional::query()
            ->with('outlet:id,outlet_name')
            ->berlaku($tanggal)
            /*
             * Biaya milik cabang LAIN dibuang; biaya bersama (outlet_id null) selalu ikut.
             *
             * Tanpa cabang yang diminta, tidak ada yang dibuang sama sekali — pemilik yang
             * melihat angka seluruh warung memang ingin melihat semuanya.
             */
            ->when($outletId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('outlet_id')
                ->orWhere('outlet_id', $outletId)))
            ->orderByDesc('nominal')
            ->get();

        $perHari = 0.0;
        $rincian = [];

        foreach ($baris as $biaya) {
            $harian = $biaya->perHari();
            $perHari += $harian;

            $rincian[] = [
                'nama' => $biaya->nama,
                'periode' => $biaya->periode->label(),
                'nominal' => (float) $biaya->nominal,
                'perHari' => $harian,
                'cabang' => $biaya->outlet?->outlet_name,
            ];
        }

        $perHari = round($perHari, 2);

        return [
            'perHari' => $perHari,
            /*
             * Per bulan dihitung dari angka HARIAN x 30, bukan dari menjumlah nominal bulanan.
             *
             * Bedanya terasa begitu ada biaya mingguan atau tahunan: menjumlah nominal apa
             * adanya akan menambahkan sewa setahun penuh ke total sebulan. Satu arah
             * konversi saja, dan arahnya lewat hari.
             */
            'perBulan' => round($perHari * 30, 2),
            'rincian' => $rincian,
        ];
    }
}
