@props(['kunci', 'sebagai' => null])

{{--
    Penjelasan istilah yang bisa dibuka di tempat.

    KENAPA ISTILAHNYA SENDIRI YANG JADI TOMBOL, bukan ikon "?" kecil di sebelahnya. Ikon 16 px
    di tengah kalimat adalah sasaran sentuh yang meleset terus di layar ponsel, dan yang paling
    sering meleset justru jari orang tua — yaitu orang yang paling butuh penjelasannya. Seluruh
    katanya bisa ditekan, jadi sasarannya selebar kata itu sendiri.

    GARIS BAWAH PUTUS-PUTUS, bukan warna saja: yang tidak bisa membedakan warna tetap melihat
    bahwa kata ini berbeda dari kata di sebelahnya. Ikon "?" tetap ada sebagai penanda kedua.

    TIDAK memakai hover. Layar owner dipakai di tablet dan HP, dan di sana hover tidak ada
    sama sekali — penjelasan yang cuma muncul saat disentuh tetikus tidak pernah muncul.

    Isinya dari App\Support\Istilah, SATU sumber yang sama dengan halaman Istilah. Arti yang
    ditulis dua kali akan bercabang pada perbaikan pertama, dan yang bercabang di sini adalah
    penjelasan tentang uang.
--}}
@php
    $isi = \App\Support\Istilah::ambil($kunci);
    $label = $sebagai ?? $isi['istilah'] ?? $kunci;
    // id unik supaya aria-controls menunjuk panel yang benar walau satu halaman memuat
    // beberapa penjelasan sekaligus.
    $idPanel = 'jelaskan-'.$kunci.'-'.substr(md5($kunci.$label.uniqid()), 0, 6);
@endphp

@if ($isi === null)
    {{-- Kunci yang tidak dikenal TIDAK menghilangkan katanya dari layar: judul kolom yang
         lenyap karena salah ketik kunci jauh lebih buruk daripada kata tanpa penjelasan. --}}
    {{ $label }}
@else
    <span {{ $attributes->merge(['class' => 'inline-block']) }} x-data="{ buka: false }">
        <button type="button" x-on:click="buka = ! buka"
                :aria-expanded="buka ? 'true' : 'false'" aria-controls="{{ $idPanel }}"
                class="inline-flex cursor-pointer items-baseline gap-1 rounded text-left underline decoration-dotted decoration-from-font underline-offset-4 hover:text-terracotta-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-terracotta">
            {{ $label }}
            <svg viewBox="0 0 16 16" class="size-3.5 shrink-0 translate-y-px opacity-70" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3" />
                <path d="M6.4 6.2a1.6 1.6 0 1 1 1.9 1.7v1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
                <circle cx="8" cy="11.3" r=".7" fill="currentColor" />
            </svg>
            <span class="sr-only">— buka penjelasan</span>
        </button>

        <span id="{{ $idPanel }}" x-show="buka" x-cloak x-collapse
              class="mt-2 block rounded-xl border border-line bg-cream/70 p-3.5 text-left text-[0.875rem] leading-relaxed font-normal normal-case text-umber">
            <span class="block font-bold text-ink">{{ $isi['istilah'] }}</span>
            <span class="mt-1 block">{{ $isi['arti'] }}</span>

            @if ($isi['contoh'])
                {{-- Contoh berangka rupiah adalah penjelasan yang sebenarnya; kalimat di
                     atasnya cuma pengantar. Orang yang tidak paham "30%" langsung paham
                     "modal Rp 10.000, dijual Rp 14.500". --}}
                <span class="mt-2 block rounded-lg bg-white px-3 py-2 text-[0.8125rem] text-ink">
                    <span class="font-semibold">Contohnya:</span> {{ $isi['contoh'] }}
                </span>
            @endif

            <a href="{{ route('owner.istilah') }}#{{ $kunci }}" wire:navigate
               class="mt-2 inline-block text-[0.8125rem] font-semibold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">
                Lihat semua istilah
            </a>
        </span>
    </span>
@endif
