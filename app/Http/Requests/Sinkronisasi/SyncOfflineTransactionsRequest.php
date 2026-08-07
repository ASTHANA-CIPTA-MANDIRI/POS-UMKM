<?php

namespace App\Http\Requests\Sinkronisasi;

use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionMode;
use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Kasir\Bill;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

class SyncOfflineTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->tenant_id !== null;
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['required', 'uuid', $this->milikTenant('outlets')],
            'device_id' => ['nullable', 'uuid', $this->milikTenant('devices')],

            // Dibatasi supaya satu request tidak memproses ribuan transaksi
            // sekaligus; klien memecah antrean offline menjadi beberapa batch.
            'transactions' => ['required', 'array', 'min:1', 'max:200'],

            /*
             * Bill Mode B (buka bill) & Mode C (titip-ambil). Opsional karena Mode A
             * tidak memakai bill sama sekali. Sengaja TIDAK divalidasi dengan
             * exists(): bill ini boleh belum ada di server — justru itu gunanya,
             * kasir membuatnya di perangkat saat jaringan mati.
             */
            'bills' => ['nullable', 'array', 'max:100'],
            'bills.*.id' => ['required', 'uuid'],
            'bills.*.mode' => ['required', Rule::enum(TransactionMode::class)],
            'bills.*.status' => ['required', Rule::enum(BillStatus::class)],
            'bills.*.label' => ['required', 'string', 'max:100'],
            'bills.*.customer_id' => ['nullable', 'uuid', $this->milikTenant('customers')],
            'bills.*.dibuka_pada' => ['nullable', 'date'],
            'bills.*.estimasi_selesai' => ['nullable', 'date'],
            'bills.*.catatan' => ['nullable', 'string', 'max:255'],

            // id dibuat di perangkat dan menjadi kunci idempotensi.
            'transactions.*.id' => ['required', 'uuid'],
            'transactions.*.nomor_transaksi' => ['required', 'string', 'max:64'],
            'transactions.*.mode' => ['required', Rule::enum(TransactionMode::class)],
            'transactions.*.status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'transactions.*.waktu_transaksi' => ['required', 'date'],
            'transactions.*.dibuat_offline_pada' => ['nullable', 'date'],
            'transactions.*.origin' => ['nullable', Rule::enum(TransactionOrigin::class)],
            'transactions.*.subtotal' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.diskon' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.pajak' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.service_charge' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.total' => ['required', 'numeric', 'min:0'],
            'transactions.*.customer_id' => ['nullable', 'uuid', $this->milikTenant('customers')],
            'transactions.*.bill_id' => ['nullable', 'uuid', $this->billTersedia()],
            'transactions.*.cash_session_id' => ['nullable', 'uuid', $this->milikTenant('cash_sessions')],
            'transactions.*.tanggal_jatuh_tempo' => ['nullable', 'date'],

            'transactions.*.items' => ['required', 'array', 'min:1'],
            'transactions.*.items.*.product_id' => ['nullable', 'uuid', $this->milikTenant('products')],
            'transactions.*.items.*.product_variant_id' => ['nullable', 'uuid', $this->milikTenant('product_variants')],
            'transactions.*.items.*.nama_produk' => ['required', 'string', 'max:255'],
            'transactions.*.items.*.qty' => ['required', 'numeric', 'gt:0'],
            'transactions.*.items.*.harga_satuan' => ['required', 'numeric', 'min:0'],
            'transactions.*.items.*.diskon' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.items.*.subtotal' => ['required', 'numeric'],
            'transactions.*.items.*.catatan_modifier' => ['nullable', 'string', 'max:255'],

            'transactions.*.payments' => ['nullable', 'array'],
            'transactions.*.payments.*.metode' => ['required', Rule::enum(PaymentMethod::class)],
            'transactions.*.payments.*.jumlah' => ['required', 'numeric', 'min:0'],
            'transactions.*.payments.*.jumlah_diterima' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.payments.*.kembalian' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.payments.*.referensi' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Pembayaran yang MELEBIHI total ditolak di gerbang, bukan diperiksa belakangan.
     *
     * Nominal `jumlah` adalah uang yang diterapkan ke tagihan; kelebihan uang tunai
     * dinyatakan lewat `jumlah_diterima`, bukan di sini. Jadi jumlah pembayaran yang
     * melampaui total tidak punya bentuk yang sah sama sekali — itu muatan rusak atau
     * dibuat-buat, dan tidak boleh dibiarkan menyentuh kas.
     *
     * Bentuk lain yang tidak konsisten (mis. total tidak cocok dengan rincian item)
     * SENGAJA tidak ditolak di sini, melainkan per transaksi di dalam action: penolakan
     * di gerbang membatalkan seluruh batch, dan satu muatan rusak akan menyandera semua
     * penjualan sah di belakangnya.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('transactions', []) as $i => $transaksi) {
                $total = (float) ($transaksi['total'] ?? 0);
                $dibayar = array_sum(array_map(
                    fn ($p) => (float) ($p['jumlah'] ?? 0),
                    (array) ($transaksi['payments'] ?? []),
                ));

                // Toleransi satu rupiah untuk pembulatan di perangkat.
                if ($dibayar > $total + 1) {
                    $v->errors()->add(
                        "transactions.{$i}.payments",
                        'Jumlah pembayaran melebihi total transaksi.',
                    );
                }
            }
        });
    }

    /**
     * Rule exists bawaan memakai query mentah tanpa global scope tenant, sehingga
     * perangkat bisa merujuk id milik merchant lain. Semua referensi dikunci ke
     * tenant user yang sedang login.
     */
    private function milikTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->user()->tenant_id);
    }

    /**
     * Bill boleh belum ada di database — ia bisa ikut dibuat dalam request yang SAMA.
     *
     * Ini bukan kelonggaran, melainkan syarat agar Mode B & C bisa jalan offline:
     * kasir membuka bill di meja saat sinyal mati, lalu mengirim bill dan pesanannya
     * bersamaan begitu jaringan kembali. Memakai rule exists() biasa membuat seluruh
     * batch ditolak 422 karena validasi berjalan sebelum bill-nya tersimpan.
     *
     * Jadi yang diterima: bill yang sudah ada milik tenant ini, ATAU bill yang ikut
     * dikirim di array `bills` pada request ini. Selain itu ditolak.
     */
    private function billTersedia(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($nilai === null) {
                return;
            }

            $ikutDikirim = collect($this->input('bills', []))
                ->pluck('id')
                ->filter()
                ->all();

            if (in_array($nilai, $ikutDikirim, true)) {
                return;
            }

            $ada = Bill::withoutGlobalScopes()
                ->whereKey($nilai)
                ->where('tenant_id', $this->user()->tenant_id)
                ->exists();

            if (! $ada) {
                $gagal('Bill tidak ditemukan dan tidak ikut dikirim.');
            }
        };
    }
}
