<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = ['candidate_id', 'skill_name'];
    public $timestamps = false;

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
