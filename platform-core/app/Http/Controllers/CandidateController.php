<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Jobs\ProcessCvJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CandidateController extends Controller
{
    /**
     * GET /api/candidates — List all candidates with their processing status.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Candidate::with(['skills'])->orderByDesc('created_at');

        // Optional filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * POST /api/candidates/upload — Upload a CV file and queue it for processing.
     *
     * Accepts multipart form data with:
     *   - cv: PDF or DOCX file (required, max 10MB)
     *   - name: Candidate name
     *   - email: Candidate email
     *   - phone: Candidate phone
     *   - job_id: Optional job posting ID to auto-score after parsing
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cv' => 'required|file|mimes:pdf,docx|max:10240', // 10MB max
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'job_id' => 'nullable|integer|exists:job_postings,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Store the uploaded file
        $file = $request->file('cv');
        $filename = uniqid('cv_') . '.' . $file->getClientOriginalExtension();
        $destPath = storage_path('app/cv_uploads/' . $filename);

        // Ensure upload directory exists
        if (!is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0755, true);
        }

        $file->move(dirname($destPath), $filename);

        // Create candidate record
        $candidate = Candidate::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'original_file_path' => $destPath,
            'status' => 'pending',
        ]);

        // Dispatch async processing job
        ProcessCvJob::dispatch($candidate->id, $request->job_id);

        return response()->json([
            'message' => 'CV uploaded and queued for processing.',
            'candidate_id' => $candidate->id,
            'status' => 'pending',
        ], 201);
    }

    /**
     * POST /api/candidates/upload-bulk — Upload multiple CVs at once.
     */
    public function uploadBulk(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cvs' => 'required|array|min:1|max:50',
            'cvs.*' => 'file|mimes:pdf,docx|max:10240',
            'job_id' => 'nullable|integer|exists:job_postings,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $results = [];

        foreach ($request->file('cvs') as $file) {
            $filename = uniqid('cv_') . '.' . $file->getClientOriginalExtension();
            $destPath = storage_path('app/cv_uploads/' . $filename);

            if (!is_dir(dirname($destPath))) {
                mkdir(dirname($destPath), 0755, true);
            }

            $file->move(dirname($destPath), $filename);

            // Use the original filename to guess the candidate name
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            $candidate = Candidate::create([
                'name' => $originalName,
                'email' => 'pending@kazibora.ai',
                'phone' => null,
                'original_file_path' => $destPath,
                'status' => 'pending',
            ]);

            ProcessCvJob::dispatch($candidate->id, $request->job_id);

            $results[] = [
                'candidate_id' => $candidate->id,
                'original_filename' => $file->getClientOriginalName(),
                'status' => 'pending',
            ];
        }

        return response()->json([
            'message' => count($results) . ' CVs uploaded and queued.',
            'candidates' => $results,
        ], 201);
    }

    /**
     * GET /api/candidates/{id} — Get full candidate profile with structured data.
     * Returns data formatted to match Developer 2's Candidate Pydantic model.
     */
    public function show(int $id): JsonResponse
    {
        $candidate = Candidate::with(['skills', 'education', 'experience', 'matchScores'])
            ->find($id);

        if (!$candidate) {
            return response()->json(['error' => 'Candidate not found.'], 404);
        }

        return response()->json([
            'id' => $candidate->id,
            'name' => $candidate->name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'status' => $candidate->status,
            'skills' => $candidate->skills->pluck('skill_name'),
            'education' => $candidate->education->map(fn($edu) => [
                'institution' => $edu->institution_name,
                'is_kenyan_institution' => $edu->is_kenyan_institution,
                'degree' => $edu->degree ?? 'Unknown',
                'graduation_year' => $edu->graduation_year,
            ]),
            'experience' => $candidate->experience->map(fn($exp) => [
                'company' => $exp->company_name,
                'title' => $exp->job_title,
                'years' => $exp->years_of_experience,
            ]),
            'match_scores' => $candidate->matchScores->map(fn($s) => [
                'job_id' => $s->job_id,
                'overall_score' => round($s->overall_score, 4),
                'matched_skills' => $s->matched_skills,
                'missing_skills' => $s->missing_skills,
            ]),
            'created_at' => $candidate->created_at,
        ]);
    }
}
