<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Candidate model — maps to the `candidates` table created by Developer 1's schema.
 * This model is shared between the PHP CV Processor and the Platform Core.
 */
class Candidate extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'original_file_path',
        'raw_resume_text',
        'status',
    ];

    /* ---- Relationships ---- */

    public function skills()
    {
        return $this->hasMany(Skill::class);
    }

    public function education()
    {
        return $this->hasMany(Education::class);
    }

    public function experience()
    {
        return $this->hasMany(Experience::class);
    }

    public function matchScores()
    {
        return $this->hasMany(MatchScore::class);
    }

    /**
     * Format candidate data for the AI Matching Service.
     * Output matches the Pydantic Candidate model in Developer 2's backend.
     */
    public function toMatcherPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'skills' => $this->skills->pluck('skill_name')->toArray(),
            'education' => $this->education->map(fn($edu) => [
                'institution' => $edu->institution_name,
                'is_kenyan_institution' => (bool) $edu->is_kenyan_institution,
                'degree' => $edu->degree ?? 'Unknown',
                'field_of_study' => 'Unknown',
                'graduation_year' => $edu->graduation_year,
            ])->toArray(),
            'experience' => $this->experience->map(fn($exp) => [
                'title' => $exp->job_title ?? 'Professional',
                'company' => $exp->company_name ?? 'Not specified',
                'years' => (float) $exp->years_of_experience,
                'description' => null,
            ])->toArray(),
            'raw_resume_text' => $this->raw_resume_text,
        ];
    }
}
