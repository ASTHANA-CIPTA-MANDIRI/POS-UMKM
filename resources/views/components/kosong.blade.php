@props(['judul', 'keterangan' => null])

{{-- Keadaan kosong yang menuntun, bukan sekadar memberitahu bahwa data tidak ada. --}}
<div class="rounded-[20px] border border-dashed border-line px-4 py-14 text-center">
    <p class="text-[0.9375rem] font-semibold text-ink">{{ $judul }}</p>
    @if ($keterangan)
        <p class="mx-auto mt-1 max-w-sm text-[0.8125rem] text-umber">{{ $keterangan }}</p>
    @endif
    @if (isset($aksi))
        <div class="mt-4 flex justify-center">{{ $aksi }}</div>
    @endif
</div>
