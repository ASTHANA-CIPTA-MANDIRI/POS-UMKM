<footer class="border-t border-line-soft bg-cream-deep/50">
    <div class="mx-auto max-w-7xl px-5 py-14 sm:px-6 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
            <div>
                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-lg bg-gradient-to-br from-terracotta to-terracotta-deep">
                        <svg viewBox="0 0 24 24" class="size-4.5" fill="none" aria-hidden="true">
                            <path d="M4 8.4 12 4l8 4.4-8 4.4-8-4.4Z" fill="#FFFFFF" fill-opacity="0.95" />
                            <path d="M4 12.6 12 17l8-4.4" stroke="#FFFFFF" stroke-opacity="0.6" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span class="font-display text-base font-semibold text-ink">Nampan</span>
                </div>
                <p class="mt-4 max-w-xs text-[0.875rem] text-umber">
                    Kasir untuk warung, toko kelontong, depot air, dan laundry. Dibuat di Yogyakarta.
                </p>
            </div>

            @foreach ([
                'Produk' => [
                    'Cara jualan' => ['cara-jualan', null],
                    'Mode offline' => ['mode-offline', null],
                    'Harga' => ['harga', null],
                ],
                'Usaha' => [
                    'Warteg' => ['untuk-siapa', 'warteg'],
                    'Kelontong' => ['untuk-siapa', 'kelontong'],
                    'Depot air' => ['untuk-siapa', 'depot-air'],
                    'Laundry' => ['untuk-siapa', 'laundry'],
                ],
            ] as $kolom => $tautan)
                <div>
                    <p class="eyebrow text-umber-soft">{{ $kolom }}</p>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($tautan as $label => [$rute, $anchor])
                            <li>
                                <a
                                    href="{{ route($rute) }}{{ $anchor ? '#' . $anchor : '' }}"
                                    class="text-[0.875rem] text-ink transition-colors hover:text-terracotta"
                                >{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <div>
                <p class="eyebrow text-umber-soft">Bantuan</p>
                <ul class="mt-4 space-y-2.5">
                    @foreach (['Panduan pakai', 'Hubungi kami', 'Status layanan'] as $label)
                        <li>
                            {{-- Belum ada halamannya; ditandai agar tidak terlihat seperti tautan aktif. --}}
                            <span class="text-[0.875rem] text-umber-soft">{{ $label }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="mt-12 flex flex-col gap-3 rule pt-6 sm:flex-row sm:items-center sm:justify-between">
            <p class="font-mono text-[0.6875rem] tracking-wider text-umber-soft">
                &copy; {{ now()->year }} Nampan &middot; Asthana Cipta Mandiri
            </p>
            <p class="font-mono text-[0.6875rem] tracking-wider text-umber-soft">
                Harga dalam rupiah, belum termasuk pajak
            </p>
        </div>
    </div>
</footer>
