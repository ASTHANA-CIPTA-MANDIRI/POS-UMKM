@props(['kunci' => []])

{{--
    Baris istilah untuk satu layar: "Istilah di layar ini: Modal · Untung · Batas menipis".

    KENAPA BARIS SENDIRI, dan bukan ditempel di judul kolom tabelnya.

    Terukur dari potret: <x-jelaskan> di dalam <th> membuat gelembung penjelasannya TERJEPIT
    selebar kolomnya — sekitar 190px, jadi kalimatnya turun satu kata per baris dan menjadi
    pita sempit yang lebih sulit dibaca daripada tidak ada penjelasan sama sekali. Ia juga
    mendorong seluruh baris judul tabel ke bawah. Pemeriksa kerapian melaporkannya BERSIH
    (tidak ada yang bertumpuk, tidak ada yang menggulir), jadi hanya mata yang bisa
    menemukannya.

    Membuat gelembungnya melayang (absolute) bukan jalan keluar di sini: kartu tabelnya
    ber-`overflow-hidden` demi sudut membulat, jadi gelembung yang melayang akan terpotong.

    Bentuk ini juga LEBIH BAIK untuk orang yang dituju, bukan cuma lebih mudah dibuat: satu
    tempat yang sama di tiap layar, terlihat sebelum orangnya bingung, dan tidak bersembunyi
    di judul kolom yang mungkin tidak pernah ia perhatikan.
--}}
@php
    $tersedia = collect($kunci)
        ->map(fn (string $k) => [$k, \App\Support\Istilah::ambil($k)])
        ->filter(fn (array $p) => $p[1] !== null);
@endphp

@if ($tersedia->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'kartu mb-4 px-5 py-4']) }}>
        <p class="text-[0.8125rem] text-umber">
            <span class="font-semibold text-ink">Belum paham istilahnya?</span>
            Tekan kata yang mau dijelaskan.
        </p>

        {{-- flex-wrap dengan gap, bukan teks berpemisah titik: tiap kata jadi sasaran sentuh
             yang utuh dan tidak pernah terbelah ke dua baris. --}}
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
            @foreach ($tersedia as [$k, $isi])
                <x-jelaskan :kunci="$k" class="text-[0.875rem] font-semibold text-ink" />
            @endforeach
        </div>
    </div>
@endif
