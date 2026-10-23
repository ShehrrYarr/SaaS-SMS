<?php

namespace App\Services\Timetable;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\SubjectClass;
use App\Models\Timetable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Weekly timetables of the current academic year: one per class/section,
 * built from its subject allocations and the bell schedule, with clash
 * checks for teachers and rooms. Teachers and students read their week from
 * the same slots.
 */
class Timetables
{
    public function __construct(private readonly BellSchedule $bells) {}

    public function year(): ?AcademicYear
    {
        return AcademicYear::query()->current()->first();
    }

    /**
     * Allocations a class/section's slots can use: its own, and the ones
     * covering every section of the class.
     *
     * @return Collection<int, SubjectClass>
     */
    public function allocationsFor(SchoolClass $class, ?Section $section, AcademicYear $year): Collection
    {
        return SubjectClass::query()
            ->with(['subject', 'teacher.user'])
            ->where('academic_year_id', $year->id)
            ->where('class_id', $class->id)
            ->where(fn (Builder $query) => $query->whereNull('section_id')->when($section, fn (Builder $query) => $query->orWhere('section_id', $section->id)))
            ->get()
            ->sortBy(fn (SubjectClass $allocation) => mb_strtolower((string) $allocation->subject?->name))
            ->values();
    }

    /**
     * A class/section's week, keyed "day-period".
     *
     * @return Collection<string, Timetable>
     */
    public function grid(SchoolClass $class, ?Section $section, AcademicYear $year): Collection
    {
        return $this->withDetails(Timetable::query()->where('academic_year_id', $year->id)->forGroup($class->id, $section?->id))
            ->get()
            ->keyBy(fn (Timetable $slot) => self::cell($slot->day_of_week, $slot->period_number));
    }

    /**
     * A teacher's week: every slot of an allocation they teach.
     *
     * @return Collection<string, Collection<int, Timetable>>
     */
    public function forTeacher(StaffProfile $teacher, ?AcademicYear $year = null): Collection
    {
        $year ??= $this->year();

        if (! $year) {
            return collect();
        }

        return $this->withDetails(Timetable::query()
            ->where('academic_year_id', $year->id)
            ->whereHas('subjectClass', fn (Builder $allocation) => $allocation->where('teacher_id', $teacher->id)))
            ->get()
            ->groupBy(fn (Timetable $slot) => self::cell($slot->day_of_week, $slot->period_number));
    }

    /**
     * A student's week: their class/section's slots.
     *
     * @return Collection<string, Timetable>
     */
    public function forStudent(StudentProfile $student, ?AcademicYear $year = null): Collection
    {
        $year ??= $this->year();

        if (! $year || ! $student->class_id) {
            return collect();
        }

        return $this->withDetails(Timetable::query()->where('academic_year_id', $year->id)->forGroup($student->class_id, $student->section_id))
            ->get()
            ->keyBy(fn (Timetable $slot) => self::cell($slot->day_of_week, $slot->period_number));
    }

    /**
     * Slots of one weekday, in time order.
     *
     * @param  Collection<string, Timetable|Collection<int, Timetable>>  $week
     * @return Collection<int, Timetable>
     */
    public static function day(Collection $week, int $isoDay): Collection
    {
        return $week->flatten(1)
            ->filter(fn (Timetable $slot) => $slot->day_of_week === $isoDay)
            ->sortBy('start_time')
            ->values();
    }

