<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Education extends Model
{
    protected $table = 'education';
    protected $fillable = ['candidate_id', 'institution_name', 'is_kenyan_institution', 'degree', 'graduation_year'];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_kenyan_institution' => 'boolean',
            'graduation_year' => 'integer',
        ];
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
