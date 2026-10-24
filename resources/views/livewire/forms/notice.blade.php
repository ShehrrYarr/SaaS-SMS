<?php

/*
 * Write or edit a notice.
 *     $dispatch('open-modal', { component: 'forms.notice', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.notice', params: { notice: 5 }, size: 'lg' })
 *
 * Dates are entered in the school's timezone and stored in UTC.
 */

use App\Enums\NoticeAudience;
use App\Enums\NoticeState;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Notice;
use App\Support\Uploads;
use App\Models\SchoolClass;
use App\Services\Notices\NoticePublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, mount, protect, rules, state, uses};

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['notice' => null, 'currentState' => null, 'currentAttachment' => null])->locked();

state([
    'title' => '', 'body' => '', 'target_audience' => NoticeAudience::All->value, 'class_id' => '', 'is_pinned' => false,
    'publish' => 'now', // now | schedule | draft
    'publish_at' => '', 'expires_at' => '',
    'attachment' => null, 'remove_attachment' => false,
]);

$classes = computed(fn () => SchoolClass::query()->ordered()->pluck('name', 'id')->all());

$timezone = protect(fn () => tenant()->setting('timezone') ?: config('app.timezone'));

$toLocalInput = protect(fn (?Carbon $time) => $time ? $time->copy()->setTimezone($this->timezone())->format('Y-m-d\TH:i') : '');

$fromLocalInput = protect(fn (?string $value) => filled($value) ? Carbon::parse($value, $this->timezone())->utc() : null);

mount(function (?int $notice = null) {
    if (! $notice) {
        $this->authorize('notices.create');

        return;
    }

    $this->authorize('notices.edit');
    $record = Notice::query()->findOrFail($notice);

    $this->notice = $record->id;
    $this->currentState = $record->state()->value;
    $this->currentAttachment = $record->attachmentName();
    $this->fill([
        'title' => $record->title,
        'body' => $record->body,
        'target_audience' => $record->target_audience->value,
        'class_id' => (string) ($record->class_id ?? ''),
        'is_pinned' => $record->is_pinned,
        'publish' => match ($record->state()) {
            NoticeState::Draft => 'draft',
            NoticeState::Scheduled => 'schedule',
            default => 'keep',
        },
        'publish_at' => $this->toLocalInput($record->published_at),
        'expires_at' => $this->toLocalInput($record->expires_at),
    ]);
});

rules(fn () => [
    'title' => ['required', 'string', 'max:255'],
    'body' => ['required', 'string', 'max:20000'],
    'target_audience' => ['required', Rule::enum(NoticeAudience::class)],
    'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'is_pinned' => ['boolean'],
    'publish' => ['required', Rule::in($this->notice && in_array($this->currentState, ['live', 'expired'], true) ? ['keep', 'draft'] : ['now', 'schedule', 'draft'])],
    'publish_at' => ['exclude_unless:publish,schedule', 'required', 'date', 'after:now'],
    'expires_at' => ['nullable', 'date', 'after:'.($this->publish === 'schedule' && $this->publish_at ? 'publish_at' : 'now')],
    'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,webp'],
    'remove_attachment' => ['boolean'],
])->attributes([
    'target_audience' => __('audience'),
    'class_id' => __('class'),
    'publish_at' => __('publish time'),
    'expires_at' => __('expiry time'),
]);

