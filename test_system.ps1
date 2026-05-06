# ============================================================
#  KaziBora AI — System Verification Script (PowerShell)
#  Run this AFTER `docker compose up` is fully started
# ============================================================

$BASE_URL = "http://localhost:9000/api"
$NLP_URL = "http://localhost:8000"
$MATCHER_URL = "http://localhost:8001"

function Pass($msg) { Write-Host "  ✓ PASS — $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "  ✗ FAIL — $msg" -ForegroundColor Red }
function Info($msg) { Write-Host $msg -ForegroundColor Yellow }

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  KaziBora AI — System Health Check" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ── 1. NLP Service ──
Info "1. NLP Service (Developer 1) — :8000"
try {
    $r = Invoke-RestMethod -Uri "$NLP_URL/health" -Method GET -ErrorAction Stop
    Pass "NLP Service is healthy"
} catch {
    Fail "NLP Service not reachable ($_)"
}

# ── 2. Matcher API ──
Info "2. Matcher API (Developer 2) — :8001"
try {
    $r = Invoke-RestMethod -Uri "$MATCHER_URL/" -Method GET -ErrorAction Stop
    Pass "Matcher API is healthy"
} catch {
    Fail "Matcher API not reachable ($_)"
}

# ── 3. Platform Core ──
Info "3. Platform Core (Developer 3) — :9000"
try {
    $r = Invoke-RestMethod -Uri "$BASE_URL/health" -Method GET -ErrorAction Stop
    Pass "Platform Core is healthy"
} catch {
    Fail "Platform Core not reachable ($_)"
}

Write-Host ""
Info "4. Testing Auth Flow..."

# ── 4. Register ──
$body = @{
    name = "Test User"
    email = "test@kazibora.ai"
    password = "password123"
    password_confirmation = "password123"
} | ConvertTo-Json

$token = $null
try {
    $r = Invoke-RestMethod -Uri "$BASE_URL/auth/register" -Method POST -Body $body -ContentType "application/json" -ErrorAction Stop
    $token = $r.token
    Pass "User registration works (got JWT token)"
} catch {
    # Try login if user already exists
    try {
        $loginBody = @{ email = "test@kazibora.ai"; password = "password123" } | ConvertTo-Json
        $r = Invoke-RestMethod -Uri "$BASE_URL/auth/login" -Method POST -Body $loginBody -ContentType "application/json" -ErrorAction Stop
        $token = $r.token
        Pass "User login works (got JWT token)"
    } catch {
        Fail "Auth flow broken: $_"
    }
}

# ── 5. Create Job ──
Info "5. Testing Job Creation..."
if ($token) {
    $headers = @{ Authorization = "Bearer $token" }
    $jobBody = @{
        title = "Senior Python Developer"
        description = "Looking for an experienced Python developer with Django and FastAPI skills for our Nairobi office."
        required_skills = @("python", "django", "fastapi", "sql")
        preferred_skills = @("docker", "aws", "react")
        min_years_experience = 3
        location = "Nairobi, Kenya"
    } | ConvertTo-Json

    try {
        $r = Invoke-RestMethod -Uri "$BASE_URL/jobs" -Method POST -Body $jobBody -ContentType "application/json" -Headers $headers -ErrorAction Stop
        $jobId = $r.job.id
        Pass "Job posting created (ID: $jobId)"
    } catch {
        Fail "Job creation failed: $_"
    }
} else {
    Fail "Skipping — no auth token available"
}

# ── 6. Test NLP ──
Info "6. Testing NLP Parsing..."
$nlpBody = @{
    text = "John Doe is a Software Engineer with 5 years of experience in Python, Django, and FastAPI. He graduated from University of Nairobi with a BSc in Computer Science in 2019. Previously worked at Safaricom as a Backend Developer."
} | ConvertTo-Json

try {
    $r = Invoke-RestMethod -Uri "$NLP_URL/parse" -Method POST -Body $nlpBody -ContentType "application/json" -ErrorAction Stop
    $skillCount = $r.skills.Count
    Pass "NLP parsed $skillCount skills from sample CV text"
    Write-Host "       Skills: $($r.skills -join ', ')" -ForegroundColor Gray
} catch {
    Fail "NLP parsing failed: $_"
}

# ── 7. Test Matching ──
Info "7. Testing AI Matching..."
$matchBody = @{
    job = @{
        id = "test-job-1"
        title = "Python Developer"
        required_skills = @("python", "django", "fastapi")
        preferred_skills = @("docker", "aws")
        minimum_years_experience = 3
        description_text = "Looking for a senior Python developer with web framework experience"
    }
    candidates = @(@{
        id = "test-cand-1"
        name = "John Doe"
        skills = @("python", "django", "fastapi", "sql", "docker")
        education = @(@{ institution = "University of Nairobi"; degree = "BSc"; field_of_study = "Computer Science"; is_kenyan_institution = $true })
        experience = @(@{ title = "Backend Developer"; company = "Safaricom"; years = 5.0 })
        raw_resume_text = "Experienced Python developer with Django and FastAPI skills, 5 years at Safaricom"
    })
} | ConvertTo-Json -Depth 5

try {
    $r = Invoke-RestMethod -Uri "$MATCHER_URL/match" -Method POST -Body $matchBody -ContentType "application/json" -ErrorAction Stop
    $taskId = $r.task_id
    Pass "Matching task queued (Task ID: $taskId)"

    Write-Host "       Waiting 5 seconds for results..." -ForegroundColor Gray
    Start-Sleep -Seconds 5

    try {
        $result = Invoke-RestMethod -Uri "$MATCHER_URL/task/$taskId" -Method GET -ErrorAction Stop
        if ($result.task_status -eq "SUCCESS") {
            $score = $result.task_result.results[0].overall_score
            $pct = [math]::Round($score * 100, 1)
            Pass "Matching complete! Overall score: $pct%"
        } else {
            Info "   Task status: $($result.task_status) (may still be processing)"
        }
    } catch {
        Info "   Could not retrieve results yet"
    }
} catch {
    Fail "Matching submission failed: $_"
}

# ── 8. Payment Plans ──
Info "8. Testing Payment Plans..."
try {
    $r = Invoke-RestMethod -Uri "$BASE_URL/payments/plans" -Method GET -ErrorAction Stop
    Pass "Payment plans endpoint works ($($r.plans.Count) plans available)"
} catch {
    Fail "Payment plans endpoint failed: $_"
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  Health check complete!" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Service URLs:" -ForegroundColor White
Write-Host "  Platform Core API: http://localhost:9000/api" -ForegroundColor Gray
Write-Host "  NLP Service:       http://localhost:8000" -ForegroundColor Gray
Write-Host "  Matcher API:       http://localhost:8001" -ForegroundColor Gray
Write-Host "  PHP CV Processor:  http://localhost:8080" -ForegroundColor Gray
Write-Host ""
