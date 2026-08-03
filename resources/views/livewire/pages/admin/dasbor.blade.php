@php
    $kartuStat = [
        [
            'label' => 'Merchant aktif',
            'nilai' => number_format($perStatus['aktif'], 0, ',', '.'),
            'ket' => 'dari '.$totalMerchant.' total',
            'ikon' => 'M4 9h16v11H4V9Zm0 0 2-5h12l2 5M10 20v-6h4v6',
            'aksen' => 'olive',
        ],
        [
            'label' => 'Sedang trial',
            'nilai' => number_format($perStatus['trial'], 0, ',', '.'),
            'ket' => 'belum berlangganan',
            'ikon' => 'M12 20.5a8.5 8.5 0 1 0 0-17 8.5 8.5 0 0 0 0 17ZM12 7.5V12l3 2',
            'aksen' => 'amber',
        ],
        [
            'label' => 'Pendapatan bulanan',
            'nilai' => 'Rp '.number_format($mrr, 0, ',', '.'),
            'ket' => 'langganan berjalan',
            'ikon' => 'M3 8h18v10H3V8Zm0 3h18M6.5 15H10',
            'aksen' => 'terracotta',
        ],
        [
            'label' => 'Tagihan tertunggak',
            'nilai' => 'Rp '.number_format($tagihanTertunggak['nilai'], 0, ',', '.'),
            'ket' => $tagihanTertunggak['jumlah'].' invoice',
            'ikon' => 'M12 3.5 21 19H3l9-15.5Zm0 6v4m0 2.6v.2',
            'aksen' => $tagihanTertunggak['jumlah'] > 0 ? 'terracotta' : 'olive',
        ],
    ];
@endphp

<div>
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

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        {{-- Trial yang segera habis: daftar follow-up, bukan sekadar angka. --}}
        <div class="kartu p-6">
            <p class="text-[1.0625rem] font-bold text-ink">Trial berakhir &le; 7 hari</p>

            @if ($trialSegeraHabis->isEmpty())
                <div class="mt-6 rounded-[20px] border border-dashed border-line px-4 py-10 text-center">
                    <p class="text-[0.875rem] text-umber">Tidak ada trial yang segera berakhir.</p>
                </div>
            @else
                <ul class="mt-5 divide-y divide-line-soft">
                    @foreach ($trialSegeraHabis as $merchant)
                        @php $sisa = (int) now()->diffInDays($merchant->trial_ends_at, false); @endphp
                        <li class="flex items-center justify-between gap-3 py-3.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-cream font-mono text-[0.625rem] font-bold text-umber">
                                    {{ mb_strtoupper(mb_substr($merchant->business_name, 0, 2)) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-[0.875rem] font-bold text-ink">{{ $merchant->business_name }}</p>
                                    <p class="truncate text-[0.75rem] text-umber">{{ $merchant->owner_name }} &middot; {{ $merchant->owner_phone }}</p>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 font-mono text-[0.5625rem] tracking-wider uppercase {{ $sisa <= 2 ? 'bg-terracotta/12 text-terracotta' : 'bg-amber-glow/25 text-amber-warm' }}">
                                {{ $sisa > 0 ? $sisa.' hari' : 'habis' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Sebaran status --}}
        <div class="kartu p-6">
            <p class="text-[1.0625rem] font-bold text-ink">Sebaran status merchant</p>

            <div class="mt-5 space-y-4">
                @foreach ($perStatus as $status => $jumlah)
                    @php
                        $persen = $totalMerchant > 0 ? round($jumlah / $totalMerchant * 100) : 0;
                        $warna = match ($status) {
                            'aktif' => 'bg-olive',
                            'trial' => 'bg-amber-glow',
                            'suspend' => 'bg-terracotta-soft',
                            default => 'bg-umber-soft',
                        };
                    @endphp
                    <div>
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-[0.8125rem] font-medium text-ink">{{ \App\Enums\TenantStatus::from($status)->label() }}</span>
                            <span class="tabular font-mono text-[0.75rem] text-umber">{{ $jumlah }}</span>
                        </div>
                        {{-- Batang plus angka di sebelahnya: batang saja kehilangan
                             nilai pastinya, angka saja kehilangan perbandingannya. --}}
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-cream-deep">
                            <div class="h-full rounded-full {{ $warna }}" style="width: {{ $persen }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 border-t border-line-soft pt-4">
                <p class="font-mono text-[0.5625rem] tracking-[0.14em] text-umber-soft uppercase">Merchant terbaru</p>
                <ul class="mt-3 space-y-2.5">
                    @foreach ($merchantBaru as $merchant)
                        <li class="flex items-center justify-between gap-3">
                            <span class="min-w-0 flex-1 truncate text-[0.8125rem] text-ink">{{ $merchant->business_name }}</span>
                            <span class="shrink-0 font-mono text-[0.6875rem] text-umber-soft">
                                {{ $merchant->created_at->translatedFormat('j M') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="mt-5 rounded-[20px] border border-dashed border-line bg-cream-deep/40 p-6">
        <p class="font-mono text-[0.5625rem] tracking-[0.14em] text-umber-soft uppercase">Sedang dibangun</p>
        <p class="mt-2 max-w-2xl text-[0.875rem] text-umber">
            Kelola merchant (approve, suspend, impersonate), kelola paket &amp; invoice, manajemen aset
            perangkat dengan remote lock, dan log audit belum tersedia di versi ini.
        </p>
    </div>
</div>
