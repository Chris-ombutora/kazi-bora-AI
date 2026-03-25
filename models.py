from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any

class Education(BaseModel):
    institution: str
    degree: str
    field_of_study: str

class Experience(BaseModel):
    title: str
    company: str
    years: float
    description: Optional[str] = None

class Candidate(BaseModel):
    id: str
    name: str
    skills: List[str]
    education: List[Education]
    experience: List[Experience]
    raw_resume_text: Optional[str] = None

class JobDescription(BaseModel):
    id: str
    title: str
    required_skills: List[str]
    preferred_skills: List[str] = []
    minimum_years_experience: float
    description_text: str

class ScoringCard(BaseModel):
    candidate_id: str
    job_id: str
    overall_score: float
    semantic_score: float
    skills_score: float
    experience_score: float
    education_score: float
    explanation: Dict[str, Any]
