"""Embedding microservice for BibleGet semantic search.

Lightweight FastAPI app that accepts text and returns a 384-dimensional
embedding vector using the paraphrase-multilingual-MiniLM-L12-v2 model.

The PHP endpoint calls this service at query time to vectorize user queries
for pgvector similarity search.
"""

import os
import time

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "paraphrase-multilingual-MiniLM-L12-v2")

app = FastAPI(title="BibleGet Embedding Service", version="1.0.0")
model = None


class EmbedRequest(BaseModel):
    text: str = Field(..., min_length=1, description="Text to embed")


class EmbedResponse(BaseModel):
    embedding: list[float]
    dimensions: int
    model: str


class BatchEmbedRequest(BaseModel):
    texts: list[str] = Field(..., min_length=1, description="List of texts to embed")


class BatchEmbedResponse(BaseModel):
    embeddings: list[list[float]]
    dimensions: int
    model: str


@app.on_event("startup")
def load_model():
    global model
    start = time.time()
    model = SentenceTransformer(MODEL_NAME)
    elapsed = time.time() - start
    print(f"Model '{MODEL_NAME}' loaded in {elapsed:.1f}s")


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
