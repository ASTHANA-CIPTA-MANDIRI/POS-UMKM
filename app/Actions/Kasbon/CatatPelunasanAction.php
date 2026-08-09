<?php

namespace App\Actions\Kasbon;

use App\Enums\CreditStatus;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\CreditPayment;
use App\Models\Tenant\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mencatat setoran pelunasan kasbon — SATU-SATUNYA jalan `jumlah_dibayar` berubah.
 *
 * Kenapa satu pintu, bukan `$ledger->increment('jumlah_dibayar', $jumlah)` di layar:
 * angka itu turunan, dan turunan yang bisa dinaikkan dari mana saja cepat atau lambat tidak
 * lagi sama dengan riwayatnya. Begitu keduanya berbeda, tidak ada cara memutuskan mana yang
 * benar — dan yang diperdebatkan adalah uang pelanggan.
 *
 * Angkanya DIHITUNG ULANG dari SUM(credit_payments), bukan ditambahkan. Bedanya baru terasa
 * saat ada yang salah: penambahan mewariskan setiap kekeliruan masa lalu selamanya, sedangkan
 * penghitungan ulang membuat pembatalan satu setoran otomatis mengembalikan sisa utang ke
 * angka yang benar tanpa ada yang perlu mengurangi apa pun dengan tangan.
 */
