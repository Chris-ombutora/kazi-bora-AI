from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import logging
from .parser import CVParser

app = FastAPI(title="NLP Parsing Service")
logger = logging.getLogger(__name__)

# Initialize parser on startup
parser = CVParser()

class ParseRequest(BaseModel):
    text: str

@app.post("/parse")
async def parse_cv(request: ParseRequest):
    try:
        structured_data = parser.parse_text(request.text)
        return structured_data
    except Exception as e:
        logger.error(f"Failed to parse CV: {str(e)}")
        raise HTTPException(status_code=500, detail="Internal Server Error during parsing.")

@app.get("/health")
def health_check():
    return {"status": "ok"}