$save = function (NoticePublisher $publisher) {
    $this->authorize($this->notice ? 'notices.edit' : 'notices.create');

    // Times are typed in the school's timezone; validate them as such.
    $data = $this->withValidator(function ($validator) {
        $validator->setData([
            ...$validator->getData(),
            'publish_at' => $this->fromLocalInput($this->publish_at)?->toDateTimeString() ?? '',
            'expires_at' => $this->fromLocalInput($this->expires_at)?->toDateTimeString() ?? '',
        ]);
    })->validate();

    $narrowed = in_array($data['target_audience'], [NoticeAudience::Students->value, NoticeAudience::Parents->value], true);

    $notice = DB::transaction(function () use ($data, $narrowed) {
        $attributes = [
            'title' => $data['title'],
            'body' => $data['body'],
            'target_audience' => $data['target_audience'],
            'class_id' => $narrowed && $data['class_id'] ? $data['class_id'] : null,
            'is_pinned' => $data['is_pinned'],
            'expires_at' => $this->fromLocalInput($this->expires_at),
        ];

        if ($this->notice) {
            $notice = tap(Notice::query()->findOrFail($this->notice))->update($attributes);
        } else {
            $notice = Notice::query()->create($attributes + ['created_by' => auth()->id()]);
        }

        if ($data['remove_attachment'] || $this->attachment) {
            if ($notice->attachment) {
                Storage::disk(Notice::DISK)->delete($notice->attachment);
            }

            $path = null;

            if ($this->attachment) {
                $name = Str::slug(pathinfo($this->attachment->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'attachment';
                $path = Uploads::store($this->attachment, "schools/{$notice->school_id}/notices/{$notice->id}", $name, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', ...Uploads::IMAGES], Notice::DISK);
            }

            $notice->update(['attachment' => $path]);
        }

        return $notice;
    });

    match ($data['publish']) {
        'now' => $publisher->publish($notice),
        'schedule' => $publisher->publish($notice, $this->fromLocalInput($this->publish_at)),
        'draft' => $notice->published_at ? $publisher->unpublish($notice) : null,
        default => null,
    };

    $this->closeModal();
    $this->refreshTables();
    $this->toast(match ($data['publish']) {
        'now' => __('Notice published.'),
        'schedule' => __('Notice scheduled.'),
        'draft' => __('Saved as a draft.'),
        default => __('Notice updated.'),
    });
};

?>

<x-modal.form :title="$notice ? __('Edit notice') : __('New notice')">
    <x-form.input name="title" :label="__('Title')" required maxlength="255" />

    <x-form.textarea name="body" :label="__('Message')" rows="7" maxlength="20000" required
                     :hint="__('Plain text. Line breaks are kept.')" />

    <div class="row">
        <div class="col-sm-6">
            <x-form.select name="target_audience" :label="__('Who is it for?')" :options="NoticeAudience::options()" :placeholder="false" required live />
        </div>
        @if (in_array($target_audience, [NoticeAudience::Students->value, NoticeAudience::Parents->value], true))
            <div class="col-sm-6">
                <x-form.select name="class_id" :label="__('Class')" :options="$this->classes" :placeholder="__('Every class')" />
            </div>
        @endif
    </div>

    <div class="mb-4">
        <label class="form-label" for="field-attachment">{{ __('Attachment') }}</label>
        @if ($currentAttachment && ! $remove_attachment && ! $attachment)
            <div class="d-flex align-items-center gap-2 mb-2 fs-13">
                <i class="ri-attachment-2 text-muted"></i><span>{{ $currentAttachment }}</span>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" wire:click="$set('remove_attachment', true)">{{ __('Remove') }}</button>
            </div>
        @endif
        <input type="file" id="field-attachment" wire:model="attachment"
               @class(['form-control', 'is-invalid' => $errors->has('attachment')]) accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png,.webp">
        <div wire:loading wire:target="attachment" class="form-text"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Uploading…') }}</div>
        @error('attachment') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('Optional. PDF, Office documents or images up to 10 MB.') }}</div>
    </div>

    <fieldset class="mb-3">
        <legend class="form-label fs-14">{{ __('Publishing') }}</legend>
        <div class="d-flex flex-wrap gap-3">
            @if (in_array($currentState, ['live', 'expired'], true))
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="publish-keep" value="keep" wire:model.live="publish">
                    <label class="form-check-label" for="publish-keep">{{ __('Keep it published') }}</label>
                </div>
            @else
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="publish-now" value="now" wire:model.live="publish">
                    <label class="form-check-label" for="publish-now">{{ __('Publish now') }}</label>
                </div>
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="publish-schedule" value="schedule" wire:model.live="publish">
                    <label class="form-check-label" for="publish-schedule">{{ __('Schedule') }}</label>
                </div>
            @endif
            <div class="form-check">
                <input type="radio" class="form-check-input" id="publish-draft" value="draft" wire:model.live="publish">
                <label class="form-check-label" for="publish-draft">{{ $currentState && $currentState !== 'draft' ? __('Unpublish (draft)') : __('Save as draft') }}</label>
            </div>
        </div>
        @error('publish') <div class="text-danger fs-13 mt-1">{{ $message }}</div> @enderror
    </fieldset>

    <div class="row">
        @if ($publish === 'schedule')
            <div class="col-sm-6"><x-form.input name="publish_at" type="datetime-local" :label="__('Publish on')" required /></div>
        @endif
        <div class="col-sm-6"><x-form.input name="expires_at" type="datetime-local" :label="__('Take down on')"
                                            :hint="__('Optional. Times are in :zone.', ['zone' => tenant()->setting('timezone')])" /></div>
    </div>

    <x-form.switch name="is_pinned" :label="__('Pin to the top of the notice board')" wrapper="mb-0" />
</x-modal.form>
