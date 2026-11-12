FROM python:3.12-slim-bookworm AS builder
COPY constraints.txt /opt/constraints.txt
RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir -c /opt/constraints.txt jupyterlab
COPY labextension /build
WORKDIR /build
RUN npm ci && npm run build

FROM python:3.12-slim-bookworm
COPY constraints.txt /opt/constraints.txt
RUN apt-get update && apt-get install -y --no-install-recommends util-linux && rm -rf /var/lib/apt/lists/* \
    && useradd -m -u 1000 jovyan \
    && pip install --no-cache-dir -c /opt/constraints.txt jupyterhub jupyterlab nbgrader \
       'nbclient>=0.10,<0.11' 'numpy>=2,<3' 'pandas>=2.2,<3' 'matplotlib>=3.9,<4' \
    && mkdir -p /opt/notebook /work /input /results /home/jovyan/work /etc/jupyter \
    && chown 1000:1000 /work /home/jovyan/work
COPY --from=builder /build/labextension /usr/local/share/jupyter/labextensions/@moodle/notebook
COPY images/execute.py /opt/notebook/execute.py
COPY images/jupyter_server_config.py /etc/jupyter/jupyter_server_config.py
COPY images/kernel.json /usr/local/share/jupyter/kernels/grading/kernel.json
ENV HOME=/home/jovyan JUPYTER_CONFIG_DIR=/etc/jupyter
USER 1000:1000
WORKDIR /home/jovyan/work
CMD ["jupyterhub-singleuser"]
