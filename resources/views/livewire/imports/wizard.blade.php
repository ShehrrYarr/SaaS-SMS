<?php

/*
 * CSV import in three steps: upload (with a template to start from), check
 * every row against the school's data, then import the valid rows and hand
 * out a sign-in sheet for the new accounts.
 *     <livewire:imports.wizard kind="students" :done-url="route('admin.students.index')" />
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Services\Imports\RowImport;
use App\Services\Imports\StaffImport;
use App\Services\Imports\StudentImport;
use App\Services\People\CredentialSheet;
use App\Tables\ActionFailed;
use Livewire\WithFileUploads;

use function Livewire\Volt\{mount, protect, state, uses};

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['kind' => 'students', 'doneUrl' => null, 'step' => 'upload', 'summary' => null, 'problems' => [], 'preview' => [], 'imported' => 0, 'sheet' => null])->locked();

state(['file' => null]);

// kind => [importer, permission]
$importers = protect(fn () => [
    'students' => [StudentImport::class, 'students.import'],
    'staff' => [StaffImport::class, 'staff.import'],
]);

mount(function (string $kind, ?string $doneUrl = null) {
    abort_unless(array_key_exists($kind, $this->importers()), 404);

    $this->kind = $kind;
    $this->doneUrl = $doneUrl;
    $this->authorize($this->importers()[$kind][1]);
});

$importer = protect(function (): RowImport {
    $this->authorize($this->importers()[$this->kind][1]);

    return app($this->importers()[$this->kind][0]);
});

$downloadTemplate = function () {
    $csv = $this->importer()->template();

    return response()->streamDownload(fn () => print ($csv), "{$this->kind}-import-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
};

$check = function () {
    $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [], ['file' => __('CSV file')]);

    try {
        $result = $this->importer()->check($this->file->getRealPath());
    } catch (ActionFailed $e) {
        $this->addError('file', $e->getMessage());

        return;
    }

    $columns = $this->importer()->columns();
    $label = fn (array $values) => $values['name'] ?? '';

    $this->summary = ['total' => count($result['rows']), 'valid' => $result['valid'], 'invalid' => $result['invalid'], 'name' => $this->file->getClientOriginalName()];
    $this->problems = collect($result['rows'])->filter(fn ($row) => $row['errors'])->take(200)
        ->map(fn ($row) => ['line' => $row['line'], 'name' => $label($row['values']), 'errors' => $row['errors']])->values()->all();
    $this->preview = collect($result['rows'])->filter(fn ($row) => ! $row['errors'])->take(15)
        ->map(fn ($row) => ['line' => $row['line'], 'name' => $label($row['values']), 'notes' => $row['notes'], 'values' => $row['values']])->values()->all();
    $this->step = 'review';
};

$import = function (CredentialSheet $sheets) {
    abort_unless($this->step === 'review' && $this->file, 400);

    $importer = $this->importer();

    try {
        // Checked again: the school's data may have changed since the review.
        $result = $importer->check($this->file->getRealPath());
        $rows = collect($result['rows'])->filter(fn ($row) => ! $row['errors'])->pluck('data')->all();

        $outcome = $importer->import($rows, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->imported = $outcome->imported;
    $this->sheet = $sheets->store($outcome->credentials);
    $this->step = 'done';
    $this->reset('file');
    $this->refreshTables();
};

$downloadSheet = function (CredentialSheet $sheets) {
    $this->authorize($this->importers()[$this->kind][1]);

    $response = $this->sheet ? $sheets->download($this->sheet, "{$this->kind}-sign-in-details-".now()->format('Y-m-d-His').'.csv') : null;

    if (! $response) {
        $this->toast(__('The sign-in sheet has expired. Reset passwords individually from the accounts list.'), 'warning');

        return;
    }

    return $response;
};

$startOver = function () {
    $this->reset('file');
    $this->step = 'upload';
    $this->summary = null;
    $this->problems = [];
    $this->preview = [];
    $this->resetValidation();
};

$columns = protect(fn () => $this->importer()->columns());

?>

@php
    $columns = $this->columns();
    $steps = ['upload' => __('Upload'), 'review' => __('Check'), 'done' => __('Import')];
    $stepKeys = array_keys($steps);
@endphp

<div>
    <ol class="wizard-steps mb-4">
        @foreach ($steps as $key => $label)
            @php($index = array_search($key, $stepKeys, true))
            @php($current = array_search($step, $stepKeys, true))
            <li @class(['is-current' => $index === $current, 'is-done' => $index < $current])>
                <span class="wizard-step-number">@if ($index < $current)<i class="ri-check-line"></i>@else{{ $index + 1 }}@endif</span>{{ $label }}
            </li>
        @endforeach
    </ol>

    @if ($step === 'upload')
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-0 h-100">
                    <div class="card-body">
                        <h5 class="mb-1">{{ __('Upload a CSV file') }}</h5>
                        <p class="text-muted">{{ __('Save your spreadsheet as CSV (UTF-8). Up to :max rows per file. Nothing is saved until you confirm the import.', ['max' => RowImport::MAX_ROWS]) }}</p>

                        <form wire:submit="check" novalidate>
                            <label class="form-label" for="import-file">{{ __('CSV file') }}<span class="text-danger ms-1">*</span></label>
                            <input type="file" id="import-file" wire:model="file" accept=".csv,text/csv"
                                   @class(['form-control', 'is-invalid' => $errors->has('file')])>
                            @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div wire:loading wire:target="file" class="form-text"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Uploading…') }}</div>

                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="check,file">
                                    <span wire:loading.remove wire:target="check"><i class="ri-search-eye-line me-1"></i>{{ __('Check file') }}</span>
                                    <span wire:loading wire:target="check"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Checking…') }}</span>
                                </button>
                                <button type="button" class="btn btn-light" wire:click="downloadTemplate"><i class="ri-download-2-line me-1"></i>{{ __('Download template') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Columns') }}</h5></div>
                    <ul class="list-group list-group-flush fs-13">
                        @foreach ($columns as $key => [$label, $required])
                            <li class="list-group-item d-flex gap-2">
                                <code class="flex-shrink-0">{{ $key }}</code>
                                <span class="text-muted">{{ $label }}</span>
                                @if ($required)<span class="badge bg-danger-subtle text-danger ms-auto align-self-start">{{ __('Required') }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @elseif ($step === 'review')
        <div class="row g-4 mb-4">
            <div class="col-sm-4"><x-stat-card :label="__('Rows in :file', ['file' => Str::limit($summary['name'], 24)])" :value="number_format($summary['total'])" icon="ri-file-list-3-line" color="primary" /></div>
            <div class="col-sm-4"><x-stat-card :label="__('Ready to import')" :value="number_format($summary['valid'])" icon="ri-checkbox-circle-line" color="success" /></div>
            <div class="col-sm-4"><x-stat-card :label="__('With problems')" :value="number_format($summary['invalid'])" icon="ri-error-warning-line" color="danger" /></div>
        </div>

        @if ($problems)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ __('Rows with problems') }}</h5>
                    <small class="text-muted">{{ __('These rows are skipped. Fix them in the file and import them separately, or start over.') }}</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light bg-opacity-30">
                            <tr><th scope="col" class="ps-4" style="width: 5rem">{{ __('Line') }}</th><th scope="col">{{ __('Name') }}</th><th scope="col" class="pe-4">{{ __('Problems') }}</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($problems as $problem)
                                <tr>
                                    <td class="ps-4 text-muted">{{ $problem['line'] }}</td>
                                    <td class="fw-medium">{{ $problem['name'] ?: '—' }}</td>
                                    <td class="pe-4">
                                        <ul class="mb-0 ps-3 text-danger fs-13">
                                            @foreach ($problem['errors'] as $error)<li>{{ $error }}</li>@endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($preview)
            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('Preview') }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 text-nowrap">
                        <thead class="bg-light bg-opacity-30">
                            <tr>
                                <th scope="col" class="ps-4">{{ __('Line') }}</th>
                                @foreach (array_slice(array_keys($columns), 0, 7) as $key)<th scope="col">{{ $key }}</th>@endforeach
                                <th scope="col" class="pe-4">{{ __('Notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview as $row)
                                <tr>
                                    <td class="ps-4 text-muted">{{ $row['line'] }}</td>
                                    @foreach (array_slice(array_keys($columns), 0, 7) as $key)<td>{{ $row['values'][$key] ?? '' }}</td>@endforeach
                                    <td class="pe-4 text-info fs-13 text-wrap">{{ implode(' ', $row['notes']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($summary['valid'] > count($preview))
                    <div class="card-footer text-muted fs-13">{{ __('…and :count more.', ['count' => $summary['valid'] - count($preview)]) }}</div>
                @endif
            </div>
        @endif

        <div class="d-flex flex-wrap gap-2">
            @if ($summary['valid'] > 0)
                <button type="button" class="btn btn-primary" wire:click="import" wire:loading.attr="disabled" wire:target="import">
                    <span wire:loading.remove wire:target="import"><i class="ri-upload-cloud-2-line me-1"></i>{{ trans_choice('Import :count row|Import :count rows', $summary['valid'], ['count' => number_format($summary['valid'])]) }}</span>
                    <span wire:loading wire:target="import"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Importing…') }}</span>
                </button>
            @endif
            <button type="button" class="btn btn-light" wire:click="startOver">{{ __('Start over') }}</button>
        </div>
    @else
        <div class="card mb-0">
            <div class="card-body p-5 text-center">
                <span class="avatar-item avatar-xl avatar-title bg-success-subtle text-success rounded-circle fs-2 mx-auto mb-4"><i class="ri-checkbox-circle-line"></i></span>
                <h5 class="mb-1">{{ trans_choice(':count record imported|:count records imported', $imported, ['count' => number_format($imported)]) }}</h5>
                <p class="text-muted mx-auto mb-4" style="max-width: 32rem">
                    {{ __('Download the sign-in sheet now: it has the temporary password of every new account and is available for :minutes minutes. Keep it safe and hand each person their own details.', ['minutes' => App\Services\People\CredentialSheet::MINUTES]) }}
                </p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <button type="button" class="btn btn-primary" wire:click="downloadSheet"><i class="ri-download-2-line me-1"></i>{{ __('Download sign-in sheet') }}</button>
                    <button type="button" class="btn btn-light" wire:click="startOver"><i class="ri-file-upload-line me-1"></i>{{ __('Import another file') }}</button>
                    @if ($doneUrl)
                        <a href="{{ $doneUrl }}" wire:navigate class="btn btn-link">{{ __('Done') }}</a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
