import json
import os
import numpy as np
from sentence_transformers import SentenceTransformer, util
from .models import Candidate, JobDescription, ScoringCard

# Load the sentence transformer model
# We'll use a lightweight model for faster inference and batch processing
try:
    model = SentenceTransformer('all-MiniLM-L6-v2')
except Exception as e:
    print(f"Failed to load sentence-transformers model: {e}")
    model = None

# Fallback: load Kenyan institutions from JSON for candidates without the is_kenyan_institution flag
def load_kenyan_institutions() -> list:
    """Load institutions from the JSON file as a fallback lookup."""
    json_path = os.path.join(os.path.dirname(__file__), 'kenyan_institutions.json')
    if os.path.exists(json_path):
        with open(json_path, 'r') as f:
            return json.load(f)
    return []

KNOWN_INSTITUTIONS = load_kenyan_institutions()
KNOWN_INSTITUTIONS_LOWER = set(inst.lower() for inst in KNOWN_INSTITUTIONS)


def calculate_skills_score(candidate_skills: list, job_skills: list, job_preferred: list) -> float:
    """
    Score based on overlap between candidate skills and job requirements.
    Required skills contribute up to 1.0, preferred skills add up to 0.2 bonus (capped at 1.0).
    """
    if not job_skills:
        return 1.0  # default if no skills required

    candidate_skills_lower = {s.lower() for s in candidate_skills}
    job_skills_lower = {s.lower() for s in job_skills}
    job_pref_lower = {s.lower() for s in job_preferred}
    
    # Required skills score
    matched_required = candidate_skills_lower.intersection(job_skills_lower)
    required_score = len(matched_required) / len(job_skills_lower) if job_skills_lower else 1.0
    
    # Preferred skills bonus
    matched_preferred = candidate_skills_lower.intersection(job_pref_lower)
    preferred_bonus = (len(matched_preferred) / len(job_pref_lower)) * 0.2 if job_pref_lower else 0.0
    
    return min(1.0, required_score + preferred_bonus)


def calculate_experience_score(candidate_exp_history: list, min_years_required: float) -> float:
    """Score based on total years of experience vs job requirement."""
    total_years = sum(exp.years for exp in candidate_exp_history)
    if min_years_required <= 0:
        return 1.0
    
    if total_years >= min_years_required:
        return 1.0
    
    return total_years / min_years_required


def calculate_education_score(candidate_education: list) -> float:
    """
    Score education quality. Uses the is_kenyan_institution flag set by the NLP parser
    (stored in the DB by the PHP service). Falls back to JSON lookup if the flag is missing.
    
    Scoring:
      - No education: 0.0
      - Has education: 0.5 base
      - From a known Kenyan institution: +0.5 (capped at 1.0)
    """
    if not candidate_education:
        return 0.0

    score = 0.5  # Base score for having some education
    
    for edu in candidate_education:
        # Primary check: use the is_kenyan_institution flag from the NLP parser / PHP service
        if edu.is_kenyan_institution:
            score += 0.5
            break
        # Fallback: check against the local JSON institution list
        elif edu.institution.lower() in KNOWN_INSTITUTIONS_LOWER:
            score += 0.5
            break

    return min(1.0, score)


def calculate_semantic_score(candidate_text: str, job_text: str) -> float:
    """Compute semantic similarity between candidate resume text and job description."""
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
    """
    Core matching function: scores a candidate against a job description using
    structured signals (skills, experience, education) and semantic similarity.
    
    Returns a ScoringCard with overall score, component scores, and explanation.
    """
    # 1. Structured signals processing
    skills_score = calculate_skills_score(candidate.skills, job.required_skills, job.preferred_skills)
    experience_score = calculate_experience_score(candidate.experience, job.minimum_years_experience)
    education_score = calculate_education_score(candidate.education)
    
    # 2. Semantic matching processing
    # Fallback to constructing text from structured data if raw text is missing
    cand_text = candidate.raw_resume_text
    if not cand_text:
        skills_text = " ".join(candidate.skills)
        exp_text = " ".join(
            f"{e.title} at {e.company}" for e in candidate.experience
        )
        edu_text = " ".join(
            f"{e.degree} from {e.institution}" for e in candidate.education
        )
        cand_text = f"{skills_text} {exp_text} {edu_text}".strip()
    
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
    
    # Build sets once for explanation (avoid re-creating inside list comprehensions)
    candidate_skills_lower = {s.lower() for s in candidate.skills}
    
    # Explanation payload
    explanation = {
        "matched_skills": [s for s in job.required_skills if s.lower() in candidate_skills_lower],
        "missing_skills": [s for s in job.required_skills if s.lower() not in candidate_skills_lower],
        "total_experience_years": sum(exp.years for exp in candidate.experience),
        "required_experience": job.minimum_years_experience,
        "semantic_similarity": f"{semantic_score:.2f}",
        "education_status": "Matched Known Institution" if education_score >= 1.0 else "Generic",
        "weighted_breakdown": {
            "semantic": round(W_SEMANTIC * semantic_score, 4),
            "skills": round(W_SKILLS * skills_score, 4),
            "experience": round(W_EXP * experience_score, 4),
            "education": round(W_EDU * education_score, 4)
        }
    }
    
    return ScoringCard(
        candidate_id=candidate.id,
        job_id=job.id,
        overall_score=round(overall_score, 4),
        semantic_score=round(semantic_score, 4),
        skills_score=round(skills_score, 4),
        experience_score=round(experience_score, 4),
        education_score=round(education_score, 4),
        explanation=explanation
    )
