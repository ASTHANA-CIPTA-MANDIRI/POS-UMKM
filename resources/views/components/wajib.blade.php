@props(['bersyarat' => false])

{{--
    Penanda medan wajib: bintang merah sesudah label.

    SATU bentuk untuk seluruh aplikasi. Ditulis per layar, bentuknya akan bercabang —
    dan penanda "wajib" yang berbeda-beda di tiap layar berhenti terbaca sebagai aturan.

    Dua hal yang membuatnya bukan sekadar tanda bintang:

    1. Bintangnya `aria-hidden`, dan artinya dititipkan ke teks `sr-only`. Bintang telanjang
       dibacakan pembaca layar sebagai "asterisk" atau dilewati sama sekali — sementara orang
       yang memakainya justru paling butuh tahu medan mana yang menahan tombol simpan.

    2. Warnanya `text-merah-tua`, bukan `text-merah-deep`. Keduanya merah, tapi yang kedua
       hanya 4,15:1 di atas latar bertint; merah-tua terukur 7,14:1 di atas putih maupun
       cream. Label formulir memang berlatar putih hari ini, tapi penanda sekecil ini adalah
       yang pertama hilang begitu latarnya berubah — dan kalau hilang, tidak ada yang
       menyadarinya sampai ada yang menekan simpan lalu ditolak.

    `bersyarat` untuk medan yang wajibnya bergantung keadaan (mis. alasan hanya wajib kalau
    ada beda). Judulnya berubah supaya orang tidak menyimpulkan medan itu selalu wajib.
--}}
<span {{ $attributes->merge(['class' => 'ml-0.5 align-middle text-[0.9375rem] leading-none font-bold text-merah-tua']) }}
      title="{{ $bersyarat ? 'Wajib diisi untuk keadaan ini' : 'Wajib diisi' }}">
    <span aria-hidden="true">*</span>
    <span class="sr-only">{{ $bersyarat ? '(wajib diisi untuk keadaan ini)' : '(wajib diisi)' }}</span>
</span>
