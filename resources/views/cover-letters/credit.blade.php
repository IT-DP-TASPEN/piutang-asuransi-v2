@extends('cover-letters.layout')

@section('title', 'Surat Pengantar Klaim Asuransi')
@section('section-id', 'surat-klaim-asuransi-kredit')
@section('letter-number')<strong>{{ $letterNumber }}</strong>@endsection

@section('attachments')
      <p>Kami bermaksud mengajukan Klaim asuransi yang bersangkutan, sebagai dasar pengajuan klaim berikut kami lampirkan:</p>
      <ol>
        <li>Surat Pengajuan Klaim dari Pemegang Polis</li>
        <li>Formulir STGR</li>
        <li>Foto Copy perjanjian kredit yang bersangkutan</li>
      </ol>
      <p class="payment">Demikian kami sampaikan, untuk pembayaran klaim dapat di transfer ke rekening MANDIRI dengan nomor rekening 167-001-811-9908 atas nama BPR DP TASPEN. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.</p>
@endsection
