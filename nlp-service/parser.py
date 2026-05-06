import spacy
import re
import mysql.connector
import os
import logging
import time

logger = logging.getLogger(__name__)

# Comprehensive skills taxonomy organized by category
SKILLS_TAXONOMY = {
    # Programming Languages
    'python', 'java', 'javascript', 'typescript', 'c', 'c++', 'c#', 'ruby',
    'go', 'golang', 'rust', 'swift', 'kotlin', 'scala', 'php', 'perl',
    'r', 'matlab', 'dart', 'lua', 'haskell', 'elixir', 'clojure',
    'objective-c', 'assembly', 'fortran', 'cobol', 'vba', 'bash', 'shell',
    'powershell',

    # Web Frontend
    'html', 'css', 'sass', 'scss', 'less', 'tailwind', 'tailwindcss',
    'bootstrap', 'react', 'reactjs', 'react.js', 'angular', 'angularjs',
    'vue', 'vuejs', 'vue.js', 'svelte', 'next.js', 'nextjs', 'nuxt',
    'nuxtjs', 'gatsby', 'jquery', 'webpack', 'vite', 'rollup',
    'redux', 'mobx', 'graphql', 'rest', 'restful', 'ajax', 'websocket',

    # Web Backend / Frameworks
    'node.js', 'nodejs', 'express', 'expressjs', 'django', 'flask',
    'fastapi', 'spring', 'spring boot', 'springboot', 'laravel',
    'symfony', 'rails', 'ruby on rails', 'asp.net', '.net', 'dotnet',
    'nestjs', 'gin', 'fiber', 'actix', 'rocket',

    # Mobile
    'android', 'ios', 'react native', 'flutter', 'xamarin', 'ionic',
    'swiftui', 'jetpack compose', 'cordova',

    # Databases
    'sql', 'mysql', 'postgresql', 'postgres', 'sqlite', 'oracle',
    'sql server', 'mssql', 'mariadb', 'mongodb', 'dynamodb', 'cassandra',
    'redis', 'elasticsearch', 'neo4j', 'couchdb', 'firebase', 'supabase',
    'cockroachdb', 'influxdb', 'memcached',

    # Cloud & DevOps
    'aws', 'amazon web services', 'azure', 'gcp', 'google cloud',
    'docker', 'kubernetes', 'k8s', 'terraform', 'ansible', 'puppet',
    'chef', 'jenkins', 'gitlab ci', 'github actions', 'circleci',
    'travis ci', 'ci/cd', 'devops', 'nginx', 'apache', 'linux',
    'ubuntu', 'centos', 'serverless', 'lambda', 'cloudformation',
    'helm', 'prometheus', 'grafana', 'datadog', 'splunk', 'elk',
    'vagrant', 'packer',

    # Data Science & ML
    'machine learning', 'deep learning', 'artificial intelligence', 'ai',
    'neural networks', 'nlp', 'natural language processing',
    'computer vision', 'tensorflow', 'pytorch', 'keras', 'scikit-learn',
    'sklearn', 'pandas', 'numpy', 'scipy', 'matplotlib', 'seaborn',
    'tableau', 'power bi', 'powerbi', 'jupyter', 'spark', 'pyspark',
    'hadoop', 'hive', 'airflow', 'dbt', 'etl', 'data engineering',
    'data analysis', 'data visualization', 'statistics', 'opencv',
    'hugging face', 'transformers', 'llm', 'langchain', 'openai',
    'spacy', 'nltk',

    # Testing
    'unit testing', 'integration testing', 'selenium', 'cypress',
    'jest', 'mocha', 'pytest', 'junit', 'testng', 'postman',
    'jmeter', 'playwright', 'tdd', 'bdd',

    # Tools & Platforms
    'git', 'github', 'gitlab', 'bitbucket', 'jira', 'confluence',
    'trello', 'slack', 'notion', 'figma', 'sketch', 'adobe xd',
    'photoshop', 'illustrator', 'canva',

    # Methodologies & Concepts
    'agile', 'scrum', 'kanban', 'waterfall', 'microservices', 'soa',
    'api design', 'system design', 'oop', 'design patterns',
    'solid principles', 'clean code', 'clean architecture',
    'event-driven', 'message queues', 'rabbitmq', 'kafka', 'celery',

    # Security
    'cybersecurity', 'penetration testing', 'owasp', 'encryption',
    'ssl', 'tls', 'oauth', 'jwt', 'sso', 'iam', 'firewalls',

    # Business & Soft Skills
    'project management', 'product management', 'business analysis',
    'stakeholder management', 'leadership', 'communication',
    'problem solving', 'critical thinking', 'teamwork', 'presentation',
    'negotiation', 'time management', 'budgeting', 'strategic planning',

    # Office & General
    'excel', 'microsoft excel', 'word', 'powerpoint', 'outlook',
    'google sheets', 'google docs', 'sap', 'salesforce', 'hubspot',
    'quickbooks', 'erp', 'crm',

    # Domain-specific (Kenya market)
    'mpesa', 'm-pesa', 'ussd', 'safaricom', 'mobile money',
    'fintech', 'agritech', 'edtech', 'healthtech',
}


