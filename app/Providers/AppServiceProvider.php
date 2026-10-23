<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\EnsureCentralContext;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantContext;
use App\Models;
use App\Models\School;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantUserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests and between queued jobs, so one
        // school's context can never bleed into the next unit of work.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->registerMorphMap();
        $this->registerTenancy();

        // Super admins only ever act in the central console, where they hold
        // every platform permission.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }

    private function registerTenancy(): void
    {
        // {school} only matches a well-formed slug that isn't reserved, so tenant
        // routes can never capture /super-admin, /livewire, /storage and the like.
        $reserved = collect(config('tenancy.reserved_slugs'))
            ->filter(fn (string $slug) => preg_match('/^[a-z0-9-]+$/', $slug))
            ->map(fn (string $slug) => preg_quote($slug, '#'))
            ->implode('|');

        Route::pattern(config('tenancy.route_parameter'), "(?!(?:{$reserved})(?![a-z0-9-]))[a-z0-9]+(?:-[a-z0-9]+)*");

        Auth::provider('tenant-eloquent', fn ($app, array $config) => new TenantUserProvider($app['hash'], $config['model']));

        // Livewire update requests replay these from the originating page's route.
        Livewire::addPersistentMiddleware([
            EnsureTenantContext::class,
            EnsureCentralContext::class,
            EnsureSuperAdmin::class,
            EnsurePortalAccess::class,
            EnsureAccountIsUsable::class,
        ]);

        // TenantContext stores the active tenant in hidden Context, which Laravel
        // serialises into every queued job. Restore it on the worker.
        Context::hydrated(function (ContextRepository $context): void {
            $state = $context->getHidden('tenancy');

            if (! is_array($state)) {
                return;
            }

            if ($state['central'] ?? false) {
                tenancy()->enterCentral();

                return;
            }

            $school = isset($state['school_id']) ? School::find($state['school_id']) : null;

            $school ? tenancy()->set($school) : tenancy()->forget();
        });
    }

    /**
     * Stable aliases for polymorphic columns (activity log, leave applicants,
     * roles, notifications), so renaming a class never orphans rows.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap([
            'plan' => Models\Plan::class,
            'school' => Models\School::class,
            'subscription_payment' => Models\SubscriptionPayment::class,
            'user' => Models\User::class,
            'role' => Models\Role::class,
            'permission' => \Spatie\Permission\Models\Permission::class,
            'academic_year' => Models\AcademicYear::class,
            'term' => Models\Term::class,
            'campus' => Models\Campus::class,
            'department' => Models\Department::class,
            'classroom' => Models\Classroom::class,
            'school_class' => Models\SchoolClass::class,
            'section' => Models\Section::class,
            'subject' => Models\Subject::class,
            'subject_class' => Models\SubjectClass::class,
            'timetable' => Models\Timetable::class,
            'staff_profile' => Models\StaffProfile::class,
            'student_profile' => Models\StudentProfile::class,
            'parent_profile' => Models\ParentProfile::class,
            'parent_student' => Models\ParentStudent::class,
            'student_enrollment' => Models\StudentEnrollment::class,
            'student_attendance' => Models\StudentAttendance::class,
            'staff_attendance' => Models\StaffAttendance::class,
            'leave_type' => Models\LeaveType::class,
            'leave_balance' => Models\LeaveBalance::class,
            'leave_request' => Models\LeaveRequest::class,
            'grade_scale' => Models\GradeScale::class,
            'grade_scale_item' => Models\GradeScaleItem::class,
            'examination' => Models\Examination::class,
            'exam_schedule' => Models\ExamSchedule::class,
            'mark' => Models\Mark::class,
            'exam_remark' => Models\ExamRemark::class,
            'homework' => Models\Homework::class,
            'homework_submission' => Models\HomeworkSubmission::class,
            'fee_structure' => Models\FeeStructure::class,
            'fee_discount' => Models\FeeDiscount::class,
            'student_fee_discount' => Models\StudentFeeDiscount::class,
            'sibling_discount_rule' => Models\SiblingDiscountRule::class,
            'invoice_batch' => Models\InvoiceBatch::class,
            'invoice' => Models\Invoice::class,
            'invoice_item' => Models\InvoiceItem::class,
            'payment' => Models\Payment::class,
            'finance_category' => Models\FinanceCategory::class,
            'expense' => Models\Expense::class,
            'income_ledger' => Models\IncomeLedger::class,
            'payroll_record' => Models\PayrollRecord::class,
            'library_book' => Models\LibraryBook::class,
            'book_issue' => Models\BookIssue::class,
            'transport_vehicle' => Models\TransportVehicle::class,
            'transport_route' => Models\TransportRoute::class,
            'student_transport' => Models\StudentTransport::class,
            'hostel_building' => Models\HostelBuilding::class,
            'hostel_room' => Models\HostelRoom::class,
            'student_hostel' => Models\StudentHostel::class,
            'notice' => Models\Notice::class,
            'activity' => Models\Activity::class,
        ]);
    }
}
