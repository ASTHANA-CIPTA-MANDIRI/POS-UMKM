<div>
    <x-kartu-alat
        judul="Ringkasan penjualan"
        keterangan="Hanya transaksi lunas dan kasbon yang dihitung. Pesanan bill yang belum dibayar serta transaksi void tidak masuk."
    >
        <x-slot:aksi>
            {{-- Rentang yang sedang dilihat ditulis sebagai lencana, bukan baris teks
                 terpisah di bawah kartu: owner membacanya bersamaan dengan angkanya. --}}
            <span class="tabular rounded-full bg-cream-deep px-3 py-1.5 text-[0.75rem] font-semibold text-umber">
                {{ $mulai->translatedFormat('j M') }} &ndash; {{ $selesai->translatedFormat('j M Y') }}
            </span>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="flex flex-wrap items-center gap-3">
                @if ($outlet->count() > 1)
                    <div class="min-w-0">
                        <label for="outlet" class="sr-only">Outlet</label>
                        <select id="outlet" wire:model.live="outletId"
                                class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none sm:w-56">
                            <option value="">Semua outlet</option>
                            @foreach ($outlet as $o)
                                <option value="{{ $o->id }}">{{ $o->outlet_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Empat pilihan rentang tidak muat sebaris di ponsel; barisnya digulir
                     mendatar alih-alih mendorong lebar halaman. --}}
                <div class="-m-px max-w-full flex-1 overflow-x-auto p-px [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <div class="inline-flex w-fit items-center gap-1 rounded-xl bg-white p-1 ring-1 ring-line">
                        @foreach ($pilihanRentang as $nilai => $teks)
                            <button type="button" wire:click="pilihRentang('{{ $nilai }}')"
                                    aria-pressed="{{ $rentang === $nilai ? 'true' : 'false' }}"
                                    @class([
                                        'h-9 shrink-0 cursor-pointer rounded-lg px-3.5 text-[0.8125rem] whitespace-nowrap transition',
                                        'bg-terracotta font-semibold text-white' => $rentang === $nilai,
                                        'font-medium text-umber hover:bg-cream hover:text-ink' => $rentang !== $nilai,
                                    ])>
                                {{ $teks }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Ringkasan ─────────────────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 sm:gap-5">
        @php
            $kartuRingkas = [
                ['Omzet', 'Rp '.number_format($ringkas['omzet'], 0, ',', '.'), 'sudah dikurangi void', 'text-hijau-tua', 'M4 20V6m0 14h16M8 16V11m4 5V8m4 8v-4'],
                ['Transaksi', (string) $ringkas['jumlah'], 'jumlah struk', 'text-terracotta', 'M4 7h16v12H4V7Zm0 4h16M8 15h3'],
                ['Rata-rata', 'Rp '.number_format($ringkas['rata'], 0, ',', '.'), 'per transaksi', 'text-ink', 'M3 12h4l3-7 4 14 3-7h4'],
                ['Kasbon', 'Rp '.number_format($ringkas['belum_lunas'], 0, ',', '.'), 'belum dibayar', 'text-jingga-tua', 'M5 4h9a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4Zm3 4h6m-6 4h6'],
            ];
        @endphp

        @foreach ($kartuRingkas as [$label, $nilai, $ket, $warna, $ikon])
            <div class="kartu flex min-h-[5.625rem] min-w-0 items-center gap-4 pr-5 pl-[1.125rem]">
                <span class="lencana-ikon bg-cream-deep {{ $warna }}">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                        <path d="{{ $ikon }}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-[0.875rem] font-medium text-umber">{{ $label }}</p>
                    <p class="tabular truncate text-[1.25rem] leading-tight font-bold text-ink">{{ $nilai }}</p>
                    <p class="truncate text-[0.75rem] text-umber-soft">{{ $ket }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 sm:mt-5 sm:gap-5 lg:grid-cols-[1.5fr_1fr]">
        {{-- ── Grafik harian ─────────────────────────────────────────────── --}}
        <div class="kartu min-w-0 p-5 sm:p-6">
            <h2 class="text-[1.0625rem] font-bold text-ink"><x-jelaskan kunci="omzet" sebagai="Omzet per hari" /></h2>
            <p class="text-[0.8125rem] text-umber-soft">Hari tanpa penjualan tetap ditampilkan supaya hari sepi terlihat.</p>

            @php $tertinggi = collect($perHari)->max('total') ?: 1; @endphp

            {{-- Batang dibungkus track ber-flex-1: tinggi persen di dalam kolom
                 setinggi-isi akan menghitung ke 0 dan batangnya tidak terlihat. --}}
            <div class="mt-6 flex h-44 items-end gap-1.5 sm:gap-2">
                @foreach ($perHari as $hari)
                    @php
                        $tinggi = $tertinggi > 0 ? max(2, (int) round($hari['total'] / $tertinggi * 100)) : 2;
                        $iniHariIni = $hari['tanggal']->isToday();
                    @endphp
                    <div class="flex h-full min-w-0 flex-1 flex-col items-center gap-1.5">
                        <span class="tabular w-full truncate text-center text-[0.5625rem] text-umber-soft">
                            {{ $hari['total'] > 0 ? round($hari['total'] / 1000).'rb' : '–' }}
                        </span>
                        <div class="flex w-full flex-1 items-end">
                            <div class="w-full rounded-t-lg {{ $iniHariIni ? 'bg-gradient-to-t from-terracotta-deep to-terracotta-soft' : 'bg-terracotta/20' }}"
                                 style="height: {{ $tinggi }}%"
                                 title="{{ $hari['tanggal']->translatedFormat('j M') }}: Rp {{ number_format($hari['total'], 0, ',', '.') }}"></div>
                        </div>
                        <span class="text-[0.5625rem] text-umber-soft uppercase">
                            {{ $hari['tanggal']->locale('id')->translatedFormat(count($perHari) > 10 ? 'j' : 'D') }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Metode bayar ──────────────────────────────────────────────── --}}
        <div class="kartu min-w-0 p-5 sm:p-6">
            <h2 class="text-[1.0625rem] font-bold text-ink">Cara pembayaran</h2>
            <p class="text-[0.8125rem] text-umber-soft">Berapa yang masuk lewat masing-masing cara.</p>

            @if ($perMetode->isEmpty())
                <p class="mt-6 text-[0.875rem] text-umber">Belum ada pembayaran pada rentang ini.</p>
            @else
                @php $totalMetode = (float) $perMetode->sum('jumlah') ?: 1; @endphp
                <ul class="mt-5 space-y-4">
                    @foreach ($perMetode as $baris)
                        @php $persen = (int) round($baris->jumlah / $totalMetode * 100); @endphp
                        <li>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-[0.875rem] font-semibold text-ink">
                                    {{ $baris->metode instanceof \App\Enums\PaymentMethod ? $baris->metode->label() : $baris->metode }}
                                </span>
                                <span class="tabular text-[0.875rem] font-bold text-ink">
                                    Rp {{ number_format((float) $baris->jumlah, 0, ',', '.') }}
                                </span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-cream-deep">
                                <div class="h-full rounded-full bg-terracotta" style="width: {{ max(2, $persen) }}%"></div>
                            </div>
                            <p class="mt-1 text-[0.75rem] text-umber-soft">{{ $persen }}% &middot; {{ $baris->banyak }}&times; dipakai</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- ── Produk terlaris ───────────────────────────────────────────────── --}}
    <div class="kartu mt-4 min-w-0 p-5 sm:mt-5 sm:p-6">
        <h2 class="text-[1.0625rem] font-bold text-ink">Produk terlaris</h2>
        <p class="text-[0.8125rem] text-umber-soft">Diurutkan berdasarkan omzet, bukan jumlah — barang murah yang laku banyak tidak menutupi yang mahal.</p>

        @if ($terlaris->isEmpty())
            <x-kosong judul="Belum ada penjualan" keterangan="Ubah rentang tanggalnya, atau tunggu transaksi pertama masuk." />
        @else
            <ul class="mt-4 divide-y divide-line-soft">
                @foreach ($terlaris as $i => $item)
                    <li class="flex items-center gap-3 py-3">
                        <span class="tabular grid size-7 shrink-0 place-items-center rounded-lg bg-cream-deep text-[0.75rem] font-bold text-umber">
                            {{ $i + 1 }}
                        </span>
                        <span class="min-w-0 flex-1 truncate text-[0.9375rem] font-medium text-ink">{{ $item->nama_produk }}</span>
                        <span class="tabular shrink-0 text-[0.8125rem] text-umber">
                            {{ rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',') }}&times;
                        </span>
                        <span class="tabular w-28 shrink-0 text-right text-[0.9375rem] font-bold text-ink">
                            Rp {{ number_format((float) $item->omzet, 0, ',', '.') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
