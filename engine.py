import json
import os
import numpy as np
from sentence_transformers import SentenceTransformer, util
from .models import Candidate, JobDescription, ScoringCard

# Load the sentence transformer model
# We use a lightweight model for faster inference and batch processing
try:
    model = SentenceTransformer('all-MiniLM-L6-v2')
except Exception as e:
    print(f"Failed to load sentence-transformers model: {e}")
    model = None

def load_kenyan_institutions() -> list:
    """Load institutions from the JSON file to provide education score boosting."""
    json_path = os.path.join(os.path.dirname(__file__), '..', 'kenyan_institutions.json')
    if os.path.exists(json_path):
        with open(json_path, 'r') as f:
            return json.load(f)
    return []

KNOWN_INSTITUTIONS = load_kenyan_institutions()

def calculate_skills_score(candidate_skills: list, job_skills: list, job_preferred: list) -> float:
    if not job_skills:
        return 1.0 # default if no skills required
    candidate_skills_lower = set([s.lower() for s in candidate_skills])
    job_skills_lower = set([s.lower() for s in job_skills])
    job_pref_lower = set([s.lower() for s in job_preferred])
    
    # Required skills score
    matched_required = candidate_skills_lower.intersection(job_skills_lower)
    required_score = len(matched_required) / len(job_skills_lower) if job_skills_lower else 1.0
    
    # Preferred skills bonus
    matched_preferred = candidate_skills_lower.intersection(job_pref_lower)
    preferred_bonus = (len(matched_preferred) / len(job_pref_lower)) * 0.2 if job_pref_lower else 0.0
    
    return min(1.0, required_score + preferred_bonus)

def calculate_experience_score(candidate_exp_history: list, min_years_required: float) -> float:
    total_years = sum([exp.years for exp in candidate_exp_history])
    if min_years_required <= 0:
        return 1.0
    
    if total_years >= min_years_required:
        return 1.0
    
    return total_years / min_years_required

def calculate_education_score(candidate_education: list) -> float:
    score = 0.5 # Base score for having some education
    if not candidate_education:
        return 0.0
    
    known_institutions_lower = set([inst.lower() for inst in KNOWN_INSTITUTIONS])
    
    # Boost for known Kenyan institutions (for domestic hiring preference, if applicable)
    for edu in candidate_education:
        if edu.institution.lower() in known_institutions_lower:
            score += 0.5
            break # Max out at 1.0
    return min(1.0, score)

def calculate_semantic_score(candidate_text: str, job_text: str) -> float:
    if not model or not candidate_text or not job_text:
        return 0.5
    
    # Compute embeddings
    job_embedding = model.encode(job_text, convert_to_tensor=True)
    candidate_embedding = model.encode(candidate_text, convert_to_tensor=True)
    
    # Compute cosine similarity
    cosine_score = util.cos_sim(job_embedding, candidate_embedding)
    
    # Convert tensor to float and scale to 0-1 range if needed (MiniLM cosine is generally -1 to 1)
    # Since texts are likely conceptually related, we might get scores 0.3-0.8. We map negatives to 0.
    return float(max(0.0, cosine_score.item()))

def match_candidate_to_job(candidate: Candidate, job: JobDescription) -> ScoringCard:
    # 1. Structured signals processing
    skills_score = calculate_skills_score(candidate.skills, job.required_skills, job.preferred_skills)
    experience_score = calculate_experience_score(candidate.experience, job.minimum_years_experience)
    education_score = calculate_education_score(candidate.education)
    
    # 2. Semantic matching processing
    # Fallback to constructing text from structured data if raw text is missing
    cand_text = candidate.raw_resume_text
    if not cand_text:
        cand_text = " ".join(candidate.skills) + " " + " ".join([e.title + " at " + e.company for e in candidate.experience])
    
    semantic_score = calculate_semantic_score(cand_text, job.description_text)
    
    # 3. Overall calculation (Weighted average)
    # Weights can be adjusted based on domain specifics
    W_SEMANTIC = 0.40
    W_SKILLS = 0.30
    W_EXP = 0.20
    W_EDU = 0.10
    
    overall_score = (
        (semantic_score * W_SEMANTIC) +
        (skills_score * W_SKILLS) +
        (experience_score * W_EXP) +
        (education_score * W_EDU)
    )
    
    # Explanation payload
    explanation = {
        "matched_skills": [s for s in job.required_skills if s.lower() in set([c_s.lower() for c_s in candidate.skills])],
        "missing_skills": [s for s in job.required_skills if s.lower() not in set([c_s.lower() for c_s in candidate.skills])],
        "total_experience_years": sum([exp.years for exp in candidate.experience]),
        "required_experience": job.minimum_years_experience,
        "semantic_similarity": f"{semantic_score:.2f}",
        "education_status": "Matched Known Institution" if education_score >= 1.0 else "Generic",
        "weighted_breakdown": {
            "semantic": W_SEMANTIC * semantic_score,
            "skills": W_SKILLS * skills_score,
            "experience": W_EXP * experience_score,
            "education": W_EDU * education_score
        }
    }
    
    return ScoringCard(
        candidate_id=candidate.id,
        job_id=job.id,
        overall_score=overall_score,
        semantic_score=semantic_score,
        skills_score=skills_score,
        experience_score=experience_score,
        education_score=education_score,
        explanation=explanation
    )
