FROM python:3.12-slim

WORKDIR /app

# Install dependencies first for better layer caching
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Create non-root user
RUN groupadd -r appuser && useradd -r -g appuser -m -s /bin/false appuser \
    && mkdir -p /app/.cache && chown -R appuser:appuser /app

# Pre-download the model at build time so container startup is fast.
# Override the model name via build arg (e.g. for the LaBSE A/B experiment):
#   docker build --build-arg EMBEDDING_MODEL=sentence-transformers/LaBSE .
ARG EMBEDDING_MODEL=paraphrase-multilingual-MiniLM-L12-v2
ENV EMBEDDING_MODEL=${EMBEDDING_MODEL}
ENV TRANSFORMERS_CACHE=/app/.cache
RUN python -c "import os; from sentence_transformers import SentenceTransformer; SentenceTransformer(os.environ['EMBEDDING_MODEL'])" \
    && chown -R appuser:appuser /app/.cache

USER appuser

COPY main.py .

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
