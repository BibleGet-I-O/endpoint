FROM python:3.12-slim

WORKDIR /app

# Install dependencies first for better layer caching
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Create non-root user
RUN groupadd -r appuser && useradd -r -g appuser -m -s /bin/false appuser \
    && mkdir -p /app/.cache && chown -R appuser:appuser /app

# Pre-download the model at build time so container startup is fast. The
# revision is pinned to a specific commit on the Hugging Face Hub so a model
# upgrade is an explicit decision, not a side-effect of a rebuild (issue #71).
# Override via build args, e.g.:
#   docker build --build-arg EMBEDDING_MODEL=sentence-transformers/LaBSE \
#       --build-arg EMBEDDING_MODEL_REVISION=836121a0533e5664b21c7aacc5d22951f2b8b25b .
ARG EMBEDDING_MODEL=sentence-transformers/LaBSE
ARG EMBEDDING_MODEL_REVISION=836121a0533e5664b21c7aacc5d22951f2b8b25b
ENV EMBEDDING_MODEL=${EMBEDDING_MODEL}
ENV EMBEDDING_MODEL_REVISION=${EMBEDDING_MODEL_REVISION}
ENV TRANSFORMERS_CACHE=/app/.cache
RUN python -c "import os; from sentence_transformers import SentenceTransformer; SentenceTransformer(os.environ['EMBEDDING_MODEL'], revision=os.environ['EMBEDDING_MODEL_REVISION'])" \
    && chown -R appuser:appuser /app/.cache

USER appuser

COPY main.py .

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
