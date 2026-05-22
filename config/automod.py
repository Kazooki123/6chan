from fastapi import FastAPI
from pydantic import BaseModel
from detoxify import Detoxify
import psycopg2
import os

DB = psycopg2.connect(
    dbname=os.getenv("DB_NAME"),
    user=os.getenv("DB_USER"),
    password=os.getenv("DB_PASS"),
    host="localhost"
)

app = FastAPI()
model = Detoxify('original')  # loads once on startup

class PostRequest(BaseModel):
    text: str
    post_id: int
    post_type: str  # 'thread' or 'post'


THRESHOLDS = {
    'toxicity':            0.85,
    'severe_toxicity':     0.70,
    'obscene':             0.90,
    'threat':              0.75,
    'insult':              0.90,
    'identity_attack':     0.80,
}

@app.post("/analyze")
def analyze(req: PostRequest):
    scores = model.predict(req.text)
    flags  = {k: float(v) for k, v in scores.items()
              if k in THRESHOLDS and float(v) >= THRESHOLDS[k]}
    
    if flags:
        top_flag = max(flags, key=flags.get)
        table    = 'threads' if req.post_type == 'thread' else 'posts'
        cur      = DB.cursor()
        cur.execute(
            f"UPDATE {table} SET automod_flag = %s WHERE id = %s",
            (top_flag, req.post_id)
        )
        DB.commit()
        cur.close()
    
    return {
        "post_id":   req.post_id,
        "post_type": req.post_type,
        "flagged": len(flags) > 0,
        "flags": flags,
        "scores":    {k: float(v) for k, v in scores.items()}
    }

@app.get("/health")
def health():
    return {"status": "ok"}
