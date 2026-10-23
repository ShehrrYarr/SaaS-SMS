<?php

/*
 * The class teacher's overall report-card remark for each student, with
 * their result so far alongside.
 *     <livewire:exams.remarks-sheet :examination="3" group="r-4-12" :key="..." />
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Examination;
use App\Services\Exams\Gradebook;
use App\Services\Exams\ResultSheet;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, protect, state, uses};

uses([InteractsWithUi::class]);

state(['examination' => null, 'group' => null])->locked();

// student id => remark
state(['remarks' => []]);

$gradebook = protect(fn (): Gradebook => app(Gradebook::class));

$exam = computed(fn () => Examination::query()->findOrFail($this->examination));

$info = computed(fn () => $this->gradebook()->remarkGroupsFor(auth()->user(), $this->exam)->get($this->group));

$results = computed(fn () => app(ResultSheet::class)->forClass($this->exam, $this->info['class'], $this->info['section'])['rows']
    ->keyBy(fn (array $row) => $row['student']->id));

$roster = computed(fn () => $this->gradebook()->roster($this->exam, $this->info['class'], $this->info['section']));

mount(function (int $examination, string $group) {
    $this->examination = $examination;
    $this->group = $group;

    abort_unless($this->info, 403);

    $this->remarks = $this->gradebook()->remarks($this->exam, $this->roster);
});

$save = function () {
    abort_unless($this->info && ! $this->exam->isPublished(), 403);

    $this->validate(['remarks.*' => ['nullable', 'string', 'max:1000']]);

    try {
        $this->gradebook()->saveRemarks($this->exam, $this->info['class'], $this->info['section'], $this->remarks, auth()->user());
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $this->toast(__('Report card remarks saved.'));
};

?>

<div class="card mb-0">
    <div class="card-header">
        <h5 class="card-title mb-0">{{ __('Report card remarks') }} · {{ $this->info['label'] }}</h5>
        <small class="text-muted">{{ __('Your overall comment on each student, printed on their report card.') }}</small>
    </div>

    @if ($this->roster->isEmpty())
        <x-empty-state icon="ri-group-line" :title="__('No students in this section')" />
    @else
        <form wire:submit="save">
            <fieldset @disabled($this->exam->isPublished())>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <tbody>
                            @foreach ($this->roster as $enrollment)
                                @php($id = $enrollment->student_profile_id)
                                @php($result = $this->results->get($id))
                                <tr wire:key="remark-{{ $id }}">
                                    <td class="ps-4" style="width: 16rem">
                                        <span class="fw-medium">{{ $enrollment->student?->user?->name }}</span>
                                        <small class="d-block text-muted">
                                            @if ($result && $result['percentage'] !== null)
                                                {{ $result['percentage'] }}%{{ $result['grade'] ? ' · '.$result['grade'] : '' }}{{ $result['rank'] ? ' · '.__('Position :rank', ['rank' => $result['rank']]) : '' }}
                                            @else
                                                {{ __('No marks yet') }}
                                            @endif
                                        </small>
                                    </td>
                                    <td class="pe-4">
                                        <textarea rows="2" maxlength="1000" class="form-control form-control-sm" wire:model="remarks.{{ $id }}"
                                                  aria-label="{{ __('Remark for :name', ['name' => $enrollment->student?->user?->name]) }}"
                                                  placeholder="{{ __('e.g. Works hard and participates well in class.') }}"></textarea>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @unless ($this->exam->isPublished())
                    <div class="card-footer d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <i class="ri-save-line me-1"></i>{{ __('Save remarks') }}
                        </button>
                    </div>
                @endunless
            </fieldset>
        </form>
    @endif
</div>
