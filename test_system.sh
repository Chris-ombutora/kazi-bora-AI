#!/bin/bash
# ============================================================
#  KaziBora AI — System Verification Script
#  Run this AFTER `docker compose up` is fully started
# ============================================================

BASE_URL="http://localhost:9000/api"
NLP_URL="http://localhost:8000"
MATCHER_URL="http://localhost:8001"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

pass() { echo -e "  ${GREEN}✓ PASS${NC} — $1"; }
fail() { echo -e "  ${RED}✗ FAIL${NC} — $1"; }
info() { echo -e "${YELLOW}$1${NC}"; }

echo "============================================"
echo "  KaziBora AI — System Health Check"
echo "============================================"
echo ""

# ── 1. Check NLP Service (Developer 1) ──
info "1. NLP Service (Developer 1) — :8000"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" $NLP_URL/health 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    pass "NLP Service is healthy"
else
    fail "NLP Service not reachable (HTTP $HTTP_CODE)"
fi

# ── 2. Check Matcher API (Developer 2) ──
info "2. Matcher API (Developer 2) — :8001"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" $MATCHER_URL/ 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    pass "Matcher API is healthy"
else
    fail "Matcher API not reachable (HTTP $HTTP_CODE)"
fi

# ── 3. Check Platform Core (Developer 3) ──
info "3. Platform Core (Developer 3) — :9000"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" $BASE_URL/health 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    pass "Platform Core is healthy"
else
    fail "Platform Core not reachable (HTTP $HTTP_CODE)"
fi

echo ""
info "4. Testing Auth Flow..."

# ── 4. Register a test user ──
REGISTER_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/register" \
    -H "Content-Type: application/json" \
    -d '{
        "name": "Test User",
        "email": "test@kazibora.ai",
        "password": "password123",
        "password_confirmation": "password123"
    }' 2>/dev/null)

TOKEN=$(echo $REGISTER_RESPONSE | python3 -c "import sys,json; print(json.load(sys.stdin).get('token',''))" 2>/dev/null)

if [ -n "$TOKEN" ] && [ "$TOKEN" != "" ]; then
    pass "User registration works (got JWT token)"
else
    # Try login if user already exists
    LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/login" \
        -H "Content-Type: application/json" \
        -d '{"email": "test@kazibora.ai", "password": "password123"}' 2>/dev/null)
    
    TOKEN=$(echo $LOGIN_RESPONSE | python3 -c "import sys,json; print(json.load(sys.stdin).get('token',''))" 2>/dev/null)
    
    if [ -n "$TOKEN" ] && [ "$TOKEN" != "" ]; then
        pass "User login works (got JWT token)"
    else
        fail "Auth flow broken: $REGISTER_RESPONSE"
    fi
fi

# ── 5. Create a job posting ──
info "5. Testing Job Creation..."
JOB_RESPONSE=$(curl -s -X POST "$BASE_URL/jobs" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d '{
        "title": "Senior Python Developer",
        "description": "We are looking for an experienced Python developer with Django and FastAPI skills for our Nairobi office.",
        "required_skills": ["python", "django", "fastapi", "sql"],
        "preferred_skills": ["docker", "aws", "react"],
        "min_years_experience": 3,
        "location": "Nairobi, Kenya"
    }' 2>/dev/null)

JOB_ID=$(echo $JOB_RESPONSE | python3 -c "import sys,json; print(json.load(sys.stdin).get('job',{}).get('id',''))" 2>/dev/null)

if [ -n "$JOB_ID" ] && [ "$JOB_ID" != "" ]; then
    pass "Job posting created (ID: $JOB_ID)"
else
    fail "Job creation failed: $JOB_RESPONSE"
fi

# ── 6. Test NLP Parsing directly ──
info "6. Testing NLP Parsing..."
NLP_RESPONSE=$(curl -s -X POST "$NLP_URL/parse" \
    -H "Content-Type: application/json" \
    -d '{
        "text": "John Doe is a Software Engineer with 5 years of experience in Python, Django, and FastAPI. He graduated from University of Nairobi with a BSc in Computer Science in 2019. Previously worked at Safaricom as a Backend Developer."
    }' 2>/dev/null)

SKILLS_COUNT=$(echo $NLP_RESPONSE | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('skills',[])))" 2>/dev/null)

if [ "$SKILLS_COUNT" -gt "0" ] 2>/dev/null; then
    pass "NLP parsed $SKILLS_COUNT skills from sample CV text"
    echo "       Skills: $(echo $NLP_RESPONSE | python3 -c "import sys,json; print(', '.join(json.load(sys.stdin).get('skills',[])))" 2>/dev/null)"
else
    fail "NLP parsing returned no skills: $NLP_RESPONSE"
fi

# ── 7. Test Matching directly ──
info "7. Testing AI Matching..."
MATCH_RESPONSE=$(curl -s -X POST "$MATCHER_URL/match" \
    -H "Content-Type: application/json" \
    -d '{
        "job": {
            "id": "test-job-1",
            "title": "Python Developer",
            "required_skills": ["python", "django", "fastapi"],
            "preferred_skills": ["docker", "aws"],
            "minimum_years_experience": 3,
            "description_text": "Looking for a senior Python developer with web framework experience"
        },
        "candidates": [{
            "id": "test-cand-1",
            "name": "John Doe",
            "skills": ["python", "django", "fastapi", "sql", "docker"],
            "education": [{"institution": "University of Nairobi", "degree": "BSc", "field_of_study": "Computer Science", "is_kenyan_institution": true}],
            "experience": [{"title": "Backend Developer", "company": "Safaricom", "years": 5.0}],
            "raw_resume_text": "Experienced Python developer with Django and FastAPI skills, 5 years at Safaricom"
        }]
    }' 2>/dev/null)

TASK_ID=$(echo $MATCH_RESPONSE | python3 -c "import sys,json; print(json.load(sys.stdin).get('task_id',''))" 2>/dev/null)

if [ -n "$TASK_ID" ] && [ "$TASK_ID" != "" ]; then
    pass "Matching task queued (Task ID: $TASK_ID)"
    
    # Wait and check result
    sleep 5
    RESULT=$(curl -s "$MATCHER_URL/task/$TASK_ID" 2>/dev/null)
    STATUS=$(echo $RESULT | python3 -c "import sys,json; print(json.load(sys.stdin).get('task_status',''))" 2>/dev/null)
    
    if [ "$STATUS" = "SUCCESS" ]; then
        SCORE=$(echo $RESULT | python3 -c "import sys,json; r=json.load(sys.stdin)['task_result']['results'][0]; print(f\"{r['overall_score']:.2%}\")" 2>/dev/null)
        pass "Matching complete! Overall score: $SCORE"
    else
        info "   Task status: $STATUS (may still be processing)"
    fi
else
    fail "Matching submission failed: $MATCH_RESPONSE"
fi

# ── 8. Check subscription plans ──
info "8. Testing Payment Plans..."
PLANS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/payments/plans" 2>/dev/null)
if [ "$PLANS_CODE" = "200" ]; then
    pass "Payment plans endpoint works"
else
    fail "Payment plans endpoint returned HTTP $PLANS_CODE"
fi

echo ""
echo "============================================"
echo "  Health check complete!"
echo "============================================"
echo ""
echo "Service URLs:"
echo "  Platform Core API: http://localhost:9000/api"
echo "  NLP Service:       http://localhost:8000"  
echo "  Matcher API:       http://localhost:8001"
echo "  PHP CV Processor:  http://localhost:8080"
echo ""
