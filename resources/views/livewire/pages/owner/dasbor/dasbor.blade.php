@php
    $tenant = auth()->user()->tenant;
    $sisaTrial = $tenant?->trial_ends_at ? (int) now()->diffInDays($tenant->trial_ends_at, false) : null;
    $puncak = collect($tujuhHari)->max('total') ?: 1;

    // Pola MiniStatistics Horizon: lencana ikon bundar, label kecil, angka tebal.
    $kartuStat = [
        [
            'label' => 'Omzet hari ini',
            'nilai' => 'Rp '.number_format($omzetHariIni, 0, ',', '.'),
            'ket' => $jumlahTransaksi.' transaksi',
            'ikon' => 'M3 8h18v9H3V8Zm0 3h18M7 14h3',
            'aksen' => 'terracotta',
        ],
        [
            'label' => 'Bill terbuka',
            'nilai' => (string) $billTerbuka,
            'ket' => 'belum dibayar',
            'ikon' => 'M7 4h10v16l-3-2-2 2-2-2-3 2V4Zm3 5h4m-4 4h4',
            'aksen' => 'olive',
        ],
        [
            'label' => 'Sisa kasbon',
            'nilai' => 'Rp '.number_format($sisaKasbon, 0, ',', '.'),
            'ket' => 'piutang pelanggan',
            'ikon' => 'M5 4h9a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4Zm3 4h6m-6 4h6',
            'aksen' => 'amber',
        ],
        [
            'label' => 'Stok menipis',
            'nilai' => (string) $stokMenipis,
            'ket' => 'item perlu dibeli',
            'ikon' => 'M12 3 4 7v10l8 4 8-4V7l-8-4Zm0 0v18M4 7l8 4 8-4',
            'aksen' => $stokMenipis > 0 ? 'terracotta' : 'olive',
        ],
    ];
@endphp

