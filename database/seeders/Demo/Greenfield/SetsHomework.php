<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\HomeworkStatus;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Homework\Homeworks;
use App\Tables\ActionFailed;
use Carbon\CarbonImmutable;

/**
 * Homework for every section from Grade 1: one marked and closed, one being
 * marked now, one still open; plus a couple of drafts. Students hand work
 * in themselves; teachers grade, give feedback and send some back.
 */
trait SetsHomework
{
    /** subject => [junior titles, senior titles] */
    private const HOMEWORK = [
        'ENG' => [
            ['Write five sentences about your family', 'Phonics worksheet: long vowel sounds', 'Learn the spellings in the Unit 4 word list'],
            ['Comprehension: answer questions 1 to 6 on "The Last Leaf"', 'Write a letter to a friend about your summer holidays', 'Grammar: active and passive voice, exercise 7B'],
        ],
        'MATH' => [
            ['Addition with carrying: worksheet 3', 'Times tables: practise the table of 4', 'Shapes around the house: find and draw five shapes'],
            ['Fractions: exercise 4.2, questions 1 to 10', 'Word problems on percentages (page 58)', 'Angles: measure and classify the angles on worksheet 6'],
        ],
        'SCI' => [
            ['Label the parts of a plant', 'Living and non-living things: cut and paste activity', 'Draw the water cycle'],
            ['Write up the germination experiment', 'States of matter: end-of-chapter questions', 'Food chains: draw two food chains from a pond'],
        ],
    ];

    private const ANSWERS = [
        'I have done all the questions in my notebook.',
        'Completed. Please see my answers below.',
        'Done! The last question was a bit hard but I tried.',
        'I finished the worksheet and checked my answers with the example.',
        'Here is my work. I have written it neatly as you said.',
    ];

    /** Teachers' comments: any subject, then by subject. */
    private const FEEDBACK = [
        'all' => ['Well done!', 'Neat and careful work.', 'Excellent!', 'Good effort.', null, null, null],
        'ENG' => ['Mind your spellings.', 'Use full stops and capital letters.', 'Lovely handwriting.'],
        'MATH' => ['Show your working next time.', 'Check question 4 again.', 'Careful with carrying over.'],
        'SCI' => ['Label your diagrams.', 'Good observations.', 'Write the answer in full sentences.'],
    ];

    private function planHomework(): void
    {
        $sections = array_filter(array_keys(self::TEACHING), fn (string $key) => ! str_starts_with($key, 'PREP'));
        $subjects = array_keys(self::HOMEWORK);

        foreach (array_values($sections) as $i => $section) {
            $senior = self::CLASSES[strstr($section, '-', true)][2] >= 3 ? 1 : 0;

            foreach ([['marked', -17, 5], ['marking', -9, 7], ['open', -1, 5]] as $j => [$stage, $from, $length]) {
                $code = $subjects[($i + $j) % count($subjects)];
                $assigned = $this->addSchoolDays($this->today, $from);
                $due = $this->addSchoolDays($assigned, $length);

                if ($assigned->lte($this->yearStart)) {
                    continue;
                }

                $this->at($this->on($assigned, '14:'.$this->dice->int(10, 50)), fn () => $this->setHomework(
                    $section, $code, self::HOMEWORK[$code][$senior][$j], $assigned, $due, $stage,
                ));
            }
        }

        // Drafts the teachers are still working on.
        $drafts = [['G3-A', 'MATH', 'Fractions revision worksheet'], ['G7-A', 'SCI', 'Research a famous Muslim scientist and write one page about them']];

        foreach ($drafts as [$section, $code, $title]) {
            $day = $this->addSchoolDays($this->today, -1);

            $this->at($this->on($day, '16:20'), fn () => $this->as($this->people[self::TEACHING[$section][$code]], fn () => Homework::query()->create([
                'subject_class_id' => $this->allocations[$section.':'.$code]->id,
                'section_id' => $this->sections[$section]->id,
                'title' => $title,
                'description' => 'To be set after this week\'s lessons.',
                'assigned_on' => $this->addSchoolDays($this->today, 2)->toDateString(),
                'due_at' => $this->on($this->addSchoolDays($this->today, 6), '23:59')->utc(),
                'max_marks' => 10,
                'status' => HomeworkStatus::Draft,
                'created_by' => auth()->id(),
            ])));
        }
    }