    /**
     * Put an allocation in a cell of a class/section's week, replacing what
     * was there. Throws a ValidationException (keys allocation / classroom)
     * when the teacher or room is already busy then.
     */
    public function assign(SchoolClass $class, ?Section $section, int $day, int $period, int $allocationId, ?int $classroomId): Timetable
    {
        $year = $this->year();

        throw_unless($year, ValidationException::withMessages(['allocation' => __('Set a current academic year first.')]));

        $times = $this->bells->period($period);

        throw_unless($times, ValidationException::withMessages(['allocation' => __('That period is not part of the school day.')]));
        throw_unless(in_array($day, $this->bells->days(), true), ValidationException::withMessages(['allocation' => __('The school is closed that day.')]));

        $allocation = $this->allocationsFor($class, $section, $year)->firstWhere('id', $allocationId);

        throw_unless($allocation, ValidationException::withMessages(['allocation' => __('Pick a subject taught to this class.')]));

        $classroom = $classroomId ? Classroom::query()->find($classroomId) : null;

        throw_if($classroomId && ! $classroom, ValidationException::withMessages(['classroom' => __('Pick a classroom from the list.')]));

        return DB::transaction(function () use ($class, $section, $day, $period, $allocation, $classroom, $year, $times) {
            $existing = Timetable::query()->where('academic_year_id', $year->id)->forGroup($class->id, $section?->id)
                ->where('day_of_week', $day)->where('period_number', $period)->lockForUpdate()->first();

            $clashes = $this->clashes($year, $day, $times['start'], $times['end'], $allocation, $classroom, $existing);

            if ($clashes !== []) {
                throw ValidationException::withMessages($clashes);
            }

            $slot = $existing ?? new Timetable([
                'academic_year_id' => $year->id,
                'class_id' => $class->id,
                'section_id' => $section?->id,
                'day_of_week' => $day,
                'period_number' => $period,
            ]);

            $slot->fill([
                'subject_class_id' => $allocation->id,
                'classroom_id' => $classroom?->id,
                'start_time' => $times['start'].':00',
                'end_time' => $times['end'].':00',
            ])->save();

            activity('timetable')->performedOn($slot)->causedBy(auth()->user())
                ->withProperties([
                    'group' => $class->name.($section ? ' · '.$section->name : ''),
                    'day' => BellSchedule::dayName($day),
                    'period' => $period,
                    'subject' => $allocation->subject?->name,
                    'teacher' => $allocation->teacher?->user?->name,
                    'classroom' => $classroom?->label(),
                ])
                ->log($existing ? 'Timetable slot changed' : 'Timetable slot added');

            return $slot;
        });
    }

    public function clear(Timetable $slot): void
    {
        $slot->loadMissing(['schoolClass', 'section', 'subjectClass.subject']);

        activity('timetable')->performedOn($slot)->causedBy(auth()->user())
            ->withProperties([
                'group' => $slot->schoolClass?->name.($slot->section ? ' · '.$slot->section->name : ''),
                'day' => BellSchedule::dayName($slot->day_of_week),
                'period' => $slot->period_number,
                'subject' => $slot->subjectClass?->subject?->name,
            ])
            ->log('Timetable slot cleared');

        $slot->delete();
    }

    /**
     * Why an allocation can't go in a slot: its teacher or the room is
     * already booked at an overlapping time that day.
     *
     * @return array<string, string> field => message
     */
    public function clashes(AcademicYear $year, int $day, string $start, string $end, SubjectClass $allocation, ?Classroom $classroom, ?Timetable $ignore = null): array
    {
        $overlapping = fn () => Timetable::query()
            ->with(['schoolClass', 'section', 'subjectClass.subject'])
            ->where('academic_year_id', $year->id)
            ->where('day_of_week', $day)
            ->where('start_time', '<', $end.':00')
            ->where('end_time', '>', $start.':00')
            ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->id));

        $describe = fn (Timetable $slot) => trim($slot->schoolClass?->name.($slot->section ? ' · '.$slot->section->name : '')).' ('.$slot->subjectClass?->subject?->name.')';
        $clashes = [];

        if ($allocation->teacher_id) {
            $busy = $overlapping()->whereHas('subjectClass', fn (Builder $query) => $query->where('teacher_id', $allocation->teacher_id))->first();

            if ($busy) {
                $clashes['allocation'] = __(':teacher is already teaching :group then.', [
                    'teacher' => $allocation->teacher?->user?->name ?? __('The teacher'),
                    'group' => $describe($busy),
                ]);
            }
        }

        if ($classroom) {
            $busy = $overlapping()->where('classroom_id', $classroom->id)->first();

            if ($busy) {
                $clashes['classroom'] = __(':room is already in use by :group then.', ['room' => $classroom->label(), 'group' => $describe($busy)]);
            }
        }

        return $clashes;
    }

    public static function cell(int $day, int $period): string
    {
        return $day.'-'.$period;
    }

    /**
     * @param  Builder<Timetable>  $query
     * @return Builder<Timetable>
     */
    private function withDetails(Builder $query): Builder
    {
        return $query->with(['subjectClass.subject', 'subjectClass.teacher.user', 'classroom', 'schoolClass', 'section']);
    }
}
