<?php

namespace App\Providers;

use App\Contracts\InsuranceCoverLetterPdfRenderer;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\CkpnAgeBucket;
use App\Models\CkpnCalculationRule;
use App\Models\ClaimDocumentRequirement;
use App\Models\ClaimDocumentType;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceCoverLetterSetting;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableDocument;
use App\Models\ReceivablePayment;
use App\Models\ReceivablePaymentRequest;
use App\Observers\InsuranceReceivableObserver;
use App\Policies\ApiIntegrationLogPolicy;
use App\Policies\BranchOfficePolicy;
use App\Policies\CkpnAgeBucketPolicy;
use App\Policies\CkpnCalculationRulePolicy;
use App\Policies\ClaimDocumentRequirementPolicy;
use App\Policies\ClaimDocumentTypePolicy;
use App\Policies\ClaimStatusPolicy;
use App\Policies\InsuranceCompanyPolicy;
use App\Policies\InsuranceCoverLetterPolicy;
use App\Policies\InsuranceCoverLetterSettingPolicy;
use App\Policies\InsuranceReceivableDocumentPolicy;
use App\Policies\InsuranceReceivablePolicy;
use App\Policies\ReceivablePaymentPolicy;
use App\Policies\ReceivablePaymentRequestPolicy;
use App\Policies\RolePolicy;
use App\RateLimit\WhatsAppRateLimit;
use App\Services\InsuranceCoverLetter\DompdfInsuranceCoverLetterPdfRenderer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InsuranceCoverLetterPdfRenderer::class, DompdfInsuranceCoverLetterPdfRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(BranchOffice::class, BranchOfficePolicy::class);
        Gate::policy(InsuranceCompany::class, InsuranceCompanyPolicy::class);
        Gate::policy(ClaimDocumentType::class, ClaimDocumentTypePolicy::class);
        Gate::policy(ClaimDocumentRequirement::class, ClaimDocumentRequirementPolicy::class);
        Gate::policy(InsuranceCoverLetterSetting::class, InsuranceCoverLetterSettingPolicy::class);
        Gate::policy(ClaimStatus::class, ClaimStatusPolicy::class);
        Gate::policy(CkpnAgeBucket::class, CkpnAgeBucketPolicy::class);
        Gate::policy(CkpnCalculationRule::class, CkpnCalculationRulePolicy::class);
        Gate::policy(InsuranceReceivable::class, InsuranceReceivablePolicy::class);
        Gate::policy(InsuranceReceivableDocument::class, InsuranceReceivableDocumentPolicy::class);
        Gate::policy(ReceivablePayment::class, ReceivablePaymentPolicy::class);
        Gate::policy(ReceivablePaymentRequest::class, ReceivablePaymentRequestPolicy::class);
        Gate::policy(InsuranceCoverLetter::class, InsuranceCoverLetterPolicy::class);
        Gate::policy(ApiIntegrationLog::class, ApiIntegrationLogPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        WhatsAppRateLimit::onAppBoot();

        InsuranceReceivable::observe(InsuranceReceivableObserver::class);
    }
}
