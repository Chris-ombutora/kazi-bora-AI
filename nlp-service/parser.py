import spacy
import re
import mysql.connector
import os
import logging

logger = logging.getLogger(__name__)

class CVParser:
    def __init__(self):
        # We will use spacy for some basic entity recognition
        # In a real setup, you should load 'en_core_web_sm' or your custom NER model.
        try:
            self.nlp = spacy.load("en_core_web_sm")
        except OSError:
            # Fallback if not downloaded
            import spacy.cli
            spacy.cli.download("en_core_web_sm")
            self.nlp = spacy.load("en_core_web_sm")
        
        # Connect to DB to load Kenyan institutions for checking
        self.kenyan_institutions = self.load_kenyan_institutions()

    def load_kenyan_institutions(self):
        try:
            conn = mysql.connector.connect(
                host=os.getenv("DB_HOST", "localhost"),
                user=os.getenv("DB_USER", "root"),
                password=os.getenv("DB_PASS", ""),
                database=os.getenv("DB_NAME", "kazibora"),
                port=int(os.getenv("DB_PORT", "3306"))
            )
            cursor = conn.cursor(dictionary=True)
            # Assuming a table kenyan_institutions exists or we can just fetch from a similar logic
            # Since the user requested it: "recognizing entries from a Kenyan institution... dataset stored in MySQL"
            cursor.execute("SELECT name FROM kenyan_institutions")
            institutions = cursor.fetchall()
            conn.close()
            return [inst['name'].lower() for inst in institutions]
        except Exception as e:
            logger.warning(f"Could not load kenyan institutions from DB: {e}")
            return []

    def parse_text(self, text: str) -> dict:
        doc = self.nlp(text)
        
        # Very simple extraction logic for demonstration
        skills = []
        possible_skills = ['python', 'java', 'sql', 'php', 'fastapi', 'spacy', 'mysql', 'docker', 'kubernetes', 'react', 'javascript', 'aws']
        for token in doc:
            if token.text.lower() in possible_skills and token.text.lower() not in skills:
                skills.append(token.text.lower())

        education = []
        # Look for ORGs in ORG/Entities that might match Kenyan institutions
        for ent in doc.ents:
            if ent.label_ == "ORG":
                name = ent.text.strip().replace("\n", " ")
                is_kenyan = any(ki in name.lower() for ki in self.kenyan_institutions) if self.kenyan_institutions else False
                
                # Adding some minimal filtering so not every ORG is Education
                if "university" in name.lower() or "college" in name.lower() or "institute" in name.lower() or is_kenyan:
                    if not any(e['institution_name'] == name for e in education):
                        education.append({
                            "institution_name": name,
                            "is_kenyan_institution": is_kenyan,
                            "degree": "Unknown", # Needs more complex regex or ML
                            "graduation_year": None
                        })
        
        experience = []
        # Attempt finding years of experience
        years_pattern = re.search(r'(\d+)\+?\s*(years|yrs)\s*(of)?\s*experience', text.lower())
        years = float(years_pattern.group(1)) if years_pattern else 0.0

        if years > 0:
            experience.append({
                "company_name": "Various", 
                "job_title": "Professional", 
                "years": years
            })

        return {
            "skills": skills,
            "education": education,
            "experience": experience
        }
