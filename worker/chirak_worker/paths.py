"""Path rules: everything the API reads or writes lives under DATA_DIR/runs/<run_id>."""
import re
from pathlib import Path

from fastapi import HTTPException

from .config import Settings
from .schemas import RUN_ID_PATTERN


def run_dir(settings: Settings, run_id: str) -> Path:
    if not re.match(RUN_ID_PATTERN, run_id):
        raise HTTPException(400, "invalid run_id")
    return settings.runs_dir / run_id


def resolve_under_runs(settings: Settings, value: str, must_exist: bool = True) -> Path:
    """Resolve a client-supplied path and require it to stay inside DATA_DIR/runs (no symlink escapes)."""
    if "\x00" in value:
        raise HTTPException(400, "invalid path")
    root = settings.runs_dir.resolve()
    p = Path(value).resolve()
    if root not in p.parents or p.relative_to(root).parts[0] == "_jobs":
        raise HTTPException(400, f"path must be under {root}")
    if must_exist and not p.exists():
        raise HTTPException(404, f"not found: {value}")
    return p


def safe_artifact_path(settings: Settings, run_id: str, rel: str) -> Path:
    base = run_dir(settings, run_id).resolve()
    if "\x00" in rel or rel.startswith("/"):
        raise HTTPException(400, "invalid path")
    p = (base / rel).resolve()
    if base != p and base not in p.parents:
        raise HTTPException(400, "path traversal rejected")
    return p
