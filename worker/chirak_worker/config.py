"""Worker settings, read from the environment once."""
import os
from dataclasses import dataclass, field
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    data_dir: Path = Path("/data")
    bearer_token: str = ""
    host: str = "127.0.0.1"
    port: int = 8100
    generator_model: str = "Qwen/Qwen3.8-27B"
    student_model: str = "Qwen/Qwen3.5-4B"
    embedding_model: str = "BAAI/bge-m3"
    vllm_bin: str = "vllm"
    vllm_host: str = "127.0.0.1"
    vllm_port: int = 8000
    vllm_start_timeout_s: int = 1500
    gen_concurrency: int = 32
    train_python: str = ""  # empty = the interpreter running the worker
    extra: dict = field(default_factory=dict)

    @property
    def runs_dir(self) -> Path:
        return self.data_dir / "runs"

    @property
    def jobs_dir(self) -> Path:
        return self.runs_dir / "_jobs"

    @property
    def logs_dir(self) -> Path:
        return self.data_dir / "logs"

    @property
    def vllm_url(self) -> str:
        return f"http://{self.vllm_host}:{self.vllm_port}"

    @classmethod
    def from_env(cls, env=None) -> "Settings":
        e = os.environ if env is None else env
        return cls(
            data_dir=Path(e.get("DATA_DIR", "/data")),
            bearer_token=e.get("WORKER_BEARER_TOKEN", ""),
            host=e.get("WORKER_HOST", "127.0.0.1"),
            port=int(e.get("WORKER_PORT", "8100")),
            generator_model=e.get("GENERATOR_MODEL", cls.generator_model),
            student_model=e.get("STUDENT_MODEL", cls.student_model),
            embedding_model=e.get("EMBEDDING_MODEL", cls.embedding_model),
            vllm_bin=e.get("VLLM_BIN", "vllm"),
            vllm_port=int(e.get("VLLM_PORT", "8000")),
            vllm_start_timeout_s=int(e.get("VLLM_START_TIMEOUT_S", "1500")),
            gen_concurrency=int(e.get("GEN_CONCURRENCY", "32")),
            train_python=e.get("TRAIN_PYTHON", ""),
        )
