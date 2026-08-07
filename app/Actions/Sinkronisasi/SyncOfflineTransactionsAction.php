<?php

namespace App\Actions\Sinkronisasi;

use App\Actions\Stok\ApplySaleToStockAction;
use App\Enums\BillStatus;
use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionOrigin;
use App\Models\Kas\CashMovement;
use App\Models\Kas\CashSession;
use App\Models\Kasir\Bill;
use App\Models\Kasir\Transaction;
use App\Models\Kasir\TransactionItem;
use App\Models\Kasir\TransactionPayment;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Sistem\SyncLog;
use App\Models\Tenant\User;
use App\Support\SyncResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menerima batch transaksi yang dibuat kasir saat offline, lalu menerapkannya
 * seolah-olah transaksi itu terjadi online.
 *
 * Sifat penting yang dijaga di sini:
 *
 * 1. IDEMPOTEN — id transaksi dibuat di perangkat (UUID), jadi batch yang sama
 *    dikirim berulang kali hanya menghasilkan satu baris. Tanpa ini, koneksi yang
 *    putus tepat setelah server menyimpan tapi sebelum perangkat menerima balasan
 *    akan membuat transaksi dobel — dan omzet ikut dobel.
 *
 * 2. PER-TRANSAKSI ATOMIK — setiap transaksi diproses dalam DB transaction
 *    tersendiri. Satu transaksi bermasalah (mis. produk sudah dihapus owner) tidak
 *    membatalkan transaksi lain di batch yang sama, karena kasir tidak punya cara
 *    memperbaiki data yang sudah tercetak di struk.
 *
 * 3. OUTLET DIVALIDASI DARI SESI LOGIN — outlet_id dari perangkat tidak dipercaya
 *    begitu saja; dicek lewat User::canAccessOutlet() supaya perangkat tidak bisa
 *    menyuntik transaksi ke outlet lain.
 */
class SyncOfflineTransactionsAction
{
    public function __construct(private ApplySaleToStockAction $applyStock) {}

    /**
     * @param  array{outlet_id: string, device_id?: string|null, transactions: array<int, array<string, mixed>>}  $payload
     */
    public function execute(User $user, array $payload): SyncResult
    {
        $outletId = $payload['outlet_id'];
        $transaksiMasuk = $payload['transactions'] ?? [];

        $dibuat = 0;
        $duplikat = 0;
        $gagal = [];

        if (! $user->canAccessOutlet($outletId)) {
            // Seluruh batch ditolak: perangkat mengklaim outlet yang bukan haknya.
            $gagal = array_map(
                fn (array $t) => ['id' => (string) ($t['id'] ?? '-'), 'alasan' => 'Outlet tidak diizinkan untuk user ini.'],
                $transaksiMasuk,
            );

            return $this->catat($user, $payload, new SyncResult(
                count($transaksiMasuk), 0, 0, count($gagal), $gagal,
            ));
        }

        /*
         * Bill diproses LEBIH DULU: transaksi Mode B/C mengacu ke bill_id, jadi
         * kalau urutannya dibalik, transaksi akan gagal karena bill-nya belum ada.
         */
        [$billDibuat, $billDiperbarui] = $this->sinkronBills($user, $outletId, $payload['bills'] ?? []);

        foreach ($transaksiMasuk as $data) {
            $id = (string) ($data['id'] ?? '');

            if ($id === '') {
                $gagal[] = ['id' => '-', 'alasan' => 'Transaksi tanpa id tidak bisa disinkronkan.'];

                continue;
            }

            // withTrashed: transaksi yang sudah di-void/soft delete tetap dianggap
            // ada, supaya sinkronisasi ulang tidak menghidupkannya kembali.
            if (Transaction::withTrashed()->whereKey($id)->exists()) {
                $duplikat++;

                continue;
            }

            try {
                $this->pastikanUangnyaMasukHitungan($data);

                DB::transaction(function () use ($user, $outletId, $data) {
                    $this->simpanSatuTransaksi($user, $outletId, $data);
                });

                $dibuat++;
            } catch (Throwable $e) {
                Log::warning('Sinkronisasi transaksi offline gagal', [
                    'transaction_id' => $id,
                    'outlet_id' => $outletId,
                    'error' => $e->getMessage(),
                ]);

                $gagal[] = ['id' => $id, 'alasan' => $e->getMessage()];
            }
        }

        return $this->catat($user, $payload, new SyncResult(
            count($transaksiMasuk), $dibuat, $duplikat, count($gagal), $gagal,
            $billDibuat, $billDiperbarui,
        ));
    }