<div>
    {{-- Pengingat masa trial. Hanya tampil kalau memang sedang trial, dan nadanya
         berubah jadi mendesak ketika sisanya tinggal tiga hari. --}}
    @if ($sisaTrial !== null && $tenant->status->value === 'trial')
        <div class="kartu mb-5 flex flex-col gap-3 border px-5 py-4 sm:flex-row sm:items-center sm:justify-between {{ $sisaTrial <= 3 ? 'border-terracotta-soft/40' : 'border-amber-warm/30' }}">
            <div class="flex items-start gap-4">
                <span class="lencana-ikon {{ $sisaTrial <= 3 ? 'bg-terracotta/10 text-terracotta' : 'bg-amber-glow/25 text-amber-warm' }}">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </span>
                <div>
                    <p class="text-[0.9375rem] font-bold text-ink">
                        {{ $sisaTrial > 0 ? "Masa coba tinggal {$sisaTrial} hari" : 'Masa coba sudah berakhir' }}
                    </p>
                    <p class="mt-0.5 text-[0.8125rem] text-umber">
                        Setelah itu transaksi dihentikan sementara, tapi data Anda tetap tersimpan.
                    </p>
                </div>
            </div>
            <a
                href="{{ route('owner.langganan') }}"
                wire:navigate
                data-tap
                class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-terracotta-soft to-terracotta-deep px-5 text-[0.875rem] font-bold text-cream"
            >
                Lihat paket
            </a>
        </div>
    @endif

    {{-- Kartu mini-statistik --}}
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($kartuStat as $kartu)
            @php
                [, $warnaAksen] = match ($kartu['aksen']) {
                    'terracotta' => ['bg-terracotta/10', 'text-terracotta'],
                    'olive' => ['bg-olive/12', 'text-olive'],
                    default => ['bg-amber-glow/25', 'text-amber-warm'],
                };
            @endphp
            {{-- Kartu MiniStatistics template: baris mendatar setinggi 90px, lencana
                 bundar berlatar netral di kiri, lalu label kecil di atas angka besar.
                 Latar lencana sengaja netral seperti aslinya (satu warna untuk semua
                 kartu); yang membedakan kartu hanya warna ikonnya. --}}
            <div data-tilt-in class="kartu flex min-h-[5.625rem] items-center gap-4 pr-5 pl-[1.125rem]">
                <span class="lencana-ikon bg-cream-deep {{ $warnaAksen }}">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                        <path d="{{ $kartu['ikon'] }}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-[0.875rem] font-medium text-umber">{{ $kartu['label'] }}</p>
                    <p class="tabular truncate text-[1.25rem] leading-tight font-bold text-ink">{{ $kartu['nilai'] }}</p>
                    <p class="mt-0.5 truncate text-[0.6875rem] {{ $warnaAksen }}">{{ $kartu['ket'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-[1.5fr_1fr]">
        {{-- Omzet tujuh hari. Batang CSS, bukan pustaka grafik — datanya cuma tujuh
             titik, memuat pustaka chart untuk ini tidak sepadan. --}}
        <div class="kartu p-6">
            <div class="flex items-baseline justify-between gap-3">
                <div>
                    <p class="text-[0.75rem] font-medium text-umber-soft">Omzet 7 hari terakhir</p>
                    <p class="tabular mt-1 text-[1.5rem] leading-none font-bold text-ink">
                        Rp {{ number_format(collect($tujuhHari)->sum('total'), 0, ',', '.') }}
                    </p>
                </div>
                <span class="rounded-full bg-olive/12 px-2.5 py-1 font-mono text-[0.5625rem] tracking-wider text-olive uppercase">
                    7 hari
                </span>
            </div>

            <div class="mt-8 flex items-end justify-between gap-2" style="height: 10rem">
                @foreach ($tujuhHari as $hari)
                    @php
                        $tinggi = max(3, round($hari['total'] / $puncak * 100));
                        $iniHariIni = $hari['tanggal']->isToday();
                    @endphp
                    {{-- Kolom diberi h-full dan batangnya dibungkus track flex-1.
                         Tanpa itu, height persen pada batang resolve ke nol karena
                         induknya tidak punya tinggi definit — batangnya hilang. --}}
                    <div class="flex h-full min-w-0 flex-1 flex-col items-center gap-2">
                        <span class="tabular font-mono text-[0.5625rem] text-umber-soft">
                            {{ $hari['total'] > 0 ? round($hari['total'] / 1000).'rb' : '–' }}
                        </span>
                        <div class="flex w-full flex-1 items-end">
                            <div
                                class="w-full rounded-t-xl {{ $iniHariIni ? 'bg-gradient-to-t from-terracotta-deep to-terracotta-soft' : 'bg-terracotta/20' }}"
                                style="height: {{ $tinggi }}%"
                                role="img"
                                aria-label="{{ $hari['tanggal']->translatedFormat('j F') }}: Rp {{ number_format($hari['total'], 0, ',', '.') }}"
                            ></div>
                        </div>
                        <span class="font-mono text-[0.5625rem] tracking-wider text-umber-soft uppercase">
                            {{ $hari['tanggal']->translatedFormat('D') }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Produk terlaris --}}
        <div class="kartu p-6">
            <p class="text-[1.0625rem] font-bold text-ink">Terlaris 7 hari</p>

            @if (count($terlaris) === 0)
                <div class="mt-6 rounded-[20px] border border-dashed border-line px-4 py-10 text-center">
                    <p class="text-[0.875rem] text-umber">Belum ada penjualan tercatat.</p>
                </div>
            @else
                <ol class="mt-5 space-y-3.5">
                    @foreach ($terlaris as $i => $item)
                        <li class="flex items-center gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-cream font-mono text-[0.625rem] font-bold text-umber">
                                {{ $i + 1 }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-[0.875rem] font-medium text-ink">{{ $item['nama'] }}</span>
                            <span class="tabular shrink-0 font-mono text-[0.75rem] font-bold text-terracotta">
                                {{ rtrim(rtrim(number_format($item['qty'], 1, ',', '.'), '0'), ',') }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="mt-6 flex items-center justify-between gap-3 border-t border-line-soft pt-4">
                <span class="text-[0.8125rem] text-umber">Sesi kas terbuka</span>
                <span class="tabular font-mono text-[0.8125rem] font-bold {{ $sesiTerbuka > 0 ? 'text-olive' : 'text-umber-soft' }}">
                    {{ $sesiTerbuka }}
                </span>
            </div>
        </div>
    </div>

    {{-- Jujur soal apa yang belum ada, daripada memasang tombol yang tidak berfungsi. --}}
    <div class="mt-5 rounded-[20px] border border-dashed border-line bg-cream-deep/40 p-6">
        <p class="font-mono text-[0.5625rem] tracking-[0.14em] text-umber-soft uppercase">Sedang dibangun</p>
        <p class="mt-2 max-w-2xl text-[0.875rem] text-umber">
            Layar kasir, kelola produk &amp; stok, pembelian, kasbon, laporan, dan kelola karyawan
            belum tersedia di versi ini. Menu bertanda <span class="font-mono text-[0.6875rem] uppercase">segera</span>
            di sidebar adalah urutan yang akan dikerjakan.
        </p>
    </div>
</div>
