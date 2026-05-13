"""Embedding microservice for BibleGet semantic search.

Lightweight FastAPI app that accepts text and returns a 768-dimensional
embedding vector using the sentence-transformers/LaBSE model. LaBSE was
adopted after the A/B experiment documented in discussion #107
demonstrably outperformed paraphrase-multilingual-MiniLM-L12-v2 on Latin
(NVBSE) — the original failure mode that drove the rebuild.

Pinned revision: MODEL_REVISION below. Issue #71 requires a deterministic
model version for reproducible builds and embeddings.

The PHP endpoint calls this service at query time to vectorize user queries
for pgvector similarity search.
"""

import os
import time

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field, field_validator
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "sentence-transformers/LaBSE")
MODEL_REVISION = os.environ.get(
    "EMBEDDING_MODEL_REVISION",
    "836121a0533e5664b21c7aacc5d22951f2b8b25b",
)

MAX_TEXT_LENGTH = 10000
MAX_BATCH_SIZE = 128

app = FastAPI(title="BibleGet Embedding Service", version="1.0.0")
model = None


class EmbedRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=MAX_TEXT_LENGTH, description="Text to embed")


class EmbedResponse(BaseModel):
    embedding: list[float]
    dimensions: int
    model: str


class BatchEmbedRequest(BaseModel):
    texts: list[str] = Field(..., min_length=1, max_length=MAX_BATCH_SIZE, description="List of texts to embed")

    @field_validator("texts")
    @classmethod
    def validate_text_lengths(cls, v: list[str]) -> list[str]:
        for i, text in enumerate(v):
            if len(text) > MAX_TEXT_LENGTH:
                raise ValueError(f"texts[{i}] exceeds maximum length of {MAX_TEXT_LENGTH} characters")
            if len(text) == 0:
                raise ValueError(f"texts[{i}] must not be empty")
        return v


class BatchEmbedResponse(BaseModel):
    embeddings: list[list[float]]
    dimensions: int
    model: str


@app.on_event("startup")
def load_model():
    global model
    start = time.time()
    model = SentenceTransformer(MODEL_NAME, revision=MODEL_REVISION)
    elapsed = time.time() - start
    print(f"Model '{MODEL_NAME}' @ {MODEL_REVISION[:8]} loaded in {elapsed:.1f}s")


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME, "ready": model is not None}


@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest):
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded yet")

    vector = model.encode(req.text).tolist()
    return EmbedResponse(embedding=vector, dimensions=len(vector), model=MODEL_NAME)


@app.post("/embed/batch", response_model=BatchEmbedResponse)
def embed_batch(req: BatchEmbedRequest):
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded yet")

    vectors = model.encode(req.texts).tolist()
    return BatchEmbedResponse(
        embeddings=vectors,
        dimensions=len(vectors[0]) if vectors else 0,
        model=MODEL_NAME,
    )
