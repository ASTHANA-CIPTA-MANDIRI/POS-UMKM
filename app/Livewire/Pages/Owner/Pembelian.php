<?php

namespace App\Livewire\Pages\Owner;

use App\Actions\Purchase\BatalkanPembelianAction;
use App\Enums\DocumentStatus;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar nota belanja (pembelian) — riwayat barang masuk beserta uang yang keluar.
 *
 * Yang TIDAK ada di sini, dan sengaja: alur draf. Nota disimpan berarti barangnya sudah
 * datang, jadi status yang mungkin hanya dua — Diterima dan Dibatalkan. Nota lama dari
 * data demo yang berstatus lain tetap tampil apa adanya; menyembunyikannya berarti
 * menghilangkan mutasi stok yang sudah terjadi dari layar yang seharusnya menjelaskannya.
 *
 * Nota yang dibatalkan TIDAK hilang dari daftar. Kalau ia hilang, kartu stok memuat mutasi
 * masuk dan keluar yang menunjuk dokumen yang tidak bisa dibuka siapa pun.
 */
#[Layout('layouts.aplikasi')]
class Pembelian extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    private const NAMA_HALAMAN = 'page';

    /**
     * Rincian nota punya nomor halamannya SENDIRI.
     *
     * Kalau ikut 'page', membuka rincian nota di halaman 3 daftar akan melompatkan
     * daftarnya, dan menggeser halaman rincian ikut menggeser daftarnya — dua daftar
     * berbeda memakai satu penunjuk.
     */
    private const HALAMAN_RINCIAN = 'baris';

    /** Kosong = semua outlet. Nota dicatat per outlet karena stoknya per outlet. */
    #[Url(as: 'outlet')]
    public string $outletId = '';

    #[Url(as: 'cari')]
    public string $cari = '';

    /** semua|diterima|dibatalkan */
    #[Url(as: 'status')]
    public string $status = 'semua';

    /** Nota yang rinciannya sedang dibuka; null berarti tidak ada. */
    public ?string $rincianId = null;

    public function mount(): void
    {
        // Manager outlet tidak punya pilihan: nilainya dikunci ke outletnya sendiri
        // supaya layar tidak pernah menampilkan pilihan yang akan diabaikan.
        $terkunci = auth()->user()->scopedOutletId();

        if ($terkunci !== null) {
            $this->outletId = $terkunci;
        }
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'status', 'outletId'], true)) {
            $this->resetPage();
            $this->rincianId = null;
        }
    }

    /**
     * Gerbang akses outlet — dijalankan tiap render, bukan hanya saat mount.
     *
     * Pola yang sama dengan layar Stok & lembar hitung stok: pilihan outlet bisa berubah
     * kapan saja lewat properti, dan pemeriksaan yang hanya berjalan di mount berarti
     * outlet merchant lain cukup di-set belakangan untuk lolos.
     */
    public function outletTerpakai(): ?string
    {
        $user = auth()->user();
        $terkunci = $user->scopedOutletId();

        if ($terkunci !== null) {
            return $terkunci;
        }

        if (filled($this->outletId)) {
            abort_unless($user->canAccessOutlet($this->outletId), 403);

            return $this->outletId;
        }

        return null;
    }

    /* ── Rincian ─────────────────────────────────────────────────────────── */

    public function bukaRincian(string $id): void
    {
        $this->rincianId = $this->rincianId === $id ? null : $id;

        // Rinciannya selalu mulai dari halaman 1. Tanpa ini, halaman 3 dari nota
        // sebelumnya terbawa ke nota yang barisnya cuma dua, dan panelnya terbuka KOSONG
        // — terbaca sebagai "nota ini tidak ada isinya", padahal ada.
        $this->resetPage(self::HALAMAN_RINCIAN);
    }

    public function tutupRincian(): void
    {
        $this->rincianId = null;
    }

    /**
     * Baris nota yang sedang dibuka, berhalaman.
     *
     * Dihalamankan walau notanya biasanya pendek: nota belanja bulanan kelontong bisa
     * berisi 40 barang, dan `limit(n)` tanpa penunjuk halaman adalah pemotongan diam-diam
     * — pemiliknya membandingkan total nota dengan barisnya lalu menyimpulkan totalnya
     * salah.
     *
     * @return LengthAwarePaginator<int, PurchaseOrderItem>|Collection<int, PurchaseOrderItem>
     */
    private function barisRincian(): LengthAwarePaginator|Collection
    {
        if ($this->rincianId === null) {
            return collect();
        }

        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $this->rincianId)
            ->with(['product:id,nama_produk,sku', 'rawMaterial:id,nama'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(config('nampan.per_halaman'), ['*'], self::HALAMAN_RINCIAN);
    }

    /* ── Pembatalan ──────────────────────────────────────────────────────── */

    /**
     * Membatalkan nota: stok dikembalikan, harga beli dipulihkan, notanya tetap ada.
     *
     * Aksinya idempoten, jadi tombol yang tertekan dua kali tidak mengurangi stok dua
     * kali. Pesannya dibedakan supaya pemilik tahu mana yang benar-benar baru terjadi —
     * "sudah dibatalkan sebelumnya" adalah keterangan, bukan kegagalan.
     */
    public function batalkan(string $id): void
    {
        $nota = $this->kueri()->whereKey($id)->first();

        if ($nota === null) {
            // Termasuk nota milik merchant lain: global scope sudah menyaringnya, jadi
            // yang tersisa terbaca sebagai "tidak ada".
            $this->toast('Nota belanja tidak ditemukan.', 'peringatan');

            return;
        }

        $terjadi = app(BatalkanPembelianAction::class)->execute($nota, auth()->user());

        $this->toast(
            $terjadi
                ? 'Nota '.$nota->nomor_po.' dibatalkan. Stok dikembalikan seperti sebelum nota ini dicatat.'
                : 'Nota '.$nota->nomor_po.' memang sudah dibatalkan sebelumnya; stok tidak disentuh lagi.',
            $terjadi ? 'sukses' : 'info',
        );
    }

    /* ── Daftar ──────────────────────────────────────────────────────────── */

    /** @return Builder<PurchaseOrder> */
    private function kueri()
    {
        $cari = trim($this->cari);
        $outletId = $this->outletTerpakai();

        return PurchaseOrder::query()
            ->with(['supplier:id,nama', 'outlet:id,outlet_name'])
            ->withCount('items')
            ->when($outletId !== null, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($this->status === 'diterima', fn ($q) => $q->where('status', DocumentStatus::Diterima->value))
            ->when($this->status === 'dibatalkan', fn ($q) => $q->where('status', DocumentStatus::Dibatalkan->value))
            ->when($cari !== '', fn ($q) => $q->where(function ($w) use ($cari) {
                $w->where('nomor_po', 'like', '%'.$cari.'%')
                    ->orWhereHas('supplier', fn ($s) => $s->where('nama', 'like', '%'.$cari.'%'));
            }))
            ->orderByDesc('tanggal')
            ->orderByDesc('created_at');
    }

    /** @return array<int, array{id: string, nama: string}> */
    private function outletTersedia(): array
    {
        return Outlet::query()
            ->orderBy('outlet_name')
            ->get(['id', 'outlet_name'])
            ->map(fn (Outlet $outlet) => ['id' => $outlet->getKey(), 'nama' => $outlet->outlet_name])
            ->all();
    }

    public function render()
    {
        $daftar = $this->kueri()->paginate(config('nampan.per_halaman'), ['*'], self::NAMA_HALAMAN);

        return view('livewire.pages.owner.pembelian', [
            'daftar' => $daftar,
            'notaRincian' => $this->rincianId === null
                ? null
                : $daftar->firstWhere('id', $this->rincianId) ?? PurchaseOrder::query()->find($this->rincianId),
            'barisRincian' => $this->barisRincian(),
            'outletTersedia' => auth()->user()->scopedOutletId() === null ? $this->outletTersedia() : [],
            'outletDipakai' => $this->outletTerpakai(),
        ]);
    }
}
