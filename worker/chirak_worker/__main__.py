import sys

import uvicorn

from .app import create_app
from .config import Settings


def main() -> None:
    settings = Settings.from_env()
    if not settings.bearer_token:
        sys.exit("WORKER_BEARER_TOKEN is not set; refusing to start without auth")
    settings.runs_dir.mkdir(parents=True, exist_ok=True)
    app = create_app(settings)
    uvicorn.run(app, host=settings.host, port=settings.port, log_level="info")


if __name__ == "__main__":
    main()
