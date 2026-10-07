<?php

namespace App\Services\KolekRpa;

use App\Models\ApiIntegrationLog;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Drives the Fincloud Web endpoints the way the former kolek-rpa service did. Both operations
 * only create pending changes in Fincloud; someone still has to approve them there.
 */
class KolekRpaClient
{
    private const LOGIN_PATH = '/admin/access/login';

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:142.0) Gecko/20100101 Firefox/142.0';

    // The repayment-account form expects the inquired loan record echoed back under the same keys.
    private const REPAYMENT_FIELDS = [
        'lokasi', 'id', 'nopk', 'namanasabah', 'aliasnama', 'statusrekening', 'rec_dibuat_oleh', 'noalt',
        'produk_jenispinjaman', 'produkid', 'idproduk', 'currency', 'jangkawaktu', 'produk_jenisangsuran',
        'sid_sifatkredit2', 'sid_jenispenggunaan', 'sid_sumberdanapelunasan', 'sid_golongankredit',
        'sid_orientasipenggunaan', 'sid_sektorekonomi', 'sid_sektorekonomi2', 'sid_sifatkredit', 'datapenjamin',
        'mengetahuisuamiistri', 'sid_jenisusaha', 'noperjanjiankredit', 'periode', 'produk_perubahansukubunga',
        'updatekolekbi', 'nocif', 'jenisnasabah', 'dataalamat_ktp_alamat1', 'dataalamat_ktp_alamat2',
        'dataalamat_ktp_rt', 'dataalamat_ktp_rw', 'dataalamat_ktp_kelurahan', 'dataalamat_ktp_kecamatan',
        'dataalamat_ktp_kota', 'dataalamat_ktp_propinsi', 'dataalamat_ktp_kodepos', 'norektab_pencairanpinjaman',
        'plafondlimit', 'jmlpokok_pinjaman', 'tgl_angsuran', 'bungaflat', 'persendendatunggakan', 'titipan',
        'produk_sukubunga', 'outstandingpinjaman', 'tunggakanpokok', 'accrue', 'decimalpoint', 'tunggakanbunga',
        'dendatunggakan', 'dpd', 'kolekbi', 'kolekbpr', 'totalcollateralvalue', 'totalassetvalue',
        'pejabatkredit', 'pejabatkreditdua', 'tujuankredit',
    ];

    private const REPAYMENT_DATE_FIELDS = [
        'tgl_pencairan', 'tglterakhir_bayarpokokdanbunga', 'tglbayarpokokbunga_berikutnya',
        'tgljtterakhir', 'tgljtberikutnya', 'tglbukacif',
    ];

    public function __construct(private readonly CoreBankingClient $coreBanking) {}

    /**
     * @return array<string, mixed>
     */
    public function setRepaymentAccount(string $loanAccount, string $savingAccount, ?Model $related = null, ?User $requestedBy = null): array
    {
        $loanAccount = trim($loanAccount);
        $savingAccount = trim($savingAccount);
        $session = $this->login($loanAccount, $related, $requestedBy);

        $loan = $this->request('GET', '/pinjaman/pendaftaranPenghapusanAutodebit/pembuatan/cari', ['norekening' => $loanAccount], $session, $related, $requestedBy);
        if (($loan['id'] ?? null) !== $loanAccount) {
            $this->fail("Loan inquiry did not return account {$loanAccount}.");
        }

        $inquiry = $this->coreBanking->inquireBalance($savingAccount, $related, $requestedBy);
        $saving = $inquiry['data'];
        if (! $inquiry['ok']) {
            $this->fail("Saving account {$savingAccount} inquiry failed: ".($inquiry['description'] ?: $inquiry['error_message'] ?: 'no response').'.');
        }
        if (($saving['accountNumber'] ?? null) !== $savingAccount
            || trim((string) ($saving['customerName'] ?? '')) === ''
            || trim((string) ($saving['documentStatus'] ?? '')) === ''
            || trim((string) ($saving['currency'] ?? '')) === '') {
            $this->fail("Saving account {$savingAccount} inquiry is incomplete.");
        }
        if (strcasecmp(trim($saving['documentStatus']), 'Active') !== 0) {
            $this->fail("Saving account {$savingAccount} has status {$saving['documentStatus']}.");
        }

        $form = [];
        foreach (self::REPAYMENT_FIELDS as $field) {
            $value = $loan[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $form[$field] = (string) $value;
            }
        }
        foreach (self::REPAYMENT_DATE_FIELDS as $field) {
            if (($date = $this->date($loan[$field] ?? null)) !== '') {
                $form[$field] = $date;
            }
        }

        $this->request('POST', '/pinjaman/pendaftaranPenghapusanAutodebit/pembuatan/pinjaman', [
            ...$form,
            'status_dokumen' => 'Diajukan',
            'norektab_bayarangsuran' => $savingAccount,
            'tabbayar_namapemilik' => $saving['customerName'],
            'tabbayar_status' => $saving['documentStatus'],
            'tabbayar_currency' => $saving['currency'],
        ], $session, $related, $requestedBy);

        return [
            'primary_loan_account' => $loanAccount,
            'branch' => substr($loanAccount, 3, 3),
            'saving_account' => $savingAccount,
            'saving_account_name' => $saving['customerName'],
            'saving_account_status' => $saving['documentStatus'],
            'currency' => $saving['currency'],
        ];
    }

    /**
     * Sets BI and BPR collectability together; skipped when both already match.
     *
     * @return array<string, mixed>
     */
    public function updateCollectability(string $loanAccount, int $kolek, string $changeType, ?Model $related = null, ?User $requestedBy = null): array
    {
        $loanAccount = trim($loanAccount);
        $session = $this->login($loanAccount, $related, $requestedBy);

        $loan = $this->request('GET', '/pinjaman/updateManualKolek/pembuatan/cari', ['norekening' => $loanAccount], $session, $related, $requestedBy);
        $detail = $loan['datarekening'] ?? [];
        $transactionDate = $this->date($loan['appdate'] ?? null);

        if (($loan['norekening'] ?? null) !== $loanAccount
            || trim((string) ($loan['namanasabah'] ?? '')) === ''
            || trim((string) ($loan['nopk'] ?? '')) === ''
            || $transactionDate === ''
            || ! is_int($detail['kolekbi'] ?? null) || ! is_int($detail['kolekbpr'] ?? null) || ! is_int($detail['dpd'] ?? null)
            || trim((string) ($detail['updatekolekbi'] ?? '')) === '' || trim((string) ($detail['updatekolekbpr'] ?? '')) === ''
            || ! is_numeric($detail['totalassetvalue'] ?? null) || ! is_numeric($detail['totalcollateralvalue'] ?? null)) {
            $this->fail("Collectability inquiry for {$loanAccount} is incomplete.");
        }

        $result = [
            'primary_account' => $loanAccount,
            'branch' => substr($loanAccount, 3, 3),
            'old_kolek_bi' => $detail['kolekbi'],
            'old_kolek_bpr' => $detail['kolekbpr'],
            'new_kolek' => $kolek,
        ];

        if ($detail['kolekbi'] === $kolek && $detail['kolekbpr'] === $kolek
            && strcasecmp(trim($detail['updatekolekbi']), $changeType) === 0
            && strcasecmp(trim($detail['updatekolekbpr']), $changeType) === 0) {
            return [...$result, 'status' => 'SKIPPED', 'reason' => "collectability and change type already {$kolek}/{$changeType}"];
        }

        $this->request('POST', '/pinjaman/updateManualKolek/pembuatan/pinjaman', [
            'jenistransaksi' => 'Update Manual Kolektibilitas BI & Internal',
            'norekening' => $loanAccount,
            'namanasabah' => $loan['namanasabah'],
            'nopk' => $loan['nopk'],
            'tgl_transaksi' => $transactionDate,
            'total_collateralvalue' => (string) $detail['totalcollateralvalue'],
            'total_assetvalue' => (string) $detail['totalassetvalue'],
            'dpd' => (string) $detail['dpd'],
            'nilai_kolekbilama' => (string) $detail['kolekbi'],
            'nilai_kolekbprlama' => (string) $detail['kolekbpr'],
            'nilai_kolekbi' => (string) $kolek,
            'nilai_kolekbpr' => (string) $kolek,
            'jenisperubahan_kolekbi' => $changeType,
            'jenisperubahan_kolekbpr' => $changeType,
            'status_dokumen' => 'Diajukan',
        ], $session, $related, $requestedBy);

        return [...$result, 'status' => 'SUCCESS'];
    }

    /**
     * Logs in at the loan's branch (digits 4-6 of a primary account); Fincloud only lets that branch file the change.
     */
    private function login(string $loanAccount, ?Model $related, ?User $requestedBy): string
    {
        $config = config('services.fincloud_web');

        if (trim((string) ($config['base_url'] ?? '')) === '' || trim((string) ($config['username'] ?? '')) === ''
            || trim((string) ($config['password'] ?? '')) === '' || trim((string) ($config['role_id'] ?? '')) === '') {
            $this->fail('Fincloud Web configuration is incomplete.');
        }

        // Ten digits is an alternate number, which needs a cabang=ALL lookup; receivables store primary accounts.
        if (preg_match('/^\d{6,}$/', $loanAccount) !== 1 || strlen($loanAccount) === 10) {
            $this->fail("Loan account {$loanAccount} is not a primary loan account.");
        }

        $result = $this->request('POST', self::LOGIN_PATH, [
            'locationid' => substr($loanAccount, 3, 3),
            'roleid' => $config['role_id'],
            'username' => $config['username'],
            'pwd' => $config['password'],
        ], null, $related, $requestedBy);

        return is_string($result['sessionid'] ?? null) && $result['sessionid'] !== ''
            ? $result['sessionid']
            : $this->fail('Fincloud Web login returned no session.');
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed> the response's data.result
     */
    private function request(string $method, string $path, array $data, ?string $session, ?Model $related, ?User $requestedBy): array
    {
        $requestedAt = now();
        $startedAt = hrtime(true);
        $status = null;
        $decoded = null;
        $raw = null;
        $errorMessage = null;
        $isLogin = $path === self::LOGIN_PATH;

        try {
            $pending = Http::withOptions(['verify' => (bool) config('services.fincloud_web.verify_ssl')])
                ->withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->withHeaders($session === null ? [] : ['sessionid' => $session])
                ->timeout((int) config('services.fincloud_web.timeout', 15));
            $url = rtrim((string) config('services.fincloud_web.base_url'), '/').$path;
            $response = $method === 'GET' ? $pending->get($url, $data) : $pending->asForm()->post($url, $data);

            $status = $response->status();
            $raw = $response->body();
            $decoded = $response->json();

            if ($status !== 200 || ($decoded['status'] ?? null) !== 'ok') {
                $errorMessage = "Fincloud rejected {$path}: ".($decoded['error']['system'] ?? "HTTP {$status}").'.';
            }
        } catch (Throwable $exception) {
            $errorMessage = 'Fincloud Web is unavailable: '.$exception->getMessage();
        }

        ApiIntegrationLog::create([
            'service_name' => 'kolek_rpa',
            'endpoint' => $path,
            'method' => $method,
            'request_headers' => ['Accept' => 'application/json'],
            'request_body' => $isLogin ? [...$data, 'pwd' => '[masked]'] : $data,
            'response_status' => $status,
            // The login response carries the session id.
            'response_body' => $isLogin ? [] : (is_array($decoded) ? $decoded : ($raw === null ? [] : ['raw' => $raw])),
            'is_success' => $errorMessage === null,
            'error_message' => $errorMessage,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'requested_by' => $requestedBy?->id,
            'requested_at' => $requestedAt,
            'completed_at' => now(),
        ]);

        if ($errorMessage !== null) {
            $this->fail($errorMessage);
        }

        $result = $decoded['data']['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * Fincloud dates arrive as {"date": "2026-08-27 00:00:00.000000"} and are posted back as "2026-8-27".
     */
    private function date(mixed $value): string
    {
        $raw = trim((string) (is_array($value) ? ($value['date'] ?? '') : ''));

        return $raw === '' ? '' : Carbon::createFromFormat('!Y-m-d', substr($raw, 0, 10))->format('Y-n-j');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['kolek_rpa' => $message]);
    }
}
