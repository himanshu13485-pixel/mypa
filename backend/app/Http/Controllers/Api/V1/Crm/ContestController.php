<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Contest;
use App\Models\Crm\ContestAnswer;
use App\Models\Crm\ContestQuestion;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contests: the old CRM's timed quizzes, upgraded. A contest owns its
 * questions; every member answers once per question inside the window;
 * option answers grade themselves, free-text grades against the model
 * answer (or waits for a human). Results stay sealed until the end so
 * nobody plays with the leaderboard open.
 */
class ContestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);

        $contests = Contest::withCount('questions')
            ->where('organization_id', $org->id)
            ->when(! $manages, fn ($q) => $q->where('status', '!=', 'draft')
                // An aimed contest reaches only its audience: everyone by
                // default, one department, or one named person.
                ->where(fn ($w) => $w
                    ->where(fn ($x) => $x->whereNull('audience_department')->whereNull('audience_member_id'))
                    ->orWhere('audience_member_id', $me->id)
                    ->when($me->department, fn ($d) => $d->orWhere('audience_department', $me->department))))
            ->orderByDesc('starts_at')
            ->paginate(20);

        // My progress per contest, one query.
        $answered = ContestAnswer::where('member_id', $me->id)
            ->whereIn('question_id', ContestQuestion::whereIn(
                'contest_id', $contests->getCollection()->pluck('id'))->select('id'))
            ->with('question:id,contest_id')
            ->get()
            ->groupBy(fn ($a) => $a->question->contest_id);

        $contests->getCollection()->transform(fn (Contest $c) => [
            'uuid' => $c->uuid,
            'title' => $c->title,
            'starts_at' => $c->starts_at->toDateTimeString(),
            'ends_at' => $c->ends_at->toDateTimeString(),
            'status' => $c->status,
            'phase' => $c->phase(),
            'audience' => $c->audience_member_id
                ? 'For: ' . (\App\Models\Crm\Member::find($c->audience_member_id)?->user?->name ?? 'one person')
                : ($c->audience_department ? 'For: ' . $c->audience_department : 'For all'),
            'questions' => $c->questions_count,
            'my_answers' => ($answered[$c->id] ?? collect())->count(),
            'my_points' => $c->phase() === 'ended' ? ($answered[$c->id] ?? collect())->sum('points_awarded') : null,
        ]);

        return response()->json($contests);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        [$data, $questions] = $this->validatePayload($request);

        $contest = DB::transaction(function () use ($org, $data, $questions, $request) {
            $contest = Contest::create($data + [
                'organization_id' => $org->id,
                'created_by' => $request->user()->id,
            ]);
            $this->syncQuestions($contest, $questions);

            return $contest;
        });

        return response()->json(['message' => 'Contest created.', 'data' => ['uuid' => $contest->uuid]], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $contest = $this->find($request, $uuid);
        [$data, $questions] = $this->validatePayload($request);

        if ($contest->questions()->whereHas('answers')->exists() && $questions !== null) {
            abort(422, 'Answers are already in; the questions can no longer be changed.');
        }

        DB::transaction(function () use ($contest, $data, $questions) {
            $contest->update($data);
            if ($questions !== null) {
                $this->syncQuestions($contest, $questions);
            }
        });

        return response()->json(['message' => 'Contest saved.']);
    }

    /**
     * Replicate: a finished contest reborn as a fresh DRAFT with the same
     * questions — dates and audience re-chosen before publishing, answers
     * left behind. The way a good quiz reaches the next batch.
     */
    public function replicate(Request $request, string $uuid): JsonResponse
    {
        $contest = $this->find($request, $uuid);
        $contest->load('questions');

        $copy = DB::transaction(function () use ($contest, $request) {
            $copy = Contest::create([
                'organization_id' => $contest->organization_id,
                'title' => 'Copy of ' . $contest->title,
                'description' => $contest->description,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHours(max(1, (int) $contest->starts_at->diffInHours($contest->ends_at))),
                'status' => 'draft',
                'audience_department' => $contest->audience_department,
                'audience_member_id' => $contest->audience_member_id,
                'created_by' => $request->user()->id,
            ]);
            foreach ($contest->questions as $q) {
                $copy->questions()->create($q->only([
                    'type', 'question', 'options', 'correct_option', 'correct_text', 'points', 'sort',
                ]));
            }

            return $copy;
        });

        return response()->json([
            'message' => 'Replicated as a draft — set the dates and audience, then publish.',
            'data' => ['uuid' => $copy->uuid],
        ], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $contest = $this->find($request, $uuid);
        $contest->delete();

        return response()->json(['message' => 'Contest deleted.']);
    }

    /**
     * The player's (or editor's) view. Correct answers and per-question
     * results stay hidden until the contest has ended — except for editors.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $contest = Contest::with('questions')
            ->where('organization_id', $org->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        if ($contest->status === 'draft' && ! $manages) {
            abort(404);
        }
        if (! $manages && ! $contest->isFor($me)) {
            abort(404);
        }

        $phase = $contest->phase();
        $revealed = $phase === 'ended' || $manages;
        // An upcoming contest stays sealed: players see the card — title,
        // window, question count — but not one question before the start.
        $sealed = ! $manages && $phase === 'upcoming';

        $mine = ContestAnswer::where('member_id', $me->id)
            ->whereIn('question_id', $contest->questions->pluck('id'))
            ->get()
            ->keyBy('question_id');

        return response()->json(['data' => [
            'uuid' => $contest->uuid,
            'title' => $contest->title,
            'audience_department' => $contest->audience_department,
            'audience_member_uuid' => $contest->audience_member_id
                ? Member::find($contest->audience_member_id)?->uuid
                : null,
            'description' => $contest->description,
            'starts_at' => $contest->starts_at->toDateTimeString(),
            'ends_at' => $contest->ends_at->toDateTimeString(),
            'status' => $contest->status,
            'phase' => $phase,
            'manages' => $manages,
            'sealed' => $sealed,
            'question_count' => $contest->questions->count(),
            'questions' => $sealed ? [] : $contest->questions->map(fn (ContestQuestion $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'question' => $q->question,
                'options' => $q->options,
                'points' => $q->points,
                'correct_option' => $revealed ? $q->correct_option : null,
                'correct_text' => $revealed ? $q->correct_text : null,
                'my_answer' => ($a = $mine[$q->id] ?? null) ? [
                    'answer_option' => $a->answer_option,
                    'answer_text' => $a->answer_text,
                    'is_correct' => $revealed ? $a->is_correct : null,
                    'points_awarded' => $revealed ? $a->points_awarded : null,
                ] : null,
            ]),
        ]]);
    }

    /** Answer one question — inside the window, once, no edits. */
    public function answer(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $contest = Contest::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();
        if ($contest->phase() !== 'live') {
            abort(422, 'This contest is not open for answers right now.');
        }
        // The Admin runs the quiz; they never play it. Same for a Super
        // Admin wearing the company hat — participation is for employees.
        if ($me->crm_role === 'admin' || $me->is_oversight) {
            abort(422, 'The Company Admin runs contests and does not participate.');
        }

        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'answer_option' => ['nullable', 'integer', 'min:0', 'max:9'],
            'answer_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $question = $contest->questions()->whereKey($data['question_id'])->firstOrFail();

        if ($question->type === 'option' && $data['answer_option'] === null) {
            abort(422, 'Pick one of the options.');
        }
        if ($question->type === 'text' && ! filled($data['answer_text'])) {
            abort(422, 'Write an answer.');
        }
        if (ContestAnswer::where('question_id', $question->id)->where('member_id', $me->id)->exists()) {
            abort(422, 'You already answered this question.');
        }

        $isCorrect = $question->grade($data['answer_option'] ?? null, $data['answer_text'] ?? null);

        ContestAnswer::create([
            'question_id' => $question->id,
            'member_id' => $me->id,
            'answer_option' => $question->type === 'option' ? $data['answer_option'] : null,
            'answer_text' => $question->type === 'text' ? $data['answer_text'] : null,
            'is_correct' => $isCorrect,
            'points_awarded' => $isCorrect === true ? $question->points : 0,
        ]);

        // Deliberately no correctness in the reply: the reveal comes at the end.
        return response()->json(['message' => 'Answer locked in.'], 201);
    }

    /** Leaderboard — everyone after the end, editors any time. */
    public function results(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $contest = Contest::with('questions:id,contest_id,points')
            ->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        if ($contest->phase() !== 'ended' && ! $manages) {
            abort(422, 'Results open when the contest ends.');
        }

        $questionIds = $contest->questions->pluck('id');

        $board = ContestAnswer::with('member.user:id,name')
            ->whereIn('question_id', $questionIds)
            ->get()
            // The Admin (or a Super Admin in oversight) never ranks — any
            // answers from before this rule stay off the board too.
            ->filter(fn ($a) => $a->member
                && $a->member->crm_role !== 'admin'
                && ! $a->member->is_oversight)
            ->groupBy('member_id')
            ->map(fn ($answers) => [
                'member_uuid' => $answers->first()->member?->uuid,
                'name' => $answers->first()->member?->user?->name,
                'answered' => $answers->count(),
                'correct' => $answers->where('is_correct', true)->count(),
                'pending' => $answers->whereNull('is_correct')->count(),
                'points' => $answers->sum('points_awarded'),
                'last_answer_at' => $answers->max('created_at')?->toDateTimeString(),
            ])
            // Points first; the earlier finisher wins a tie, like a real quiz.
            ->sortBy([['points', 'desc'], ['last_answer_at', 'asc']])
            ->values()
            ->map(fn ($row, $i) => $row + ['rank' => $i + 1]);

        // Pending free-text answers, for the editor to grade by hand.
        $pending = $manages
            ? ContestAnswer::with(['member.user:id,name', 'question:id,question,points'])
                ->whereIn('question_id', $questionIds)
                ->whereNull('is_correct')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->member?->user?->name,
                    'question' => $a->question?->question,
                    'answer_text' => $a->answer_text,
                    'points' => $a->question?->points,
                ])
            : collect();

        return response()->json(['data' => [
            'title' => $contest->title,
            'phase' => $contest->phase(),
            'max_points' => $contest->questions->sum('points'),
            'board' => $board,
            'pending' => $pending,
        ]]);
    }

    /** Manual grading for free-text answers without a model answer. */
    public function gradeAnswer(Request $request, string $uuid, int $answerId): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $contest = Contest::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['is_correct' => ['required', 'boolean']]);

        $answer = ContestAnswer::whereIn('question_id', $contest->questions()->select('id'))
            ->whereKey($answerId)
            ->firstOrFail();

        $answer->update([
            'is_correct' => $data['is_correct'],
            'points_awarded' => $data['is_correct'] ? ($answer->question->points ?? 0) : 0,
        ]);

        return response()->json(['message' => 'Answer graded.']);
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, string $uuid): Contest
    {
        return Contest::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** @return array{0: array, 1: ?array} [$contestData, $questions|null] */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['nullable', Rule::in(Contest::STATUSES)],
            // Contest for: everyone (default), one department, or one person.
            'audience_department' => ['nullable', 'string', 'max:64'],
            'audience_member_uuid' => ['nullable', 'string'],
            'questions' => ['nullable', 'array'],
            'questions.*.type' => ['required', Rule::in(['option', 'text'])],
            'questions.*.question' => ['required', 'string', 'max:2000'],
            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*' => ['string', 'max:255'],
            'questions.*.correct_option' => ['nullable', 'integer', 'min:0', 'max:9'],
            'questions.*.correct_text' => ['nullable', 'string', 'max:255'],
            'questions.*.points' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $questions = $data['questions'] ?? null;
        unset($data['questions']);

        // Resolve the aimed person; a department and a person are exclusive,
        // the person winning when both were sent.
        $data['audience_member_id'] = null;
        if (! empty($data['audience_member_uuid'])) {
            $data['audience_member_id'] = Member::where('organization_id', request()->attributes->get('crm_org')->id)
                ->where('uuid', $data['audience_member_uuid'])->firstOrFail()->id;
            $data['audience_department'] = null;
        }
        $data['audience_department'] = $data['audience_department'] ?? null;
        unset($data['audience_member_uuid']);

        foreach ($questions ?? [] as $q) {
            if ($q['type'] === 'option') {
                $options = array_values(array_filter($q['options'] ?? [], fn ($o) => trim($o) !== ''));
                if (count($options) < 2) {
                    abort(422, 'An option question needs at least two options.');
                }
                if (! isset($q['correct_option']) || $q['correct_option'] >= count($options)) {
                    abort(422, 'Every option question must mark which option is correct.');
                }
            }
        }

        return [$data, $questions];
    }

    private function syncQuestions(Contest $contest, array $questions): void
    {
        $contest->questions()->delete();
        foreach (array_values($questions) as $i => $q) {
            $options = $q['type'] === 'option'
                ? array_values(array_filter($q['options'] ?? [], fn ($o) => trim($o) !== ''))
                : null;
            $contest->questions()->create([
                'type' => $q['type'],
                'question' => $q['question'],
                'options' => $options,
                'correct_option' => $q['type'] === 'option' ? $q['correct_option'] : null,
                'correct_text' => $q['type'] === 'text' ? ($q['correct_text'] ?? null) : null,
                'points' => $q['points'] ?? 10,
                'sort' => $i,
            ]);
        }
    }
}
