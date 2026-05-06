from pydantic import BaseModel, Field, ConfigDict
from typing import List, Optional, Dict, Any

class Education(BaseModel):
    """Education record — must match the shape returned by PHP's getCandidate()."""
    model_config = ConfigDict(extra='ignore')

    institution: str
    degree: str = "Unknown"
    field_of_study: str = "Unknown"
    is_kenyan_institution: bool = False
    graduation_year: Optional[int] = None

class Experience(BaseModel):
    """Work experience entry — must match the shape returned by PHP's getCandidate()."""
    model_config = ConfigDict(extra='ignore')

    title: str
    company: str
    years: float
    description: Optional[str] = None

class Candidate(BaseModel):
    """
    Candidate profile consumed by the Matching Engine.
    
    This model accepts data from two sources:
      1. PHP CV Processor's GET /candidate/{id} endpoint (includes email, status, etc.)
      2. Direct API calls to POST /match
    
    Extra fields from PHP (email, phone, status) are safely ignored via ConfigDict.
    """
    model_config = ConfigDict(extra='ignore')

    id: str
    name: str
    skills: List[str]
    education: List[Education]
    experience: List[Experience]
    raw_resume_text: Optional[str] = None

class JobDescription(BaseModel):
    model_config = ConfigDict(extra='ignore')

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

class MatchRequest(BaseModel):
    """Wrapper for the /match endpoint — groups job + candidates into one body."""
    job: JobDescription
    candidates: List[Candidate]
