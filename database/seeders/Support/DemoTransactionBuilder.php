<?php

namespace Database\Seeders\Support;

use App\Actions\Stock\ApplySaleToStockAction;
use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionMode;
use App\Enums\TransactionStatus;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CreditLedger;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Perakit transaksi demo untuk seeder lokal.
 *
 * Sengaja memakai action stok yang sama dengan jalur produksi
 * (ApplySaleToStockAction), supaya stok & kartu stok hasil seeding konsisten
 * dengan yang akan terjadi saat aplikasi dipakai sungguhan — termasuk pengurangan
 * bahan baku lewat resep untuk menu FnB.
 */
class DemoTransactionBuilder
{
    /** @var array<string, int> */
    private array $counter = [];

    /** @var array<string, string> */
    private array $kodeOutlet = [];

    public function __construct(private ApplySaleToStockAction $applyStock) {}

    /**
     * @param  array<int, array{0: Product, 1: float, 2?: string|null}>  $baris
     */
    public function buat(
        Outlet $outlet,
        User $kasir,
        array $baris,
        CarbonInterface $waktu,
        PaymentMethod $metode = PaymentMethod::Cash,
        TransactionMode $mode = TransactionMode::Langsung,
        ?Customer $customer = null,
        ?CashSession $session = null,
        ?Bill $bill = null,
        ?Device $device = null,
        float $diskon = 0,
        bool $sudahDibayar = true,
    ): Transaction {
        $subtotal = 0.0;

        foreach ($baris as [$product, $qty]) {
            $subtotal += (float) $product->harga_default * $qty;
        }

        $total = max(0, $subtotal - $diskon);
        $kasbon = $metode->createsReceivable();

        // Bill Mode B/C yang masih berjalan menampung pesanan sebagai transaksi
        // berstatus draft — belum dihitung sebagai omzet sampai bill ditutup.
        $status = match (true) {
            ! $sudahDibayar => TransactionStatus::Draft,
            $kasbon => TransactionStatus::BelumLunas,
            default => TransactionStatus::Lunas,
        };

        $transaction = Transaction::create([
            'outlet_id' => $outlet->getKey(),
            'bill_id' => $bill?->getKey(),
            'customer_id' => $customer?->getKey(),
            'staff_id' => $kasir->getKey(),
            'device_id' => $device?->getKey(),
            'nomor_transaksi' => $this->nomorBerikutnya($outlet, $waktu),
            'mode' => $mode,
            'subtotal' => $subtotal,
            'diskon' => $diskon,
            'total' => $total,
            'status' => $status,
            'waktu_transaksi' => $waktu,
        ]);

        foreach ($baris as $item) {
            [$product, $qty] = $item;
            $modifier = $item[2] ?? null;

            TransactionItem::create([
                'transaction_id' => $transaction->getKey(),
                'product_id' => $product->getKey(),
                'nama_produk' => $product->nama_produk,
                'qty' => $qty,
                'harga_satuan' => $product->harga_default,
                'subtotal' => (float) $product->harga_default * $qty,
                'catatan_modifier' => $modifier,
            ]);
        }

        if ($sudahDibayar) {
            TransactionPayment::create([
                'transaction_id' => $transaction->getKey(),
                'metode' => $metode,
                'jumlah' => $total,
                'jumlah_diterima' => $metode->affectsCashDrawer() ? $this->bulatkanKeAtas($total) : null,
                'kembalian' => $metode->affectsCashDrawer() ? $this->bulatkanKeAtas($total) - $total : null,
            ]);
        }

        if ($sudahDibayar && $kasbon && $customer !== null) {
            CreditLedger::create([
                'outlet_id' => $outlet->getKey(),
                'customer_id' => $customer->getKey(),
                'transaction_id' => $transaction->getKey(),
                'jumlah_utang' => $total,
                'tanggal_jatuh_tempo' => $waktu->copy()->addDays(14)->toDateString(),
                'catatan' => 'Kasbon pelanggan langganan',
            ]);
        }

        if ($sudahDibayar && $session !== null && $metode->affectsCashDrawer()) {
            CashMovement::create([
                'cash_session_id' => $session->getKey(),
                'tipe' => CashMovementType::Penjualan,
                'jumlah' => $total,
                'oleh_user_id' => $kasir->getKey(),
                'transaction_id' => $transaction->getKey(),
            ]);
        }

        $this->applyStock->execute($transaction, $kasir->getKey());

        return $transaction;
    }

    public function bukaSesiKas(Outlet $outlet, User $kasir, CarbonInterface $waktu, float $modalAwal = 200000): CashSession
    {
        return CashSession::create([
            'outlet_id' => $outlet->getKey(),
            'staff_id' => $kasir->getKey(),
            'dibuka_pada' => $waktu,
            'modal_awal' => $modalAwal,
        ]);
    }

    /**
     * Menutup sesi kasir sekaligus menghitung selisih kas fisik vs sistem.
     * $selisihFisik dipakai untuk mensimulasikan kasir yang kurang/lebih hitung,
     * supaya laporan selisih di UI nanti ada datanya untuk diuji.
     */
    public function tutupSesiKas(CashSession $session, CarbonInterface $waktu, float $selisihFisik = 0): CashSession
    {
        $sistem = $session->fresh(['movements'])->hitungKasSistem();
        $fisik = $sistem + $selisihFisik;

        $session->update([
            'ditutup_pada' => $waktu,
            'kas_akhir_sistem' => $sistem,
            'kas_akhir_fisik' => $fisik,
            'selisih' => $fisik - $sistem,
            'status' => CashSessionStatus::Ditutup,
        ]);

        return $session;
    }

    private function nomorBerikutnya(Outlet $outlet, CarbonInterface $waktu): string
    {
        $prefix = 'TRX-'.$waktu->format('Ymd');
        $key = $outlet->getKey().$prefix;

        $urutan = ($this->counter[$key] ?? 0) + 1;
        $this->counter[$key] = $urutan;

        return sprintf('%s-%s-%03d', $prefix, $this->kodeOutlet($outlet), $urutan);
    }

    /**
     * Kode pendek per outlet. TIDAK boleh diambil dari potongan awal UUID: Laravel
     * memakai UUID v7 yang berurut waktu, sehingga outlet-outlet yang dibuat dalam
     * detik yang sama punya prefix identik dan nomor transaksinya bentrok.
     */
    private function kodeOutlet(Outlet $outlet): string
    {
        return $this->kodeOutlet[$outlet->getKey()] ??= sprintf('O%02d', count($this->kodeOutlet) + 1);
    }

    /** Pembeli warung biasanya menyerahkan uang bulat. */
    private function bulatkanKeAtas(float $total): float
    {
        return ceil($total / 5000) * 5000;
    }
}
