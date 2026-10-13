"""FastAPI app implementing docs/worker-api.md. Heavy backends are injected so tests can fake them."""
import hmac
from contextlib import asynccontextmanager
from dataclasses import dataclass
from typing import Callable

from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import FileResponse, JSONResponse

from . import __version__
from .config import Settings
from .embedding import Embedder
from .evaluate import run_evaluate
from .generate import run_generate
from .gpu import gpu_info
from .jobs import JobRunner, JobStore
from .parsing import parse_student_answer
from .paths import resolve_under_runs, run_dir, safe_artifact_path
from .prompts import student_messages
from .schemas import EvaluateRequest, GenerateRequest, PredictRequest, ServeRequest, TrainRequest
from .train import run_train
from .vllm import LlmBackend, VllmError, VllmManager


@dataclass
class Services:
    llm: LlmBackend
    embedder: Embedder
    runner: JobRunner
    gpu_info: Callable[[], dict] = gpu_info
    train: Callable = run_train


def default_services(settings: Settings) -> Services:
    from .embedding import SentenceTransformerEmbedder

    return Services(
        llm=VllmManager(settings),
        embedder=SentenceTransformerEmbedder(settings.embedding_model),
        runner=JobRunner(JobStore(settings.jobs_dir)),
    )


def create_app(settings: Settings, services: Services | None = None) -> FastAPI:
    svc = services or default_services(settings)

    @asynccontextmanager
    async def lifespan(_: FastAPI):
        yield
        svc.llm.stop()  # do not leave an orphaned vLLM holding the GPU

    app = FastAPI(title="Chirak worker", version=__version__, lifespan=lifespan)

    def token_ok(request: Request) -> bool:
        header = request.headers.get("authorization", "")
        scheme, _, token = header.partition(" ")
        if scheme.lower() != "bearer" or not settings.bearer_token:
            return False
        return hmac.compare_digest(token.encode(), settings.bearer_token.encode())

    def auth(request: Request) -> None:
        if not token_ok(request):
            raise HTTPException(401, "unauthorized")

    @app.exception_handler(RequestValidationError)
    async def _validation(_: Request, exc: RequestValidationError):
        msg = "; ".join(f"{'.'.join(map(str, e['loc'][1:]))}: {e['msg']}" for e in exc.errors())
        return JSONResponse({"detail": msg}, status_code=422)

    @app.exception_handler(VllmError)
    async def _vllm(_: Request, exc: VllmError):
        return JSONResponse({"detail": str(exc)}, status_code=502)

    @app.get("/health")
    def health(request: Request):
        if not token_ok(request):
            return {"status": "ok"}
        cur = svc.runner.current
        return {
            "status": "ok",
            "gpu": svc.gpu_info(),
            "vllm": {"mode": svc.llm.mode, "model": svc.llm.model_name if svc.llm.mode != "none" else None},
            "adapters": sorted(svc.llm.adapters),
            "current_job": cur["id"] if cur else None,
            "queue_length": svc.runner.queue_length,
        }

    @app.post("/generate", status_code=202, dependencies=[Depends(auth)])
    def generate(req: GenerateRequest):
        rdir = run_dir(settings, req.run_id)
        if req.purpose == "eval" and (rdir / "eval.jsonl").exists():
            raise HTTPException(409, "eval.jsonl already exists for this run; the eval set is frozen")
        avoid = [str(resolve_under_runs(settings, p)) for p in req.avoid_near] if req.purpose == "train" else []
        if req.purpose == "train" and str(resolve_under_runs(settings, str(rdir / "eval.jsonl"))) not in avoid:
            # without the run's own eval file the leakage guard would silently check nothing
            raise HTTPException(400, "train generation requires this run's eval.jsonl in avoid_near")
        job_id = svc.runner.submit(
            "generate", req.run_id,
            lambda ctx: run_generate(req, settings, svc.llm, svc.embedder, ctx, avoid))
        return {"job_id": job_id}

    @app.post("/train", status_code=202, dependencies=[Depends(auth)])
    def train(req: TrainRequest):
        run_dir(settings, req.run_id)
        files = [str(resolve_under_runs(settings, p)) for p in req.train_files]
        job_id = svc.runner.submit("train", req.run_id, lambda ctx: svc.train(req, settings, svc.llm, ctx, files))
        return {"job_id": job_id}

    @app.post("/evaluate", status_code=202, dependencies=[Depends(auth)])
    def evaluate(req: EvaluateRequest):
        run_dir(settings, req.run_id)
        eval_path = resolve_under_runs(settings, req.eval_file)
        adapter = None if req.model == "base" else str(resolve_under_runs(settings, req.model))
        job_id = svc.runner.submit(
            "evaluate", req.run_id, lambda ctx: run_evaluate(req, settings, svc.llm, ctx, eval_path, adapter))
        return {"job_id": job_id}

    @app.get("/jobs/{job_id}", dependencies=[Depends(auth)])
    def job(job_id: str):
        rec = svc.runner.store.load(job_id)
        if rec is None:
            raise HTTPException(404, "job not found")
        return rec

    def require_student_capable() -> None:
        cur = svc.runner.current
        if cur and cur["type"] != "evaluate":
            raise HTTPException(409, f"GPU is busy with a {cur['type']} job")

    @app.post("/serve", dependencies=[Depends(auth)])
    def serve(req: ServeRequest):
        require_student_capable()
        path = resolve_under_runs(settings, req.adapter_path)
        svc.llm.load_adapter(req.name, str(path))
        return {"name": req.name, "loaded": True}

    @app.delete("/serve/{name}", dependencies=[Depends(auth)])
    def unserve(name: str):
        require_student_capable()
        if name not in svc.llm.adapters:
            raise HTTPException(404, "adapter not served")
        svc.llm.unload_adapter(name)
        return {"name": name, "loaded": False}

    @app.post("/predict", dependencies=[Depends(auth)])
    def predict(req: PredictRequest):
        require_student_capable()
        if req.model != "base" and req.model not in svc.llm.adapters:
            raise HTTPException(404, f"model not served: {req.model}")
        svc.llm.ensure_mode("student")
        raw, ms = svc.llm.chat(req.model, student_messages(req.task_spec, req.input), temperature=0, max_tokens=256)
        parsed, err = parse_student_answer(raw, req.task_spec)
        return {"raw": raw, "parsed": parsed, "valid": parsed is not None, "error": err, "latency_ms": round(ms)}

    @app.get("/artifacts/{run_id}/{path:path}", dependencies=[Depends(auth)])
    def artifact(run_id: str, path: str):
        p = safe_artifact_path(settings, run_id, path)
        if not p.is_file():
            raise HTTPException(404, "not found")
        return FileResponse(p)

    return app
