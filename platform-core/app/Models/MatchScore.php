<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchScore extends Model
{
    protected $fillable = [
        'candidate_id',
        'job_id',
        'overall_score',
        'semantic_score',
        'skills_score',
        'experience_score',
        'education_score',
        'matched_skills',
        'missing_skills',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'float',
            'semantic_score' => 'float',
            'skills_score' => 'float',
            'experience_score' => 'float',
            'education_score' => 'float',
            'matched_skills' => 'array',
            'missing_skills' => 'array',
            'explanation' => 'array',
        ];
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
