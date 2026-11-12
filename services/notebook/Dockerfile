FROM python:3.12-slim-bookworm
WORKDIR /app
COPY pyproject.toml .
COPY constraints.txt .
COPY notebook_service notebook_service
RUN pip install --no-cache-dir -c constraints.txt .
ENV PYTHONUNBUFFERED=1
CMD ["uvicorn", "notebook_service.api:app", "--host", "0.0.0.0", "--port", "8080"]