    private function setHomework(string $section, string $code, string $title, CarbonImmutable $assigned, CarbonImmutable $due, string $stage): void
    {
        $teacher = $this->people[self::TEACHING[$section][$code]];
        $service = app(Homeworks::class);

        $homework = $this->as($teacher, function () use ($section, $code, $title, $assigned, $due, $service) {
            $homework = Homework::query()->create([
                'subject_class_id' => $this->allocations[$section.':'.$code]->id,
                'section_id' => $this->sections[$section]->id,
                'title' => $title,
                'description' => 'Do this in your homework notebook and hand it in by the due date.',
                'assigned_on' => $assigned->toDateString(),
                'due_at' => $this->on($due, '23:59')->utc(),
                'max_marks' => 10,
                'status' => HomeworkStatus::Draft,
                'created_by' => auth()->id(),
            ]);

            $service->publish($homework);

            return $homework;
        });

        $handIn = ['marked' => 92, 'marking' => 85, 'open' => 45][$stage];
        $graded = [];

        foreach ($service->roster($homework) as $student) {
            if (! $this->dice->chance($handIn)) {
                continue;
            }

            $late = $stage !== 'open' && $this->dice->chance(8);
            $day = $late ? $this->addSchoolDays($due, 1) : $this->addSchoolDays($assigned, $this->dice->int(1, max(1, $assigned->diffInWeekdays($due))));
            $when = $this->on($day, sprintf('%02d:%02d', $this->dice->int(16, 21), $this->dice->int(0, 59)));

            if ($when->gt($this->on($due, '23:59')) && ! $late) {
                $when = $this->on($due, '19:00');
            }

            $this->at($when, fn () => $this->handIn($homework, $student));
            $graded[] = $student;
        }

        if ($stage === 'open') {
            return;
        }

        // Marking: all of it for the closed homework, about half so far for the other.
        foreach ($graded as $student) {
            if ($stage === 'marking' && ! $this->dice->chance(50)) {
                continue;
            }

            $when = $this->on($this->addSchoolDays($due, $stage === 'marked' ? $this->dice->int(1, 3) : 1), sprintf('%02d:%02d', $this->dice->int(13, 18), $this->dice->int(0, 59)));

            $this->at($when, fn () => $this->gradeHomework($homework, $student, $teacher, $code, sendBack: $stage === 'marking' && $this->dice->chance(6)));
        }

        if ($stage === 'marked') {
            $this->at($this->on($this->addSchoolDays($due, 4), '08:30'), fn () => $service->close($homework->refresh()));
        }
    }

    private function handIn(Homework $homework, StudentProfile $student): void
    {
        $user = $student->user;

        if (! $user || $student->refresh()->status->value !== 'active') {
            return;
        }

        try {
            $this->as($user, fn () => app(Homeworks::class)->submit($homework->refresh(), $student, $this->dice->pick(self::ANSWERS), null));
        } catch (ActionFailed) {
            // Closed or already graded.
        }
    }

    private function gradeHomework(Homework $homework, StudentProfile $student, User $teacher, string $code, bool $sendBack = false): void
    {
        $service = app(Homeworks::class);
        $submission = HomeworkSubmission::query()->where('homework_id', $homework->id)->where('student_profile_id', $student->id)->first();

        if (! $submission) {
            return;
        }

        try {
            if ($sendBack) {
                $service->sendBack($submission, 'Please redo questions 3 and 4 and show your working.', $teacher);

                return;
            }

            $ability = $this->pupils[$student->id]['ability'] ?? 0.7;
            $marks = max(2, min(10, round(($ability + $this->dice->normal(0, 0.1)) * 10 * 2) / 2));

            $this->as($teacher, fn () => $service->grade($homework->refresh(), $student, $marks, $this->dice->pick([...self::FEEDBACK['all'], ...self::FEEDBACK[$code]]), $teacher));
        } catch (ActionFailed) {
            // Nothing to grade.
        }
    }
}
