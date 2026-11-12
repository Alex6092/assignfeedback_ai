FROM python:3.12-slim-bookworm
COPY constraints.txt /opt/constraints.txt
RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir -c /opt/constraints.txt jupyterhub dockerspawner \
    && npm install -g configurable-http-proxy@4
COPY hub/jupyterhub_config.py hub/moodleauth.py hub/egress.py /opt/moodle/
CMD ["jupyterhub", "-f", "/opt/moodle/jupyterhub_config.py"]