class CVParser:
    def __init__(self):
        # Load spaCy model for entity recognition
        try:
            self.nlp = spacy.load("en_core_web_sm")
        except OSError:
            import spacy.cli
            spacy.cli.download("en_core_web_sm")
            self.nlp = spacy.load("en_core_web_sm")

        # Build a PhraseMatcher for multi-word skills
        from spacy.matcher import PhraseMatcher
        self.skill_matcher = PhraseMatcher(self.nlp.vocab, attr="LOWER")
        patterns = [self.nlp.make_doc(skill) for skill in SKILLS_TAXONOMY]
        self.skill_matcher.add("SKILLS", patterns)

        # Lazy-loaded Kenyan institutions (loaded on first use with retry)
        self._kenyan_institutions = None

    @property
    def kenyan_institutions(self):
        """Lazy-load institutions from DB with retry logic for Docker startup race conditions."""
        if self._kenyan_institutions is None:
            self._kenyan_institutions = self._load_kenyan_institutions_with_retry()
        return self._kenyan_institutions

    def _load_kenyan_institutions_with_retry(self, max_retries=3, delay=5):
        """Attempt to load institutions from DB, retrying on failure."""
        for attempt in range(max_retries):
            try:
                conn = mysql.connector.connect(
                    host=os.getenv("DB_HOST", "localhost"),
                    user=os.getenv("DB_USER", "root"),
                    password=os.getenv("DB_PASS", ""),
                    database=os.getenv("DB_NAME", "kazibora"),
                    port=int(os.getenv("DB_PORT", "3306"))
                )
                cursor = conn.cursor(dictionary=True)
                cursor.execute("SELECT name FROM kenyan_institutions")
                institutions = cursor.fetchall()
                conn.close()
                logger.info(f"Loaded {len(institutions)} Kenyan institutions from DB")
                return [inst['name'].lower() for inst in institutions]
            except Exception as e:
                logger.warning(f"Attempt {attempt + 1}/{max_retries} — could not load institutions: {e}")
                if attempt < max_retries - 1:
                    time.sleep(delay)
        logger.warning("All retries exhausted. Proceeding with empty institutions list.")
        return []

    def parse_text(self, text: str) -> dict:
        doc = self.nlp(text)

        skills = self._extract_skills(doc)
        education = self._extract_education(doc)
        experience = self._extract_experience(doc, text)

        return {
            "skills": skills,
            "education": education,
            "experience": experience
        }

    def _extract_skills(self, doc) -> list:
        """Extract skills using PhraseMatcher for multi-word and single-word matches."""
        found_skills = set()
        matches = self.skill_matcher(doc)
        for match_id, start, end in matches:
            span = doc[start:end]
            found_skills.add(span.text.lower())
        return sorted(found_skills)

    def _extract_education(self, doc) -> list:
        """Extract education entries from ORG entities with institution recognition."""
        education = []
        for ent in doc.ents:
            if ent.label_ == "ORG":
                name = ent.text.strip().replace("\n", " ")
                is_kenyan = any(ki in name.lower() for ki in self.kenyan_institutions) if self.kenyan_institutions else False

                if "university" in name.lower() or "college" in name.lower() or "institute" in name.lower() or is_kenyan:
                    if not any(e['institution_name'] == name for e in education):
                        # Attempt to extract degree from surrounding context
                        degree = self._extract_degree_near(doc, ent)
                        grad_year = self._extract_year_near(doc, ent)

                        education.append({
                            "institution_name": name,
                            "is_kenyan_institution": is_kenyan,
                            "degree": degree,
                            "graduation_year": grad_year
                        })
        return education

    def _extract_degree_near(self, doc, entity) -> str:
        """Look for degree keywords near an education entity."""
        degree_keywords = {
            'phd': 'PhD', 'ph.d': 'PhD', 'doctorate': 'PhD',
            'master': 'Masters', 'masters': 'Masters', 'msc': 'Masters',
            'mba': 'MBA', 'ma': 'Masters',
            'bachelor': 'Bachelors', 'bachelors': 'Bachelors', 'bsc': 'Bachelors',
            'bcom': 'Bachelors', 'b.com': 'Bachelors', 'ba': 'Bachelors',
            'diploma': 'Diploma', 'certificate': 'Certificate',
            'higher diploma': 'Higher Diploma',
        }

        # Search in a window of 50 tokens around the entity
        start = max(0, entity.start - 25)
        end = min(len(doc), entity.end + 25)
        window_text = doc[start:end].text.lower()

        for keyword, degree in degree_keywords.items():
            if keyword in window_text:
                return degree
        return "Unknown"

    def _extract_year_near(self, doc, entity) -> int:
        """Look for a 4-digit year near an education entity."""
        start = max(0, entity.start - 20)
        end = min(len(doc), entity.end + 20)
        window_text = doc[start:end].text

        years = re.findall(r'\b(19[89]\d|20[0-3]\d)\b', window_text)
        if years:
            return int(years[-1])  # Return the latest year found
        return None

    def _extract_experience(self, doc, text: str) -> list:
        """Extract work experience entries including individual positions."""
        experience = []

        # Strategy 1: Look for "X years of experience" pattern
        years_patterns = re.finditer(
            r'(\d+)\+?\s*(?:years?|yrs?)\.?\s*(?:of\s+)?(?:experience|exp\.?)',
            text, re.IGNORECASE
        )
        for match in years_patterns:
            years = float(match.group(1))
            # Try to find a job title or context nearby
            context_start = max(0, match.start() - 100)
            context = text[context_start:match.start()].strip()
            title = self._extract_title_from_context(context)

            if not any(e.get('years') == years and e.get('job_title') == title for e in experience):
                experience.append({
                    "company_name": "Not specified",
                    "job_title": title,
                    "years": years
                })

        # Strategy 2: Look for ORG entities paired with role-like patterns
        role_keywords = [
            'engineer', 'developer', 'manager', 'analyst', 'consultant',
            'designer', 'architect', 'lead', 'director', 'intern',
            'coordinator', 'specialist', 'administrator', 'officer',
            'technician', 'assistant', 'supervisor', 'head of',
        ]
        for ent in doc.ents:
            if ent.label_ == "ORG":
                name = ent.text.strip()
                # Skip if this looks like an educational institution
                if any(kw in name.lower() for kw in ['university', 'college', 'institute', 'school']):
                    continue
                # Look for role keywords near this ORG
                window_start = max(0, ent.start - 15)
                window_end = min(len(doc), ent.end + 15)
                window_text = doc[window_start:window_end].text.lower()

                for role in role_keywords:
                    if role in window_text:
                        if not any(e.get('company_name') == name for e in experience):
                            experience.append({
                                "company_name": name,
                                "job_title": role.title(),
                                "years": 0  # Duration unknown from this extraction
                            })
                        break

        # Strategy 3: Look for date ranges like "2018 - 2021" or "Jan 2019 - Present"
        date_range_pattern = re.finditer(
            r'(?:(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+)?'
            r'(20[0-2]\d|19[89]\d)\s*[-–—to]+\s*'
            r'(?:(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+)?'
            r'(20[0-2]\d|19[89]\d|[Pp]resent|[Cc]urrent)',
            text
        )
        for match in date_range_pattern:
            start_year = int(match.group(1))
            end_str = match.group(2)
            end_year = 2026 if end_str.lower() in ('present', 'current') else int(end_str)
            years = max(0, end_year - start_year)

            context_start = max(0, match.start() - 150)
            context = text[context_start:match.start()].strip()
            title = self._extract_title_from_context(context)

            if years > 0 and not any(e.get('years') == years and e.get('job_title') == title for e in experience):
                experience.append({
                    "company_name": "Not specified",
                    "job_title": title,
                    "years": float(years)
                })

        # Fallback: if nothing was extracted, try the simple total years pattern
        if not experience:
            simple_match = re.search(r'(\d+)\+?\s*(years|yrs)\s*(of)?\s*experience', text.lower())
            if simple_match:
                experience.append({
                    "company_name": "Various",
                    "job_title": "Professional",
                    "years": float(simple_match.group(1))
                })

        return experience

    def _extract_title_from_context(self, context: str) -> str:
        """Try to extract a job title from surrounding text."""
        title_patterns = [
            r'(?:as\s+(?:a\s+)?|position:\s*|role:\s*|title:\s*)'
            r'([\w\s]+(?:engineer|developer|manager|analyst|consultant|designer|architect|lead|director|intern|officer|specialist|coordinator|technician))',
        ]
        for pattern in title_patterns:
            match = re.search(pattern, context, re.IGNORECASE)
            if match:
                return match.group(1).strip().title()

        # Check last line of context for a capitalized title-like string
        lines = context.strip().split('\n')
        if lines:
            last_line = lines[-1].strip()
            if last_line and len(last_line) < 80 and last_line[0].isupper():
                return last_line
        return "Professional"
