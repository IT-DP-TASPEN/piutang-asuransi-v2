<?php

namespace App\Providers;

use App\Models\BranchOffice;
use App\Models\CkpnAgeBucket;
use App\Models\CkpnCalculationRule;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Policies\BranchOfficePolicy;
use App\Policies\CkpnAgeBucketPolicy;
use App\Policies\CkpnCalculationRulePolicy;
use App\Policies\ClaimStatusPolicy;
use App\Policies\InsuranceCompanyPolicy;
use App\Policies\RolePolicy;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(BranchOffice::class, BranchOfficePolicy::class);
        Gate::policy(InsuranceCompany::class, InsuranceCompanyPolicy::class);
        Gate::policy(ClaimStatus::class, ClaimStatusPolicy::class);
        Gate::policy(CkpnAgeBucket::class, CkpnAgeBucketPolicy::class);
        Gate::policy(CkpnCalculationRule::class, CkpnCalculationRulePolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}
