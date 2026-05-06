<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'job_postings';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'required_skills',
        'preferred_skills',
        'min_years_experience',
        'location',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'required_skills' => 'array',
            'preferred_skills' => 'array',
            'min_years_experience' => 'float',
        ];
    }

    /* ---- Relationships ---- */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function matchScores()
    {
        return $this->hasMany(MatchScore::class, 'job_id');
    }

    /**
     * Get ranked candidates for this job, sorted by overall score descending.
     */
    public function rankedCandidates()
    {
        return $this->matchScores()
            ->with('candidate.skills', 'candidate.education', 'candidate.experience')
            ->orderByDesc('overall_score');
    }
}
