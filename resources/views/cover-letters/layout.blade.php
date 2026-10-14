{{-- Shared cover letter layout. Values arrive pre-formatted from InsuranceCoverLetterRenderer; {{ }} escapes them. --}}
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title')</title>
  <style>
:root {
  --page-width: 210mm;
  --page-height: 297mm;
  --page-pad-x: 15mm;
  --page-pad-y: 10mm;
}

* {
  box-sizing: border-box;
}

html,
body {
  margin: 0;
  padding: 0;
}

body {
  background: #e9e9e9;
  color: #000;
  font-family: "Times New Roman", Times, serif;
  font-size: 12pt;
  line-height: 1.18;
}

.page {
  position: relative;
  width: var(--page-width);
  min-height: var(--page-height);
  margin: 0 auto 16px;
  padding: var(--page-pad-y) var(--page-pad-x) 18mm;
  background: #fff;
  overflow: hidden;
}

.logo {
  display: block;
  width: 64mm;
  height: auto;
  margin: 0 0 5mm 0;
}

.meta,
.recipient,
.body-section,
.closing {
  width: 100%;
}

.date-line {
  margin: 0 0 5mm 0;
}

.info-table {
  border-collapse: collapse;
  margin: 0 0 6mm 0;
}

.info-table td {
  border: 0;
  padding: 0;
  vertical-align: top;
  line-height: 1.15;
}

.info-table .label {
  width: 20mm;
}

.info-table .colon {
  width: 5mm;
  text-align: center;
}

.recipient {
  margin-bottom: 2mm;
}

.recipient .title {
  margin-bottom: 0;
  font-weight: 700;
}

.recipient p,
.body-section p,
.closing p {
  margin: 0 0 4mm 0;
}

.attn {
  margin: 0 0 5mm 0;
  font-weight: 700;
}

.claim-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  margin: 5mm 0 4mm 0;
}

.claim-table th,
.claim-table td {
  border: 1px solid #000;
  padding: 2.5mm 1.5mm;
  text-align: center;
  vertical-align: middle;
  line-height: 1.05;
}

.claim-table th {
  height: 13mm;
  background: #f2f2f2;
  font-weight: 700;
}

.claim-table td {
  min-height: 10mm;
  font-weight: 700;
}

.claim-table .col-name { width: 29%; }
.claim-table .col-loan { width: 20%; }
.claim-table .col-claim { width: 17.5%; }
.claim-table .col-start { width: 16.75%; }
.claim-table .col-end { width: 16.75%; }

ol {
  margin: 0 0 6mm 11mm;
  padding-left: 6mm;
}

ol li {
  margin: 0 0 0.6mm 0;
  padding-left: 1.5mm;
}

/* The numbered list continues into the a/b sub-list, so no gap before it. */
ol.before-alpha {
  margin-bottom: 0;
}

ol.alpha {
  list-style-type: lower-alpha;
  margin-left: 14mm;
  margin-top: 1mm;
  margin-bottom: 3mm;
}

ul.bullets {
  margin: 0.5mm 0 1.5mm 8mm;
  padding-left: 5mm;
}

ul.bullets li {
  margin: 0 0 0.6mm 0;
}

.paragraph-tight {
  margin-bottom: 2.5mm !important;
}

.payment {
  margin-top: 3mm;
}

.signature {
  margin-top: 7mm;
}

.signature p {
  margin-bottom: 0;
}

.signature .company {
  font-weight: 700;
}

.footer {
  position: absolute;
  left: var(--page-pad-x);
  right: var(--page-pad-x);
  bottom: 9mm;
  padding-top: 1.5mm;
  border-top: 1px solid #000;
  text-align: center;
  font-size: 9.7pt;
  line-height: 1.1;
}

.footer .company {
  font-size: 10.8pt;
}

@page {
  size: A4;
  margin: 0;
}

@media print {
  /* Dompdf renders with print media and ignores box-sizing: a fixed 210mm width plus padding overflowed
     the sheet. Let the page fill A4 and pin the footer to the sheet bottom instead. */
  body {
    background: #fff;
  }

  .page {
    width: auto;
    min-height: 0;
    margin: 0;
    page-break-after: always;
    break-after: page;
  }

  .footer {
    position: fixed;
  }

  .page:last-child {
    page-break-after: auto;
    break-after: auto;
  }
}
</style>
</head>
<body>

<section class="page" id="@yield('section-id')">

    <img class="logo" src="/logo.png" alt="Bank DP Taspen">
    <div class="meta">
      <p class="date-line">Bekasi, {{ $letterDate }}</p>
      <table class="info-table" aria-label="Informasi surat">
        <tr><td class="label">Nomor</td><td class="colon">:</td><td>@yield('letter-number', $letterNumber)</td></tr>
        <tr><td class="label">Sifat</td><td class="colon">:</td><td>Penting</td></tr>
        <tr><td class="label">Lampiran</td><td class="colon">:</td><td>1 (satu)&nbsp; Berkas</td></tr>
      </table>
    </div>

    <div class="recipient">
      <p class="title">Kepada :</p>
      <p><strong>{{ $recipientName }}</strong><br>
      <strong>{{ $recipientAddress }}</strong></p>
    </div>

    <p class="attn">Up : Bag Klaim Asuransi</p>

    <div class="body-section">
      <p>Dengan Hormat,</p>
      <p>Dengan ini kami sampaikan bahwa nasabah kami yang bernama :</p>


      <table class="claim-table" aria-label="Data klaim asuransi">
        <thead>
          <tr>
            <th class="col-name">Nama</th>
            <th class="col-loan">Nominal<br>Pinjaman</th>
            <th class="col-claim">Nominal<br>Klaim</th>
            <th class="col-start">Tanggal<br>Realisasi<br>Kredit</th>
            <th class="col-end">Tanggal Jatuh<br>Tempo Kredit</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>{{ $customerName }}</td>
            <td>{{ $creditLimit }}</td>
            <td>{{ $claimAmount }}</td>
            <td>{{ $startPeriod }}</td>
            <td>{{ $endPeriod }}</td>
          </tr>
        </tbody>
      </table>

@yield('attachments')
    </div>

    <div class="closing signature">
      <p>Hormat Kami,<br>
      <span class="company">PT BPR DP TASPEN</span></p>
    </div>

    <footer class="footer">
      <div class="company">PT BPR DP TASPEN</div>
      <div>Jl. Raya Pondok Gede No.9 - Pondok Gede Bekasi 17413, Telp (021) 8467944&nbsp; - 84971636 Fax. (021) 8487577</div>
      <div>Website : www.bankdptaspen.co.id Email : bprtaspen@gmail.com</div>
    </footer>

  </section>

</body>
</html>
