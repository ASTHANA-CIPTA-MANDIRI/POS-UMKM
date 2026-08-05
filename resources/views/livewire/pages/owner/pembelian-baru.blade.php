{{--
    RANGKA SEMENTARA — dibuat agen data supaya komponennya bisa dirender & diuji.
    Tata letak, kerapian, dan kepatuhan pada patokan responsif (layar Stok & hitung stok)
    BELUM dikerjakan; itu bagian agen frontend. Ganti seluruh isi berkas ini.

    Yang WAJIB ada di versi jadinya, karena logikanya sudah menuntutnya di komponen:
      - blok peringatan $namaOutletDiminta (buang-lalu-pindah / tetap di outlet ini),
        pola persis lembar hitung stok;
      - nilai $jumlah/$harga bertahan antar-halaman (di-key id barang, jangan indeks);
      - bar ringkasan uang memakai $ringkasan dari server, JANGAN dihitung ulang di Alpine;
      - medan "Beli dari" berupa teks bebas + <datalist> berisi $saranPemasok.

    Data dari komponen: $daftar (paginator baris barang), $ringkasan, $jumlahTerisi,
    $saranPemasok, $outletTersedia, $outletDipakai, $namaOutletDiminta.
--}}
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-ink">Catat nota belanja</h1>
        <a href="{{ route('owner.pembelian') }}" wire:navigate class="tombol-kedua">Daftar nota</a>
    </div>

    @if ($namaOutletDiminta !== null)
        <div class="rounded-xl border border-line p-4">
            <p>Isian di nota ini diketik untuk outlet lain. Pindah ke {{ $namaOutletDiminta }} akan membuang isiannya.</p>
            <button type="button" wire:click="pindahOutlet('{{ $outletDiminta }}')">Buang lalu pindah</button>
            <button type="button" wire:click="tetapDiOutlet">Tetap di sini</button>
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2">
        @if ($outletTersedia !== [])
            <select wire:model.live="outletId" class="h-12 w-full rounded-xl border border-line px-3 sm:col-span-2" aria-label="Outlet tujuan barang">
                @foreach ($outletTersedia as $outlet)
                    <option value="{{ $outlet['id'] }}">{{ $outlet['nama'] }}</option>
                @endforeach
            </select>
        @endif

        <label class="block">
            <span class="text-sm">Beli dari</span>
            <input type="text" wire:model="beliDari" list="saran-pemasok" class="h-12 w-full rounded-xl border border-line px-3" />
            <datalist id="saran-pemasok">
                @foreach ($saranPemasok as $nama)
                    <option value="{{ $nama }}"></option>
                @endforeach
            </datalist>
        </label>

        <label class="block">
            <span class="text-sm">Tanggal nota</span>
            <input type="date" wire:model="tanggal" class="h-12 w-full rounded-xl border border-line px-3" />
            @error('tanggal') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </label>
    </div>

    <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Cari barang" class="h-12 w-full rounded-xl border border-line px-3" />

    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada barang yang cocok" keterangan="Ubah kata pencarian, atau tambahkan barangnya dulu di layar Produk." ikon="cari" />
    @else
        <table class="w-full text-left text-sm">
            <thead>
                <tr>
                    <th>Barang</th>
                    <th>Sisa sekarang</th>
                    <th>Jumlah beli</th>
                    <th>Harga per satuan beli</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($daftar as $b)
                    <tr wire:key="beli-{{ $b['kunci'] }}">
                        <td>
                            {{ $b['nama'] }}
                            @if ($b['isi_per_satuan'])
                                <span class="block text-xs text-umber">1 {{ $b['satuan'] }} = {{ rtrim(rtrim(number_format($b['isi_per_satuan'], 3, ',', '.'), '0'), ',') }} {{ $b['satuan_dasar'] }}</span>
                            @endif
                        </td>
                        <td>{{ $b['punya_baris'] ? rtrim(rtrim(number_format($b['sistem'], 3, ',', '.'), '0'), ',') : '—' }}</td>
                        <td>
                            <input type="text" inputmode="decimal" wire:model.blur="jumlah.{{ $b['kunci'] }}"
                                   aria-label="Jumlah beli {{ $b['nama'] }}" class="h-12 w-full rounded-xl border border-line px-3" />
                            @error('jumlah.'.$b['kunci']) <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </td>
                        <td>
                            <input type="text" inputmode="decimal" wire:model.blur="harga.{{ $b['kunci'] }}"
                                   aria-label="Harga beli {{ $b['nama'] }}" class="h-12 w-full rounded-xl border border-line px-3" />
                            @error('harga.'.$b['kunci']) <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $daftar->links() }}
    @endif

    <div class="grid gap-3 sm:grid-cols-2">
        <label class="block">
            <span class="text-sm">Diskon</span>
            <input type="text" inputmode="decimal" wire:model.blur="diskon" class="h-12 w-full rounded-xl border border-line px-3" />
            @error('diskon') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="text-sm">Ongkos kirim</span>
            <input type="text" inputmode="decimal" wire:model.blur="ongkosKirim" class="h-12 w-full rounded-xl border border-line px-3" />
            @error('ongkosKirim') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </label>

        <label class="block sm:col-span-2">
            <span class="text-sm">Catatan</span>
            <input type="text" wire:model="catatan" class="h-12 w-full rounded-xl border border-line px-3" />
        </label>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line p-4">
        <div class="text-sm">
            <p>{{ $ringkasan['baris'] }} barang · Belanja Rp {{ number_format($ringkasan['subtotal'], 0, ',', '.') }}</p>
            <p>Diskon Rp {{ number_format($ringkasan['diskon'], 0, ',', '.') }} · Ongkir Rp {{ number_format($ringkasan['ongkir'], 0, ',', '.') }}</p>
            <p class="font-bold">Total bayar Rp {{ number_format($ringkasan['total'], 0, ',', '.') }}</p>
        </div>

        <button type="button" wire:click="simpan" class="tombol-utama">Simpan nota</button>
    </div>
</div>
