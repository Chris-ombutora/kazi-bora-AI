<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Experience extends Model
{
    protected $table = 'experience';
    protected $fillable = ['candidate_id', 'company_name', 'job_title', 'years_of_experience'];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'years_of_experience' => 'float',
        ];
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