class CatatPelunasanAction
{
    /**
     * @param  float  $jumlah  Rupiah yang benar-benar diterima. Sudah lewat App\Support\Uang
     *                         di lapisan yang memanggil — di sini yang diperiksa maknanya,
     *                         bukan bentuk ketikannya.
     *
     * @throws RuntimeException
     */
    public function execute(
        CreditLedger $kasbon,
        float $jumlah,
        User $oleh,
        ?CarbonInterface $dibayarPada = null,
        ?string $metode = null,
        ?string $catatan = null,
    ): CreditPayment {
        if ($jumlah <= 0) {
            throw new RuntimeException('Jumlah setoran harus lebih dari nol.');
        }

        /*
         * Waktu setor tidak boleh di MASA DEPAN.
         *
         * Bukan kerapian: layar penagihan menjumlah setoran "hari ini", dan satu baris
         * bertanggal bulan depan membuat uang yang sudah ada di laci tidak pernah muncul di
         * rekap hari mana pun — hilang dari pandangan tanpa hilang dari basis data.
         */
        $waktu = $dibayarPada ?? now();

        if ($waktu->isAfter(now()->addMinute())) {
            throw new RuntimeException('Tanggal setornya di masa depan. Uang yang belum diterima belum bisa dicatat.');
        }

        return DB::transaction(function () use ($kasbon, $jumlah, $oleh, $waktu, $metode, $catatan) {
            /*
             * Barisnya DIKUNCI, dan ini bukan kehati-hatian yang berlebihan.
             *
             * Pemilik membuka kasbon di HP sambil kasir membukanya di tablet — keadaan biasa
             * di warung yang ramai. Tanpa kunci, keduanya membaca sisa utang Rp 100.000 dan
             * masing-masing mencatat setoran Rp 100.000: dua-duanya lolos pemeriksaan
             * "tidak melebihi sisa", dan pelanggan tercatat membayar Rp 200.000 untuk utang
             * Rp 100.000. Tidak ada galat; yang terjadi adalah warung berutang balik.
             */
            $terkunci = CreditLedger::query()
                ->whereKey($kasbon->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sisa = round((float) $terkunci->jumlah_utang - (float) $terkunci->jumlah_dibayar, 2);

            if ($sisa <= 0) {
                throw new RuntimeException('Kasbon ini sudah lunas.');
            }

            /*
             * Setoran LEBIH BESAR daripada sisa utang DITOLAK, tidak dipotong diam-diam.
             *
             * Memotongnya ke sisa utang terlihat ramah dan justru menyembunyikan dua keadaan
             * yang berbeda jauh: pemilik salah ketik satu angka nol, atau pelanggan memang
             * menyerahkan uang lebih dan berhak atas kembaliannya. Yang pertama harus
             * diperbaiki, yang kedua harus dikembalikan — dan pemotongan senyap membuat
             * keduanya berakhir sama: uangnya menguap dari catatan.
             *
             * Toleransi satu sen menahan pembulatan decimal(15,2), bukan kesalahan ketik.
             */
            if ($jumlah > $sisa + 0.01) {
                throw new RuntimeException(
                    'Setorannya lebih besar daripada sisa utang (Rp '.number_format($sisa, 0, ',', '.').'). '
                    .'Catat sebesar sisanya saja, atau perbaiki dulu jumlah utangnya.',
                );
            }

            $setoran = CreditPayment::create([
                'credit_ledger_id' => $terkunci->getKey(),
                'diterima_oleh' => $oleh->getKey(),
                'jumlah' => $jumlah,
                'dibayar_pada' => $waktu,
                'metode' => $metode,
                'catatan' => $catatan,
            ]);

            $this->segarkan($terkunci);

            return $setoran;
        });
    }

    /**
     * Membatalkan satu setoran yang telanjur salah dicatat.
     *
     * Soft delete, bukan hapus: yang dicari orang saat angka kasbon tidak cocok dengan
     * ingatannya justru catatan yang keliru itu sendiri. Baris yang lenyap tanpa bekas
     * membuat pemilik dan pelanggan berdebat tanpa satu pun bukti di tengah.
     */
    public function batalkan(CreditPayment $setoran): void
    {
        DB::transaction(function () use ($setoran) {
            $kasbon = CreditLedger::query()
                ->whereKey($setoran->credit_ledger_id)
                ->lockForUpdate()
                ->firstOrFail();

            $setoran->delete();

            $this->segarkan($kasbon);
        });
    }

    /**
     * Menghitung ulang `jumlah_dibayar` dan status dari riwayat setoran.
     *
     * Dipanggil DI DALAM transaksi yang sudah mengunci barisnya — memanggilnya dari luar
     * membuka kembali balapan yang justru dijaga kunci itu.
     */
    private function segarkan(CreditLedger $kasbon): void
    {
        // Yang dibatalkan tidak ikut: relasinya membawa SoftDeletingScope milik CreditPayment.
        $dibayar = round((float) $kasbon->payments()->sum('jumlah'), 2);

        /*
         * SISA DI BAWAH SATU RUPIAH DIANGGAP LUNAS, bukan cuma sisa nol.
         *
         * Bukan kelonggaran untuk pembulatan: `credit_ledgers.jumlah_utang` boleh bersen
         * (decimal(15,2), dan kasbon yang lahir dari struk berpajak bisa membawa 100000.50),
         * sementara SELURUH nominal yang bisa diketik orang dibaca App\Support\Uang yang
         * menolak desimal. Tanpa ambang ini, kasbon bersen tidak akan PERNAH lunas: setoran
         * terbesar yang sah adalah 100000, sisanya Rp 0,50 selamanya, dan barisnya menetap
         * di daftar penagihan sebagai utang yang tidak bisa diselesaikan siapa pun.
         *
         * Satu rupiah, bukan seratus: yang dinyatakan tidak bisa dibayar adalah pecahan yang
         * memang tidak punya wujud fisik. Rp 50 yang benar-benar terutang tetap utang.
         */
        $lunas = round((float) $kasbon->jumlah_utang, 2) - $dibayar < 1.0;

        $kasbon->update([
            'jumlah_dibayar' => $dibayar,
            'status' => $lunas ? CreditStatus::Lunas : CreditStatus::BelumLunas,
            /*
             * `dilunasi_pada` dikosongkan lagi kalau setorannya dibatalkan, dan itu penting:
             * kasbon yang berstatus belum lunas TAPI memegang tanggal pelunasan adalah baris
             * yang bercerita dua hal sekaligus, dan laporan mana pun yang membacanya akan
             * memilih salah satu — diam-diam.
             */
            'dilunasi_pada' => $lunas ? ($kasbon->dilunasi_pada ?? now()) : null,
        ]);
    }
}
