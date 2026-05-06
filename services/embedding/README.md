# BibleGet Embedding Service

Long-running FastAPI app that vectorises text via the
`paraphrase-multilingual-MiniLM-L12-v2` sentence-transformers model. The PHP
endpoint calls it at request time (via `EMBEDDING_SERVICE_URL`) for semantic
and similar-verse search.

## How it's deployed

The chrooted PHP deploy lane (see `.github/workflows/deploy.yaml`) **does not**
ship this service — different runtime model (long-lived FastAPI vs stateless
PHP-FPM), different deps (Python venv vs Composer), and different lifecycle
(needs explicit restart on code change).

Instead, this service has its own deploy lane:

- Runs as a dedicated unprivileged user `bibleget-embed` on the VPS
  (non-chrooted, separate from the PHP deploy user).
- Managed by systemd at the user level (`systemctl --user`).
- Source under `~bibleget-embed/embedding/`, venv at
  `~bibleget-embed/embedding/venv`, model cache at
  `~bibleget-embed/.cache/huggingface/`.
- Workflow `.github/workflows/deploy-embedding.yaml` rsyncs the sources,
  refreshes the venv (`pip install -r requirements.txt`, idempotent),
  reloads + restarts the systemd unit, and probes `/health`.

A single instance serves all environments (dev / v3 / v4); the service is
stateless and the model is the same regardless of which Bible version is
being queried.

## One-time server-side provisioning

### As root

```bash
# 1. Create the dedicated user and enable user-level systemd at boot.
sudo useradd -m -s /bin/bash bibleget-embed
sudo loginctl enable-linger bibleget-embed

# 2. Make sure the python3-venv apt package is installed.
sudo apt-get install -y python3-venv
```

### As `bibleget-embed`

```bash
# 1. Set up the venv and cache the model (one-time, ~470 MB download).
mkdir -p ~/embedding
cd ~/embedding
python3 -m venv venv
. venv/bin/activate
pip install --upgrade pip
# Install the runtime deps (matches services/embedding/requirements.txt).
# The deploy workflow will keep this in sync going forward.
pip install fastapi uvicorn sentence-transformers
# Pre-warm the model cache so cold starts are fast.
python -c "from sentence_transformers import SentenceTransformer; \
           SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')"
deactivate

# 2. Set up SSH for the deploy keypair.
mkdir -p ~/.ssh && chmod 700 ~/.ssh
# Append the GitHub deploy keypair PUBLIC half to ~/.ssh/authorized_keys
# (generate locally with:
#   ssh-keygen -t ed25519 -C bibleget-embed-deploy -f bibleget-embed-deploy -N ''
# put the .pub here, store the private half as the GitHub secret
# VPS_EMBED_SSH_PRIVATE_KEY).
# Then:
chmod 600 ~/.ssh/authorized_keys

# 3. Install the systemd user unit.
mkdir -p ~/.config/systemd/user
# The first deploy from CI will rsync bibleget-embedding.service into
# ~/embedding/ and copy it here. For the very first start before CI runs,
# you can install it manually from this repo:
#
#   cp /tmp/bibleget-embedding.service ~/.config/systemd/user/
#   systemctl --user daemon-reload
#   systemctl --user enable --now bibleget-embedding
#   systemctl --user status bibleget-embedding
#   curl -sS http://127.0.0.1:8000/health
```

## GitHub repo configuration

| Kind | Name | Value |
|---|---|---|
| Secret | `VPS_EMBED_USER` | `bibleget-embed` |
| Secret | `VPS_EMBED_SSH_PRIVATE_KEY` | private half of the deploy keypair |
| *(reused)* | `VPS_HOST`, `VPS_SSH_KNOWN_HOSTS` | already configured for the PHP deploy lane |
| *(optional)* | `VPS_SSH_PORT` | only if SSH is not on 22 |

## Wiring it up

Once the service is healthy, set `EMBEDDING_SERVICE_URL=http://127.0.0.1:8000`
in each environment's `.env` (or `.env.staging`) on the server. The PHP app
will pick it up on the next request.

## Operational commands

```bash
# Status / restart / logs (as bibleget-embed):
systemctl --user status bibleget-embedding
systemctl --user restart bibleget-embedding
journalctl --user -u bibleget-embedding -f

# Health probe:
curl -sS http://127.0.0.1:8000/health
# → {"status":"ok","model":"paraphrase-multilingual-MiniLM-L12-v2","ready":true}

# Manual embed test:
curl -sS -X POST http://127.0.0.1:8000/embed \
  -H 'Content-Type: application/json' \
  -d '{"text":"Blessed is the man"}' | head -c 200
```
