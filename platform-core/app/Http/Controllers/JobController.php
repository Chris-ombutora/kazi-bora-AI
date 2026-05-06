<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\MatchScore;
use App\Jobs\ScoreCandidateJob;
use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobController extends Controller
{
    /**
     * GET /api/jobs — List all jobs for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $jobs = Job::where('user_id', $request->user()->id)
            ->withCount('matchScores')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($jobs);
    }

    /**
     * POST /api/jobs — Create a new job posting.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'required_skills' => 'required|array|min:1',
            'required_skills.*' => 'string',
            'preferred_skills' => 'nullable|array',
            'preferred_skills.*' => 'string',
            'min_years_experience' => 'required|numeric|min:0',
            'location' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $job = Job::create([
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'required_skills' => $request->required_skills,
            'preferred_skills' => $request->preferred_skills ?? [],
            'min_years_experience' => $request->min_years_experience,
            'location' => $request->location,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Job posting created successfully.',
            'job' => $job,
        ], 201);
    }

    /**
     * GET /api/jobs/{id} — Get job details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $job = Job::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->withCount('matchScores')
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        return response()->json($job);
    }

    /**
     * PUT /api/jobs/{id} — Update a job posting.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $job = Job::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'required_skills' => 'sometimes|array|min:1',
            'preferred_skills' => 'sometimes|array',
            'min_years_experience' => 'sometimes|numeric|min:0',
            'location' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,closed,draft',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $job->update($request->only([
            'title', 'description', 'required_skills', 'preferred_skills',
            'min_years_experience', 'location', 'status',
        ]));

        return response()->json([
            'message' => 'Job updated successfully.',
            'job' => $job->fresh(),
        ]);
    }

    /**
     * DELETE /api/jobs/{id} — Delete a job posting.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $job = Job::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        $job->matchScores()->delete();
        $job->delete();

        return response()->json(['message' => 'Job deleted successfully.']);
    }

    /**
     * GET /api/jobs/{id}/candidates — Get ranked candidates for a job.
     * Returns candidates sorted by overall_score descending.
     */
    public function rankedCandidates(Request $request, int $id): JsonResponse
    {
        $job = Job::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        $scores = MatchScore::where('job_id', $id)
            ->with(['candidate.skills', 'candidate.education', 'candidate.experience'])
            ->orderByDesc('overall_score')
            ->paginate(20);

        // Format response with candidate details and scoring breakdown
        $ranked = $scores->through(function ($score) {
            $candidate = $score->candidate;
            return [
                'rank' => null, // Will be set below
                'candidate' => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'email' => $candidate->email,
                    'skills' => $candidate->skills->pluck('skill_name'),
                    'education' => $candidate->education->map(fn($e) => [
                        'institution' => $e->institution_name,
                        'degree' => $e->degree,
                        'is_kenyan' => $e->is_kenyan_institution,
                    ]),
                    'total_experience_years' => $candidate->experience->sum('years_of_experience'),
                ],
                'scores' => [
                    'overall' => round($score->overall_score, 4),
                    'semantic' => round($score->semantic_score, 4),
                    'skills' => round($score->skills_score, 4),
                    'experience' => round($score->experience_score, 4),
                    'education' => round($score->education_score, 4),
                ],
                'matched_skills' => $score->matched_skills,
                'missing_skills' => $score->missing_skills,
                'explanation' => $score->explanation,
            ];
        });

        // Set ranks based on pagination offset
        $offset = ($scores->currentPage() - 1) * $scores->perPage();
        $ranked->each(function (&$item, $index) use ($offset) {
            $item['rank'] = $offset + $index + 1;
        });

        return response()->json($ranked);
    }

    /**
     * POST /api/jobs/{id}/score-all — Score all completed candidates against this job.
     */
    public function scoreAllCandidates(Request $request, int $id): JsonResponse
    {
        $job = Job::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        // Find candidates that have been processed but not yet scored for this job
        $candidates = Candidate::where('status', 'completed')
            ->whereDoesntHave('matchScores', fn($q) => $q->where('job_id', $id))
            ->get();

        if ($candidates->isEmpty()) {
            return response()->json(['message' => 'No unscored candidates found.']);
        }

        foreach ($candidates as $candidate) {
            ScoreCandidateJob::dispatch($candidate->id, $id);
        }

        return response()->json([
            'message' => "Scoring queued for {$candidates->count()} candidates.",
            'candidates_queued' => $candidates->count(),
        ]);
    }
}