    /**
     * Menyinkronkan bill Mode B (buka bill) & Mode C (titip-ambil).
     *
     * Bill juga dibuat di perangkat — kasir warteg membuka bill di meja walau sinyal
     * mati — jadi id-nya UUID buatan klien dan operasi ini idempoten.
     *
     * Perubahan status HANYA diterima kalau maju. Antrean offline bisa tiba tidak
     * berurutan; tanpa penjagaan ini paket lama bisa memundurkan status bill yang
     * sudah lebih maju, dan pelanggan diberi tahu cuciannya belum selesai padahal
     * sudah siap diambil.
     *
     * @return array{0: int, 1: int} [jumlah dibuat, jumlah diperbarui]
     */
    private function sinkronBills(User $user, string $outletId, array $bills): array
    {
        $dibuat = 0;
        $diperbarui = 0;

        foreach ($bills as $data) {
            $id = (string) ($data['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $statusMasuk = BillStatus::tryFrom($data['status'] ?? '') ?? BillStatus::Terbuka;
            $bill = Bill::withTrashed()->find($id);

            if ($bill === null) {
                $baru = new Bill([
                    'id' => $id,
                    'outlet_id' => $outletId,
                    'mode' => $data['mode'],
                    'status' => $statusMasuk,
                    'label' => $data['label'],
                    'customer_id' => $data['customer_id'] ?? null,
                    'dibuka_oleh' => $user->getKey(),
                    'dibuka_pada' => $data['dibuka_pada'] ?? now(),
                    'estimasi_selesai' => $data['estimasi_selesai'] ?? null,
                    'catatan' => $data['catatan'] ?? null,
                ]);

                $baru->tenant_id = $user->tenant_id;

                if ($statusMasuk === BillStatus::SelesaiDibayar) {
                    $baru->ditutup_pada = now();
                }

                $baru->save();
                $dibuat++;

                continue;
            }

            if ($statusMasuk->urutan() <= $bill->status->urutan()) {
                continue;
            }

            $bill->status = $statusMasuk;

            if ($statusMasuk === BillStatus::SelesaiDibayar) {
                $bill->ditutup_pada = now();
            }

            if (isset($data['estimasi_selesai'])) {
                $bill->estimasi_selesai = $data['estimasi_selesai'];
            }

            $bill->save();
            $diperbarui++;
        }

        return [$dibuat, $diperbarui];
    }

    private function simpanSatuTransaksi(User $user, string $outletId, array $data): Transaction
    {
        $transaction = new Transaction([
            'id' => $data['id'],
            'outlet_id' => $outletId,
            'bill_id' => $data['bill_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'staff_id' => $data['staff_id'] ?? $user->getKey(),
            'device_id' => $data['device_id'] ?? $user->device_id_terikat,
            'nomor_transaksi' => $data['nomor_transaksi'],
            'mode' => $data['mode'],
            'subtotal' => $data['subtotal'] ?? 0,
            'diskon' => $data['diskon'] ?? 0,
            'pajak' => $data['pajak'] ?? 0,
            'service_charge' => $data['service_charge'] ?? 0,
            'total' => $data['total'] ?? 0,
            'status' => $data['status'] ?? 'lunas',
            'waktu_transaksi' => $data['waktu_transaksi'],
            /*
             * Asal transaksi dikirim klien, tidak dipaksa "offline" di sini.
             * Layar kasir memakai endpoint ini untuk KEDUA keadaan — saat online ia
             * mengirim langsung, saat mati jaringan ia mengantre lalu mengirim
             * belakangan. Satu jalur simpan untuk dua keadaan berarti tidak ada
             * kemungkinan dua kode berbeda perilaku. Tapi kalau semuanya dicap
             * "offline", laporan tidak bisa lagi membedakan transaksi yang sempat
             * tertahan dari yang mulus — padahal itu justru yang perlu dipantau.
             *
             * Default tetap offline agar klien lama yang belum mengirim kolom ini
             * tidak berubah perilakunya.
             */
            'origin' => TransactionOrigin::tryFrom($data['origin'] ?? '') ?? TransactionOrigin::Offline,
            'dibuat_offline_pada' => $data['dibuat_offline_pada'] ?? $data['waktu_transaksi'],
        ]);

        $transaction->tenant_id = $user->tenant_id;
        $transaction->disinkronkan_pada = now();
        $transaction->save();

        foreach ($data['items'] ?? [] as $item) {
            $baris = new TransactionItem([
                'transaction_id' => $transaction->getKey(),
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'nama_produk' => $item['nama_produk'],
                'qty' => $item['qty'],
                'harga_satuan' => $item['harga_satuan'],
                'diskon' => $item['diskon'] ?? 0,
                'subtotal' => $item['subtotal'],
                'catatan_modifier' => $item['catatan_modifier'] ?? null,
            ]);

            $baris->tenant_id = $user->tenant_id;
            $baris->save();
        }

        $totalTunai = 0.0;

        foreach ($data['payments'] ?? [] as $payment) {
            $metode = PaymentMethod::from($payment['metode']);

            $baris = new TransactionPayment([
                'transaction_id' => $transaction->getKey(),
                'metode' => $metode,
                'jumlah' => $payment['jumlah'],
                'jumlah_diterima' => $payment['jumlah_diterima'] ?? null,
                'kembalian' => $payment['kembalian'] ?? null,
                'referensi' => $payment['referensi'] ?? null,
            ]);

            $baris->tenant_id = $user->tenant_id;
            $baris->save();

            if ($metode->affectsCashDrawer()) {
                $totalTunai += (float) $payment['jumlah'];
            }

            if ($metode->createsReceivable()) {
                $this->catatKasbon($transaction, (float) $payment['jumlah'], $data);
            }
        }

        $this->applyStock->execute($transaction, $user->getKey());

        if ($totalTunai > 0) {
            $this->catatKasMasuk($transaction, $totalTunai, $data);
        }

        return $transaction;
    }

    /**
     * Kasbon tanpa pelanggan tidak bisa ditagih ke siapa pun, jadi transaksinya
     * ditolak agar tidak jadi piutang hantu.
     */
    private function catatKasbon(Transaction $transaction, float $jumlah, array $data): void
    {
        if ($transaction->customer_id === null) {
            throw new \RuntimeException('Pembayaran kasbon wajib menyertakan pelanggan.');
        }

        $ledger = new CreditLedger([
            'outlet_id' => $transaction->outlet_id,
            'customer_id' => $transaction->customer_id,
            'transaction_id' => $transaction->getKey(),
            'jumlah_utang' => $jumlah,
            'tanggal_jatuh_tempo' => $data['tanggal_jatuh_tempo'] ?? null,
            'catatan' => 'Kasbon dari transaksi offline '.$transaction->nomor_transaksi,
        ]);

        $ledger->tenant_id = $transaction->tenant_id;
        $ledger->save();
    }

    /**
     * Uang tunai hanya dicatat ke laci kas kalau sesi kasirnya memang ada dan masih
     * terbuka. Sesi yang sudah ditutup tidak diubah lagi supaya rekonsiliasi shift
     * yang sudah selesai tidak berubah angkanya setelah ditandatangani kasir.
     */
    private function catatKasMasuk(Transaction $transaction, float $jumlah, array $data): void
    {
        $sessionId = $data['cash_session_id'] ?? null;

        if ($sessionId === null) {
            return;
        }

        $session = CashSession::query()
            ->whereKey($sessionId)
            ->where('outlet_id', $transaction->outlet_id)
            ->first();

        if ($session === null || ! $session->isTerbuka()) {
            return;
        }

        $movement = new CashMovement([
            'cash_session_id' => $session->getKey(),
            'tipe' => CashMovementType::Penjualan,
            'jumlah' => $jumlah,
            'catatan' => 'Penjualan offline '.$transaction->nomor_transaksi,
            'oleh_user_id' => $transaction->staff_id,
            'transaction_id' => $transaction->getKey(),
        ]);

        $movement->tenant_id = $transaction->tenant_id;
        $movement->save();
    }

    /**
     * Uang yang masuk harus masuk hitungan.
     *
     * Sebelum ini, satu-satunya penjaga uang adalah klien: `bisaBayar` di layar kasir.
     * Terbukti keliru — bayar terpisah pernah menaikkan sendiri nominal tunai — dan
     * lebih penting lagi, perangkat mana pun yang bisa membuka layar kasir bisa
     * mengirim muatan buatan sendiri. Pembayaran Rp 500.000 untuk tagihan Rp 10.000
     * dulu diterima 200 OK dan tercatat sebagai kas masuk.
     *
     * Diperiksa PER TRANSAKSI, bukan untuk seluruh batch, dan sengaja begitu: menolak
     * seluruh batch berarti satu muatan rusak menyandera semua penjualan sah di
     * belakangnya sampai kasir menghapus antreannya sendiri. Yang salah dilaporkan di
     * detail_gagal; yang benar tetap tersimpan.
     *
     * Yang TIDAK diperiksa di sini: pembayaran yang lebih kecil daripada total. Itu sah
     * untuk kasbon (belum_lunas) dan untuk bill yang masih draft.
     *
     * @param  array<string, mixed>  $data
     */
    private function pastikanUangnyaMasukHitungan(array $data): void
    {
        // Toleransi satu rupiah: pembulatan di perangkat tidak boleh membatalkan
        // penjualan yang sudah benar-benar terjadi.
        $toleransi = 1.0;

        $total = (float) ($data['total'] ?? 0);
        $dibayar = array_sum(array_map(
            fn (array $p) => (float) ($p['jumlah'] ?? 0),
            $data['payments'] ?? [],
        ));

        if ($dibayar > $total + $toleransi) {
            throw new \RuntimeException(
                'Jumlah pembayaran ('.$dibayar.') melebihi total transaksi ('.$total.').',
            );
        }

        $status = (string) ($data['status'] ?? 'lunas');

        if ($status === 'lunas' && $dibayar < $total - $toleransi) {
            throw new \RuntimeException(
                'Transaksi ditandai lunas tapi pembayarannya kurang ('.$dibayar.' dari '.$total.').',
            );
        }

        $rincian = array_sum(array_map(
            fn (array $i) => (float) ($i['subtotal'] ?? 0),
            $data['items'] ?? [],
        ))
            - (float) ($data['diskon'] ?? 0)
            + (float) ($data['pajak'] ?? 0)
            + (float) ($data['service_charge'] ?? 0);

        if (abs($total - $rincian) > $toleransi) {
            throw new \RuntimeException(
                'Total ('.$total.') tidak sesuai rincian item ('.$rincian.').',
            );
        }
    }

    private function catat(User $user, array $payload, SyncResult $result): SyncResult
    {
        $log = new SyncLog([
            'outlet_id' => $payload['outlet_id'],
            'device_id' => $payload['device_id'] ?? $user->device_id_terikat,
            'user_id' => $user->getKey(),
            'jumlah_dikirim' => $result->dikirim,
            'jumlah_dibuat' => $result->dibuat,
            'jumlah_duplikat' => $result->duplikat,
            'jumlah_gagal' => $result->gagal,
            'detail_gagal' => $result->detailGagal ?: null,
        ]);

        $log->tenant_id = $user->tenant_id;
        $log->save();

        return $result;
    }
}
