{{--
    RANGKA SEMENTARA — dibuat agen data supaya komponennya bisa dirender & diuji.
    Tata letak, kerapian, dan kepatuhan pada patokan responsif (layar Stok) BELUM
    dikerjakan; itu bagian agen frontend. Ganti seluruh isi berkas ini, jangan menambal.

    Data yang tersedia dari komponen:
      $daftar        paginator PurchaseOrder (with supplier, outlet, items_count)
      $notaRincian   PurchaseOrder yang rinciannya dibuka, atau null
      $barisRincian  paginator PurchaseOrderItem (pageName 'baris')
      $outletTersedia, $outletDipakai
    Aksi: bukaRincian($id), tutupRincian(), batalkan($id)
--}}
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-ink">Pembelian</h1>
        <a href="{{ route('owner.pembelian.baru') }}" wire:navigate class="tombol-utama">Catat nota belanja</a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Cari nomor nota atau nama pemasok" class="h-12 w-full rounded-xl border border-line px-3" />

        <select wire:model.live="status" class="h-12 w-full rounded-xl border border-line px-3" aria-label="Status nota">
            <option value="semua">Semua status</option>
            <option value="diterima">Barang sudah masuk</option>
            <option value="dibatalkan">Dibatalkan</option>
        </select>

        @if ($outletTersedia !== [])
            <select wire:model.live="outletId" class="form-kolom sm:col-span-2" aria-label="Outlet">
                <option value="">Semua outlet</option>
                @foreach ($outletTersedia as $outlet)
                    <option value="{{ $outlet['id'] }}">{{ $outlet['nama'] }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($daftar->isEmpty())
        <x-kosong judul="Belum ada nota belanja" keterangan="Nota yang dicatat di sini langsung menambah stok outletnya." ikon="lembar" />
    @else
        <table class="w-full text-left text-sm">
            <thead>
                <tr>
                    <th>Nomor nota</th>
                    <th>Tanggal</th>
                    <th>Beli dari</th>
                    <th>Outlet</th>
                    <th>Barang</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($daftar as $nota)
                    <tr wire:key="nota-{{ $nota->id }}">
                        <td>{{ $nota->nomor_po }}</td>
                        <td>{{ $nota->tanggal?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $nota->supplier?->nama ?? '—' }}</td>
                        <td>{{ $nota->outlet?->outlet_name ?? '—' }}</td>
                        <td>{{ $nota->items_count }}</td>
                        <td>Rp {{ number_format((float) $nota->total, 0, ',', '.') }}</td>
                        <td>{{ $nota->status->label() }}</td>
                        <td>
                            <button type="button" wire:click="bukaRincian('{{ $nota->id }}')">Rincian</button>
                            @if ($nota->status !== \App\Enums\DocumentStatus::Dibatalkan)
                                <button type="button" wire:click="batalkan('{{ $nota->id }}')">Batalkan</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $daftar->links() }}
    @endif

    @if ($notaRincian !== null)
        <div class="rounded-xl border border-line p-4">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Isi nota {{ $notaRincian->nomor_po }}</h2>
                <button type="button" wire:click="tutupRincian">Tutup</button>
            </div>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th>Jumlah beli</th>
                        <th>Masuk stok</th>
                        <th>Harga satuan</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($barisRincian as $baris)
                        <tr wire:key="baris-{{ $baris->id }}">
                            <td>{{ $baris->namaItem() }}</td>
                            <td>{{ rtrim(rtrim(number_format((float) ($baris->qty_beli ?? $baris->qty), 3, ',', '.'), '0'), ',') }} {{ $baris->satuan_beli ?? '' }}</td>
                            <td>{{ rtrim(rtrim(number_format((float) $baris->qty, 3, ',', '.'), '0'), ',') }}</td>
                            <td>Rp {{ number_format((float) $baris->harga_satuan, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $baris->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($barisRincian instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                {{ $barisRincian->links() }}
            @endif

            <dl class="mt-3 text-sm">
                <div><dt class="inline">Diskon</dt> <dd class="inline">Rp {{ number_format((float) $notaRincian->diskon, 0, ',', '.') }}</dd></div>
                <div><dt class="inline">Ongkos kirim</dt> <dd class="inline">Rp {{ number_format((float) $notaRincian->ongkos_kirim, 0, ',', '.') }}</dd></div>
                <div><dt class="inline">Total</dt> <dd class="inline">Rp {{ number_format((float) $notaRincian->total, 0, ',', '.') }}</dd></div>
            </dl>
        </div>
    @endif
</div>
